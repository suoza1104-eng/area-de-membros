"""
Repara o dano causado pelo bug de prefixo em admin/import_vendas_hotmart.php
(hmri_sale_from_csv_row nao normalizava o transaction_code da Hotmart para o
formato "hotmart:HP..." usado pelo resto do sistema).

Duas consequencias diretas do upload de "Conciliar Vendas" feito em
05/09/2026 ~22:28-22:29, ambas corrigidas aqui:

1) Vendas que JA existiam (com o prefixo "hotmart:") ganharam uma linha
   DUPLICADA sem prefixo, criada pela propria tela de conciliacao.
2) Pior: como a comparacao "vendas ausentes na planilha autoritativa"
   (hmri_load_missing_in_file) compara pelo transaction_code exato, TODA
   venda existente com prefixo "hotmart:" foi considerada "ausente da
   planilha" (mesmo estando la, so que sem o prefixo) e teve status
   trocado para CANCELED + excluded_from_financials=1 por engano.

Estrategia (idempotente, roda em transacao, dry-run por padrao):
  a) Para toda linha "hotmart:XXX" marcada reconciliation_status =
     'missing_in_authoritative_import' que tenha uma gemea sem prefixo
     "XXX": restaura status/valores a partir da gemea (que reflete o que a
     planilha realmente diz) e limpa as flags de exclusao.
  b) Deleta a linha gemea sem prefixo (agora redundante) em
     hotmart_sales_live e hotmart_sales.
  c) Para pares duplicados que NAO tinham sido cancelados por engano
     (ambos ja aprovados, ex: venda nova do dia), so deleta a linha sem
     prefixo, sem mexer na original.

Uso:
    python corrigir_duplicacao_conciliar_hotmart.py            # dry-run
    python corrigir_duplicacao_conciliar_hotmart.py --apply     # aplica
"""
import argparse
import json
import re
from datetime import datetime
from pathlib import Path

import pymysql

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "reports" / "conciliar_hotmart_dedup"


def db_config():
    config = (ROOT / "app" / "config.php").read_text(encoding="utf-8", errors="ignore")
    values = {}
    for key in ("DB_HOST", "DB_USER", "DB_PASS", "DB_NAME"):
        match = re.search(r"define\(\s*['\"]" + key + r"['\"]\s*,\s*['\"]([^'\"]*)['\"]", config)
        if not match:
            raise RuntimeError(f"{key} nao encontrado em app/config.php")
        values[key] = match.group(1)
    return {
        "host": values["DB_HOST"], "user": values["DB_USER"], "password": values["DB_PASS"],
        "database": values["DB_NAME"], "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
        "connect_timeout": 8, "read_timeout": 120, "write_timeout": 120,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Aplica de fato. Sem isso, roda dry-run.")
    args = parser.parse_args()

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    report = {"mode": "apply" if args.apply else "dry-run", "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")}

    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            # --- passo a: contar / restaurar cancelamentos indevidos em hotmart_sales_live ---
            cur.execute("""
                SELECT COUNT(*) AS n FROM hotmart_sales_live p
                JOIN hotmart_sales_live b ON b.transaction_code = SUBSTRING(p.transaction_code, 9)
                WHERE p.transaction_code LIKE 'hotmart:%'
                  AND p.reconciliation_status = 'missing_in_authoritative_import'
            """)
            report['wrongly_canceled_live'] = cur.fetchone()['n']

            cur.execute("""
                SELECT COUNT(*) AS n FROM hotmart_sales p
                JOIN hotmart_sales b ON b.transaction_code = SUBSTRING(p.transaction_code, 9)
                WHERE p.transaction_code LIKE 'hotmart:%' AND p.status = 'CANCELED'
                  AND b.status <> 'CANCELED'
            """)
            report['wrongly_canceled_master'] = cur.fetchone()['n']

            cur.execute("""
                SELECT COUNT(*) AS n FROM hotmart_sales_live p
                JOIN hotmart_sales_live b ON b.transaction_code = SUBSTRING(p.transaction_code, 9)
                WHERE p.transaction_code LIKE 'hotmart:%'
            """)
            report['total_duplicate_pairs_live'] = cur.fetchone()['n']

            cur.execute("""
                SELECT COUNT(*) AS n FROM hotmart_sales p
                JOIN hotmart_sales b ON b.transaction_code = SUBSTRING(p.transaction_code, 9)
                WHERE p.transaction_code LIKE 'hotmart:%'
            """)
            report['total_duplicate_pairs_master'] = cur.fetchone()['n']

            if args.apply:
                conn.begin()

                # a) restaura as canceladas por engano (hotmart_sales_live)
                cur.execute("""
                    UPDATE hotmart_sales_live p
                    JOIN hotmart_sales_live b ON b.transaction_code = SUBSTRING(p.transaction_code, 9)
                    SET p.status = b.status,
                        p.gross_revenue = b.gross_revenue,
                        p.net_revenue = b.net_revenue,
                        p.producer_net = b.producer_net,
                        p.refunded_value = b.refunded_value,
                        p.chargeback_value = b.chargeback_value,
                        p.reconciliation_status = 'confirmed_by_import',
                        p.excluded_from_financials = 0,
                        p.excluded_reason = NULL,
                        p.confirmed_by_import_at = NOW(),
                        p.updated_at = NOW()
                    WHERE p.transaction_code LIKE 'hotmart:%'
                      AND p.reconciliation_status = 'missing_in_authoritative_import'
                """)
                report['restored_live'] = cur.rowcount

                # a) restaura as canceladas por engano (hotmart_sales, tabela master)
                cur.execute("""
                    UPDATE hotmart_sales p
                    JOIN hotmart_sales b ON b.transaction_code = SUBSTRING(p.transaction_code, 9)
                    SET p.status = b.status,
                        p.gross_revenue = b.gross_revenue,
                        p.net_revenue = b.net_revenue,
                        p.producer_net = b.producer_net,
                        p.fees = GREATEST(0, b.gross_revenue - b.producer_net),
                        p.refunded_value = b.refunded_value,
                        p.updated_at = NOW()
                    WHERE p.transaction_code LIKE 'hotmart:%' AND p.status = 'CANCELED'
                      AND b.status <> 'CANCELED'
                """)
                report['restored_master'] = cur.rowcount

                # b/c) apaga as gemeas sem prefixo, agora redundantes, nas duas tabelas
                cur.execute("""
                    DELETE b FROM hotmart_sales_live b
                    JOIN hotmart_sales_live p ON p.transaction_code = CONCAT('hotmart:', b.transaction_code)
                    WHERE b.transaction_code NOT LIKE 'hotmart:%'
                      AND b.transaction_code NOT LIKE 'dom:%'
                      AND b.transaction_code NOT LIKE 'pagarme:%'
                """)
                report['deleted_dupes_live'] = cur.rowcount

                cur.execute("""
                    DELETE b FROM hotmart_sales b
                    JOIN hotmart_sales p ON p.transaction_code = CONCAT('hotmart:', b.transaction_code)
                    WHERE b.transaction_code NOT LIKE 'hotmart:%'
                      AND b.transaction_code NOT LIKE 'dom:%'
                      AND b.transaction_code NOT LIKE 'pagarme:%'
                """)
                report['deleted_dupes_master'] = cur.rowcount

                conn.commit()

    out_name = "repair_applied.json" if args.apply else "repair_dry_run.json"
    (OUT_DIR / out_name).write_text(json.dumps(report, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
