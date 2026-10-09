<?php
declare(strict_types=1);

// Envia a fila sino_outbox para a API do Sino (WhatsApp). Roda a cada 1 minuto.
require_once __DIR__ . '/../app/cron_manager.php';

if (empty($GLOBALS['cron_manager_task_key'])) {
    $managedResult = cron_manager_execute(getPDO(), 'sino_outbox', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

$res = sino_process_outbox(getPDO(), SINO_RODADA_MAX);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
