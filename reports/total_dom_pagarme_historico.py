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
            raise RuntimeError(f"{key} nao encontrado em app/config.php")
        values[key] = match.group(1)
    return {
        "host": values["DB_HOST"],
        "user": values["DB_USER"],
        "password": values["DB_PASS"],
        "database": values["DB_NAME"],
        "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
        "connect_timeout": 8,
        "read_timeout": 20,
    }


def cents_to_money(value):
    return round((int(value or 0)) / 100, 2)


def main():
    approved = ("PAID", "APPROVED", "COMPLETED", "COMPLETE")
    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT provider,
                       COUNT(*) total_transacoes,
                       SUM(gross_amount_cents) total_bruto_cents,
                       SUM(COALESCE(net_amount_cents, 0)) total_liquido_cents,
                       SUM(COALESCE(fee_amount_cents, 0)) total_taxas_cents,
                       MIN(first_received_at) primeira,
                       MAX(last_received_at) ultima
                  FROM payment_sales
                 WHERE provider IN ('dom', 'pagarme')
                 GROUP BY provider
                 ORDER BY provider
                """
            )
            totals = cur.fetchall()

            placeholders = ",".join(["%s"] * len(approved))
            cur.execute(
                f"""
                SELECT provider,
                       COUNT(*) vendas,
                       SUM(gross_amount_cents) bruto_cents,
                       SUM(COALESCE(net_amount_cents, 0)) liquido_cents,
                       SUM(COALESCE(fee_amount_cents, 0)) taxas_cents,
                       MIN(first_received_at) primeira,
                       MAX(last_received_at) ultima
                  FROM payment_sales
                 WHERE provider IN ('dom', 'pagarme')
                   AND normalized_status IN ({placeholders})
                 GROUP BY provider
                 ORDER BY provider
                """,
                approved,
            )
            approved_totals = cur.fetchall()

            cur.execute(
                """
                SELECT provider, normalized_status, COUNT(*) qtd,
                       SUM(gross_amount_cents) bruto_cents,
                       SUM(COALESCE(net_amount_cents, 0)) liquido_cents
                  FROM payment_sales
                 WHERE provider IN ('dom', 'pagarme')
                 GROUP BY provider, normalized_status
                 ORDER BY provider, qtd DESC
                """
            )
            by_status = cur.fetchall()

    def normalize(rows, money_fields):
        out = []
        for row in rows:
            item = dict(row)
            for src, dest in money_fields:
                item[dest] = cents_to_money(item.pop(src, 0))
            out.append(item)
        return out

    result = {
        "fonte": "payment_sales",
        "status_contabilizados_como_venda": list(approved),
        "total_historico_todas_transacoes": normalize(
            totals,
            [
                ("total_bruto_cents", "total_bruto"),
                ("total_liquido_cents", "total_liquido"),
                ("total_taxas_cents", "total_taxas"),
            ],
        ),
        "total_historico_vendas_aprovadas": normalize(
            approved_totals,
            [
                ("bruto_cents", "bruto"),
                ("liquido_cents", "liquido"),
                ("taxas_cents", "taxas"),
            ],
        ),
        "por_status": normalize(
            by_status,
            [
                ("bruto_cents", "bruto"),
                ("liquido_cents", "liquido"),
            ],
        ),
    }
    out = ROOT / "reports" / "total_dom_pagarme_historico.json"
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
