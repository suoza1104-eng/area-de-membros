<?php
declare(strict_types=1);

/**
 * Ponte independente para webhook Firepay.
 *
 * Instale este arquivo no dominio sem ModSecurity problemático, por exemplo:
 * https://professoremersonleite.site/firepay_bridge_site.php
 *
 * Configure essa URL na Firepay. O script recebe o payload original,
 * reduz para os campos necessários e reenvia para a area de membros.
 */

const FIREPAY_TARGET_URL = 'https://professoremersonleite.com/area_membros/firepay_mcqdc.php';
const FIREPAY_BRIDGE_LOG_FILE = __DIR__ . '/firepay_bridge_site.log';

header('Content-Type: application/json; charset=utf-8');

function fpb_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fpb_log(string $message, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents(FIREPAY_BRIDGE_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function fpb_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function fpb_reduce_payload(array $payload): array
{
    $keep = [
        'id',
        'checkout_id',
        'type',
        'status',
        'payment_method',
        'payment_gateway',
        'price_currency',
        'price',
        'product_price',
        'interest_fee',
        'installments',
        'tenant_id',
        'link',
    ];

    $out = [];
    foreach ($keep as $key) {
        if (array_key_exists($key, $payload)) $out[$key] = $payload[$key];
    }

    if (isset($payload['product']) && is_array($payload['product'])) {
        $out['product'] = [];
        foreach (['id', 'name', 'slug', 'integration_id', 'integration_delivery_type', 'turmas'] as $key) {
            if (array_key_exists($key, $payload['product'])) $out['product'][$key] = $payload['product'][$key];
        }
    }

    if (isset($payload['client']) && is_array($payload['client'])) {
        $out['client'] = [];
        foreach (['name', 'email', 'phone'] as $key) {
            if (array_key_exists($key, $payload['client'])) $out['client'][$key] = $payload['client'][$key];
        }
    }

    if (isset($payload['origin']) && is_array($payload['origin'])) {
        $out['origin'] = [];
        foreach (['description', 'slug'] as $key) {
            if (array_key_exists($key, $payload['origin'])) $out['origin'][$key] = $payload['origin'][$key];
        }
    }

    if (isset($payload['order_bumps']) && is_array($payload['order_bumps'])) {
        $out['order_bumps'] = [];
        foreach ($payload['order_bumps'] as $bump) {
            if (!is_array($bump)) continue;
            $item = [];
            foreach (['product_id', 'id', 'name'] as $key) {
                if (array_key_exists($key, $bump)) $item[$key] = $bump[$key];
            }
            if ($item) $out['order_bumps'][] = $item;
        }
    }

    return $out;
}

function fpb_forward_json(string $json): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'curl indisponivel'];
    }

    $ch = curl_init(FIREPAY_TARGET_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FirepayBridgeSite/1.0',
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

    return ['status' => $status, 'body' => $body, 'error' => $error];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fpb_response(405, ['ok' => false, 'message' => 'Use POST']);
}

$raw = file_get_contents('php://input') ?: '';
if (trim($raw) === '') {
    fpb_log('payload vazio');
    fpb_response(400, ['ok' => false, 'message' => 'Payload vazio']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    fpb_log('json invalido', ['sha256' => hash('sha256', $raw)]);
    fpb_response(400, ['ok' => false, 'message' => 'JSON invalido']);
}

$transactionId = fpb_scalar($payload, 'id');
$status = fpb_scalar($payload, 'status');
$forwardPayload = fpb_reduce_payload($payload);
$forwardJson = json_encode($forwardPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($forwardJson) || $forwardJson === '') {
    fpb_log('falha ao recodificar payload', ['transaction' => $transactionId]);
    fpb_response(400, ['ok' => false, 'message' => 'Falha ao preparar payload']);
}

$result = fpb_forward_json($forwardJson);
$ok = $result['status'] >= 200 && $result['status'] < 300 && $result['error'] === '';

fpb_log('webhook encaminhado', [
    'transaction' => $transactionId,
    'firepay_status' => $status,
    'target_status' => $result['status'],
    'ok' => $ok,
    'error' => $result['error'],
]);

if (!$ok) {
    fpb_response(502, [
        'ok' => false,
        'message' => 'Falha ao encaminhar webhook',
        'target_status' => $result['status'],
        'target_error' => $result['error'],
        'target_body' => substr($result['body'], 0, 500),
    ]);
}

$target = json_decode($result['body'], true);
fpb_response(200, [
    'ok' => true,
    'bridge' => true,
    'transaction' => $transactionId,
    'target' => is_array($target) ? $target : ['raw' => substr($result['body'], 0, 500)],
]);
