<?php
// FILE: app/sino.php
// Integracao com o Sino (API de automacao do WhatsApp).
//
// Os pontos do sistema (cadastro, edicao de aluno, turmas) so INSEREM na fila
// `sino_outbox` via sino_aluno_cadastrado() / sino_aluno_atualizado() /
// sino_turma_atualizada(). Quem chama a API e o cron `sino_outbox`
// (cron/processar_sino.php), para que o cadastro nunca fique lento ou falhe
// quando o Sino estiver fora do ar.
//
// Configuracao (.env, fora do git): SINO_API_URL, SINO_API_KEY, SINO_ENABLED.
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const SINO_FLOW_BOAS_VINDAS = 'boas_vindas_aluno';
const SINO_TAG_ALUNO        = 'aluno_quadros';
const SINO_BATCH_MAX        = 5000;
const SINO_RODADA_MAX       = 200;
const SINO_MAX_TENTATIVAS   = 8;
const SINO_TIMEOUT_S        = 15;

// ===================== CONFIGURACAO =====================

/**
 * Configuracao: o .env (SINO_*) tem prioridade; sem ele, vale o que foi salvo
 * na tela admin/sino.php (tabela settings, fora do codigo e do git).
 */
function sino_config_value(string $envKey, string $settingKey, string $default = ''): string
{
    $env = getenv($envKey);
    if ($env !== false && trim((string)$env) !== '') return trim((string)$env);
    if (function_exists('get_setting')) {
        try {
            $v = get_setting($settingKey, null);
            if ($v !== null && trim((string)$v) !== '') return trim((string)$v);
        } catch (Throwable $e) {}
    }
    return $default;
}

/** Diz se a configuracao esta vindo do .env do servidor (a tela so informa). */
function sino_config_from_env(string $envKey): bool
{
    $env = getenv($envKey);
    return $env !== false && trim((string)$env) !== '';
}

function sino_enabled(): bool
{
    $v = strtolower(sino_config_value('SINO_ENABLED', 'sino_enabled', 'false'));
    return in_array($v, ['1', 'true', 'yes', 'on', 'sim'], true);
}

function sino_api_url(): string
{
    return rtrim(sino_config_value('SINO_API_URL', 'sino_api_url', 'https://sino.professoremersonleite.site/v1'), '/');
}

function sino_api_key(): string
{
    return sino_config_value('SINO_API_KEY', 'sino_api_key', '');
}

// ===================== CLIENTE HTTP =====================

/**
 * Chamada unica a API do Sino. Nunca lanca excecao por HTTP 4xx/5xx: devolve
 * ['status' => int (0 = falha de rede/timeout), 'body' => ?array, 'error' => string].
 */
function sino_request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    $key = sino_api_key();
    if ($key === '') return ['status' => 0, 'body' => null, 'error' => 'SINO_API_KEY nao configurada'];

    $headers = ['Accept: application/json', 'x-api-key: ' . $key];
    if ($idempotencyKey !== null && $idempotencyKey !== '') $headers[] = 'Idempotency-Key: ' . $idempotencyKey;

    $ch = curl_init(sino_api_url() . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => SINO_TIMEOUT_S,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOSIGNAL       => 1,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['status' => 0, 'body' => null, 'error' => $curlErr !== '' ? $curlErr : 'falha de rede'];
    $decoded = json_decode((string)$raw, true);
    $decoded = is_array($decoded) ? $decoded : null;
    $error = '';
    if ($status < 200 || $status >= 300) {
        $error = (string)($decoded['error']['message'] ?? '');
        if ($error === '') $error = 'HTTP ' . $status . ': ' . mb_substr(trim((string)$raw), 0, 300);
    }
    return ['status' => $status, 'body' => $decoded, 'error' => $error];
}

/** 429, 5xx, timeout e falha de rede podem ser repetidos; o resto e definitivo. */
function sino_http_retryable(int $status): bool
{
    return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
}

// ===================== SCHEMA =====================

function sino_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sino_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(30) NOT NULL,
        ref VARCHAR(100) NOT NULL DEFAULT '',
        payload MEDIUMTEXT NOT NULL,
        idempotency_key VARCHAR(150) NULL,
        tentativas INT NOT NULL DEFAULT 0,
        proxima_tentativa_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',
        ultimo_http SMALLINT NULL,
        ultimo_erro TEXT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        enviado_em DATETIME NULL,
        UNIQUE KEY uk_sino_idem (idempotency_key),
        KEY idx_sino_fila (status, proxima_tentativa_em),
        KEY idx_sino_ref (tipo, ref, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Link da live por turma (antes nao existia campo proprio para isso).
    if (!sino_column_exists($pdo, 'turmas', 'link_live')) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN link_live VARCHAR(500) NULL"); } catch (Throwable $e) {}
    }
    $done = true;
}

function sino_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $column]);
        return $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

// ===================== FILA (ganchos) =====================

/**
 * Insere na fila. Nunca lanca excecao e nao chama a API: pode ser usado
 * dentro de qualquer requisicao de cadastro/admin.
 */
function sino_enqueue(string $tipo, string $ref, array $payload, ?string $idempotencyKey = null): bool
{
    if (!sino_enabled()) return false;
    try {
        $pdo = getPDO();
        try {
            return sino_enqueue_row($pdo, $tipo, $ref, $payload, $idempotencyKey);
        } catch (PDOException $e) {
            // Tabela ainda nao criada (primeiro uso antes do cron). CREATE TABLE
            // faria commit implicito, entao so cria fora de transacao.
            if ($e->getCode() !== '42S02' || $pdo->inTransaction()) throw $e;
            sino_ensure_schema($pdo);
            return sino_enqueue_row($pdo, $tipo, $ref, $payload, $idempotencyKey);
        }
    } catch (Throwable $e) {
        @error_log('sino_enqueue ' . $tipo . ' ' . $ref . ': ' . $e->getMessage());
        return false;
    }
}

function sino_enqueue_row(PDO $pdo, string $tipo, string $ref, array $payload, ?string $idempotencyKey): bool
{
    // Atualizacoes repetidas do mesmo aluno/turma ainda nao enviadas viram uma so:
    // o contato e montado com os dados atuais no momento do envio.
    // Um cadastro ainda nao enviado ja leva os dados atuais do aluno.
    if ($tipo === 'aluno_atualizado' || $tipo === 'turma_atualizada') {
        $tipos = $tipo === 'aluno_atualizado' ? "'aluno_atualizado','aluno_cadastrado'" : "'turma_atualizada'";
        $st = $pdo->prepare("SELECT id FROM sino_outbox WHERE tipo IN ({$tipos}) AND ref = :r AND status = 'pendente' AND tentativas = 0 LIMIT 1");
        $st->execute([':r' => $ref]);
        if ($st->fetchColumn()) return true;
    }
    $pdo->prepare("INSERT IGNORE INTO sino_outbox (tipo, ref, payload, idempotency_key, proxima_tentativa_em, status, criado_em)
                   VALUES (:t, :r, :p, :k, NOW(), 'pendente', NOW())")
        ->execute([
            ':t' => $tipo,
            ':r' => $ref,
            ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':k' => $idempotencyKey,
        ]);
    return true;
}

/** Gancho: aluno novo -> fluxo de boas-vindas (chamada 1). */
function sino_aluno_cadastrado(int $userId): void
{
    if ($userId <= 0) return;
    sino_enqueue('aluno_cadastrado', (string)$userId, ['user_id' => $userId], 'aluno-' . $userId);
}

/** Gancho: telefone, nome, e-mail, turma ou data da live do aluno mudou (chamada 2). */
function sino_aluno_atualizado(int $userId): void
{
    if ($userId <= 0) return;
    sino_enqueue('aluno_atualizado', (string)$userId, ['user_id' => $userId]);
}

/** Gancho: data_live ou link_live da turma definido/alterado (chamada 3, todos os alunos). */
function sino_turma_atualizada(string $codigoTurma, string $origem = 'turma_alterada'): void
{
    $codigoTurma = trim($codigoTurma);
    if ($codigoTurma === '') return;
    sino_enqueue('turma_atualizada', $codigoTurma, ['turma' => $codigoTurma, 'origem' => $origem]);
}

// ===================== MONTAGEM DO CONTATO =====================

function sino_iso_datetime($value): ?string
{
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000')) return null;
    try {
        $dt = new DateTime($value, new DateTimeZone('America/Sao_Paulo'));
    } catch (Throwable $e) {
        return null;
    }
    if ((int)$dt->format('Y') < 2000) return null;
    return $dt->format(DATE_ATOM);
}

/** Tag em minusculas, sem acentos nem espacos. */
function sino_tag(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
    ]);
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
    return trim($value, '_');
}

/** SELECT dos dados do aluno + turma usados no contato do Sino. */
function sino_user_select(PDO $pdo): string
{
    $turmaExpr = sino_column_exists($pdo, 'users', 'turma_codigo')
        ? "COALESCE(NULLIF(u.codigo_turma,''), NULLIF(u.turma_codigo,''))"
        : "NULLIF(u.codigo_turma,'')";
    $liveParts = [];
    if (sino_column_exists($pdo, 'users', 'turma_live_at')) $liveParts[] = 'u.turma_live_at';
    if (sino_column_exists($pdo, 'users', 'data_live')) $liveParts[] = 'u.data_live';
    $userLive = $liveParts ? 'COALESCE(' . implode(', ', $liveParts) . ')' : 'NULL';
    $bloq = sino_column_exists($pdo, 'users', 'bloquear') ? 'COALESCE(u.bloquear,0)' : '0';
    $link = sino_column_exists($pdo, 'turmas', 'link_live') ? 't.link_live' : 'NULL';
    return "SELECT u.id, u.nome, u.email, u.telefone, {$turmaExpr} AS turma_codigo,
                   {$userLive} AS user_live, {$bloq} AS bloquear,
                   t.data_live AS turma_live, {$link} AS turma_link
              FROM users u
         LEFT JOIN turmas t ON t.codigo = {$turmaExpr}";
}

function sino_load_user(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare(sino_user_select($pdo) . ' WHERE u.id = :id LIMIT 1');
    $st->execute([':id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Converte a linha do aluno no contato do Sino. Devolve null (com o motivo em
 * $motivo) quando o aluno nao deve ser enviado: sem telefone ou bloqueado.
 */
function sino_contact_from_row(array $row, ?string &$motivo = null): ?array
{
    $phone = trim((string)($row['telefone'] ?? ''));
    if (preg_replace('/\D+/', '', $phone) === '') { $motivo = 'sem telefone'; return null; }
    if ((int)($row['bloquear'] ?? 0) !== 0) { $motivo = 'aluno bloqueado'; return null; }

    $contact = ['phone' => $phone];
    $nome = trim((string)($row['nome'] ?? ''));
    if ($nome !== '') $contact['name'] = $nome;
    $email = trim((string)($row['email'] ?? ''));
    if ($email !== '') $contact['email'] = $email;
    $contact['externalId'] = (string)(int)$row['id'];

    $fields = [];
    $turma = trim((string)($row['turma_codigo'] ?? ''));
    if ($turma !== '') $fields['turma'] = $turma;

    // A data do proprio aluno prevalece: alunos reagendados tem live diferente da turma.
    $userLive = sino_iso_datetime($row['user_live'] ?? '');
    $turmaLive = sino_iso_datetime($row['turma_live'] ?? '');
    $dataLive = $userLive ?? $turmaLive;
    if ($dataLive !== null) $fields['data_live'] = $dataLive;

    $link = trim((string)($row['turma_link'] ?? ''));
    if ($userLive !== null && $turmaLive !== null && $userLive !== $turmaLive) {
        $repescagem = function_exists('get_setting') ? trim((string)get_setting('reagendar_live_url', '')) : '';
        if ($repescagem !== '') $link = $repescagem;
    }
    if ($link !== '') $fields['link_live'] = $link;

    if ($fields) $contact['fields'] = $fields;
    $contact['tags'] = [SINO_TAG_ALUNO];
    if ($turma !== '' && ($turmaTag = sino_tag($turma)) !== '') $contact['tags'][] = 'turma_' . $turmaTag;
    return $contact;
}

function sino_body_hash(array $body): string
{
    return substr(sha1((string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12);
}

// ===================== LOG =====================

/** Registra cada envio (nunca inclui a chave da API). */
function sino_log(string $tipo, string $ref, int $http, string $mensagem, array $extra = []): void
{
    $ok = $http >= 200 && $http < 300;
    $ctx = ['tipo' => $tipo, 'http' => $http] + $extra;
    $porAluno = $tipo !== 'turma_atualizada';
    if ($porAluno) $ctx['user_id'] = (int)$ref; else $ctx['turma'] = $ref;
    $linha = sprintf('[sino] %s %s=%s http=%d %s', $tipo, $porAluno ? 'aluno' : 'turma', $ref, $http, $mensagem);
    try {
        if (function_exists('log_sistema')) log_sistema($ok ? 'info' : 'error', 'sino', $linha, $ctx);
        else @error_log($linha);
    } catch (Throwable $e) {
        @error_log($linha);
    }
}

// ===================== PROCESSAMENTO DA FILA (cron) =====================

/** Espera crescente entre tentativas: 1, 5, 15 e depois 60 minutos. */
function sino_retry_delay_minutes(int $tentativas): int
{
    $delays = [1, 5, 15, 60];
    return $delays[min(max($tentativas, 1), count($delays)) - 1];
}

function sino_outbox_finish(PDO $pdo, array $job, string $status, int $http, string $erro = '', ?array $payload = null): void
{
    $pdo->prepare("UPDATE sino_outbox
                      SET status = :s, ultimo_http = :h, ultimo_erro = :e,
                          tentativas = tentativas + 1,
                          payload = COALESCE(:p, payload), idempotency_key = COALESCE(:k, idempotency_key),
                          enviado_em = IF(:s2 IN ('enviado','ignorado'), NOW(), enviado_em)
                    WHERE id = :id")
        ->execute([
            ':s' => $status, ':s2' => $status, ':h' => $http ?: null, ':e' => $erro !== '' ? mb_substr($erro, 0, 2000) : null,
            ':p' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':k' => $job['idempotency_key'] ?: null, ':id' => (int)$job['id'],
        ]);
}

function sino_outbox_retry(PDO $pdo, array $job, int $http, string $erro, ?array $payload = null): string
{
    $tentativas = (int)$job['tentativas'] + 1;
    if ($tentativas >= SINO_MAX_TENTATIVAS) {
        sino_outbox_finish($pdo, $job, 'erro_definitivo', $http, $erro, $payload);
        return 'erro_definitivo';
    }
    $pdo->prepare("UPDATE sino_outbox
                      SET tentativas = :t, ultimo_http = :h, ultimo_erro = :e,
                          proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL :m MINUTE),
                          payload = COALESCE(:p, payload), idempotency_key = COALESCE(:k, idempotency_key)
                    WHERE id = :id")
        ->execute([
            ':t' => $tentativas, ':h' => $http ?: null, ':e' => mb_substr($erro, 0, 2000),
            ':m' => sino_retry_delay_minutes($tentativas),
            ':p' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':k' => $job['idempotency_key'] ?: null, ':id' => (int)$job['id'],
        ]);
    return 'pendente';
}

/** Conclui o job conforme o status HTTP: sucesso, repetir ou erro definitivo. */
function sino_outbox_resolve(PDO $pdo, array $job, array $res, array $payload, string $okMsg = 'ok'): string
{
    $http = (int)$res['status'];
    sino_log((string)$job['tipo'], (string)$job['ref'], $http, $http >= 200 && $http < 300 ? $okMsg : (string)$res['error'], ['outbox_id' => (int)$job['id']]);
    if ($http >= 200 && $http < 300) {
        sino_outbox_finish($pdo, $job, 'enviado', $http, '', $payload);
        return 'enviado';
    }
    if (sino_http_retryable($http)) return sino_outbox_retry($pdo, $job, $http, (string)$res['error'], $payload);
    sino_outbox_finish($pdo, $job, 'erro_definitivo', $http, (string)$res['error'], $payload);
    return 'erro_definitivo';
}

/** Monta (na primeira tentativa) ou reaproveita (nas repeticoes) o corpo do contato. */
function sino_job_contact(PDO $pdo, array &$job, array &$payload, ?string &$motivo): ?array
{
    if (isset($payload['body']) && is_array($payload['body'])) return $payload['body'];
    $row = sino_load_user($pdo, (int)($payload['user_id'] ?? 0));
    if (!$row) { $motivo = 'aluno nao encontrado'; return null; }
    $body = sino_contact_from_row($row, $motivo);
    if ($body === null) return null;
    $payload['body'] = $body;
    return $body;
}

function sino_process_aluno_cadastrado(PDO $pdo, array $job, array $payload): string
{
    $motivo = null;
    $body = sino_job_contact($pdo, $job, $payload, $motivo);
    if ($body === null) {
        sino_log('aluno_cadastrado', (string)$job['ref'], 0, 'ignorado: ' . $motivo);
        sino_outbox_finish($pdo, $job, 'ignorado', 0, (string)$motivo, $payload);
        return 'ignorado';
    }

    if (empty($payload['fallback_contacts'])) {
        $job['idempotency_key'] = $job['idempotency_key'] ?: 'aluno-' . (int)$job['ref'];
        $res = sino_request('POST', '/flows/' . SINO_FLOW_BOAS_VINDAS . '/trigger', $body, (string)$job['idempotency_key']);
        $code = (string)($res['body']['error']['code'] ?? '');
        if (!($res['status'] === 409 && $code === 'flow_inactive')) {
            $msg = 'started=' . (!empty($res['body']['started']) ? 'true' : 'false');
            if (!empty($res['body']['reason'])) $msg .= ' reason=' . (is_scalar($res['body']['reason']) ? (string)$res['body']['reason'] : json_encode($res['body']['reason'], JSON_UNESCAPED_UNICODE));
            return sino_outbox_resolve($pdo, $job, $res, $payload, $msg);
        }
        // Fluxo de boas-vindas ainda nao publicado: grava o contato para nao perder o aluno.
        sino_log('aluno_cadastrado', (string)$job['ref'], 409, 'flow_inactive: enviando para /contacts');
        $payload['fallback_contacts'] = true;
    }
    $key = 'aluno-' . (int)$job['ref'] . '-' . sino_body_hash($body);
    $res = sino_request('POST', '/contacts', $body, $key);
    return sino_outbox_resolve($pdo, $job, $res, $payload, 'flow_inactive -> contato gravado');
}

function sino_process_aluno_atualizado(PDO $pdo, array $job, array $payload): string
{
    $motivo = null;
    $body = sino_job_contact($pdo, $job, $payload, $motivo);
    if ($body === null) {
        sino_log('aluno_atualizado', (string)$job['ref'], 0, 'ignorado: ' . $motivo);
        sino_outbox_finish($pdo, $job, 'ignorado', 0, (string)$motivo, $payload);
        return 'ignorado';
    }
    $job['idempotency_key'] = $job['idempotency_key'] ?: 'aluno-' . (int)$job['ref'] . '-' . sino_body_hash($body);
    $res = sino_request('POST', '/contacts', $body, (string)$job['idempotency_key']);
    $msg = isset($res['body']['created']) ? ('created=' . ($res['body']['created'] ? 'true' : 'false')) : 'ok';
    return sino_outbox_resolve($pdo, $job, $res, $payload, $msg);
}

/**
 * Bloco "Enviar para fluxo do Sino" da automacao. 409/404 (fluxo pausado ou
 * apagado) -> grava o mesmo contato em /contacts para nao perder o aluno.
 */
function sino_process_fluxo(PDO $pdo, array $job, array $payload): string
{
    $flowKey = (string)($payload['flow_key'] ?? '');
    $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
    if ($flowKey === '' || !$body) {
        sino_outbox_finish($pdo, $job, 'erro_definitivo', 0, 'payload sem fluxo ou contato');
        return 'erro_definitivo';
    }

    if (empty($payload['fallback_contacts'])) {
        $res = sino_request('POST', '/flows/' . rawurlencode($flowKey) . '/trigger', $body, (string)$job['idempotency_key']);
        if ($res['status'] !== 409 && $res['status'] !== 404) {
            $msg = 'fluxo ' . $flowKey . ' started=' . (!empty($res['body']['started']) ? 'true' : 'false');
            if (!empty($res['body']['reason'])) $msg .= ' reason=' . (is_scalar($res['body']['reason']) ? (string)$res['body']['reason'] : json_encode($res['body']['reason'], JSON_UNESCAPED_UNICODE));
            return sino_outbox_resolve($pdo, $job, $res, $payload, $msg);
        }
        $estado = $res['status'] === 404 ? 'apagado' : 'inativo';
        sino_log('fluxo', (string)$job['ref'], (int)$res['status'], 'fluxo ' . $flowKey . ' ' . $estado . ' no Sino: enviando contato para /contacts', ['outbox_id' => (int)$job['id']]);
        $payload['fallback_contacts'] = $estado;
    }
    $contact = $body;
    unset($contact['data']);
    $res = sino_request('POST', '/contacts', $contact, 'aluno-' . (int)$job['ref'] . '-' . sino_body_hash($contact));
    return sino_outbox_resolve($pdo, $job, $res, $payload, 'fluxo ' . $flowKey . ' ' . $payload['fallback_contacts'] . ' no Sino -> contato gravado');
}

/** Alunos de uma turma, a partir de um id, prontos para o lote. */
function sino_turma_contacts(PDO $pdo, string $codigo, int $afterId, int $limit, array &$ignorados = []): array
{
    $where = sino_column_exists($pdo, 'users', 'turma_codigo')
        ? "(u.codigo_turma = :c1 OR ((u.codigo_turma IS NULL OR u.codigo_turma = '') AND u.turma_codigo = :c2))"
        : 'u.codigo_turma = :c1';
    $params = [':c1' => $codigo, ':after' => $afterId];
    if (str_contains($where, ':c2')) $params[':c2'] = $codigo;
    $st = $pdo->prepare(sino_user_select($pdo) . " WHERE {$where} AND u.id > :after ORDER BY u.id LIMIT " . (int)$limit);
    $st->execute($params);
    $contacts = [];
    $lastId = $afterId;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $lastId = (int)$row['id'];
        $motivo = null;
        $contact = sino_contact_from_row($row, $motivo);
        if ($contact === null) { $ignorados[$motivo] = ($ignorados[$motivo] ?? 0) + 1; continue; }
        $contacts[] = $contact;
    }
    return ['contacts' => $contacts, 'last_id' => $lastId, 'rows' => count($contacts) + array_sum($ignorados)];
}

function sino_process_turma_atualizada(PDO $pdo, array $job, array $payload, float $deadline): string
{
    $codigo = (string)($payload['turma'] ?? $job['ref']);
    $afterId = (int)($payload['after_id'] ?? 0);
    $tags = [SINO_TAG_ALUNO, 'turma_' . sino_tag($codigo)];
    $enviados = (int)($payload['enviados'] ?? 0);

    while (true) {
        $ignorados = [];
        $lote = sino_turma_contacts($pdo, $codigo, $afterId, SINO_BATCH_MAX, $ignorados);
        if (!$lote['contacts'] && $lote['last_id'] === $afterId) break; // acabou a turma
        if ($lote['contacts']) {
            $body = ['tags' => $tags, 'contacts' => $lote['contacts']];
            $key = 'turma-' . sino_tag($codigo) . '-' . sino_body_hash($body);
            $res = sino_request('POST', '/contacts/batch', $body, $key);
            $http = (int)$res['status'];
            if ($http < 200 || $http >= 300) {
                $payload['after_id'] = $afterId;
                return sino_outbox_resolve($pdo, $job, $res, $payload);
            }
            foreach ((array)($res['body']['errors'] ?? []) as $err) {
                $idx = (int)($err['index'] ?? -1);
                $uid = (string)($lote['contacts'][$idx]['externalId'] ?? '?');
                sino_log('aluno_atualizado', $uid, 422, 'erro no lote da turma ' . $codigo . ': ' . (string)($err['error'] ?? ''), ['outbox_id' => (int)$job['id']]);
            }
            $enviados += count($lote['contacts']);
            sino_log('turma_atualizada', $codigo, $http, sprintf('lote received=%d created=%d updated=%d errors=%d',
                (int)($res['body']['received'] ?? 0), (int)($res['body']['created'] ?? 0),
                (int)($res['body']['updated'] ?? 0), count((array)($res['body']['errors'] ?? []))), ['outbox_id' => (int)$job['id']]);
        }
        $afterId = $lote['last_id'];
        $payload['after_id'] = $afterId;
        $payload['enviados'] = $enviados;
        if ($lote['rows'] < SINO_BATCH_MAX) break;
        if (microtime(true) > $deadline) {
            // Sem tempo nesta rodada: salva o progresso e continua no proximo minuto.
            $pdo->prepare("UPDATE sino_outbox SET payload = :p WHERE id = :id")
                ->execute([':p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => (int)$job['id']]);
            return 'pendente';
        }
    }
    sino_outbox_finish($pdo, $job, 'enviado', 200, '', $payload);
    return 'enviado';
}

/**
 * Rede de seguranca: alunos criados por caminhos sem gancho explicito
 * (importacoes, SQL manual etc.) tambem recebem boas-vindas. Na primeira
 * execucao o cursor comeca no ultimo aluno, para nunca disparar para antigos.
 */
function sino_capture_new_users(PDO $pdo): int
{
    $cursor = (int)get_setting('sino_user_cursor', '0');
    if ($cursor <= 0) {
        set_setting('sino_user_cursor', (string)(int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM users")->fetchColumn());
        return 0;
    }
    $hasCreated = sino_column_exists($pdo, 'users', 'created_at');
    $st = $pdo->prepare("SELECT id, " . ($hasCreated ? 'created_at' : 'NULL AS created_at') . " FROM users WHERE id > :c ORDER BY id LIMIT 500");
    $st->execute([':c' => $cursor]);
    $now = time();
    $novos = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $ts = $row['created_at'] ? (int)strtotime((string)$row['created_at']) : 0;
        if ($ts > $now - 60) break; // deixa o cadastro terminar de gravar turma/live
        if ($ts >= $now - 86400) { sino_aluno_cadastrado((int)$row['id']); $novos++; }
        $cursor = (int)$row['id'];
    }
    set_setting('sino_user_cursor', (string)$cursor);
    return $novos;
}

/** Executado pelo cron a cada minuto: envia ate 200 itens pendentes. */
function sino_process_outbox(PDO $pdo, int $limit = SINO_RODADA_MAX): array
{
    if (!sino_enabled()) return ['ok' => true, 'message' => 'SINO_ENABLED=false: nada enviado.'];
    if (sino_api_key() === '') return ['ok' => false, 'message' => 'SINO_API_KEY nao configurada.'];
    sino_ensure_schema($pdo);

    $started = microtime(true);
    $deadline = $started + 50; // cabe dentro do minuto do cron
    $stats = ['capturados' => 0, 'processados' => 0, 'enviado' => 0, 'pendente' => 0, 'erro_definitivo' => 0, 'ignorado' => 0];
    try { $stats['capturados'] = sino_capture_new_users($pdo); } catch (Throwable $e) { @error_log('sino_capture_new_users: ' . $e->getMessage()); }

    $st = $pdo->prepare("SELECT * FROM sino_outbox WHERE status = 'pendente' AND proxima_tentativa_em <= NOW() ORDER BY id LIMIT " . max(1, min(SINO_RODADA_MAX, $limit)));
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $job) {
        if (microtime(true) > $deadline) break;
        $payload = json_decode((string)$job['payload'], true);
        $payload = is_array($payload) ? $payload : [];
        try {
            switch ((string)$job['tipo']) {
                case 'aluno_cadastrado': $r = sino_process_aluno_cadastrado($pdo, $job, $payload); break;
                case 'aluno_atualizado': $r = sino_process_aluno_atualizado($pdo, $job, $payload); break;
                case 'turma_atualizada': $r = sino_process_turma_atualizada($pdo, $job, $payload, $deadline); break;
                case 'fluxo':            $r = sino_process_fluxo($pdo, $job, $payload); break;
                default:
                    sino_outbox_finish($pdo, $job, 'erro_definitivo', 0, 'tipo desconhecido');
                    $r = 'erro_definitivo';
            }
        } catch (Throwable $e) {
            // Erro local (banco etc.): tenta de novo mais tarde.
            sino_log((string)$job['tipo'], (string)$job['ref'], 0, 'erro local: ' . $e->getMessage());
            $r = sino_outbox_retry($pdo, $job, 0, 'erro local: ' . $e->getMessage());
        }
        $stats['processados']++;
        $stats[$r] = ($stats[$r] ?? 0) + 1;
    }
    $stats['ok'] = true;
    $stats['duracao_ms'] = (int)round((microtime(true) - $started) * 1000);
    return $stats;
}

// ===================== BLOCO DA AUTOMACAO: "Enviar para fluxo do Sino" =====================

/**
 * Fluxos ativos do Sino que podem ser disparados por API (trigger.type = api),
 * cacheados por 5 minutos. Devolve ['ok', 'flows' => [{key,name,status,fields}], 'cached_at', 'error'].
 */
function sino_list_flows(bool $refresh = false): array
{
    $cacheKey = 'sino_flows_cache';
    $cached = json_decode((string)get_setting($cacheKey, ''), true);
    if (!$refresh && is_array($cached) && (int)($cached['ts'] ?? 0) > time() - 300) {
        return ['ok' => true, 'flows' => $cached['flows'], 'cached_at' => date('Y-m-d H:i:s', (int)$cached['ts']), 'error' => ''];
    }
    $res = sino_request('GET', '/flows?status=active');
    if ($res['status'] !== 200 || !is_array($res['body']['flows'] ?? null)) {
        $erro = $res['error'] !== '' ? $res['error'] : 'resposta inesperada do Sino';
        // Sem conexao: mostra a ultima lista conhecida, avisando o erro.
        return ['ok' => false, 'flows' => is_array($cached['flows'] ?? null) ? $cached['flows'] : [], 'cached_at' => isset($cached['ts']) ? date('Y-m-d H:i:s', (int)$cached['ts']) : '', 'error' => $erro];
    }
    $flows = [];
    foreach ($res['body']['flows'] as $f) {
        if (!is_array($f) || ($f['trigger']['type'] ?? '') !== 'api' || empty($f['key'])) continue;
        if (($f['status'] ?? 'active') !== 'active' || (isset($f['acceptsContacts']) && !$f['acceptsContacts'])) continue;
        $flows[] = [
            'key' => (string)$f['key'],
            'name' => (string)($f['name'] ?? $f['key']),
            'status' => (string)($f['status'] ?? 'active'),
            'fields' => array_values(array_map('strval', (array)($f['uses']['fields'] ?? []))),
        ];
    }
    set_setting($cacheKey, (string)json_encode(['ts' => time(), 'flows' => $flows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return ['ok' => true, 'flows' => $flows, 'cached_at' => date('Y-m-d H:i:s'), 'error' => ''];
}

/** Valor de um "dado extra" do bloco: variaveis da automacao ({{nome}}, {{turma}}...) e {{extra.campo}} do evento. */
function sino_render_data_value(string $template, array $user, array $extra): string
{
    $value = function_exists('push_flow_render_template') ? push_flow_render_template($template, $user, $extra) : $template;
    return (string)preg_replace_callback('/\{\{\s*extra\.([a-zA-Z0-9_.]+)\s*\}\}/', static function (array $m) use ($extra): string {
        $v = $extra;
        foreach (explode('.', $m[1]) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) return '';
            $v = $v[$part];
        }
        return is_scalar($v) ? (string)$v : '';
    }, $value);
}

/**
 * Execucao do bloco: NAO chama a API, so enfileira no sino_outbox (tipo fluxo).
 * O fluxo da automacao segue para o proximo bloco sem esperar o envio.
 */
function sino_automation_enqueue_flow(PDO $pdo, array $config, array $user, array $extra, array $job): array
{
    $flowKey = trim((string)($config['flowKey'] ?? ''));
    $userId = (int)($job['user_id'] ?? $user['id'] ?? 0);
    if ($flowKey === '') throw new RuntimeException('Selecione o fluxo do Sino no bloco.');
    if (!sino_enabled()) return ['skipped' => 'SINO_ENABLED=false', 'flow_key' => $flowKey];

    $row = $userId > 0 ? sino_load_user($pdo, $userId) : null;
    $motivo = $row ? null : 'aluno nao encontrado';
    $contact = $row ? sino_contact_from_row($row, $motivo) : null;
    if ($contact === null) {
        sino_log('fluxo', (string)$userId, 0, 'fluxo ' . $flowKey . ' nao enfileirado: ' . $motivo);
        return ['skipped' => (string)$motivo, 'flow_key' => $flowKey];
    }

    $data = [];
    foreach ((array)($config['data'] ?? []) as $pair) {
        $key = trim((string)($pair['key'] ?? ''));
        if ($key === '') continue;
        $value = trim(sino_render_data_value((string)($pair['value'] ?? ''), $user, $extra));
        if ($value !== '') $data[$key] = $value;
    }
    if ($data) $contact['data'] = $data;

    $runId = (int)($job['run_id'] ?? 0);
    $idem = 'fluxo-' . $flowKey . '-aluno-' . $userId . '-' . $runId;
    $ok = sino_enqueue('fluxo', (string)$userId, ['flow_key' => $flowKey, 'user_id' => $userId, 'run_id' => $runId, 'body' => $contact], $idem);
    if (!$ok) throw new RuntimeException('Falha ao colocar o aluno na fila do Sino.');
    return ['queued' => true, 'flow_key' => $flowKey, 'idempotency_key' => $idem];
}

// ===================== CARGA INICIAL (terminal e tela admin/sino.php) =====================

/**
 * Coloca na fila os alunos das turmas com live no futuro (chamada 3, lote por
 * turma) e os reagendados para live futura cuja turma ja passou (chamada 2).
 * Nunca dispara boas-vindas. Com $dryRun so conta, sem gravar nada.
 */
function sino_initial_load(PDO $pdo, bool $dryRun, ?string $turma = null): array
{
    if (!$dryRun) sino_ensure_schema($pdo);
    if ($turma !== null) {
        $st = $pdo->prepare("SELECT codigo, data_live FROM turmas WHERE codigo = :c LIMIT 1");
        $st->execute([':c' => trim($turma)]);
    } else {
        $st = $pdo->query("SELECT codigo, data_live FROM turmas WHERE data_live > NOW() ORDER BY data_live");
    }
    $result = ['turmas' => [], 'reagendados' => 0, 'total' => 0, 'ignorados' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
        $codigo = (string)$t['codigo'];
        $qtd = 0; $lotes = 0; $after = 0; $ign = [];
        do {
            $ignLote = [];
            $lote = sino_turma_contacts($pdo, $codigo, $after, SINO_BATCH_MAX, $ignLote);
            foreach ($ignLote as $k => $v) $ign[$k] = ($ign[$k] ?? 0) + $v;
            $qtd += count($lote['contacts']);
            if ($lote['contacts']) $lotes++;
            $after = $lote['last_id'];
        } while ($lote['rows'] >= SINO_BATCH_MAX);
        foreach ($ign as $k => $v) $result['ignorados'][$k] = ($result['ignorados'][$k] ?? 0) + $v;
        if (!$dryRun && $qtd > 0) sino_turma_atualizada($codigo, 'carga_inicial');
        $result['turmas'][] = ['codigo' => $codigo, 'data_live' => (string)($t['data_live'] ?? ''), 'alunos' => $qtd, 'lotes' => $lotes, 'ignorados' => $ign];
        $result['total'] += $qtd;
    }
    if ($turma === null) {
        $st = $pdo->query("SELECT * FROM (" . sino_user_select($pdo) . ") x
                            WHERE x.user_live > NOW() AND (x.turma_live IS NULL OR x.turma_live <= NOW())");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $motivo = null;
            if (sino_contact_from_row($row, $motivo) === null) { $result['ignorados'][$motivo] = ($result['ignorados'][$motivo] ?? 0) + 1; continue; }
            $result['reagendados']++;
            if (!$dryRun) sino_aluno_atualizado((int)$row['id']);
        }
        $result['total'] += $result['reagendados'];
    }
    return $result;
}
