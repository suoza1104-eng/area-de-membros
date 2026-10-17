<?php
declare(strict_types=1);

// Aviso de live por turma para os Disparos do Sino (pacotes). Roda a cada 1 minuto.
require_once __DIR__ . '/../app/cron_manager.php';
require_once __DIR__ . '/../app/sino_live.php';

if (empty($GLOBALS['cron_manager_task_key'])) {
    $managedResult = cron_manager_execute(getPDO(), 'lives_sino', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

$res = sino_live_process(getPDO(), 600);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
