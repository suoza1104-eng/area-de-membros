import csv
import json
import re
from collections import Counter
from datetime import datetime
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = Path(r"C:\Users\Emerson\Downloads\4a80f28b9c7d3c90dffb7467e7507fa79995.csv")
OUT_DIR = ROOT / "reports" / "dom_auditoria"


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
    if s in {"approved", "paid", "aprovado"}:
        return "APPROVED"
    if s in {"pending", "capture", "revision_paid", "pendente"}:
        return "PENDING"
    if s in {"refunded", "pending_refund", "reembolsado"}:
        return "REFUNDED"
    if s in {"chargeback", "in_mediation", "dispute_pending", "dispute", "em disputa"}:
        return "CHARGEBACK"
    if s in {"failed", "not_authorized", "expired", "cancelled_capture", "canceled", "cancelled", "cancelado", "error", "falha na transacao", "falha na transa��o"}:
        return "CANCELED"
    return "UNKNOWN"


def money_to_cents(value):
    text = str(value or "").strip()
    if text == "":
        return 0
    text = text.replace("R$", "").replace(" ", "")
    if "," in text and "." in text:
        text = text.replace(".", "").replace(",", ".")
    elif "," in text:
        text = text.replace(",", ".")
    try:
        return int(round(float(text) * 100))
    except ValueError:
        return 0


def cents_money(value):
    return round(int(value or 0) / 100, 2)


def parse_dt(value):
    text = str(value or "").strip().strip('"')
    if not text:
        return None
    try:
        return datetime.strptime(text[:19], "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return None


def load_report():
    with CSV_PATH.open("r", encoding="utf-8-sig", errors="replace", newline="") as fh:
        sample = fh.readline()
        delimiter = "\t" if sample.count("\t") >= sample.count(";") else ";"
        fh.seek(0)
        reader = csv.DictReader(fh, delimiter=delimiter)
        rows = {}
        duplicates = []
        for line_no, row in enumerate(reader, start=2):
            tx = (row.get("id_transaction") or row.get("order_id") or "").strip()
            if not tx:
                continue
            item = {
                "line": line_no,
                "transaction_id": tx,
                "db_transaction_id": "dom:" + tx,
                "status": (row.get("status_type") or row.get("type_status") or row.get("status") or "").strip(),
                "normalized_status": norm_status(row.get("status_type") or row.get("type_status") or row.get("status")),
                "gross_amount_cents": money_to_cents(row.get("total")),
                "net_amount_cents": money_to_cents(row.get("total_liquid")),
                "fee_amount_cents": money_to_cents(row.get("mdr_value")) + money_to_cents(row.get("fee_installment_value")) + money_to_cents(row.get("fee_transaction")),
                "created_at": parse_dt(row.get("create_date")),
                "updated_at": parse_dt(row.get("last_date")),
                "paid_at": parse_dt(row.get("paid_date")),
                "payment_method": (row.get("type_payment") or "").strip(),
                "product_name": (row.get("item_name") or row.get("product_first") or "").strip().strip('"'),
                "buyer_name": (row.get("client_name") or "").strip().strip('"'),
                "buyer_email": (row.get("client_email") or "").strip().lower(),
                "buyer_phone": (row.get("client_phone") or "").strip(),
                "buyer_document": (row.get("client_document") or "").strip(),
                "raw": row,
            }
            if tx in rows:
                duplicates.append(tx)
                if item["normalized_status"] == "UNKNOWN" and item["gross_amount_cents"] == 0 and item["net_amount_cents"] == 0:
                    continue
                if rows[tx]["normalized_status"] != "UNKNOWN" or rows[tx]["gross_amount_cents"] > 0:
                    continue
            rows[tx] = item
    return rows, duplicates


def load_db():
    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT id, external_transaction_id, external_checkout_id, transaction_type,
                       provider_status, normalized_status, currency, gross_amount_cents,
                       net_amount_cents, fee_amount_cents, product_name, buyer_name,
                       buyer_email, buyer_phone, buyer_document, matched_user_id, match_method,
                       first_received_at, last_received_at, created_at, updated_at
                  FROM payment_sales
                 WHERE provider='dom'
                """
            )
            rows = cur.fetchall()
    by_external = {row["external_transaction_id"]: row for row in rows}
    return rows, by_external


def write_csv(path, rows):
    fields = [
        "tipo", "transaction_id", "db_transaction_id", "detalhe", "report_status", "db_status",
        "report_gross", "db_gross", "report_net", "db_net", "report_created", "db_last_received",
        "report_email", "db_email", "report_name", "db_name", "db_user_id", "db_match",
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
        "transaction_id": report.get("transaction_id") or str(db.get("external_transaction_id") or "").removeprefix("dom:"),
        "db_transaction_id": report.get("db_transaction_id") or db.get("external_transaction_id") or "",
        "detalhe": detail,
        "report_status": report.get("normalized_status", ""),
        "db_status": db.get("normalized_status", ""),
        "report_gross": f"{cents_money(report.get('gross_amount_cents', 0)):.2f}" if report else "",
        "db_gross": f"{cents_money(db.get('gross_amount_cents', 0)):.2f}" if db else "",
        "report_net": f"{cents_money(report.get('net_amount_cents', 0)):.2f}" if report else "",
        "db_net": f"{cents_money(db.get('net_amount_cents', 0)):.2f}" if db else "",
        "report_created": report.get("created_at").strftime("%Y-%m-%d %H:%M:%S") if report.get("created_at") else "",
        "db_last_received": str(db.get("last_received_at") or ""),
        "report_email": report.get("buyer_email", ""),
        "db_email": db.get("buyer_email", ""),
        "report_name": report.get("buyer_name", ""),
        "db_name": db.get("buyer_name", ""),
        "db_user_id": str(db.get("matched_user_id") or ""),
        "db_match": db.get("match_method", ""),
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
    db_rows, db = load_db()

    matched = {}
    for tx, row in report.items():
        match = db.get("dom:" + tx) or db.get(tx)
        if match:
            matched[tx] = match

    report_keys = set(report)
    matched_keys = set(matched)
    db_report_keys = {"dom:" + tx for tx in report_keys} | report_keys
    db_keys = set(db)

    missing = sorted(report_keys - matched_keys, key=lambda tx: report[tx].get("created_at") or datetime.min)
    extra = sorted(db_keys - db_report_keys, key=lambda tx: db[tx].get("last_received_at") or datetime.min)
    divergences = []
    for tx in sorted(matched_keys, key=lambda x: report[x].get("created_at") or datetime.min):
        r = report[tx]
        d = matched[tx]
        notes = []
        if r["normalized_status"] != d.get("normalized_status"):
            notes.append(f"status {r['normalized_status']} != {d.get('normalized_status')}")
        if int(r["gross_amount_cents"]) != int(d.get("gross_amount_cents") or 0):
            notes.append(f"bruto diff {cents_money(int(r['gross_amount_cents']) - int(d.get('gross_amount_cents') or 0)):.2f}")
        if int(r["net_amount_cents"]) != int(d.get("net_amount_cents") or 0):
            notes.append(f"liquido diff {cents_money(int(r['net_amount_cents']) - int(d.get('net_amount_cents') or 0)):.2f}")
        if r["buyer_email"] and str(d.get("buyer_email") or "").lower() != r["buyer_email"]:
            notes.append("email diferente")
        if notes:
            divergences.append(out_row("divergente", r, d, " | ".join(notes)))

    missing_rows = [out_row("faltando_no_banco", report[tx], None) for tx in missing]
    extra_rows = [out_row("sobrando_no_banco", None, db[tx]) for tx in extra]
    all_rows = missing_rows + extra_rows + divergences
    write_csv(OUT_DIR / "divergencias_dom.csv", all_rows)
    write_csv(OUT_DIR / "faltando_no_banco.csv", missing_rows)
    write_csv(OUT_DIR / "sobrando_no_banco.csv", extra_rows)
    write_csv(OUT_DIR / "campos_divergentes.csv", divergences)

    summary = {
        "csv_path": str(CSV_PATH),
        "db_table": "payment_sales",
        "db_provider": "dom",
        "match_key": "id_transaction/order_id contra external_transaction_id com e sem prefixo dom:",
        "report_total_rows": len(report),
        "db_total_rows": len(db_rows),
        "report_status_counts": dict(Counter(row["normalized_status"] for row in report.values())),
        "db_status_counts": dict(Counter(row["normalized_status"] for row in db_rows)),
        "report_gross_total": cents_money(sum_cents(report.values(), "gross_amount_cents")),
        "db_gross_total": cents_money(sum_cents(db_rows, "gross_amount_cents")),
        "report_net_total": cents_money(sum_cents(report.values(), "net_amount_cents")),
        "db_net_total": cents_money(sum_cents(db_rows, "net_amount_cents")),
        "report_approved_count": sum(1 for row in report.values() if row["normalized_status"] == "APPROVED"),
        "db_approved_count": sum(1 for row in db_rows if row["normalized_status"] == "APPROVED"),
        "report_approved_gross": cents_money(sum_cents(report.values(), "gross_amount_cents", {"APPROVED"})),
        "db_approved_gross": cents_money(sum_cents(db_rows, "gross_amount_cents", {"APPROVED"})),
        "report_approved_net": cents_money(sum_cents(report.values(), "net_amount_cents", {"APPROVED"})),
        "db_approved_net": cents_money(sum_cents(db_rows, "net_amount_cents", {"APPROVED"})),
        "missing_in_db_count": len(missing_rows),
        "extra_in_db_count": len(extra_rows),
        "field_divergence_count": len(divergences),
        "duplicates_in_report": duplicates,
        "outputs": {
            "all_divergences": str(OUT_DIR / "divergencias_dom.csv"),
            "missing_in_db": str(OUT_DIR / "faltando_no_banco.csv"),
            "extra_in_db": str(OUT_DIR / "sobrando_no_banco.csv"),
            "field_divergences": str(OUT_DIR / "campos_divergentes.csv"),
        },
    }
    summary["approved_count_diff_report_minus_db"] = summary["report_approved_count"] - summary["db_approved_count"]
    summary["approved_gross_diff_report_minus_db"] = round(summary["report_approved_gross"] - summary["db_approved_gross"], 2)
    summary["approved_net_diff_report_minus_db"] = round(summary["report_approved_net"] - summary["db_approved_net"], 2)
    (OUT_DIR / "resumo.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2, default=str), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
