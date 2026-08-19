<?php
// FILE: app/superfuncionario_dispatcher.php
declare(strict_types=1);

/**
 * Integração SuperFuncionário (SF)
 * - Mantém credenciais globais (token/base_url/endpoint/timeout)
 * - Mantém regras por evento (gatilho) para:
 *    - criar/atualizar contato
 *    - atribuir tags
 *    - enviar campos personalizados
 *    - disparar fluxos
 *
 * IMPORTANTE:
 * - Este arquivo não depende do front. Ele é chamado automaticamente por disparar_webhooks().
 * - A tela de admin superfuncionario.php grava em tabelas próprias (superfuncionario_config / superfuncionario_rules).
 */

/** -----------------------------
 *  Tabelas (auto-create)
 * ------------------------------*/
function sf_ensure_tables(PDO $pdo): void
{
    // Config (1 linha é o padrão)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS superfuncionario_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            base_url VARCHAR(255) NOT NULL DEFAULT '',
            token VARCHAR(255) NOT NULL DEFAULT '',
            default_endpoint VARCHAR(255) NOT NULL DEFAULT '/api/contacts',
            header_mode VARCHAR(30) NOT NULL DEFAULT 'x-access-token', /* x-access-token | bearer */
            timeout_seconds INT NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Regras por evento
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS superfuncionario_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL DEFAULT '',
            evento VARCHAR(80) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            tags_text TEXT NULL,
            flows_text TEXT NULL, /* ids separados por vírgula */
            endpoint_override VARCHAR(255) NULL,
            fields_json LONGTEXT NULL, /* [{source:'user.email', dest:'EMAIL'}, ...] */
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_evento (evento),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Logs (para depurar)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS superfuncionario_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            evento VARCHAR(80) NOT NULL,
            rule_id INT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            http_status INT NULL,
            error_text TEXT NULL,
            request_json LONGTEXT NULL,
            response_text LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_evento (evento),
            KEY idx_ok (ok)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    sf_ensure_turma_columns($pdo);
}

function sf_ensure_turma_columns(PDO $pdo): void
{
    try {
        $pdo->query("SELECT id FROM turmas LIMIT 0");
    } catch (Throwable $e) {
        return;
    }

    $columns = [
        'sf_enabled'          => "ALTER TABLE turmas ADD COLUMN sf_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'sf_tags_text'        => "ALTER TABLE turmas ADD COLUMN sf_tags_text TEXT NULL",
        'sf_flows_text'       => "ALTER TABLE turmas ADD COLUMN sf_flows_text TEXT NULL",
        'sf_fields_json'      => "ALTER TABLE turmas ADD COLUMN sf_fields_json LONGTEXT NULL",
        'delay_ms'            => "ALTER TABLE turmas ADD COLUMN delay_ms INT NOT NULL DEFAULT 500",
        'live_filter_tag_ids' => "ALTER TABLE turmas ADD COLUMN live_filter_tag_ids LONGTEXT NULL",
        'live_disparo_data'   => "ALTER TABLE turmas ADD COLUMN live_disparo_data DATETIME NULL",
        'live_disparada'      => "ALTER TABLE turmas ADD COLUMN live_disparada TINYINT(1) NOT NULL DEFAULT 0",
    ];

    foreach ($columns as $name => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM turmas LIKE :col");
            $st->execute([':col' => $name]);
            if (!$st->fetch()) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
            // Let the save path report the real error if a required column is still missing.
        }
    }
}

/** -----------------------------
 *  Config e regras
 * ------------------------------*/
function sf_get_config(PDO $pdo): array
{
    sf_ensure_tables($pdo);

    $st = $pdo->query("SELECT * FROM superfuncionario_config ORDER BY id DESC LIMIT 1");
    $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;

    if (!$row) {
        // cria config padrão
        $pdo->exec("INSERT INTO superfuncionario_config (is_enabled, base_url, token, default_endpoint, header_mode, timeout_seconds)
                    VALUES (0,'','', '/api/contacts','x-access-token',10)");
        $st2 = $pdo->query("SELECT * FROM superfuncionario_config ORDER BY id DESC LIMIT 1");
        $row = $st2 ? ($st2->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    }

    $cfg = [
        'is_enabled'       => (int)($row['is_enabled'] ?? 0),
        'base_url'         => trim((string)($row['base_url'] ?? '')),
        'token'            => trim((string)($row['token'] ?? '')),
        'default_endpoint' => trim((string)($row['default_endpoint'] ?? '/api/contacts')),
        'header_mode'      => trim((string)($row['header_mode'] ?? 'x-access-token')),
        'timeout_seconds'  => (int)($row['timeout_seconds'] ?? 10),
    ];

    if ($cfg['default_endpoint'] === '') $cfg['default_endpoint'] = '/api/contacts';
    if (!in_array($cfg['header_mode'], ['x-access-token','bearer'], true)) $cfg['header_mode'] = 'x-access-token';
    if ($cfg['timeout_seconds'] <= 0) $cfg['timeout_seconds'] = 10;

    return $cfg;
}

function sf_get_rules_for_event(PDO $pdo, string $evento): array
{
    sf_ensure_tables($pdo);

    $st = $pdo->prepare("
        SELECT * FROM superfuncionario_rules
        WHERE is_active=1 AND evento = :e
        ORDER BY id DESC
    ");
    $st->execute([':e' => $evento]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** -----------------------------
 *  Resolver valores (source -> value)
 *  Suporta:
 *   - user.<campo>        (ex.: user.email)
 *   - extra.<campo>       (ex.: extra.codigo_live)
 *   - payload.<campo>     (ex.: payload.timestamp)
 *   - users.<coluna>      (busca no banco users pelo user[id])
 * ------------------------------*/
function sf_get_user_row(PDO $pdo, array $user): array
{
    $id = isset($user['id']) ? (int)$user['id'] : 0;
    if ($id <= 0) return $user;

    try {
        // Busca linha completa do usuário (para mapear qualquer coluna existente)
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && $row) {
            // preserva chaves padrão do payload
            $row['id'] = $row['id'] ?? $id;
            return $row;
        }
    } catch (Throwable $e) {
        // silencioso
    }
    return $user;
}

function sf_resolve_source(PDO $pdo, string $source, array $userRow, array $extra, array $payload): ?string
{
    $source = trim($source);
    if ($source === '') return null;

    // dot notation
    $parts = explode('.', $source, 2);
    if (count($parts) === 2) {
        [$root, $key] = $parts;
        $root = strtolower(trim($root));
        $key = trim($key);

        if ($root === 'user') {
            return isset($userRow[$key]) ? (string)$userRow[$key] : null;
        }
        if ($root === 'users') {
            return isset($userRow[$key]) ? (string)$userRow[$key] : null;
        }
        if ($root === 'extra') {
            return isset($extra[$key]) ? (is_scalar($extra[$key]) ? (string)$extra[$key] : json_encode($extra[$key])) : null;
        }
        if ($root === 'payload') {
            return isset($payload[$key]) ? (is_scalar($payload[$key]) ? (string)$payload[$key] : json_encode($payload[$key])) : null;
        }
    }

    // fallback: tenta direto no extra, depois userRow, depois payload
    if (isset($extra[$source])) {
        return is_scalar($extra[$source]) ? (string)$extra[$source] : json_encode($extra[$source]);
    }
    if (isset($userRow[$source])) {
        return (string)$userRow[$source];
    }
    if (isset($payload[$source])) {
        return is_scalar($payload[$source]) ? (string)$payload[$source] : json_encode($payload[$source]);
    }

    return null;
}

/** -----------------------------
 *  HTTP (cURL)
 * ------------------------------*/
function sf_http_post_json(string $url, array $headers, array $body, int $timeoutSeconds): array
{
    $ch = curl_init($url);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $hdr = $headers;
    $hdr[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
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

/** -----------------------------
 *  Disparo principal (chamado pelo app)
 * ------------------------------*/
function sf_disparar_evento(PDO $pdo, string $evento, array $user, array $extra = []): void
{
    $cfg = sf_get_config($pdo);
    if ((int)$cfg['is_enabled'] !== 1) return;
    if ($cfg['token'] === '') return;

    // Regra por evento (mesmo esquema dos Webhooks)
    $rules = sf_get_rules_for_event($pdo, $evento);
    if (!$rules) return;

    // payload base (para uso em placeholders / debug)
    $payload = [
        'evento' => $evento,
        'user'   => $user,
        'extra'  => $extra,
        'timestamp' => date('c'),
    ];

    $userRow = sf_get_user_row($pdo, $user);

    foreach ($rules as $rule) {
        $ruleId = (int)($rule['id'] ?? 0);

        $tags = [];
        $flows = [];
        $fields = [];

        // tags (1 por linha)
        $tagsText = (string)($rule['tags_text'] ?? '');
        foreach (preg_split('/\R+/', $tagsText) as $t) {
            $t = trim($t);
            if ($t !== '') $tags[] = $t;
        }

        // flows (csv)
        $flowsText = (string)($rule['flows_text'] ?? '');
        foreach (explode(',', $flowsText) as $f) {
            $f = trim($f);
            if ($f !== '' && ctype_digit($f)) $flows[] = (int)$f;
        }

        // fields mapping
        $fieldsJson = (string)($rule['fields_json'] ?? '');
        $pairs = [];
        if ($fieldsJson !== '') {
            $tmp = json_decode($fieldsJson, true);
            if (is_array($tmp)) $pairs = $tmp;
        }

        foreach ($pairs as $p) {
            $src = trim((string)($p['source'] ?? ''));
            $dst = trim((string)($p['dest'] ?? ''));
            if ($src === '' || $dst === '') continue;

            $val = sf_resolve_source($pdo, $src, $userRow, $extra, $payload);
            if ($val === null) continue;

            $fields[] = ['field_name' => $dst, 'value' => $val];
        }

        // endpoint final
        $base = rtrim($cfg['base_url'], '/');
        if ($base === '') $base = 'https://app.superfuncionario.com.br';
        $endpoint = trim((string)($rule['endpoint_override'] ?? ''));
        if ($endpoint === '') $endpoint = $cfg['default_endpoint'];
        if ($endpoint === '') $endpoint = '/api/contacts';

        $url = $base . (((substr($endpoint,0,1)==='/') ? $endpoint : '/' . $endpoint));

        // monta contato mínimo
        $email = trim((string)($userRow['email'] ?? ($user['email'] ?? '')));
        $phone = trim((string)($userRow['telefone'] ?? ($user['telefone'] ?? '')));
        $name  = trim((string)($userRow['nome'] ?? ($user['nome'] ?? '')));

        // separa nome (best-effort)
        $first = $name;
        $last  = '';
        if (strpos($name, ' ') !== false) {
            $chunks = preg_split('/\s+/', $name);
            $first = array_shift($chunks) ?: $name;
            $last = implode(' ', $chunks);
        }

        // actions no formato do Swagger /contacts (mais simples e 1 request só)
        $actions = [];
        foreach ($tags as $t) {
            $actions[] = ['action' => 'add_tag', 'tag_name' => $t];
        }
        foreach ($fields as $f) {
            $actions[] = ['action' => 'set_field_value', 'field_name' => $f['field_name'], 'value' => $f['value']];
        }
        foreach ($flows as $fid) {
            $actions[] = ['action' => 'send_flow', 'flow_id' => $fid];
        }

        $body = [
            // SF aceita email e/ou phone. Se ambos vazios, não adianta enviar.
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'first_name' => $first !== '' ? $first : null,
            'last_name'  => $last !== '' ? $last : null,
            'actions' => $actions,
        ];

        // limpa nulls
        $body = array_filter($body, fn($v) => $v !== null);

        if (empty($body['email']) && empty($body['phone'])) {
            sf_log($pdo, $evento, $ruleId, false, null, 'Contato sem email/telefone', $payload, '');
            continue;
        }

        // headers
        $headers = [];
        if ($cfg['header_mode'] === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $cfg['token'];
        } else {
            $headers[] = 'X-ACCESS-TOKEN: ' . $cfg['token'];
        }

        $res = sf_http_post_json($url, $headers, $body, (int)$cfg['timeout_seconds']);

        sf_log(
            $pdo,
            $evento,
            $ruleId,
            (bool)$res['ok'],
            (int)$res['http_status'],
            (string)($res['error'] ?? ''),
            json_decode((string)$res['request_json'], true) ?: ['raw' => (string)$res['request_json']],
            (string)($res['response'] ?? '')
        );
    }
}

function sf_log(PDO $pdo, string $evento, ?int $ruleId, bool $ok, ?int $httpStatus, string $errorText, $request, string $responseText): void
{
    try {
        sf_ensure_tables($pdo);
        $st = $pdo->prepare("
            INSERT INTO superfuncionario_logs (evento, rule_id, ok, http_status, error_text, request_json, response_text, created_at)
            VALUES (:e, :rid, :ok, :hs, :er, :rq, :rp, NOW())
        ");
        $st->execute([
            ':e'   => $evento,
            ':rid' => $ruleId,
            ':ok'  => $ok ? 1 : 0,
            ':hs'  => $httpStatus,
            ':er'  => $errorText,
            ':rq'  => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':rp'  => $responseText,
        ]);
    } catch (Throwable $e) {
        // nunca quebra o app por causa de log
    }
}
