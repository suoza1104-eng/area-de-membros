<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/cron_manager.php';

if (empty($GLOBALS['cron_manager_task_key'])) {
    $managedResult = cron_manager_execute(getPDO(), 'dom_pagamentos', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/dom_pagamentos.php';

$pdo = getPDO();

try {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $result = [
        'today' => dom_sync_transactions_for_date($pdo, $today, 'cron_regular'),
        'yesterday' => dom_sync_transactions_for_date($pdo, $yesterday, 'cron_regular_backfill'),
    ];
    echo json_encode(['ok' => true, 'resultado' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    app_log('Falha no cron DOM Pagamentos', ['error' => $e->getMessage()]);
    throw $e;
}
