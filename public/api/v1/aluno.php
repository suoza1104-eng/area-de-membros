<?php
declare(strict_types=1);

/**
 * POST /public/api/v1/aluno.php
 * Header: X-API-Key: amk_...   (ou Authorization: Bearer amk_...)
 * Body JSON: {"email": "...", "telefone": "..."}
 *
 * Documentacao: admin > Integracoes > Chaves API > Documentacao.
 */

require_once __DIR__ . '/../../../app/funcoes.php';
require_once __DIR__ . '/../../../app/student_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '0');
// Endpoint sem sessao: libera o lock para nao segurar outras requisicoes.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$startedAt = microtime(true);

function student_api_reply(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function student_api_error(int $status, string $code, string $message): void
{
    student_api_reply($status, ['ok' => false, 'api_version' => STUDENT_API_VERSION, 'erro' => $code, 'mensagem' => $message]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    student_api_error(405, 'metodo_nao_permitido', 'Use POST com JSON {"email": "...", "telefone": "..."}.');
}

try {
    $pdo = getPDO();
    student_api_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('student_api db: ' . $e->getMessage());
    student_api_error(503, 'indisponivel', 'Servico temporariamente indisponivel. Tente novamente.');
}

$key = student_api_find_key($pdo, student_api_request_key());
if (!$key) {
    student_api_log($pdo, null, '', '', 'unauthorized', null, 401, (int)((microtime(true) - $startedAt) * 1000));
    student_api_error(401, 'chave_invalida', 'Chave de API ausente ou invalida. Envie no header X-API-Key.');
}
if ($key['status'] !== 'active') {
    student_api_log($pdo, (int)$key['id'], '', '', 'revoked', null, 403, (int)((microtime(true) - $startedAt) * 1000));
    student_api_error(403, 'chave_revogada', 'Esta chave de API foi revogada.');
}
if (student_api_rate_limited($pdo, $key)) {
    header('Retry-After: 60');
    student_api_error(429, 'limite_excedido', 'Limite de ' . (int)$key['rate_limit_per_minute'] . ' consultas por minuto atingido para esta chave.');
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

if ($email === '' && $phone === '') {
    student_api_log($pdo, (int)$key['id'], '', '', 'bad_request', null, 400, (int)((microtime(true) - $startedAt) * 1000));
    student_api_error(400, 'parametros_ausentes', 'Informe ao menos "email" ou "telefone".');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if ($phone === '') {
        student_api_log($pdo, (int)$key['id'], $email, '', 'bad_request', null, 400, (int)((microtime(true) - $startedAt) * 1000));
        student_api_error(400, 'email_invalido', 'O e-mail informado nao e valido.');
    }
    $email = ''; // segue so pelo telefone
}

try {
    $result = student_api_lookup($pdo, $email, $phone, (int)$key['scope_financial'] === 1);
} catch (Throwable $e) {
    @error_log('student_api lookup: ' . $e->getMessage());
    student_api_log($pdo, (int)$key['id'], $email, $phone, 'error', null, 500, (int)((microtime(true) - $startedAt) * 1000));
    student_api_error(500, 'erro_interno', 'Erro ao consultar o aluno. Tente novamente.');
}

student_api_log(
    $pdo,
    (int)$key['id'],
    $email,
    $phone,
    $result['status'],
    isset($result['aluno']['id']) ? (int)$result['aluno']['id'] : null,
    200,
    (int)((microtime(true) - $startedAt) * 1000)
);
student_api_reply(200, $result);
