import json
import re
import urllib.error
import urllib.request
from pathlib import Path

import pymysql


ROOT = Path(__file__).resolve().parents[1]
WEBHOOK_URL = "https://professoremersonleite.com/area_membros/public/hotmart_metrics_webhook.php"


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
    }


def request_status(headers):
    req = urllib.request.Request(
        WEBHOOK_URL,
        data=b'"x"',
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "HotmartWebhook/1.0",
            **headers,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            return {"status": resp.status, "body": resp.read().decode("utf-8", errors="replace")}
    except urllib.error.HTTPError as exc:
        return {"status": exc.code, "body": exc.read().decode("utf-8", errors="replace")}


def main():
    with pymysql.connect(**db_config()) as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT valor FROM settings WHERE chave='metrics_hotmart_hottok' LIMIT 1")
            row = cur.fetchone()
            token = (row or {}).get("valor") or ""
            cur.execute("SELECT COUNT(*) qty, MAX(received_at) last_received FROM hotmart_webhook_events")
            events = cur.fetchone()
    no_token = request_status({})
    with_token = request_status({"x-hotmart-hottok": token}) if token else None
    result = {
        "webhook_url": WEBHOOK_URL,
        "token_saved": bool(token),
        "token_length": len(token),
        "post_without_token": no_token,
        "post_with_saved_token_invalid_json": with_token,
        "hotmart_events_count": events.get("qty") if events else None,
        "hotmart_last_event_received_at": str(events.get("last_received")) if events and events.get("last_received") else None,
        "expected": {
            "without_token": "401 Nao autorizado quando token esta configurado",
            "with_saved_token_invalid_json": "400 JSON invalido, provando que passou da autenticacao sem gravar venda",
        },
    }
    out = ROOT / "reports" / "hotmart_agosto_2026_auditoria" / "hottok_webhook_check.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
