<?php
declare(strict_types=1);

require_once __DIR__ . '/firepay.php';
require_once __DIR__ . '/course_access.php';
require_once __DIR__ . '/payment_events.php';
require_once __DIR__ . '/payment_amounts.php';

function pagarme_ensure_schema(PDO $pdo): void
{
    metrics_ensure_schema($pdo);
    firepay_ensure_schema($pdo);
    firepay_ensure_hotmart_compat_schema($pdo);
    course_access_ensure_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS pagarme_webhook_events (
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
        UNIQUE KEY uq_pagarme_fingerprint (event_fingerprint),
        KEY idx_pagarme_transaction (external_transaction_id),
        KEY idx_pagarme_status (process_status),
        KEY idx_pagarme_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pagarme_scalar(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function pagarme_cents($value): int
{
    return payment_amount_cents($value);
}

function pagarme_find_amount_by_keys($value, array $keys): int
{
    return payment_amount_find_by_keys($value, $keys);
}

function pagarme_fee_cents(array $data, array $charge, array $lastTransaction, int $grossCents, string $paymentMethod): int
{
    return payment_amount_fee_cents([$charge, $lastTransaction, $data], $grossCents, 'pagarme', $paymentMethod, '0.98');
}

function pagarme_net_cents(array $data, array $charge, array $lastTransaction, int $grossCents, int $feeCents): int
{
    return payment_amount_net_cents([$charge, $lastTransaction, $data], $grossCents, $feeCents);
}

function pagarme_normalized_status(string $event, string $status): string
{
    $event = strtolower(trim($event));
    $status = strtolower(trim($status));
    if (in_array($event, ['order.paid','charge.paid','invoice.paid','checkout.closed'], true) || in_array($status, ['paid','captured'], true)) return 'APPROVED';
    if (in_array($event, ['charge.pending','charge.processing','order.created','checkout.created','invoice.created'], true) || in_array($status, ['pending','processing'], true)) return 'PENDING';
    if (in_array($event, ['charge.refunded'], true) || $status === 'refunded') return 'REFUNDED';
    if (in_array($event, ['charge.chargedback','chargeback.received'], true) || in_array($status, ['chargedback','chargeback'], true)) return 'CHARGEBACK';
    if (str_contains($event, 'payment_failed') || str_contains($event, 'canceled') || in_array($status, ['failed','canceled','cancelled'], true)) return 'CANCELED';
    return 'UNKNOWN';
}

function pagarme_phone_from_customer(array $customer): string
{
    $phones = is_array($customer['phones'] ?? null) ? $customer['phones'] : [];
    foreach (['mobile_phone','home_phone'] as $key) {
        if (!is_array($phones[$key] ?? null)) continue;
        $p = $phones[$key];
        $raw = (string)($p['country_code'] ?? '') . (string)($p['area_code'] ?? '') . (string)($p['number'] ?? '');
        if ($raw !== '') return $raw;
    }
    foreach (['phone','mobile_phone','home_phone'] as $key) {
        if (isset($customer[$key]) && is_scalar($customer[$key])) return (string)$customer[$key];
    }
    return '';
}

function pagarme_event_data(array $payload): array
{
    return is_array($payload['data'] ?? null) ? $payload['data'] : [];
}

function pagarme_first_charge(array $data): array
{
    if (is_array($data['charges'] ?? null) && is_array($data['charges'][0] ?? null)) return $data['charges'][0];
    return str_starts_with((string)($data['id'] ?? ''), 'ch_') ? $data : [];
}

function pagarme_customer(array $data, array $charge): array
{
    if (is_array($charge['customer'] ?? null)) return $charge['customer'];
    return is_array($data['customer'] ?? null) ? $data['customer'] : [];
}

function pagarme_datetime(array $data, array $charge): string
{
    foreach ([$charge['paid_at'] ?? '', $charge['updated_at'] ?? '', $data['closed_at'] ?? '', $data['updated_at'] ?? '', $data['created_at'] ?? ''] as $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        try { return (new DateTimeImmutable($value))->format('Y-m-d H:i:s'); } catch (Throwable $e) {}
    }
    return date('Y-m-d H:i:s');
}

function pagarme_find_matching_user(PDO $pdo, string $emailNorm, string $phoneNorm): array
{
    if ($emailNorm !== '') {
        $stmt = $pdo->prepare("SELECT id,nome,email,telefone,utm_source,utm_medium,utm_campaign,utm_term,utm_content FROM users WHERE LOWER(TRIM(email))=:email ORDER BY id DESC LIMIT 1");
        $stmt->execute([':email' => $emailNorm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['user' => $row, 'method' => 'email'];
    }
    return hotmart_find_matching_user($pdo, '', $phoneNorm);
}

function pagarme_offer_candidates(array $data, array $charge): array
{
    $candidates = [];
    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    $add = static function ($value) use (&$candidates): void {
        foreach (course_access_offer_codes((string)$value) as $code) $candidates[] = $code;
    };
    $addWithPrefix = static function ($value, string $prefix) use ($add): void {
        $value = trim((string)$value);
        if ($value === '') return;
        $add($value);
        $add($prefix . ':' . $value);
    };

    foreach ([$data['code'] ?? '', $charge['code'] ?? '', $metadata['offer_code'] ?? '', $metadata['oferta'] ?? '', $metadata['offer'] ?? '', $metadata['produto'] ?? ''] as $value) {
        $add($value);
    }
    foreach ([$metadata['checkout_id'] ?? '', $metadata['checkout'] ?? ''] as $value) {
        $addWithPrefix($value, 'checkout');
    }
    foreach ([$metadata['product_id'] ?? '', $metadata['product'] ?? ''] as $value) {
        $addWithPrefix($value, 'product');
    }
    foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $item) {
        if (!is_array($item)) continue;
        foreach ([$item['code'] ?? '', $item['id'] ?? '', $item['description'] ?? ''] as $value) {
            $add($value);
        }
    }
    return array_values(array_unique(array_filter($candidates, static fn($v) => trim((string)$v) !== '')));
}

function pagarme_try_grant_lifetime(PDO $pdo, array $data, array $charge, string $transactionCode, string $email, string $phoneRaw, ?array $matchedUser): array
{
    foreach (pagarme_offer_candidates($data, $charge) as $offerCode) {
        $attempt = course_access_try_grant_lifetime_purchase($pdo, [
            'user_id' => isset($matchedUser['id']) ? (int)$matchedUser['id'] : null,
            'offer_code' => $offerCode,
            'transaction_code' => $transactionCode,
            'status' => 'paid',
            'event' => 'PAGARME_PAID',
            'email' => $email,
            'phone' => $phoneRaw,
            'payload' => $data,
            'source' => 'pagarme',
        ]);
        if (!empty($attempt['granted'])) return $attempt;
    }
    return ['granted' => false, 'reason' => 'no_pagarme_offer_candidate_matched', 'candidates' => pagarme_offer_candidates($data, $charge)];
}

function pagarme_validate_secret(array $server, array $query): bool
{
    $expected = trim((string)get_setting('pagarme_webhook_secret', ''));
    if ($expected === '') return true;
    $received = trim((string)($query['secret'] ?? $query['token'] ?? ''));
    if ($received === '') {
        $received = trim((string)($server['HTTP_X_PAGARME_WEBHOOK_SECRET'] ?? $server['HTTP_X_WEBHOOK_SECRET'] ?? ''));
    }
    return $received !== '' && hash_equals($expected, $received);
}

function pagarme_process_webhook(PDO $pdo, array $payload, string $rawPayload, array $server = [], array $query = []): array
{
    pagarme_ensure_schema($pdo);

    $event = trim((string)($payload['type'] ?? $payload['event'] ?? ''));
    $data = pagarme_event_data($payload);
    if ($event === '') throw new InvalidArgumentException('Pagar.me: tipo do evento ausente.');
    if (!$data) throw new InvalidArgumentException('Pagar.me: data ausente ou invalido.');
    if (!pagarme_validate_secret($server, $query)) throw new RuntimeException('Pagar.me: segredo do webhook invalido.');

    $charge = pagarme_first_charge($data);
    $customer = pagarme_customer($data, $charge);
    $transactionId = pagarme_scalar($charge, 'id') ?: pagarme_scalar($data, 'id');
    if ($transactionId === '') throw new InvalidArgumentException('Pagar.me: id da transacao ausente.');
    $providerStatus = pagarme_scalar($charge, 'status') ?: pagarme_scalar($data, 'status');
    $normalizedStatus = pagarme_normalized_status($event, $providerStatus);
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $firstItem = is_array($items[0] ?? null) ? $items[0] : [];
    $lastTransaction = is_array($charge['last_transaction'] ?? null) ? $charge['last_transaction'] : [];
    $email = normalize_email_value($customer['email'] ?? '');
    $phoneRaw = pagarme_phone_from_customer($customer);
    $phoneNorm = normalize_phone_value($phoneRaw);
    $matched = pagarme_find_matching_user($pdo, $email, $phoneNorm);
    $matchedUser = is_array($matched['user'] ?? null) ? $matched['user'] : null;
    $transactionCode = 'pagarme:' . $transactionId;
    $receivedAt = pagarme_datetime($data, $charge);
    $amountCents = payment_amount_cents($charge['amount'] ?? $data['amount'] ?? 0);
    $paymentMethod = pagarme_scalar($charge, 'payment_method') ?: pagarme_scalar($lastTransaction, 'transaction_type') ?: null;
    $feeCents = pagarme_fee_cents($data, $charge, $lastTransaction, $amountCents, (string)$paymentMethod);
    $netCents = pagarme_net_cents($data, $charge, $lastTransaction, $amountCents, $feeCents);
    // A Pagar.me nao tem sincronizacao por API (so webhook), entao nao ha fonte
    // autoritativa alternativa para consultar — quando a taxa nao vem explicita
    // no payload, o fallback percentual fixo (pagarme_fee_cents) e a unica opcao
    // hoje, e fica marcado como estimado para nao ser confundido com dado real.
    $feeIsEstimated = !payment_amount_fee_found_in_payload([$charge, $lastTransaction, $data]);
    $productName = pagarme_scalar($firstItem, 'description');
    if ($productName === '') {
        // Eventos do tipo "charge.*" nao trazem os itens do pedido (isso so
        // vem em "order.*"). Se um charge.* chegar antes do order.* para a
        // mesma transacao, usa o nome de produto ja conhecido para nao perder
        // essa informacao no registro da venda nem no disparo de automacoes.
        $knownProductStmt = $pdo->prepare("SELECT product_name FROM payment_sales WHERE provider='pagarme' AND external_transaction_id=:t AND product_name IS NOT NULL AND product_name<>'' LIMIT 1");
        $knownProductStmt->execute([':t' => 'pagarme:' . $transactionId]);
        $productName = (string)($knownProductStmt->fetchColumn() ?: '');
    }
    $fingerprint = hash('sha256', $event . '|' . $transactionId . '|' . $providerStatus . '|' . $rawPayload);
    $secretConfigured = trim((string)get_setting('pagarme_webhook_secret', '')) !== '';
    $secretValid = $secretConfigured ? pagarme_validate_secret($server, $query) : false;

    $pdo->beginTransaction();
    try {
        $log = $pdo->prepare("INSERT INTO pagarme_webhook_events
            (event_fingerprint,external_transaction_id,event_name,provider_status,signature_valid,process_status,process_message,payload_json,received_at,processed_at)
            VALUES (:fingerprint,:transaction,:event,:provider_status,:signature_valid,:process_status,:message,:payload,NOW(),NOW())
            ON DUPLICATE KEY UPDATE processed_at=NOW(),process_message='Evento repetido; transacao mantida idempotente'");
        $log->execute([
            ':fingerprint'=>$fingerprint, ':transaction'=>$transactionId, ':event'=>$event,
            ':provider_status'=>$providerStatus, ':signature_valid'=>$secretValid ? 1 : 0,
            ':process_status'=>$normalizedStatus !== 'UNKNOWN' ? 'success' : 'ignored',
            ':message'=>$normalizedStatus === 'UNKNOWN' ? 'Status Pagar.me ainda nao mapeado.' : null,
            ':payload'=>$rawPayload,
        ]);

        $sale = $pdo->prepare("INSERT INTO payment_sales
            (provider,external_transaction_id,external_checkout_id,transaction_type,provider_status,normalized_status,currency,
             gross_amount_cents,net_amount_cents,fee_amount_cents,fee_is_estimated,product_amount_cents,interest_amount_cents,installments,payment_method,payment_gateway,provider_account_id,
             external_product_id,product_name,product_slug,integration_id,integration_delivery_type,classes_text,origin_description,origin_slug,
             buyer_name,buyer_email,buyer_phone,buyer_phone_norm,buyer_document,matched_user_id,match_method,checkout_url,order_bumps_json,raw_payload_json,
             first_received_at,last_received_at)
            VALUES ('pagarme',:transaction,:checkout,:type,:provider_status,:normalized_status,:currency,:gross,:net,:fee,:fee_is_estimated,:product_amount,0,
             :installments,:payment_method,'pagarme',:account,:product_id,:product_name,NULL,:integration_id,NULL,NULL,:origin,NULL,
             :buyer_name,:buyer_email,:buyer_phone,:phone_norm,:buyer_document,:user_id,:match_method,NULL,NULL,:payload,:received_at,:received_at)
            ON DUPLICATE KEY UPDATE external_checkout_id=VALUES(external_checkout_id),transaction_type=VALUES(transaction_type),
             provider_status=VALUES(provider_status),normalized_status=VALUES(normalized_status),currency=VALUES(currency),
             gross_amount_cents=VALUES(gross_amount_cents),
             net_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(net_amount_cents) ELSE net_amount_cents END,
             fee_amount_cents=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_amount_cents) ELSE fee_amount_cents END,
             fee_is_estimated=CASE WHEN VALUES(fee_is_estimated)=0 OR fee_is_estimated=1 THEN VALUES(fee_is_estimated) ELSE fee_is_estimated END,
             product_amount_cents=VALUES(product_amount_cents),installments=VALUES(installments),
             payment_method=VALUES(payment_method),provider_account_id=VALUES(provider_account_id),
             external_product_id=COALESCE(NULLIF(VALUES(external_product_id),''),external_product_id),
             product_name=COALESCE(NULLIF(VALUES(product_name),''),product_name),integration_id=COALESCE(NULLIF(VALUES(integration_id),''),integration_id),origin_description=VALUES(origin_description),
             buyer_name=VALUES(buyer_name),buyer_email=VALUES(buyer_email),buyer_phone=VALUES(buyer_phone),buyer_phone_norm=VALUES(buyer_phone_norm),buyer_document=VALUES(buyer_document),
             matched_user_id=VALUES(matched_user_id),match_method=VALUES(match_method),raw_payload_json=VALUES(raw_payload_json),
             last_received_at=VALUES(last_received_at)");
        $sale->execute([
            ':transaction'=>$transactionCode,
            ':checkout'=>pagarme_scalar($data, 'code') ?: null,
            ':type'=>$event,
            ':provider_status'=>$providerStatus,
            ':normalized_status'=>$normalizedStatus,
            ':currency'=>pagarme_scalar($data, 'currency') ?: pagarme_scalar($charge, 'currency') ?: 'BRL',
            ':gross'=>$amountCents,
            ':net'=>$netCents,
            ':fee'=>$feeCents,
            ':fee_is_estimated'=>$feeIsEstimated ? 1 : 0,
            ':product_amount'=>payment_amount_cents($firstItem['amount'] ?? $amountCents),
            ':installments'=>(int)($lastTransaction['installments'] ?? 0) ?: null,
            ':payment_method'=>$paymentMethod,
            ':account'=>is_array($payload['account'] ?? null) ? ($payload['account']['id'] ?? null) : null,
            ':product_id'=>pagarme_scalar($firstItem, 'id') ?: null,
            ':product_name'=>$productName ?: null,
            ':integration_id'=>pagarme_scalar($firstItem, 'code') ?: pagarme_scalar($data, 'code') ?: null,
            ':origin'=>(string)($data['metadata']['utm_source'] ?? '') ?: null,
            ':buyer_name'=>pagarme_scalar($customer, 'name') ?: null,
            ':buyer_email'=>$email ?: null,
            ':buyer_phone'=>$phoneRaw ?: null,
            ':phone_norm'=>$phoneNorm ?: null,
            ':buyer_document'=>pagarme_scalar($customer, 'document') ?: null,
            ':user_id'=>$matchedUser['id'] ?? null,
            ':match_method'=>(string)($matched['method'] ?? 'none'),
            ':payload'=>$rawPayload,
            ':received_at'=>$receivedAt,
        ]);

        $lifetimeAttempt = ['granted'=>false,'reason'=>'payment_not_approved'];
        if (in_array($normalizedStatus, ['APPROVED','REFUNDED','CHARGEBACK','CANCELED'], true)) {
            $legacySale = hotmart_build_sale_data_from_array([
                'webhook_event'=>'PAGARME_' . strtoupper(str_replace('.', '_', $event)),
                'webhook_event_id'=>$fingerprint,
                'transaction_code'=>$transactionCode,
                'status'=>$normalizedStatus,
                'transaction_date'=>$receivedAt,
                'payment_confirmed_at'=>$normalizedStatus === 'APPROVED' ? $receivedAt : null,
                'refund_or_chargeback_at'=>in_array($normalizedStatus, ['REFUNDED','CHARGEBACK'], true) ? $receivedAt : null,
                'product_code'=>null,
                'product_name'=>$productName,
                'price_code'=>pagarme_scalar($firstItem, 'id'),
                'price_name'=>pagarme_scalar($firstItem, 'code'),
                'currency'=>pagarme_scalar($data, 'currency') ?: pagarme_scalar($charge, 'currency') ?: 'BRL',
                'gross_revenue'=>$amountCents / 100,
                'net_revenue'=>$netCents / 100,
                'producer_net'=>$netCents / 100,
                'refunded_value'=>$normalizedStatus === 'REFUNDED' ? $amountCents / 100 : 0,
                'chargeback_value'=>$normalizedStatus === 'CHARGEBACK' ? $amountCents / 100 : 0,
                'buyer_name'=>pagarme_scalar($customer, 'name'),
                'buyer_email'=>$email,
                'buyer_phone_raw'=>$phoneRaw,
                'buyer_phone_norm'=>$phoneNorm,
                'raw_payload_json'=>$rawPayload,
            ], $matched);
            foreach (['utm_source','utm_medium','utm_campaign','utm_term','utm_content'] as $utm) {
                if (isset($data['metadata'][$utm])) $legacySale[$utm] = (string)$data['metadata'][$utm];
            }
            hotmart_upsert_sale_live($pdo, $legacySale);
            hotmart_upsert_sale_legacy($pdo, $legacySale);
            $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,sale_origin=:origin,sales_channel='pagarme' WHERE transaction_code=:transaction")
                ->execute([
                    ':payment'=>$paymentMethod,
                    ':installments'=>(int)($lastTransaction['installments'] ?? 0) ?: null,
                    ':origin'=>(string)($data['metadata']['utm_source'] ?? '') ?: 'pagarme',
                    ':transaction'=>$transactionCode,
                ]);
            if ($normalizedStatus === 'APPROVED') {
                $lifetimeAttempt = pagarme_try_grant_lifetime($pdo, $data, $charge, $transactionCode, $email, $phoneRaw, $matchedUser);
            }
        }

        if ($pdo->inTransaction()) $pdo->commit();
        $paymentEvent = payment_event_register($pdo, [
            'provider'=>'pagarme',
            'normalized_status'=>$normalizedStatus,
            'transaction_code'=>$transactionCode,
            'provider_transaction_id'=>$transactionId,
            'provider_status'=>$providerStatus,
            'payment_method'=>$paymentMethod,
            'currency'=>pagarme_scalar($data, 'currency') ?: pagarme_scalar($charge, 'currency') ?: 'BRL',
            'gross_amount_cents'=>$amountCents,
            'net_amount_cents'=>$netCents,
            'fee_amount_cents'=>$feeCents,
            'installments'=>(int)($lastTransaction['installments'] ?? 0) ?: null,
            'product_name'=>$productName,
            'product_code'=>pagarme_scalar($firstItem, 'id') ?: pagarme_scalar($firstItem, 'code'),
            'checkout_id'=>pagarme_scalar($data, 'code'),
            'checkout_url'=>payment_event_first_value($data, ['checkout_url','payment_url','url']),
            'pix_qrcode'=>payment_event_first_value($lastTransaction, ['qr_code','pix_qr_code','pix_code','qrcode','copy_paste']),
            'pix_qrcode_url'=>payment_event_first_value($lastTransaction, ['qr_code_url','pix_qr_code_url','pix_url','qrcode_url']),
            'pix_expires_at'=>payment_event_first_value($lastTransaction, ['expires_at','expiration_date','pix_expires_at']),
            'boleto_url'=>payment_event_first_value($lastTransaction, ['boleto_url','pdf','url']),
            'boleto_line'=>payment_event_first_value($lastTransaction, ['line','digitable_line','barcode']),
            'buyer_name'=>pagarme_scalar($customer, 'name'),
            'buyer_email'=>$email,
            'buyer_phone'=>$phoneRaw,
            'buyer_document'=>pagarme_scalar($customer, 'document'),
            'user_id'=>(int)($matchedUser['id'] ?? 0),
            'raw_payload'=>$payload,
            'metadata'=>[
                'source'=>'pagarme_webhook',
                'event'=>$event,
                'match_method'=>(string)($matched['method'] ?? 'none'),
                'account'=>is_array($payload['account'] ?? null) ? ($payload['account']['id'] ?? null) : null,
                'lifetime_attempt'=>$lifetimeAttempt,
            ],
            'occurred_at'=>$receivedAt,
        ]);
        return [
            'transaction_id'=>$transactionId,
            'transaction_code'=>$transactionCode,
            'event'=>$event,
            'normalized_status'=>$normalizedStatus,
            'signature_valid'=>$secretValid,
            'matched_user_id'=>(int)($matchedUser['id'] ?? 0),
            'match_method'=>(string)($matched['method'] ?? 'none'),
            'lifetime_granted'=>!empty($lifetimeAttempt['granted']),
            'lifetime_attempt'=>$lifetimeAttempt,
            'payment_event'=>$paymentEvent,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
