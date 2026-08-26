<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics.php';

proteger_admin();

$menu = 'hotmart_import';
$page_title = 'Conciliar Vendas';

function hmri_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hmri_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function hmri_num($value): string { return number_format((float)$value, 0, ',', '.'); }

function hmri_provider_labels(): array
{
    return [
        'hotmart' => 'Hotmart',
        'dom' => 'DOM Pagamentos',
        'pagarme' => 'Pagar.me',
    ];
}

function hmri_provider_label(string $provider): string
{
    $labels = hmri_provider_labels();
    return $labels[$provider] ?? 'Hotmart';
}

function hmri_normalize_provider(string $provider): string
{
    $provider = strtolower(trim($provider));
    return in_array($provider, ['hotmart', 'dom', 'pagarme'], true) ? $provider : 'hotmart';
}

function hmri_batch_root(): string
{
    return __DIR__ . '/../app/private/hotmart_imports';
}

function hmri_batch_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Lote invalido.');
    }
    return hmri_batch_root() . '/' . $token;
}

function hmri_ensure_batch_root(): void
{
    $root = hmri_batch_root();
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de importacao.');
    }
}

function hmri_save_uploaded_file(array $file, string $provider): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro no upload do arquivo.');
    }
    $provider = hmri_normalize_provider($provider);
    $originalName = (string)($file['name'] ?? 'sales_upload');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'zip'], true)) {
        throw new RuntimeException('Envie um arquivo .csv ou .zip exportado da plataforma.');
    }

    hmri_ensure_batch_root();
    $token = bin2hex(random_bytes(16));
    $dir = hmri_batch_path($token);
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar o lote de importacao.');
    }
    $stored = $dir . '/upload.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $stored)) {
        throw new RuntimeException('Nao foi possivel salvar o upload.');
    }
    file_put_contents($dir . '/meta.json', json_encode([
        'token' => $token,
        'original_name' => $originalName,
        'stored_file' => basename($stored),
        'provider' => $provider,
        'uploaded_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return ['token' => $token, 'path' => $stored, 'original_name' => $originalName];
}

function hmri_extract_csv_files(string $filePath, string $token): array
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return [['path' => $filePath, 'name' => basename($filePath)]];
    }
    if ($ext !== 'zip') {
        throw new RuntimeException('Formato nao suportado.');
    }
    $dir = hmri_batch_path($token) . '/extracted';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel extrair o ZIP.');
    }
    $files = [];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Nao foi possivel abrir o ZIP.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
                continue;
            }
            $base = basename(str_replace('\\', '/', $name));
            if ($base === '' || strpos($base, '..') !== false) {
                continue;
            }
            $target = $dir . '/' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base);
            $stream = $zip->getStream($name);
            if (!$stream) {
                continue;
            }
            $out = fopen($target, 'wb');
            if (!$out) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            $files[] = ['path' => $target, 'name' => $base];
        }
        $zip->close();
    } elseif (class_exists('PharData')) {
        try {
            $zip = new PharData($filePath);
            $i = 0;
            foreach (new RecursiveIteratorIterator($zip) as $entry) {
                $name = (string)$entry->getPathName();
                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
                    continue;
                }
                $base = basename(str_replace('\\', '/', $name));
                if ($base === '' || strpos($base, '..') !== false) {
                    continue;
                }
                $target = $dir . '/' . ($i++) . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base);
                $content = file_get_contents((string)$entry->getPathName());
                if ($content === false) {
                    continue;
                }
                file_put_contents($target, $content);
                $files[] = ['path' => $target, 'name' => $base];
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Nao foi possivel abrir o ZIP: ' . $e->getMessage());
        }
    } else {
        throw new RuntimeException('O PHP nao tem suporte para ler ZIP. Envie o CSV extraido.');
    }

    if (!$files) {
        throw new RuntimeException('Nenhum CSV encontrado dentro do ZIP.');
    }
    return $files;
}

function hmri_sale_from_csv_row(PDO $sourcePdo, array $row, array $map, array $headers, string $fileName, int $lineNo): array
{
    $tx = trim((string)hotmart_pick($row, $map, ['transacao','codigodatransacao','codigodatransacaohotmart','transactioncode','codigo','hp','purchasetransaction'], ''));
    if ($tx === '') {
        throw new RuntimeException('Transacao/HP nao encontrada no CSV.');
    }

    $buyerEmail = trim((string)hotmart_pick($row, $map, ['emaildoacompradora','emaildocomprador','emailcomprador','email'], ''));
    $buyerPhoneRaw = trim((string)hotmart_pick($row, $map, ['telefonedocomprador','telefonecomprador','telefone','celular'], ''));
    $buyerPhoneNorm = normalize_phone_value($buyerPhoneRaw);
    $gross = hotmart_parse_decimal(hotmart_pick($row, $map, ['faturamentobrutosemimpostos','valordavenda','valorbruto','valor','fullprice','grossrevenue'], '0'));
    $net = hotmart_parse_decimal(hotmart_pick($row, $map, ['faturamentoliquido','valorliquido','receitaliquida','netrevenue'], (string)$gross));
    $producer = hotmart_parse_decimal(hotmart_pick($row, $map, ['faturamentoliquidodoaprodutora','faturamentoliquidodoprodutor','valordoprodutor','produtorneto','producernet'], (string)$net));

    return hotmart_build_sale_data_from_array([
        'webhook_event' => 'CSV_RECONCILE',
        'webhook_event_id' => 'csv:' . md5($fileName . '|' . $lineNo . '|' . $tx),
        'transaction_code' => $tx,
        'status' => hotmart_pick($row, $map, ['statusdatransacao','statusdacompra','status','situacao'], 'Aprovado'),
        'transaction_date' => hotmart_parse_datetime_value(hotmart_pick($row, $map, ['datadatransacao','datadacompra','datadepedido','data'], '')) ?: date('Y-m-d H:i:s'),
        'payment_confirmed_at' => hotmart_parse_datetime_value(hotmart_pick($row, $map, ['confirmacaodopagamento','datadeconfirmacao','pagamentoconfirmadoem','paymentconfirmedat'], '')),
        'refund_or_chargeback_at' => hotmart_parse_datetime_value(hotmart_pick($row, $map, ['datareembolso','datachargeback'], '')),
        'product_code' => hotmart_pick($row, $map, ['codigodoproduto','productcode','produtoid'], null),
        'product_name' => hotmart_pick($row, $map, ['produto','nomedoproduto','productname'], ''),
        'price_code' => hotmart_pick($row, $map, ['codigodopreco','codigodaoferta','pricecode','offercode'], ''),
        'price_name' => hotmart_pick($row, $map, ['nomedestepreco','nomedaoferta','oferta','pricename'], ''),
        'currency' => hotmart_pick($row, $map, ['moedadecompra','moeda','currency'], 'BRL'),
        'gross_revenue' => $gross,
        'net_revenue' => $net,
        'producer_net' => $producer,
        'refunded_value' => hotmart_parse_decimal(hotmart_pick($row, $map, ['valorreembolsado'], '0')),
        'chargeback_value' => hotmart_parse_decimal(hotmart_pick($row, $map, ['valorchargeback'], '0')),
        'buyer_name' => hotmart_pick($row, $map, ['compradora','nomedocomprador','comprador','buyername','nome'], ''),
        'buyer_email' => normalize_email_value($buyerEmail),
        'buyer_phone_raw' => $buyerPhoneRaw,
        'buyer_phone_norm' => $buyerPhoneNorm,
        'raw_payload_json' => json_encode([
            'source' => 'csv_reconcile',
            'file_name' => $fileName,
            'line' => $lineNo,
            'raw' => array_combine($headers, array_pad($row, count($headers), '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], ['user' => null, 'method' => 'none']);
}

function hmri_apply_user_to_sale(array &$sale, ?array $user, string $method): void
{
    if (!$user) {
        return;
    }
    $sale['matched_user_id'] = (int)$user['id'];
    $sale['match_method'] = $method;
    $sale['utm_source'] = (string)($user['utm_source'] ?? '');
    $sale['utm_medium'] = (string)($user['utm_medium'] ?? '');
    $sale['utm_campaign'] = (string)($user['utm_campaign'] ?? '');
    $sale['utm_term'] = (string)($user['utm_term'] ?? '');
    $sale['utm_content'] = (string)($user['utm_content'] ?? '');
}

function hmri_enrich_sales_matches(PDO $pdo, array &$sales): void
{
    $phones = [];
    $emails = [];
    foreach ($sales as $sale) {
        $phone = normalize_phone_value($sale['buyer_phone_norm'] ?? ($sale['buyer_phone_raw'] ?? ''));
        $email = normalize_email_value($sale['buyer_email'] ?? '');
        if ($phone !== '') {
            $phones[$phone] = $phone;
        }
        if ($email !== '') {
            $emails[$email] = $email;
        }
    }

    $phoneMap = [];
    foreach (array_chunk(array_values($phones), 400) as $chunk) {
        $params = [];
        $in = [];
        foreach ($chunk as $i => $phone) {
            $key = 'p' . $i;
            $params[$key] = $phone;
            $in[] = ':' . $key;
        }
        $sql = "SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                       RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 11) phone_norm
                  FROM users
                 WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 11) IN (" . implode(',', $in) . ")
                 ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $phone = (string)($row['phone_norm'] ?? '');
            if ($phone !== '' && !isset($phoneMap[$phone])) {
                $phoneMap[$phone] = $row;
            }
        }
    }

    $emailMap = [];
    foreach (array_chunk(array_values($emails), 400) as $chunk) {
        $params = [];
        $in = [];
        foreach ($chunk as $i => $email) {
            $key = 'e' . $i;
            $params[$key] = $email;
            $in[] = ':' . $key;
        }
        $sql = "SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                       LOWER(TRIM(email)) email_norm
                  FROM users
                 WHERE LOWER(TRIM(email)) IN (" . implode(',', $in) . ")
                 ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $email = (string)($row['email_norm'] ?? '');
            if ($email !== '' && !isset($emailMap[$email])) {
                $emailMap[$email] = $row;
            }
        }
    }

    foreach ($sales as &$sale) {
        $phone = normalize_phone_value($sale['buyer_phone_norm'] ?? ($sale['buyer_phone_raw'] ?? ''));
        $email = normalize_email_value($sale['buyer_email'] ?? '');
        if ($phone !== '' && isset($phoneMap[$phone])) {
            hmri_apply_user_to_sale($sale, $phoneMap[$phone], 'phone');
        } elseif ($email !== '' && isset($emailMap[$email])) {
            hmri_apply_user_to_sale($sale, $emailMap[$email], 'email');
        }
    }
    unset($sale);
}

function hmri_read_csv_sales(PDO $sourcePdo, string $filePath, string $fileName): array
{
    $fh0 = fopen($filePath, 'r');
    if (!$fh0) {
        throw new RuntimeException('Nao foi possivel abrir ' . $fileName);
    }
    $firstLine = (string)fgets($fh0);
    fclose($fh0);
    $separator = hotmart_guess_separator($firstLine);

    $fh = fopen($filePath, 'r');
    if (!$fh) {
        throw new RuntimeException('Nao foi possivel ler ' . $fileName);
    }
    $headers = fgetcsv($fh, 0, $separator, '"', '\\');
    if (!$headers) {
        fclose($fh);
        throw new RuntimeException('CSV sem cabecalho: ' . $fileName);
    }
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    }
    $map = [];
    foreach ($headers as $i => $header) {
        $map[hotmart_normalize_header((string)$header)] = $i;
    }

    $sales = [];
    $errors = [];
    $lineNo = 1;
    while (($row = fgetcsv($fh, 0, $separator, '"', '\\')) !== false) {
        $lineNo++;
        if (!$row || count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) {
            continue;
        }
        try {
            $sale = hmri_sale_from_csv_row($sourcePdo, $row, $map, $headers, $fileName, $lineNo);
            $tx = (string)$sale['transaction_code'];
            $sales[$tx] = $sale;
        } catch (Throwable $e) {
            $errors[] = ['file' => $fileName, 'line' => $lineNo, 'error' => $e->getMessage()];
        }
    }
    fclose($fh);
    return ['sales' => $sales, 'errors' => $errors];
}

function hmri_guess_csv_separator(string $firstLine): string
{
    $counts = [
        "\t" => substr_count($firstLine, "\t"),
        ';' => substr_count($firstLine, ';'),
        ',' => substr_count($firstLine, ','),
    ];
    arsort($counts);
    return (string)array_key_first($counts);
}

function hmri_read_assoc_csv(string $filePath, string $fileName): array
{
    $fh0 = fopen($filePath, 'r');
    if (!$fh0) throw new RuntimeException('Nao foi possivel abrir ' . $fileName);
    $firstLine = (string)fgets($fh0);
    fclose($fh0);
    $separator = hmri_guess_csv_separator($firstLine);

    $fh = fopen($filePath, 'r');
    if (!$fh) throw new RuntimeException('Nao foi possivel ler ' . $fileName);
    $headers = fgetcsv($fh, 0, $separator, '"', '\\');
    if (!$headers) {
        fclose($fh);
        throw new RuntimeException('CSV sem cabecalho: ' . $fileName);
    }
    if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $rows = [];
    $lineNo = 1;
    while (($row = fgetcsv($fh, 0, $separator, '"', '\\')) !== false) {
        $lineNo++;
        if (!$row || count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) continue;
        $assoc = array_combine($headers, array_pad($row, count($headers), ''));
        if (!is_array($assoc)) continue;
        $assoc['_line'] = $lineNo;
        $rows[] = $assoc;
    }
    fclose($fh);
    return [$headers, $rows];
}

function hmri_cents_to_money(int $cents): float
{
    return round($cents / 100, 2);
}

function hmri_decimal_to_cents($value): int
{
    $raw = trim((string)$value);
    if ($raw === '') return 0;
    $raw = str_replace(['R$', ' '], '', $raw);
    if (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif (strpos($raw, ',') !== false) {
        $raw = str_replace(',', '.', $raw);
    }
    return (int)round(((float)$raw) * 100);
}

function hmri_pagarme_status(string $status): string
{
    $s = strtolower(trim($status));
    if (in_array($s, ['paid', 'captured'], true)) return 'APPROVED';
    if (in_array($s, ['pending', 'processing'], true)) return 'PENDING';
    if ($s === 'refunded') return 'REFUNDED';
    if (in_array($s, ['chargedback', 'chargeback'], true)) return 'CHARGEBACK';
    if (in_array($s, ['failed', 'canceled', 'cancelled'], true)) return 'CANCELED';
    return 'UNKNOWN';
}

function hmri_dom_status(string $status): string
{
    $s = strtolower(trim($status));
    if (in_array($s, ['approved', 'paid', 'aprovado'], true)) return 'APPROVED';
    if (in_array($s, ['pending', 'capture', 'revision_paid', 'pendente'], true)) return 'PENDING';
    if (in_array($s, ['refunded', 'pending_refund', 'reembolsado'], true)) return 'REFUNDED';
    if (in_array($s, ['chargeback', 'in_mediation', 'dispute_pending', 'dispute', 'em disputa'], true)) return 'CHARGEBACK';
    if (in_array($s, ['failed', 'not_authorized', 'expired', 'cancelled_capture', 'canceled', 'cancelled', 'cancelado', 'error', 'falha na transacao', 'falha na transa��o'], true)) return 'CANCELED';
    return 'UNKNOWN';
}

function hmri_status_to_legacy(string $status): string
{
    $s = strtoupper(trim($status));
    if ($s === 'APPROVED') return 'Aprovado';
    if ($s === 'REFUNDED') return 'Reembolsado';
    if ($s === 'CHARGEBACK') return 'Chargeback';
    if ($s === 'CANCELED') return 'Cancelado';
    if ($s === 'PENDING') return 'Pendente';
    return 'Ignorado';
}

function hmri_gateway_sale_base(array $data): array
{
    $gross = (int)($data['gross_amount_cents'] ?? 0);
    $net = (int)($data['net_amount_cents'] ?? $gross);
    return [
        'provider' => (string)$data['provider'],
        'transaction_code' => (string)$data['transaction_code'],
        'external_checkout_id' => $data['external_checkout_id'] ?? null,
        'transaction_type' => 'csv_reconcile',
        'provider_status' => $data['provider_status'] ?? null,
        'normalized_status' => (string)$data['normalized_status'],
        'status' => hmri_status_to_legacy((string)$data['normalized_status']),
        'currency' => $data['currency'] ?? 'BRL',
        'gross_amount_cents' => $gross,
        'net_amount_cents' => $net,
        'fee_amount_cents' => (int)($data['fee_amount_cents'] ?? max(0, $gross - $net)),
        'fee_is_estimated' => (int)($data['fee_is_estimated'] ?? 0),
        'product_amount_cents' => (int)($data['product_amount_cents'] ?? $gross),
        'installments' => (int)($data['installments'] ?? 1),
        'payment_method' => $data['payment_method'] ?? null,
        'product_name' => $data['product_name'] ?? '',
        'buyer_name' => $data['buyer_name'] ?? '',
        'buyer_email' => normalize_email_value($data['buyer_email'] ?? ''),
        'buyer_phone_raw' => $data['buyer_phone_raw'] ?? '',
        'buyer_document' => $data['buyer_document'] ?? '',
        'transaction_date' => $data['transaction_date'] ?? date('Y-m-d H:i:s'),
        'payment_confirmed_at' => $data['payment_confirmed_at'] ?? ($data['transaction_date'] ?? date('Y-m-d H:i:s')),
        'gross_revenue' => hmri_cents_to_money($gross),
        'net_revenue' => hmri_cents_to_money($net),
        'producer_net' => hmri_cents_to_money($net),
        'raw_payload_json' => $data['raw_payload_json'] ?? '{}',
    ];
}

function hmri_sale_from_dom_row(array $row, string $fileName): ?array
{
    $txRaw = trim((string)($row['id_transaction'] ?? $row['order_id'] ?? ''));
    if ($txRaw === '') return null;
    $gross = hmri_decimal_to_cents($row['total'] ?? 0);
    $net = hmri_decimal_to_cents($row['total_liquid'] ?? 0);
    $status = hmri_dom_status((string)($row['status_type'] ?? $row['type_status'] ?? $row['status'] ?? ''));
    if ($status === 'UNKNOWN' && $gross === 0 && $net === 0) return null;
    return hmri_gateway_sale_base([
        'provider' => 'dom',
        'transaction_code' => 'dom:' . $txRaw,
        'external_checkout_id' => $txRaw,
        'provider_status' => (string)($row['type_status'] ?? $row['status_type'] ?? $row['status'] ?? ''),
        'normalized_status' => $status,
        'gross_amount_cents' => $gross,
        'net_amount_cents' => $net,
        'fee_amount_cents' => hmri_decimal_to_cents($row['mdr_value'] ?? 0) + hmri_decimal_to_cents($row['fee_installment_value'] ?? 0) + hmri_decimal_to_cents($row['fee_transaction'] ?? 0),
        'product_amount_cents' => hmri_decimal_to_cents($row['item_price'] ?? 0) ?: $gross,
        'installments' => (int)((float)($row['installments'] ?? 1)),
        'payment_method' => (string)($row['type_payment'] ?? ''),
        'product_name' => trim((string)($row['item_name'] ?? $row['product_first'] ?? ''), "\" \t\n\r\0\x0B"),
        'buyer_name' => trim((string)($row['client_name'] ?? ''), "\" \t\n\r\0\x0B"),
        'buyer_email' => (string)($row['client_email'] ?? ''),
        'buyer_phone_raw' => (string)($row['client_phone'] ?? ''),
        'buyer_document' => (string)($row['client_document'] ?? ''),
        'transaction_date' => hotmart_parse_datetime_value($row['create_date'] ?? '') ?: date('Y-m-d H:i:s'),
        'payment_confirmed_at' => hotmart_parse_datetime_value($row['paid_date'] ?? ($row['last_date'] ?? '')),
        'raw_payload_json' => json_encode(['source'=>'dom_csv_reconcile','file_name'=>$fileName,'line'=>$row['_line'] ?? null,'raw'=>$row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function hmri_sale_from_pagarme_row(array $row, string $fileName): ?array
{
    $charge = trim((string)($row['Charge_ID'] ?? ''));
    if ($charge === '') return null;
    $gross = (int)round((float)str_replace(',', '.', (string)($row['Amount_In_Cents'] ?? 0)));
    $status = hmri_pagarme_status((string)($row['Status'] ?? ''));
    return hmri_gateway_sale_base([
        'provider' => 'pagarme',
        'transaction_code' => 'pagarme:' . $charge,
        'external_checkout_id' => (string)($row['Order_Id'] ?? ''),
        'provider_status' => (string)($row['Status'] ?? ''),
        'normalized_status' => $status,
        'gross_amount_cents' => $gross,
        'net_amount_cents' => $gross,
        'fee_amount_cents' => 0,
        'fee_is_estimated' => 1,
        'product_amount_cents' => $gross,
        'installments' => 1,
        'payment_method' => '',
        'product_name' => '',
        'buyer_name' => (string)($row['Customer_Name'] ?? ''),
        'buyer_email' => (string)($row['Customer_Email'] ?? ''),
        'buyer_phone_raw' => (string)($row['Customer_Cell_phone'] ?? ($row['Customer_Home_phone'] ?? '')),
        'buyer_document' => (string)($row['Customer_Document'] ?? ''),
        'transaction_date' => hotmart_parse_datetime_value($row['Created_Date'] ?? '') ?: date('Y-m-d H:i:s'),
        'payment_confirmed_at' => hotmart_parse_datetime_value($row['Updated_At'] ?? ($row['Created_Date'] ?? '')),
        'raw_payload_json' => json_encode(['source'=>'pagarme_csv_reconcile','file_name'=>$fileName,'line'=>$row['_line'] ?? null,'raw'=>$row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function hmri_read_gateway_sales(string $provider, string $filePath, string $fileName): array
{
    [$headers, $rows] = hmri_read_assoc_csv($filePath, $fileName);
    $sales = [];
    $errors = [];
    foreach ($rows as $row) {
        try {
            $sale = $provider === 'dom' ? hmri_sale_from_dom_row($row, $fileName) : hmri_sale_from_pagarme_row($row, $fileName);
            if (!$sale) continue;
            $tx = (string)$sale['transaction_code'];
            if (isset($sales[$tx])) {
                if (($sale['normalized_status'] ?? 'UNKNOWN') === 'UNKNOWN' && (int)($sale['gross_amount_cents'] ?? 0) === 0) continue;
                if (($sales[$tx]['normalized_status'] ?? 'UNKNOWN') !== 'UNKNOWN' || (int)($sales[$tx]['gross_amount_cents'] ?? 0) > 0) continue;
            }
            $sales[$tx] = $sale;
        } catch (Throwable $e) {
            $errors[] = ['file'=>$fileName,'line'=>$row['_line'] ?? '?','error'=>$e->getMessage()];
        }
    }
    return ['sales'=>$sales,'errors'=>$errors];
}

function hmri_load_sales_from_batch(PDO $pdo, string $token): array
{
    $dir = hmri_batch_path($token);
    $metaFile = $dir . '/meta.json';
    if (!is_file($metaFile)) {
        throw new RuntimeException('Lote nao encontrado.');
    }
    $meta = json_decode((string)file_get_contents($metaFile), true) ?: [];
    $provider = hmri_normalize_provider((string)($meta['provider'] ?? 'hotmart'));
    $stored = $dir . '/' . (string)($meta['stored_file'] ?? '');
    if (!is_file($stored)) {
        throw new RuntimeException('Arquivo do lote nao encontrado.');
    }
    $files = hmri_extract_csv_files($stored, $token);
    $sales = [];
    $errors = [];
    foreach ($files as $file) {
        $result = $provider === 'hotmart'
            ? hmri_read_csv_sales($pdo, $file['path'], $file['name'])
            : hmri_read_gateway_sales($provider, $file['path'], $file['name']);
        foreach ($result['sales'] as $tx => $sale) {
            $sales[$tx] = $sale;
        }
        $errors = array_merge($errors, $result['errors']);
    }
    if ($provider === 'hotmart') hmri_enrich_sales_matches($pdo, $sales);
    return ['meta' => $meta, 'provider' => $provider, 'files' => $files, 'sales' => $sales, 'errors' => $errors];
}

function hmri_value_changed($old, $new, string $type): bool
{
    if ($type === 'money') {
        return abs((float)$old - (float)$new) >= 0.01;
    }
    if ($type === 'int') {
        return (int)$old !== (int)$new;
    }
    return trim((string)$old) !== trim((string)$new);
}

function hmri_compare_sale(?array $existing, array $sale): array
{
    if (!$existing) {
        return ['action' => 'insert', 'changes' => []];
    }
    $fields = [
        'status' => 'text',
        'transaction_date' => 'text',
        'payment_confirmed_at' => 'text',
        'product_code' => 'int',
        'product_name' => 'text',
        'price_code' => 'text',
        'price_name' => 'text',
        'currency' => 'text',
        'gross_revenue' => 'money',
        'net_revenue' => 'money',
        'producer_net' => 'money',
        'buyer_name' => 'text',
        'buyer_email' => 'text',
        'buyer_phone_norm' => 'text',
    ];
    $changes = [];
    foreach ($fields as $field => $type) {
        $old = $existing[$field] ?? null;
        $new = $sale[$field] ?? null;
        if (hmri_value_changed($old, $new, $type)) {
            $changes[$field] = ['old' => $old, 'new' => $new, 'type' => $type];
        }
    }
    return ['action' => $changes ? 'update' : 'same', 'changes' => $changes];
}

function hmri_load_existing_sales(PDO $pdo, array $transactionCodes): array
{
    $existing = [];
    $transactionCodes = array_values(array_unique(array_filter(array_map('strval', $transactionCodes))));
    foreach (array_chunk($transactionCodes, 400) as $chunk) {
        $params = [];
        $placeholders = [];
        foreach ($chunk as $i => $tx) {
            $key = 'tx' . $i;
            $params[$key] = $tx;
            $placeholders[] = ':' . $key;
        }
        if (!$placeholders) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT * FROM hotmart_sales_live WHERE transaction_code IN (' . implode(',', $placeholders) . ')');
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(string)$row['transaction_code']] = $row;
        }
    }
    return $existing;
}

function hmri_load_existing_payment_sales(PDO $pdo, string $provider, array $transactionCodes): array
{
    $existing = [];
    $transactionCodes = array_values(array_unique(array_filter(array_map('strval', $transactionCodes))));
    foreach (array_chunk($transactionCodes, 400) as $chunk) {
        $params = ['provider' => $provider];
        $placeholders = [];
        foreach ($chunk as $i => $tx) {
            $key = 'tx' . $i;
            $params[$key] = $tx;
            $placeholders[] = ':' . $key;
        }
        if (!$placeholders) continue;
        $stmt = $pdo->prepare("SELECT * FROM payment_sales WHERE provider=:provider AND external_transaction_id IN (" . implode(',', $placeholders) . ")");
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(string)$row['external_transaction_id']] = $row;
        }
    }
    return $existing;
}

function hmri_compare_payment_sale(?array $existing, array $sale): array
{
    if (!$existing) return ['action' => 'insert', 'changes' => []];
    $fields = [
        'provider_status' => 'text',
        'normalized_status' => 'text',
        'gross_amount_cents' => 'int',
        'net_amount_cents' => 'int',
        'fee_amount_cents' => 'int',
        'product_name' => 'text',
        'buyer_name' => 'text',
        'buyer_email' => 'text',
        'buyer_phone' => 'text',
        'buyer_document' => 'text',
    ];
    if (($sale['provider'] ?? '') === 'pagarme' && (int)($sale['fee_is_estimated'] ?? 0) === 1) {
        unset($fields['net_amount_cents'], $fields['fee_amount_cents']);
    }
    $map = [
        'buyer_phone' => 'buyer_phone_raw',
    ];
    $changes = [];
    foreach ($fields as $field => $type) {
        $saleField = $map[$field] ?? $field;
        $old = $existing[$field] ?? null;
        $new = $sale[$saleField] ?? null;
        if (hmri_value_changed($old, $new, $type)) {
            $changes[$field] = ['old'=>$old,'new'=>$new,'type'=>$type];
        }
    }
    return ['action' => $changes ? 'update' : 'same', 'changes' => $changes];
}

function hmri_build_preview(PDO $pdo, array $sales, array $errors, string $provider = 'hotmart'): array
{
    $summary = [
        'total' => count($sales),
        'insert' => 0,
        'update' => 0,
        'same' => 0,
        'errors' => count($errors),
        'net_total' => 0.0,
        'producer_total' => 0.0,
        'gross_total' => 0.0,
    ];
    $rows = [];
    $provider = hmri_normalize_provider($provider);
    $existingMap = $provider === 'hotmart'
        ? hmri_load_existing_sales($pdo, array_keys($sales))
        : hmri_load_existing_payment_sales($pdo, $provider, array_keys($sales));
    foreach ($sales as $tx => $sale) {
        $existing = $existingMap[$tx] ?? null;
        $cmp = $provider === 'hotmart' ? hmri_compare_sale($existing, $sale) : hmri_compare_payment_sale($existing, $sale);
        $summary[$cmp['action']]++;
        $summary['net_total'] += (float)$sale['net_revenue'];
        $summary['producer_total'] += (float)$sale['producer_net'];
        $summary['gross_total'] += (float)$sale['gross_revenue'];
        if ($cmp['action'] !== 'same' || count($rows) < 80) {
            $rows[] = [
                'transaction_code' => $tx,
                'action' => $cmp['action'],
                'status' => $sale['status'],
                'transaction_date' => $sale['transaction_date'],
                'payment_confirmed_at' => $sale['payment_confirmed_at'],
                'product_name' => $sale['product_name'],
                'buyer_email' => $sale['buyer_email'],
                'gross_revenue' => $sale['gross_revenue'],
                'net_revenue' => $sale['net_revenue'],
                'producer_net' => $sale['producer_net'],
                'changes' => $cmp['changes'],
            ];
        }
    }
    return ['summary' => $summary, 'rows' => array_slice($rows, 0, 300), 'errors' => array_slice($errors, 0, 100)];
}

function hmri_upsert_payment_sale(PDO $pdo, array $sale): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO payment_sales (
            provider, external_transaction_id, external_checkout_id, transaction_type, provider_status, normalized_status, currency,
            gross_amount_cents, net_amount_cents, fee_amount_cents, fee_is_estimated, product_amount_cents, interest_amount_cents,
            installments, payment_method, payment_gateway, product_name, buyer_name, buyer_email, buyer_phone, buyer_document,
            match_method, raw_payload_json, first_received_at, last_received_at, created_at, updated_at
        ) VALUES (
            :provider, :transaction, :checkout, :type, :provider_status, :normalized_status, :currency,
            :gross, :net, :fee, :fee_estimated, :product_amount, 0,
            :installments, :payment_method, :payment_gateway, :product_name, :buyer_name, :buyer_email, :buyer_phone, :buyer_document,
            'none', :raw, :first_received, :last_received, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            external_checkout_id=VALUES(external_checkout_id), transaction_type=VALUES(transaction_type),
            provider_status=VALUES(provider_status), normalized_status=VALUES(normalized_status), currency=VALUES(currency),
            gross_amount_cents=VALUES(gross_amount_cents),
            net_amount_cents=IF(VALUES(fee_is_estimated)=1, net_amount_cents, VALUES(net_amount_cents)),
            fee_amount_cents=IF(VALUES(fee_is_estimated)=1, fee_amount_cents, VALUES(fee_amount_cents)),
            fee_is_estimated=IF(VALUES(fee_is_estimated)=1, fee_is_estimated, VALUES(fee_is_estimated)),
            product_amount_cents=VALUES(product_amount_cents), installments=VALUES(installments),
            payment_method=VALUES(payment_method), product_name=VALUES(product_name), buyer_name=VALUES(buyer_name),
            buyer_email=VALUES(buyer_email), buyer_phone=VALUES(buyer_phone), buyer_document=VALUES(buyer_document),
            raw_payload_json=VALUES(raw_payload_json), last_received_at=VALUES(last_received_at), updated_at=NOW()"
    );
    $stmt->execute([
        'provider'=>$sale['provider'],
        'transaction'=>$sale['transaction_code'],
        'checkout'=>$sale['external_checkout_id'] ?: null,
        'type'=>'csv_reconcile',
        'provider_status'=>$sale['provider_status'] ?: null,
        'normalized_status'=>$sale['normalized_status'],
        'currency'=>$sale['currency'] ?: 'BRL',
        'gross'=>(int)$sale['gross_amount_cents'],
        'net'=>(int)$sale['net_amount_cents'],
        'fee'=>(int)$sale['fee_amount_cents'],
        'fee_estimated'=>$sale['provider'] === 'pagarme' ? 1 : 0,
        'product_amount'=>(int)$sale['product_amount_cents'],
        'installments'=>max(1, (int)$sale['installments']),
        'payment_method'=>$sale['payment_method'] ?: null,
        'payment_gateway'=>$sale['provider'],
        'product_name'=>$sale['product_name'] ?: null,
        'buyer_name'=>$sale['buyer_name'] ?: null,
        'buyer_email'=>$sale['buyer_email'] ?: null,
        'buyer_phone'=>$sale['buyer_phone_raw'] ?: null,
        'buyer_document'=>$sale['buyer_document'] ?: null,
        'raw'=>$sale['raw_payload_json'],
        'first_received'=>$sale['transaction_date'],
        'last_received'=>$sale['payment_confirmed_at'] ?: $sale['transaction_date'],
    ]);
}

function hmri_sync_gateway_ledger(PDO $pdo, array $sale): void
{
    $provider = (string)$sale['provider'];
    $legacyStatus = hmri_status_to_legacy((string)$sale['normalized_status']);
    if ((string)$sale['normalized_status'] === 'APPROVED') {
        $ledger = hotmart_build_sale_data_from_array([
            'webhook_event' => strtoupper($provider) . '_CSV_RECONCILE',
            'webhook_event_id' => $provider . '-csv:' . md5((string)$sale['transaction_code']),
            'transaction_code' => $sale['transaction_code'],
            'status' => 'Aprovado',
            'transaction_date' => $sale['transaction_date'],
            'payment_confirmed_at' => $sale['payment_confirmed_at'],
            'product_name' => $sale['product_name'],
            'currency' => $sale['currency'],
            'gross_revenue' => $sale['gross_revenue'],
            'net_revenue' => $sale['net_revenue'],
            'producer_net' => $sale['producer_net'],
            'buyer_name' => $sale['buyer_name'],
            'buyer_email' => $sale['buyer_email'],
            'buyer_phone_raw' => $sale['buyer_phone_raw'],
            'buyer_phone_norm' => normalize_phone_value($sale['buyer_phone_raw']),
            'raw_payload_json' => $sale['raw_payload_json'],
        ], ['user'=>null,'method'=>'none']);
        hotmart_upsert_sale_live($pdo, $ledger);
        hotmart_upsert_sale_legacy($pdo, $ledger);
        $pdo->prepare("UPDATE hotmart_sales_live SET sales_channel=:provider, sale_origin=:origin, payment_type=:payment, installments_number=:installments WHERE transaction_code=:tx")
            ->execute(['provider'=>$provider,'origin'=>$provider . '_csv','payment'=>$sale['payment_method'] ?: null,'installments'=>max(1,(int)$sale['installments']),'tx'=>$sale['transaction_code']]);
        return;
    }
    $pdo->prepare("UPDATE hotmart_sales_live SET status=:status, webhook_event=:event, updated_at=NOW() WHERE transaction_code=:tx AND COALESCE(NULLIF(sales_channel,''), :provider_default)=:provider")
        ->execute(['status'=>$legacyStatus,'event'=>strtoupper($provider) . '_CSV_RECONCILE','tx'=>$sale['transaction_code'],'provider_default'=>$provider,'provider'=>$provider]);
    $pdo->prepare("UPDATE hotmart_sales SET status=:status, updated_at=NOW() WHERE transaction_code=:tx")
        ->execute(['status'=>$legacyStatus,'tx'=>$sale['transaction_code']]);
}

function hmri_apply_sales(PDO $pdo, array $sales, string $provider = 'hotmart'): array
{
    $stats = ['inserted' => 0, 'updated' => 0, 'same' => 0, 'errors' => 0];
    $provider = hmri_normalize_provider($provider);
    $existingMap = $provider === 'hotmart'
        ? hmri_load_existing_sales($pdo, array_keys($sales))
        : hmri_load_existing_payment_sales($pdo, $provider, array_keys($sales));
    foreach ($sales as $tx => $sale) {
        try {
            $existing = $existingMap[$tx] ?? null;
            $cmp = $provider === 'hotmart' ? hmri_compare_sale($existing, $sale) : hmri_compare_payment_sale($existing, $sale);
            if ($cmp['action'] === 'same') {
                $stats['same']++;
                continue;
            }
            $pdo->beginTransaction();
            if ($provider === 'hotmart') {
                hotmart_upsert_sale_live($pdo, $sale);
                hotmart_upsert_sale_legacy($pdo, $sale);
            } else {
                hmri_upsert_payment_sale($pdo, $sale);
                hmri_sync_gateway_ledger($pdo, $sale);
            }
            $pdo->commit();
            if ($cmp['action'] === 'insert') {
                $stats['inserted']++;
            } else {
                $stats['updated']++;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $stats['errors']++;
            app_log('Erro ao aplicar conciliacao de vendas', ['transaction_code' => $tx, 'error' => $e->getMessage()]);
        }
    }
    return $stats;
}

$pdo = getPDO();
metrics_ensure_schema($pdo);

$message = '';
$error = '';
$preview = null;
$token = trim((string)($_GET['batch'] ?? $_POST['batch'] ?? ''));
$selectedProvider = hmri_normalize_provider((string)($_GET['provider'] ?? $_POST['provider'] ?? 'hotmart'));
$action = (string)($_POST['action'] ?? '');
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($requestMethod === 'POST' && $action === 'analyze') {
        $selectedProvider = hmri_normalize_provider((string)($_POST['provider'] ?? 'hotmart'));
        $upload = hmri_save_uploaded_file($_FILES['sales_file'] ?? ($_FILES['hotmart_file'] ?? []), $selectedProvider);
        $token = $upload['token'];
        $loaded = hmri_load_sales_from_batch($pdo, $token);
        $selectedProvider = hmri_normalize_provider((string)($loaded['provider'] ?? $selectedProvider));
        $preview = hmri_build_preview($pdo, $loaded['sales'], $loaded['errors'], $selectedProvider);
        file_put_contents(hmri_batch_path($token) . '/preview.json', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $message = 'Arquivo analisado. Revise as divergencias antes de autorizar.';
    } elseif ($requestMethod === 'POST' && $action === 'apply') {
        $loaded = hmri_load_sales_from_batch($pdo, $token);
        $selectedProvider = hmri_normalize_provider((string)($loaded['provider'] ?? $selectedProvider));
        $stats = hmri_apply_sales($pdo, $loaded['sales'], $selectedProvider);
        $preview = hmri_build_preview($pdo, $loaded['sales'], $loaded['errors'], $selectedProvider);
        file_put_contents(hmri_batch_path($token) . '/applied.json', json_encode([
            'applied_at' => date('Y-m-d H:i:s'),
            'provider' => $selectedProvider,
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $message = 'Conciliacao aplicada: ' . hmri_num($stats['inserted']) . ' inseridas, ' . hmri_num($stats['updated']) . ' atualizadas, ' . hmri_num($stats['same']) . ' sem alteracao, ' . hmri_num($stats['errors']) . ' erro(s).';
    } elseif ($token !== '') {
        $metaFile = hmri_batch_path($token) . '/meta.json';
        if (is_file($metaFile)) {
            $meta = json_decode((string)file_get_contents($metaFile), true) ?: [];
            $selectedProvider = hmri_normalize_provider((string)($meta['provider'] ?? $selectedProvider));
        }
        $previewFile = hmri_batch_path($token) . '/preview.json';
        if (is_file($previewFile)) {
            $preview = json_decode((string)file_get_contents($previewFile), true) ?: null;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/_header.php';
?>
<style>
.hmri{display:flex;flex-direction:column;gap:16px}.hmri-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}.hmri-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.hmri-head h1{font-size:22px;margin:0;color:var(--text)}.hmri-head p{font-size:12px;color:var(--muted);margin:4px 0 0}.hmri-actions{display:flex;gap:8px;flex-wrap:wrap}.hmri-upload{display:grid;grid-template-columns:220px 1fr auto;gap:12px;align-items:end}.hmri-upload label{display:block;font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.hmri-upload input,.hmri-upload select{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);color:var(--text);padding:10px}.hmri-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.hmri-kpi{border:1px solid var(--border);border-radius:var(--r);padding:12px;background:rgba(255,255,255,.02)}.hmri-kpi span{display:block;font-size:11px;color:var(--muted)}.hmri-kpi strong{display:block;font-size:19px;margin-top:4px}.hmri-table-wrap{overflow:auto;border:1px solid var(--border);border-radius:var(--r)}.hmri-table{width:100%;border-collapse:collapse;min-width:1000px}.hmri-table th,.hmri-table td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;text-align:left;vertical-align:top}.hmri-table th{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em;background:rgba(255,255,255,.03)}.hmri-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:800;text-transform:uppercase}.hmri-badge.insert{background:var(--success-dim);color:var(--success)}.hmri-badge.update{background:var(--warning-dim);color:var(--warning)}.hmri-badge.same{background:var(--info-dim);color:var(--info)}.hmri-msg{padding:12px 14px;border-radius:var(--r);border:1px solid var(--border);font-size:13px}.hmri-msg.ok{background:var(--success-dim);color:var(--text);border-color:rgba(34,197,94,.22)}.hmri-msg.err{background:var(--danger-dim);color:var(--text);border-color:rgba(239,68,68,.25)}.hmri-sub{font-size:11px;color:var(--muted)}.hmri-change{display:block;white-space:nowrap}.hmri-change b{color:var(--warning)}@media(max-width:900px){.hmri-upload{grid-template-columns:1fr}.hmri-kpis{grid-template-columns:repeat(2,1fr)}.hmri-head{flex-direction:column}}
</style>
<div class="hmri">
  <div class="hmri-head">
    <div>
      <h1>Conciliar vendas</h1>
      <p>Envie o CSV ou ZIP exportado da plataforma. O arquivo e a fonte confiavel: quando a transacao existir no banco, ela sera atualizada; quando nao existir, sera criada.</p>
    </div>
    <div class="hmri-actions">
      <a class="btn btn-ghost" href="vendas_analytics.php">Voltar para vendas</a>
    </div>
  </div>

  <?php if ($message): ?><div class="hmri-msg ok"><?= hmri_h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="hmri-msg err"><?= hmri_h($error) ?></div><?php endif; ?>

  <div class="hmri-card">
    <form method="post" enctype="multipart/form-data" class="hmri-upload">
      <input type="hidden" name="action" value="analyze">
      <div>
        <label>Plataforma</label>
        <select name="provider" required>
          <?php foreach (hmri_provider_labels() as $key => $label): ?>
            <option value="<?= hmri_h($key) ?>" <?= $selectedProvider === $key ? 'selected' : '' ?>><?= hmri_h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Arquivo de vendas (.csv ou .zip)</label>
        <input type="file" name="sales_file" accept=".csv,.zip" required>
      </div>
      <button class="btn btn-primary" type="submit">Analisar arquivo</button>
    </form>
    <p class="hmri-sub" style="margin-top:10px">Nada e aplicado nesta etapa. O upload cria um lote pendente para revisao.</p>
  </div>

  <?php if ($preview): $s = $preview['summary'] ?? []; ?>
  <div class="hmri-card">
    <div class="hmri-head" style="margin-bottom:12px">
      <div>
        <h1 style="font-size:17px">Resultado da analise</h1>
        <p><?= hmri_h(hmri_provider_label($selectedProvider)) ?> | lote <?= hmri_h($token) ?>. A tabela abaixo mostra ate 300 linhas de amostra, priorizando divergencias.</p>
      </div>
      <form method="post" onsubmit="return confirm('Autorizar a atualizacao das vendas com base neste arquivo?');">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="batch" value="<?= hmri_h($token) ?>">
        <input type="hidden" name="provider" value="<?= hmri_h($selectedProvider) ?>">
        <button class="btn btn-primary" type="submit">Autorizar e atualizar</button>
      </form>
    </div>
    <div class="hmri-kpis">
      <div class="hmri-kpi"><span>Transacoes no arquivo</span><strong><?= hmri_num($s['total'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Novas</span><strong><?= hmri_num($s['insert'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Com divergencia</span><strong><?= hmri_num($s['update'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Sem alteracao</span><strong><?= hmri_num($s['same'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Faturamento bruto</span><strong><?= hmri_money($s['gross_total'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Receita liquida</span><strong><?= hmri_money($s['net_total'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Liquido produtor</span><strong><?= hmri_money($s['producer_total'] ?? 0) ?></strong></div>
      <div class="hmri-kpi"><span>Erros de leitura</span><strong><?= hmri_num($s['errors'] ?? 0) ?></strong></div>
    </div>
  </div>

  <?php if (!empty($preview['errors'])): ?>
  <div class="hmri-card">
    <h2 style="font-size:15px;margin-bottom:10px">Erros de leitura</h2>
    <div class="hmri-table-wrap"><table class="hmri-table"><thead><tr><th>Arquivo</th><th>Linha</th><th>Erro</th></tr></thead><tbody>
      <?php foreach ($preview['errors'] as $err): ?>
      <tr><td><?= hmri_h($err['file'] ?? '') ?></td><td><?= hmri_h($err['line'] ?? '') ?></td><td><?= hmri_h($err['error'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>

  <div class="hmri-card">
    <h2 style="font-size:15px;margin-bottom:10px">Divergencias e amostra</h2>
    <div class="hmri-table-wrap">
      <table class="hmri-table">
        <thead><tr><th>Acao</th><th>Transacao</th><th>Status</th><th>Datas</th><th>Produto</th><th>Comprador</th><th>Valores</th><th>Campos divergentes</th></tr></thead>
        <tbody>
          <?php foreach (($preview['rows'] ?? []) as $row): ?>
          <tr>
            <td><span class="hmri-badge <?= hmri_h($row['action'] ?? 'same') ?>"><?= hmri_h($row['action'] ?? '-') ?></span></td>
            <td><strong><?= hmri_h($row['transaction_code'] ?? '') ?></strong></td>
            <td><?= hmri_h($row['status'] ?? '') ?></td>
            <td><div><?= hmri_h($row['transaction_date'] ?? '') ?></div><div class="hmri-sub">Confirmado: <?= hmri_h($row['payment_confirmed_at'] ?? '-') ?></div></td>
            <td><?= hmri_h($row['product_name'] ?? '') ?></td>
            <td><?= hmri_h($row['buyer_email'] ?? '') ?></td>
            <td><strong>Bruto: <?= hmri_money($row['gross_revenue'] ?? 0) ?></strong><div class="hmri-sub">Liquido: <?= hmri_money($row['net_revenue'] ?? 0) ?> | Produtor: <?= hmri_money($row['producer_net'] ?? 0) ?></div></td>
            <td>
              <?php if (empty($row['changes'])): ?>
                <span class="hmri-sub">Sem diferenca campo a campo</span>
              <?php else: foreach ($row['changes'] as $field => $change): ?>
                <span class="hmri-change"><b><?= hmri_h($field) ?></b>: <?= hmri_h($change['old'] ?? '') ?> -> <?= hmri_h($change['new'] ?? '') ?></span>
              <?php endforeach; endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
