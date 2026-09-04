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
    }


def read_ids():
    ids = []
    extra_file = ROOT / "reports" / "pagarme_extra_ids.txt"
    if extra_file.is_file():
        ids.extend([line.strip() for line in extra_file.read_text(encoding="utf-8").splitlines() if line.strip()])
    for name in ["faltando_no_banco.csv", "campos_divergentes.csv"]:
        path = ROOT / "reports" / "pagarme_auditoria" / name
        with path.open(encoding="utf-8-sig", newline="") as fh:
            for row in csv.DictReader(fh, delimiter=";"):
                tx = row["transaction_id"]
                if tx:
                    ids.append(tx.replace("pagarme:", ""))
    return ids


def main():
    ids = read_ids()
    patterns = [f"%{x}%" for x in ids]
    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            out = {}
            for charge_id, pattern in zip(ids, patterns):
                cur.execute(
                    """
                    SELECT external_transaction_id, provider_status, normalized_status, gross_amount_cents,
                           buyer_email, last_received_at, LEFT(raw_payload_json, 500) payload
                      FROM payment_sales
                     WHERE provider='pagarme'
                       AND (external_transaction_id LIKE %s OR raw_payload_json LIKE %s)
                     ORDER BY last_received_at DESC
                     LIMIT 10
                    """,
                    (pattern, pattern),
                )
                sales = cur.fetchall()
                cur.execute(
                    """
                    SELECT event_name, external_transaction_id, provider_status, process_status,
                           received_at, LEFT(payload_json, 500) payload
                      FROM pagarme_webhook_events
                     WHERE external_transaction_id LIKE %s OR payload_json LIKE %s
                     ORDER BY received_at DESC
                     LIMIT 10
                    """,
                    (pattern, pattern),
                )
                events = cur.fetchall()
                out[charge_id] = {"payment_sales": sales, "events": events}
    print(json.dumps(out, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
