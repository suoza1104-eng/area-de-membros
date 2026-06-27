<?php

function hotmart_sale_valid_statuses(): array
{
    return array('Aprovado','APPROVED','PURCHASE_APPROVED','Completo','COMPLETE','PURCHASE_COMPLETE');
}

function hotmart_legacy_status_from_live(string $status): string
{
    $s = strtoupper(trim($status));
    if (in_array($s, array('APPROVED','PURCHASE_APPROVED','COMPLETE','PURCHASE_COMPLETE'), true)) {
        return 'Aprovado';
    }
    if ($s === 'REFUNDED' || $s === 'PURCHASE_REFUNDED') {
        return 'Reembolsado';
    }
    if ($s === 'CHARGEBACK' || $s === 'PURCHASE_CHARGEBACK') {
        return 'Chargeback';
    }
    if ($s === 'CANCELED' || $s === 'PURCHASE_CANCELED') {
        return 'Cancelado';
    }
    return trim($status) !== '' ? trim($status) : 'Aprovado';
}

function hotmart_find_matching_user(PDO $pdo, string $emailNorm, string $phoneNorm): array
{
    if ($phoneNorm !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content
             FROM users
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = :phone
                OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 11) = :phone
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([':phone' => $phoneNorm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return ['user' => $row, 'method' => 'phone'];
        }
    }

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
        if ($row) {
            return ['user' => $row, 'method' => 'email'];
        }
    }

    return ['user' => null, 'method' => 'none'];
}

function hotmart_get_existing_sale(PDO $pdo, string $transactionCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM hotmart_sales_live WHERE transaction_code = :transaction_code LIMIT 1');
    $stmt->execute([':transaction_code' => $transactionCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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
}

function hotmart_upsert_sale_legacy(PDO $pdo, array $saleData): void
{
    $stmt = $pdo->prepare('SELECT transaction_code FROM hotmart_sales WHERE transaction_code = :transaction_code LIMIT 1');
    $stmt->execute([':transaction_code' => $saleData['transaction_code']]);
    $exists = (bool)$stmt->fetchColumn();

    if ($exists) {
        $sql = "UPDATE hotmart_sales SET
                    status = :status,
                    transaction_date = :transaction_date,
                    payment_confirmed_at = :payment_confirmed_at,
                    product_code = :product_code,
                    product_name = :product_name,
                    price_code = :price_code,
                    price_name = :price_name,
                    currency = :currency,
                    gross_revenue = :gross_revenue,
                    net_revenue = :net_revenue,
                    producer_net = :producer_net,
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
                    updated_at = NOW()
                WHERE transaction_code = :transaction_code";
    } else {
        $sql = "INSERT INTO hotmart_sales (
                    transaction_code, status, transaction_date, payment_confirmed_at,
                    product_code, product_name, price_code, price_name, currency,
                    gross_revenue, net_revenue, producer_net,
                    buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm,
                    matched_user_id, match_method,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    imported_at, updated_at
                ) VALUES (
                    :transaction_code, :status, :transaction_date, :payment_confirmed_at,
                    :product_code, :product_name, :price_code, :price_name, :currency,
                    :gross_revenue, :net_revenue, :producer_net,
                    :buyer_name, :buyer_email, :buyer_phone_raw, :buyer_phone_norm,
                    :matched_user_id, :match_method,
                    :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                    NOW(), NOW()
                )";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':transaction_code' => $saleData['transaction_code'],
        ':status' => $saleData['legacy_status'],
        ':transaction_date' => $saleData['transaction_date'],
        ':payment_confirmed_at' => $saleData['payment_confirmed_at'],
        ':product_code' => $saleData['product_code'],
        ':product_name' => $saleData['product_name'],
        ':price_code' => $saleData['price_code'],
        ':price_name' => $saleData['price_name'],
        ':currency' => $saleData['currency'],
        ':gross_revenue' => $saleData['gross_revenue'],
        ':net_revenue' => $saleData['net_revenue'],
        ':producer_net' => $saleData['producer_net'],
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
    ]);
}

function hotmart_build_sale_data_from_array(array $data, array $matchedUser = ['user' => null, 'method' => 'none']): array
{
    $user = $matchedUser['user'] ?? null;
    $method = (string)($matchedUser['method'] ?? 'none');
    $status = trim((string)($data['status'] ?? 'Aprovado'));
    if ($status === '') { $status = 'Aprovado'; }

    return [
        'webhook_event' => (string)($data['webhook_event'] ?? 'CSV_IMPORT'),
        'webhook_event_id' => (string)($data['webhook_event_id'] ?? ''),
        'transaction_code' => (string)($data['transaction_code'] ?? ''),
        'status' => $status,
        'legacy_status' => (string)($data['legacy_status'] ?? hotmart_legacy_status_from_live($status)),
        'transaction_date' => $data['transaction_date'] ?: date('Y-m-d H:i:s'),
        'payment_confirmed_at' => $data['payment_confirmed_at'] ?: null,
        'refund_or_chargeback_at' => $data['refund_or_chargeback_at'] ?: null,
        'product_code' => $data['product_code'] ?: null,
        'product_name' => (string)($data['product_name'] ?? ''),
        'price_code' => (string)($data['price_code'] ?? ''),
        'price_name' => (string)($data['price_name'] ?? ''),
        'currency' => (string)($data['currency'] ?? 'BRL'),
        'gross_revenue' => (float)($data['gross_revenue'] ?? 0),
        'net_revenue' => (float)($data['net_revenue'] ?? 0),
        'producer_net' => (float)($data['producer_net'] ?? ($data['net_revenue'] ?? 0)),
        'refunded_value' => (float)($data['refunded_value'] ?? 0),
        'chargeback_value' => (float)($data['chargeback_value'] ?? 0),
        'buyer_name' => (string)($data['buyer_name'] ?? ''),
        'buyer_email' => (string)($data['buyer_email'] ?? ''),
        'buyer_phone_raw' => (string)($data['buyer_phone_raw'] ?? ''),
        'buyer_phone_norm' => normalize_phone_value($data['buyer_phone_norm'] ?? ($data['buyer_phone_raw'] ?? '')),
        'matched_user_id' => $user ? (int)$user['id'] : ($data['matched_user_id'] ?? null),
        'match_method' => $user ? (in_array($method, array('phone','email'), true) ? $method : 'none') : (string)($data['match_method'] ?? 'none'),
        'utm_source' => $user ? (string)($user['utm_source'] ?? '') : (string)($data['utm_source'] ?? ''),
        'utm_medium' => $user ? (string)($user['utm_medium'] ?? '') : (string)($data['utm_medium'] ?? ''),
        'utm_campaign' => $user ? (string)($user['utm_campaign'] ?? '') : (string)($data['utm_campaign'] ?? ''),
        'utm_term' => $user ? (string)($user['utm_term'] ?? '') : (string)($data['utm_term'] ?? ''),
        'utm_content' => $user ? (string)($user['utm_content'] ?? '') : (string)($data['utm_content'] ?? ''),
        'raw_payload_json' => (string)($data['raw_payload_json'] ?? ''),
    ];
}

function hotmart_guess_separator(string $headerLine): string
{
    $candidates = array(';', ',', "\t", '|');
    $best = ';';
    $bestCount = -1;
    foreach ($candidates as $sep) {
        $count = substr_count($headerLine, $sep);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $sep;
        }
    }
    return $best;
}

function hotmart_normalize_header(string $value): string
{
    return normalize_text_value(str_replace(array('Ã§','Ã£','Ã¡','Ã ','Ã¢','Ã©','Ãª','Ã­','Ã³','Ã´','Ãµ','Ãº'), array('c','a','a','a','a','e','e','i','o','o','o','u'), mb_strtolower(trim($value), 'UTF-8')));
}

function hotmart_parse_decimal($value): float
{
    $v = trim((string)$value);
    if ($v === '') { return 0.0; }
    $v = preg_replace('/[^\d,\.\-]/', '', $v);
    if ($v === null || $v === '') { return 0.0; }
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (strpos($v, ',') !== false) {
        $v = str_replace(',', '.', $v);
    }
    return (float)$v;
}

function hotmart_parse_datetime_value($value): ?string
{
    $v = trim((string)$value);
    if ($v === '') { return null; }
    $formats = array('Y-m-d H:i:s','d/m/Y H:i:s','d/m/Y H:i','d-m-Y H:i:s','Y-m-d\TH:i:sP','Y-m-d');
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $v);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function hotmart_pick(array $row, array $map, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (isset($map[$k]) && array_key_exists($map[$k], $row)) {
            $val = $row[$map[$k]];
            if ($val !== null && trim((string)$val) !== '') {
                return $val;
            }
        }
    }
    return $default;
}

function hotmart_import_csv(PDO $mainPdo, PDO $sourcePdo, string $filePath, string $fileName = ''): array
{
    $fh0 = fopen($filePath, 'r');
    if (!$fh0) {
        throw new RuntimeException('NÃ£o foi possÃ­vel abrir o arquivo CSV.');
    }
    $firstLine = (string)fgets($fh0);
    fclose($fh0);
    $separator = hotmart_guess_separator($firstLine);

    $fh = fopen($filePath, 'r');
    if (!$fh) {
        throw new RuntimeException('NÃ£o foi possÃ­vel ler o arquivo CSV.');
    }

    $headers = fgetcsv($fh, 0, $separator);
    if (!$headers) {
        fclose($fh);
        throw new RuntimeException('CSV sem cabeÃ§alho.');
    }
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    }
    $map = [];
    foreach ($headers as $i => $header) {
        $map[hotmart_normalize_header((string)$header)] = $i;
    }

    $inserted = 0; $updated = 0; $errors = 0; $lineNo = 1;

    while (($row = fgetcsv($fh, 0, $separator)) !== false) {
        $lineNo++;
        if (!$row || count(array_filter($row, function($v){ return trim((string)$v) !== ''; })) === 0) {
            continue;
        }
        try {
            $tx = trim((string)hotmart_pick($row, $map, ['transacao','codigodatransacao','codigodatransacaohotmart','transactioncode','codigo','hp','purchasetransaction'], ''));
            if ($tx === '') {
                throw new RuntimeException('TransaÃ§Ã£o/HP nÃ£o encontrada no CSV.');
            }

            $existing = hotmart_get_existing_sale($mainPdo, $tx);
            $buyerEmail = trim((string)hotmart_pick($row, $map, ['emaildocomprador','emailcomprador','email'], ''));
            $buyerPhoneRaw = trim((string)hotmart_pick($row, $map, ['telefonedocomprador','telefonecomprador','telefone','celular'], ''));
            $buyerPhoneNorm = normalize_phone_value($buyerPhoneRaw);
            $matched = hotmart_find_matching_user($sourcePdo, normalize_email_value($buyerEmail), $buyerPhoneNorm);
            $gross = hotmart_parse_decimal(hotmart_pick($row, $map, ['valordavenda','valorbruto','valor','fullprice','grossrevenue'], '0'));
            $net = hotmart_parse_decimal(hotmart_pick($row, $map, ['valorliquido','receitaliquida','netrevenue'], (string)$gross));
            $producer = hotmart_parse_decimal(hotmart_pick($row, $map, ['valordoprodutor','produtorneto','producernet'], (string)$net));

            $sale = hotmart_build_sale_data_from_array([
                'webhook_event' => 'CSV_IMPORT',
                'webhook_event_id' => 'csv:' . md5($fileName . '|' . $lineNo . '|' . $tx),
                'transaction_code' => $tx,
                'status' => hotmart_pick($row, $map, ['statusdacompra','status','situacao'], 'Aprovado'),
                'transaction_date' => hotmart_parse_datetime_value(hotmart_pick($row, $map, ['datadacompra','datadatransacao','datadepedido','data'], '')) ?: date('Y-m-d H:i:s'),
                'payment_confirmed_at' => hotmart_parse_datetime_value(hotmart_pick($row, $map, ['datadeconfirmacao','pagamentoconfirmadoem','paymentconfirmedat'], '')),
                'refund_or_chargeback_at' => hotmart_parse_datetime_value(hotmart_pick($row, $map, ['datareembolso','datachargeback'], '')),
                'product_code' => hotmart_pick($row, $map, ['codigodoproduto','productcode','produtoid'], null),
                'product_name' => hotmart_pick($row, $map, ['nomedoproduto','produto','productname'], ''),
                'price_code' => hotmart_pick($row, $map, ['codigodaoferta','pricecode','offercode'], ''),
                'price_name' => hotmart_pick($row, $map, ['nomedaoferta','oferta','pricename'], ''),
                'currency' => hotmart_pick($row, $map, ['moeda','currency'], 'BRL'),
                'gross_revenue' => $gross,
                'net_revenue' => $net,
                'producer_net' => $producer,
                'refunded_value' => hotmart_parse_decimal(hotmart_pick($row, $map, ['valorreembolsado'], '0')),
                'chargeback_value' => hotmart_parse_decimal(hotmart_pick($row, $map, ['valorchargeback'], '0')),
                'buyer_name' => hotmart_pick($row, $map, ['nomedocomprador','comprador','buyername','nome'], ''),
                'buyer_email' => $buyerEmail,
                'buyer_phone_raw' => $buyerPhoneRaw,
                'buyer_phone_norm' => $buyerPhoneNorm,
                'raw_payload_json' => json_encode([
                    'source' => 'csv_import',
                    'file_name' => $fileName,
                    'line' => $lineNo,
                    'raw' => array_combine($headers, array_pad($row, count($headers), '')),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $matched);

            $mainPdo->beginTransaction();
            hotmart_upsert_sale_live($mainPdo, $sale);
            $mainPdo->commit();
            hotmart_upsert_sale_legacy($sourcePdo, $sale);
            if ($existing) { $updated++; } else { $inserted++; }
        } catch (Throwable $e) {
            if ($mainPdo->inTransaction()) { $mainPdo->rollBack(); }
            $errors++;
            app_log('Erro ao importar CSV Hotmart', ['file' => $fileName, 'line' => $lineNo, 'error' => $e->getMessage()]);
        }
    }
    fclose($fh);

    return ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors, 'file_name' => $fileName];
}

