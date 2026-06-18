<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

const FORM_LEAD_ORIGINS = [
    'https://professoremersonleite.com',
    'https://www.professoremersonleite.com',
];

function form_lead_response(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function form_lead_log(string $level, string $message, array $context = []): void
{
    try {
        log_sistema($level, 'formulario_lead', $message, $context);
    } catch (Throwable $e) {
        @error_log('formulario_lead: ' . $message . ' | ' . $e->getMessage());
    }
}

function form_lead_origin(): string
{
    return rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
}

function form_lead_is_allowed_origin(string $origin): bool
{
    if (in_array($origin, FORM_LEAD_ORIGINS, true)) {
        return true;
    }
    return (bool)preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin);
}

function form_lead_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $json = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($json) ? $json : [];
}

function form_lead_post_to_subscription(array $payload): array
{
    $url = rtrim(BASE_URL, '/') . '/api_inscrever.php';
    $body = http_build_query($payload);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $error !== '') {
            throw new RuntimeException('Falha ao conectar com o fluxo de inscrição: ' . $error);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        if ($raw === false) {
            throw new RuntimeException('Falha ao conectar com o fluxo de inscrição.');
        }
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Resposta inválida do fluxo de inscrição.');
    }
    return ['status' => $status, 'body' => $json];
}

$origin = form_lead_origin();
if (form_lead_is_allowed_origin($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Max-Age: 86400');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    if (!form_lead_is_allowed_origin($origin)) {
        form_lead_response(403, ['ok' => false, 'error' => 'origin_not_allowed']);
    }
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    form_lead_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$eventId = 'lead_' . bin2hex(random_bytes(12));
$source = 'formulario_site';

try {
    if (!form_lead_is_allowed_origin($origin)) {
        throw new RuntimeException('Origem do formulário não autorizada.');
    }

    $data = form_lead_input();
    $source = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($data['form_source'] ?? $source)) ?: $source;

    if (trim((string)($data['honeypot'] ?? '')) !== '') {
        form_lead_log('warning', 'Envio bloqueado pelo honeypot', [
            'event_id' => $eventId, 'source' => $source, 'origin' => $origin,
        ]);
        form_lead_response(200, ['ok' => true, 'event_id' => $eventId]);
    }

    $nome = trim((string)($data['FNAME'] ?? $data['nome'] ?? ''));
    $email = mb_strtolower(trim((string)($data['EMAIL'] ?? $data['email'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string)($data['PHONE'] ?? $data['telefone'] ?? '')) ?? '';
    $ddi = preg_replace('/\D+/', '', (string)($data['DDI'] ?? '55')) ?: '55';

    if ($nome === '' || $email === '' || $phone === '') {
        throw new InvalidArgumentException('Nome, e-mail e telefone são obrigatórios.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('E-mail inválido.');
    }
    if (strlen($phone) < 8 || strlen($phone) > 15) {
        throw new InvalidArgumentException('Telefone inválido.');
    }

    $telefone = $ddi === '55' ? $phone : $ddi . $phone;
    $subscriptionPayload = [
        'nome' => $nome,
        'email' => $email,
        'telefone' => $telefone,
        'utm_source' => trim((string)($data['utm_source'] ?? '')) ?: $source,
        'utm_medium' => trim((string)($data['utm_medium'] ?? '')) ?: 'formulario',
        'utm_campaign' => trim((string)($data['utm_campaign'] ?? '')),
        'utm_term' => trim((string)($data['utm_term'] ?? '')),
        'utm_content' => trim((string)($data['utm_content'] ?? '')),
    ];

    form_lead_log('info', 'Lead recebido do formulário', [
        'event_id' => $eventId,
        'source' => $source,
        'origin' => $origin,
        'email' => $email,
        'telefone' => $telefone,
        'gclid' => trim((string)($data['gclid'] ?? '')),
        'fbclid' => trim((string)($data['fbclid'] ?? '')),
    ]);

    $result = form_lead_post_to_subscription($subscriptionPayload);
    $resultBody = $result['body'];
    if ($result['status'] < 200 || $result['status'] >= 300 || empty($resultBody['ok'])) {
        throw new RuntimeException((string)($resultBody['message'] ?? $resultBody['error'] ?? 'Falha no fluxo de inscrição.'));
    }

    form_lead_log('info', 'Lead processado com sucesso', [
        'event_id' => $eventId,
        'source' => $source,
        'user_id' => (int)($resultBody['user_id'] ?? 0),
        'cadastrado' => (bool)($resultBody['cadastrado'] ?? false),
        'codigo_turma' => $resultBody['codigo_turma'] ?? null,
    ]);

    form_lead_response(200, [
        'ok' => true,
        'event_id' => $eventId,
        'user_id' => (int)($resultBody['user_id'] ?? 0),
        'cadastrado' => (bool)($resultBody['cadastrado'] ?? false),
        'codigo_turma' => $resultBody['codigo_turma'] ?? null,
    ]);
} catch (InvalidArgumentException $e) {
    form_lead_log('warning', 'Lead rejeitado pelo formulário', [
        'event_id' => $eventId, 'source' => $source, 'origin' => $origin, 'error' => $e->getMessage(),
    ]);
    form_lead_response(422, ['ok' => false, 'error' => 'validation_error', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    form_lead_log('error', 'Erro ao processar lead do formulário', [
        'event_id' => $eventId, 'source' => $source, 'origin' => $origin, 'error' => $e->getMessage(),
    ]);
    form_lead_response(500, [
        'ok' => false, 'error' => 'internal_error', 'message' => 'Não foi possível concluir a inscrição.',
    ]);
}
