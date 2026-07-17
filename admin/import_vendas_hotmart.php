<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics.php';

proteger_admin();

$menu = 'hotmart_import';
$page_title = 'Conciliar Vendas Hotmart';

function hmri_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hmri_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function hmri_num($value): string { return number_format((float)$value, 0, ',', '.'); }

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

function hmri_save_uploaded_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro no upload do arquivo.');
    }
    $originalName = (string)($file['name'] ?? 'hotmart_upload');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'zip'], true)) {
        throw new RuntimeException('Envie um arquivo .csv ou .zip exportado da Hotmart.');
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

function hmri_load_sales_from_batch(PDO $pdo, string $token): array
{
    $dir = hmri_batch_path($token);
    $metaFile = $dir . '/meta.json';
    if (!is_file($metaFile)) {
        throw new RuntimeException('Lote nao encontrado.');
    }
    $meta = json_decode((string)file_get_contents($metaFile), true) ?: [];
    $stored = $dir . '/' . (string)($meta['stored_file'] ?? '');
    if (!is_file($stored)) {
        throw new RuntimeException('Arquivo do lote nao encontrado.');
    }
    $files = hmri_extract_csv_files($stored, $token);
    $sales = [];
    $errors = [];
    foreach ($files as $file) {
        $result = hmri_read_csv_sales($pdo, $file['path'], $file['name']);
        foreach ($result['sales'] as $tx => $sale) {
            $sales[$tx] = $sale;
        }
        $errors = array_merge($errors, $result['errors']);
    }
    hmri_enrich_sales_matches($pdo, $sales);
    return ['meta' => $meta, 'files' => $files, 'sales' => $sales, 'errors' => $errors];
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

function hmri_build_preview(PDO $pdo, array $sales, array $errors): array
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
    $existingMap = hmri_load_existing_sales($pdo, array_keys($sales));
    foreach ($sales as $tx => $sale) {
        $existing = $existingMap[$tx] ?? null;
        $cmp = hmri_compare_sale($existing, $sale);
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

function hmri_apply_sales(PDO $pdo, array $sales): array
{
    $stats = ['inserted' => 0, 'updated' => 0, 'same' => 0, 'errors' => 0];
    $existingMap = hmri_load_existing_sales($pdo, array_keys($sales));
    foreach ($sales as $tx => $sale) {
        try {
            $existing = $existingMap[$tx] ?? null;
            $cmp = hmri_compare_sale($existing, $sale);
            if ($cmp['action'] === 'same') {
                $stats['same']++;
                continue;
            }
            $pdo->beginTransaction();
            hotmart_upsert_sale_live($pdo, $sale);
            hotmart_upsert_sale_legacy($pdo, $sale);
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
            app_log('Erro ao aplicar conciliacao Hotmart', ['transaction_code' => $tx, 'error' => $e->getMessage()]);
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
$action = (string)($_POST['action'] ?? '');
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($requestMethod === 'POST' && $action === 'analyze') {
        $upload = hmri_save_uploaded_file($_FILES['hotmart_file'] ?? []);
        $token = $upload['token'];
        $loaded = hmri_load_sales_from_batch($pdo, $token);
        $preview = hmri_build_preview($pdo, $loaded['sales'], $loaded['errors']);
        file_put_contents(hmri_batch_path($token) . '/preview.json', json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $message = 'Arquivo analisado. Revise as divergencias antes de autorizar.';
    } elseif ($requestMethod === 'POST' && $action === 'apply') {
        $loaded = hmri_load_sales_from_batch($pdo, $token);
        $stats = hmri_apply_sales($pdo, $loaded['sales']);
        $preview = hmri_build_preview($pdo, $loaded['sales'], $loaded['errors']);
        file_put_contents(hmri_batch_path($token) . '/applied.json', json_encode([
            'applied_at' => date('Y-m-d H:i:s'),
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $message = 'Conciliacao aplicada: ' . hmri_num($stats['inserted']) . ' inseridas, ' . hmri_num($stats['updated']) . ' atualizadas, ' . hmri_num($stats['same']) . ' sem alteracao, ' . hmri_num($stats['errors']) . ' erro(s).';
    } elseif ($token !== '') {
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
.hmri{display:flex;flex-direction:column;gap:16px}.hmri-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}.hmri-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.hmri-head h1{font-size:22px;margin:0;color:var(--text)}.hmri-head p{font-size:12px;color:var(--muted);margin:4px 0 0}.hmri-actions{display:flex;gap:8px;flex-wrap:wrap}.hmri-upload{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}.hmri-upload label{display:block;font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}.hmri-upload input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);color:var(--text);padding:10px}.hmri-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.hmri-kpi{border:1px solid var(--border);border-radius:var(--r);padding:12px;background:rgba(255,255,255,.02)}.hmri-kpi span{display:block;font-size:11px;color:var(--muted)}.hmri-kpi strong{display:block;font-size:19px;margin-top:4px}.hmri-table-wrap{overflow:auto;border:1px solid var(--border);border-radius:var(--r)}.hmri-table{width:100%;border-collapse:collapse;min-width:1000px}.hmri-table th,.hmri-table td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;text-align:left;vertical-align:top}.hmri-table th{color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em;background:rgba(255,255,255,.03)}.hmri-badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:800;text-transform:uppercase}.hmri-badge.insert{background:var(--success-dim);color:var(--success)}.hmri-badge.update{background:var(--warning-dim);color:var(--warning)}.hmri-badge.same{background:var(--info-dim);color:var(--info)}.hmri-msg{padding:12px 14px;border-radius:var(--r);border:1px solid var(--border);font-size:13px}.hmri-msg.ok{background:var(--success-dim);color:var(--text);border-color:rgba(34,197,94,.22)}.hmri-msg.err{background:var(--danger-dim);color:var(--text);border-color:rgba(239,68,68,.25)}.hmri-sub{font-size:11px;color:var(--muted)}.hmri-change{display:block;white-space:nowrap}.hmri-change b{color:var(--warning)}@media(max-width:900px){.hmri-upload{grid-template-columns:1fr}.hmri-kpis{grid-template-columns:repeat(2,1fr)}.hmri-head{flex-direction:column}}
</style>
<div class="hmri">
  <div class="hmri-head">
    <div>
      <h1>Conciliar vendas Hotmart</h1>
      <p>Envie o CSV ou ZIP exportado da Hotmart. O sistema compara com as transacoes atuais e so altera o banco depois da sua autorizacao.</p>
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
        <label>Arquivo Hotmart (.csv ou .zip)</label>
        <input type="file" name="hotmart_file" accept=".csv,.zip" required>
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
        <p>Lote <?= hmri_h($token) ?>. A tabela abaixo mostra ate 300 linhas de amostra, priorizando divergencias.</p>
      </div>
      <form method="post" onsubmit="return confirm('Autorizar a atualizacao das vendas com base neste arquivo?');">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="batch" value="<?= hmri_h($token) ?>">
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
