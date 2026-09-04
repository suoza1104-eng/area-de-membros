import csv
import json
import re
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]


def db_config():
    config = (ROOT / "app" / "config.php").read_text(encoding="utf-8", errors="ignore")
    values = {}
    for key in ("DB_HOST", "DB_USER", "DB_PASS", "DB_NAME"):
        match = re.search(r"define\(\s*['\"]" + key + r"['\"]\s*,\s*['\"]([^'\"]*)['\"]", config)
        if not match:
            raise RuntimeError(f"{key} nao encontrado")
        values[key] = match.group(1)
    return {
        "host": values["DB_HOST"],
        "user": values["DB_USER"],
        "password": values["DB_PASS"],
        "database": values["DB_NAME"],
        "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
        "connect_timeout": 8,
    }


def affected_transactions():
    path = ROOT / "reports" / "hotmart_agosto_2026_auditoria" / "campos_divergentes.csv"
    with path.open(encoding="utf-8-sig", newline="") as fh:
        rows = csv.DictReader(fh, delimiter=";")
        return [row["transacao"] for row in rows if "status " in row["detalhe"]]


def main():
    txs = affected_transactions()
    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            placeholders = ",".join(["%s"] * len(txs))
            cur.execute(
                f"""
                SELECT transaction_code,status,webhook_event,webhook_event_id,
                       transaction_date,payment_confirmed_at,gross_revenue,net_revenue,producer_net,
                       imported_at,updated_at,LEFT(raw_payload_json,300) raw_head
                  FROM hotmart_sales_live
                 WHERE transaction_code IN ({placeholders})
                 ORDER BY transaction_date, transaction_code
                """,
                txs,
            )
            sales = cur.fetchall()
            cur.execute(
                f"""
                SELECT event_id,event_name,transaction_code,received_at,processed_at,process_status,
                       LEFT(payload_json,240) payload_head
                  FROM hotmart_webhook_events
                 WHERE transaction_code IN ({placeholders})
                 ORDER BY received_at, transaction_code
                """,
                txs,
            )
            events = cur.fetchall()
            cur.execute(
                """
                SELECT status, webhook_event, COUNT(*) qty
                  FROM hotmart_sales_live
                 WHERE transaction_date >= '2026-08-01 00:00:00'
                   AND transaction_date < '2026-09-01 00:00:00'
                   AND COALESCE(NULLIF(sales_channel,''),'hotmart')='hotmart'
                 GROUP BY status, webhook_event
                 ORDER BY qty DESC
                """
            )
            grouped = cur.fetchall()
            cur.execute("SELECT COUNT(*) total, MIN(received_at) min_received, MAX(received_at) max_received FROM hotmart_webhook_events")
            events_total = cur.fetchone()
            cur.execute(
                """
                SELECT event_name, COUNT(*) qty, MIN(received_at) min_received, MAX(received_at) max_received
                  FROM hotmart_webhook_events
                 GROUP BY event_name
                 ORDER BY qty DESC
                 LIMIT 20
                """
            )
            events_by_name = cur.fetchall()
            cur.execute(
                f"""
                SELECT l.transaction_code, l.status live_status, h.status legacy_status,
                       l.webhook_event live_event, h.updated_at legacy_updated
                  FROM hotmart_sales_live l
                  LEFT JOIN hotmart_sales h ON CONVERT(h.transaction_code USING utf8mb4) COLLATE utf8mb4_unicode_ci = l.transaction_code
                 WHERE l.transaction_code IN ({placeholders})
                 ORDER BY l.transaction_date, l.transaction_code
                 LIMIT 20
                """,
                txs,
            )
            legacy_compare = cur.fetchall()
            cur.execute("SHOW TABLES LIKE 'settings'")
            has_settings = cur.fetchone() is not None
            settings_rows = []
            if has_settings:
                cur.execute(
                    """
                    SELECT chave, LEFT(valor, 500) valor
                      FROM settings
                     WHERE chave LIKE '%hotmart%' OR chave LIKE '%hottok%' OR chave LIKE '%metrics%'
                     ORDER BY chave
                    """
                )
                settings_rows = cur.fetchall()
    print(json.dumps({
        "affected_count": len(txs),
        "sales_sample": sales[:20],
        "events_for_affected": events[:40],
        "hotmart_webhook_events_total": events_total,
        "hotmart_webhook_events_by_name": events_by_name,
        "legacy_compare_sample": legacy_compare,
        "hotmart_related_settings": settings_rows,
        "grouped_status_event": grouped,
    }, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
