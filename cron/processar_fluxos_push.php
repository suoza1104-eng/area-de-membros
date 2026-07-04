<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'fluxos_push', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/push_flow_engine.php';
$pdo = getPDO();
$liveReminders = push_flow_enqueue_live_reminders($pdo, 500, 5);
$result = push_flow_process_due($pdo, 50, 45);
echo json_encode(['ok'=>true,'live_reminders'=>$liveReminders] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
