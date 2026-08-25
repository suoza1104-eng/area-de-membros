<?php
declare(strict_types=1);

require_once __DIR__ . '/firepay.php';
require_once __DIR__ . '/course_access.php';
require_once __DIR__ . '/payment_events.php';
require_once __DIR__ . '/payment_amounts.php';

function dom_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    metrics_ensure_schema($pdo);
    firepay_ensure_schema($pdo);
    firepay_ensure_hotmart_compat_schema($pdo);
    course_access_ensure_schema($pdo);

    foreach ([
        "ALTER TABLE payment_sales ADD COLUMN net_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER gross_amount_cents",
        "ALTER TABLE payment_sales ADD COLUMN fee_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER net_amount_cents",
    ] as $migration) {
        try { $pdo->exec($migration); } catch (Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS dom_webhook_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_fingerprint CHAR(64) NOT NULL,
        external_transaction_id VARCHAR(100) NULL,
        event_name VARCHAR(100) NULL,
        provider_status VARCHAR(80) NULL,
        signature_valid TINYINT(1) NOT NULL DEFAULT 0,
        process_status ENUM('success','ignored','error') NOT NULL,
        process_message TEXT NULL,
        payload_json LONGTEXT NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_dom_fingerprint (event_fingerprint),
        KEY idx_dom_transaction (external_transaction_id),
        KEY idx_dom_status (process_status),
        KEY idx_dom_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dom_api_sync_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_date DATE NOT NULL,
        trigger_source VARCHAR(60) NOT NULL,
        status ENUM('success','error') NOT NULL,
        total_listed INT UNSIGNED NOT NULL DEFAULT 0,
        total_synced INT UNSIGNED NOT NULL DEFAULT 0,
        total_errors INT UNSIGNED NOT NULL DEFAULT 0,
        message TEXT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_dom_sync_date (sync_date),
        KEY idx_dom_sync_finished (finished_at),
        KEY idx_dom_sync_source (trigger_source)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function dom_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

/** @deprecated usar payment_amount_cents() (app/payment_amounts.php) — mantido só por segurança. */
function dom_int_cents($value): int
{
    return payment_amount_cents($value);
}

function dom_api_normalized_status(string $status): string
{
    $value = strtolower(trim($status));
    if ($value === 'paid') return 'APPROVED';
    if (in_array($value, ['pending', 'capture', 'revision_paid'], true)) return 'PENDING';
    if (in_array($value, ['refunded', 'pending_refund'], true)) return 'REFUNDED';
    if (in_array($value, ['chargeback', 'in_mediation', 'dispute_pending'], true)) return 'CHARGEBACK';
    if (in_array($value, ['failed', 'not_authorized', 'expired', 'cancelled_capture'], true)) return 'CANCELED';
    return 'UNKNOWN';
}

function dom_normalized_status(string $event, string $status): string
{
    $event = strtoupper(trim($event));
    $status = strtolower(trim($status));
    if ($event === 'CHARGE-APPROVED' || $status === 'paid') return 'APPROVED';
    if (in_array($event, ['CHARGE-REFUND'], true) || in_array($status, ['refunded', 'pending_refund'], true)) return 'REFUNDED';
    if (in_array($event, ['CHARGE-CHARGEBACK', 'CHARGE-DISPUT', 'CHARGE-DISPUTE_PENDING'], true) || in_array($status, ['chargeback', 'in_mediation', 'dispute_pending'], true)) return 'CHARGEBACK';
    if (in_array($event, ['CHARGE-NOT_AUTHORIZED', 'CHARGE-EXPIRE', 'CHARGE-REJECTED_ANTIFRAUD'], true) || in_array($status, ['failed', 'not_authorized', 'expired', 'cancelled_capture'], true)) return 'CANCELED';
    if (in_array($event, ['CHARGE-PENDING', 'CHARGE-REVISION_PAID'], true) || in_array($status, ['pending', 'capture', 'revision_paid'], true)) return 'PENDING';
    return 'UNKNOWN';
}

function dom_parse_jsonish($value): array
{
    if (is_array($value)) return $value;
    $raw = trim((string)$value);
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    $json = json_decode(str_replace("'", '"', $raw), true);
    return is_array($json) ? $json : [];
}

function dom_transaction_datetime(array $data): string
{
    $value = dom_scalar($data, 'updated_at') ?: dom_scalar($data, 'created_at');
    if ($value !== '') {
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {}
    }
    return date('Y-m-d H:i:s');
}

function dom_find_matching_user(PDO $pdo, string $emailNorm, string $phoneNorm): array
{
    if ($emailNorm !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content
             FROM users
             WHERE LOWER(TRIM(email)) = :email
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([':email' => $emailNorm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['user' => $row, 'method' => 'email'];
    }

    return hotmart_find_matching_user($pdo, '', $phoneNorm);
}

function dom_jwt_base64url_decode(string $value): string
{
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($value, true);
    if ($decoded === false) throw new InvalidArgumentException('DOM: assinatura JWT invalida.');
    return $decoded;
}

function dom_validate_signature(string $signature, string $apiToken, string $transactionId): bool
{
    if ($apiToken === '') return false;
    $parts = explode('.', trim($signature));
    if (count($parts) !== 3) return false;
    $header = json_decode(dom_jwt_base64url_decode($parts[0]), true);
    if (!is_array($header) || strtoupper((string)($header['alg'] ?? '')) !== 'HS256') return false;
    $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $apiToken, true);
    $actual = dom_jwt_base64url_decode($parts[2]);
    if (!hash_equals($expected, $actual)) return false;
    $payload = json_decode(dom_jwt_base64url_decode($parts[1]), true);
    if (!is_array($payload)) return false;
    if (isset($payload['exp']) && (int)$payload['exp'] < time()) return false;
    $signedId = trim((string)($payload['id'] ?? ''));
    return $signedId === '' || $transactionId === '' || hash_equals($signedId, $transactionId);
}

function dom_offer_candidates(array $data): array
{
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $metadata = dom_parse_jsonish($data['metadata'] ?? '');
    $relations = is_array($data['relations'] ?? null) ? $data['relations'] : [];
    $candidates = [];

    $add = static function ($value) use (&$candidates): void {
        $value = trim((string)$value);
        if ($value === '') return;
        foreach (course_access_offer_codes($value) as $code) $candidates[] = $code;
    };
    $addWithPrefix = static function ($value, string $prefix) use (&$candidates, $add): void {
        $value = trim((string)$value);
        if ($value === '') return;
        $add($value);
        $add($prefix . ':' . $value);
    };

    foreach ([
        $data['cod_external'] ?? '',
        $metadata['offer_code'] ?? '',
        $metadata['oferta'] ?? '',
        $metadata['offer'] ?? '',
        $metadata['produto'] ?? '',
        $relations['id_link_payment'] ?? '',
    ] as $value) {
        $add($value);
    }

    foreach ([$metadata['checkout_id'] ?? '', $metadata['checkout'] ?? '', $relations['id_link_payment'] ?? ''] as $value) {
        $addWithPrefix($value, 'checkout');
    }
    foreach ([$metadata['product_id'] ?? '', $metadata['product'] ?? ''] as $value) {
        $addWithPrefix($value, 'product');
    }

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        foreach ([$item['reference'] ?? '', $item['externCode'] ?? '', $item['id'] ?? '', $item['description'] ?? ''] as $value) {
            $add($value);
        }
        foreach ([$item['sku'] ?? ''] as $value) {
            $add($value);
            if (preg_match('/(?:^|[-_])(\d+)$/', trim((string)$value), $m)) {
                $addWithPrefix($m[1], 'product');
            }
        }
    }

    return array_values(array_unique(array_filter($candidates, static fn($v) => trim((string)$v) !== '')));
}

function dom_try_grant_lifetime(PDO $pdo, array $data, string $transactionCode, string $status, string $email, string $phoneRaw, ?array $matchedUser): array
{
    if (isset($matchedUser['id'])) {
        $existingGrant = course_access_lifetime_entitlement($pdo, (int)$matchedUser['id']);
        if ($existingGrant && (int)($existingGrant['is_paid'] ?? 0) === 1) {
            return [
                'granted' => false,
                'reason' => 'user_already_has_paid_lifetime',
                'user_id' => (int)$matchedUser['id'],
                'transaction_code' => (string)($existingGrant['transaction_code'] ?? ''),
            ];
        }
    }

    foreach (dom_offer_candidates($data) as $offerCode) {
        $attempt = course_access_try_grant_lifetime_purchase($pdo, [
            'user_id' => isset($matchedUser['id']) ? (int)$matchedUser['id'] : null,
            'offer_code' => $offerCode,
            'transaction_code' => $transactionCode,
            'status' => $status,
            'event' => 'DOM_CHARGE_APPROVED',
            'email' => $email,
            'phone' => $phoneRaw,
            'payload' => $data,
            'source' => 'dom',
        ]);
        if (!empty($attempt['granted'])) return $attempt;
    }
    return ['granted' => false, 'reason' => 'no_dom_offer_candidate_matched', 'candidates' => dom_offer_candidates($data)];
}

function dom_api_request(string $path, array $query = [], int $timeoutSeconds = 30, int $connectTimeoutSeconds = 10): array
{
    // Seam de teste: um script de verificacao pode sobrescrever a chamada real
    // de rede sem precisar de credenciais/ambiente sandbox. Nunca setado em producao.
    if (isset($GLOBALS['dom_api_request_override']) && is_callable($GLOBALS['dom_api_request_override'])) {
        return ($GLOBALS['dom_api_request_override'])($path, $query);
    }

    $apiToken = trim((string)get_setting('dom_pagamentos_api_token', ''));
    if ($apiToken === '') throw new RuntimeException('DOM API: token nao configurado.');

    $environment = (string)get_setting('dom_pagamentos_environment', 'production');
    $environment = $environment === 'sandbox' ? 'sandbox' : 'production';
    $url = 'https://apiv3.dompagamentos.com.br/checkout/' . $environment . $path;
    if ($query) $url .= '?' . http_build_query($query);

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken,
        'User-Agent: AreaMembros-DOM-Sync/1.0',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('DOM API: falha HTTP ' . $error);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
        if ($body === false) throw new RuntimeException('DOM API: falha HTTP via stream.');
    }

    $decoded = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('DOM API: HTTP ' . $status . ' ' . substr((string)$body, 0, 500));
    }
    if (!is_array($decoded)) throw new RuntimeException('DOM API: resposta JSON invalida.');
    return $decoded;
}

function dom_api_detail(string $transactionId, int $timeoutSeconds = 30): array
{
    $transactionId = trim($transactionId);
    if ($transactionId === '') throw new InvalidArgumentException('DOM API: id ausente.');
    return dom_api_request('/transactions/' . rawurlencode($transactionId), [], $timeoutSeconds, min(10, $timeoutSeconds));
}

/**
 * Busca gross/liquid/fee autoritativos na API da DOM quando o webhook chegou
 * sem liquid_amount (comum em eventos de status intermediario). Timeout curto
 * e fallback gracioso: qualquer falha retorna null e quem chamou mantem os
 * valores que ja tinha do payload do webhook — nunca derruba o processamento.
 */
function dom_try_fetch_authoritative_amounts(string $transactionId, int $timeoutSeconds = 8): ?array
{
    try {
        $detail = dom_api_detail($transactionId, $timeoutSeconds);
    } catch (Throwable $e) {
        if (function_exists('app_log')) {
            app_log('DOM: fallback de API para taxa/liquido falhou', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
        return null;
    }
    $grossCents = payment_amount_cents($detail['amount'] ?? 0);
    $liquidCents = payment_amount_cents($detail['liquid_amount'] ?? 0);
    $feeDetails = is_array($detail['fee_details'] ?? null) ? $detail['fee_details'] : [];
    $feeCents = isset($feeDetails['amount'])
        ? payment_amount_cents($feeDetails['amount'])
        : max(0, $grossCents - $liquidCents);
    return ['gross_cents' => $grossCents, 'liquid_cents' => $liquidCents, 'fee_cents' => $feeCents];
}

function dom_sync_transaction(PDO $pdo, array $detail, string $triggerSource = 'api_sync'): array
{
    dom_ensure_schema($pdo);

    $transactionId = dom_scalar($detail, 'id');
    if ($transactionId === '') throw new InvalidArgumentException('DOM API: transacao sem id.');

    $providerStatus = dom_scalar($detail, 'status');
    $normalizedStatus = dom_api_normalized_status($providerStatus);
    $customer = is_array($detail['customer'] ?? null) ? $detail['customer'] : [];
    $items = is_array($detail['items'] ?? null) ? $detail['items'] : [];
    $firstItem = is_array($items[0] ?? null) ? $items[0] : [];
    $query = dom_parse_jsonish($detail['query_param'] ?? '');
    $metadata = dom_parse_jsonish($detail['metadata'] ?? '');
    $relations = is_array($detail['relations'] ?? null) ? $detail['relations'] : [];
    $email = normalize_email_value($customer['email'] ?? $detail['customer_email'] ?? '');
    $phoneRaw = dom_scalar($customer, 'mobile_phone') ?: dom_scalar($detail, 'customer_phone');
    $phoneNorm = normalize_phone_value($phoneRaw);
    $matched = dom_find_matching_user($pdo, $email, $phoneNorm);
    $matchedUser = is_array($matched['user'] ?? null) ? $matched['user'] : null;
    $transactionCode = 'dom:' . $transactionId;
    $receivedAt = dom_transaction_datetime($detail);
    $grossCents = payment_amount_cents($detail['amount'] ?? 0);
    $liquidCents = payment_amount_cents($detail['liquid_amount'] ?? 0);
    $feeDetails = is_array($detail['fee_details'] ?? null) ? $detail['fee_details'] : [];
    $feeCents = isset($feeDetails['amount']) ? payment_amount_cents($feeDetails['amount']) : max(0, $grossCents - $liquidCents);
    // Esta funcao consulta a API diretamente (fonte autoritativa), entao so fica
    // "estimado" no caso raro de a propria API nao trazer liquid_amount.
    $feeIsEstimated = $liquidCents <= 0;
    $productCents = payment_amount_cents($firstItem['price'] ?? $grossCents);
    $productName = dom_scalar($firstItem, 'description') ?: dom_scalar($detail, 'product_first') ?: (string)($metadata['product_name'] ?? $metadata['produto'] ?? '');
    $productRef = dom_scalar($firstItem, 'reference') ?: dom_scalar($firstItem, 'externCode') ?: dom_scalar($firstItem, 'sku');
    $rawPayload = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $fingerprint = hash('sha256', $triggerSource . '|' . $transactionId . '|' . $providerStatus . '|' . $rawPayload);

    $pdo->beginTransaction();
    try {
        $sale = $pdo->prepare("INSERT INTO payment_sales
            (provider,external_transaction_id,external_checkout_id,transaction_type,provider_status,normalized_status,currency,
             gross_amount_cents,net_amount_cents,fee_amount_cents,fee_is_estimated,product_amount_cents,interest_amount_cents,installments,payment_method,payment_gateway,provider_account_id,
             external_product_id,product_name,product_slug,integration_id,integration_delivery_type,classes_text,origin_description,origin_slug,
             buyer_name,buyer_email,buyer_phone,buyer_phone_norm,buyer_document,matched_user_id,match_method,checkout_url,order_bumps_json,raw_payload_json,
             first_received_at,last_received_at)
            VALUES ('dom',:transaction,:checkout,:type,:provider_status,:normalized_status,:currency,:gross,:net,:fee,:fee_is_estimated,:product_amount,0,
             :installments,:payment_method,'dom',NULL,:product_id,:product_name,NULL,:integration_id,NULL,NULL,:origin,NULL,
             :buyer_name,:buyer_email,:buyer_phone,:phone_norm,:buyer_document,:user_id,:match_method,:checkout_url,NULL,:payload,:received_at,:received_at)
            ON DUPLICATE KEY UPDATE external_checkout_id=VALUES(external_checkout_id),transaction_type=VALUES(transaction_type),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),currency=VALUES(currency),
             gross_amount_cents=VALUES(gross_amount_cents),
             net_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(net_amount_cents) ELSE net_amount_cents END,
             fee_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_amount_cents) ELSE fee_amount_cents END,
             fee_is_estimated=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_is_estimated) ELSE fee_is_estimated END,
             product_amount_cents=VALUES(product_amount_cents),installments=VALUES(installments),payment_method=VALUES(payment_method),
             external_product_id=VALUES(external_product_id),product_name=VALUES(product_name),integration_id=VALUES(integration_id),
             origin_description=VALUES(origin_description),buyer_name=VALUES(buyer_name),buyer_email=VALUES(buyer_email),
             buyer_phone=VALUES(buyer_phone),buyer_phone_norm=VALUES(buyer_phone_norm),buyer_document=VALUES(buyer_document),matched_user_id=VALUES(matched_user_id),
             match_method=VALUES(match_method),checkout_url=VALUES(checkout_url),raw_payload_json=VALUES(raw_payload_json),
             last_received_at=VALUES(last_received_at)");
        $sale->execute([
            ':transaction' => $transactionCode,
            ':checkout' => (string)($relations['id_link_payment'] ?? '') ?: null,
            ':type' => dom_scalar($detail, 'type') ?: 'api_sync',
            ':provider_status' => $providerStatus,
            ':normalized_status' => $normalizedStatus,
            ':currency' => dom_scalar($detail, 'currency') ?: 'BRL',
            ':gross' => $grossCents,
            ':net' => $liquidCents,
            ':fee' => $feeCents,
            ':fee_is_estimated' => $feeIsEstimated ? 1 : 0,
            ':product_amount' => $productCents,
            ':installments' => (int)(dom_scalar($detail, 'installments') ?: 0) ?: null,
            ':payment_method' => dom_scalar($detail, 'payment_method') ?: null,
            ':product_id' => $productRef ?: null,
            ':product_name' => $productName ?: null,
            ':integration_id' => (string)($metadata['offer_code'] ?? $metadata['oferta'] ?? $metadata['checkout_id'] ?? '') ?: null,
            ':origin' => (string)($query['utm_source'] ?? $metadata['platform_integration'] ?? 'dom_api') ?: null,
            ':buyer_name' => dom_scalar($customer, 'name') ?: dom_scalar($detail, 'customer_name') ?: null,
            ':buyer_email' => $email ?: null,
            ':buyer_phone' => $phoneRaw ?: null,
            ':phone_norm' => $phoneNorm ?: null,
            ':buyer_document' => dom_scalar($customer, 'document') ?: dom_scalar($detail, 'customer_document') ?: null,
            ':user_id' => $matchedUser['id'] ?? null,
            ':match_method' => (string)($matched['method'] ?? 'none'),
            ':checkout_url' => dom_scalar($detail, 'postbackUrl') ?: null,
            ':payload' => $rawPayload,
            ':received_at' => $receivedAt,
        ]);

        $lifetimeAttempt = ['granted' => false, 'reason' => 'payment_not_approved'];
        if (in_array($normalizedStatus, ['APPROVED', 'REFUNDED', 'CHARGEBACK', 'CANCELED'], true)) {
            $legacySale = hotmart_build_sale_data_from_array([
                'webhook_event' => 'DOM_API_' . $normalizedStatus,
                'webhook_event_id' => $fingerprint,
                'transaction_code' => $transactionCode,
                'status' => $normalizedStatus,
                'transaction_date' => $receivedAt,
                'payment_confirmed_at' => $normalizedStatus === 'APPROVED' ? $receivedAt : null,
                'refund_or_chargeback_at' => in_array($normalizedStatus, ['REFUNDED', 'CHARGEBACK'], true) ? $receivedAt : null,
                'product_code' => $productRef ?: null,
                'product_name' => $productName,
                'price_code' => (string)($relations['id_link_payment'] ?? ''),
                'price_name' => (string)($metadata['offer_code'] ?? $metadata['oferta'] ?? $metadata['checkout_id'] ?? ''),
                'currency' => dom_scalar($detail, 'currency') ?: 'BRL',
                'gross_revenue' => $grossCents / 100,
                'net_revenue' => $liquidCents > 0 ? $liquidCents / 100 : $grossCents / 100,
                'producer_net' => $liquidCents > 0 ? $liquidCents / 100 : $grossCents / 100,
                'refunded_value' => $normalizedStatus === 'REFUNDED' ? payment_amount_cents((is_array($detail['refunds'] ?? null) ? ($detail['refunds']['total_refunds'] ?? 0) : 0)) / 100 : 0,
                'chargeback_value' => $normalizedStatus === 'CHARGEBACK' ? $grossCents / 100 : 0,
                'buyer_name' => dom_scalar($customer, 'name') ?: dom_scalar($detail, 'customer_name'),
                'buyer_email' => $email,
                'buyer_phone_raw' => $phoneRaw,
                'buyer_phone_norm' => $phoneNorm,
                'raw_payload_json' => $rawPayload,
            ], $matched);
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utm) {
                if (isset($query[$utm])) $legacySale[$utm] = (string)$query[$utm];
            }
            hotmart_upsert_sale_live($pdo, $legacySale);
            hotmart_upsert_sale_legacy($pdo, $legacySale);
            $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,
                sale_origin=:origin,sales_channel='dom' WHERE transaction_code=:transaction")
                ->execute([
                    ':payment' => dom_scalar($detail, 'payment_method') ?: null,
                    ':installments' => (int)(dom_scalar($detail, 'installments') ?: 0) ?: null,
                    ':origin' => (string)($query['utm_source'] ?? $metadata['platform_integration'] ?? 'dom_api') ?: null,
                    ':transaction' => $transactionCode,
                ]);

            if ($normalizedStatus === 'APPROVED') {
                $lifetimeAttempt = dom_try_grant_lifetime($pdo, $detail, $transactionCode, 'paid', $email, $phoneRaw, $matchedUser);
            }
        }

        if ($pdo->inTransaction()) $pdo->commit();
        $paymentEvent = payment_event_register($pdo, [
            'provider'=>'dom',
            'normalized_status'=>$normalizedStatus,
            'transaction_code'=>$transactionCode,
            'provider_transaction_id'=>$transactionId,
            'provider_status'=>$providerStatus,
            'payment_method'=>dom_scalar($detail, 'payment_method'),
            'currency'=>dom_scalar($detail, 'currency') ?: 'BRL',
            'gross_amount_cents'=>$grossCents,
            'net_amount_cents'=>$liquidCents,
            'fee_amount_cents'=>$feeCents,
            'installments'=>(int)(dom_scalar($detail, 'installments') ?: 0) ?: null,
            'product_name'=>$productName,
            'product_code'=>$productRef,
            'checkout_id'=>(string)($relations['id_link_payment'] ?? ''),
            'checkout_url'=>dom_scalar($detail, 'postbackUrl') ?: payment_event_first_value($detail, ['checkout_url','payment_url','url']),
            'pix_qrcode'=>payment_event_first_value($detail, ['qr_code','pix_qr_code','pix_code','qrcode','copy_paste']),
            'pix_qrcode_url'=>payment_event_first_value($detail, ['qr_code_url','pix_qr_code_url','pix_url','qrcode_url']),
            'pix_expires_at'=>payment_event_first_value($detail, ['expires_at','expiration_date','pix_expires_at']),
            'boleto_url'=>payment_event_first_value($detail, ['boleto_url','pdf','url']),
            'boleto_line'=>payment_event_first_value($detail, ['line','digitable_line','barcode']),
            'buyer_name'=>dom_scalar($customer, 'name') ?: dom_scalar($detail, 'customer_name'),
            'buyer_email'=>$email,
            'buyer_phone'=>$phoneRaw,
            'buyer_document'=>dom_scalar($customer, 'document') ?: dom_scalar($detail, 'customer_document'),
            'user_id'=>(int)($matchedUser['id'] ?? 0),
            'raw_payload'=>$detail,
            'metadata'=>[
                'source'=>'dom_api_sync',
                'trigger_source'=>$triggerSource,
                'match_method'=>(string)($matched['method'] ?? 'none'),
                'query_param'=>$query,
                'metadata'=>$metadata,
                'lifetime_attempt'=>$lifetimeAttempt,
            ],
            'occurred_at'=>$receivedAt,
        ]);
        return [
            'transaction_id' => $transactionId,
            'transaction_code' => $transactionCode,
            'normalized_status' => $normalizedStatus,
            'gross_amount_cents' => $grossCents,
            'net_amount_cents' => $liquidCents,
            'fee_amount_cents' => $feeCents,
            'matched_user_id' => (int)($matchedUser['id'] ?? 0),
            'lifetime_granted' => !empty($lifetimeAttempt['granted']),
            'payment_event' => $paymentEvent,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dom_sync_transactions_for_date(PDO $pdo, string $date, string $triggerSource = 'scheduled'): array
{
    dom_ensure_schema($pdo);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $started = date('Y-m-d H:i:s');
    $listed = 0;
    $synced = 0;
    $errors = [];
    $page = 1;

    try {
        do {
            $response = dom_api_request('/transactions', [
                'begin_date' => $date,
                'end_date' => $date,
                'type_date' => 'updated',
                'page' => (string)$page,
            ]);
            $items = is_array($response['data'] ?? null) ? $response['data'] : (is_array($response['items'] ?? null) ? $response['items'] : []);
            $listed += count($items);
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $id = trim((string)($item['id'] ?? ''));
                if ($id === '') continue;
                try {
                    dom_sync_transaction($pdo, dom_api_detail($id), $triggerSource);
                    $synced++;
                } catch (Throwable $e) {
                    $errors[] = $id . ': ' . $e->getMessage();
                }
            }
            $totalPages = max(1, (int)($response['total_pages'] ?? 1));
            $page++;
        } while ($page <= $totalPages && $page <= 50);

        $status = $errors ? 'error' : 'success';
        $message = $errors ? implode("\n", array_slice($errors, 0, 20)) : 'Sincronizacao DOM concluida.';
        $pdo->prepare("INSERT INTO dom_api_sync_runs
            (sync_date,trigger_source,status,total_listed,total_synced,total_errors,message,started_at,finished_at)
            VALUES (:sync_date,:trigger_source,:status,:listed,:synced,:errors,:message,:started,:finished)")
            ->execute([
                ':sync_date' => $date,
                ':trigger_source' => $triggerSource,
                ':status' => $status,
                ':listed' => $listed,
                ':synced' => $synced,
                ':errors' => count($errors),
                ':message' => $message,
                ':started' => $started,
                ':finished' => date('Y-m-d H:i:s'),
            ]);

        return ['ok' => !$errors, 'date' => $date, 'listed' => $listed, 'synced' => $synced, 'errors' => $errors];
    } catch (Throwable $e) {
        $pdo->prepare("INSERT INTO dom_api_sync_runs
            (sync_date,trigger_source,status,total_listed,total_synced,total_errors,message,started_at,finished_at)
            VALUES (:sync_date,:trigger_source,'error',:listed,:synced,:errors,:message,:started,:finished)")
            ->execute([
                ':sync_date' => $date,
                ':trigger_source' => $triggerSource,
                ':listed' => $listed,
                ':synced' => $synced,
                ':errors' => count($errors) + 1,
                ':message' => $e->getMessage(),
                ':started' => $started,
                ':finished' => date('Y-m-d H:i:s'),
            ]);
        throw $e;
    }
}

function dom_process_webhook(PDO $pdo, array $payload, string $rawPayload): array
{
    dom_ensure_schema($pdo);

    $event = trim((string)($payload['event'] ?? ''));
    $signature = trim((string)($payload['signature'] ?? ''));
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    if ($event === '') throw new InvalidArgumentException('DOM: evento ausente.');
    if (!$data) throw new InvalidArgumentException('DOM: data ausente ou invalido.');

    $transactionId = dom_scalar($data, 'id');
    if ($transactionId === '') throw new InvalidArgumentException('DOM: id da transacao ausente.');

    $apiToken = trim((string)get_setting('dom_pagamentos_api_token', ''));
    $requireSignature = (string)get_setting('dom_pagamentos_require_signature', '1') === '1';
    $signatureValid = dom_validate_signature($signature, $apiToken, $transactionId);
    if ($requireSignature && !$signatureValid) {
        throw new RuntimeException('DOM: assinatura invalida ou token da API nao configurado.');
    }

    $providerStatus = dom_scalar($data, 'status');
    $normalizedStatus = dom_normalized_status($event, $providerStatus);
    $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $firstItem = is_array($items[0] ?? null) ? $items[0] : [];
    $query = dom_parse_jsonish($data['query_param'] ?? '');
    $metadata = dom_parse_jsonish($data['metadata'] ?? '');
    $email = normalize_email_value($customer['email'] ?? '');
    $phoneRaw = dom_scalar($customer, 'mobile_phone');
    $phoneNorm = normalize_phone_value($phoneRaw);
    $matched = dom_find_matching_user($pdo, $email, $phoneNorm);
    $matchedUser = is_array($matched['user'] ?? null) ? $matched['user'] : null;
    $transactionCode = 'dom:' . $transactionId;
    $receivedAt = dom_transaction_datetime($data);
    $amountCents = payment_amount_cents($data['amount'] ?? 0);
    $liquidCents = payment_amount_cents($data['liquid_amount'] ?? 0);
    $paymentMethod = dom_scalar($data, 'payment_method');
    $feeCents = payment_amount_fee_cents([$data], $amountCents, 'dom', $paymentMethod);
    $netCents = $liquidCents > 0 ? min($liquidCents, $amountCents) : payment_amount_net_cents([$data], $amountCents, $feeCents);
    if ($feeCents <= 0 && $netCents > 0 && $amountCents > $netCents) $feeCents = $amountCents - $netCents;
    $feeIsEstimated = $liquidCents <= 0;
    // Evento sem liquid_amount (comum antes da liquidacao). So vale buscar na API
    // para status que vao gravar valor financeiro definitivo — chamada de rede
    // fica fora da transacao (ainda nao abrimos beginTransaction), com timeout
    // curto e fallback gracioso: se falhar, seguimos com os valores do payload.
    if ($feeIsEstimated && in_array($normalizedStatus, ['APPROVED', 'REFUNDED', 'CHARGEBACK', 'CANCELED'], true)) {
        $authoritative = dom_try_fetch_authoritative_amounts($transactionId);
        if ($authoritative !== null && $authoritative['liquid_cents'] > 0) {
            $liquidCents = $authoritative['liquid_cents'];
            $netCents = min($liquidCents, $amountCents);
            $feeCents = $authoritative['fee_cents'] > 0 ? $authoritative['fee_cents'] : max(0, $amountCents - $netCents);
            $feeIsEstimated = false;
        }
    }
    $refundedCents = (int)(is_array($data['refunds'] ?? null) ? ($data['refunds']['total_refunds'] ?? 0) : 0);
    $productName = dom_scalar($firstItem, 'description') ?: (string)($metadata['product_name'] ?? $metadata['produto'] ?? '');
    $productRef = dom_scalar($firstItem, 'reference');
    $fingerprint = hash('sha256', $event . '|' . $transactionId . '|' . $providerStatus . '|' . $rawPayload);

    $pdo->beginTransaction();
    try {
        $log = $pdo->prepare("INSERT INTO dom_webhook_events
            (event_fingerprint,external_transaction_id,event_name,provider_status,signature_valid,process_status,process_message,payload_json,received_at,processed_at)
            VALUES (:fingerprint,:transaction,:event,:provider_status,:signature_valid,:process_status,:message,:payload,NOW(),NOW())
            ON DUPLICATE KEY UPDATE processed_at=NOW(),process_message='Evento repetido; transacao mantida idempotente'");
        $log->execute([
            ':fingerprint' => $fingerprint,
            ':transaction' => $transactionId,
            ':event' => $event,
            ':provider_status' => $providerStatus,
            ':signature_valid' => $signatureValid ? 1 : 0,
            ':process_status' => $normalizedStatus !== 'UNKNOWN' ? 'success' : 'ignored',
            ':message' => $normalizedStatus === 'UNKNOWN' ? 'Status DOM ainda nao mapeado.' : null,
            ':payload' => $rawPayload,
        ]);

        $sale = $pdo->prepare("INSERT INTO payment_sales
            (provider,external_transaction_id,external_checkout_id,transaction_type,provider_status,normalized_status,currency,
             gross_amount_cents,net_amount_cents,fee_amount_cents,fee_is_estimated,product_amount_cents,interest_amount_cents,installments,payment_method,payment_gateway,provider_account_id,
             external_product_id,product_name,product_slug,integration_id,integration_delivery_type,classes_text,origin_description,origin_slug,
             buyer_name,buyer_email,buyer_phone,buyer_phone_norm,buyer_document,matched_user_id,match_method,checkout_url,order_bumps_json,raw_payload_json,
             first_received_at,last_received_at)
            VALUES ('dom',:transaction,:checkout,:type,:provider_status,:normalized_status,:currency,:gross,:net,:fee,:fee_is_estimated,:product_amount,0,
             :installments,:payment_method,'dom',NULL,:product_id,:product_name,NULL,:integration_id,NULL,NULL,:origin,NULL,
             :buyer_name,:buyer_email,:buyer_phone,:phone_norm,:buyer_document,:user_id,:match_method,NULL,NULL,:payload,:received_at,:received_at)
            ON DUPLICATE KEY UPDATE external_checkout_id=VALUES(external_checkout_id),transaction_type=VALUES(transaction_type),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),currency=VALUES(currency),
             gross_amount_cents=VALUES(gross_amount_cents),
             net_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(net_amount_cents) ELSE net_amount_cents END,
             fee_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_amount_cents) ELSE fee_amount_cents END,
             fee_is_estimated=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_is_estimated) ELSE fee_is_estimated END,
             product_amount_cents=VALUES(product_amount_cents),
             installments=VALUES(installments),payment_method=VALUES(payment_method),external_product_id=VALUES(external_product_id),
             product_name=VALUES(product_name),integration_id=VALUES(integration_id),origin_description=VALUES(origin_description),
             buyer_name=VALUES(buyer_name),buyer_email=VALUES(buyer_email),buyer_phone=VALUES(buyer_phone),buyer_phone_norm=VALUES(buyer_phone_norm),buyer_document=VALUES(buyer_document),
             matched_user_id=VALUES(matched_user_id),match_method=VALUES(match_method),raw_payload_json=VALUES(raw_payload_json),
             last_received_at=VALUES(last_received_at)");
        $sale->execute([
            ':transaction' => $transactionCode,
            ':checkout' => (is_array($data['relations'] ?? null) ? ($data['relations']['id_link_payment'] ?? null) : null),
            ':type' => $event,
            ':provider_status' => $providerStatus,
            ':normalized_status' => $normalizedStatus,
            ':currency' => dom_scalar($data, 'currency') ?: 'BRL',
            ':gross' => $amountCents,
            ':net' => $netCents,
            ':fee' => $feeCents,
            ':fee_is_estimated' => $feeIsEstimated ? 1 : 0,
            ':product_amount' => payment_amount_cents($firstItem['price'] ?? $amountCents),
            ':installments' => (int)(dom_scalar($data, 'installments') ?: 0) ?: null,
            ':payment_method' => $paymentMethod ?: null,
            ':product_id' => $productRef ?: null,
            ':product_name' => $productName ?: null,
            ':integration_id' => (string)($metadata['offer_code'] ?? $metadata['oferta'] ?? '') ?: null,
            ':origin' => (string)($query['utm_source'] ?? '') ?: null,
            ':buyer_name' => dom_scalar($customer, 'name') ?: null,
            ':buyer_email' => $email ?: null,
            ':buyer_phone' => $phoneRaw ?: null,
            ':phone_norm' => $phoneNorm ?: null,
            ':buyer_document' => dom_scalar($customer, 'document') ?: null,
            ':user_id' => $matchedUser['id'] ?? null,
            ':match_method' => (string)($matched['method'] ?? 'none'),
            ':payload' => $rawPayload,
            ':received_at' => $receivedAt,
        ]);

        $lifetimeAttempt = ['granted' => false, 'reason' => 'payment_not_approved'];
        if (in_array($normalizedStatus, ['APPROVED', 'REFUNDED', 'CHARGEBACK', 'CANCELED'], true)) {
            $legacySale = hotmart_build_sale_data_from_array([
                'webhook_event' => 'DOM_' . strtoupper(str_replace('-', '_', $event)),
                'webhook_event_id' => $fingerprint,
                'transaction_code' => $transactionCode,
                'status' => $normalizedStatus,
                'transaction_date' => $receivedAt,
                'payment_confirmed_at' => $normalizedStatus === 'APPROVED' ? $receivedAt : null,
                'refund_or_chargeback_at' => in_array($normalizedStatus, ['REFUNDED', 'CHARGEBACK'], true) ? $receivedAt : null,
                'product_code' => null,
                'product_name' => $productName,
                'price_code' => $productRef,
                'price_name' => (string)($metadata['offer_code'] ?? $metadata['oferta'] ?? ''),
                'currency' => dom_scalar($data, 'currency') ?: 'BRL',
                'gross_revenue' => $amountCents / 100,
                'net_revenue' => $netCents / 100,
                'producer_net' => $netCents / 100,
                'refunded_value' => $normalizedStatus === 'REFUNDED' ? $refundedCents / 100 : 0,
                'chargeback_value' => $normalizedStatus === 'CHARGEBACK' ? $amountCents / 100 : 0,
                'buyer_name' => dom_scalar($customer, 'name'),
                'buyer_email' => $email,
                'buyer_phone_raw' => $phoneRaw,
                'buyer_phone_norm' => $phoneNorm,
                'raw_payload_json' => $rawPayload,
            ], $matched);
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utm) {
                if (isset($query[$utm])) $legacySale[$utm] = (string)$query[$utm];
            }
            hotmart_upsert_sale_live($pdo, $legacySale);
            hotmart_upsert_sale_legacy($pdo, $legacySale);
            $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,
                sale_origin=:origin,sales_channel='dom' WHERE transaction_code=:transaction")
                ->execute([
                    ':payment' => dom_scalar($data, 'payment_method') ?: null,
                    ':installments' => (int)(dom_scalar($data, 'installments') ?: 0) ?: null,
                    ':origin' => (string)($query['utm_source'] ?? '') ?: 'dom',
                    ':transaction' => $transactionCode,
                ]);

            if ($normalizedStatus === 'APPROVED') {
                $lifetimeAttempt = dom_try_grant_lifetime($pdo, $data, $transactionCode, 'paid', $email, $phoneRaw, $matchedUser);
            }
        }

        if ($pdo->inTransaction()) $pdo->commit();
        return [
            'transaction_id' => $transactionId,
            'transaction_code' => $transactionCode,
            'event' => $event,
            'normalized_status' => $normalizedStatus,
            'signature_valid' => $signatureValid,
            'matched_user_id' => (int)($matchedUser['id'] ?? 0),
            'match_method' => (string)($matched['method'] ?? 'none'),
            'lifetime_granted' => !empty($lifetimeAttempt['granted']),
            'lifetime_attempt' => $lifetimeAttempt,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
