<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics.php';
require_once __DIR__ . '/../app/integration_hub.php';
require_once __DIR__ . '/../app/course_access.php';
require_once __DIR__ . '/../app/payment_events.php';

header('Content-Type: application/json; charset=utf-8');

function hmw_reply(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hmw_datetime($value): ?string {
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) {
        $ts = (int)$value;
        if ($ts > 9999999999) $ts = (int)floor($ts / 1000);
        return date('Y-m-d H:i:s', $ts);
    }
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function hmw_status(string $event, string $status): string {
    $event = strtoupper(trim($event));
    if (in_array($event, ['PURCHASE_COMPLETE', 'PURCHASE_COMPLETED'], true)) return 'COMPLETE';
    if ($event === 'PURCHASE_APPROVED') return 'APPROVED';
    $v = strtoupper(trim($status ?: $event));
    if (strpos($v, 'REFUND') !== false) return 'REFUNDED';
    if (strpos($v, 'CHARGEBACK') !== false) return 'CHARGEBACK';
    if (strpos($v, 'CANCEL') !== false) return 'CANCELED';
    if (strpos($v, 'APPROV') !== false || strpos($v, 'COMPLET') !== false || strpos($v, 'PAID') !== false) return 'APPROVED';
    if (strpos($v, 'PEND') !== false || strpos($v, 'WAIT') !== false) return 'PENDING';
    return $v ?: 'PENDING';
}

function hmw_producer_net(array $commissions): float {
    $total = 0.0;
    foreach ($commissions as $commission) {
        $source = strtoupper((string)($commission['source'] ?? ''));
        if ($source === 'PRODUCER' || $source === 'COPRODUCER' || strpos($source, 'PRODUCER') !== false) {
            $val = (float)($commission['value'] ?? $commission['commission']['value'] ?? 0);
            $total += $val;
        }
    }
    return $total;
}

function hmw_money_at(array $data, array $path): float {
    $current = $data;
    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) return 0.0;
        $current = $current[$key];
    }
    return is_numeric($current) ? (float)$current : 0.0;
}

function hmw_cents(float $value): int {
    return (int)round($value * 100);
}

function hmw_payment_event_status(string $status): string {
    $status = strtoupper(trim($status));
    if (in_array($status, ['COMPLETE', 'COMPLETED', 'APPROVED', 'PAID'], true)) return 'APPROVED';
    return $status;
}

function hmw_offer_candidates(array $product, array $offer): array {
    $values = [
        $offer['code'] ?? '',
        $offer['name'] ?? '',
        $product['id'] ?? '',
        $product['name'] ?? '',
    ];
    $candidates = [];
    foreach ($values as $value) {
        foreach (course_access_offer_codes((string)$value) as $code) {
            $candidates[] = $code;
        }
    }
    return array_values(array_unique(array_filter($candidates, static fn($value) => trim((string)$value) !== '')));
}

function hmw_try_grant_lifetime(PDO $pdo, array $product, array $offer, string $transactionCode, string $status, string $event, string $email, string $phoneRaw, ?array $matched): array {
    if (!course_access_purchase_is_approved($status, $event)) {
        return ['granted' => false, 'reason' => 'payment_not_approved'];
    }

    $matchedUser = is_array($matched['user'] ?? null) ? $matched['user'] : null;
    foreach (hmw_offer_candidates($product, $offer) as $offerCode) {
        $attempt = course_access_try_grant_lifetime_purchase($pdo, [
            'user_id' => isset($matchedUser['id']) ? (int)$matchedUser['id'] : null,
            'offer_code' => $offerCode,
            'transaction_code' => $transactionCode,
            'status' => $status,
            'event' => $event,
            'email' => $email,
            'phone' => $phoneRaw,
            'payload' => ['product' => $product, 'offer' => $offer],
            'source' => 'hotmart_webhook',
        ]);
        if (!empty($attempt['granted'])) return $attempt;
    }

    return ['granted' => false, 'reason' => 'no_hotmart_offer_candidate_matched', 'candidates' => hmw_offer_candidates($product, $offer)];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') hmw_reply(405, ['ok' => false, 'message' => 'Método não permitido']);

$token = (string)(get_setting('metrics_hotmart_hottok', '') ?: '');
if ($token === '') hmw_reply(503, ['ok' => false, 'message' => 'HOTTOK ainda não configurado']);

$headers = function_exists('getallheaders') ? getallheaders() : [];
$provided = '';
foreach ($headers as $key => $value) {
    if (in_array(strtolower((string)$key), ['x-hotmart-hottok', 'hotmart-hottok'], true)) {
        $provided = (string)$value;
    }
}

if (!hash_equals($token, $provided)) hmw_reply(401, ['ok' => false, 'message' => 'Não autorizado']);

$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) hmw_reply(400, ['ok' => false, 'message' => 'JSON inválido']);

$pdo = getPDO();
metrics_ensure_schema($pdo);
hub_ensure_schema($pdo);

$eventId = (string)($payload['id'] ?? hash('sha256', $raw));
$event = (string)($payload['event'] ?? '');
$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$purchase = is_array($data['purchase'] ?? null) ? $data['purchase'] : [];
$buyer = is_array($data['buyer'] ?? null) ? $data['buyer'] : [];
$product = is_array($data['product'] ?? null) ? $data['product'] : [];
$offer = is_array($purchase['offer'] ?? null) ? $purchase['offer'] : [];
$transaction = trim((string)($purchase['transaction'] ?? ''));

if ($transaction === '') {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO hotmart_webhook_events (event_id,event_name,transaction_code,process_status,process_message,payload_json,received_at,processed_at) VALUES (:id,:event,NULL,'success','Evento sem transação armazenado',:payload,NOW(),NOW()) ON DUPLICATE KEY UPDATE event_name=VALUES(event_name),process_status='success',process_message='Reprocessado sem transação',payload_json=VALUES(payload_json),processed_at=NOW()");
        $stmt->execute(['id' => $eventId, 'event' => $event, 'payload' => $raw]);
        $hubResult = hub_ingest_hotmart($pdo, $payload);
        $pdo->commit();
        hmw_reply(200, ['ok' => true, 'event' => $event, 'transaction' => null, 'stored' => true, 'hub_deliveries' => $hubResult['deliveries'], 'dispatched' => false]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        app_log('Falha no webhook Hotmart sem transacao', ['event_id' => $eventId, 'event' => $event, 'error' => $e->getMessage()]);
        hmw_reply(500, ['ok' => false, 'message' => 'Falha ao armazenar evento']);
    }
}

$txPrefixed = strpos($transaction, 'hotmart:') === 0 ? $transaction : 'hotmart:' . $transaction;
$email = normalize_email_value($buyer['email'] ?? '');
$phoneRaw = trim((string)($buyer['checkout_phone_code'] ?? '') . (string)($buyer['checkout_phone'] ?? ''));
$phone = normalize_phone_value($phoneRaw);

$matched = hotmart_find_matching_user($pdo, $email, $phone);
$status = hmw_status($event, (string)($purchase['status'] ?? ''));

$purchasePrice = hmw_money_at($purchase, ['price', 'value']);
$gross = hmw_money_at($purchase, ['full_price', 'value']);
if ($gross <= 0) $gross = hmw_money_at($purchase, ['original_offer_price', 'value']);
if ($gross <= 0) $gross = $purchasePrice;

$producer = hmw_producer_net((array)($data['commissions'] ?? []));
$net = $producer > 0 ? $producer : $purchasePrice;
if ($producer <= 0) $producer = $net;

$sale = hotmart_build_sale_data_from_array([
    'webhook_event' => $event,
    'webhook_event_id' => $eventId,
    'transaction_code' => $txPrefixed,
    'status' => $status,
    'transaction_date' => hmw_datetime($purchase['order_date'] ?? null),
    'payment_confirmed_at' => hmw_datetime($purchase['approved_date'] ?? null),
    'refund_or_chargeback_at' => in_array($status, ['REFUNDED', 'CHARGEBACK', 'CANCELED'], true) ? hmw_datetime($payload['creation_date'] ?? null) : null,
    'product_code' => $product['id'] ?? null,
    'product_name' => $product['name'] ?? '',
    'price_code' => $offer['code'] ?? '',
    'price_name' => $offer['name'] ?? '',
    'currency' => $purchase['price']['currency_value'] ?? ($purchase['full_price']['currency_value'] ?? 'BRL'),
    'gross_revenue' => $gross,
    'net_revenue' => $net,
    'producer_net' => $producer,
    'refunded_value' => $status === 'REFUNDED' ? $net : 0,
    'chargeback_value' => $status === 'CHARGEBACK' ? $net : 0,
    'buyer_name' => $buyer['name'] ?? '',
    'buyer_email' => $email,
    'buyer_phone_raw' => $phoneRaw,
    'buyer_phone_norm' => $phone,
    'raw_payload_json' => $raw,
], $matched);

try {
    $pdo->beginTransaction();
    hotmart_upsert_sale_live($pdo, $sale);
    hotmart_upsert_sale_legacy($pdo, $sale);

    $stmt = $pdo->prepare("INSERT INTO hotmart_webhook_events (event_id,event_name,transaction_code,process_status,process_message,payload_json,received_at,processed_at) VALUES (:id,:event,:tx,'success','Processado',:payload,NOW(),NOW()) ON DUPLICATE KEY UPDATE process_status='success',process_message='Reprocessado',processed_at=NOW()");
    $stmt->execute(['id' => $eventId, 'event' => $event, 'tx' => $txPrefixed, 'payload' => $raw]);

    $payment = (string)($purchase['payment']['type'] ?? '');
    $installments = (int)($purchase['payment']['installments_number'] ?? 0);
    $origin = (string)($purchase['origin']['src'] ?? 'hotmart');

    $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,sale_origin=:origin,sales_channel='hotmart' WHERE transaction_code=:tx")->execute(['payment' => $payment ?: null, 'installments' => $installments ?: null, 'origin' => $origin ?: null, 'tx' => $txPrefixed]);

    $hubResult = hub_ingest_hotmart($pdo, $payload);
    $lifetimeAttempt = hmw_try_grant_lifetime($pdo, $product, $offer, $txPrefixed, $status, $event, $email, $phoneRaw, $matched);
    $pdo->commit();

    $paymentEventStatus = hmw_payment_event_status($status);
    $paymentEvent = payment_event_register($pdo, [
        'provider' => 'hotmart',
        'normalized_status' => $paymentEventStatus,
        'transaction_code' => $txPrefixed,
        'provider_transaction_id' => $transaction,
        'provider_status' => (string)($purchase['status'] ?? $event),
        'payment_method' => $payment ?: null,
        'currency' => (string)($sale['currency'] ?? 'BRL'),
        'gross_amount_cents' => hmw_cents($gross),
        'net_amount_cents' => hmw_cents($net),
        'fee_amount_cents' => max(0, hmw_cents($gross - $producer)),
        'installments' => $installments ?: null,
        'product_name' => (string)($product['name'] ?? ''),
        'product_code' => (string)($product['id'] ?? ''),
        'checkout_id' => (string)($offer['code'] ?? ''),
        'checkout_url' => payment_event_first_value($payload, ['checkout_url', 'payment_url', 'url']),
        'buyer_name' => (string)($buyer['name'] ?? ''),
        'buyer_email' => $email,
        'buyer_phone' => $phoneRaw,
        'buyer_document' => payment_event_first_value($buyer, ['document', 'document_number', 'cpf', 'cnpj']),
        'user_id' => (int)($sale['matched_user_id'] ?? 0),
        'raw_payload' => $payload,
        'metadata' => [
            'source' => 'hotmart_webhook',
            'event' => $event,
            'event_id' => $eventId,
            'match_method' => (string)($sale['match_method'] ?? 'none'),
            'lifetime_attempt' => $lifetimeAttempt,
        ],
        'occurred_at' => $sale['payment_confirmed_at'] ?: $sale['transaction_date'] ?: date('Y-m-d H:i:s'),
    ]);

    hmw_reply(200, [
        'ok' => true,
        'transaction' => $txPrefixed,
        'status' => $status,
        'match_method' => $sale['match_method'],
        'hub_deliveries' => $hubResult['deliveries'],
        'dispatched' => ((int)($paymentEvent['triggered'] ?? 0)) > 0,
        'payment_event' => $paymentEvent,
        'lifetime_granted' => !empty($lifetimeAttempt['granted'])
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_log('Falha no webhook de metricas Hotmart', ['event_id' => $eventId, 'transaction' => $transaction, 'error' => $e->getMessage()]);
    hmw_reply(500, ['ok' => false, 'message' => 'Falha ao processar evento']);
}
