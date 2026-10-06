<?php
declare(strict_types=1);

/**
 * API de consulta de aluno (v1).
 *
 * Plataformas externas recebem uma chave propria (tela Integracoes > Chaves API),
 * enviam e-mail e/ou telefone e recebem o perfil completo do aluno.
 *
 * Seguranca:
 * - a chave so e exibida uma vez; o banco guarda apenas o SHA-256;
 * - toda consulta fica registrada em student_api_logs (chave, aluno, resultado);
 * - limite de requisicoes por minuto por chave;
 * - dados sensiveis (senha, senha digitada no login, IP, user agent, fbclid,
 *   documento do comprador) NUNCA entram no payload.
 */

require_once __DIR__ . '/course_access.php';

const STUDENT_API_VERSION = '1';
const STUDENT_API_KEY_PREFIX = 'amk_';

function student_api_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_api_keys (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            key_prefix VARCHAR(20) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            scope_financial TINYINT(1) NOT NULL DEFAULT 1,
            rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 60,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes VARCHAR(500) NULL,
            created_by VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            last_used_at DATETIME NULL,
            last_used_ip VARCHAR(64) NULL,
            total_requests INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uk_student_api_key_hash (key_hash),
            KEY idx_student_api_key_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_api_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            key_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(64) NULL,
            query_email VARCHAR(190) NULL,
            query_phone VARCHAR(40) NULL,
            result VARCHAR(30) NOT NULL,
            user_id INT UNSIGNED NULL,
            http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            duration_ms INT UNSIGNED NULL,
            KEY idx_student_api_logs_key_time (key_id, created_at),
            KEY idx_student_api_logs_time (created_at),
            KEY idx_student_api_logs_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

/* ------------------------------------------------------------------ chaves */

/** Cria uma chave e devolve o texto completo (unica vez em que ele existe). */
function student_api_create_key(PDO $pdo, string $name, bool $scopeFinancial, int $ratePerMinute, string $notes, string $createdBy): array
{
    student_api_ensure_schema($pdo);
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') throw new InvalidArgumentException('Informe o nome da plataforma.');

    $plain = STUDENT_API_KEY_PREFIX . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO student_api_keys (name, key_prefix, key_hash, scope_financial, rate_limit_per_minute, notes, created_by)
        VALUES (:name, :prefix, :hash, :fin, :rate, :notes, :by)")
        ->execute([
            ':name' => $name,
            ':prefix' => substr($plain, 0, 12),
            ':hash' => hash('sha256', $plain),
            ':fin' => $scopeFinancial ? 1 : 0,
            ':rate' => max(1, min(1000, $ratePerMinute)),
            ':notes' => mb_substr(trim($notes), 0, 500) ?: null,
            ':by' => mb_substr($createdBy, 0, 190) ?: null,
        ]);
    return ['id' => (int)$pdo->lastInsertId(), 'key' => $plain];
}

function student_api_set_key_status(PDO $pdo, int $id, string $status): void
{
    student_api_ensure_schema($pdo);
    if (!in_array($status, ['active', 'revoked'], true)) return;
    $pdo->prepare("UPDATE student_api_keys
        SET status = :s, revoked_at = IF(:s2 = 'revoked', NOW(), NULL)
        WHERE id = :id")
        ->execute([':s' => $status, ':s2' => $status, ':id' => $id]);
}

function student_api_delete_key(PDO $pdo, int $id): void
{
    student_api_ensure_schema($pdo);
    $pdo->prepare("DELETE FROM student_api_keys WHERE id = :id")->execute([':id' => $id]);
}

function student_api_list_keys(PDO $pdo): array
{
    student_api_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM student_api_keys ORDER BY status = 'active' DESC, created_at DESC")
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function student_api_recent_logs(PDO $pdo, int $limit = 50): array
{
    student_api_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT l.*, k.name AS key_name
        FROM student_api_logs l
        LEFT JOIN student_api_keys k ON k.id = l.key_id
        ORDER BY l.id DESC
        LIMIT " . max(1, min(500, $limit)));
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Extrai a chave do header X-API-Key ou Authorization: Bearer. */
function student_api_request_key(): string
{
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '') return $key;

    // Alguns servidores (CGI/FastCGI) removem Authorization do $_SERVER.
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) { $auth = (string)$value; break; }
        }
    }
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) return $m[1];
    return '';
}

function student_api_find_key(PDO $pdo, string $plain): ?array
{
    student_api_ensure_schema($pdo);
    if ($plain === '' || !str_starts_with($plain, STUDENT_API_KEY_PREFIX)) return null;
    $st = $pdo->prepare("SELECT * FROM student_api_keys WHERE key_hash = :h LIMIT 1");
    $st->execute([':h' => hash('sha256', $plain)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function student_api_rate_limited(PDO $pdo, array $key): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM student_api_logs
        WHERE key_id = :id AND created_at >= (NOW() - INTERVAL 60 SECOND)");
    $st->execute([':id' => (int)$key['id']]);
    return (int)$st->fetchColumn() >= max(1, (int)$key['rate_limit_per_minute']);
}

function student_api_log(PDO $pdo, ?int $keyId, string $email, string $phone, string $result, ?int $userId, int $httpStatus, int $durationMs): void
{
    try {
        student_api_ensure_schema($pdo);
        $pdo->prepare("INSERT INTO student_api_logs (key_id, ip, query_email, query_phone, result, user_id, http_status, duration_ms)
            VALUES (:k, :ip, :e, :p, :r, :u, :s, :d)")
            ->execute([
                ':k' => $keyId,
                ':ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
                ':e' => mb_substr($email, 0, 190) ?: null,
                ':p' => mb_substr($phone, 0, 40) ?: null,
                ':r' => $result,
                ':u' => $userId,
                ':s' => $httpStatus,
                ':d' => $durationMs,
            ]);
        if ($keyId) {
            $pdo->prepare("UPDATE student_api_keys
                SET last_used_at = NOW(), last_used_ip = :ip, total_requests = total_requests + 1
                WHERE id = :id")
                ->execute([':ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null, ':id' => $keyId]);
        }
    } catch (Throwable $e) {
        @error_log('student_api_log: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ busca */

/**
 * Normaliza telefone brasileiro para DDD + numero (10 ou 11 digitos).
 * Aceita +55, 55, 0 a esquerda, mascaras e espacos.
 */
function student_api_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $digits = ltrim($digits, '0');
    if (strlen($digits) >= 12 && str_starts_with($digits, '55')) $digits = substr($digits, 2);
    return $digits;
}

/** Variacoes do telefone como podem estar gravadas em users.telefone (com/sem 55, com/sem 9o digito). */
function student_api_phone_variants(string $local): array
{
    if (strlen($local) < 10 || strlen($local) > 11) return $local !== '' ? [$local, '55' . $local] : [];
    $locals = [$local];
    if (strlen($local) === 11 && $local[2] === '9') {
        $locals[] = substr($local, 0, 2) . substr($local, 3);
    } elseif (strlen($local) === 10 && in_array($local[2], ['6', '7', '8', '9'], true)) {
        $locals[] = substr($local, 0, 2) . '9' . substr($local, 2);
    }
    $all = [];
    foreach ($locals as $l) { $all[] = $l; $all[] = '55' . $l; }
    return array_values(array_unique($all));
}

function student_api_users_by_email(PDO $pdo, string $email): array
{
    if ($email === '') return [];
    $st = $pdo->prepare("SELECT * FROM users WHERE email = :e ORDER BY id DESC LIMIT 10");
    $st->execute([':e' => $email]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function student_api_users_by_phone(PDO $pdo, string $local): array
{
    $variants = student_api_phone_variants($local);
    if (!$variants) return [];

    $clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''),' ',''),'-',''),'(',''),')',''),'+',''),'.','')";
    $in = [];
    $params = [':tail' => '%' . substr($local, -4)];
    foreach ($variants as $i => $v) { $in[] = ':v' . $i; $params[':v' . $i] = $v; }
    // O LIKE nos 4 ultimos digitos corta a maior parte das linhas antes dos REPLACE.
    $st = $pdo->prepare("SELECT * FROM users
        WHERE telefone LIKE :tail AND {$clean} IN (" . implode(',', $in) . ")
        ORDER BY id DESC LIMIT 10");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Resolve o aluno a partir de e-mail e/ou telefone.
 * Retorna ['status' => found|not_found|ambiguous, 'user' => ?array, 'match' => array, 'candidates' => array]
 */
function student_api_resolve(PDO $pdo, string $email, string $phone): array
{
    $email = strtolower(trim($email));
    $local = student_api_normalize_phone($phone);

    $byEmail = student_api_users_by_email($pdo, $email);
    $byPhone = $local !== '' ? student_api_users_by_phone($pdo, $local) : [];
    $emailIds = array_map(fn($u) => (int)$u['id'], $byEmail);
    $phoneIds = array_map(fn($u) => (int)$u['id'], $byPhone);

    $match = [
        'email_informado' => $email !== '' ? $email : null,
        'telefone_informado' => $phone !== '' ? $phone : null,
        'telefone_normalizado' => $local !== '' ? $local : null,
        'encontrado_por_email' => count($byEmail),
        'encontrado_por_telefone' => count($byPhone),
        'metodo' => null,
        'avisos' => [],
    ];

    $pick = null;
    $both = array_values(array_intersect($emailIds, $phoneIds));
    if ($both) {
        $pick = $both[0];
        $match['metodo'] = 'email_e_telefone';
    } elseif (count($byEmail) === 1) {
        $pick = $emailIds[0];
        $match['metodo'] = 'email';
        if ($byPhone) $match['avisos'][] = 'O telefone informado pertence a outro cadastro; foi usado o cadastro do e-mail.';
        elseif ($local !== '') $match['avisos'][] = 'O telefone informado nao confere com o cadastro encontrado pelo e-mail.';
    } elseif (!$byEmail && count($byPhone) === 1) {
        $pick = $phoneIds[0];
        $match['metodo'] = 'telefone';
        if ($email !== '') $match['avisos'][] = 'O e-mail informado nao foi encontrado; o aluno foi localizado pelo telefone.';
    }

    if ($pick !== null) {
        foreach (array_merge($byEmail, $byPhone) as $u) {
            if ((int)$u['id'] === $pick) return ['status' => 'found', 'user' => $u, 'match' => $match, 'candidates' => []];
        }
    }

    $candidates = [];
    foreach (array_merge($byEmail, $byPhone) as $u) {
        $candidates[(int)$u['id']] = [
            'id' => (int)$u['id'],
            'nome' => (string)$u['nome'],
            'email' => (string)$u['email'],
            'telefone' => (string)$u['telefone'],
            'cadastrado_em' => student_api_dt($u['created_at'] ?? null),
            'encontrado_por' => in_array((int)$u['id'], $emailIds, true) ? 'email' : 'telefone',
        ];
    }
    if ($candidates) {
        return ['status' => 'ambiguous', 'user' => null, 'match' => $match, 'candidates' => array_values($candidates)];
    }
    return ['status' => 'not_found', 'user' => null, 'match' => $match, 'candidates' => []];
}

/* ------------------------------------------------------------------ perfil */

function student_api_dt(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) return null;
    try { return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM); } catch (Throwable $e) { return null; }
}

function student_api_money(?int $cents): ?float
{
    return $cents === null ? null : round($cents / 100, 2);
}

function student_api_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE :t");
            $st->execute([':t' => $table]);
            $cache[$table] = (bool)$st->fetchColumn();
        } catch (Throwable $e) { $cache[$table] = false; }
    }
    return $cache[$table];
}

function student_api_build_profile(PDO $pdo, array $user, bool $includeFinancial): array
{
    $uid = (int)$user['id'];
    $email = strtolower(trim((string)$user['email']));
    $q = function (string $sql, array $params = []) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    // Tags do aluno (base para live, cliques e marcos)
    $tagRows = $q("SELECT t.nome, ut.origem, ut.created_at
        FROM user_tags ut JOIN tags t ON t.id = ut.tag_id
        WHERE ut.user_id = :u ORDER BY ut.created_at ASC, ut.id ASC", [':u' => $uid]);
    $tagFirst = [];
    foreach ($tagRows as $t) {
        $name = strtoupper((string)$t['nome']);
        if (!isset($tagFirst[$name])) $tagFirst[$name] = $t['created_at'];
    }

    // Turma atual
    $turmaCodigo = course_access_user_turma_code($user);
    $turma = null;
    if ($turmaCodigo !== '') {
        $rows = $q("SELECT codigo, codigo_live, data_live FROM turmas WHERE codigo = :c LIMIT 1", [':c' => $turmaCodigo]);
        $turma = $rows[0] ?? null;
    }
    $liveAt = $user['turma_live_at'] ?? $user['data_live'] ?? ($turma['data_live'] ?? null);

    // Inscricoes (cada turma em que o aluno se inscreveu)
    $inscricoes = array_map(fn($r) => [
        'turma' => $r['codigo_turma'],
        'inscrito_em' => student_api_dt($r['created_at']),
        'tipo_acesso' => $r['access_type'] ?: 'free',
        'origem' => $r['source'],
        'novo_cadastro' => (int)$r['is_novo'] === 1,
        'utm_source' => $r['utm_source'],
        'utm_campaign' => $r['utm_campaign'],
    ], $q("SELECT codigo_turma, created_at, access_type, source, is_novo, utm_source, utm_campaign
        FROM inscricao_logs WHERE user_id = :u ORDER BY created_at ASC, id ASC", [':u' => $uid]));

    // Acesso / bloqueio / vitalicio
    $access = course_access_status($pdo, $uid);
    $bloqueadoManual = (int)($user['bloquear'] ?? 0) === 1;
    $acesso = [
        'bloqueado' => $bloqueadoManual || !empty($access['expired']),
        'motivo_bloqueio' => $bloqueadoManual ? 'bloqueio_manual' : (!empty($access['expired']) ? 'prazo_expirado' : null),
        'bloqueio_manual' => $bloqueadoManual,
        'bloqueado_em' => student_api_dt($user['bloqueado_em'] ?? null),
        'desbloqueado_em' => student_api_dt($user['desbloqueado_em'] ?? null),
        'prazo_de_acesso_ativo' => !empty($access['enabled']),
        'dias_de_acesso' => $access['access_days'],
        'acesso_iniciado_em' => student_api_dt($access['start_at'] ?? null),
        'acesso_expira_em' => student_api_dt($access['expires_at'] ?? null),
        'segundos_restantes' => $access['remaining_seconds'],
        'prazo_expirado' => !empty($access['expired']),
        'vitalicio' => !empty($access['lifetime']),
        'vitalicio_desde' => student_api_dt($access['lifetime_granted_at'] ?? null),
        'vitalicio_tipo' => !empty($access['lifetime']) ? ($access['is_paid'] ? 'pago' : 'cortesia') : null,
        'vitalicio_origem' => $access['grant_source'],
        'vitalicio_transacao' => $access['lifetime_transaction_code'] ?? null,
        'link_compra_vitalicio' => ($access['checkout_url'] ?? '') !== '' ? $access['checkout_url'] : null,
    ];

    // Aulas e progresso
    $lessons = $q("SELECT id, titulo, slug, ordem, is_live, conta_para_conclusao FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC");
    $progress = [];
    foreach ($q("SELECT lesson_id, status, watched_seconds, created_at, completed_at, completion_source
        FROM lesson_progress WHERE user_id = :u", [':u' => $uid]) as $p) {
        $progress[(int)$p['lesson_id']] = $p;
    }
    $views = [];
    foreach ($q("SELECT lesson_id, COUNT(*) n, MIN(viewed_at) primeira, MAX(viewed_at) ultima
        FROM lesson_view_events WHERE user_id = :u GROUP BY lesson_id", [':u' => $uid]) as $v) {
        $views[(int)$v['lesson_id']] = $v;
    }
    $aulas = [];
    $obrig = 0;
    $concl = 0;
    foreach ($lessons as $l) {
        $lid = (int)$l['id'];
        $p = $progress[$lid] ?? null;
        $v = $views[$lid] ?? null;
        $done = $p && $p['status'] === 'completed';
        if ((int)$l['conta_para_conclusao'] === 1) {
            $obrig++;
            if ($done) $concl++;
        }
        $aulas[] = [
            'id' => $lid,
            'titulo' => $l['titulo'],
            'ordem' => (int)$l['ordem'],
            'aula_ao_vivo' => (int)$l['is_live'] === 1,
            'obrigatoria' => (int)$l['conta_para_conclusao'] === 1,
            'status' => $done ? 'concluida' : ($p || $v ? 'iniciada' : 'nao_iniciada'),
            'iniciada_em' => student_api_dt($p['created_at'] ?? ($v['primeira'] ?? null)),
            'concluida_em' => $done ? student_api_dt($p['completed_at'] ?? null) : null,
            'segundos_assistidos' => $p ? (int)$p['watched_seconds'] : 0,
            'visualizacoes' => $v ? (int)$v['n'] : 0,
            'ultima_visualizacao_em' => student_api_dt($v['ultima'] ?? null),
        ];
    }
    $percent = $obrig > 0 ? (int)round($concl / $obrig * 100) : 0;

    // Live: tags gravadas pelos eventos de live (tela Eventos de Live)
    $liveEventos = [];
    foreach ($q("SELECT nome, tipo, tag_nome FROM live_events WHERE ativo = 1 ORDER BY id") as $ev) {
        $tag = strtoupper((string)$ev['tag_nome']);
        $liveEventos[] = [
            'evento' => $ev['nome'],
            'tipo' => $ev['tipo'],
            'tag' => $ev['tag_nome'],
            'ocorreu' => isset($tagFirst[$tag]),
            'em' => student_api_dt($tagFirst[$tag] ?? null),
        ];
    }
    $acessouLiveEm = null;
    foreach ($liveEventos as $ev) {
        if ($ev['tipo'] === 'acessou' && $ev['ocorreu']) { $acessouLiveEm = $ev['em']; break; }
    }
    $reagendamentos = array_map(fn($r) => [
        'de_turma' => $r['old_codigo_turma'],
        'para_turma' => $r['new_codigo_turma'],
        'live_anterior' => student_api_dt($r['old_turma_live_at']),
        'nova_live' => student_api_dt($r['new_turma_live_at']),
        'origem' => $r['origem'],
        'status' => $r['status'],
        'em' => student_api_dt($r['created_at']),
    ], $q("SELECT old_codigo_turma, new_codigo_turma, old_turma_live_at, new_turma_live_at, origem, status, created_at
        FROM reagendamentos_live WHERE user_id = :u ORDER BY created_at ASC", [':u' => $uid]));

    // Certificados
    $certs = array_map(fn($c) => [
        'curso' => $c['course'],
        'codigo' => $c['codigo_uid'],
        'status' => $c['status'],
        'emitido_em' => student_api_dt($c['emitido_em']),
        'link_pdf' => $c['pdf_url'] ?: null,
    ], $q("SELECT course, codigo_uid, status, emitido_em, pdf_url FROM certificates WHERE user_id = :u ORDER BY emitido_em ASC", [':u' => $uid]));
    $certsIndividuais = [];
    if ($email !== '' && student_api_table_exists($pdo, 'individual_certificate_issues')) {
        $certsIndividuais = array_map(fn($c) => [
            'nome_no_certificado' => $c['full_name'],
            'status' => $c['status'],
            'gerado_em' => student_api_dt($c['generated_at'] ?: $c['created_at']),
            'link_pdf' => $c['pdf_url'] ?: null,
        ], $q("SELECT full_name, status, generated_at, created_at, pdf_url
            FROM individual_certificate_issues WHERE email = :e ORDER BY id ASC", [':e' => $email]));
    }
    $certEmitido = null;
    foreach ($certs as $c) if ($c['status'] === 'emitido') $certEmitido = $c;

    // Login
    $loginStats = $q("SELECT COUNT(*) total, MIN(logged_at) primeiro FROM login_events WHERE user_id = :u AND success = 1", [':u' => $uid])[0] ?? [];

    // E-mail marketing
    $emailMarketing = [
        'status' => $user['email_status'] ?? null,
        'descadastrado' => (int)($user['email_unsubscribed'] ?? 0) === 1,
        'descadastrado_em' => student_api_dt($user['unsubscribed_at'] ?? null),
        'bounce' => (int)($user['email_bounced'] ?? 0) === 1,
        'marcou_spam' => (int)($user['email_complained'] ?? 0) === 1,
        'bloqueado' => (int)($user['email_blocked'] ?? 0) === 1,
    ];

    // WhatsApp (grupos)
    $whatsapp = null;
    if (student_api_table_exists($pdo, 'whatsapp_group_members')) {
        $wg = $q("SELECT COUNT(*) total, SUM(is_current = 1) atuais, MIN(first_seen_at) primeiro, MAX(last_seen_at) ultimo
            FROM whatsapp_group_members WHERE user_id = :u", [':u' => $uid])[0] ?? [];
        $whatsapp = [
            'esta_em_grupo' => (int)($wg['atuais'] ?? 0) > 0,
            'grupos_atuais' => (int)($wg['atuais'] ?? 0),
            'grupos_historico' => (int)($wg['total'] ?? 0),
            'entrou_primeira_vez_em' => student_api_dt($wg['primeiro'] ?? null),
            'visto_por_ultimo_em' => student_api_dt($wg['ultimo'] ?? null),
        ];
    }

    // Suporte
    $suporte = null;
    if (student_api_table_exists($pdo, 'support_conversations')) {
        $sc = $q("SELECT COUNT(*) total, SUM(status <> 'closed') abertas, MAX(last_message_at) ultima
            FROM support_conversations WHERE user_id = :u", [':u' => $uid])[0] ?? [];
        $suporte = [
            'conversas' => (int)($sc['total'] ?? 0),
            'conversas_abertas' => (int)($sc['abertas'] ?? 0),
            'ultima_mensagem_em' => student_api_dt($sc['ultima'] ?? null),
            'ultimo_assunto' => ($user['support_chat_assunto'] ?? '') !== '' ? $user['support_chat_assunto'] : null,
        ];
    }

    $profile = [
        'id' => $uid,
        'nome' => $user['nome'],
        'email' => $user['email'],
        'telefone' => $user['telefone'],
        'telefone_normalizado' => student_api_normalize_phone((string)$user['telefone']) ?: null,
        'cadastrado_em' => student_api_dt($user['created_at'] ?? null),
        'ultimo_login_em' => student_api_dt($user['last_login_at'] ?? null),
        'primeiro_login_em' => student_api_dt($loginStats['primeiro'] ?? null),
        'total_logins' => (int)($loginStats['total'] ?? 0),
        'ja_acessou_area_de_membros' => (int)($loginStats['total'] ?? 0) > 0 || !empty($user['last_login_at']),
        'turma_atual' => [
            'codigo' => $turmaCodigo !== '' ? $turmaCodigo : null,
            'codigo_live' => $turma['codigo_live'] ?? null,
            'data_live' => student_api_dt($liveAt),
            'live_ja_aconteceu' => $liveAt ? (strtotime((string)$liveAt) <= time()) : null,
        ],
        'inscricoes' => $inscricoes,
        'total_inscricoes' => count($inscricoes),
        'utm_cadastro' => [
            'source' => $user['utm_source'] ?? null,
            'medium' => $user['utm_medium'] ?? null,
            'campaign' => $user['utm_campaign'] ?? null,
            'term' => $user['utm_term'] ?? null,
            'content' => $user['utm_content'] ?? null,
        ],
        'acesso' => $acesso,
        'progresso' => [
            'aulas_obrigatorias' => $obrig,
            'aulas_concluidas' => $concl,
            'percentual' => $percent,
            'curso_concluido' => $obrig > 0 && $concl >= $obrig,
            'aulas' => $aulas,
        ],
        'live' => [
            'acessou_live' => $acessouLiveEm !== null,
            'acessou_live_em' => $acessouLiveEm,
            'eventos' => $liveEventos,
            'reagendamentos' => $reagendamentos,
        ],
        'certificado' => [
            'emitido' => $certEmitido !== null,
            'emitido_em' => $certEmitido['emitido_em'] ?? null,
            'codigo' => $certEmitido['codigo'] ?? null,
            'link_pdf' => $certEmitido['link_pdf'] ?? null,
            'link_pagina_certificado' => defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/certificado.php' : null,
            'historico' => $certs,
            'certificados_individuais' => $certsIndividuais,
        ],
        'tags' => array_map(fn($t) => ['nome' => $t['nome'], 'origem' => $t['origem'], 'em' => student_api_dt($t['created_at'])], $tagRows),
        'email_marketing' => $emailMarketing,
        'whatsapp' => $whatsapp,
        'suporte' => $suporte,
    ];

    if ($includeFinancial) {
        $profile['compras'] = student_api_purchases($pdo, $uid, $email, student_api_normalize_phone((string)$user['telefone']));
    }
    return $profile;
}

/** Compras ligadas ao aluno (pelo vinculo do conciliador ou pelo e-mail/telefone do comprador). */
function student_api_purchases(PDO $pdo, int $uid, string $email, string $phoneLocal): array
{
    if (!student_api_table_exists($pdo, 'payment_sales')) return [];
    $where = ['matched_user_id = :u'];
    $params = [':u' => $uid];
    if ($email !== '') { $where[] = 'buyer_email = :e'; $params[':e'] = $email; }
    if (strlen($phoneLocal) >= 10) {
        $where[] = 'buyer_phone_norm IN (:p1, :p2)';
        $params[':p1'] = $phoneLocal;
        $params[':p2'] = '55' . $phoneLocal;
    }
    $st = $pdo->prepare("SELECT provider, external_transaction_id, product_name, normalized_status, provider_status,
            gross_amount_cents, currency, payment_method, installments, first_received_at, excluded_from_financials
        FROM payment_sales
        WHERE " . implode(' OR ', $where) . "
        ORDER BY first_received_at DESC
        LIMIT 100");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_map(fn($r) => [
        'plataforma' => $r['provider'],
        'transacao' => $r['external_transaction_id'],
        'produto' => $r['product_name'],
        'status' => $r['normalized_status'],
        'status_original' => $r['provider_status'],
        'valor' => student_api_money($r['gross_amount_cents'] !== null ? (int)$r['gross_amount_cents'] : null),
        'moeda' => $r['currency'],
        'forma_pagamento' => $r['payment_method'],
        'parcelas' => $r['installments'] !== null ? (int)$r['installments'] : null,
        'data' => student_api_dt($r['first_received_at']),
    ], $rows);
    $approved = array_values(array_filter($rows, fn($r) => $r['normalized_status'] === 'APPROVED' && (int)$r['excluded_from_financials'] === 0));

    return [
        'total_registros' => count($items),
        'compras_aprovadas' => count($approved),
        'valor_total_aprovado' => student_api_money(array_sum(array_map(fn($r) => (int)$r['gross_amount_cents'], $approved))),
        'itens' => $items,
    ];
}

/** Monta a resposta completa (usada pelo endpoint e pelo teste do admin). */
function student_api_lookup(PDO $pdo, string $email, string $phone, bool $includeFinancial): array
{
    $resolved = student_api_resolve($pdo, $email, $phone);
    $base = [
        'ok' => true,
        'api_version' => STUDENT_API_VERSION,
        'status' => $resolved['status'],
        'encontrado' => $resolved['status'] === 'found',
        'busca' => $resolved['match'],
        'gerado_em' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
    ];
    if ($resolved['status'] === 'found') {
        $base['aluno'] = student_api_build_profile($pdo, $resolved['user'], $includeFinancial);
    } elseif ($resolved['status'] === 'ambiguous') {
        $base['mensagem'] = 'Mais de um cadastro corresponde aos dados informados. Envie e-mail e telefone juntos para desempatar.';
        $base['candidatos'] = $resolved['candidates'];
    } else {
        $base['mensagem'] = 'Nenhum aluno encontrado com o e-mail/telefone informado.';
    }
    return $base;
}
