#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Execute como root ou usando sudo." >&2
  exit 1
fi

ENDPOINT="${1:-https://professoremersonleite.com/area_membros/public/cron_dispatcher.php}"
TOKEN="${CRON_TOKEN:-${2:-}}"
if [[ -z "$TOKEN" ]]; then
  echo "Defina CRON_TOKEN antes de executar o instalador." >&2
  exit 1
fi

RAW_BASE="https://raw.githubusercontent.com/suoza1104-eng/area-de-membros/main/infra/cron-agent"

curl -fsSL "$RAW_BASE/cron-agent.sh" -o /usr/local/sbin/area-membros-cron-agent
curl -fsSL "$RAW_BASE/area-membros-cron-agent.service" -o /etc/systemd/system/area-membros-cron-agent.service
curl -fsSL "$RAW_BASE/area-membros-cron-agent.timer" -o /etc/systemd/system/area-membros-cron-agent.timer
curl -fsSL "$RAW_BASE/area-membros-cron-task@.service" -o /etc/systemd/system/area-membros-cron-task@.service
chmod 0755 /usr/local/sbin/area-membros-cron-agent

umask 077
cat >/etc/area-membros-cron-agent.conf <<EOF
CRON_ENDPOINT="$ENDPOINT"
CRON_TOKEN="$TOKEN"
CRON_SOURCE="vps"
EOF

systemctl daemon-reload
systemctl enable --now area-membros-cron-agent.timer
systemctl start area-membros-cron-agent.service

echo "Agente instalado."
systemctl --no-pager status area-membros-cron-agent.timer || true
