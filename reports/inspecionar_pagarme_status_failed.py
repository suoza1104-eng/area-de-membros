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
            raise RuntimeError(f"{key} nao encontrado")
        values[key] = match.group(1)
    return {
        "host": values["DB_HOST"],
        "user": values["DB_USER"],
        "password": values["DB_PASS"],
        "database": values["DB_NAME"],
        "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
    }


with pymysql.connect(**db_config()) as conn:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT external_transaction_id, provider_status, normalized_status, gross_amount_cents,
                   buyer_email, last_received_at
              FROM payment_sales
             WHERE provider='pagarme'
               AND LOWER(COALESCE(provider_status,''))='failed'
             ORDER BY last_received_at
            """
        )
        rows = cur.fetchall()

print(json.dumps(rows, ensure_ascii=False, indent=2, default=str))
