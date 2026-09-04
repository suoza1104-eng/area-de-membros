import csv
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = Path(r"C:\Users\Emerson\Downloads\sales_history_20260825230545_2F5E29F716253618490943717533.csv")
OUT_DIR = ROOT / "reports" / "hotmart_agosto_2026_auditoria"

def load_db_config():
    config = (ROOT / "app" / "config.php").read_text(encoding="utf-8", errors="ignore")
    values = {}
    for key in ("DB_HOST", "DB_USER", "DB_PASS", "DB_NAME"):
        match = re.search(r"define\(\s*['\"]" + key + r"['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)", config)
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


def norm_header(value: str) -> str:
    value = value.replace("\ufeff", "").strip().lower()
    value = re.sub(r"[^a-z0-9áàâãéêíóôõúç]+", "", value)
    trans = str.maketrans("áàâãéêíóôõúç", "aaaaeeiooouc")
    return value.translate(trans)


def parse_money(value) -> Decimal:
    text = str(value or "").strip()
    if text == "" or text.lower() == "(none)":
        return Decimal("0.00")
    text = text.replace("R$", "").replace(" ", "")
    if "," in text and "." in text:
        text = text.replace(".", "").replace(",", ".")
    elif "," in text:
        text = text.replace(",", ".")
    try:
        return Decimal(text).quantize(Decimal("0.01"))
    except InvalidOperation:
        return Decimal("0.00")


def parse_dt(value):
    text = str(value or "").strip()
    if text == "" or text.lower() == "(none)":
        return None
    for fmt in ("%d/%m/%Y %H:%M:%S", "%Y-%m-%d %H:%M:%S"):
        try:
            return datetime.strptime(text, fmt)
        except ValueError:
            pass
    return None


def pick(row, mapping, names, default=""):
    for name in names:
        idx = mapping.get(norm_header(name))
        if idx is not None and idx < len(row):
            return row[idx]
    return default


def clean(value):
    text = str(value or "").strip()
    return "" if text.lower() == "(none)" else text


def status_group(status: str) -> str:
    s = clean(status).upper()
    if s in {"APROVADO", "APPROVED", "PURCHASE_APPROVED", "COMPLETO", "COMPLETE", "PURCHASE_COMPLETE", "PAID"}:
        return "approved"
    if s in {"REEMBOLSADO", "REFUNDED", "PURCHASE_REFUNDED"}:
        return "refunded"
    if s in {"CHARGEBACK", "PURCHASE_CHARGEBACK"}:
        return "chargeback"
    if s in {"CANCELADO", "CANCELED", "PURCHASE_CANCELED"}:
        return "canceled"
    return s.lower() or "blank"


def load_csv_sales():
    with CSV_PATH.open("r", encoding="utf-8-sig", newline="") as fh:
        sample = fh.readline()
        sep = ";" if sample.count(";") >= sample.count(",") else ","
        fh.seek(0)
        reader = csv.reader(fh, delimiter=sep, quotechar='"')
        headers = next(reader)
        mapping = {norm_header(h): i for i, h in enumerate(headers)}
        sales = {}
        duplicates = []
        for line_no, row in enumerate(reader, start=2):
            if not any(clean(v) for v in row):
                continue
            tx = clean(pick(row, mapping, ["Código da transação", "transaction_code"]))
            if not tx:
                continue
            sale = {
                "source_line": line_no,
                "transaction_code": tx,
                "status": clean(pick(row, mapping, ["Status da transação"])),
                "status_group": status_group(pick(row, mapping, ["Status da transação"])),
                "transaction_date": parse_dt(pick(row, mapping, ["Data da transação"])),
                "payment_confirmed_at": parse_dt(pick(row, mapping, ["Confirmação do pagamento"])),
                "product_code": clean(pick(row, mapping, ["Código do produto"])),
                "product_name": clean(pick(row, mapping, ["Produto"])),
                "price_code": clean(pick(row, mapping, ["Código do preço"])),
                "price_name": clean(pick(row, mapping, ["Nome deste preço"])),
                "currency": clean(pick(row, mapping, ["Moeda de recebimento", "Moeda de compra"], "BRL")),
                "gross_revenue": parse_money(pick(row, mapping, ["Faturamento bruto (sem impostos)", "Valor de compra sem impostos"])),
                "net_revenue": parse_money(pick(row, mapping, ["Faturamento líquido"])),
                "producer_net": parse_money(pick(row, mapping, ["Faturamento líquido do(a) Produtor(a)", "Faturamento líquido do Produtor"])),
                "buyer_name": clean(pick(row, mapping, ["Comprador(a)"])),
                "buyer_email": clean(pick(row, mapping, ["Email do(a) Comprador(a)"])).lower(),
                "buyer_phone": clean(pick(row, mapping, ["Telefone"])),
                "payment_method": clean(pick(row, mapping, ["Método de pagamento"])),
                "installments": clean(pick(row, mapping, ["Quantidade total de parcelas"])),
                "sale_channel": clean(pick(row, mapping, ["Canal usado para venda"])),
                "src": clean(pick(row, mapping, ["Código SRC"])),
                "sck": clean(pick(row, mapping, ["Código SCK"])),
            }
            if tx in sales:
                duplicates.append(tx)
            sales[tx] = sale
    return sales, duplicates


def load_db_sales():
    sql = """
        SELECT id, transaction_code, status, transaction_date, payment_confirmed_at,
               product_code, product_name, price_code, price_name, currency,
               gross_revenue, net_revenue, producer_net, buyer_name, buyer_email,
               buyer_phone_raw, buyer_phone_norm, matched_user_id, match_method,
               payment_type, installments_number, sale_origin, sales_channel,
               webhook_event, webhook_event_id, imported_at, updated_at
          FROM hotmart_sales_live
         WHERE transaction_date >= '2026-08-01 00:00:00'
           AND transaction_date <  '2026-09-01 00:00:00'
           AND COALESCE(NULLIF(sales_channel,''), 'hotmart') = 'hotmart'
    """
    with pymysql.connect(**load_db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
            cur.execute(
                """
                SELECT transaction_code, COUNT(*) events, MIN(received_at) first_received, MAX(received_at) last_received
                  FROM hotmart_webhook_events
                 WHERE received_at >= '2026-08-01 00:00:00'
                   AND received_at <  '2026-09-01 00:00:00'
                 GROUP BY transaction_code
                """
            )
            events = {r["transaction_code"]: r for r in cur.fetchall() if r["transaction_code"]}
    sales = {}
    for row in rows:
        row["status_group"] = status_group(row.get("status"))
        for key in ("gross_revenue", "net_revenue", "producer_net"):
            row[key] = Decimal(str(row.get(key) or "0")).quantize(Decimal("0.01"))
        sales[str(row["transaction_code"])] = row
    return sales, events


def money_diff(a, b) -> Decimal:
    return (Decimal(a or 0) - Decimal(b or 0)).copy_abs().quantize(Decimal("0.01"))


def dt_str(dt):
    if not dt:
        return ""
    return dt.strftime("%Y-%m-%d %H:%M:%S") if hasattr(dt, "strftime") else str(dt)


def row_for(kind, csv_sale=None, db_sale=None, details=""):
    base = csv_sale or {}
    db = db_sale or {}
    return {
        "tipo": kind,
        "transacao": base.get("transaction_code") or db.get("transaction_code") or "",
        "detalhe": details,
        "csv_status": base.get("status", ""),
        "db_status": db.get("status", ""),
        "csv_data": dt_str(base.get("transaction_date")),
        "db_data": dt_str(db.get("transaction_date")),
        "csv_produto": base.get("product_name", ""),
        "db_produto": db.get("product_name", ""),
        "csv_bruto": str(base.get("gross_revenue", "")),
        "db_bruto": str(db.get("gross_revenue", "")),
        "csv_liquido": str(base.get("net_revenue", "")),
        "db_liquido": str(db.get("net_revenue", "")),
        "csv_produtor": str(base.get("producer_net", "")),
        "db_produtor": str(db.get("producer_net", "")),
        "csv_email": base.get("buyer_email", ""),
        "db_email": db.get("buyer_email", ""),
        "db_user_id": str(db.get("matched_user_id") or ""),
        "db_match": db.get("match_method", ""),
        "db_webhook_event": db.get("webhook_event", ""),
    }


def write_csv(path, rows):
    fields = [
        "tipo", "transacao", "detalhe", "csv_status", "db_status", "csv_data", "db_data",
        "csv_produto", "db_produto", "csv_bruto", "db_bruto", "csv_liquido", "db_liquido",
        "csv_produtor", "db_produtor", "csv_email", "db_email", "db_user_id", "db_match", "db_webhook_event",
    ]
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields, delimiter=";")
        writer.writeheader()
        writer.writerows(rows)


def sum_money(rows, key, approved_only=True):
    total = Decimal("0.00")
    for row in rows:
        if approved_only and row["status_group"] != "approved":
            continue
        total += Decimal(row.get(key) or 0)
    return total.quantize(Decimal("0.01"))


def main():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    csv_sales, csv_duplicates = load_csv_sales()
    db_sales, webhook_events = load_db_sales()

    csv_keys = set(csv_sales)
    db_keys = set(db_sales)
    missing = sorted(csv_keys - db_keys, key=lambda tx: csv_sales[tx]["transaction_date"] or datetime.min)
    extra = sorted(db_keys - csv_keys, key=lambda tx: db_sales[tx]["transaction_date"] or datetime.min)

    divergences = []
    for tx in sorted(csv_keys & db_keys, key=lambda tx: csv_sales[tx]["transaction_date"] or datetime.min):
        c = csv_sales[tx]
        d = db_sales[tx]
        notes = []
        if c["status_group"] != d["status_group"]:
            notes.append(f"status {c['status']} != {d.get('status')}")
        for field, label in (("gross_revenue", "bruto"), ("net_revenue", "liquido"), ("producer_net", "produtor")):
            if money_diff(c[field], d[field]) >= Decimal("0.01"):
                notes.append(f"{label} diff {money_diff(c[field], d[field])}")
        if clean(c["product_name"]) != clean(d.get("product_name")):
            notes.append("produto diferente")
        if clean(c["buyer_email"]).lower() != clean(d.get("buyer_email")).lower():
            notes.append("email diferente")
        if notes:
            divergences.append(row_for("divergente", c, d, " | ".join(notes)))

    missing_rows = [row_for("faltando_no_banco", csv_sales[tx], None, "") for tx in missing]
    extra_rows = [row_for("sobrando_no_banco", None, db_sales[tx], "") for tx in extra]
    all_rows = missing_rows + extra_rows + divergences

    write_csv(OUT_DIR / "divergencias_hotmart_agosto_2026.csv", all_rows)
    write_csv(OUT_DIR / "faltando_no_banco.csv", missing_rows)
    write_csv(OUT_DIR / "sobrando_no_banco.csv", extra_rows)
    write_csv(OUT_DIR / "campos_divergentes.csv", divergences)

    csv_list = list(csv_sales.values())
    db_list = list(db_sales.values())
    by_day = defaultdict(lambda: {"csv_qtd": 0, "csv_produtor": Decimal("0.00"), "db_qtd": 0, "db_produtor": Decimal("0.00")})
    for sale in csv_list:
        if sale["status_group"] == "approved" and sale["transaction_date"]:
            key = sale["transaction_date"].strftime("%Y-%m-%d")
            by_day[key]["csv_qtd"] += 1
            by_day[key]["csv_produtor"] += sale["producer_net"]
    for sale in db_list:
        if sale["status_group"] == "approved" and sale["transaction_date"]:
            key = sale["transaction_date"].strftime("%Y-%m-%d")
            by_day[key]["db_qtd"] += 1
            by_day[key]["db_produtor"] += sale["producer_net"]

    daily_rows = []
    for day in sorted(by_day):
        r = by_day[day]
        daily_rows.append({
            "dia": day,
            "csv_qtd": r["csv_qtd"],
            "db_qtd": r["db_qtd"],
            "diff_qtd": r["csv_qtd"] - r["db_qtd"],
            "csv_produtor": str(r["csv_produtor"].quantize(Decimal("0.01"))),
            "db_produtor": str(r["db_produtor"].quantize(Decimal("0.01"))),
            "diff_produtor": str((r["csv_produtor"] - r["db_produtor"]).quantize(Decimal("0.01"))),
        })
    with (OUT_DIR / "resumo_por_dia.csv").open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["dia", "csv_qtd", "db_qtd", "diff_qtd", "csv_produtor", "db_produtor", "diff_produtor"], delimiter=";")
        writer.writeheader()
        writer.writerows(daily_rows)

    summary = {
        "csv_path": str(CSV_PATH),
        "db_table": "hotmart_sales_live",
        "period": "2026-08-01 a 2026-08-31 por transaction_date",
        "csv_total_rows": len(csv_sales),
        "db_total_rows_hotmart_august": len(db_sales),
        "csv_status_counts": Counter(s["status"] for s in csv_list),
        "db_status_counts": Counter(s["status"] for s in db_list),
        "csv_approved_count": sum(1 for s in csv_list if s["status_group"] == "approved"),
        "db_approved_count": sum(1 for s in db_list if s["status_group"] == "approved"),
        "csv_approved_gross": str(sum_money(csv_list, "gross_revenue")),
        "db_approved_gross": str(sum_money(db_list, "gross_revenue")),
        "csv_approved_net": str(sum_money(csv_list, "net_revenue")),
        "db_approved_net": str(sum_money(db_list, "net_revenue")),
        "csv_approved_producer": str(sum_money(csv_list, "producer_net")),
        "db_approved_producer": str(sum_money(db_list, "producer_net")),
        "missing_in_db_count": len(missing_rows),
        "extra_in_db_count": len(extra_rows),
        "field_divergence_count": len(divergences),
        "csv_duplicate_transactions": csv_duplicates,
        "webhook_transactions_in_august": len(webhook_events),
        "outputs": {
            "all_divergences": str(OUT_DIR / "divergencias_hotmart_agosto_2026.csv"),
            "missing_in_db": str(OUT_DIR / "faltando_no_banco.csv"),
            "extra_in_db": str(OUT_DIR / "sobrando_no_banco.csv"),
            "field_divergences": str(OUT_DIR / "campos_divergentes.csv"),
            "daily_summary": str(OUT_DIR / "resumo_por_dia.csv"),
        },
    }
    summary["approved_count_diff_csv_minus_db"] = summary["csv_approved_count"] - summary["db_approved_count"]
    summary["approved_producer_diff_csv_minus_db"] = str((Decimal(summary["csv_approved_producer"]) - Decimal(summary["db_approved_producer"])).quantize(Decimal("0.01")))
    (OUT_DIR / "resumo.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2, default=str), encoding="utf-8")

    md = [
        "# Auditoria Hotmart Agosto 2026",
        "",
        f"CSV: `{CSV_PATH}`",
        "Banco: `hotmart_sales_live`, apenas `sales_channel = hotmart`, período por `transaction_date` em agosto/2026.",
        "",
        "## Resumo",
        "",
        f"- CSV: {summary['csv_total_rows']} transações ({summary['csv_approved_count']} aprovadas).",
        f"- Banco: {summary['db_total_rows_hotmart_august']} transações Hotmart em agosto ({summary['db_approved_count']} aprovadas).",
        f"- Diferença de aprovadas (CSV - banco): {summary['approved_count_diff_csv_minus_db']}.",
        f"- Produtor líquido aprovado CSV: R$ {summary['csv_approved_producer']}.",
        f"- Produtor líquido aprovado banco: R$ {summary['db_approved_producer']}.",
        f"- Diferença produtor líquido aprovado (CSV - banco): R$ {summary['approved_producer_diff_csv_minus_db']}.",
        f"- Faltando no banco: {summary['missing_in_db_count']}.",
        f"- Sobrando no banco: {summary['extra_in_db_count']}.",
        f"- Transações existentes nos dois lados com campos divergentes: {summary['field_divergence_count']}.",
        "",
        "## Arquivos gerados",
        "",
        "- `divergencias_hotmart_agosto_2026.csv`: tudo que precisa de revisão.",
        "- `faltando_no_banco.csv`: vendas presentes na Hotmart e ausentes no banco.",
        "- `sobrando_no_banco.csv`: vendas no banco que não aparecem no CSV.",
        "- `campos_divergentes.csv`: transações encontradas nos dois lados, mas com valor/status/produto/email diferente.",
        "- `resumo_por_dia.csv`: confronto diário das aprovadas.",
    ]
    (OUT_DIR / "relatorio.md").write_text("\n".join(md) + "\n", encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2, default=str))


if __name__ == "__main__":
    main()
