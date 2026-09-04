import argparse
import csv
import json
import re
from datetime import datetime
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = Path(r"C:\Users\Emerson\Downloads\sales_history_20260825230545_2F5E29F716253618490943717533.csv")
OUT_DIR = ROOT / "reports" / "hotmart_agosto_2026_auditoria"


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
        "write_timeout": 20,
    }


def norm_header(value):
    value = value.replace("\ufeff", "").strip().lower()
    value = re.sub(r"[^a-z0-9áàâãéêíóôõúç]+", "", value)
    return value.translate(str.maketrans("áàâãéêíóôõúç", "aaaaeeiooouc"))


def pick(row, mapping, name):
    idx = mapping.get(norm_header(name))
    return "" if idx is None or idx >= len(row) else str(row[idx]).strip()


def parse_csv_complete_transactions():
    with CSV_PATH.open("r", encoding="utf-8-sig", newline="") as fh:
        first = fh.readline()
        sep = ";" if first.count(";") >= first.count(",") else ","
        fh.seek(0)
        reader = csv.reader(fh, delimiter=sep, quotechar='"')
        headers = next(reader)
        mapping = {norm_header(h): i for i, h in enumerate(headers)}
        rows = {}
        for line_no, row in enumerate(reader, start=2):
            tx = pick(row, mapping, "Código da transação")
            status = pick(row, mapping, "Status da transação")
            if tx and status.lower() == "completo":
                rows[tx] = {
                    "line": line_no,
                    "transaction_code": tx,
                    "csv_status": status,
                    "csv_transaction_date": pick(row, mapping, "Data da transação"),
                    "csv_payment_confirmed_at": pick(row, mapping, "Confirmação do pagamento"),
                    "csv_product": pick(row, mapping, "Produto"),
                    "csv_email": pick(row, mapping, "Email do(a) Comprador(a)"),
                    "csv_producer_net": pick(row, mapping, "Faturamento líquido do(a) Produtor(a)"),
                }
        return rows


def load_candidates(conn, complete_rows):
    txs = sorted(complete_rows)
    if not txs:
        return []
    candidates = []
    with conn.cursor() as cur:
        for start in range(0, len(txs), 400):
            chunk = txs[start:start + 400]
            placeholders = ",".join(["%s"] * len(chunk))
            cur.execute(
                f"""
                SELECT transaction_code,status,webhook_event,transaction_date,payment_confirmed_at,
                       product_name,buyer_email,producer_net,updated_at
                  FROM hotmart_sales_live
                 WHERE transaction_code IN ({placeholders})
                   AND UPPER(COALESCE(status,'')) IN ('PENDENTE','PENDING')
                   AND transaction_date >= '2026-08-01 00:00:00'
                   AND transaction_date < '2026-09-01 00:00:00'
                   AND COALESCE(NULLIF(sales_channel,''),'hotmart') = 'hotmart'
                """,
                chunk,
            )
            for row in cur.fetchall():
                csv_row = complete_rows[str(row["transaction_code"])]
                candidates.append({**csv_row, **{f"db_{k}": v for k, v in row.items()}})
    return candidates


def write_candidates(candidates):
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    path = OUT_DIR / "complete_backfill_candidates.csv"
    fields = [
        "transaction_code", "csv_status", "db_status", "csv_transaction_date", "db_transaction_date",
        "csv_payment_confirmed_at", "db_payment_confirmed_at", "csv_product", "db_product_name",
        "csv_email", "db_buyer_email", "csv_producer_net", "db_producer_net", "db_webhook_event", "db_updated_at",
    ]
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields, delimiter=";")
        writer.writeheader()
        for row in candidates:
            writer.writerow({key: row.get(key, "") for key in fields})
    return path


def apply_backfill(conn, candidates):
    txs = [row["transaction_code"] for row in candidates]
    if not txs:
        return {"hotmart_sales_live": 0, "hotmart_sales": 0}
    updated_live = 0
    updated_legacy = 0
    now_event = "CSV_COMPLETE_BACKFILL"
    with conn.cursor() as cur:
        for start in range(0, len(txs), 400):
            chunk = txs[start:start + 400]
            placeholders = ",".join(["%s"] * len(chunk))
            cur.execute(
                f"""
                UPDATE hotmart_sales_live
                   SET status = 'Completo',
                       webhook_event = %s,
                       webhook_event_id = CONCAT('csv-complete-backfill:', transaction_code),
                       updated_at = NOW()
                 WHERE transaction_code IN ({placeholders})
                   AND UPPER(COALESCE(status,'')) IN ('PENDENTE','PENDING')
                   AND transaction_date >= '2026-08-01 00:00:00'
                   AND transaction_date < '2026-09-01 00:00:00'
                   AND COALESCE(NULLIF(sales_channel,''),'hotmart') = 'hotmart'
                """,
                [now_event] + chunk,
            )
            updated_live += cur.rowcount
            cur.execute(
                f"""
                UPDATE hotmart_sales
                   SET status = 'Completo',
                       updated_at = NOW()
                 WHERE transaction_code IN ({placeholders})
                   AND UPPER(COALESCE(status,'')) IN ('PENDENTE','PENDING')
                """,
                chunk,
            )
            updated_legacy += cur.rowcount
    return {"hotmart_sales_live": updated_live, "hotmart_sales": updated_legacy}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Aplica a atualizacao no banco. Sem isso, roda dry-run.")
    args = parser.parse_args()

    complete_rows = parse_csv_complete_transactions()
    with pymysql.connect(**db_config()) as conn:
        candidates = load_candidates(conn, complete_rows)
        candidates_path = write_candidates(candidates)
        result = {
            "mode": "apply" if args.apply else "dry-run",
            "csv_complete_count": len(complete_rows),
            "candidate_pending_count": len(candidates),
            "candidates_file": str(candidates_path),
            "applied": None,
            "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }
        if args.apply:
            conn.begin()
            result["applied"] = apply_backfill(conn, candidates)
            conn.commit()
    summary_path = OUT_DIR / ("complete_backfill_applied.json" if args.apply else "complete_backfill_dry_run.json")
    summary_path.write_text(json.dumps(result, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
