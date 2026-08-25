<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/cron_manager.php';

if (empty($GLOBALS['cron_manager_task_key'])) {
    $managedResult = cron_manager_execute(getPDO(), 'firepay_reconciliation', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/payment_reconciliation.php';

$pdo = getPDO();

try {
    $result = payment_reconciliation_rescan_unmatched_firepay($pdo);
    echo json_encode(['ok' => true, 'resultado' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    app_log('Falha no cron de reconciliacao Firepay', ['error' => $e->getMessage()]);
    throw $e;
}
