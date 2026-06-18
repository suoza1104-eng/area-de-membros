<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/evolution_api.php';
require_once __DIR__ . '/../app/whatsapp_ai.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
evolution_ensure_tables($pdo);
whatsapp_ai_ensure_tables($pdo);

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

$fieldRows = evolution_extract_raw_event_fields_all($payload);
$loggedEvents = [];
try {
    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_webhook_raw_logs
            (token_ok, event_type, instance_key, group_id, action, participant_number, participant_phone, participant_id, author_id, interpreted_event, payload_raw, headers_json, source_ip, received_at)
        VALUES
            (:token_ok, :event_type, :instance_key, :group_id, :action, :participant_number, :participant_phone, :participant_id, :author_id, :interpreted_event, :payload_raw, :headers_json, :source_ip, NOW())
    ");
    foreach ($fieldRows as $fields) {
        $stmt->execute([
            ':token_ok' => $tokenOk ? 1 : 0,
            ':event_type' => $fields['event_type'],
            ':instance_key' => $fields['instance_key'],
            ':group_id' => $fields['group_id'],
            ':action' => $fields['action'],
            ':participant_number' => $fields['participant_number'],
            ':participant_phone' => $fields['participant_phone'],
            ':participant_id' => $fields['participant_id'],
            ':author_id' => $fields['author_id'],
            ':interpreted_event' => $fields['interpreted_event'],
            ':payload_raw' => $rawBody !== '' ? $rawBody : '{}',
            ':headers_json' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':source_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $loggedEvents[] = ['id' => (int)$pdo->lastInsertId(), 'fields' => $fields];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'log_failed']);
    exit;
}
$id = (int)($loggedEvents[0]['id'] ?? 0);

if (!$tokenOk) {
    http_response_code(403);
    $loggedIds = array_column($loggedEvents, 'id');
    echo json_encode(['ok' => false, 'logged' => count($loggedIds) === 1 ? $loggedIds[0] : $loggedIds, 'msg' => 'invalid_token']);
    exit;
}

$aiMessageId = null;
try {
    $aiMessageId = whatsapp_ai_record_message($pdo, $id, $payload);
} catch (Throwable $e) {
    @error_log('whatsapp_ai_record_message: ' . $e->getMessage());
}

$process = [];
foreach ($loggedEvents as $loggedEvent) {
    $process[] = evolution_process_group_event($pdo, (int)$loggedEvent['id'], (array)$loggedEvent['fields']);
}

echo json_encode([
    'ok' => true,
    'logged' => count($loggedEvents) === 1 ? $loggedEvents[0]['id'] : array_column($loggedEvents, 'id'),
    'ai_message' => $aiMessageId,
    'process' => count($process) === 1 ? $process[0] : $process,
]);
