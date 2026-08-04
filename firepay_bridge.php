<?php
declare(strict_types=1);

/**
 * Ponte temporaria para webhooks Firepay.
 *
 * Instalar este arquivo no dominio/VPS que nao esta atras do ModSecurity da
 * HostGator, por exemplo:
 * https://professoremersonleite.site/firepay_bridge.php
 */

const FIREPAY_BRIDGE_TARGET = 'https://professoremersonleite.com/area_membros/firepay_mcqdc.php';
const FIREPAY_BRIDGE_LOG = __DIR__ . '/firepay_bridge.log';

header('Content-Type: application/json; charset=utf-8');

if (in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) {
    http_response_code(200);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo json_encode([
            'ok' => true,
            'bridge' => true,
            'message' => 'Firepay bridge ativo. Envie webhooks por POST.',
            'target' => FIREPAY_BRIDGE_TARGET,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function bridge_log(string $message, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents(FIREPAY_BRIDGE_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function bridge_request_context(): array
{
    return [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
}

function bridge_json_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bridge_sanitize_payload(array $payload): array
{
    // Repassa somente os campos usados pelo processador local. O payload
    // completo da Firepay pode conter PIX copia/cola, endereco, documento e
    // textos longos que frequentemente disparam WAF/ModSecurity.
    $keep = [
        'id', 'checkout_id', 'type', 'status', 'payment_method', 'payment_gateway',
        'price_currency', 'price', 'product_price', 'interest_fee', 'installments',
        'tenant_id', 'link',
    ];
    $sanitized = [];
    foreach ($keep as $key) {
        if (array_key_exists($key, $payload)) $sanitized[$key] = $payload[$key];
    }

    if (isset($payload['product']) && is_array($payload['product'])) {
        $sanitized['product'] = [];
        foreach (['id', 'name', 'slug', 'integration_id', 'integration_delivery_type', 'turmas'] as $key) {
            if (array_key_exists($key, $payload['product'])) $sanitized['product'][$key] = $payload['product'][$key];
        }
    }

    if (isset($payload['client']) && is_array($payload['client'])) {
        $sanitized['client'] = [];
        foreach (['name', 'email', 'phone'] as $key) {
            if (array_key_exists($key, $payload['client'])) $sanitized['client'][$key] = $payload['client'][$key];
        }
    }

    if (isset($payload['origin']) && is_array($payload['origin'])) {
        $sanitized['origin'] = [];
        foreach (['description', 'slug'] as $key) {
            if (array_key_exists($key, $payload['origin'])) $sanitized['origin'][$key] = $payload['origin'][$key];
        }
    }

    if (isset($payload['order_bumps']) && is_array($payload['order_bumps'])) {
        $sanitized['order_bumps'] = [];
        foreach ($payload['order_bumps'] as $bump) {
            if (!is_array($bump)) continue;
            $item = [];
            foreach (['product_id', 'id', 'name'] as $key) {
                if (array_key_exists($key, $bump)) $item[$key] = $bump[$key];
            }
            if ($item) $sanitized['order_bumps'][] = $item;
        }
    }

    return $sanitized;
}

function bridge_forward(string $jsonBody): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl indisponivel'];
    }

    $ch = curl_init(FIREPAY_BRIDGE_TARGET);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FirepayBridge/1.0',
            'X-Firepay-Bridge: professoremersonleite.site',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = (string)curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'error' => $error,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bridge_json_response(405, ['ok' => false, 'message' => 'Use POST']);
}

$rawBody = file_get_contents('php://input') ?: '';
if (trim($rawBody) === '') {
    if ($_POST) {
        $rawBody = json_encode($_POST, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    if (trim($rawBody) === '') {
        $context = bridge_request_context();
        bridge_log('payload vazio', $context);
        bridge_json_response(422, [
            'ok' => false,
            'bridge' => true,
            'message' => 'Payload vazio. Webhook nao encaminhado para evitar falso sucesso.',
        ]);
    }
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    bridge_log('json invalido', bridge_request_context() + ['sha256' => hash('sha256', $rawBody), 'sample' => substr($rawBody, 0, 120)]);
    bridge_json_response(400, ['ok' => false, 'message' => 'JSON invalido']);
}

$transactionId = isset($payload['id']) && is_scalar($payload['id']) ? (string)$payload['id'] : '';
$status = isset($payload['status']) && is_scalar($payload['status']) ? (string)$payload['status'] : '';
$forwardBody = json_encode(bridge_sanitize_payload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($forwardBody) || $forwardBody === '') {
    bridge_log('falha ao recodificar payload', ['transaction' => $transactionId]);
    bridge_json_response(400, ['ok' => false, 'message' => 'Falha ao preparar payload']);
}

$result = bridge_forward($forwardBody);
bridge_log('firepay encaminhado', [
    'transaction' => $transactionId,
    'status' => $status,
    'target_status' => $result['status'],
    'ok' => $result['ok'],
    'error' => $result['error'],
    'target_body' => substr($result['body'], 0, 500),
]);

if (!$result['ok']) {
    bridge_json_response(502, [
        'ok' => false,
        'message' => 'Falha ao encaminhar webhook',
        'target_status' => $result['status'],
        'target_error' => $result['error'],
        'target_body' => substr($result['body'], 0, 500),
    ]);
}

$decodedTarget = json_decode($result['body'], true);
if (is_array($decodedTarget)) {
    bridge_json_response(200, ['ok' => true, 'bridge' => true, 'target' => $decodedTarget]);
}

bridge_json_response(200, ['ok' => true, 'bridge' => true, 'target_body' => $result['body']]);
