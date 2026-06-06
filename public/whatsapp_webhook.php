<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/evolution_api.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
evolution_ensure_tables($pdo);

$expectedToken = evolution_get_webhook_token();
$receivedToken = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? $_GET['token'] ?? ''));
$tokenOk = hash_equals($expectedToken, strtolower($receivedToken));

$rawBody = file_get_contents('php://input') ?: '';
if ($rawBody === '' && !empty($_POST)) {
    $rawBody = json_encode($_POST, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $headers[$key] = is_scalar($value) ? (string)$value : '';
    }
}

$fields = evolution_extract_raw_event_fields($payload);

try {
    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_webhook_raw_logs
            (token_ok, event_type, instance_key, group_id, action, participant_number, payload_raw, headers_json, source_ip, received_at)
        VALUES
            (:token_ok, :event_type, :instance_key, :group_id, :action, :participant_number, :payload_raw, :headers_json, :source_ip, NOW())
    ");
    $stmt->execute([
        ':token_ok' => $tokenOk ? 1 : 0,
        ':event_type' => $fields['event_type'],
        ':instance_key' => $fields['instance_key'],
        ':group_id' => $fields['group_id'],
        ':action' => $fields['action'],
        ':participant_number' => $fields['participant_number'],
        ':payload_raw' => $rawBody !== '' ? $rawBody : '{}',
        ':headers_json' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':source_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    $id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'log_failed']);
    exit;
}

if (!$tokenOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'logged' => $id, 'msg' => 'invalid_token']);
    exit;
}

echo json_encode(['ok' => true, 'logged' => $id]);
