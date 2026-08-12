<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/support_chat.php';

$pdo = getPDO();
support_chat_ensure_schema($pdo);
$inserted = support_learning_collect_suggestions($pdo);

echo json_encode([
    'ok' => true,
    'inserted' => $inserted,
    'finished_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
