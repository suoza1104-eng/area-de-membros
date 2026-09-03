<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/metrics_helper.php')) require_once __DIR__ . '/metrics_helper.php';
elseif (file_exists(__DIR__ . '/../metrics_helper.php')) require_once __DIR__ . '/../metrics_helper.php';
require_once __DIR__ . '/../funcoes.php';

function hotmart_get_existing_sale(PDO $pdo, string $transactionCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM hotmart_sales_live WHERE transaction_code = :transaction_code LIMIT 1');
    $stmt->execute([':transaction_code' => $transactionCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hotmart_upsert_sales_master(PDO $pdo, array $saleData): void
{
    $tx = (string)($saleData['transaction_code'] ?? '');
    if ($tx === '') return;

    $rawStatus = (string)($saleData['status'] ?? 'PENDING');
    $v = strtoupper(trim($rawStatus));
    if (in_array($v, ['APPROVED', 'APROVADO', 'COMPLETO', 'COMPLETED', 'PAID', 'DISPAROU', 'OK'], true)) $stEnum = 'APPROVED';
    elseif (in_array($v, ['REFUNDED', 'REEMBOLSADO', 'REFUND'], true)) $stEnum = 'REFUNDED';
    elseif (in_array($v, ['CHARGEBACK', 'CONTESTADO', 'RECLAMADO'], true)) $stEnum = 'CHARGEBACK';
    elseif (in_array($v, ['CANCELED', 'CANCELADO', 'FAILED', 'EXPIRED'], true)) $stEnum = 'CANCELED';
    else $stEnum = 'PENDING';

    $gross = (float)($saleData['gross_revenue'] ?? 0);
    $prod = (float)($saleData['producer_net'] ?? $saleData['net_revenue'] ?? $gross);
    $net = (float)($saleData['net_revenue'] ?? $prod);
    $fees = max(0, $gross - $prod);

    $st = $pdo->prepare("
        INSERT INTO hotmart_sales (
            transaction_code, status, product_id, product_name, price_code, price_name,
            gross_revenue, net_revenue, producer_net, fees, refunded_value,
            buyer_name, buyer_email, buyer_phone, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
            payment_type, installments, sale_date, payment_confirmed_at, raw_payload_json, created_at
        ) VALUES (
            :tx, :status, :prod_id, :prod_name, :price_code, :price_name,
            :gross, :net, :prod, :fees, :ref_val,
            :b_name, :b_email, :b_phone, :utm_s, :utm_m, :utm_c, :utm_t, :utm_cnt,
            :pay_type, :inst, :sale_date, :confirmed_at, :raw, NOW()
        ) ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            product_name = VALUES(product_name),
            gross_revenue = VALUES(gross_revenue),
            net_revenue = VALUES(net_revenue),
            producer_net = VALUES(producer_net),
            fees = VALUES(fees),
            buyer_name = VALUES(buyer_name),
            buyer_email = VALUES(buyer_email),
            buyer_phone = VALUES(buyer_phone),
            payment_confirmed_at = VALUES(payment_confirmed_at),
            updated_at = NOW()
    ");

    $st->execute([
        'tx' => $tx,
        'status' => $stEnum,
        'prod_id' => (string)($saleData['product_code'] ?? ''),
        'prod_name' => (string)($saleData['product_name'] ?? ''),
        'price_code' => (string)($saleData['price_code'] ?? ''),
        'price_name' => (string)($saleData['price_name'] ?? ''),
        'gross' => $gross,
        'net' => $net,
        'prod' => $prod,
        'fees' => $fees,
        'ref_val' => (float)($saleData['refunded_value'] ?? 0),
        'b_name' => (string)($saleData['buyer_name'] ?? ''),
        'b_email' => (string)($saleData['buyer_email'] ?? ''),
        'b_phone' => (string)($saleData['buyer_phone_norm'] ?: $saleData['buyer_phone_raw'] ?? ''),
        'utm_s' => (string)($saleData['utm_source'] ?? ''),
        'utm_m' => (string)($saleData['utm_medium'] ?? ''),
        'utm_c' => (string)($saleData['utm_campaign'] ?? ''),
        'utm_t' => (string)($saleData['utm_term'] ?? ''),
        'utm_cnt' => (string)($saleData['utm_content'] ?? ''),
        'pay_type' => (string)($saleData['payment_type'] ?? ''),
        'inst' => (int)($saleData['installments_number'] ?: 1),
        'sale_date' => (string)($saleData['transaction_date'] ?: date('Y-m-d H:i:s')),
        'confirmed_at' => $saleData['payment_confirmed_at'] ?: null,
        'raw' => $saleData['raw_payload_json'] ?: null,
    ]);
}

function hotmart_upsert_sale_live(PDO $pdo, array $saleData): void
{
    $exists = hotmart_get_existing_sale($pdo, (string)$saleData['transaction_code']) !== null;
    if ($exists) {
        $sql = "UPDATE hotmart_sales_live SET
                    webhook_event = :webhook_event,
                    webhook_event_id = :webhook_event_id,
                    status = :status,
                    transaction_date = :transaction_date,
                    payment_confirmed_at = :payment_confirmed_at,
                    refund_or_chargeback_at = :refund_or_chargeback_at,
                    product_code = :product_code,
                    product_name = :product_name,
                    price_code = :price_code,
                    price_name = :price_name,
                    currency = :currency,
                    gross_revenue = :gross_revenue,
                    net_revenue = :net_revenue,
                    producer_net = :producer_net,
                    refunded_value = :refunded_value,
                    chargeback_value = :chargeback_value,
                    buyer_name = :buyer_name,
                    buyer_email = :buyer_email,
                    buyer_phone_raw = :buyer_phone_raw,
                    buyer_phone_norm = :buyer_phone_norm,
                    matched_user_id = :matched_user_id,
                    match_method = :match_method,
                    utm_source = :utm_source,
                    utm_medium = :utm_medium,
                    utm_campaign = :utm_campaign,
                    utm_term = :utm_term,
                    utm_content = :utm_content,
                    raw_payload_json = :raw_payload_json,
                    updated_at = NOW()
                WHERE transaction_code = :transaction_code";
    } else {
        $sql = "INSERT INTO hotmart_sales_live (
                    webhook_event, webhook_event_id, transaction_code, status,
                    transaction_date, payment_confirmed_at, refund_or_chargeback_at,
                    product_code, product_name, price_code, price_name, currency,
                    gross_revenue, net_revenue, producer_net, refunded_value, chargeback_value,
                    buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm,
                    matched_user_id, match_method,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    raw_payload_json, imported_at, updated_at
                ) VALUES (
                    :webhook_event, :webhook_event_id, :transaction_code, :status,
                    :transaction_date, :payment_confirmed_at, :refund_or_chargeback_at,
                    :product_code, :product_name, :price_code, :price_name, :currency,
                    :gross_revenue, :net_revenue, :producer_net, :refunded_value, :chargeback_value,
                    :buyer_name, :buyer_email, :buyer_phone_raw, :buyer_phone_norm,
                    :matched_user_id, :match_method,
                    :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                    :raw_payload_json, NOW(), NOW()
                )";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':webhook_event' => $saleData['webhook_event'],
        ':webhook_event_id' => $saleData['webhook_event_id'],
        ':transaction_code' => $saleData['transaction_code'],
        ':status' => $saleData['status'],
        ':transaction_date' => $saleData['transaction_date'],
        ':payment_confirmed_at' => $saleData['payment_confirmed_at'],
        ':refund_or_chargeback_at' => $saleData['refund_or_chargeback_at'],
        ':product_code' => $saleData['product_code'],
        ':product_name' => $saleData['product_name'],
        ':price_code' => $saleData['price_code'],
        ':price_name' => $saleData['price_name'],
        ':currency' => $saleData['currency'],
        ':gross_revenue' => $saleData['gross_revenue'],
        ':net_revenue' => $saleData['net_revenue'],
        ':producer_net' => $saleData['producer_net'],
        ':refunded_value' => $saleData['refunded_value'],
        ':chargeback_value' => $saleData['chargeback_value'],
        ':buyer_name' => $saleData['buyer_name'],
        ':buyer_email' => $saleData['buyer_email'],
        ':buyer_phone_raw' => $saleData['buyer_phone_raw'],
        ':buyer_phone_norm' => $saleData['buyer_phone_norm'],
        ':matched_user_id' => $saleData['matched_user_id'],
        ':match_method' => $saleData['match_method'],
        ':utm_source' => $saleData['utm_source'],
        ':utm_medium' => $saleData['utm_medium'],
        ':utm_campaign' => $saleData['utm_campaign'],
        ':utm_term' => $saleData['utm_term'],
        ':utm_content' => $saleData['utm_content'],
        ':raw_payload_json' => $saleData['raw_payload_json'],
    ]);

    try {
        $tx = (string)($saleData['transaction_code'] ?? '');
        if ($tx !== '' && strpos($tx, 'dom:') !== 0 && strpos($tx, 'pagarme:') !== 0) {
            hotmart_upsert_sales_master($pdo, $saleData);
        }
    } catch (Throwable $e) {}
}

function hotmart_upsert_sale_legacy(PDO $pdo, array $saleData): void
{
    try {
        hotmart_upsert_sales_master($pdo, $saleData);
    } catch (Throwable $e) {}
}

function hotmart_find_matching_user(PDO $pdo, string $email, string $phone): ?array
{
    $email = normalize_email_value($email);
    $phone = normalize_phone_value($phone);

    $hasUsers = md_table_exists($pdo, 'users');
    $userTable = $hasUsers ? 'users' : (md_table_exists($pdo, 'usuarios') ? 'usuarios' : '');
    if (!$userTable) return null;

    $phoneCol = md_pick_column($pdo, $userTable, ['telefone', 'whatsapp', 'phone']);

    if ($email !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id, email, nome FROM {$userTable} WHERE LOWER(TRIM(email)) = :email LIMIT 1");
            $stmt->execute([':email' => strtolower($email)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return ['id' => (int)$user['id'], 'match_method' => 'email', 'user' => $user];
            }
        } catch (Throwable $e) {}
    }

    if ($phone !== '' && $phoneCol !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id, email, nome FROM {$userTable} WHERE {$phoneCol} IS NOT NULL AND {$phoneCol} <> '' AND ({$phoneCol} = :phone OR RIGHT({$phoneCol}, 8) = RIGHT(:phone, 8)) LIMIT 1");
            $stmt->execute([':phone' => $phone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return ['id' => (int)$user['id'], 'match_method' => 'phone', 'user' => $user];
            }
        } catch (Throwable $e) {}
    }

    return null;
}

function hotmart_build_sale_data_from_array(array $payload, ?array $matchedUser): array
{
    $status = (string)($payload['status'] ?? 'PENDING');

    return [
        'webhook_event' => (string)($payload['webhook_event'] ?? ''),
        'webhook_event_id' => (string)($payload['webhook_event_id'] ?? ''),
        'transaction_code' => (string)($payload['transaction_code'] ?? ''),
        'status' => $status,
        'legacy_status' => $status,
        'transaction_date' => $payload['transaction_date'] ?? null,
        'payment_confirmed_at' => $payload['payment_confirmed_at'] ?? null,
        'refund_or_chargeback_at' => $payload['refund_or_chargeback_at'] ?? null,
        'product_code' => (string)($payload['product_code'] ?? ''),
        'product_name' => (string)($payload['product_name'] ?? ''),
        'price_code' => (string)($payload['price_code'] ?? ''),
        'price_name' => (string)($payload['price_name'] ?? ''),
        'currency' => (string)($payload['currency'] ?? 'BRL'),
        'gross_revenue' => (float)($payload['gross_revenue'] ?? 0),
        'net_revenue' => (float)($payload['net_revenue'] ?? 0),
        'producer_net' => (float)($payload['producer_net'] ?? 0),
        'refunded_value' => (float)($payload['refunded_value'] ?? 0),
        'chargeback_value' => (float)($payload['chargeback_value'] ?? 0),
        'buyer_name' => (string)($payload['buyer_name'] ?? ''),
        'buyer_email' => (string)($payload['buyer_email'] ?? ''),
        'buyer_phone_raw' => (string)($payload['buyer_phone_raw'] ?? ''),
        'buyer_phone_norm' => (string)($payload['buyer_phone_norm'] ?? ''),
        'matched_user_id' => $matchedUser ? (int)$matchedUser['id'] : null,
        'match_method' => $matchedUser ? (string)$matchedUser['match_method'] : 'none',
        'utm_source' => (string)($payload['utm_source'] ?? ''),
        'utm_medium' => (string)($payload['utm_medium'] ?? ''),
        'utm_campaign' => (string)($payload['utm_campaign'] ?? ''),
        'utm_term' => (string)($payload['utm_term'] ?? ''),
        'utm_content' => (string)($payload['utm_content'] ?? ''),
        'raw_payload_json' => (string)($payload['raw_payload_json'] ?? ''),
    ];
}
