<?php
// FILE: app/webhook_dispatcher.php
declare(strict_types=1);

/**
 * Monta o payload padrão usado em todos os webhooks.
 *
 * Estrutura:
 *  - evento:    código do evento (ex.: PAGAMENTO_APROVADO, CERT_EMITIDO, etc.)
 *  - gateway:   nome do gateway financeiro (hotmart, firepay, dom, pagarme)
 *  - user:      dados cadastrais do aluno/comprador (id, nome, email, telefone, documento, magic_link)
 *  - pagamento: dados da transação (valores, taxas, moeda, método, parcelas, produto, links)
 *  - utm:       origem do tráfego e tags de rastreamento (source, campaign, medium, etc.)
 *  - extra:     dados extras do contexto completo
 *  - timestamp: data/hora em ISO-8601
 */
function wh_first_non_empty(array $values)
{
    foreach ($values as $value) {
        if ($value !== null && $value !== '') return $value;
    }
    return null;
}

function wh_normalize_payment_extra(array $extra): array
{
    $provider = wh_first_non_empty([$extra['provider'] ?? null, $extra['gateway'] ?? null, $extra['origem'] ?? null]);
    if ($provider !== null) {
        $extra['provider'] = $extra['provider'] ?? $provider;
        $extra['gateway'] = $extra['gateway'] ?? $provider;
    }

    $eventCode = wh_first_non_empty([$extra['event_code'] ?? null, $extra['evento'] ?? null]);
    if ($eventCode !== null) {
        $extra['event_code'] = $extra['event_code'] ?? $eventCode;
        $extra['evento'] = $extra['evento'] ?? $eventCode;
    }

    $status = wh_first_non_empty([$extra['normalized_status'] ?? null, $extra['status'] ?? null]);
    if ($status !== null) {
        $extra['normalized_status'] = $extra['normalized_status'] ?? $status;
        $extra['status'] = $extra['status'] ?? $status;
    }

    $transaction = wh_first_non_empty([$extra['transaction_code'] ?? null, $extra['transacao_id'] ?? null]);
    if ($transaction !== null) {
        $extra['transaction_code'] = $extra['transaction_code'] ?? $transaction;
        $extra['transacao_id'] = $extra['transacao_id'] ?? $transaction;
    }

    $paymentMethod = wh_first_non_empty([$extra['payment_method'] ?? null, $extra['metodo_pagamento'] ?? null]);
    if ($paymentMethod !== null) {
        $extra['payment_method'] = $extra['payment_method'] ?? $paymentMethod;
        $extra['metodo_pagamento'] = $extra['metodo_pagamento'] ?? $paymentMethod;
    }

    $installments = wh_first_non_empty([$extra['installments'] ?? null, $extra['parcelas'] ?? null]);
    if ($installments !== null) {
        $extra['installments'] = $extra['installments'] ?? $installments;
        $extra['parcelas'] = $extra['parcelas'] ?? $installments;
    }

    $productName = wh_first_non_empty([$extra['product_name'] ?? null, $extra['produto_nome'] ?? null]);
    if ($productName !== null) {
        $extra['product_name'] = $extra['product_name'] ?? $productName;
        $extra['produto_nome'] = $extra['produto_nome'] ?? $productName;
    }

    $productCode = wh_first_non_empty([$extra['product_code'] ?? null, $extra['produto_id'] ?? null]);
    if ($productCode !== null) {
        $extra['product_code'] = $extra['product_code'] ?? $productCode;
        $extra['produto_id'] = $extra['produto_id'] ?? $productCode;
    }

    $buyerAliases = [
        'name' => ['buyer_name', 'comprador_nome'],
        'email' => ['buyer_email', 'comprador_email'],
        'phone' => ['buyer_phone', 'comprador_telefone'],
        'document' => ['buyer_document', 'comprador_documento'],
    ];
    foreach ($buyerAliases as $keys) {
        $value = wh_first_non_empty(array_map(static fn($key) => $extra[$key] ?? null, $keys));
        if ($value === null) continue;
        foreach ($keys as $key) {
            $extra[$key] = $extra[$key] ?? $value;
        }
    }

    return $extra;
}

function build_webhook_payload(string $evento, array $user, array $extra = []): array
{
    $extra = wh_normalize_payment_extra($extra);
    $uid = (int)($user['id'] ?? 0);
    $magicLink = '';
    if ($uid > 0 && function_exists('gerar_magic_link')) {
        try { $magicLink = gerar_magic_link($uid, 30, false); } catch (Throwable $e) {}
    }

    $meta = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];

    // Detecção e conversão de valores financeiros
    $gross = $extra['valor_bruto'] ?? (isset($extra['gross_amount_cents']) ? ((float)$extra['gross_amount_cents'] / 100) : null);
    $net = $extra['valor_liquido'] ?? (isset($extra['net_amount_cents']) ? ((float)$extra['net_amount_cents'] / 100) : null);
    $fee = $extra['taxa'] ?? (isset($extra['fee_amount_cents']) ? ((float)$extra['fee_amount_cents'] / 100) : null);

    $gateway = $extra['gateway'] ?? $extra['provider'] ?? $extra['origem'] ?? ($meta['gateway'] ?? null);

    return [
        'evento'    => $evento,
        'gateway'   => $gateway,
        'user'      => [
            'id'         => $user['id'] ?? null,
            'nome'       => $user['nome'] ?? $extra['buyer_name'] ?? null,
            'email'      => $user['email'] ?? $extra['buyer_email'] ?? null,
            'telefone'   => $user['telefone'] ?? $extra['buyer_phone'] ?? null,
            'documento'  => $user['documento'] ?? $user['cpf'] ?? $extra['buyer_document'] ?? null,
            'magic_link' => $magicLink,
        ],
        'pagamento' => [
            'gateway'             => $gateway,
            'status'              => $extra['status'] ?? $extra['normalized_status'] ?? null,
            'metodo'              => $extra['payment_method'] ?? null,
            'transacao_id'        => $extra['transaction_code'] ?? $extra['provider_transaction_id'] ?? null,
            'valor_bruto'         => $gross !== null ? round((float)$gross, 2) : null,
            'valor_liquido'       => $net !== null ? round((float)$net, 2) : null,
            'taxa'                => $fee !== null ? round((float)$fee, 2) : null,
            'moeda'               => $extra['currency'] ?? 'BRL',
            'parcelas'            => $extra['installments'] ?? null,
            'produto_nome'        => $extra['product_name'] ?? $extra['produto_nome'] ?? null,
            'produto_id'          => $extra['product_code'] ?? $extra['produto_id'] ?? null,
            'checkout_id'         => $extra['checkout_id'] ?? null,
            'checkout_url'        => $extra['checkout_url'] ?? null,
            'pix_copia_cola'      => $extra['pix_qrcode'] ?? null,
            'pix_qrcode_url'      => $extra['pix_qrcode_url'] ?? null,
            'pix_expira_em'       => $extra['pix_expires_at'] ?? null,
            'boleto_url'          => $extra['boleto_url'] ?? null,
            'boleto_linha'        => $extra['boleto_line'] ?? null,
        ],
        'utm'       => [
            'source'   => $extra['utm_source'] ?? $meta['utm_source'] ?? $meta['src'] ?? null,
            'medium'   => $extra['utm_medium'] ?? $meta['utm_medium'] ?? null,
            'campaign' => $extra['utm_campaign'] ?? $meta['utm_campaign'] ?? null,
            'content'  => $extra['utm_content'] ?? $meta['utm_content'] ?? null,
            'term'     => $extra['utm_term'] ?? $meta['utm_term'] ?? null,
            'src'      => $extra['src'] ?? $meta['src'] ?? null,
            'sck'      => $extra['sck'] ?? $meta['sck'] ?? null,
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

function wh_format_live_br(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function wh_format_live_iso(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
}

function wh_get_user_live_at(PDO $pdo, array $user): ?string
{
    $userId = isset($user['id']) ? (int)$user['id'] : 0;
    if ($userId <= 0) return null;

    $cols = [];
    if (wh_col_exists($pdo, 'users', 'turma_live_at')) $cols[] = 'turma_live_at';
    if (wh_col_exists($pdo, 'users', 'data_live')) $cols[] = 'data_live';
    if (!$cols) return null;

    try {
        $st = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['turma_live_at', 'data_live'] as $col) {
            if (!empty($row[$col])) return (string)$row[$col];
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function wh_enrich_extra_with_payment_product(PDO $pdo, array $extra): array
{
    if (!empty($extra['product_name']) || !empty($extra['produto_nome'])) {
        return $extra;
    }

    $transaction = trim((string)($extra['transaction_code'] ?? $extra['transacao_id'] ?? ''));
    $checkoutId = trim((string)($extra['checkout_id'] ?? ''));
    if ($transaction === '' && $checkoutId === '') {
        return $extra;
    }

    $lookups = [];
    if ($transaction !== '') {
        $lookups[] = [
            "SELECT product_name, external_product_id AS product_code FROM payment_sales WHERE external_transaction_id=:value AND product_name IS NOT NULL AND product_name<>'' ORDER BY last_received_at DESC LIMIT 1",
            $transaction,
        ];
        $lookups[] = [
            "SELECT product_name, product_code FROM student_payment_events WHERE transaction_code=:value AND product_name IS NOT NULL AND product_name<>'' ORDER BY last_seen_at DESC LIMIT 1",
            $transaction,
        ];
    }
    if ($checkoutId !== '') {
        $lookups[] = [
            "SELECT product_name, external_product_id AS product_code FROM payment_sales WHERE external_checkout_id=:value AND product_name IS NOT NULL AND product_name<>'' ORDER BY last_received_at DESC LIMIT 1",
            $checkoutId,
        ];
        $lookups[] = [
            "SELECT product_name, product_code FROM student_payment_events WHERE checkout_id=:value AND product_name IS NOT NULL AND product_name<>'' ORDER BY last_seen_at DESC LIMIT 1",
            $checkoutId,
        ];
    }

    foreach ($lookups as [$sql, $value]) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([':value' => $value]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $productName = trim((string)($row['product_name'] ?? ''));
            if ($productName === '') continue;

            $extra['product_name'] = $productName;
            $extra['produto_nome'] = $extra['produto_nome'] ?? $productName;
            $productCode = trim((string)($row['product_code'] ?? ''));
            if ($productCode !== '' && empty($extra['product_code'])) {
                $extra['product_code'] = $productCode;
            }
            return $extra;
        } catch (Throwable $e) {
            continue;
        }
    }

    return $extra;
}

function wh_enrich_extra_with_codigo_live(PDO $pdo, array $user, array $extra): array
{
    $extra = wh_enrich_extra_with_payment_product($pdo, $extra);
    $turmaCodigo = wh_get_turma_codigo_from_context($pdo, $user, $extra);

    if (empty($extra['codigo_live'])) {
        $codigoLive  = wh_get_codigo_live($pdo, $turmaCodigo);
        if ($codigoLive !== null) {
            $extra['codigo_live'] = $codigoLive;
        }
    }

    $liveAtual = wh_get_user_live_at($pdo, $user);
    if ($liveAtual === null && !empty($extra['data_live_iso'])) {
        $liveAtual = (string)$extra['data_live_iso'];
    }
    if ($liveAtual === null && !empty($extra['data_live'])) {
        $liveAtual = (string)$extra['data_live'];
    }
    if ($liveAtual === null) {
        $liveAtual = wh_get_data_live($pdo, $turmaCodigo);
    }
    if ($liveAtual !== null && trim((string)$liveAtual) !== '') {
        $extra['data_live'] = wh_format_live_br($liveAtual);
        $extra['data_live_iso'] = wh_format_live_iso($liveAtual);
        if (!isset($extra['data']) || !is_array($extra['data'])) {
            $extra['data'] = [];
        }
        $extra['data']['live'] = $extra['data_live'];
        $extra['data']['live_iso'] = $extra['data_live_iso'];
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
        CURLOPT_CONNECTTIMEOUT => 5,   // limita a fase de conexao (destino inacessivel)
        CURLOPT_TIMEOUT        => 15,  // limite total da requisicao
        CURLOPT_NOSIGNAL       => 1,   // garante que os timeouts sejam respeitados
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
    $userId = isset($user['id']) ? (int)$user['id'] : 0;
    if ($userId > 0 && function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $userId)) {
        return;
    }

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
