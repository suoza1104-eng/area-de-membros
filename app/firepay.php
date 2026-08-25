<?php
declare(strict_types=1);

require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/course_access.php';
require_once __DIR__ . '/payment_events.php';
require_once __DIR__ . '/payment_amounts.php';
require_once __DIR__ . '/payment_reconciliation.php';

function firepay_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_sales (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(30) NOT NULL,
        external_transaction_id VARCHAR(100) NOT NULL,
        external_checkout_id VARCHAR(100) NULL,
        transaction_type VARCHAR(40) NULL,
        provider_status VARCHAR(80) NULL,
        normalized_status VARCHAR(30) NOT NULL DEFAULT 'UNKNOWN',
        currency VARCHAR(10) NULL,
        gross_amount_cents BIGINT NOT NULL DEFAULT 0,
        product_amount_cents BIGINT NOT NULL DEFAULT 0,
        interest_amount_cents BIGINT NOT NULL DEFAULT 0,
        installments INT UNSIGNED NULL,
        payment_method VARCHAR(80) NULL,
        payment_gateway VARCHAR(120) NULL,
        provider_account_id VARCHAR(150) NULL,
        external_product_id VARCHAR(100) NULL,
        product_name VARCHAR(255) NULL,
        product_slug VARCHAR(255) NULL,
        integration_id VARCHAR(500) NULL,
        integration_delivery_type VARCHAR(80) NULL,
        classes_text VARCHAR(500) NULL,
        origin_description VARCHAR(255) NULL,
        origin_slug VARCHAR(255) NULL,
        buyer_name VARCHAR(255) NULL,
        buyer_email VARCHAR(255) NULL,
        buyer_phone VARCHAR(60) NULL,
        buyer_document VARCHAR(60) NULL,
        matched_user_id BIGINT UNSIGNED NULL,
        match_method VARCHAR(30) NOT NULL DEFAULT 'none',
        checkout_url VARCHAR(1000) NULL,
        order_bumps_json LONGTEXT NULL,
        raw_payload_json LONGTEXT NOT NULL,
        first_received_at DATETIME NOT NULL,
        last_received_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_payment_provider_transaction (provider, external_transaction_id),
        KEY idx_payment_status (normalized_status),
        KEY idx_payment_buyer_email (buyer_email),
        KEY idx_payment_user (matched_user_id),
        KEY idx_payment_received (last_received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        "ALTER TABLE payment_sales ADD COLUMN net_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER gross_amount_cents",
        "ALTER TABLE payment_sales ADD COLUMN fee_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER net_amount_cents",
    ] as $migration) {
        try { $pdo->exec($migration); } catch (Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS firepay_webhook_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        inbound_webhook_id INT NULL,
        event_fingerprint CHAR(64) NOT NULL,
        external_transaction_id VARCHAR(100) NULL,
        provider_status VARCHAR(80) NULL,
        process_status ENUM('success','ignored','error') NOT NULL,
        process_message TEXT NULL,
        payload_json LONGTEXT NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_firepay_fingerprint (event_fingerprint),
        KEY idx_firepay_transaction (external_transaction_id),
        KEY idx_firepay_status (process_status),
        KEY idx_firepay_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    payment_reconciliation_ensure_schema($pdo);
}

function firepay_normalized_status(string $status): string
{
    $value = strtolower(trim($status));
    if ($value === 'paid') return 'APPROVED';
    if (in_array($value, ['waiting', 'waiting gateway', 'waiting_gateway', 'overdue', 'expired'], true)) return 'PENDING';
    if (in_array($value, ['failed', 'canceled', 'cancelled'], true)) return 'CANCELED';
    if ($value === 'chargeback') return 'CHARGEBACK';
    if ($value === 'refunded') return 'REFUNDED';
    if ($value === 'abandoned') return 'ABANDONED';
    return 'UNKNOWN';
}

function firepay_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function firepay_add_offer_candidate(array &$candidates, string $value, string $prefix = ''): void
{
    $value = trim($value);
    if ($value === '') return;
    foreach (course_access_offer_codes($value) as $part) {
        $candidates[] = $part;
        if ($prefix !== '') $candidates[] = $prefix . ':' . $part;
    }
}

function firepay_offer_candidates(array $payload): array
{
    $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
    $orderBumps = is_array($payload['order_bumps'] ?? null) ? $payload['order_bumps'] : [];
    if (!$orderBumps && is_array($payload['order_bump'] ?? null)) $orderBumps = [$payload['order_bump']];

    $candidates = [];
    foreach ($orderBumps as $bump) {
        if (!is_array($bump)) continue;
        firepay_add_offer_candidate($candidates, firepay_scalar($bump, 'product_id'), 'bump');
        firepay_add_offer_candidate($candidates, firepay_scalar($bump, 'product_id'), 'order_bump');
    }
    firepay_add_offer_candidate($candidates, firepay_scalar($payload, 'checkout_id'), 'checkout');
    firepay_add_offer_candidate($candidates, firepay_scalar($product, 'id'), 'product');
    firepay_add_offer_candidate($candidates, firepay_scalar($product, 'integration_id'), 'integration');
    firepay_add_offer_candidate($candidates, firepay_scalar($product, 'turmas'), 'turma');

    return array_values(array_unique(array_filter($candidates, static fn($v) => trim((string)$v) !== '')));
}

function firepay_ensure_hotmart_compat_schema(PDO $pdo): void
{
    foreach ([
        "ALTER TABLE hotmart_sales_live ADD COLUMN payment_type VARCHAR(40) NULL AFTER price_name",
        "ALTER TABLE hotmart_sales_live ADD COLUMN installments_number INT UNSIGNED NULL AFTER payment_type",
        "ALTER TABLE hotmart_sales_live ADD COLUMN sale_origin VARCHAR(100) NULL AFTER installments_number",
        "ALTER TABLE hotmart_sales_live ADD COLUMN sales_channel VARCHAR(40) NOT NULL DEFAULT 'hotmart' AFTER sale_origin",
        "ALTER TABLE hotmart_sales_live ADD KEY idx_hotmart_live_payment (payment_type)",
    ] as $migration) {
        try { $pdo->exec($migration); } catch (Throwable $e) {}
    }
}

function firepay_find_matching_user(PDO $pdo, string $emailNorm, string $phoneNorm): array
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

function firepay_try_grant_lifetime(PDO $pdo, array $payload, string $transactionCode, string $status, string $email, string $phoneRaw, ?array $matchedUser): array
{
    foreach (firepay_offer_candidates($payload) as $offerCode) {
        $attempt = course_access_try_grant_lifetime_purchase($pdo, [
            'user_id' => isset($matchedUser['id']) ? (int)$matchedUser['id'] : null,
            'offer_code' => $offerCode,
            'transaction_code' => $transactionCode,
            'status' => $status,
            'event' => 'FIREPAY_PAID',
            'email' => $email,
            'phone' => $phoneRaw,
            'payload' => $payload,
            'source' => 'firepay',
        ]);
        if (!empty($attempt['granted'])) return $attempt;
    }
    return ['granted' => false, 'reason' => 'no_firepay_offer_candidate_matched', 'candidates' => firepay_offer_candidates($payload)];
}

/**
 * Persiste todo payload Firepay e espelha somente vendas pagas nas tabelas
 * legadas usadas atualmente pelos relatorios.
 */
function firepay_process_webhook(PDO $pdo, array $payload, string $rawPayload, int $inboundWebhookId): array
{
    metrics_ensure_schema($pdo);
    firepay_ensure_hotmart_compat_schema($pdo);
    firepay_ensure_schema($pdo);
    course_access_ensure_schema($pdo);

    $transactionId = firepay_scalar($payload, 'id');
    if ($transactionId === '') throw new InvalidArgumentException('Firepay: id da transacao ausente.');

    $providerStatus = firepay_scalar($payload, 'status');
    if ($providerStatus === '') throw new InvalidArgumentException('Firepay: status da transacao ausente.');

    $client = is_array($payload['client'] ?? null) ? $payload['client'] : [];
    $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
    $origin = is_array($payload['origin'] ?? null) ? $payload['origin'] : [];
    $orderBumps = is_array($payload['order_bumps'] ?? null) ? $payload['order_bumps'] : [];
    $normalizedStatus = firepay_normalized_status($providerStatus);
    $email = normalize_email_value($client['email'] ?? '');
    $phoneRaw = firepay_scalar($client, 'phone');
    $phoneNorm = normalize_phone_value($phoneRaw);
    $matched = firepay_find_matching_user($pdo, $email, $phoneNorm);
    $matchedUser = is_array($matched['user'] ?? null) ? $matched['user'] : null;
    $receivedAt = date('Y-m-d H:i:s');
    $fingerprint = hash('sha256', $inboundWebhookId . '|' . $transactionId . '|' . $providerStatus . '|' . $rawPayload);
    $paymentMethod = firepay_scalar($payload, 'payment_method');
    $grossCents = payment_amount_cents($payload['price'] ?? 0);
    $productCents = payment_amount_cents($payload['product_price'] ?? $grossCents);
    $interestCents = payment_amount_cents($payload['interest_fee'] ?? 0);
    $feeCents = payment_amount_fee_cents([$payload], $grossCents, 'firepay', $paymentMethod);
    $netCents = payment_amount_net_cents([$payload], $grossCents, $feeCents);
    // So visibilidade (Firepay ja fica fora dos KPIs do dashboard) — mesma logica do Pagar.me.
    $feeIsEstimated = !payment_amount_fee_found_in_payload([$payload]);

    $pdo->beginTransaction();
    try {
        $event = $pdo->prepare("INSERT INTO firepay_webhook_events
            (inbound_webhook_id,event_fingerprint,external_transaction_id,provider_status,process_status,process_message,payload_json,received_at,processed_at)
            VALUES (:inbound,:fingerprint,:transaction,:provider_status,:process_status,:message,:payload,NOW(),NOW())
            ON DUPLICATE KEY UPDATE processed_at=NOW(),process_message='Evento repetido; transacao mantida idempotente'");
        $event->execute([
            ':inbound'=>$inboundWebhookId, ':fingerprint'=>$fingerprint, ':transaction'=>$transactionId,
            ':provider_status'=>$providerStatus, ':process_status'=>$normalizedStatus !== 'UNKNOWN' ? 'success' : 'ignored',
            ':message'=>$normalizedStatus === 'APPROVED' ? 'Venda Firepay paga processada' : ($normalizedStatus !== 'UNKNOWN' ? 'Status Firepay mapeado sem liberar venda aprovada' : 'Status ainda nao mapeado; payload preservado'),
            ':payload'=>$rawPayload,
        ]);

        $sale = $pdo->prepare("INSERT INTO payment_sales
            (provider,external_transaction_id,external_checkout_id,transaction_type,provider_status,normalized_status,currency,
             gross_amount_cents,net_amount_cents,fee_amount_cents,fee_is_estimated,product_amount_cents,interest_amount_cents,installments,payment_method,payment_gateway,provider_account_id,
             external_product_id,product_name,product_slug,integration_id,integration_delivery_type,classes_text,origin_description,origin_slug,
             buyer_name,buyer_email,buyer_phone,buyer_phone_norm,buyer_document,matched_user_id,match_method,checkout_url,order_bumps_json,raw_payload_json,
             first_received_at,last_received_at)
            VALUES ('firepay',:transaction,:checkout,:type,:provider_status,:normalized_status,:currency,:gross,:net,:fee,:fee_is_estimated,:product_amount,:interest,
             :installments,:payment_method,:gateway,:account,:product_id,:product_name,:product_slug,:integration_id,:delivery_type,:classes,
             :origin_description,:origin_slug,:buyer_name,:buyer_email,:buyer_phone,:phone_norm,:buyer_document,:user_id,:match_method,:checkout_url,
             :order_bumps,:payload,NOW(),NOW())
            ON DUPLICATE KEY UPDATE external_checkout_id=VALUES(external_checkout_id),transaction_type=VALUES(transaction_type),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),currency=VALUES(currency),
             gross_amount_cents=VALUES(gross_amount_cents),
             net_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(net_amount_cents) ELSE net_amount_cents END,
             fee_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_amount_cents) ELSE fee_amount_cents END,
             fee_is_estimated=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_is_estimated) ELSE fee_is_estimated END,
             product_amount_cents=VALUES(product_amount_cents),interest_amount_cents=VALUES(interest_amount_cents),
             installments=VALUES(installments),payment_method=VALUES(payment_method),payment_gateway=VALUES(payment_gateway),
             provider_account_id=VALUES(provider_account_id),external_product_id=VALUES(external_product_id),product_name=VALUES(product_name),
             product_slug=VALUES(product_slug),integration_id=VALUES(integration_id),integration_delivery_type=VALUES(integration_delivery_type),
             classes_text=VALUES(classes_text),origin_description=VALUES(origin_description),origin_slug=VALUES(origin_slug),buyer_name=VALUES(buyer_name),
             buyer_email=VALUES(buyer_email),buyer_phone=VALUES(buyer_phone),buyer_phone_norm=VALUES(buyer_phone_norm),buyer_document=VALUES(buyer_document),matched_user_id=VALUES(matched_user_id),
             match_method=VALUES(match_method),checkout_url=VALUES(checkout_url),order_bumps_json=VALUES(order_bumps_json),
             raw_payload_json=VALUES(raw_payload_json),last_received_at=VALUES(last_received_at)");
        $sale->execute([
            ':transaction'=>$transactionId, ':checkout'=>firepay_scalar($payload, 'checkout_id') ?: null,
            ':type'=>firepay_scalar($payload, 'type') ?: null, ':provider_status'=>$providerStatus, ':normalized_status'=>$normalizedStatus,
            ':currency'=>firepay_scalar($payload, 'price_currency') ?: 'BRL', ':gross'=>$grossCents,
            ':net'=>$netCents, ':fee'=>$feeCents, ':fee_is_estimated'=>$feeIsEstimated ? 1 : 0, ':product_amount'=>$productCents, ':interest'=>$interestCents,
            ':installments'=>(int)($payload['installments'] ?? 0) ?: null, ':payment_method'=>$paymentMethod ?: null,
            ':gateway'=>firepay_scalar($payload, 'payment_gateway') ?: null, ':account'=>firepay_scalar($payload, 'tenant_id') ?: null,
            ':product_id'=>firepay_scalar($product, 'id') ?: null, ':product_name'=>firepay_scalar($product, 'name') ?: null,
            ':product_slug'=>firepay_scalar($product, 'slug') ?: null, ':integration_id'=>firepay_scalar($product, 'integration_id') ?: null,
            ':delivery_type'=>firepay_scalar($product, 'integration_delivery_type') ?: null, ':classes'=>firepay_scalar($product, 'turmas') ?: null,
            ':origin_description'=>firepay_scalar($origin, 'description') ?: null, ':origin_slug'=>firepay_scalar($origin, 'slug') ?: null,
            ':buyer_name'=>firepay_scalar($client, 'name') ?: null, ':buyer_email'=>$email ?: null, ':buyer_phone'=>$phoneRaw ?: null,
            ':phone_norm'=>$phoneNorm ?: null,
            ':buyer_document'=>firepay_scalar($client, 'document') ?: null, ':user_id'=>$matchedUser['id'] ?? null,
            ':match_method'=>(string)($matched['method'] ?? 'none'), ':checkout_url'=>firepay_scalar($payload, 'link') ?: null,
            ':order_bumps'=>json_encode($orderBumps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':payload'=>$rawPayload,
        ]);
        $paymentSaleId = (int)$pdo->lastInsertId();

        $lifetimeAttempt = ['granted' => false, 'reason' => 'payment_not_approved'];
        if ($normalizedStatus === 'APPROVED') {
            $transactionCode = 'firepay:' . $transactionId;
            $gross = $grossCents / 100;
            $net = $netCents / 100;
            $legacySale = hotmart_build_sale_data_from_array([
                'webhook_event'=>'FIREPAY_PAID', 'webhook_event_id'=>$fingerprint, 'transaction_code'=>$transactionCode,
                'status'=>'APPROVED', 'transaction_date'=>$receivedAt, 'payment_confirmed_at'=>$receivedAt,
                'product_code'=>firepay_scalar($product, 'id') ?: null, 'product_name'=>firepay_scalar($product, 'name'),
                'price_code'=>firepay_scalar($payload, 'checkout_id'), 'price_name'=>firepay_scalar($product, 'integration_id'),
                'currency'=>firepay_scalar($payload, 'price_currency') ?: 'BRL', 'gross_revenue'=>$gross,
                'net_revenue'=>$net, 'producer_net'=>$net, 'buyer_name'=>firepay_scalar($client, 'name'),
                'buyer_email'=>$email, 'buyer_phone_raw'=>$phoneRaw, 'buyer_phone_norm'=>$phoneNorm, 'raw_payload_json'=>$rawPayload,
            ], $matched);
            hotmart_upsert_sale_live($pdo, $legacySale);
            hotmart_upsert_sale_legacy($pdo, $legacySale);
            $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,
                sale_origin=:origin,sales_channel='firepay' WHERE transaction_code=:transaction")
                ->execute([':payment'=>$paymentMethod ?: null,
                    ':installments'=>(int)($payload['installments'] ?? 0) ?: null,
                    ':origin'=>firepay_scalar($origin, 'slug') ?: firepay_scalar($origin, 'description') ?: null,
                    ':transaction'=>$transactionCode]);
            $lifetimeAttempt = firepay_try_grant_lifetime($pdo, $payload, $transactionCode, $providerStatus, $email, $phoneRaw, $matchedUser);
        }

        if ($pdo->inTransaction()) $pdo->commit();

        // Tenta achar a venda "gemea" ja capturada via DOM/Pagar.me direto, so
        // para visibilidade/anti-duplicacao futura — nunca deve derrubar o
        // webhook se falhar (rede, schema, etc).
        if ($paymentSaleId > 0) {
            try {
                payment_reconciliation_link_firepay_twin($pdo, $paymentSaleId);
            } catch (Throwable $e) {
                if (function_exists('app_log')) {
                    app_log('Firepay: falha ao tentar localizar venda gemea DOM/Pagar.me', [
                        'payment_sale_id' => $paymentSaleId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $paymentEvent = payment_event_register($pdo, [
            'provider'=>'firepay',
            'normalized_status'=>$normalizedStatus,
            'transaction_code'=>'firepay:' . $transactionId,
            'provider_transaction_id'=>$transactionId,
            'provider_status'=>$providerStatus,
            'payment_method'=>$paymentMethod ?: null,
            'currency'=>firepay_scalar($payload, 'price_currency') ?: 'BRL',
            'gross_amount_cents'=>$grossCents,
            'net_amount_cents'=>$netCents,
            'fee_amount_cents'=>$feeCents,
            'installments'=>(int)($payload['installments'] ?? 0) ?: null,
            'product_name'=>firepay_scalar($product, 'name'),
            'product_code'=>firepay_scalar($product, 'id'),
            'checkout_id'=>firepay_scalar($payload, 'checkout_id'),
            'checkout_url'=>firepay_scalar($payload, 'link'),
            'pix_qrcode'=>payment_event_first_value($payload, ['qr_code','pix_qr_code','pix_code','qrcode','copy_paste']),
            'pix_qrcode_url'=>payment_event_first_value($payload, ['qr_code_url','pix_qr_code_url','pix_url','qrcode_url']),
            'pix_expires_at'=>payment_event_first_value($payload, ['expires_at','expiration_date','pix_expires_at']),
            'boleto_url'=>payment_event_first_value($payload, ['boleto_url','pdf','url']),
            'boleto_line'=>payment_event_first_value($payload, ['line','digitable_line','barcode']),
            'buyer_name'=>firepay_scalar($client, 'name'),
            'buyer_email'=>$email,
            'buyer_phone'=>$phoneRaw,
            'buyer_document'=>firepay_scalar($client, 'document'),
            'user_id'=>(int)($matchedUser['id'] ?? 0),
            'raw_payload'=>$payload,
            'metadata'=>[
                'source'=>'firepay_webhook',
                'inbound_webhook_id'=>$inboundWebhookId,
                'match_method'=>(string)($matched['method'] ?? 'none'),
                'origin'=>firepay_scalar($origin, 'slug') ?: firepay_scalar($origin, 'description'),
                'lifetime_attempt'=>$lifetimeAttempt,
            ],
            'occurred_at'=>$receivedAt,
        ]);
        return ['transaction_id'=>$transactionId, 'normalized_status'=>$normalizedStatus,
            'matched_user_id'=>(int)($matchedUser['id'] ?? 0), 'match_method'=>(string)($matched['method'] ?? 'none'),
            'lifetime_granted'=>!empty($lifetimeAttempt['granted']), 'lifetime_attempt'=>$lifetimeAttempt,
            'payment_event'=>$paymentEvent];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
