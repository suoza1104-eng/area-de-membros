import csv
import json
import re
import zipfile
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
ZIP_PATH = Path(r"C:\Users\Emerson\Downloads\a83f4373-a537-42d5-9d59-2e6cd82a8fbb.zip")
OUT_DIR = ROOT / "reports" / "pagarme_auditoria"


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


def norm_status(status):
    s = str(status or "").strip().lower()
    if s in {"paid", "captured"}:
        return "APPROVED"
    if s in {"pending", "processing"}:
        return "PENDING"
    if s in {"refunded"}:
        return "REFUNDED"
    if s in {"chargedback", "chargeback"}:
        return "CHARGEBACK"
    if s in {"failed", "canceled", "cancelled"}:
        return "CANCELED"
    return "UNKNOWN"


def cents(value):
    text = str(value or "").strip()
    if text == "":
        return 0
    try:
        return int(round(float(text.replace(",", "."))))
    except ValueError:
        return 0


def money(cents_value):
    return round(int(cents_value or 0) / 100, 2)


def parse_dt(value):
    text = str(value or "").strip()
    if not text:
        return None
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
        try:
            return datetime.strptime(text[:19], fmt)
        except ValueError:
            pass
    return None


def load_report():
    with zipfile.ZipFile(ZIP_PATH) as zf:
        names = [n for n in zf.namelist() if n.lower().endswith(".csv")]
        if len(names) != 1:
            raise RuntimeError(f"Esperado 1 CSV no ZIP, encontrado {len(names)}")
        raw = zf.read(names[0])
    text = raw.decode("utf-8-sig", errors="replace")
    rows = {}
    duplicates = []
    reader = csv.DictReader(text.splitlines(), delimiter=";")
    for line_no, row in enumerate(reader, start=2):
        charge_id = (row.get("Charge_ID") or "").strip()
        if not charge_id:
            continue
        tx = "pagarme:" + charge_id
        item = {
            "line": line_no,
            "transaction_id": tx,
            "charge_id": charge_id,
            "order_id": (row.get("Order_Id") or "").strip(),
            "code": (row.get("Code") or "").strip(),
            "status": (row.get("Status") or "").strip(),
            "normalized_status": norm_status(row.get("Status")),
            "amount_cents": cents(row.get("Amount_In_Cents")),
            "created_date": parse_dt(row.get("Created_Date")),
            "updated_at": parse_dt(row.get("Updated_At")),
            "customer_name": (row.get("Customer_Name") or "").strip(),
            "customer_email": (row.get("Customer_Email") or "").strip().lower(),
            "customer_document": (row.get("Customer_Document") or "").strip(),
            "cell_phone": (row.get("Customer_Cell_phone") or "").strip(),
            "metadata": (row.get("Metadata") or "").strip(),
        }
        if tx in rows:
            duplicates.append(tx)
        rows[tx] = item
    return rows, duplicates


def load_db():
    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT id, external_transaction_id, external_checkout_id, transaction_type,
                       provider_status, normalized_status, gross_amount_cents,
                       net_amount_cents, fee_amount_cents, product_name, buyer_name,
                       buyer_email, buyer_document, matched_user_id, match_method,
                       first_received_at, last_received_at, created_at, updated_at
                  FROM payment_sales
                 WHERE provider = 'pagarme'
                """
            )
            sales = {row["external_transaction_id"]: row for row in cur.fetchall()}
            cur.execute(
                """
                SELECT external_transaction_id, COUNT(*) events, MIN(received_at) first_event, MAX(received_at) last_event
                  FROM pagarme_webhook_events
                 GROUP BY external_transaction_id
                """
            )
            events = {row["external_transaction_id"]: row for row in cur.fetchall() if row["external_transaction_id"]}
    return sales, events


def write_csv(path, rows):
    fields = [
        "tipo", "transaction_id", "detalhe", "report_status", "db_status", "report_amount",
        "db_gross", "db_net", "report_created", "db_last_received", "report_email",
        "db_email", "report_name", "db_name", "db_match", "db_user_id",
    ]
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields, delimiter=";")
        writer.writeheader()
        writer.writerows(rows)


def out_row(kind, report=None, db=None, detail=""):
    report = report or {}
    db = db or {}
    return {
        "tipo": kind,
        "transaction_id": report.get("transaction_id") or db.get("external_transaction_id") or "",
        "detalhe": detail,
        "report_status": report.get("normalized_status", ""),
        "db_status": db.get("normalized_status", ""),
        "report_amount": f"{money(report.get('amount_cents', 0)):.2f}" if report else "",
        "db_gross": f"{money(db.get('gross_amount_cents', 0)):.2f}" if db else "",
        "db_net": f"{money(db.get('net_amount_cents', 0)):.2f}" if db else "",
        "report_created": report.get("created_date").strftime("%Y-%m-%d %H:%M:%S") if report.get("created_date") else "",
        "db_last_received": str(db.get("last_received_at") or ""),
        "report_email": report.get("customer_email", ""),
        "db_email": db.get("buyer_email", ""),
        "report_name": report.get("customer_name", ""),
        "db_name": db.get("buyer_name", ""),
        "db_match": db.get("match_method", ""),
        "db_user_id": str(db.get("matched_user_id") or ""),
    }


def sum_cents(rows, key, statuses=None):
    total = 0
    for row in rows:
        if statuses and row.get("normalized_status") not in statuses:
            continue
        total += int(row.get(key) or 0)
    return total


def main():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    report, duplicates = load_report()
    db, events = load_db()
    report_keys = set(report)
    db_keys = set(db)

    missing = sorted(report_keys - db_keys, key=lambda tx: report[tx].get("created_date") or datetime.min)
    extra = sorted(db_keys - report_keys, key=lambda tx: db[tx].get("last_received_at") or datetime.min)
    divergences = []

    for tx in sorted(report_keys & db_keys, key=lambda tx: report[tx].get("created_date") or datetime.min):
        r = report[tx]
        d = db[tx]
        notes = []
        if r["normalized_status"] != d.get("normalized_status"):
            notes.append(f"status {r['normalized_status']} != {d.get('normalized_status')}")
        if int(r["amount_cents"]) != int(d.get("gross_amount_cents") or 0):
            notes.append(f"bruto diff {money(int(r['amount_cents']) - int(d.get('gross_amount_cents') or 0)):.2f}")
        if r["customer_email"] and str(d.get("buyer_email") or "").lower() != r["customer_email"]:
            notes.append("email diferente")
        if notes:
            divergences.append(out_row("divergente", r, d, " | ".join(notes)))

    missing_rows = [out_row("faltando_no_banco", report[tx], None) for tx in missing]
    extra_rows = [out_row("sobrando_no_banco", None, db[tx]) for tx in extra]
    all_rows = missing_rows + extra_rows + divergences

    write_csv(OUT_DIR / "divergencias_pagarme.csv", all_rows)
    write_csv(OUT_DIR / "faltando_no_banco.csv", missing_rows)
    write_csv(OUT_DIR / "sobrando_no_banco.csv", extra_rows)
    write_csv(OUT_DIR / "campos_divergentes.csv", divergences)

    by_status_report = Counter(row["normalized_status"] for row in report.values())
    by_status_db = Counter(row["normalized_status"] for row in db.values())
    summary = {
        "zip_path": str(ZIP_PATH),
        "db_table": "payment_sales",
        "db_provider": "pagarme",
        "match_key": "report Charge_ID -> payment_sales.external_transaction_id = pagarme:<Charge_ID>",
        "report_total_rows": len(report),
        "db_total_rows": len(db),
        "report_status_counts": dict(by_status_report),
        "db_status_counts": dict(by_status_db),
        "report_gross_total": money(sum_cents(report.values(), "amount_cents")),
        "db_gross_total": money(sum_cents(db.values(), "gross_amount_cents")),
        "db_net_total": money(sum_cents(db.values(), "net_amount_cents")),
        "report_approved_count": sum(1 for row in report.values() if row["normalized_status"] == "APPROVED"),
        "db_approved_count": sum(1 for row in db.values() if row["normalized_status"] == "APPROVED"),
        "report_approved_gross": money(sum_cents(report.values(), "amount_cents", {"APPROVED"})),
        "db_approved_gross": money(sum_cents(db.values(), "gross_amount_cents", {"APPROVED"})),
        "db_approved_net": money(sum_cents(db.values(), "net_amount_cents", {"APPROVED"})),
        "missing_in_db_count": len(missing_rows),
        "extra_in_db_count": len(extra_rows),
        "field_divergence_count": len(divergences),
        "duplicates_in_report": duplicates,
        "db_webhook_transactions": len(events),
        "outputs": {
            "all_divergences": str(OUT_DIR / "divergencias_pagarme.csv"),
            "missing_in_db": str(OUT_DIR / "faltando_no_banco.csv"),
            "extra_in_db": str(OUT_DIR / "sobrando_no_banco.csv"),
            "field_divergences": str(OUT_DIR / "campos_divergentes.csv"),
        },
    }
    summary["approved_count_diff_report_minus_db"] = summary["report_approved_count"] - summary["db_approved_count"]
    summary["approved_gross_diff_report_minus_db"] = round(summary["report_approved_gross"] - summary["db_approved_gross"], 2)

    (OUT_DIR / "resumo.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
