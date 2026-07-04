<?php
declare(strict_types=1);

require_once __DIR__ . '/metrics.php';

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
}

function firepay_normalized_status(string $status): string
{
    $value = strtolower(trim($status));
    if ($value === 'paid') return 'APPROVED';
    return 'UNKNOWN';
}

function firepay_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

/**
 * Persiste todo payload Firepay e espelha somente vendas pagas nas tabelas
 * legadas usadas atualmente pelos relatorios.
 */
function firepay_process_webhook(PDO $pdo, array $payload, string $rawPayload, int $inboundWebhookId): array
{
    metrics_ensure_schema($pdo);
    firepay_ensure_schema($pdo);

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
    $matched = hotmart_find_matching_user($pdo, $email, $phoneNorm);
    $matchedUser = is_array($matched['user'] ?? null) ? $matched['user'] : null;
    $receivedAt = date('Y-m-d H:i:s');
    $fingerprint = hash('sha256', $inboundWebhookId . '|' . $transactionId . '|' . $providerStatus . '|' . $rawPayload);

    $pdo->beginTransaction();
    try {
        $event = $pdo->prepare("INSERT INTO firepay_webhook_events
            (inbound_webhook_id,event_fingerprint,external_transaction_id,provider_status,process_status,process_message,payload_json,received_at,processed_at)
            VALUES (:inbound,:fingerprint,:transaction,:provider_status,:process_status,:message,:payload,NOW(),NOW())
            ON DUPLICATE KEY UPDATE processed_at=NOW(),process_message='Evento repetido; transacao mantida idempotente'");
        $event->execute([
            ':inbound'=>$inboundWebhookId, ':fingerprint'=>$fingerprint, ':transaction'=>$transactionId,
            ':provider_status'=>$providerStatus, ':process_status'=>$normalizedStatus === 'APPROVED' ? 'success' : 'ignored',
            ':message'=>$normalizedStatus === 'APPROVED' ? 'Venda Firepay paga processada' : 'Status ainda nao mapeado; payload preservado',
            ':payload'=>$rawPayload,
        ]);

        $sale = $pdo->prepare("INSERT INTO payment_sales
            (provider,external_transaction_id,external_checkout_id,transaction_type,provider_status,normalized_status,currency,
             gross_amount_cents,product_amount_cents,interest_amount_cents,installments,payment_method,payment_gateway,provider_account_id,
             external_product_id,product_name,product_slug,integration_id,integration_delivery_type,classes_text,origin_description,origin_slug,
             buyer_name,buyer_email,buyer_phone,buyer_document,matched_user_id,match_method,checkout_url,order_bumps_json,raw_payload_json,
             first_received_at,last_received_at)
            VALUES ('firepay',:transaction,:checkout,:type,:provider_status,:normalized_status,:currency,:gross,:product_amount,:interest,
             :installments,:payment_method,:gateway,:account,:product_id,:product_name,:product_slug,:integration_id,:delivery_type,:classes,
             :origin_description,:origin_slug,:buyer_name,:buyer_email,:buyer_phone,:buyer_document,:user_id,:match_method,:checkout_url,
             :order_bumps,:payload,NOW(),NOW())
            ON DUPLICATE KEY UPDATE external_checkout_id=VALUES(external_checkout_id),transaction_type=VALUES(transaction_type),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),currency=VALUES(currency),
             gross_amount_cents=VALUES(gross_amount_cents),product_amount_cents=VALUES(product_amount_cents),interest_amount_cents=VALUES(interest_amount_cents),
             installments=VALUES(installments),payment_method=VALUES(payment_method),payment_gateway=VALUES(payment_gateway),
             provider_account_id=VALUES(provider_account_id),external_product_id=VALUES(external_product_id),product_name=VALUES(product_name),
             product_slug=VALUES(product_slug),integration_id=VALUES(integration_id),integration_delivery_type=VALUES(integration_delivery_type),
             classes_text=VALUES(classes_text),origin_description=VALUES(origin_description),origin_slug=VALUES(origin_slug),buyer_name=VALUES(buyer_name),
             buyer_email=VALUES(buyer_email),buyer_phone=VALUES(buyer_phone),buyer_document=VALUES(buyer_document),matched_user_id=VALUES(matched_user_id),
             match_method=VALUES(match_method),checkout_url=VALUES(checkout_url),order_bumps_json=VALUES(order_bumps_json),
             raw_payload_json=VALUES(raw_payload_json),last_received_at=VALUES(last_received_at)");
        $sale->execute([
            ':transaction'=>$transactionId, ':checkout'=>firepay_scalar($payload, 'checkout_id') ?: null,
            ':type'=>firepay_scalar($payload, 'type') ?: null, ':provider_status'=>$providerStatus, ':normalized_status'=>$normalizedStatus,
            ':currency'=>firepay_scalar($payload, 'price_currency') ?: 'BRL', ':gross'=>(int)($payload['price'] ?? 0),
            ':product_amount'=>(int)($payload['product_price'] ?? 0), ':interest'=>(int)($payload['interest_fee'] ?? 0),
            ':installments'=>(int)($payload['installments'] ?? 0) ?: null, ':payment_method'=>firepay_scalar($payload, 'payment_method') ?: null,
            ':gateway'=>firepay_scalar($payload, 'payment_gateway') ?: null, ':account'=>firepay_scalar($payload, 'tenant_id') ?: null,
            ':product_id'=>firepay_scalar($product, 'id') ?: null, ':product_name'=>firepay_scalar($product, 'name') ?: null,
            ':product_slug'=>firepay_scalar($product, 'slug') ?: null, ':integration_id'=>firepay_scalar($product, 'integration_id') ?: null,
            ':delivery_type'=>firepay_scalar($product, 'integration_delivery_type') ?: null, ':classes'=>firepay_scalar($product, 'turmas') ?: null,
            ':origin_description'=>firepay_scalar($origin, 'description') ?: null, ':origin_slug'=>firepay_scalar($origin, 'slug') ?: null,
            ':buyer_name'=>firepay_scalar($client, 'name') ?: null, ':buyer_email'=>$email ?: null, ':buyer_phone'=>$phoneRaw ?: null,
            ':buyer_document'=>firepay_scalar($client, 'document') ?: null, ':user_id'=>$matchedUser['id'] ?? null,
            ':match_method'=>(string)($matched['method'] ?? 'none'), ':checkout_url'=>firepay_scalar($payload, 'link') ?: null,
            ':order_bumps'=>json_encode($orderBumps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':payload'=>$rawPayload,
        ]);

        if ($normalizedStatus === 'APPROVED') {
            $transactionCode = 'firepay:' . $transactionId;
            $gross = ((int)($payload['price'] ?? 0)) / 100;
            $legacySale = hotmart_build_sale_data_from_array([
                'webhook_event'=>'FIREPAY_PAID', 'webhook_event_id'=>$fingerprint, 'transaction_code'=>$transactionCode,
                'status'=>'APPROVED', 'transaction_date'=>$receivedAt, 'payment_confirmed_at'=>$receivedAt,
                'product_code'=>firepay_scalar($product, 'id') ?: null, 'product_name'=>firepay_scalar($product, 'name'),
                'price_code'=>firepay_scalar($payload, 'checkout_id'), 'price_name'=>firepay_scalar($product, 'integration_id'),
                'currency'=>firepay_scalar($payload, 'price_currency') ?: 'BRL', 'gross_revenue'=>$gross,
                'net_revenue'=>$gross, 'producer_net'=>$gross, 'buyer_name'=>firepay_scalar($client, 'name'),
                'buyer_email'=>$email, 'buyer_phone_raw'=>$phoneRaw, 'buyer_phone_norm'=>$phoneNorm, 'raw_payload_json'=>$rawPayload,
            ], $matched);
            hotmart_upsert_sale_live($pdo, $legacySale);
            hotmart_upsert_sale_legacy($pdo, $legacySale);
            $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,
                sale_origin=:origin,sales_channel='firepay' WHERE transaction_code=:transaction")
                ->execute([':payment'=>firepay_scalar($payload, 'payment_method') ?: null,
                    ':installments'=>(int)($payload['installments'] ?? 0) ?: null,
                    ':origin'=>firepay_scalar($origin, 'slug') ?: firepay_scalar($origin, 'description') ?: null,
                    ':transaction'=>$transactionCode]);
        }

        $pdo->commit();
        return ['transaction_id'=>$transactionId, 'normalized_status'=>$normalizedStatus,
            'matched_user_id'=>(int)($matchedUser['id'] ?? 0), 'match_method'=>(string)($matched['method'] ?? 'none')];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
