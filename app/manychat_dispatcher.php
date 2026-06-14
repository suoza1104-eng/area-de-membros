<?php
// FILE: app/manychat_dispatcher.php
declare(strict_types=1);

/**
 * Integracao Manychat.
 *
 * A API publica do Manychat usa Bearer token e trabalha com subscriber_id.
 * Este dispatcher tenta encontrar/criar o subscriber por email/telefone e,
 * conforme a regra do evento, aplica tags, campos personalizados e flows.
 */

function mc_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS manychat_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            base_url VARCHAR(255) NOT NULL DEFAULT 'https://api.manychat.com',
            token VARCHAR(500) NOT NULL DEFAULT '',
            timeout_seconds INT NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS manychat_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL DEFAULT '',
            evento VARCHAR(80) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            tags_text TEXT NULL,
            flows_text TEXT NULL,
            fields_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mc_rules_evento (evento),
            KEY idx_mc_rules_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS manychat_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evento VARCHAR(80) NOT NULL,
            rule_id INT NULL,
            action VARCHAR(60) NOT NULL DEFAULT '',
            ok TINYINT(1) NOT NULL DEFAULT 0,
            http_status INT NULL,
            subscriber_id VARCHAR(80) NULL,
            error_text TEXT NULL,
            request_json LONGTEXT NULL,
            response_text LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mc_logs_evento (evento),
            KEY idx_mc_logs_rule (rule_id),
            KEY idx_mc_logs_ok (ok),
            KEY idx_mc_logs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function mc_get_config(PDO $pdo): array
{
    mc_ensure_tables($pdo);
    $row = $pdo->query("SELECT * FROM manychat_config ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        $pdo->exec("INSERT INTO manychat_config (is_enabled, base_url, token, timeout_seconds) VALUES (0, 'https://api.manychat.com', '', 10)");
        $row = $pdo->query("SELECT * FROM manychat_config ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $cfg = [
        'is_enabled' => (int)($row['is_enabled'] ?? 0),
        'base_url' => trim((string)($row['base_url'] ?? 'https://api.manychat.com')),
        'token' => trim((string)($row['token'] ?? '')),
        'timeout_seconds' => (int)($row['timeout_seconds'] ?? 10),
    ];
    if ($cfg['base_url'] === '') $cfg['base_url'] = 'https://api.manychat.com';
    if ($cfg['timeout_seconds'] <= 0) $cfg['timeout_seconds'] = 10;
    return $cfg;
}

function mc_get_rules_for_event(PDO $pdo, string $evento): array
{
    mc_ensure_tables($pdo);
    $st = $pdo->prepare("SELECT * FROM manychat_rules WHERE is_active = 1 AND evento = :e ORDER BY id DESC");
    $st->execute([':e' => $evento]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mc_get_user_row(PDO $pdo, array $user): array
{
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) return $user;
    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (function_exists('gerar_magic_link')) {
                try { $row['magic_link'] = gerar_magic_link($id, 30, false); } catch (Throwable $e) {}
            }
            return $row;
        }
    } catch (Throwable $e) {}
    return $user;
}

function mc_get_value_by_path(array $data, string $path): ?string
{
    if ($path === '') return null;
    $cur = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return null;
        $cur = $cur[$part];
    }
    if ($cur === null) return null;
    if (is_bool($cur)) return $cur ? '1' : '0';
    if (is_scalar($cur)) return (string)$cur;
    return json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mc_resolve_source(PDO $pdo, string $source, array $userRow, array $extra, array $payload): ?string
{
    $source = trim($source);
    if ($source === '') return null;
    if (strncmp($source, 'literal:', 8) === 0) return substr($source, 8);

    if (strpos($source, '{{') !== false) {
        $value = preg_replace_callback('/\{\{([^}]+)\}\}/', static function (array $m) use ($pdo, $userRow, $extra, $payload): string {
            return mc_resolve_source($pdo, trim($m[1]), $userRow, $extra, $payload) ?? '';
        }, $source);
        return trim((string)$value) !== '' ? (string)$value : null;
    }

    if (strpos($source, '|') !== false) {
        foreach (explode('|', $source) as $alt) {
            $value = mc_resolve_source($pdo, trim($alt), $userRow, $extra, $payload);
            if ($value !== null && $value !== '') return $value;
        }
        return null;
    }

    if (strpos($source, '.') !== false) {
        [$root, $path] = explode('.', $source, 2);
        $root = strtolower($root);
        if ($root === 'user' || $root === 'users') return mc_get_value_by_path($userRow, $path);
        if ($root === 'extra') return mc_get_value_by_path($extra, $path);
        if ($root === 'payload') return mc_get_value_by_path($payload, $path);
    }

    if (array_key_exists($source, $extra)) {
        $v = $extra[$source];
        return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (array_key_exists($source, $userRow)) return (string)$userRow[$source];
    if (array_key_exists($source, $payload)) {
        $v = $payload[$source];
        return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return null;
}

function mc_build_custom_fields(PDO $pdo, array $pairs, array $userRow, array $extra, array $payload): array
{
    $fields = [];
    $resolved = [];
    $skipped = [];
    foreach ($pairs as $p) {
        $src = trim((string)($p['source'] ?? ''));
        $dst = trim((string)($p['dest'] ?? ''));
        if ($src === '' || $dst === '') continue;
        $value = mc_resolve_source($pdo, $src, $userRow, $extra, $payload);
        if ($value === null) {
            $skipped[] = $dst . '(' . $src . ')';
            continue;
        }
        $fields[] = ['field_name' => $dst, 'field_value' => $value];
        $resolved[] = $dst;
    }
    return ['fields' => $fields, 'resolved_keys' => $resolved, 'skipped_keys' => $skipped];
}

function mc_http_json(string $method, string $url, string $token, array $body, int $timeoutSeconds): array
{
    $method = strtoupper($method);
    $payload = $body ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_NOSIGNAL => 1,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => ($err === '' && $code >= 200 && $code < 300),
        'http_status' => $code,
        'error' => $err,
        'response' => is_string($resp) ? $resp : '',
        'request_json' => $payload,
    ];
}

function mc_api(PDO $pdo, array $cfg, string $evento, ?int $ruleId, string $action, string $method, string $path, array $body = [], ?string $subscriberId = null, array $logContext = []): array
{
    $base = rtrim((string)$cfg['base_url'], '/');
    $url = $base . $path;
    $res = mc_http_json($method, $url, (string)$cfg['token'], $body, (int)$cfg['timeout_seconds']);
    $logRequest = $body ?: ['method' => $method, 'url' => $url];
    if ($logContext) $logRequest['_context'] = $logContext;
    mc_log($pdo, $evento, $ruleId, $action, (bool)$res['ok'], (int)$res['http_status'], (string)$subscriberId, (string)$res['error'], $logRequest, (string)$res['response']);
    return $res;
}

function mc_response_data(array $res): array
{
    $json = json_decode((string)($res['response'] ?? ''), true);
    return is_array($json) ? $json : [];
}

function mc_extract_subscriber_id(array $data): string
{
    foreach ([
        ['data', 'id'],
        ['data', 'subscriber_id'],
        ['id'],
        ['subscriber_id'],
    ] as $path) {
        $v = $data;
        foreach ($path as $key) {
            if (!is_array($v) || !array_key_exists($key, $v)) { $v = null; break; }
            $v = $v[$key];
        }
        if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
    }
    return '';
}

function mc_find_subscriber(PDO $pdo, array $cfg, string $evento, ?int $ruleId, string $field, string $value): string
{
    $field = $field === 'phone' ? 'phone' : 'email';
    $path = '/fb/subscriber/findBySystemField?' . $field . '=' . rawurlencode($value);
    $res = mc_api($pdo, $cfg, $evento, $ruleId, 'find_' . $field, 'GET', $path, []);
    if (!(bool)$res['ok']) return '';
    return mc_extract_subscriber_id(mc_response_data($res));
}

function mc_create_subscriber(PDO $pdo, array $cfg, string $evento, ?int $ruleId, array $userRow): string
{
    $name = trim((string)($userRow['nome'] ?? ''));
    $first = $name;
    $last = '';
    if (strpos($name, ' ') !== false) {
        $chunks = preg_split('/\s+/', $name);
        $first = array_shift($chunks) ?: $name;
        $last = implode(' ', $chunks);
    }
    $body = array_filter([
        'first_name' => $first !== '' ? $first : null,
        'last_name' => $last !== '' ? $last : null,
        'email' => trim((string)($userRow['email'] ?? '')) ?: null,
        'phone' => trim((string)($userRow['telefone'] ?? '')) ?: null,
    ], static fn($v) => $v !== null && $v !== '');
    if (!empty($body['email'])) $body['has_opt_in_email'] = false;
    if (!empty($body['phone'])) $body['has_opt_in_sms'] = false;
    if (empty($body['email']) && empty($body['phone'])) return '';
    $res = mc_api($pdo, $cfg, $evento, $ruleId, 'create_subscriber', 'POST', '/fb/subscriber/createSubscriber', $body);
    return mc_extract_subscriber_id(mc_response_data($res));
}

function mc_get_or_create_subscriber(PDO $pdo, array $cfg, string $evento, ?int $ruleId, array $userRow): string
{
    $email = trim((string)($userRow['email'] ?? ''));
    $phone = trim((string)($userRow['telefone'] ?? ''));
    if ($email !== '') {
        $id = mc_find_subscriber($pdo, $cfg, $evento, $ruleId, 'email', $email);
        if ($id !== '') return $id;
    }
    if ($phone !== '') {
        $id = mc_find_subscriber($pdo, $cfg, $evento, $ruleId, 'phone', $phone);
        if ($id !== '') return $id;
    }
    $id = mc_create_subscriber($pdo, $cfg, $evento, $ruleId, $userRow);
    if ($id !== '') return $id;
    if ($email !== '') return mc_find_subscriber($pdo, $cfg, $evento, $ruleId, 'email', $email);
    if ($phone !== '') return mc_find_subscriber($pdo, $cfg, $evento, $ruleId, 'phone', $phone);
    return '';
}

function mc_disparar_evento(PDO $pdo, string $evento, array $user, array $extra = []): bool
{
    $userId = isset($user['id']) ? (int)$user['id'] : 0;
    if ($userId > 0 && function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $userId)) {
        return false;
    }

    $cfg = mc_get_config($pdo);
    if ((int)$cfg['is_enabled'] !== 1 || $cfg['token'] === '') return false;
    $rules = mc_get_rules_for_event($pdo, $evento);
    if (!$rules) return false;

    $userRow = mc_get_user_row($pdo, $user);
    $payload = function_exists('build_webhook_payload')
        ? build_webhook_payload($evento, $userRow, $extra)
        : ['evento' => $evento, 'user' => $userRow, 'extra' => $extra, 'timestamp' => date('c')];

    $sentOk = false;
    foreach ($rules as $rule) {
        $ruleId = (int)($rule['id'] ?? 0);
        $subscriberId = mc_get_or_create_subscriber($pdo, $cfg, $evento, $ruleId, $userRow);
        if ($subscriberId === '') {
            mc_log($pdo, $evento, $ruleId, 'resolve_subscriber', false, null, '', 'Subscriber nao encontrado/criado', $payload, '');
            continue;
        }
        $logContext = [
            'user' => [
                'id' => $userRow['id'] ?? null,
                'nome' => $userRow['nome'] ?? null,
                'email' => $userRow['email'] ?? null,
                'telefone' => $userRow['telefone'] ?? null,
            ],
            'extra' => $extra,
        ];

        $tags = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string)($rule['tags_text'] ?? '')) ?: [])));
        $flows = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($rule['flows_text'] ?? '')) ?: [])));
        $pairs = [];
        $rawFields = trim((string)($rule['fields_json'] ?? ''));
        if ($rawFields !== '') {
            $tmp = json_decode($rawFields, true);
            if (is_array($tmp)) $pairs = $tmp;
        }
        $fieldResult = mc_build_custom_fields($pdo, $pairs, $userRow, $extra, $payload);

        foreach ($tags as $tag) {
            $res = mc_api($pdo, $cfg, $evento, $ruleId, 'add_tag', 'POST', '/fb/subscriber/addTagByName', [
                'subscriber_id' => $subscriberId,
                'tag_name' => $tag,
            ], $subscriberId, $logContext);
            if ((bool)$res['ok']) $sentOk = true;
        }

        if ($fieldResult['fields']) {
            $body = [
                'subscriber_id' => $subscriberId,
                'fields' => $fieldResult['fields'],
                '_debug' => [
                    'custom_fields_keys' => $fieldResult['resolved_keys'],
                    'skipped_keys' => $fieldResult['skipped_keys'],
                ],
            ];
            $sendBody = $body;
            unset($sendBody['_debug']);
            $res = mc_api($pdo, $cfg, $evento, $ruleId, 'set_custom_fields', 'POST', '/fb/subscriber/setCustomFields', $sendBody, $subscriberId, $logContext + [
                'custom_fields_keys' => $fieldResult['resolved_keys'],
                'skipped_keys' => $fieldResult['skipped_keys'],
            ]);
            if ((bool)$res['ok']) $sentOk = true;
        }

        foreach ($flows as $flowNs) {
            $res = mc_api($pdo, $cfg, $evento, $ruleId, 'send_flow', 'POST', '/fb/sending/sendFlow', [
                'subscriber_id' => $subscriberId,
                'flow_ns' => $flowNs,
            ], $subscriberId, $logContext);
            if ((bool)$res['ok']) $sentOk = true;
        }

        if (!$tags && !$flows && !$fieldResult['fields']) {
            mc_log($pdo, $evento, $ruleId, 'noop', true, null, $subscriberId, '', ['message' => 'Regra sem acoes configuradas', 'payload' => $payload], '');
        }
    }
    return $sentOk;
}

function mc_log(PDO $pdo, string $evento, ?int $ruleId, string $action, bool $ok, ?int $httpStatus, string $subscriberId, string $errorText, $request, string $responseText): void
{
    try {
        mc_ensure_tables($pdo);
        $st = $pdo->prepare("
            INSERT INTO manychat_logs (evento, rule_id, action, ok, http_status, subscriber_id, error_text, request_json, response_text, created_at)
            VALUES (:e, :rid, :act, :ok, :hs, :sid, :er, :rq, :rp, NOW())
        ");
        $st->execute([
            ':e' => $evento,
            ':rid' => $ruleId,
            ':act' => $action,
            ':ok' => $ok ? 1 : 0,
            ':hs' => $httpStatus,
            ':sid' => $subscriberId !== '' ? $subscriberId : null,
            ':er' => $errorText,
            ':rq' => is_string($request) ? $request : json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':rp' => $responseText,
        ]);
    } catch (Throwable $e) {}
}
