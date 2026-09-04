import csv
import json
import re
import zipfile
from datetime import datetime
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
ZIP_PATH = Path(r"C:\Users\Emerson\Downloads\a83f4373-a537-42d5-9d59-2e6cd82a8fbb.zip")
AUDIT_DIR = ROOT / "reports" / "pagarme_auditoria"


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
    try:
        return int(round(float(str(value or "0").replace(",", "."))))
    except ValueError:
        return 0


def dt(value):
    text = str(value or "").strip()
    if not text:
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    return text[:19]


def load_report_rows():
    with zipfile.ZipFile(ZIP_PATH) as zf:
        csv_name = next(name for name in zf.namelist() if name.lower().endswith(".csv"))
        text = zf.read(csv_name).decode("utf-8-sig", errors="replace")
    rows = {}
    for row in csv.DictReader(text.splitlines(), delimiter=";"):
        charge_id = (row.get("Charge_ID") or "").strip()
        if not charge_id:
            continue
        rows["pagarme:" + charge_id] = row
    return rows


def read_audit_csv(name):
    path = AUDIT_DIR / name
    with path.open(encoding="utf-8-sig", newline="") as fh:
        return list(csv.DictReader(fh, delimiter=";"))


def apply():
    report_rows = load_report_rows()
    missing = read_audit_csv("faltando_no_banco.csv")
    divergent = read_audit_csv("campos_divergentes.csv")
    extra_approved = [
        row for row in read_audit_csv("sobrando_no_banco.csv")
        if row.get("db_status") == "APPROVED"
    ]

    stats = {
        "inserted_payment_sales": 0,
        "inserted_hotmart_sales_live": 0,
        "updated_canceled": 0,
        "ignored_extra_approved_payment_sales": 0,
        "ignored_extra_approved_hotmart_sales_live": 0,
        "missing_ids": [row["transaction_id"] for row in missing],
        "extra_approved_ids": [row["transaction_id"] for row in extra_approved],
        "status_fix_ids": [row["transaction_id"] for row in divergent],
    }

    with pymysql.connect(**db_config()) as conn:
        try:
            conn.begin()
            with conn.cursor() as cur:
                for audit_row in missing:
                    tx = audit_row["transaction_id"]
                    source = report_rows.get(tx)
                    if not source:
                        continue
                    amount = cents(source.get("Amount_In_Cents"))
                    normalized = norm_status(source.get("Status"))
                    created = dt(source.get("Created_Date"))
                    updated = dt(source.get("Updated_At")) or created
                    raw = json.dumps({
                        "source": "pagarme_csv_reconcile",
                        "file": str(ZIP_PATH),
                        "row": source,
                    }, ensure_ascii=False)
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
                            'pagarme', %s, %s, 'csv_reconcile', %s, %s, 'BRL', %s,
                            %s, 0, 1, %s, 0, NULL, NULL, 'pagarme',
                            NULL, %s, %s, %s, %s, 'none', %s, %s, %s, NOW(), NOW()
                        )
                        ON DUPLICATE KEY UPDATE
                            provider_status=VALUES(provider_status),
                            normalized_status=VALUES(normalized_status),
                            gross_amount_cents=VALUES(gross_amount_cents),
                            net_amount_cents=VALUES(net_amount_cents),
                            product_amount_cents=VALUES(product_amount_cents),
                            buyer_name=VALUES(buyer_name),
                            buyer_email=VALUES(buyer_email),
                            buyer_phone=VALUES(buyer_phone),
                            buyer_document=VALUES(buyer_document),
                            raw_payload_json=VALUES(raw_payload_json),
                            last_received_at=VALUES(last_received_at),
                            updated_at=NOW()
                        """,
                        (
                            tx,
                            source.get("Order_Id") or None,
                            source.get("Status") or None,
                            normalized,
                            amount,
                            amount,
                            amount,
                            source.get("Customer_Name") or None,
                            (source.get("Customer_Email") or "").strip().lower() or None,
                            source.get("Customer_Cell_phone") or source.get("Customer_Home_phone") or None,
                            source.get("Customer_Document") or None,
                            raw,
                            created,
                            updated,
                        ),
                    )
                    stats["inserted_payment_sales"] += cur.rowcount

                    if normalized == "APPROVED":
                        cur.execute(
                            """
                            INSERT INTO hotmart_sales_live (
                                webhook_event, webhook_event_id, transaction_code, status,
                                transaction_date, payment_confirmed_at, product_name, currency,
                                gross_revenue, net_revenue, producer_net, buyer_name, buyer_email,
                                buyer_phone_raw, match_method, raw_payload_json, imported_at,
                                updated_at, sales_channel, sale_origin
                            ) VALUES (
                                'PAGARME_CSV_RECONCILE', %s, %s, 'Aprovado',
                                %s, %s, '', 'BRL', %s, %s, %s, %s, %s, %s,
                                'none', %s, NOW(), NOW(), 'pagarme', 'pagarme_csv'
                            )
                            ON DUPLICATE KEY UPDATE
                                status='Aprovado',
                                webhook_event='PAGARME_CSV_RECONCILE',
                                gross_revenue=VALUES(gross_revenue),
                                net_revenue=VALUES(net_revenue),
                                producer_net=VALUES(producer_net),
                                buyer_name=VALUES(buyer_name),
                                buyer_email=VALUES(buyer_email),
                                buyer_phone_raw=VALUES(buyer_phone_raw),
                                raw_payload_json=VALUES(raw_payload_json),
                                updated_at=NOW(),
                                sales_channel='pagarme'
                            """,
                            (
                                "pagarme-csv:" + tx,
                                tx,
                                created,
                                updated,
                                amount / 100,
                                amount / 100,
                                amount / 100,
                                source.get("Customer_Name") or None,
                                (source.get("Customer_Email") or "").strip().lower() or None,
                                source.get("Customer_Cell_phone") or source.get("Customer_Home_phone") or None,
                                raw,
                            ),
                        )
                        stats["inserted_hotmart_sales_live"] += cur.rowcount

                for audit_row in divergent:
                    tx = audit_row["transaction_id"]
                    if audit_row.get("report_status") == "CANCELED":
                        cur.execute(
                            """
                            UPDATE payment_sales
                               SET normalized_status='CANCELED',
                                   provider_status=IF(COALESCE(provider_status,'')='', 'failed', provider_status),
                                   updated_at=NOW()
                             WHERE provider='pagarme' AND external_transaction_id=%s
                            """,
                            (tx,),
                        )
                        stats["updated_canceled"] += cur.rowcount
                        cur.execute(
                            """
                            UPDATE hotmart_sales_live
                               SET status='Cancelado', updated_at=NOW()
                             WHERE transaction_code=%s AND sales_channel='pagarme'
                            """,
                            (tx,),
                        )

                for audit_row in extra_approved:
                    tx = audit_row["transaction_id"]
                    cur.execute(
                        """
                        UPDATE payment_sales
                           SET normalized_status='UNKNOWN',
                               transaction_type=CONCAT(COALESCE(transaction_type,''), '|csv_not_in_pagarme_report'),
                               updated_at=NOW()
                         WHERE provider='pagarme'
                           AND external_transaction_id=%s
                           AND normalized_status='APPROVED'
                        """,
                        (tx,),
                    )
                    stats["ignored_extra_approved_payment_sales"] += cur.rowcount
                    cur.execute(
                        """
                        UPDATE hotmart_sales_live
                           SET status='Ignorado',
                               webhook_event='PAGARME_CSV_NOT_IN_REPORT',
                               updated_at=NOW()
                         WHERE transaction_code=%s
                           AND sales_channel='pagarme'
                           AND UPPER(COALESCE(status,'')) IN ('APROVADO','APPROVED','COMPLETE','COMPLETO','PAID')
                        """,
                        (tx,),
                    )
                    stats["ignored_extra_approved_hotmart_sales_live"] += cur.rowcount
            conn.commit()
        except Exception:
            conn.rollback()
            raise

    out = AUDIT_DIR / "correcao_aplicada.json"
    out.write_text(json.dumps(stats, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(stats, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    apply()
