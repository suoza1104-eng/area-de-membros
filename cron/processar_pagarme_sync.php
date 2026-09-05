<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/cron_manager.php';

if (empty($GLOBALS['cron_manager_task_key'])) {
    $managedResult = cron_manager_execute(getPDO(), 'pagarme_sync', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/pagarme.php';

$pdo = getPDO();
$res = pagarme_sync_orders_api($pdo, 20);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
