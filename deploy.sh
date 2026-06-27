#!/bin/bash
# Deploy automático no HostGator (alternativa/à prova de falhas ao webhook).
#
# Use via cron a cada 1-2 min, em cPanel > Cron Jobs:
#   */2 * * * * /bin/bash /home1/prof2543/repositories/area-de-membros/deploy.sh >/dev/null 2>&1
#
# Faz: atualiza o repo a partir do GitHub e sincroniza para a pasta pública,
# só agindo quando houver commit novo (não mexe em nada se já estiver atualizado).

set -e

REPO_DIR="/home1/prof2543/repositories/area-de-membros"
DEPLOY_DIR="/home1/prof2543/public_html/area_membros"
LOG="$REPO_DIR/deploy.log"
PHP_BIN="${PHP_BIN:-$(command -v php || command -v /usr/local/bin/php || command -v /opt/cpanel/ea-php82/root/usr/bin/php || true)}"

run_php_cron() {
    local script="$1"
    local name="$2"
    local lock="/tmp/area_membros_${name}.lock"

    if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ] || [ ! -f "$script" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] cron $name ignorado (php/script indisponivel)" >> "$LOG"
        return 0
    fi

    if command -v flock >/dev/null 2>&1; then
        flock -n "$lock" "$PHP_BIN" "$script" >> "$LOG" 2>&1 || true
    else
        "$PHP_BIN" "$script" >> "$LOG" 2>&1 || true
    fi
}

cd "$REPO_DIR" || exit 1

run_php_cron "$DEPLOY_DIR/cron/processar_reagendamentos_live.php" "reagendamentos_live"
run_php_cron "$DEPLOY_DIR/cron/processar_metricas_negocio.php" "metricas_negocio"

git fetch --all -q

LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse origin/main)"

# Nada novo -> sai sem fazer nada
if [ "$LOCAL" = "$REMOTE" ]; then
    exit 0
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] novo commit $REMOTE — atualizando" >> "$LOG"

git reset --hard origin/main -q

/usr/bin/rsync -a --delete --no-perms \
    --exclude=.git --exclude=uploads/ --exclude=vendor/ \
    "$REPO_DIR/" "$DEPLOY_DIR/"

/bin/chmod -R u=rwX,go=rX "$DEPLOY_DIR"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] deploy concluido" >> "$LOG"
