<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'whatsapp_ai', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

// Processa pacotes de mensagens dos grupos e gera analises da IA.
require_once __DIR__ . '/../app/whatsapp_ai.php';

$pdo = getPDO();
$result = whatsapp_ai_process_due($pdo, 10);

if (PHP_SAPI === 'cli') {
    echo sprintf(
        "[%s] whatsapp_ai grupos=%d pacotes=%d mensagens=%d skipped=%s erro=%s\n",
        date('Y-m-d H:i:s'),
        (int)$result['groups_processed'],
        (int)$result['batches_created'],
        (int)$result['messages_processed'],
        !empty($result['skipped']) ? 'sim' : 'nao',
        (string)($result['error'] ?? '')
    );
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => empty($result['error'])] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
