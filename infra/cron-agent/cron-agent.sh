#!/usr/bin/env bash
set -u

TASKS=(whatsapp_ai reagendamentos_live lives_turma agendamentos_retorno)

for task in "${TASKS[@]}"; do
  systemctl start --no-block "area-membros-cron-task@${task}.service"
done
