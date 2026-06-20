#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/etc/area-membros-cron-agent.conf"
if [[ ! -r "$CONFIG_FILE" ]]; then
  echo "Configuracao nao encontrada: $CONFIG_FILE" >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$CONFIG_FILE"

task_output="$(
  /usr/bin/curl --silent --show-error --fail \
    --connect-timeout 10 \
    --max-time 30 \
    -H "X-Cron-Token: ${CRON_TOKEN}" \
    --data "source=${CRON_SOURCE}" \
    --data "task=list" \
    "${CRON_ENDPOINT}"
)"
mapfile -t TASKS <<< "$task_output"

for task in "${TASKS[@]}"; do
  [[ "$task" =~ ^[a-z0-9_]+$ ]] || continue
  systemctl start --no-block "area-membros-cron-task@${task}.service"
done
