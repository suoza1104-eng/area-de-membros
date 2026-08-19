<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'meta_leads_qualificados', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/meta_qualified_leads.php';

@set_time_limit(300);

$pdo = getPDO();
mql_ensure_schema($pdo);

$stats = mql_process_queue($pdo, 80);

echo json_encode([
    'ok' => true,
    'task' => 'meta_leads_qualificados',
    'stats' => $stats,
    'finished_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
