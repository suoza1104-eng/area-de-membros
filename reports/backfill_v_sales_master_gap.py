"""
Fecha o gap entre hotmart_sales_live (ledger de vendas em tempo real, usado
pelo motor de atribuicao de campanhas) e hotmart_sales/dom_sales/pagarme_sales
(tabelas que alimentam a view v_sales_master, usada pelos relatorios
financeiros e pelo Gerenciador de Anuncios).

Motivo: por falhas pontuais em diferentes pontos de ingestao (paginacao
ausente no sync por API da Hotmart, scripts de reconciliacao manual que so
gravavam em hotmart_sales_live), existem vendas aprovadas reais que nunca
chegaram na tabela de relatorio do gateway correspondente — ficando
invisiveis em v_sales_master, na Analise de Vendas e no ROAS por campanha,
mesmo estando corretas no ledger de atribuicao.

Este script e idempotente (INSERT ... ON DUPLICATE KEY UPDATE) e so
INSERE o que estiver comprovadamente ausente (nao mexe em nada que ja
existe na tabela de destino, mesmo com status diferente).

Uso:
    python backfill_v_sales_master_gap.py            # dry-run (so relatorio)
    python backfill_v_sales_master_gap.py --apply     # aplica de fato
"""
import argparse
import json
import re
from datetime import datetime
from pathlib import Path

import pymysql

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "reports" / "v_sales_master_backfill"

APPROVED_STATUSES = ['APPROVED', 'APROVADO', 'COMPLETE', 'COMPLETO', 'COMPLETED', 'PAID', 'DISPAROU', 'OK']


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
        "read_timeout": 60,
        "write_timeout": 60,
    }


def find_missing(conn, channel, target_table):
    status_list = ",".join(["%s"] * len(APPROVED_STATUSES))
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT hl.*
              FROM hotmart_sales_live hl
              LEFT JOIN {target_table} t ON t.transaction_code = hl.transaction_code
             WHERE hl.sales_channel = %s
               AND UPPER(COALESCE(hl.status, '')) IN ({status_list})
               AND t.transaction_code IS NULL
             ORDER BY hl.transaction_date ASC
            """,
            [channel] + APPROVED_STATUSES,
        )
        return cur.fetchall()


def insert_hotmart_sales(cur, row):
    gross = float(row["gross_revenue"] or 0)
    producer = float(row["producer_net"] or 0) or gross
    fees = max(0.0, gross - producer)
    cur.execute(
        """
        INSERT INTO hotmart_sales (
            transaction_code, status, product_id, product_name, price_code, price_name,
            gross_revenue, net_revenue, producer_net, fees, refunded_value,
            buyer_name, buyer_email, buyer_phone, payment_type, installments,
            sale_date, payment_confirmed_at, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
            raw_payload_json, created_at, updated_at
        ) VALUES (
            %(transaction_code)s, 'APPROVED', %(product_code)s, %(product_name)s, %(price_code)s, %(price_name)s,
            %(gross_revenue)s, %(net_revenue)s, %(producer_net)s, %(fees)s, %(refunded_value)s,
            %(buyer_name)s, %(buyer_email)s, %(buyer_phone_raw)s, %(payment_type)s, %(installments)s,
            %(transaction_date)s, %(payment_confirmed_at)s, %(utm_source)s, %(utm_medium)s, %(utm_campaign)s, %(utm_term)s, %(utm_content)s,
            %(raw_payload_json)s, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            status='APPROVED', gross_revenue=VALUES(gross_revenue), net_revenue=VALUES(net_revenue),
            producer_net=VALUES(producer_net), fees=VALUES(fees), buyer_name=VALUES(buyer_name),
            buyer_email=VALUES(buyer_email), buyer_phone=VALUES(buyer_phone),
            payment_confirmed_at=VALUES(payment_confirmed_at), updated_at=NOW()
        """,
        {
            **row,
            "producer_net": producer,
            "fees": fees,
            "installments": row.get("installments_number") or 1,
        },
    )
    return cur.rowcount


def insert_provider_sales(cur, target_table, channel, row):
    gross = float(row["gross_revenue"] or 0)
    producer = float(row["producer_net"] or 0) or gross
    fees = max(0.0, gross - producer)
    cur.execute(
        f"""
        INSERT INTO {target_table} (
            transaction_code, status, checkout_platform, product_name,
            amount_cents, fee_cents, net_cents, gross_revenue, net_revenue, producer_net, fees,
            buyer_name, buyer_email, buyer_phone, payment_method, installments,
            sale_date, payment_confirmed_at, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
            raw_payload_json, created_at, updated_at
        ) VALUES (
            %(transaction_code)s, 'APPROVED', %(channel)s, %(product_name)s,
            %(amount_cents)s, %(fee_cents)s, %(net_cents)s, %(gross_revenue)s, %(net_revenue)s, %(producer_net)s, %(fees)s,
            %(buyer_name)s, %(buyer_email)s, %(buyer_phone_raw)s, %(payment_type)s, %(installments)s,
            %(transaction_date)s, %(payment_confirmed_at)s, %(utm_source)s, %(utm_medium)s, %(utm_campaign)s, %(utm_term)s, %(utm_content)s,
            %(raw_payload_json)s, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            status='APPROVED', gross_revenue=VALUES(gross_revenue), net_revenue=VALUES(net_revenue),
            producer_net=VALUES(producer_net), amount_cents=VALUES(amount_cents), net_cents=VALUES(net_cents),
            buyer_name=VALUES(buyer_name), buyer_email=VALUES(buyer_email), buyer_phone=VALUES(buyer_phone),
            payment_confirmed_at=VALUES(payment_confirmed_at), updated_at=NOW()
        """,
        {
            **row,
            "channel": channel,
            "producer_net": producer,
            "fees": fees,
            "amount_cents": round(gross * 100),
            "fee_cents": round(fees * 100),
            "net_cents": round(producer * 100),
            "installments": row.get("installments_number") or 1,
        },
    )
    return cur.rowcount


CHANNEL_TARGETS = {
    "hotmart": ("hotmart_sales", insert_hotmart_sales),
    "dom": ("dom_sales", lambda cur, row: insert_provider_sales(cur, "dom_sales", "dom", row)),
    "pagarme": ("pagarme_sales", lambda cur, row: insert_provider_sales(cur, "pagarme_sales", "pagarme", row)),
}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Aplica de fato. Sem isso, roda dry-run.")
    args = parser.parse_args()

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    report = {"mode": "apply" if args.apply else "dry-run", "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"), "channels": {}}

    with pymysql.connect(**db_config()) as conn:
        for channel, (target_table, inserter) in CHANNEL_TARGETS.items():
            missing = find_missing(conn, channel, target_table)
            gross_sum = sum(float(r["gross_revenue"] or 0) for r in missing)
            report["channels"][channel] = {
                "target_table": target_table,
                "missing_count": len(missing),
                "missing_gross_revenue_sum": round(gross_sum, 2),
                "sample_transaction_codes": [r["transaction_code"] for r in missing[:10]],
            }

            if args.apply and missing:
                conn.begin()
                inserted = 0
                with conn.cursor() as cur:
                    for row in missing:
                        inserted += inserter(cur, row)
                conn.commit()
                report["channels"][channel]["applied_rowcount"] = inserted

    out_name = "backfill_applied.json" if args.apply else "backfill_dry_run.json"
    (OUT_DIR / out_name).write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
