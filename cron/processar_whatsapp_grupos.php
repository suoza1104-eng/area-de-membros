<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'whatsapp_grupos', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/whatsapp_groups.php';

$pdo = getPDO();
$result = whatsapp_groups_process_due($pdo, 30);

if (PHP_SAPI === 'cli') {
    echo sprintf(
        "[%s] whatsapp_grupos processados=%d enviados=%d erros=%d\n",
        date('Y-m-d H:i:s'),
        (int)$result['processed'],
        (int)$result['sent'],
        (int)$result['errors']
    );
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
