<?php
declare(strict_types=1);

/**
 * POST /public/api/v1/cadastrar_aluno.php
 * Header: X-API-Key: amk_...   (chave com permissao "cadastro")
 * Body JSON: {"nome": "...", "telefone": "...", "email": "...", "curso": "IMQ",
 *             "chave_idempotencia": "sino-lead-123", "teste": false, ...}
 *
 * Documentacao: admin > Integracoes > Chaves API > Documentacao (secao 9).
 */

require_once __DIR__ . '/../../../app/funcoes.php';
require_once __DIR__ . '/../../../app/student_register.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '0');
@set_time_limit(60);
ignore_user_abort(true);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$startedAt = microtime(true);
$pdo = null;
$keyId = null;
$email = '';
$phone = '';
$lockName = '';

function register_api_reply(int $status, array $data): void
{
    global $pdo, $lockName;
    if ($lockName !== '' && $pdo instanceof PDO) {
        try { $pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute([':n' => $lockName]); } catch (Throwable $e) {}
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Registra no log (conta no limite de cadastro) e responde o erro no formato combinado. */
function register_api_error(int $status, string $code, string $message, ?string $field = null, string $logResult = 'register_bad_request', array $extra = []): void
{
    global $pdo, $keyId, $email, $phone, $startedAt;
    if ($pdo instanceof PDO) {
        student_api_log($pdo, $keyId, $email, $phone, $logResult, null, $status, (int)((microtime(true) - $startedAt) * 1000));
    }
    register_api_reply($status, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => $code, 'mensagem' => $message, 'campo' => $field] + $extra);
}

function register_api_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return (string)($_SERVER['HTTP_X_HTTPS'] ?? '') === '1';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    register_api_reply(405, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => 'metodo_nao_permitido', 'mensagem' => 'Use POST com JSON.', 'campo' => null]);
}
if (!register_api_is_https()) {
    register_api_reply(403, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => 'https_obrigatorio', 'mensagem' => 'Use https://.', 'campo' => null]);
}

try {
    $pdo = getPDO();
    student_register_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('register_api db: ' . $e->getMessage());
    header('Retry-After: 30');
    register_api_reply(503, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => 'erro_interno', 'mensagem' => 'Servico temporariamente indisponivel. Tente novamente.', 'campo' => null]);
}

$key = student_api_find_key($pdo, student_api_request_key());
if (!$key) {
    register_api_error(401, 'chave_invalida', 'Chave de API ausente ou invalida. Envie no header X-API-Key.', null, 'register_unauthorized');
}
$keyId = (int)$key['id'];
if ($key['status'] !== 'active') {
    register_api_error(403, 'chave_revogada', 'Esta chave de API foi revogada.', null, 'register_forbidden');
}
if ((int)($key['scope_register'] ?? 0) !== 1) {
    register_api_error(403, 'sem_permissao', 'Esta chave nao tem permissao de cadastro. Ative em Integracoes > Chaves API.', null, 'register_forbidden');
}
$rate = student_api_rate_limit_status($pdo, $key, 'register');
if ($rate['limited']) {
    header('Retry-After: ' . (int)$rate['retry_after']);
    register_api_reply(429, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => 'limite', 'mensagem' => 'Limite de cadastros por minuto atingido (' . $rate['limit'] . '/min).', 'campo' => null]);
}

$input = [];
$raw = (string)file_get_contents('php://input');
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}
if (!$input) register_api_error(400, 'json_invalido', 'Envie o corpo em JSON.');

$email = mb_strtolower(trim((string)($input['email'] ?? '')));
$phone = trim((string)($input['telefone'] ?? ''));
$test = filter_var($input['teste'] ?? false, FILTER_VALIDATE_BOOLEAN);
$idempotencyKey = trim((string)($input['chave_idempotencia'] ?? ''));
$curso = strtoupper(trim((string)($input['curso'] ?? '')));
$origem = mb_substr(trim((string)($input['origem'] ?? '')), 0, 80);
$origemDetalhes = is_array($input['origem_detalhes'] ?? null) ? $input['origem_detalhes'] : [];
$sinoLeadId = is_numeric($input['sino_lead_id'] ?? null) ? (int)$input['sino_lead_id'] : null;

if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 120) {
    register_api_error(400, 'chave_idempotencia_invalida', 'Envie "chave_idempotencia" (ate 120 caracteres), ex.: "sino-lead-9831".', 'chave_idempotencia');
}

// Repeticao da mesma chave: devolve a resposta gravada, sem cadastrar de novo.
$replay = static function () use ($pdo, $keyId, $idempotencyKey, $email, $phone, $startedAt): void {
    $st = $pdo->prepare("SELECT user_id, http_status, response_json FROM student_api_registrations WHERE key_id = :k AND idempotency_key = :i LIMIT 1");
    $st->execute([':k' => $keyId, ':i' => $idempotencyKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $data = json_decode((string)$row['response_json'], true) ?: [];
    $data['repeticao'] = true;
    header('Idempotent-Replay: true');
    student_api_log($pdo, $keyId, $email, $phone, 'register_replay', $row['user_id'] !== null ? (int)$row['user_id'] : null, (int)$row['http_status'], (int)((microtime(true) - $startedAt) * 1000));
    register_api_reply((int)$row['http_status'], $data);
};
if (!$test) $replay();

$nome = student_register_clean_name((string)($input['nome'] ?? ''));
if ($nome === '') {
    register_api_error(400, 'nome_invalido', 'Informe o nome completo (nome e sobrenome), sem numeros ou e-mail. Ele sai no certificado.', 'nome');
}
$phoneLocal = student_register_clean_phone($phone);
if ($phoneLocal === '') {
    register_api_error(400, 'telefone_invalido', 'Telefone invalido. Envie DDD + numero, so digitos, com ou sem o 55.', 'telefone');
}
if ($email === '') {
    register_api_error(400, 'email_obrigatorio', 'O e-mail e obrigatorio: e o login do aluno na area de membros.', 'email');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    register_api_error(400, 'email_invalido', 'O e-mail informado nao e valido.', 'email');
}
if (!in_array($curso, STUDENT_REGISTER_COURSES, true)) {
    register_api_error(400, 'curso_invalido', 'Curso invalido. Valores aceitos: ' . implode(', ', STUDENT_REGISTER_COURSES) . '.', 'curso');
}

try {
    // Um cadastro por telefone por vez: evita dois alunos para a mesma pessoa
    // quando o Sino repete a chamada antes da primeira terminar.
    $lockName = 'am_register_' . $phoneLocal;
    $lock = $pdo->prepare('SELECT GET_LOCK(:n, 10)');
    $lock->execute([':n' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        $lockName = '';
        header('Retry-After: 3');
        register_api_error(503, 'ocupado', 'Ja existe um cadastro em andamento para este telefone. Tente de novo em instantes.', null, 'register_busy');
    }
    if (!$test) $replay();

    $resolved = student_api_resolve($pdo, $email, $phoneLocal);
    $emailAndPhoneSplit = $resolved['status'] === 'found'
        && $resolved['match']['metodo'] === 'email'
        && (int)$resolved['match']['encontrado_por_telefone'] > 0;
    if ($resolved['status'] === 'ambiguous' || $emailAndPhoneSplit) {
        $candidates = $resolved['candidates'];
        if ($emailAndPhoneSplit) {
            $candidates = [];
            $byPhone = student_api_users_by_phone($pdo, $phoneLocal);
            foreach (array_merge([$resolved['user']], $byPhone) as $i => $u) {
                $candidates[] = ['id' => (int)$u['id'], 'nome' => (string)$u['nome'], 'email' => (string)$u['email'], 'telefone' => (string)$u['telefone'], 'encontrado_por' => $i === 0 ? 'email' : 'telefone'];
            }
        }
        register_api_error(409, 'ambiguous', 'O e-mail e o telefone pertencem a cadastros diferentes (ou o telefone bate com mais de um aluno). A equipe precisa conferir.', null, 'register_ambiguous', ['candidatos' => $candidates]);
    }

    $includeFinancial = (int)$key['scope_financial'] === 1;
    $base = ['ok' => true, 'api_version' => STUDENT_API_VERSION, 'teste' => $test];

    if ($resolved['status'] === 'found') {
        $user = $resolved['user'];
        $status = 'existing';
        $http = 200;
        $response = $base + [
            'status' => $status,
            'mensagem' => 'Aluno ja cadastrado: nada foi alterado (turma, prazo e acesso continuam iguais).',
            'aluno' => student_api_build_profile($pdo, $user, $includeFinancial),
            'links' => student_register_links($pdo, $user, !$test),
        ];
    } else {
        $turma = enrollment_find_turma($pdo);
        if (!$turma) {
            register_api_error(422, 'turma_indisponivel', 'Nao ha turma com inscricoes abertas agora.', null, 'register_no_turma');
        }
        $status = 'created';
        $http = 201;
        if ($test) {
            $response = $base + [
                'status' => $status,
                'mensagem' => 'Modo teste: o aluno seria cadastrado assim. Nada foi gravado.',
                'aluno' => student_register_simulated_profile($turma, $nome, $email, $phoneLocal),
                'links' => [
                    'magic_link' => null,
                    'magic_link_expira_em' => null,
                    'app_login_url' => null,
                    'welcome_url' => null,
                    'grupo_whatsapp_url' => student_register_group_url((string)$turma['codigo']),
                ],
            ];
        } else {
            $registration = enrollment_register($pdo, [
                'nome' => $nome,
                'email' => $email,
                'telefone' => $phoneLocal,
                'codigo_turma' => (string)$turma['codigo'],
                'access_type' => 'free',
                'source' => STUDENT_REGISTER_SOURCE,
                'utm_source' => $origem !== '' ? $origem : 'anuncio_whatsapp',
                'utm_medium' => 'sino',
                'utm_campaign' => mb_substr((string)($origemDetalhes['campanha'] ?? ''), 0, 255),
                'utm_term' => mb_substr((string)($origemDetalhes['conjunto'] ?? ''), 0, 255),
                'utm_content' => mb_substr((string)($origemDetalhes['anuncio'] ?? ''), 0, 255),
            ]);
            $userId = (int)$registration['user_id'];
            $st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId];
            $links = student_register_links($pdo, $user, true);

            // Mesmos webhooks/SuperFuncionario/ManyChat do formulario. Os fluxos de
            // automacao ja foram capturados por enrollment_register.
            $extras = $registration['extras'] + [
                'welcome_url' => (string)($links['welcome_url'] ?? ''),
                'sino_lead_id' => $sinoLeadId,
                '_skip_automation_capture' => true,
            ];
            try {
                disparar_webhooks((string)$registration['event'], $userId, $extras);
                if (!empty($registration['access_event'])) disparar_webhooks((string)$registration['access_event'], $userId, $extras);
            } catch (Throwable $e) {
                @error_log('register_api webhooks: ' . $e->getMessage());
            }
            log_sistema('info', 'api_cadastrar_aluno', 'Aluno cadastrado pelo Sino', [
                'user_id' => $userId, 'turma' => $registration['codigo_turma'], 'sino_lead_id' => $sinoLeadId,
                'chave_idempotencia' => $idempotencyKey, 'origem' => $origem, 'origem_detalhes' => $origemDetalhes,
            ]);

            $response = $base + [
                'status' => $status,
                'mensagem' => 'Aluno cadastrado no curso gratuito.',
                'aluno' => student_api_build_profile($pdo, $user, $includeFinancial),
                'links' => $links,
            ];
        }
    }

    $userId = isset($response['aluno']['id']) ? (int)$response['aluno']['id'] : null;
    if (!$test) {
        $pdo->prepare("INSERT IGNORE INTO student_api_registrations
            (key_id, idempotency_key, user_id, status, http_status, curso, sino_lead_id, origem, origem_detalhes, response_json)
            VALUES (:k, :i, :u, :s, :h, :c, :lead, :o, :od, :r)")
            ->execute([
                ':k' => $keyId, ':i' => $idempotencyKey, ':u' => $userId, ':s' => $status, ':h' => $http,
                ':c' => $curso, ':lead' => $sinoLeadId, ':o' => $origem !== '' ? $origem : null,
                ':od' => $origemDetalhes ? json_encode($origemDetalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ':r' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
    }
    student_api_log($pdo, $keyId, $email, $phoneLocal, ($test ? 'register_test_' : 'register_') . $status, $userId, $http, (int)((microtime(true) - $startedAt) * 1000));
    register_api_reply($http, $response);
} catch (Throwable $e) {
    @error_log('register_api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    try {
        log_sistema('error', 'api_cadastrar_aluno', 'Erro ao cadastrar aluno pela API', [
            'erro' => $e->getMessage(), 'arquivo' => $e->getFile(), 'linha' => $e->getLine(),
            'chave_idempotencia' => $idempotencyKey, 'telefone' => $phoneLocal,
        ]);
    } catch (Throwable $ignored) {}
    register_api_error(500, 'erro_interno', 'Erro ao cadastrar o aluno. Tente de novo com a mesma chave_idempotencia.', null, 'register_error');
}
