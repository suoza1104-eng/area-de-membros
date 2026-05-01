<?php
// FILE: app/webhook_dispatcher.php
declare(strict_types=1);
/**
 * Monta o payload padrão usado em todos os webhooks.
 *
 * Estrutura:
 *  - evento: código do evento (ex.: CERT_EMITIDO, VIU_AULA_1, etc.)
 *  - user:   dados básicos do aluno (id, nome, email, telefone)
 *  - extra:  dados extras específicos de cada disparo
 *  - timestamp: data/hora em ISO-8601
 */
function build_webhook_payload(string $evento, array $user, array $extra = []): array
{
    return [
        'evento'    => $evento,
        'user'      => [
            'id'       => $user['id'] ?? null,
            'nome'     => $user['nome'] ?? null,
            'email'    => $user['email'] ?? null,
            'telefone' => $user['telefone'] ?? null,
        ],
        'extra'     => $extra,
        'timestamp' => date('c'),
    ];
}


/**
 * Enriquecimento automático do payload: adiciona codigo_live (slug da live) quando for possível
 * identificar a turma do aluno no momento do disparo.
 *
 * Prioridade para encontrar o código da turma:
 *  1) extra[codigo_turma] / extra[turma_codigo] / extra[turma][codigo]
 *  2) user[codigo_turma] / user[turma_codigo]
 *  3) SELECT em users (por user[id]) para obter o código da turma
 *
 * Depois disso:
 *  - SELECT em turmas (por codigo) para obter turmas.codigo_live
 *
 * Obs.: se a coluna/tabela não existir, o sistema ignora silenciosamente (compatibilidade).
 */
function wh_col_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function wh_get_turma_codigo_from_context(PDO $pdo, array $user, array $extra): ?string
{
    // 1) Extra
    foreach (['codigo_turma', 'turma_codigo'] as $k) {
        if (!empty($extra[$k]) && is_string($extra[$k])) {
            return trim($extra[$k]);
        }
    }
    if (isset($extra['turma']) && is_array($extra['turma']) && !empty($extra['turma']['codigo'])) {
        return trim((string)$extra['turma']['codigo']);
    }

    // 2) User
    foreach (['codigo_turma', 'turma_codigo'] as $k) {
        if (!empty($user[$k]) && is_string($user[$k])) {
            return trim($user[$k]);
        }
    }

    // 3) Busca no banco (users)
    $userId = isset($user['id']) ? (int)$user['id'] : 0;
    if ($userId <= 0) {
        return null;
    }

    $cols = [];
    if (wh_col_exists($pdo, 'users', 'codigo_turma')) $cols[] = 'codigo_turma';
    if (wh_col_exists($pdo, 'users', 'turma_codigo')) $cols[] = 'turma_codigo';
    if (!$cols) return null;

    try {
        $st = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['codigo_turma', 'turma_codigo'] as $k) {
            if (!empty($row[$k])) return trim((string)$row[$k]);
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function wh_get_codigo_live(PDO $pdo, ?string $turmaCodigo): ?string
{
    $turmaCodigo = trim((string)$turmaCodigo);
    if ($turmaCodigo === '') return null;
    if (!wh_col_exists($pdo, 'turmas', 'codigo_live')) return null;

    try {
        $st = $pdo->prepare("SELECT codigo_live FROM turmas WHERE codigo = :c LIMIT 1");
        $st->execute([':c' => $turmaCodigo]);
        $v = $st->fetchColumn();
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

function wh_get_data_live(PDO $pdo, ?string $turmaCodigo): ?string
{
    $turmaCodigo = trim((string)$turmaCodigo);
    if ($turmaCodigo === '') return null;

    // tenta achar uma coluna de data (compatibilidade)
    $col = null;
    foreach (['data_live', 'live_at', 'data_aula_ao_vivo'] as $c) {
        if (wh_col_exists($pdo, 'turmas', $c)) { $col = $c; break; }
    }
    if ($col === null) return null;

    try {
        $st = $pdo->prepare("SELECT {$col} FROM turmas WHERE codigo = :c LIMIT 1");
        $st->execute([':c' => $turmaCodigo]);
        $v = $st->fetchColumn();
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') return null;

        try {
            $dt = new DateTimeImmutable($v);
            return $dt->format('d/m/Y H:i');
        } catch (Throwable $e) {
            return $v;
        }
    } catch (Throwable $e) {
        return null;
    }
}


function wh_enrich_extra_with_codigo_live(PDO $pdo, array $user, array $extra): array
{
    $turmaCodigo = wh_get_turma_codigo_from_context($pdo, $user, $extra);

    if (empty($extra['codigo_live'])) {
        $codigoLive  = wh_get_codigo_live($pdo, $turmaCodigo);
        if ($codigoLive !== null) {
            $extra['codigo_live'] = $codigoLive;
        }
    }

    if (empty($extra['data_live'])) {
        $dataLive = wh_get_data_live($pdo, $turmaCodigo);
        if ($dataLive !== null) {
            $extra['data_live'] = $dataLive;
        }
    }

    // Se existir um bloco de turma no extra, espelha os valores (sem sobrescrever)
    if (isset($extra['turma']) && is_array($extra['turma'])) {
        if (empty($extra['turma']['codigo']) && is_string($turmaCodigo) && $turmaCodigo !== '') {
            $extra['turma']['codigo'] = $turmaCodigo;
        }
        if (!empty($extra['codigo_live']) && empty($extra['turma']['codigo_live'])) {
            $extra['turma']['codigo_live'] = $extra['codigo_live'];
        }
        if (!empty($extra['data_live']) && empty($extra['turma']['data_live'])) {
            $extra['turma']['data_live'] = $extra['data_live'];
        }
    }

    return $extra;
}


/**
 * Envia efetivamente o HTTP request e grava log em webhook_logs.
 *
 * @param PDO         $pdo
 * @param int|null    $webhookId     ID da tabela webhooks (ou null quando for disparo direto)
 * @param int|null    $userId        ID do usuário (para log)
 * @param string      $evento        Código do evento
 * @param string      $url
 * @param string      $metodo        GET / POST (ou outros, mas normalmente POST)
 * @param string|null $headersJson   JSON com headers extras
 * @param string      $payloadFormat 'json' ou 'form'
 * @param array       $payload       Payload estruturado
 */
function enviar_webhook_http(
    PDO $pdo,
    ?int $webhookId,
    ?int $userId,
    string $evento,
    string $url,
    string $metodo,
    ?string $headersJson,
    string $payloadFormat,
    array $payload
): void {
    // Normaliza formato
    $payloadFormat = strtolower($payloadFormat ?: 'json');
    if (!in_array($payloadFormat, ['json', 'form'], true)) {
        $payloadFormat = 'json';
    }

    // JSON oficial usado para log
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $headers   = [];
    $bodyToSend = '';

    if ($payloadFormat === 'form') {
        // application/x-www-form-urlencoded
        $bodyToSend = http_build_query($payload);
        $headers[]  = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        // application/json
        $bodyToSend = $payloadJson;
        $headers[]  = 'Content-Type: application/json';
    }

    // Headers extras configurados em webhooks.headers_json
    if ($headersJson) {
        $extraHeaders = json_decode($headersJson, true);
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }

    $ch = curl_init();

    $metodo = strtoupper($metodo ?: 'POST');

    // Se for GET, coloca o payload na query string
    if ($metodo === 'GET' && $bodyToSend !== '') {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        if ($payloadFormat === 'form') {
            $url .= $sep . $bodyToSend;
        } else {
            $url .= $sep . http_build_query(['payload' => $payloadJson]);
        }
        $bodyToSend = '';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($metodo !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metodo);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyToSend);
    }

    $responseBody = curl_exec($ch);
    $error        = curl_error($ch);
    $status       = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
    curl_close($ch);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO webhook_logs (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at)
            VALUES (:webhook_id, :user_id, :evento, :payload_json, :response_status, :response_body, :error_message, NOW())
        ");
        $stmt->execute([
            ':webhook_id'      => $webhookId,
            ':user_id'         => $userId,
            ':evento'          => $evento,
            ':payload_json'    => $payloadJson,
            ':response_status' => $status,
            ':response_body'   => (string)$responseBody,
            ':error_message'   => $error ?: null,
        ]);
    } catch (Throwable $e) {
        // Se der erro no log, não interrompe o fluxo
    }
}

/**
 * Dispara um único webhook configurado, respeitando o formato do payload.
 *
 * @param PDO   $pdo
 * @param array $webhookRow   Linha da tabela webhooks
 * @param string $evento      Evento que está sendo disparado
 * @param array  $user        Dados do usuário
 * @param array  $extra       Dados extras
 * @param bool   $isTest      Quando true, ignora filtro de evento
 */
function disparar_webhook_configurado(
    PDO $pdo,
    array $webhookRow,
    string $evento,
    array $user,
    array $extra = [],
    bool $isTest = false
): void {
    // Quando não é teste, checa se o evento está na lista configurada
    if (!$isTest) {
        $lista = array_filter(array_map('trim', explode(',', (string)($webhookRow['evento'] ?? ''))));
        if (!in_array($evento, $lista, true)) {
            return;
        }
    }

    $url           = trim((string)($webhookRow['url'] ?? ''));
    $metodo        = (string)($webhookRow['metodo'] ?? 'POST');
    $headersJson   = $webhookRow['headers_json'] ?? null;
    $payloadFormat = $webhookRow['payload_format'] ?? 'json';

    if ($url === '') {
        return;
    }

    $extra = wh_enrich_extra_with_codigo_live($pdo, $user, $extra);

    $payload   = build_webhook_payload($evento, $user, $extra);
$userId    = isset($user['id']) ? (int)$user['id'] : null;
    $webhookId = isset($webhookRow['id']) ? (int)$webhookRow['id'] : null;

    enviar_webhook_http(
        $pdo,
        $webhookId,
        $userId,
        $evento,
        $url,
        $metodo,
        $headersJson,
        $payloadFormat,
        $payload
    );
}

/**
 * Dispara todos os webhooks ativos para um determinado evento.
 */
function disparar_evento_webhooks(PDO $pdo, string $evento, array $user, array $extra = []): void
{
    $stmt = $pdo->query("SELECT * FROM webhooks WHERE ativo = 1");
    if (!$stmt) {
        return;
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        disparar_webhook_configurado($pdo, $row, $evento, $user, $extra, false);
    }
}

/**
 * Dispara um teste manual para um webhook específico a partir do painel.
 *
 * O evento usado será o primeiro listado em webhooks.evento ou 'TESTE_WEBHOOK'
 * caso não haja nenhum. Envia dados fictícios de usuário.
 */
function disparar_webhook_teste(PDO $pdo, int $webhookId): void
{
    $st = $pdo->prepare("SELECT * FROM webhooks WHERE id = :id LIMIT 1");
    $st->execute([':id' => $webhookId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $rawEventos = (string)($row['evento'] ?? '');
    $lista  = array_filter(array_map('trim', explode(',', $rawEventos)));
    $evento = $lista[0] ?? 'TESTE_WEBHOOK';

    $user = [
        'id'       => 9999,
        'nome'     => 'Aluno Teste Webhook',
        'email'    => 'teste@exemplo.com',
        'telefone' => '31999999999',
    ];

    $extra = [
        'origem'               => 'teste_manual_webhook',
        'webhook_id'           => $webhookId,
        'eventos_configurados' => $rawEventos,
        'teste'                => true,
    ];

    disparar_webhook_configurado($pdo, $row, $evento, $user, $extra, true);
}
