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

cd "$REPO_DIR" || exit 1

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
