<?php
// FILE: app/superfuncionario_dispatcher.php
declare(strict_types=1);

/**
 * Integração SuperFuncionário (SF)
 * - Mantém credenciais globais (token/base_url/endpoint/timeout)
 * - Mantém regras por evento para criar/atualizar contato, aplicar tags,
 *   enviar campos personalizados e disparar fluxos.
 */

/** ---------------------------------
 *  Tabelas (auto-create + migration)
 * ----------------------------------*/
function sf_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS superfuncionario_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            base_url VARCHAR(255) NOT NULL DEFAULT '',
            token VARCHAR(255) NOT NULL DEFAULT '',
            default_endpoint VARCHAR(255) NOT NULL DEFAULT '/api/contacts',
            header_mode VARCHAR(30) NOT NULL DEFAULT 'x-access-token',
            timeout_seconds INT NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS superfuncionario_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL DEFAULT '',
            evento VARCHAR(80) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            tags_text TEXT NULL,
            flows_text TEXT NULL,
            endpoint_override VARCHAR(255) NULL,
            fields_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_evento (evento),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Migration: adiciona custom_fields_json se não existir (retrocompatibilidade)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM superfuncionario_rules LIKE 'custom_fields_json'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE superfuncionario_rules ADD COLUMN custom_fields_json LONGTEXT NULL AFTER fields_json");
        }
    } catch (Throwable $e) {
        // silencioso — não bloqueia se já existir ou sem permissão DDL
    }

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
}

/** ---------------------------------
 *  Config e regras
 * ----------------------------------*/
function sf_get_config(PDO $pdo): array
{
    sf_ensure_tables($pdo);

    $st  = $pdo->query("SELECT * FROM superfuncionario_config ORDER BY id DESC LIMIT 1");
    $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;

    if (!$row) {
        $pdo->exec("INSERT INTO superfuncionario_config (is_enabled, base_url, token, default_endpoint, header_mode, timeout_seconds)
                    VALUES (0,'','','/api/contacts','x-access-token',10)");
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
    if (!in_array($cfg['header_mode'], ['x-access-token', 'bearer'], true)) $cfg['header_mode'] = 'x-access-token';
    if ($cfg['timeout_seconds'] <= 0) $cfg['timeout_seconds'] = 10;

    return $cfg;
}

function sf_get_rules_for_event(PDO $pdo, string $evento): array
{
    sf_ensure_tables($pdo);

    $st = $pdo->prepare("
        SELECT * FROM superfuncionario_rules
        WHERE is_active = 1 AND evento = :e
        ORDER BY id DESC
    ");
    $st->execute([':e' => $evento]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sf_get_user_row(PDO $pdo, array $user): array
{
    $id = isset($user['id']) ? (int)$user['id'] : 0;
    if ($id <= 0) return $user;

    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && $row) {
            $row['id'] = $row['id'] ?? $id;
            // Enriquece com magic_link (gerado sob demanda)
            if (function_exists('gerar_magic_link')) {
                try { $row['magic_link'] = gerar_magic_link($id, 30, false); } catch (Throwable $e) {}
            }
            return $row;
        }
    } catch (Throwable $e) {
        // silencioso
    }
    return $user;
}

/** ---------------------------------
 *  Deep path traversal
 *
 *  sf_get_value_by_path(['a' => ['b' => ['c' => 'X']]], 'a.b.c') → 'X'
 *  Retorna null se qualquer segmento não existir.
 * ----------------------------------*/
function sf_get_value_by_path(array $data, string $path): ?string
{
    if ($path === '') return null;

    $parts = explode('.', $path);
    $cur   = $data;

    foreach ($parts as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return null;
        }
        $cur = $cur[$part];
    }

    if ($cur === null) return null;
    if (is_bool($cur)) return $cur ? '1' : '0';
    if (is_scalar($cur)) return (string)$cur;
    return json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** ---------------------------------
 *  Resolver valores (source → value)
 *
 *  Formatos suportados:
 *    literal:texto fixo         → retorna "texto fixo"
 *    {{user.nome}} – {{evento}} → substitui cada placeholder recursivamente
 *    src1|src2|literal:fallback → cadeia de fallback (primeiro não-nulo vence)
 *    user.email                 → deep traversal em $userRow
 *    users.coluna               → deep traversal em $userRow
 *    extra.data.purchase.id     → deep traversal em $extra (suporta N níveis)
 *    payload.timestamp          → deep traversal em $payload
 *    email (sem ponto)          → flat lookup: extra → userRow → payload
 * ----------------------------------*/
function sf_resolve_source(PDO $pdo, string $source, array $userRow, array $extra, array $payload): ?string
{
    $source = trim($source);
    if ($source === '') return null;

    // Valor fixo
    if (strncmp($source, 'literal:', 8) === 0) {
        return substr($source, 8);
    }

    // Template com placeholders {{path.to.value}}
    if (strpos($source, '{{') !== false) {
        $result = preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            static function (array $m) use ($pdo, $userRow, $extra, $payload): string {
                return sf_resolve_source($pdo, trim($m[1]), $userRow, $extra, $payload) ?? '';
            },
            $source
        );
        return ($result !== null && trim($result) !== '') ? $result : null;
    }

    // Cadeia de fallbacks separada por |
    if (strpos($source, '|') !== false) {
        foreach (explode('|', $source) as $alt) {
            $val = sf_resolve_source($pdo, trim($alt), $userRow, $extra, $payload);
            if ($val !== null && $val !== '') return $val;
        }
        return null;
    }

    // Dot notation — deep traversal a partir do root reconhecido
    if (strpos($source, '.') !== false) {
        $dotPos = (int)strpos($source, '.');
        $root   = strtolower(substr($source, 0, $dotPos));
        $key    = substr($source, $dotPos + 1);

        if ($root === 'user' || $root === 'users') {
            return sf_get_value_by_path($userRow, $key);
        }
        if ($root === 'extra') {
            return sf_get_value_by_path($extra, $key);
        }
        if ($root === 'payload') {
            return sf_get_value_by_path($payload, $key);
        }
    }

    // Flat lookup: extra → userRow → payload (raiz)
    if (array_key_exists($source, $extra)) {
        $v = $extra[$source];
        return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (array_key_exists($source, $userRow)) {
        return (string)$userRow[$source];
    }
    if (array_key_exists($source, $payload)) {
        $v = $payload[$source];
        return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return null;
}

/** ---------------------------------
 *  Constrói lista de campos personalizados
 *
 *  Retorna:
 *    'fields'        → array de ['field_name' => ..., 'value' => ...]
 *    'resolved_keys' → nomes dos campos que foram resolvidos com sucesso
 *    'skipped_keys'  → nomes dos campos ignorados (source retornou null)
 * ----------------------------------*/
function sf_build_custom_fields(PDO $pdo, array $pairs, array $userRow, array $extra, array $payload): array
{
    $fields       = [];
    $resolvedKeys = [];
    $skippedKeys  = [];

    foreach ($pairs as $p) {
        $src = trim((string)($p['source'] ?? ''));
        $dst = trim((string)($p['dest'] ?? ''));
        if ($src === '' || $dst === '') continue;

        $val = sf_resolve_source($pdo, $src, $userRow, $extra, $payload);
        if ($val === null) {
            $skippedKeys[] = $dst . '(' . $src . ')';
            continue;
        }
        $fields[]       = ['field_name' => $dst, 'value' => $val];
        $resolvedKeys[] = $dst;
    }

    return [
        'fields'        => $fields,
        'resolved_keys' => $resolvedKeys,
        'skipped_keys'  => $skippedKeys,
    ];
}

/** ---------------------------------
 *  HTTP (cURL)
 * ----------------------------------*/
function sf_http_post_json(string $url, array $headers, array $body, int $timeoutSeconds): array
{
    $ch      = curl_init($url);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $hdr   = $headers;
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
        'ok'           => ($err === '' && $code >= 200 && $code < 300),
        'http_status'  => $code,
        'error'        => $err,
        'response'     => is_string($resp) ? $resp : '',
        'request_json' => $payload,
    ];
}

/** ---------------------------------
 *  Disparo principal (chamado pelo app)
 * ----------------------------------*/
function sf_disparar_evento(PDO $pdo, string $evento, array $user, array $extra = []): void
{
    $cfg = sf_get_config($pdo);
    if ((int)$cfg['is_enabled'] !== 1) return;
    if ($cfg['token'] === '') return;

    $rules = sf_get_rules_for_event($pdo, $evento);
    if (!$rules) return;

    // Payload base disponível como contexto de resolução
    $payload = [
        'evento'    => $evento,
        'user'      => $user,
        'extra'     => $extra,
        'timestamp' => date('c'),
    ];

    $userRow = sf_get_user_row($pdo, $user);

    foreach ($rules as $rule) {
        $ruleId = (int)($rule['id'] ?? 0);

        $tags   = [];
        $flows  = [];

        // Tags (1 por linha)
        $tagsText = (string)($rule['tags_text'] ?? '');
        foreach (preg_split('/\R+/', $tagsText) as $t) {
            $t = trim($t);
            if ($t !== '') $tags[] = $t;
        }

        // Flows (csv de IDs numéricos)
        $flowsText = (string)($rule['flows_text'] ?? '');
        foreach (explode(',', $flowsText) as $f) {
            $f = trim($f);
            if ($f !== '' && ctype_digit($f)) $flows[] = (int)$f;
        }

        // Campos personalizados — prefere custom_fields_json, cai em fields_json (retrocompat)
        $fieldsJson = '';
        $cfRaw = trim((string)($rule['custom_fields_json'] ?? ''));
        $fjRaw = trim((string)($rule['fields_json'] ?? ''));
        if ($cfRaw !== '') {
            $fieldsJson = $cfRaw;
        } elseif ($fjRaw !== '') {
            $fieldsJson = $fjRaw;
        }

        $pairs = [];
        if ($fieldsJson !== '') {
            $tmp = json_decode($fieldsJson, true);
            if (is_array($tmp)) $pairs = $tmp;
        }

        $fieldResult = sf_build_custom_fields($pdo, $pairs, $userRow, $extra, $payload);
        $fields      = $fieldResult['fields'];

        // Endpoint
        $base     = rtrim($cfg['base_url'], '/');
        if ($base === '') $base = 'https://app.superfuncionario.com.br';
        $endpoint = trim((string)($rule['endpoint_override'] ?? ''));
        if ($endpoint === '') $endpoint = $cfg['default_endpoint'];
        if ($endpoint === '') $endpoint = '/api/contacts';

        $url = $base . (substr($endpoint, 0, 1) === '/' ? $endpoint : '/' . $endpoint);

        // Contato mínimo
        $email = trim((string)($userRow['email'] ?? ($user['email'] ?? '')));
        $phone = trim((string)($userRow['telefone'] ?? ($user['telefone'] ?? '')));
        $name  = trim((string)($userRow['nome'] ?? ($user['nome'] ?? '')));

        $first = $name;
        $last  = '';
        if (strpos($name, ' ') !== false) {
            $chunks = preg_split('/\s+/', $name);
            $first  = array_shift($chunks) ?: $name;
            $last   = implode(' ', $chunks);
        }

        // Actions
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

        $body = array_filter([
            'email'      => $email !== '' ? $email : null,
            'phone'      => $phone !== '' ? $phone : null,
            'first_name' => $first !== '' ? $first : null,
            'last_name'  => $last !== '' ? $last : null,
            'actions'    => $actions,
        ], static fn($v) => $v !== null);

        if (empty($body['email']) && empty($body['phone'])) {
            sf_log($pdo, $evento, $ruleId, false, null, 'Contato sem email/telefone', $payload, '');
            continue;
        }

        // Headers
        $headers = [];
        if ($cfg['header_mode'] === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $cfg['token'];
        } else {
            $headers[] = 'X-ACCESS-TOKEN: ' . $cfg['token'];
        }

        $res = sf_http_post_json($url, $headers, $body, (int)$cfg['timeout_seconds']);

        // Log enriquecido com metadados dos campos personalizados
        $logRequest = json_decode((string)$res['request_json'], true) ?: ['raw' => (string)$res['request_json']];
        $logRequest['_debug'] = [
            'custom_fields_count' => count($fields),
            'custom_fields_keys'  => $fieldResult['resolved_keys'],
            'skipped_keys'        => $fieldResult['skipped_keys'],
        ];

        sf_log(
            $pdo,
            $evento,
            $ruleId,
            (bool)$res['ok'],
            (int)$res['http_status'],
            (string)($res['error'] ?? ''),
            $logRequest,
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

/** ---------------------------------
 *  Disparo SF para aluno de live de turma
 *
 *  Usa a config de SF da própria turma (sf_tags_text / sf_flows_text / sf_fields_json),
 *  não as regras globais da tela SuperFuncionário.
 *
 *  @param array $turmaSf  Linha da turma com: codigo, data_live, codigo_live,
 *                         sf_tags_text, sf_flows_text, sf_fields_json
 *  @param array $aluno    Linha do usuário (com andamento/aulas_concluidas/aulas_totais injetados)
 *  @param array $extra    Dados extras para resolução de source (ex.: extra.andamento)
 * ----------------------------------*/
function sf_disparar_live_turma(PDO $pdo, array $turmaSf, array $aluno, array $extra): void
{
    $cfg = sf_get_config($pdo);
    if ((int)$cfg['is_enabled'] !== 1) return;
    if ($cfg['token'] === '') return;

    $evento = 'LIVE_TURMA_' . ($turmaSf['codigo'] ?? '');

    // Tags
    $tags = [];
    foreach (preg_split('/\R+/', (string)($turmaSf['sf_tags_text'] ?? '')) as $t) {
        $t = trim($t);
        if ($t !== '') $tags[] = $t;
    }

    // Flows
    $flows = [];
    foreach (explode(',', (string)($turmaSf['sf_flows_text'] ?? '')) as $f) {
        $f = trim($f);
        if ($f !== '' && ctype_digit($f)) $flows[] = (int)$f;
    }

    // Campos personalizados
    $pairs = [];
    $fieldsJson = trim((string)($turmaSf['sf_fields_json'] ?? ''));
    if ($fieldsJson !== '') {
        $tmp = json_decode($fieldsJson, true);
        if (is_array($tmp)) $pairs = $tmp;
    }

    $userRow = sf_get_user_row($pdo, $aluno);

    $payload = [
        'evento'    => $evento,
        'user'      => $aluno,
        'extra'     => $extra,
        'timestamp' => date('c'),
    ];

    $fieldResult = sf_build_custom_fields($pdo, $pairs, $userRow, $extra, $payload);
    $fields      = $fieldResult['fields'];

    // Endpoint
    $base     = rtrim($cfg['base_url'], '/');
    if ($base === '') $base = 'https://app.superfuncionario.com.br';
    $endpoint = $cfg['default_endpoint'] ?: '/api/contacts';
    $url      = $base . (substr($endpoint, 0, 1) === '/' ? $endpoint : '/' . $endpoint);

    // Contato mínimo
    $email = trim((string)($userRow['email']    ?? ($aluno['email']    ?? '')));
    $phone = trim((string)($userRow['telefone'] ?? ($aluno['telefone'] ?? '')));
    $name  = trim((string)($userRow['nome']     ?? ($aluno['nome']     ?? '')));

    $first = $name;
    $last  = '';
    if (strpos($name, ' ') !== false) {
        $chunks = preg_split('/\s+/', $name);
        $first  = array_shift($chunks) ?: $name;
        $last   = implode(' ', $chunks);
    }

    // Actions
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

    $body = array_filter([
        'email'      => $email !== '' ? $email : null,
        'phone'      => $phone !== '' ? $phone : null,
        'first_name' => $first !== '' ? $first : null,
        'last_name'  => $last !== '' ? $last : null,
        'actions'    => $actions,
    ], static fn($v) => $v !== null);

    if (empty($body['email']) && empty($body['phone'])) {
        sf_log($pdo, $evento, null, false, null, 'Aluno sem email/telefone', $payload, '');
        return;
    }

    $headers = [];
    if ($cfg['header_mode'] === 'bearer') {
        $headers[] = 'Authorization: Bearer ' . $cfg['token'];
    } else {
        $headers[] = 'X-ACCESS-TOKEN: ' . $cfg['token'];
    }

    $res = sf_http_post_json($url, $headers, $body, (int)$cfg['timeout_seconds']);

    $logRequest = json_decode((string)$res['request_json'], true) ?: ['raw' => (string)$res['request_json']];
    $logRequest['_debug'] = [
        'custom_fields_count' => count($fields),
        'custom_fields_keys'  => $fieldResult['resolved_keys'],
        'skipped_keys'        => $fieldResult['skipped_keys'],
        'source'              => 'live_turma',
        'turma'               => $turmaSf['codigo'] ?? '',
    ];

    sf_log(
        $pdo,
        $evento,
        null,
        (bool)$res['ok'],
        (int)$res['http_status'],
        (string)($res['error'] ?? ''),
        $logRequest,
        (string)($res['response'] ?? '')
    );
}
