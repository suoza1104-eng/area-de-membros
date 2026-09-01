<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'email_marketing', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/email_flow_engine.php';

$pdo = getPDO();
$campaigns = email_process_queue($pdo);
$flows = email_flow_process_queue($pdo, 25);

echo json_encode([
    'ok' => true,
    'campaigns' => $campaigns,
    'flows' => $flows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
