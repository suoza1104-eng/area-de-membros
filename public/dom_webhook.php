<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/dom_pagamentos.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
if ((string)get_setting('dom_pagamentos_enabled', '0') !== '1') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Integracao DOM pausada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'JSON invalido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = dom_process_webhook(
        $pdo,
        $payload,
        $rawBody !== '' ? $rawBody : (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $isMapped = ($result['normalized_status'] ?? '') !== 'UNKNOWN';
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'processed' => $isMapped,
        'transaction' => $result['transaction_id'] ?? '',
        'status' => $result['normalized_status'] ?? '',
        'matched_user_id' => (int)($result['matched_user_id'] ?? 0),
        'lifetime_granted' => !empty($result['lifetime_granted']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    app_log('Falha no webhook DOM Pagamentos', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Falha ao processar webhook DOM Pagamentos'], JSON_UNESCAPED_UNICODE);
}
