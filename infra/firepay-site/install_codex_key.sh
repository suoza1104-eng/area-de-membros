#!/bin/sh
set -eu

KEY_URL="https://raw.githubusercontent.com/suoza1104-eng/area-de-membros/main/infra/firepay-site/codex_firepay_site_bridge.pub"
TMP_KEY="/tmp/codex_firepay_site_bridge.pub"

mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"

if command -v curl >/dev/null 2>&1; then
  curl -fsSL "$KEY_URL" -o "$TMP_KEY"
elif command -v wget >/dev/null 2>&1; then
  wget -qO "$TMP_KEY" "$KEY_URL"
else
  echo "curl/wget indisponivel" >&2
  exit 1
fi

touch "$HOME/.ssh/authorized_keys"
chmod 600 "$HOME/.ssh/authorized_keys"

if ! grep -q "codex-firepay-site-bridge" "$HOME/.ssh/authorized_keys"; then
  cat "$TMP_KEY" >> "$HOME/.ssh/authorized_keys"
  printf '\n' >> "$HOME/.ssh/authorized_keys"
fi

rm -f "$TMP_KEY"
echo "OK: chave Codex instalada em $HOME/.ssh/authorized_keys"
