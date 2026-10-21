<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/cron_manager.php';

if (empty($GLOBALS['cron_manager_task_key'])) {
    $managedResult = cron_manager_execute(getPDO(), 'vitalicio_bonus', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/course_bonus_lifetime.php';

$pdo = getPDO();

try {
    $result = [
        'vendas' => course_bonus_lifetime_sync_sales($pdo, 7, true),
        'alunos_novos' => course_bonus_lifetime_sync_new_users($pdo),
    ];
    echo json_encode(['ok' => true, 'resultado' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    @error_log('Falha no cron de vitalicio bonus FERA: ' . $e->getMessage());
    throw $e;
}
