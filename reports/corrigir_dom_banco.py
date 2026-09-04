import csv
import json
import re
from datetime import datetime
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = Path(r"C:\Users\Emerson\Downloads\4a80f28b9c7d3c90dffb7467e7507fa79995.csv")
AUDIT_DIR = ROOT / "reports" / "dom_auditoria"


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


def dt(value):
    text = str(value or "").strip().strip('"')
    if not text:
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    return text[:19]


def load_report_rows():
    with CSV_PATH.open("r", encoding="utf-8-sig", errors="replace", newline="") as fh:
        sample = fh.readline()
        delimiter = "\t" if sample.count("\t") >= sample.count(";") else ";"
        fh.seek(0)
        reader = csv.DictReader(fh, delimiter=delimiter)
        rows = {}
        for row in reader:
            tx = (row.get("id_transaction") or row.get("order_id") or "").strip()
            if not tx:
                continue
            normalized = norm_status(row.get("status_type") or row.get("type_status") or row.get("status"))
            gross = money_to_cents(row.get("total"))
            net = money_to_cents(row.get("total_liquid"))
            if tx in rows and normalized == "UNKNOWN" and gross == 0 and net == 0:
                continue
            if tx in rows and (rows[tx]["normalized_status"] != "UNKNOWN" or rows[tx]["gross_amount_cents"] > 0):
                continue
            rows[tx] = {
                "transaction_id": tx,
                "external_transaction_id": "dom:" + tx,
                "provider_status": row.get("type_status") or row.get("status_type") or row.get("status") or None,
                "normalized_status": normalized,
                "gross_amount_cents": gross,
                "net_amount_cents": net,
                "fee_amount_cents": money_to_cents(row.get("mdr_value")) + money_to_cents(row.get("fee_installment_value")) + money_to_cents(row.get("fee_transaction")),
                "product_amount_cents": money_to_cents(row.get("item_price")) or gross,
                "installments": int(float(row.get("installments") or 1)),
                "payment_method": row.get("type_payment") or None,
                "product_name": (row.get("item_name") or row.get("product_first") or "").strip().strip('"') or None,
                "buyer_name": (row.get("client_name") or "").strip().strip('"') or None,
                "buyer_email": (row.get("client_email") or "").strip().lower() or None,
                "buyer_phone": (row.get("client_phone") or "").strip() or None,
                "buyer_document": (row.get("client_document") or "").strip() or None,
                "first_received_at": dt(row.get("create_date")),
                "last_received_at": dt(row.get("last_date") or row.get("paid_date") or row.get("create_date")),
                "raw_payload_json": json.dumps({"source": "dom_csv_reconcile", "file": str(CSV_PATH), "row": row}, ensure_ascii=False),
            }
    return rows


def read_missing_ids():
    path = AUDIT_DIR / "faltando_no_banco.csv"
    with path.open(encoding="utf-8-sig", newline="") as fh:
        return [row["transaction_id"] for row in csv.DictReader(fh, delimiter=";") if row.get("transaction_id")]


def main():
    report_rows = load_report_rows()
    missing_ids = read_missing_ids()
    stats = {"inserted_payment_sales": 0, "inserted_hotmart_sales_live": 0, "missing_ids": missing_ids}
    with pymysql.connect(**db_config()) as conn:
        try:
            conn.begin()
            with conn.cursor() as cur:
                for tx in missing_ids:
                    row = report_rows[tx]
                    cur.execute(
                        """
                        INSERT INTO payment_sales (
                            provider, external_transaction_id, external_checkout_id, transaction_type,
                            provider_status, normalized_status, currency, gross_amount_cents,
                            net_amount_cents, fee_amount_cents, fee_is_estimated, product_amount_cents,
                            interest_amount_cents, installments, payment_method, payment_gateway,
                            product_name, buyer_name, buyer_email, buyer_phone, buyer_document,
                            match_method, raw_payload_json, first_received_at, last_received_at,
                            created_at, updated_at
                        ) VALUES (
                            'dom', %(external_transaction_id)s, %(transaction_id)s, 'csv_reconcile',
                            %(provider_status)s, %(normalized_status)s, 'BRL', %(gross_amount_cents)s,
                            %(net_amount_cents)s, %(fee_amount_cents)s, 0, %(product_amount_cents)s,
                            0, %(installments)s, %(payment_method)s, 'dom',
                            %(product_name)s, %(buyer_name)s, %(buyer_email)s, %(buyer_phone)s, %(buyer_document)s,
                            'none', %(raw_payload_json)s, %(first_received_at)s, %(last_received_at)s,
                            NOW(), NOW()
                        )
                        ON DUPLICATE KEY UPDATE
                            provider_status=VALUES(provider_status),
                            normalized_status=VALUES(normalized_status),
                            gross_amount_cents=VALUES(gross_amount_cents),
                            net_amount_cents=VALUES(net_amount_cents),
                            fee_amount_cents=VALUES(fee_amount_cents),
                            product_amount_cents=VALUES(product_amount_cents),
                            product_name=VALUES(product_name),
                            buyer_name=VALUES(buyer_name),
                            buyer_email=VALUES(buyer_email),
                            buyer_phone=VALUES(buyer_phone),
                            buyer_document=VALUES(buyer_document),
                            raw_payload_json=VALUES(raw_payload_json),
                            last_received_at=VALUES(last_received_at),
                            updated_at=NOW()
                        """,
                        row,
                    )
                    stats["inserted_payment_sales"] += cur.rowcount
                    if row["normalized_status"] == "APPROVED":
                        cur.execute(
                            """
                            INSERT INTO hotmart_sales_live (
                                webhook_event, webhook_event_id, transaction_code, status,
                                transaction_date, payment_confirmed_at, product_name, currency,
                                gross_revenue, net_revenue, producer_net, buyer_name, buyer_email,
                                buyer_phone_raw, match_method, raw_payload_json, imported_at,
                                updated_at, sales_channel, sale_origin
                            ) VALUES (
                                'DOM_CSV_RECONCILE', %(event_id)s, %(external_transaction_id)s, 'Aprovado',
                                %(first_received_at)s, %(last_received_at)s, %(product_name)s, 'BRL',
                                %(gross_revenue)s, %(net_revenue)s, %(producer_net)s,
                                %(buyer_name)s, %(buyer_email)s, %(buyer_phone)s, 'none',
                                %(raw_payload_json)s, NOW(), NOW(), 'dom', 'dom_csv'
                            )
                            ON DUPLICATE KEY UPDATE
                                status='Aprovado',
                                webhook_event='DOM_CSV_RECONCILE',
                                gross_revenue=VALUES(gross_revenue),
                                net_revenue=VALUES(net_revenue),
                                producer_net=VALUES(producer_net),
                                buyer_name=VALUES(buyer_name),
                                buyer_email=VALUES(buyer_email),
                                buyer_phone_raw=VALUES(buyer_phone_raw),
                                raw_payload_json=VALUES(raw_payload_json),
                                updated_at=NOW(),
                                sales_channel='dom'
                            """,
                            {
                                **row,
                                "event_id": "dom-csv:" + row["external_transaction_id"],
                                "gross_revenue": row["gross_amount_cents"] / 100,
                                "net_revenue": row["net_amount_cents"] / 100,
                                "producer_net": row["net_amount_cents"] / 100,
                            },
                        )
                        stats["inserted_hotmart_sales_live"] += cur.rowcount
            conn.commit()
        except Exception:
            conn.rollback()
            raise
    out = AUDIT_DIR / "correcao_aplicada.json"
    out.write_text(json.dumps(stats, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(stats, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
