<?php
declare(strict_types=1);

require_once __DIR__ . '/firepay.php';
require_once __DIR__ . '/course_access.php';

function dom_ensure_schema(PDO $pdo): void
{
    metrics_ensure_schema($pdo);
    firepay_ensure_schema($pdo);
    firepay_ensure_hotmart_compat_schema($pdo);
    course_access_ensure_schema($pdo);

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
}

function dom_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
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

    foreach ([
        $data['cod_external'] ?? '',
        $metadata['offer_code'] ?? '',
        $metadata['oferta'] ?? '',
        $metadata['offer'] ?? '',
        $metadata['produto'] ?? '',
        $relations['id_link_payment'] ?? '',
    ] as $value) {
        foreach (course_access_offer_codes((string)$value) as $code) $candidates[] = $code;
    }

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        foreach ([$item['reference'] ?? '', $item['description'] ?? ''] as $value) {
            foreach (course_access_offer_codes((string)$value) as $code) $candidates[] = $code;
        }
    }

    return array_values(array_unique(array_filter($candidates, static fn($v) => trim((string)$v) !== '')));
}

function dom_try_grant_lifetime(PDO $pdo, array $data, string $transactionCode, string $status, string $email, string $phoneRaw, ?array $matchedUser): array
{
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
    $amountCents = (int)($data['amount'] ?? 0);
    $liquidCents = (int)($data['liquid_amount'] ?? 0);
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
             gross_amount_cents,product_amount_cents,interest_amount_cents,installments,payment_method,payment_gateway,provider_account_id,
             external_product_id,product_name,product_slug,integration_id,integration_delivery_type,classes_text,origin_description,origin_slug,
             buyer_name,buyer_email,buyer_phone,buyer_document,matched_user_id,match_method,checkout_url,order_bumps_json,raw_payload_json,
             first_received_at,last_received_at)
            VALUES ('dom',:transaction,:checkout,:type,:provider_status,:normalized_status,:currency,:gross,:product_amount,0,
             :installments,:payment_method,'dom',NULL,:product_id,:product_name,NULL,:integration_id,NULL,NULL,:origin,NULL,
             :buyer_name,:buyer_email,:buyer_phone,:buyer_document,:user_id,:match_method,NULL,NULL,:payload,:received_at,:received_at)
            ON DUPLICATE KEY UPDATE external_checkout_id=VALUES(external_checkout_id),transaction_type=VALUES(transaction_type),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),currency=VALUES(currency),
             gross_amount_cents=VALUES(gross_amount_cents),product_amount_cents=VALUES(product_amount_cents),
             installments=VALUES(installments),payment_method=VALUES(payment_method),external_product_id=VALUES(external_product_id),
             product_name=VALUES(product_name),integration_id=VALUES(integration_id),origin_description=VALUES(origin_description),
             buyer_name=VALUES(buyer_name),buyer_email=VALUES(buyer_email),buyer_phone=VALUES(buyer_phone),buyer_document=VALUES(buyer_document),
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
            ':product_amount' => (int)($firstItem['price'] ?? $amountCents),
            ':installments' => (int)(dom_scalar($data, 'installments') ?: 0) ?: null,
            ':payment_method' => dom_scalar($data, 'payment_method') ?: null,
            ':product_id' => $productRef ?: null,
            ':product_name' => $productName ?: null,
            ':integration_id' => (string)($metadata['offer_code'] ?? $metadata['oferta'] ?? '') ?: null,
            ':origin' => (string)($query['utm_source'] ?? '') ?: null,
            ':buyer_name' => dom_scalar($customer, 'name') ?: null,
            ':buyer_email' => $email ?: null,
            ':buyer_phone' => $phoneRaw ?: null,
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
                'net_revenue' => $liquidCents > 0 ? $liquidCents / 100 : $amountCents / 100,
                'producer_net' => $liquidCents > 0 ? $liquidCents / 100 : $amountCents / 100,
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
