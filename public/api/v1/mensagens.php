<?php
declare(strict_types=1);

/**
 * POST /public/api/v1/mensagens.php
 * Header: X-API-Key: amk_...   (ou Authorization: Bearer amk_...)
 * Body JSON:
 *   {"telefone":"11999998888","action":"opt_out","source":"sino","reason":"cancelou_mensagens"}
 *   {"user_id":123,"action":"opt_in","source":"sino"}
 */

require_once __DIR__ . '/../../../app/funcoes.php';
require_once __DIR__ . '/../../../app/student_api.php';
require_once __DIR__ . '/../../../app/sino.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$startedAt = microtime(true);

function messages_api_reply(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function messages_api_error(int $status, string $code, string $message): void
{
    messages_api_reply($status, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => $code, 'mensagem' => $message]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    messages_api_error(405, 'metodo_nao_permitido', 'Use POST com JSON.');
}

try {
    $pdo = getPDO();
    student_api_ensure_message_optout_schema($pdo);
} catch (Throwable $e) {
    @error_log('messages_api db: ' . $e->getMessage());
    messages_api_error(503, 'indisponivel', 'Servico temporariamente indisponivel. Tente novamente.');
}

$key = student_api_find_key($pdo, student_api_request_key());
if (!$key) {
    student_api_log($pdo, null, '', '', 'messages_unauthorized', null, 401, (int)((microtime(true) - $startedAt) * 1000));
    messages_api_error(401, 'chave_invalida', 'Chave de API ausente ou invalida. Envie no header X-API-Key.');
}
if ($key['status'] !== 'active') {
    student_api_log($pdo, (int)$key['id'], '', '', 'messages_revoked', null, 403, (int)((microtime(true) - $startedAt) * 1000));
    messages_api_error(403, 'chave_revogada', 'Esta chave de API foi revogada.');
}
if (student_api_rate_limited($pdo, $key)) {
    header('Retry-After: 60');
    messages_api_error(429, 'limite_excedido', 'Limite de ' . (int)$key['rate_limit_per_minute'] . ' requisicoes por minuto atingido para esta chave.');
}

$input = [];
$raw = (string)file_get_contents('php://input');
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}
if (!$input) $input = $_POST;

$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['telefone'] ?? $input['phone'] ?? $input['celular'] ?? ''));
$userId = (int)($input['user_id'] ?? $input['aluno_id'] ?? $input['externalId'] ?? 0);
$actionRaw = strtolower(trim((string)($input['action'] ?? $input['acao'] ?? 'opt_out')));
$optOut = !in_array($actionRaw, ['opt_in', 'subscribe', 'reativar', 'receber'], true);
$source = trim((string)($input['source'] ?? $input['origem'] ?? 'api'));
$reason = trim((string)($input['reason'] ?? $input['motivo'] ?? ($optOut ? 'cancelou_mensagens' : 'reativou_mensagens')));
$externalEventId = trim((string)($input['event_id'] ?? $input['external_event_id'] ?? $input['message_id'] ?? ''));

if ($userId <= 0 && $email === '' && $phone === '') {
    student_api_log($pdo, (int)$key['id'], '', '', 'messages_bad_request', null, 400, (int)((microtime(true) - $startedAt) * 1000));
    messages_api_error(400, 'parametros_ausentes', 'Informe user_id, email ou telefone.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if ($phone === '' && $userId <= 0) {
        student_api_log($pdo, (int)$key['id'], $email, '', 'messages_bad_request', null, 400, (int)((microtime(true) - $startedAt) * 1000));
        messages_api_error(400, 'email_invalido', 'O e-mail informado nao e valido.');
    }
    $email = '';
}

try {
    if ($userId <= 0) {
        $resolved = student_api_resolve($pdo, $email, $phone);
        if ($resolved['status'] === 'not_found') {
            student_api_log($pdo, (int)$key['id'], $email, $phone, 'messages_not_found', null, 200, (int)((microtime(true) - $startedAt) * 1000));
            messages_api_reply(200, [
                'ok' => true,
                'api_version' => STUDENT_API_VERSION,
                'status' => 'not_found',
                'mensagem' => 'Nenhum aluno encontrado com os dados informados.',
                'busca' => $resolved['match'],
            ]);
        }
        if ($resolved['status'] === 'ambiguous') {
            student_api_log($pdo, (int)$key['id'], $email, $phone, 'messages_ambiguous', null, 409, (int)((microtime(true) - $startedAt) * 1000));
            messages_api_reply(409, [
                'ok' => false,
                'api_version' => STUDENT_API_VERSION,
                'erro' => 'aluno_ambiguo',
                'mensagem' => 'Mais de um aluno corresponde aos dados informados. Envie user_id ou email e telefone juntos.',
                'candidatos' => $resolved['candidates'],
            ]);
        }
        $userId = (int)($resolved['user']['id'] ?? 0);
    }

    $result = student_api_set_message_opt_out($pdo, $userId, $optOut, $source, $reason, $externalEventId, $input);
    $user = $result['user'];
    $status = $optOut ? 'opt_out' : 'opt_in';
    student_api_log($pdo, (int)$key['id'], $email, $phone, 'messages_' . $status, $userId, 200, (int)((microtime(true) - $startedAt) * 1000));

    messages_api_reply(200, [
        'ok' => true,
        'api_version' => STUDENT_API_VERSION,
        'status' => $status,
        'aluno' => [
            'id' => (int)$user['id'],
            'nome' => (string)($user['nome'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'telefone' => (string)($user['telefone'] ?? ''),
        ],
        'preferencias_mensagens' => $result['preferencias_mensagens'],
        'sino_atualizacao_enfileirada' => true,
    ]);
} catch (Throwable $e) {
    @error_log('messages_api: ' . $e->getMessage());
    student_api_log($pdo, (int)$key['id'], $email, $phone, 'messages_error', $userId > 0 ? $userId : null, 500, (int)((microtime(true) - $startedAt) * 1000));
    messages_api_error(500, 'erro_interno', 'Erro ao atualizar preferencia de mensagens. Tente novamente.');
}
