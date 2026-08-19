<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/telegram_groups.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $secret = telegram_webhook_secret();
    $queryToken = (string)($_GET['token'] ?? '');
    $headerToken = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if (!hash_equals($secret, $queryToken) && !hash_equals($secret, $headerToken)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'forbidden']);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'invalid_json']);
        exit;
    }

    $result = telegram_handle_update(getPDO(), $payload);
    echo json_encode(['ok'=>true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    log_sistema('error', 'telegram_webhook', 'Falha no webhook Telegram', ['error'=>$e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal_error']);
}

