<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/voice_torpedo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$raw = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

try {
    $result = voice_handle_telnyx_webhook(getPDO(), $raw, $headers, true);
    http_response_code((int)($result['http_status'] ?? 202));
    echo json_encode(['ok' => (($result['http_status'] ?? 202) < 400), 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'webhook_error'], JSON_UNESCAPED_UNICODE);
}
