<?php
declare(strict_types=1);

/**
 * Mini relay para webhooks Firepay.
 *
 * URL de recepcao:
 *   https://professoremersonleite.site/firepay_bridge_site.php
 *
 * Dashboard:
 *   https://professoremersonleite.site/firepay_bridge_site.php?dashboard=1
 *
 * Configuracao:
 *   https://professoremersonleite.site/firepay_bridge_site.php?config=1
 *
 * Processador da fila, use via cron a cada minuto:
 *   * * * * php /CAMINHO/DO/SITE/firepay_bridge_site.php process_queue >/dev/null 2>&1
 * ou por URL:
 *   https://professoremersonleite.site/firepay_bridge_site.php?process_queue=1
 */

const FP_RELAY_VERSION = '2026-08-04.2';
const FP_RELAY_DEFAULT_TARGET = 'https://professoremersonleite.com/area_membros/firepay_mcqdc.php';
const FP_RELAY_DATA_DIR = __DIR__ . '/firepay_relay_data';
const FP_RELAY_DB_FILE = FP_RELAY_DATA_DIR . '/firepay_relay.sqlite';
const FP_RELAY_MAX_ATTEMPTS_PER_RUN = 50;

date_default_timezone_set('America/Sao_Paulo');

function fp_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fp_now(): string
{
    return date('Y-m-d H:i:s');
}

function fp_uuid(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return str_replace('.', '', uniqid('', true));
    }
}

function fp_ensure_data_dir(): void
{
    if (!is_dir(FP_RELAY_DATA_DIR)) {
        @mkdir(FP_RELAY_DATA_DIR, 0775, true);
    }
    $htaccess = FP_RELAY_DATA_DIR . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
}

function fp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    fp_ensure_data_dir();
    $pdo = new PDO('sqlite:' . FP_RELAY_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    fp_schema($pdo);
    return $pdo;
}

function fp_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS relay_settings (
            key TEXT PRIMARY KEY,
            value TEXT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS inbound_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_uuid TEXT NOT NULL UNIQUE,
            method TEXT NOT NULL,
            content_type TEXT NULL,
            content_length TEXT NULL,
            remote_addr TEXT NULL,
            user_agent TEXT NULL,
            headers_json TEXT NULL,
            query_json TEXT NULL,
            body_raw TEXT NULL,
            body_sha256 TEXT NULL,
            parsed_type TEXT NOT NULL DEFAULT 'raw',
            parsed_json TEXT NULL,
            transaction_id TEXT NULL,
            firepay_status TEXT NULL,
            buyer_phone TEXT NULL,
            buyer_document TEXT NULL,
            buyer_email TEXT NULL,
            buyer_name TEXT NULL,
            product_name TEXT NULL,
            payment_method TEXT NULL,
            checkout_id TEXT NULL,
            product_id TEXT NULL,
            amount_total TEXT NULL,
            currency TEXT NULL,
            paid_at TEXT NULL,
            sale_created_at TEXT NULL,
            received_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_inbound_received ON inbound_logs(received_at);
        CREATE INDEX IF NOT EXISTS idx_inbound_transaction ON inbound_logs(transaction_id);
        CREATE INDEX IF NOT EXISTS idx_inbound_status ON inbound_logs(firepay_status);
        CREATE INDEX IF NOT EXISTS idx_inbound_email ON inbound_logs(buyer_email);

        CREATE TABLE IF NOT EXISTS dispatch_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inbound_id INTEGER NOT NULL,
            target_url TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 30,
            next_attempt_at TEXT NOT NULL,
            last_attempt_at TEXT NULL,
            sent_at TEXT NULL,
            last_http_status INTEGER NULL,
            last_error TEXT NULL,
            last_response_body TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(inbound_id) REFERENCES inbound_logs(id)
        );
        CREATE INDEX IF NOT EXISTS idx_queue_status_next ON dispatch_queue(status, next_attempt_at);
        CREATE INDEX IF NOT EXISTS idx_queue_inbound ON dispatch_queue(inbound_id);

        CREATE TABLE IF NOT EXISTS outbound_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue_id INTEGER NOT NULL,
            inbound_id INTEGER NOT NULL,
            target_url TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            http_status INTEGER NULL,
            ok INTEGER NOT NULL DEFAULT 0,
            error TEXT NULL,
            response_body TEXT NULL,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            attempted_at TEXT NOT NULL,
            FOREIGN KEY(queue_id) REFERENCES dispatch_queue(id),
            FOREIGN KEY(inbound_id) REFERENCES inbound_logs(id)
        );
        CREATE INDEX IF NOT EXISTS idx_outbound_attempted ON outbound_logs(attempted_at);
    ");

    fp_ensure_column($pdo, 'inbound_logs', 'buyer_phone', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'buyer_document', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'payment_method', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'checkout_id', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'product_id', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'amount_total', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'currency', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'paid_at', 'TEXT NULL');
    fp_ensure_column($pdo, 'inbound_logs', 'sale_created_at', 'TEXT NULL');

    fp_setting_default($pdo, 'target_url', FP_RELAY_DEFAULT_TARGET);
    fp_setting_default($pdo, 'receiver_url', fp_current_receiver_url());
    fp_setting_default($pdo, 'forward_mode', 'sanitized');
    fp_setting_default($pdo, 'last_queue_run_at', '');
}

function fp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $cols = $stmt ? $stmt->fetchAll() : [];
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === $column) return;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function fp_setting_default(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO relay_settings (key,value,updated_at) VALUES (:key,:value,:updated)");
    $stmt->execute([':key' => $key, ':value' => $value, ':updated' => fp_now()]);
}

function fp_get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT value FROM relay_settings WHERE key = :key LIMIT 1");
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : $default;
}

function fp_set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO relay_settings (key,value,updated_at) VALUES (:key,:value,:updated)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
    ");
    $stmt->execute([':key' => $key, ':value' => $value, ':updated' => fp_now()]);
}

function fp_current_receiver_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'professoremersonleite.site';
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/firepay_bridge_site.php'), '?') ?: '/firepay_bridge_site.php';
    return $scheme . '://' . $host . $path;
}

function fp_headers(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $raw = getallheaders();
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                $headers[(string)$key] = is_scalar($value) ? (string)$value : json_encode($value);
            }
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0 || !is_scalar($value)) continue;
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = (string)$value;
    }
    return $headers;
}

function fp_read_body(): string
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        $stdin = @file_get_contents('php://stdin');
        if (is_string($stdin) && $stdin !== '') $raw = $stdin;
    }
    if (!is_string($raw)) $raw = '';

    $encoding = strtolower(trim((string)($_SERVER['HTTP_CONTENT_ENCODING'] ?? '')));
    if ($raw !== '' && in_array($encoding, ['gzip', 'x-gzip'], true) && function_exists('gzdecode')) {
        $decoded = @gzdecode($raw);
        if (is_string($decoded) && $decoded !== '') $raw = $decoded;
    }
    return $raw;
}

function fp_parse_payload(string $raw): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $parsed = null;
    $type = 'raw';

    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return ['type' => 'json', 'data' => $json];
        }
    }

    if ($_POST) {
        return ['type' => 'form', 'data' => $_POST];
    }

    if ($raw !== '' && str_contains($contentType, 'application/x-www-form-urlencoded')) {
        parse_str($raw, $form);
        if (is_array($form) && $form) {
            return ['type' => 'form_raw', 'data' => $form];
        }
    }

    if ($raw !== '') {
        $parsed = ['raw' => $raw];
    } elseif ($_GET) {
        $type = 'query';
        $parsed = $_GET;
    } else {
        $parsed = [];
    }

    return ['type' => $type, 'data' => $parsed];
}

function fp_scalar_path(array $data, array $paths): string
{
    foreach ($paths as $path) {
        $current = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if (is_scalar($current)) {
            $value = trim((string)$current);
            if ($value !== '') return $value;
        }
    }
    return '';
}

function fp_extract_fields(array $payload): array
{
    return [
        'transaction_id' => fp_scalar_path($payload, ['id', 'transaction', 'transaction_id', 'id_transaction', 'order_id', 'data.id', 'data.purchase.transaction']),
        'firepay_status' => fp_scalar_path($payload, ['status', 'type_status', 'status_type', 'data.status', 'data.purchase.status']),
        'buyer_phone' => fp_scalar_path($payload, ['client.phone', 'buyer.phone', 'customer.phone', 'phone', 'client_phone', 'data.buyer.checkout_phone', 'data.buyer.phone']),
        'buyer_document' => fp_scalar_path($payload, ['client.document', 'buyer.document', 'customer.document', 'document', 'client_document']),
        'buyer_email' => strtolower(fp_scalar_path($payload, ['client.email', 'buyer.email', 'customer.email', 'email', 'client_email', 'data.buyer.email'])),
        'buyer_name' => fp_scalar_path($payload, ['client.name', 'buyer.name', 'customer.name', 'name', 'client_name', 'data.buyer.name']),
        'product_name' => fp_scalar_path($payload, ['product.name', 'product_name', 'product_first', 'item_name', 'data.product.name']),
        'payment_method' => fp_scalar_path($payload, ['payment_method', 'type_payment', 'payment.type', 'data.purchase.payment.type']),
        'checkout_id' => fp_scalar_path($payload, ['checkout_id', 'checkout.id', 'price_code']),
        'product_id' => fp_scalar_path($payload, ['product.id', 'product_id', 'external_product_id', 'data.product.id']),
        'amount_total' => fp_scalar_path($payload, ['total', 'price', 'amount', 'data.purchase.price.value']),
        'currency' => fp_scalar_path($payload, ['price_currency', 'currency', 'data.purchase.price.currency_code']),
        'paid_at' => fp_scalar_path($payload, ['paid_date', 'paid_at', 'payment_confirmed_at', 'data.purchase.approved_date']),
        'sale_created_at' => fp_scalar_path($payload, ['create_date', 'created_at', 'order_date', 'data.purchase.order_date']),
    ];
}

function fp_reduce_payload(array $payload): array
{
    $keep = [
        'id', 'checkout_id', 'type', 'status', 'payment_method', 'payment_gateway',
        'price_currency', 'price', 'product_price', 'interest_fee', 'installments',
        'tenant_id', 'link', 'id_transaction', 'order_id', 'type_status', 'status_type',
        'client_name', 'client_email', 'client_phone', 'client_document', 'product_first',
        'item_name', 'total', 'paid_date', 'create_date',
    ];
    $out = [];
    foreach ($keep as $key) {
        if (array_key_exists($key, $payload)) $out[$key] = $payload[$key];
    }

    foreach (['product', 'client', 'origin', 'buyer', 'customer', 'data'] as $root) {
        if (isset($payload[$root]) && is_array($payload[$root])) {
            $out[$root] = $payload[$root];
        }
    }

    if (isset($payload['order_bumps']) && is_array($payload['order_bumps'])) {
        $out['order_bumps'] = $payload['order_bumps'];
    }
    if (isset($payload['order_bump']) && is_array($payload['order_bump'])) {
        $out['order_bump'] = $payload['order_bump'];
    }
    return $out;
}

function fp_json($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function fp_dt_br(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i:s', $ts) : $value;
}

function fp_save_inbound(PDO $pdo, string $raw, array $parsed): int
{
    $fields = fp_extract_fields($parsed['data']);
    $stmt = $pdo->prepare("
        INSERT INTO inbound_logs
            (request_uuid, method, content_type, content_length, remote_addr, user_agent, headers_json, query_json,
             body_raw, body_sha256, parsed_type, parsed_json, transaction_id, firepay_status, buyer_phone,
             buyer_document, buyer_email, buyer_name, product_name, payment_method, checkout_id, product_id,
             amount_total, currency, paid_at, sale_created_at, received_at)
        VALUES
            (:request_uuid, :method, :content_type, :content_length, :remote_addr, :user_agent, :headers_json, :query_json,
             :body_raw, :body_sha256, :parsed_type, :parsed_json, :transaction_id, :firepay_status, :buyer_phone,
             :buyer_document, :buyer_email, :buyer_name, :product_name, :payment_method, :checkout_id, :product_id,
             :amount_total, :currency, :paid_at, :sale_created_at, :received_at)
    ");
    $stmt->execute([
        ':request_uuid' => fp_uuid(),
        ':method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
        ':content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        ':content_length' => (string)($_SERVER['CONTENT_LENGTH'] ?? ''),
        ':remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ':user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':headers_json' => fp_json(fp_headers()),
        ':query_json' => fp_json($_GET),
        ':body_raw' => $raw,
        ':body_sha256' => $raw !== '' ? hash('sha256', $raw) : null,
        ':parsed_type' => (string)$parsed['type'],
        ':parsed_json' => fp_json($parsed['data']),
        ':transaction_id' => $fields['transaction_id'] ?: null,
        ':firepay_status' => $fields['firepay_status'] ?: null,
        ':buyer_phone' => $fields['buyer_phone'] ?: null,
        ':buyer_document' => $fields['buyer_document'] ?: null,
        ':buyer_email' => $fields['buyer_email'] ?: null,
        ':buyer_name' => $fields['buyer_name'] ?: null,
        ':product_name' => $fields['product_name'] ?: null,
        ':payment_method' => $fields['payment_method'] ?: null,
        ':checkout_id' => $fields['checkout_id'] ?: null,
        ':product_id' => $fields['product_id'] ?: null,
        ':amount_total' => $fields['amount_total'] ?: null,
        ':currency' => $fields['currency'] ?: null,
        ':paid_at' => $fields['paid_at'] ?: null,
        ':sale_created_at' => $fields['sale_created_at'] ?: null,
        ':received_at' => fp_now(),
    ]);
    return (int)$pdo->lastInsertId();
}

function fp_enqueue(PDO $pdo, int $inboundId, array $payload): int
{
    $mode = fp_get_setting($pdo, 'forward_mode', 'sanitized');
    $targetUrl = fp_get_setting($pdo, 'target_url', FP_RELAY_DEFAULT_TARGET);
    $forwardPayload = $mode === 'raw' ? $payload : fp_reduce_payload($payload);
    $now = fp_now();

    $stmt = $pdo->prepare("
        INSERT INTO dispatch_queue
            (inbound_id, target_url, payload_json, status, attempts, max_attempts, next_attempt_at, created_at, updated_at)
        VALUES
            (:inbound_id, :target_url, :payload_json, 'pending', 0, 30, :next_attempt_at, :created_at, :updated_at)
    ");
    $stmt->execute([
        ':inbound_id' => $inboundId,
        ':target_url' => $targetUrl,
        ':payload_json' => fp_json($forwardPayload),
        ':next_attempt_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function fp_forward(string $targetUrl, string $json): array
{
    $started = microtime(true);
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl indisponivel', 'duration_ms' => 0];
    }

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FirepayRelaySite/' . FP_RELAY_VERSION,
            'X-Firepay-Bridge: professoremersonleite.site',
            'X-Firepay-Relay-Version: ' . FP_RELAY_VERSION,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = (string)curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300 && $error === '',
        'status' => $status,
        'body' => $body,
        'error' => $error,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
    ];
}

function fp_process_queue(PDO $pdo, int $limit = FP_RELAY_MAX_ATTEMPTS_PER_RUN): array
{
    $now = fp_now();
    fp_set_setting($pdo, 'last_queue_run_at', $now);
    $stmt = $pdo->prepare("
        SELECT * FROM dispatch_queue
        WHERE status IN ('pending','retry')
          AND next_attempt_at <= :now
          AND attempts < max_attempts
        ORDER BY next_attempt_at ASC, id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':now', $now);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $stats = ['checked' => count($rows), 'sent' => 0, 'retry' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        $queueId = (int)$row['id'];
        $attempts = (int)$row['attempts'] + 1;
        $result = fp_forward((string)$row['target_url'], (string)$row['payload_json']);
        $nowAttempt = fp_now();

        $log = $pdo->prepare("
            INSERT INTO outbound_logs
                (queue_id, inbound_id, target_url, payload_json, http_status, ok, error, response_body, duration_ms, attempted_at)
            VALUES
                (:queue_id, :inbound_id, :target_url, :payload_json, :http_status, :ok, :error, :response_body, :duration_ms, :attempted_at)
        ");
        $log->execute([
            ':queue_id' => $queueId,
            ':inbound_id' => (int)$row['inbound_id'],
            ':target_url' => (string)$row['target_url'],
            ':payload_json' => (string)$row['payload_json'],
            ':http_status' => $result['status'] ?: null,
            ':ok' => $result['ok'] ? 1 : 0,
            ':error' => $result['error'] ?: null,
            ':response_body' => substr((string)$result['body'], 0, 5000),
            ':duration_ms' => (int)$result['duration_ms'],
            ':attempted_at' => $nowAttempt,
        ]);

        if ($result['ok']) {
            $upd = $pdo->prepare("
                UPDATE dispatch_queue
                SET status='sent', attempts=:attempts, last_attempt_at=:last_attempt_at, sent_at=:sent_at,
                    last_http_status=:http_status, last_error=NULL, last_response_body=:response_body, updated_at=:updated_at
                WHERE id=:id
            ");
            $upd->execute([
                ':attempts' => $attempts,
                ':last_attempt_at' => $nowAttempt,
                ':sent_at' => $nowAttempt,
                ':http_status' => $result['status'] ?: null,
                ':response_body' => substr((string)$result['body'], 0, 5000),
                ':updated_at' => $nowAttempt,
                ':id' => $queueId,
            ]);
            $stats['sent']++;
            continue;
        }

        $finalFailed = $attempts >= (int)$row['max_attempts'];
        $nextAttempt = date('Y-m-d H:i:s', time() + 60);
        $upd = $pdo->prepare("
            UPDATE dispatch_queue
            SET status=:status, attempts=:attempts, last_attempt_at=:last_attempt_at, next_attempt_at=:next_attempt_at,
                last_http_status=:http_status, last_error=:error, last_response_body=:response_body, updated_at=:updated_at
            WHERE id=:id
        ");
        $upd->execute([
            ':status' => $finalFailed ? 'failed' : 'retry',
            ':attempts' => $attempts,
            ':last_attempt_at' => $nowAttempt,
            ':next_attempt_at' => $nextAttempt,
            ':http_status' => $result['status'] ?: null,
            ':error' => $result['error'] ?: ('HTTP ' . (string)$result['status']),
            ':response_body' => substr((string)$result['body'], 0, 5000),
            ':updated_at' => $nowAttempt,
            ':id' => $queueId,
        ]);
        $stats[$finalFailed ? 'failed' : 'retry']++;
    }
    return $stats;
}

function fp_response_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo fp_json($payload);
    exit;
}

function fp_receive(): void
{
    $pdo = fp_db();
    $raw = fp_read_body();
    $parsed = fp_parse_payload($raw);
    $inboundId = fp_save_inbound($pdo, $raw, $parsed);
    $queueId = fp_enqueue($pdo, $inboundId, is_array($parsed['data']) ? $parsed['data'] : ['raw' => $raw]);

    $stats = fp_process_queue($pdo, 5);
    fp_response_json(200, [
        'ok' => true,
        'relay' => true,
        'version' => FP_RELAY_VERSION,
        'inbound_id' => $inboundId,
        'queue_id' => $queueId,
        'queue_processed' => $stats,
    ]);
}

function fp_status_badge(string $status): string
{
    $colors = [
        'sent' => '#15803d',
        'pending' => '#ca8a04',
        'retry' => '#c2410c',
        'failed' => '#b91c1c',
        'paid' => '#15803d',
        'waiting' => '#ca8a04',
        'abandoned' => '#64748b',
        'recebimento' => '#2563eb',
        'envio' => '#7c3aed',
        'error' => '#b91c1c',
    ];
    $color = $colors[strtolower($status)] ?? '#475569';
    return '<span class="badge" style="border-color:' . $color . ';color:' . $color . '">' . fp_html($status !== '' ? $status : '-') . '</span>';
}

function fp_dashboard(): void
{
    $pdo = fp_db();
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $eventType = trim((string)($_GET['event_type'] ?? 'all'));
    if (!in_array($eventType, ['all', 'inbound', 'outbound'], true)) $eventType = 'all';
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    $timeFrom = trim((string)($_GET['time_from'] ?? ''));
    $timeTo = trim((string)($_GET['time_to'] ?? ''));
    $queueStatus = trim((string)($_GET['queue_status'] ?? ''));

    $inParams = [];
    $inWhere = [];
    $outParams = [];
    $outWhere = [];
    if ($date !== '') {
        $inWhere[] = "date(i.received_at) = :in_date";
        $outWhere[] = "date(o.attempted_at) = :out_date";
        $inParams[':in_date'] = $date;
        $outParams[':out_date'] = $date;
    }
    if ($timeFrom !== '') {
        $inWhere[] = "time(i.received_at) >= :in_time_from";
        $outWhere[] = "time(o.attempted_at) >= :out_time_from";
        $inParams[':in_time_from'] = $timeFrom . ':00';
        $outParams[':out_time_from'] = $timeFrom . ':00';
    }
    if ($timeTo !== '') {
        $inWhere[] = "time(i.received_at) <= :in_time_to";
        $outWhere[] = "time(o.attempted_at) <= :out_time_to";
        $inParams[':in_time_to'] = $timeTo . ':59';
        $outParams[':out_time_to'] = $timeTo . ':59';
    }
    if ($q !== '') {
        $search = '%' . $q . '%';
        $inWhere[] = "(i.transaction_id LIKE :in_q OR i.buyer_email LIKE :in_q OR i.buyer_name LIKE :in_q OR i.product_name LIKE :in_q OR i.buyer_phone LIKE :in_q OR i.buyer_document LIKE :in_q)";
        $outWhere[] = "(i.transaction_id LIKE :out_q OR i.buyer_email LIKE :out_q OR i.buyer_name LIKE :out_q OR i.product_name LIKE :out_q OR i.buyer_phone LIKE :out_q OR i.buyer_document LIKE :out_q)";
        $inParams[':in_q'] = $search;
        $outParams[':out_q'] = $search;
    }
    if ($status !== '') {
        $inWhere[] = "LOWER(COALESCE(i.firepay_status,'')) = LOWER(:in_status)";
        $outWhere[] = "(LOWER(COALESCE(i.firepay_status,'')) = LOWER(:out_status) OR LOWER(CASE WHEN o.ok=1 THEN 'sent' ELSE 'error' END) = LOWER(:out_status) OR CAST(o.http_status AS TEXT) = :out_status)";
        $inParams[':in_status'] = $status;
        $outParams[':out_status'] = $status;
    }
    if ($queueStatus !== '') {
        $inWhere[] = "q.status = :in_queue_status";
        $outWhere[] = "q.status = :out_queue_status";
        $inParams[':in_queue_status'] = $queueStatus;
        $outParams[':out_queue_status'] = $queueStatus;
    }

    $inWhereSql = $inWhere ? 'WHERE ' . implode(' AND ', $inWhere) : '';
    $outWhereSql = $outWhere ? 'WHERE ' . implode(' AND ', $outWhere) : '';
    $parts = [];
    $params = [];
    if ($eventType !== 'outbound') {
        $parts[] = "
            SELECT 'inbound' AS log_type, i.id AS log_id, i.received_at AS log_at,
                   i.transaction_id, i.firepay_status, i.buyer_name, i.buyer_email, i.buyer_phone, i.buyer_document,
                   i.product_name, i.payment_method, i.checkout_id, i.product_id, i.amount_total, i.currency, i.paid_at, i.sale_created_at,
                   i.parsed_type, i.remote_addr, i.parsed_json AS detail_json, i.body_raw,
                   q.id AS queue_id, q.status AS queue_status, q.attempts, q.next_attempt_at, q.sent_at,
                   q.last_http_status AS http_status, q.last_error AS error, q.last_response_body AS response_body, q.target_url,
                   NULL AS outbound_id, NULL AS duration_ms
            FROM inbound_logs i
            LEFT JOIN dispatch_queue q ON q.inbound_id = i.id
            $inWhereSql
        ";
        $params += $inParams;
    }
    if ($eventType !== 'inbound') {
        $parts[] = "
            SELECT 'outbound' AS log_type, o.id AS log_id, o.attempted_at AS log_at,
                   i.transaction_id, i.firepay_status, i.buyer_name, i.buyer_email, i.buyer_phone, i.buyer_document,
                   i.product_name, i.payment_method, i.checkout_id, i.product_id, i.amount_total, i.currency, i.paid_at, i.sale_created_at,
                   i.parsed_type, i.remote_addr, o.payload_json AS detail_json, i.body_raw,
                   q.id AS queue_id, q.status AS queue_status, q.attempts, q.next_attempt_at, q.sent_at,
                   o.http_status AS http_status, o.error AS error, o.response_body AS response_body, o.target_url,
                   o.id AS outbound_id, o.duration_ms AS duration_ms
            FROM outbound_logs o
            JOIN inbound_logs i ON i.id = o.inbound_id
            JOIN dispatch_queue q ON q.id = o.queue_id
            $outWhereSql
        ";
        $params += $outParams;
    }
    $sql = implode("\nUNION ALL\n", $parts) . "\nORDER BY log_at DESC, log_id DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $kpis = [
        'inbound' => (int)$pdo->query("SELECT COUNT(*) FROM inbound_logs")->fetchColumn(),
        'pending' => (int)$pdo->query("SELECT COUNT(*) FROM dispatch_queue WHERE status IN ('pending','retry')")->fetchColumn(),
        'sent' => (int)$pdo->query("SELECT COUNT(*) FROM dispatch_queue WHERE status='sent'")->fetchColumn(),
        'failed' => (int)$pdo->query("SELECT COUNT(*) FROM dispatch_queue WHERE status='failed'")->fetchColumn(),
    ];
    $lastCronAt = fp_get_setting($pdo, 'last_queue_run_at', '');

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firepay Relay</title>
<style>
body{margin:0;background:#f3f6fb;color:#172033;font-family:Inter,Arial,sans-serif}
.wrap{max-width:1440px;margin:0 auto;padding:22px}
.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}
h1{font-size:22px;margin:0}.muted{color:#64748b;font-size:12px}
.btn{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:7px;padding:8px 11px;background:#fff;color:#172033;text-decoration:none;font-size:12px;font-weight:700}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}.kpi{background:#fff;border:1px solid #dbe3ef;border-radius:8px;padding:12px}.kpi small{color:#64748b;text-transform:uppercase;font-size:10px}.kpi strong{display:block;font-size:24px;margin-top:4px}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;padding:12px;margin-bottom:12px}
.filters{display:grid;grid-template-columns:130px 105px 105px 130px 1fr 145px 130px auto;gap:8px;align-items:end}
label{display:block;font-size:10px;text-transform:uppercase;color:#64748b;font-weight:800;margin-bottom:4px}
input,select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:9px 10px;background:#fff;color:#172033}
table{width:100%;border-collapse:collapse;background:#fff}th,td{border-bottom:1px solid #e5eaf2;padding:9px 8px;text-align:left;font-size:12px;vertical-align:top}th{font-size:10px;color:#64748b;text-transform:uppercase;background:#f8fafc}
.badge{display:inline-flex;border:1px solid;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:800;background:#fff}
details summary{cursor:pointer;color:#2563eb;font-weight:700}pre{max-width:680px;max-height:300px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:7px;font-size:11px;white-space:pre-wrap}
.actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:900px){.grid,.filters{grid-template-columns:1fr}.top{display:block}.actions{margin-top:10px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1>Firepay Relay</h1>
      <div class="muted">Recepcao, banco, fila e reenvio - versao <?= fp_html(FP_RELAY_VERSION) ?></div>
      <div class="muted">Ultimo cron/processamento: <strong><?= fp_html(fp_dt_br($lastCronAt)) ?></strong></div>
    </div>
    <div class="actions">
      <a class="btn" href="?process_queue=1&dashboard=1">Processar fila</a>
      <a class="btn" href="?config=1">Configuracao</a>
      <a class="btn" href="?dashboard=1">Atualizar</a>
    </div>
  </div>

  <div class="grid">
    <div class="kpi"><small>Entradas</small><strong><?= $kpis['inbound'] ?></strong></div>
    <div class="kpi"><small>Na fila</small><strong><?= $kpis['pending'] ?></strong></div>
    <div class="kpi"><small>Enviados</small><strong><?= $kpis['sent'] ?></strong></div>
    <div class="kpi"><small>Falhas finais</small><strong><?= $kpis['failed'] ?></strong></div>
  </div>

  <div class="panel">
    <form class="filters" method="get">
      <input type="hidden" name="dashboard" value="1">
      <div><label>Data</label><input type="date" name="date" value="<?= fp_html($date) ?>"></div>
      <div><label>Hora inicial</label><input type="time" name="time_from" value="<?= fp_html($timeFrom) ?>"></div>
      <div><label>Hora final</label><input type="time" name="time_to" value="<?= fp_html($timeTo) ?>"></div>
      <div><label>Tipo log</label><select name="event_type"><option value="all" <?= $eventType === 'all' ? 'selected' : '' ?>>Entrada e saida</option><option value="inbound" <?= $eventType === 'inbound' ? 'selected' : '' ?>>Recebimentos</option><option value="outbound" <?= $eventType === 'outbound' ? 'selected' : '' ?>>Envios</option></select></div>
      <div><label>Busca</label><input name="q" value="<?= fp_html($q) ?>" placeholder="email, nome, telefone, documento, transacao, produto"></div>
      <div><label>Status</label><input name="status" value="<?= fp_html($status) ?>" placeholder="paid, waiting, abandoned, sent, 422"></div>
      <div><label>Status fila</label><select name="queue_status"><option value="">Todos</option><?php foreach (['pending','retry','sent','failed'] as $s): ?><option value="<?= $s ?>" <?= $queueStatus === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
      <button class="btn" type="submit">Filtrar</button>
    </form>
  </div>

  <div class="panel" style="overflow:auto">
    <table>
      <thead><tr><th>Hora</th><th>Tipo</th><th>Transacao</th><th>Comprador</th><th>Produto/Pagamento</th><th>Status</th><th>Fila/Saida</th><th>Detalhes</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><strong><?= fp_html(fp_dt_br((string)$row['log_at'])) ?></strong><div class="muted">#<?= (int)$row['log_id'] ?> / fila <?= fp_html((string)($row['queue_id'] ?? '-')) ?></div></td>
          <td>
            <?= fp_status_badge($row['log_type'] === 'inbound' ? 'recebimento' : 'envio') ?>
            <div class="muted"><?= fp_html((string)$row['parsed_type']) ?> - <?= fp_html((string)$row['remote_addr']) ?></div>
          </td>
          <td>
            <strong><?= fp_html((string)($row['transaction_id'] ?? '-')) ?></strong>
            <div class="muted">checkout: <?= fp_html((string)($row['checkout_id'] ?? '-')) ?></div>
            <div class="muted">produto id: <?= fp_html((string)($row['product_id'] ?? '-')) ?></div>
          </td>
          <td>
            <strong><?= fp_html((string)($row['buyer_name'] ?? '-')) ?></strong>
            <div><?= fp_html((string)($row['buyer_email'] ?? '-')) ?></div>
            <div class="muted"><?= fp_html((string)($row['buyer_phone'] ?? '-')) ?></div>
            <div class="muted"><?= fp_html((string)($row['buyer_document'] ?? '-')) ?></div>
          </td>
          <td>
            <strong><?= fp_html((string)($row['product_name'] ?? '-')) ?></strong>
            <div class="muted"><?= fp_html((string)($row['payment_method'] ?? '-')) ?> / <?= fp_html((string)($row['currency'] ?? '')) ?> <?= fp_html((string)($row['amount_total'] ?? '-')) ?></div>
            <div class="muted">criado: <?= fp_html(fp_dt_br((string)($row['sale_created_at'] ?? ''))) ?></div>
            <div class="muted">pago: <?= fp_html(fp_dt_br((string)($row['paid_at'] ?? ''))) ?></div>
          </td>
          <td>
            <?= fp_status_badge((string)($row['firepay_status'] ?? '')) ?>
            <?php if ($row['log_type'] === 'outbound'): ?><div><?= fp_status_badge((int)($row['http_status'] ?? 0) >= 200 && (int)($row['http_status'] ?? 0) < 300 ? 'sent' : 'error') ?></div><?php endif; ?>
          </td>
          <td>
            <?= fp_status_badge((string)($row['queue_status'] ?? 'sem fila')) ?>
            <div class="muted">tentativas: <?= (int)($row['attempts'] ?? 0) ?></div>
            <div class="muted">proxima: <?= fp_html((string)($row['next_attempt_at'] ?? '-')) ?></div>
            <div>HTTP: <strong><?= fp_html((string)($row['http_status'] ?? '-')) ?></strong></div>
            <div class="muted">enviado: <?= fp_html(fp_dt_br((string)($row['sent_at'] ?? ''))) ?></div>
            <?php if (!empty($row['error'])): ?><div style="color:#b91c1c"><?= fp_html((string)$row['error']) ?></div><?php endif; ?>
            <?php if (!empty($row['duration_ms'])): ?><div class="muted"><?= (int)$row['duration_ms'] ?> ms</div><?php endif; ?>
          </td>
          <td>
            <details><summary><?= $row['log_type'] === 'inbound' ? 'Payload recebido' : 'Payload enviado' ?></summary><pre><?= fp_html(json_encode(json_decode((string)$row['detail_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string)$row['detail_json']) ?></pre></details>
            <?php if ($row['log_type'] === 'outbound'): ?><details><summary>Resposta destino</summary><pre><?= fp_html((string)($row['response_body'] ?? '')) ?></pre><div class="muted"><?= fp_html((string)($row['target_url'] ?? '')) ?></div></details><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8">Nenhum registro encontrado.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
<?php
}

function fp_config_page(): void
{
    $pdo = fp_db();
    $saved = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        fp_set_setting($pdo, 'target_url', trim((string)($_POST['target_url'] ?? FP_RELAY_DEFAULT_TARGET)));
        fp_set_setting($pdo, 'receiver_url', trim((string)($_POST['receiver_url'] ?? fp_current_receiver_url())));
        $mode = (string)($_POST['forward_mode'] ?? 'sanitized');
        fp_set_setting($pdo, 'forward_mode', in_array($mode, ['sanitized', 'raw'], true) ? $mode : 'sanitized');
        $saved = true;
    }
    $targetUrl = fp_get_setting($pdo, 'target_url', FP_RELAY_DEFAULT_TARGET);
    $receiverUrl = fp_get_setting($pdo, 'receiver_url', fp_current_receiver_url());
    $mode = fp_get_setting($pdo, 'forward_mode', 'sanitized');

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuracao - Firepay Relay</title>
<style>
body{margin:0;background:#f3f6fb;color:#172033;font-family:Inter,Arial,sans-serif}.wrap{max-width:860px;margin:0 auto;padding:24px}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;padding:18px}h1{font-size:22px;margin:0 0 14px}.row{margin-bottom:14px}label{display:block;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:800;margin-bottom:5px}input,select{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:7px;padding:10px}.btn{display:inline-flex;border:1px solid #cbd5e1;border-radius:7px;padding:9px 12px;background:#fff;color:#172033;text-decoration:none;font-size:12px;font-weight:800}.ok{padding:10px;border:1px solid #86efac;background:#dcfce7;color:#166534;border-radius:7px;margin-bottom:12px}.muted{color:#64748b;font-size:12px}.help{margin-top:8px;padding:10px;border:1px solid #dbe3ef;border-radius:7px;background:#f8fafc;color:#475569;font-size:12px;line-height:1.45}
</style>
</head>
<body>
<div class="wrap">
  <div class="panel">
    <h1>Configuracao do Firepay Relay</h1>
    <?php if ($saved): ?><div class="ok">Configuracao salva.</div><?php endif; ?>
    <form method="post">
      <div class="row">
        <label>Link de recepcao</label>
        <input name="receiver_url" value="<?= fp_html($receiverUrl) ?>">
        <div class="muted">Use este link na Firepay.</div>
      </div>
      <div class="row">
        <label>Link de retransmissao</label>
        <input name="target_url" value="<?= fp_html($targetUrl) ?>">
        <div class="muted">Destino que recebera o JSON reenviado.</div>
      </div>
      <div class="row">
        <label>Modo de envio</label>
        <select name="forward_mode">
          <option value="sanitized" <?= $mode === 'sanitized' ? 'selected' : '' ?>>Sanitizado para o sistema atual</option>
          <option value="raw" <?= $mode === 'raw' ? 'selected' : '' ?>>JSON completo parseado</option>
        </select>
        <div class="help">
          <strong>Sanitizado para o sistema atual:</strong> encaminha apenas os campos que o processador atual usa, reduzindo risco de bloqueio por WAF/ModSecurity e mantendo compatibilidade com o endpoint da area de membros.<br>
          <strong>JSON completo parseado:</strong> encaminha tudo que chegou depois de interpretar JSON/formulario. Use para depuracao ou quando o destino precisa do payload integral.
        </div>
      </div>
      <button class="btn" type="submit">Salvar</button>
      <a class="btn" href="?dashboard=1">Voltar ao dashboard</a>
    </form>
  </div>
</div>
</body>
</html>
<?php
}

function fp_home(): void
{
    fp_response_json(200, [
        'ok' => true,
        'relay' => true,
        'version' => FP_RELAY_VERSION,
        'message' => 'Firepay Relay ativo. Envie webhooks por POST.',
        'receiver_url' => fp_get_setting(fp_db(), 'receiver_url', fp_current_receiver_url()),
        'dashboard' => fp_current_receiver_url() . '?dashboard=1',
        'config' => fp_current_receiver_url() . '?config=1',
    ]);
}

try {
    $isCli = PHP_SAPI === 'cli';
    $cliAction = $isCli ? (string)($argv[1] ?? '') : '';
    if ($cliAction === 'process_queue' || isset($_GET['process_queue'])) {
        $stats = fp_process_queue(fp_db());
        if (isset($_GET['dashboard'])) {
            header('Location: ?dashboard=1');
            exit;
        }
        fp_response_json(200, ['ok' => true, 'processed' => $stats]);
    }
    if (!$isCli && isset($_GET['dashboard'])) {
        fp_dashboard();
        exit;
    }
    if (!$isCli && isset($_GET['config'])) {
        fp_config_page();
        exit;
    }
    if (!$isCli && in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) {
        fp_home();
    }
    fp_receive();
} catch (Throwable $e) {
    fp_response_json(500, ['ok' => false, 'error' => $e->getMessage()]);
}
