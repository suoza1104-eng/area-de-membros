<?php
// FILE: app/sino.php
// Integracao com o Sino (API de automacao do WhatsApp).
//
// O Sino recebe alunos somente quando eles passam pelo bloco de automacao
// "Enviar para fluxo do Sino". Cadastros, login, matricula, importacoes e
// atualizacoes gerais nao sincronizam contatos automaticamente.
//
// Configuracao (.env, fora do git): SINO_API_URL, SINO_API_KEY, SINO_ENABLED.
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/student_api.php';
require_once __DIR__ . '/welcome_page.php';

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
    // Intencionalmente sem acao: o Sino recebe alunos somente pelo bloco de automacao.
}

/** Gancho: telefone, nome, e-mail, turma ou data da live do aluno mudou (chamada 2). */
function sino_aluno_atualizado(int $userId): void
{
    if ($userId <= 0) return;
    // Intencionalmente sem acao: atualizacoes gerais nao sincronizam contatos.
}

/** Gancho: data_live ou link_live da turma definido/alterado (chamada 3, todos os alunos). */
function sino_turma_atualizada(string $codigoTurma, string $origem = 'turma_alterada'): void
{
    $codigoTurma = trim($codigoTurma);
    if ($codigoTurma === '') return;
    // Intencionalmente sem acao: nao ha mais envio em lote de turma para o Sino.
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
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
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
    if (function_exists('welcome_page_ensure_schema')) {
        try { welcome_page_ensure_schema($pdo); } catch (Throwable $e) {}
    }
    if (function_exists('student_api_ensure_message_optout_schema')) {
        try { student_api_ensure_message_optout_schema($pdo); } catch (Throwable $e) {}
    }
    $turmaExpr = sino_column_exists($pdo, 'users', 'turma_codigo')
        ? "COALESCE(NULLIF(u.codigo_turma,''), NULLIF(u.turma_codigo,''))"
        : "NULLIF(u.codigo_turma,'')";
    $liveParts = [];
    if (sino_column_exists($pdo, 'users', 'turma_live_at')) $liveParts[] = 'u.turma_live_at';
    if (sino_column_exists($pdo, 'users', 'data_live')) $liveParts[] = 'u.data_live';
    $userLive = $liveParts ? 'COALESCE(' . implode(', ', $liveParts) . ')' : 'NULL';
    $bloq = sino_column_exists($pdo, 'users', 'bloquear') ? 'COALESCE(u.bloquear,0)' : '0';
    $link = sino_column_exists($pdo, 'turmas', 'link_live') ? 't.link_live' : 'NULL';
    $codigoLive = sino_column_exists($pdo, 'turmas', 'codigo_live') ? 't.codigo_live' : 'NULL';
    $welcomeUrl = sino_column_exists($pdo, 'users', 'welcome_url') ? 'u.welcome_url' : 'NULL';
    $whatsappOptOut = sino_column_exists($pdo, 'users', 'whatsapp_opt_out') ? 'COALESCE(u.whatsapp_opt_out,0)' : '0';
    $whatsappOptOutAt = sino_column_exists($pdo, 'users', 'whatsapp_opt_out_at') ? 'u.whatsapp_opt_out_at' : 'NULL';
    $whatsappOptOutSource = sino_column_exists($pdo, 'users', 'whatsapp_opt_out_source') ? 'u.whatsapp_opt_out_source' : 'NULL';
    // Data/hora de inscricao: a ultima inscricao (reinscricao conta) ou o cadastro.
    $inscricao = sino_column_exists($pdo, 'users', 'created_at') ? 'u.created_at' : 'NULL';
    if (sino_column_exists($pdo, 'inscricao_logs', 'created_at')) $inscricao = "COALESCE((SELECT MAX(il.created_at) FROM inscricao_logs il WHERE il.user_id = u.id), {$inscricao})";
    return "SELECT u.id, u.nome, u.email, u.telefone, {$turmaExpr} AS turma_codigo,
                   {$userLive} AS user_live, {$bloq} AS bloquear, {$inscricao} AS data_inscricao,
                   t.data_live AS turma_live, {$link} AS turma_link, {$codigoLive} AS turma_codigo_live,
                   {$welcomeUrl} AS welcome_url,
                   {$whatsappOptOut} AS whatsapp_opt_out,
                   {$whatsappOptOutAt} AS whatsapp_opt_out_at,
                   {$whatsappOptOutSource} AS whatsapp_opt_out_source
              FROM users u
         LEFT JOIN turmas t ON t.codigo = {$turmaExpr}";
}

function sino_load_user(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare(sino_user_select($pdo) . ' WHERE u.id = :id LIMIT 1');
    $st->execute([':id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (function_exists('welcome_page_ensure_user_link') && trim((string)($row['welcome_url'] ?? '')) === '') {
        try {
            $row['welcome_url'] = welcome_page_ensure_user_link($pdo, $userId);
        } catch (Throwable $e) {}
    }
    $rows = [$row];
    sino_attach_magic_links($pdo, $rows);
    return $rows[0];
}

/**
 * Link de login direto (magic link) de cada aluno: reaproveita um link valido
 * por mais 15 dias; senao cria um de 60 dias (em lote, uma consulta por grupo).
 */
function sino_attach_magic_links(PDO $pdo, array &$rows): void
{
    $ids = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows))));
    if (!$ids) return;
    $links = [];
    try {
        if (function_exists('gerar_magic_link') && !sino_column_exists($pdo, 'magic_links', 'token')) gerar_magic_link($ids[0], 60, false); // cria a tabela
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $st = $pdo->query("SELECT user_id, token FROM magic_links WHERE user_id IN ({$in}) AND one_shot = 0 AND expires_at > DATE_ADD(NOW(), INTERVAL 15 DAY) ORDER BY expires_at ASC");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $links[(int)$r['user_id']] = (string)$r['token'];
        }
        $novos = array_values(array_diff($ids, array_keys($links)));
        foreach (array_chunk($novos, 500) as $chunk) {
            $vals = []; $params = [];
            foreach ($chunk as $i => $uid) {
                $token = bin2hex(random_bytes(32));
                $vals[] = "(:u{$i}, :t{$i}, DATE_ADD(NOW(), INTERVAL 60 DAY), 0)";
                $params[":u{$i}"] = $uid; $params[":t{$i}"] = $token;
                $links[$uid] = $token;
            }
            $pdo->prepare('INSERT INTO magic_links (user_id, token, expires_at, one_shot) VALUES ' . implode(',', $vals))->execute($params);
        }
    } catch (Throwable $e) {
        @error_log('sino_attach_magic_links: ' . $e->getMessage());
    }
    $base = rtrim((string)BASE_URL, '/') . '/login.php?am=';
    foreach ($rows as &$row) {
        $uid = (int)($row['id'] ?? 0);
        if (isset($links[$uid])) $row['magic_link'] = $base . $links[$uid];
    }
    unset($row);
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
    // Mesmo valor de extra.codigo_live dos eventos: codigo da live da turma, ou o codigo da turma.
    $codigoLive = trim((string)($row['turma_codigo_live'] ?? ''));
    if ($codigoLive === '') $codigoLive = $turma;
    if ($codigoLive !== '') $fields['codigo_live'] = $codigoLive;

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
    $magic = trim((string)($row['magic_link'] ?? ''));
    if ($magic !== '') {
        $fields['magic_link'] = $magic;
        $fields['app_login_url'] = function_exists('welcome_page_add_next_to_login_url')
            ? welcome_page_add_next_to_login_url($magic, 'aplicativo.php')
            : $magic . (str_contains($magic, '?') ? '&' : '?') . 'next=aplicativo.php';
    }
    $welcome = trim((string)($row['welcome_url'] ?? ''));
    if ($welcome !== '') $fields['welcome_url'] = $welcome;
    $whatsappOptOut = (int)($row['whatsapp_opt_out'] ?? 0) === 1;
    $fields['whatsapp_opt_out'] = $whatsappOptOut ? '1' : '0';
    $fields['receber_mensagens'] = $whatsappOptOut ? '0' : '1';
    if ($whatsappOptOut) {
        $fields['whatsapp_opt_out_at'] = sino_iso_datetime($row['whatsapp_opt_out_at'] ?? '') ?? (string)($row['whatsapp_opt_out_at'] ?? '');
        $optOutSource = trim((string)($row['whatsapp_opt_out_source'] ?? ''));
        if ($optOutSource !== '') $fields['whatsapp_opt_out_source'] = $optOutSource;
    }
    $inscricao = sino_iso_datetime($row['data_inscricao'] ?? '');
    if ($inscricao !== null) $fields['data_inscricao'] = $inscricao;

    if ($fields) $contact['fields'] = $fields;
    $contact['tags'] = [SINO_TAG_ALUNO];
    if ($turma !== '' && ($turmaTag = sino_tag($turma)) !== '') $contact['tags'][] = 'turma_' . $turmaTag;
    if ($whatsappOptOut) $contact['tags'][] = 'mensagens_canceladas';
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
    $msg = 'sync geral desativada: aluno so e enviado ao Sino pelo bloco de automacao';
    sino_log('aluno_cadastrado', (string)$job['ref'], 0, $msg, ['outbox_id' => (int)$job['id']]);
    sino_outbox_finish($pdo, $job, 'ignorado', 0, $msg, $payload);
    return 'ignorado';
}

function sino_process_aluno_atualizado(PDO $pdo, array $job, array $payload): string
{
    $msg = 'sync geral desativada: atualizacao de aluno nao cria contato no Sino';
    sino_log('aluno_atualizado', (string)$job['ref'], 0, $msg, ['outbox_id' => (int)$job['id']]);
    sino_outbox_finish($pdo, $job, 'ignorado', 0, $msg, $payload);
    return 'ignorado';
}

/**
 * Bloco "Enviar para fluxo do Sino" da automacao. Apenas este caminho chama a
 * API do Sino para o aluno, sempre disparando o fluxo configurado no bloco.
 */
function sino_process_fluxo(PDO $pdo, array $job, array $payload): string
{
    $flowKey = (string)($payload['flow_key'] ?? '');
    $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
    if ($flowKey === '' || !$body) {
        sino_outbox_finish($pdo, $job, 'erro_definitivo', 0, 'payload sem fluxo ou contato');
        return 'erro_definitivo';
    }

    $res = sino_request('POST', '/flows/' . rawurlencode($flowKey) . '/trigger', $body, (string)$job['idempotency_key']);
    $msg = 'fluxo ' . $flowKey . ' started=' . (!empty($res['body']['started']) ? 'true' : 'false');
    if (!empty($res['body']['reason'])) $msg .= ' reason=' . (is_scalar($res['body']['reason']) ? (string)$res['body']['reason'] : json_encode($res['body']['reason'], JSON_UNESCAPED_UNICODE));
    return sino_outbox_resolve($pdo, $job, $res, $payload, $msg);
}

/** Alunos de uma turma, a partir de um id, prontos para o lote. */
function sino_turma_contacts(PDO $pdo, string $codigo, int $afterId, int $limit, array &$ignorados = [], bool $withMagicLinks = true): array
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
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($withMagicLinks) sino_attach_magic_links($pdo, $rows);
    foreach ($rows as $row) {
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
    $msg = 'sync geral desativada: lote de turma nao cria contatos no Sino';
    sino_log('turma_atualizada', $codigo, 0, $msg, ['outbox_id' => (int)$job['id']]);
    sino_outbox_finish($pdo, $job, 'ignorado', 0, $msg, $payload);
    return 'ignorado';
}

/** Sync geral desativada: alunos novos nao sao capturados automaticamente. */
function sino_capture_new_users(PDO $pdo): int
{
    return 0;
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

const SINO_ROUTING_CHANNELS = ['automatic', 'official', 'unofficial'];
const SINO_ROUTING_ROLES = ['sales', 'technical', 'student_support', 'broadcast'];

/**
 * Produtos/cursos do Sino (IDs internos do Sino), lidos de GET /v1/products
 * (cache de 5 min). `default` = marcados na aba Integracoes > Sino para virem
 * selecionados nos blocos. Sem conexao, usa a ultima lista conhecida.
 */
function sino_products(bool $refresh = false): array
{
    static $memo = null;
    if ($memo !== null && !$refresh) return $memo;
    $cache = json_decode((string)get_setting('sino_products_remote', ''), true);
    $list = is_array($cache['products'] ?? null) ? $cache['products'] : null;
    if ($refresh || $list === null || (int)($cache['ts'] ?? 0) < time() - 300) {
        $res = sino_api_key() !== '' ? sino_request('GET', '/products') : ['status' => 0];
        if ($res['status'] === 200 && is_array($res['body']['products'] ?? null)) {
            $list = [];
            foreach ($res['body']['products'] as $p) {
                $id = (int)($p['id'] ?? 0);
                if ($id > 0) $list[] = ['id' => $id, 'code' => (string)($p['code'] ?? ''), 'name' => trim((string)($p['name'] ?? '')) ?: ('Produto ' . $id)];
            }
            set_setting('sino_products_remote', (string)json_encode(['ts' => time(), 'products' => $list], JSON_UNESCAPED_UNICODE));
        }
    }
    if ($list === null) { // nunca conectou: lista antiga cadastrada a mao
        $list = array_values(array_filter(array_map(static fn($p) => ['id' => (int)($p['id'] ?? 0), 'code' => '', 'name' => (string)($p['name'] ?? '')], json_decode((string)get_setting('sino_products', ''), true) ?: []), static fn($p) => $p['id'] > 0));
    }
    $defaults = sino_product_default_ids();
    return $memo = array_map(static fn($p) => $p + ['default' => in_array($p['id'], $defaults, true)], $list);
}

/** IDs marcados como padrao (aba Sino). Compatibilidade: a lista antiga guardava `default` por produto. */
function sino_product_default_ids(): array
{
    $saved = get_setting('sino_products_default', null);
    if ($saved !== null && $saved !== '') return array_values(array_map('intval', json_decode((string)$saved, true) ?: []));
    $old = json_decode((string)get_setting('sino_products', ''), true) ?: [];
    return array_values(array_map(static fn($p) => (int)$p['id'], array_filter($old, static fn($p) => !empty($p['default']))));
}

function sino_product_name(int $id): string
{
    foreach (sino_products() as $p) if ($p['id'] === $id) return $p['name'];
    return '';
}

/**
 * routing do bloco: canal (padrao oficial), funcao (padrao comunicacao em massa)
 * e cursos (padrao: os marcados como padrao na lista de produtos). Blocos salvos
 * antes destes campos usam os padroes.
 */
function sino_routing_from_config(array $config): array
{
    $channel = (string)($config['routingChannel'] ?? 'official');
    if (!in_array($channel, SINO_ROUTING_CHANNELS, true)) $channel = 'official';
    $role = (string)($config['routingRole'] ?? 'broadcast');
    if (!in_array($role, SINO_ROUTING_ROLES, true)) $role = 'broadcast';
    if (is_array($config['routingProductIds'] ?? null)) {
        $ids = $config['routingProductIds'];
    } else {
        $ids = array_column(array_filter(sino_products(), static fn($p) => $p['default']), 'id');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    return ['channel' => $channel, 'role' => $role, 'productIds' => $ids];
}

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
    $fields = is_array($contact['fields'] ?? null) ? $contact['fields'] : [];
    foreach ([
        'turma' => $fields['turma'] ?? ($extra['codigo_turma'] ?? ''),
        'data_live' => $fields['data_live'] ?? ($extra['data_live'] ?? ''),
        'magic_link' => $fields['magic_link'] ?? '',
        'codigo_live' => $fields['codigo_live'] ?? ($extra['codigo_live'] ?? ''),
        'welcome_url' => $fields['welcome_url'] ?? ($extra['welcome_url'] ?? ''),
    ] as $key => $value) {
        $value = trim((string)$value);
        if ($value !== '') $data[$key] = $value;
    }
    foreach ((array)($config['data'] ?? []) as $pair) {
        $key = trim((string)($pair['key'] ?? ''));
        if ($key === '') continue;
        $value = trim(sino_render_data_value((string)($pair['value'] ?? ''), $user, $extra));
        if ($value !== '') $data[$key] = $value;
    }
    if ($data) $contact['data'] = $data;

    // Roteamento: o Sino escolhe o numero (oficial ou nao, funcao, curso) por estes campos.
    $routing = sino_routing_from_config($config);
    $contact['routing'] = $routing;
    if ($routing['productIds']) {
        $contact['productId'] = $routing['productIds'][0];
        $productName = sino_product_name($routing['productIds'][0]);
        if ($productName !== '') $contact['productName'] = $productName;
    }

    $runId = (int)($job['run_id'] ?? 0);
    $idem = 'fluxo-' . $flowKey . '-aluno-' . $userId . '-' . $runId;
    $ok = sino_enqueue('fluxo', (string)$userId, ['flow_key' => $flowKey, 'user_id' => $userId, 'run_id' => $runId, 'body' => $contact], $idem);
    if (!$ok) throw new RuntimeException('Falha ao colocar o aluno na fila do Sino.');
    return ['queued' => true, 'flow_key' => $flowKey, 'routing' => $routing, 'idempotency_key' => $idem];
}

// ===================== CARGA INICIAL (terminal e tela admin/sino.php) =====================

/** Carga inicial desativada: a entrada no Sino acontece somente pelo bloco. */
function sino_initial_load(PDO $pdo, bool $dryRun, ?string $turma = null): array
{
    return [
        'disabled' => true,
        'message' => 'Carga inicial desativada: o Sino recebe alunos somente pelo bloco de automacao.',
        'turmas' => [],
        'reagendados' => 0,
        'total' => 0,
        'ignorados' => [],
    ];
}
