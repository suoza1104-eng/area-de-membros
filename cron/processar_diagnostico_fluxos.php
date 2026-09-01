<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'diagnostico_fluxos', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/automation_diagnostics.php';

$pdo = getPDO();
$result = automation_run_complete_diagnostics($pdo, 'cron');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
echo json_encode([
    'ok' => true,
    'status' => $result['status'] ?? 'healthy',
    'issues_count' => $result['issues_count'] ?? 0,
    'flows_checked' => $result['total_flows_checked'] ?? 0,
    'checked_at' => $result['check_time'] ?? date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
