<?php
declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv ?? [], true);
$limit = 1000;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', (string)$arg, $m)) $limit = max(1, min(1000, (int)$m[1]));
}

if (empty($GLOBALS['cron_manager_task_key']) && !$dryRun) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'meta_form_utms', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/meta_form_utms.php';

@set_time_limit(300);

$pdo = getPDO();
$stats = mfu_process_google_sheet($pdo, $limit, $dryRun);

echo json_encode([
    'ok' => true,
    'task' => 'meta_form_utms',
    'stats' => $stats,
    'finished_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
