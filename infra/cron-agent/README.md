# Agente externo de cron

O agente chama as rotinas gerenciadas da área de membros uma vez por minuto.
A decisão de executar, ignorar ou assumir como redundância fica no servidor.

Arquivos no VPS:

- `/usr/local/sbin/area-membros-cron-agent`
- `/etc/area-membros-cron-agent.conf`
- `/etc/systemd/system/area-membros-cron-agent.service`
- `/etc/systemd/system/area-membros-cron-agent.timer`
- `/etc/systemd/system/area-membros-cron-task@.service`

Exemplo da configuração:

```bash
CRON_ENDPOINT="https://professoremersonleite.com/area_membros/public/cron_dispatcher.php"
CRON_TOKEN="TOKEN_EXIBIDO_NO_MONITOR"
CRON_SOURCE="vps"
```

Ativação:

```bash
systemctl daemon-reload
systemctl enable --now area-membros-cron-agent.timer
systemctl start area-membros-cron-agent.service
systemctl status area-membros-cron-agent.timer
```

Cada rotina roda em uma unidade independente. Uma live demorada não bloqueia
as execuções da IA, dos reagendamentos ou dos retornos no minuto seguinte.
