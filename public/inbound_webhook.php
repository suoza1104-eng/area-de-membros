<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/retorno_agendamentos.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

// Tabelas (idempotente)
$pdo->exec("CREATE TABLE IF NOT EXISTS inbound_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao TEXT NULL,
    evento VARCHAR(100) NOT NULL,
    lesson_id INT NULL,
    codigo_turma VARCHAR(100) NULL,
    tag_extra VARCHAR(200) NULL,
    token VARCHAR(64) NOT NULL,
    payload_map_json TEXT NULL,
    disparar_webhook TINYINT(1) NOT NULL DEFAULT 1,
    disparar_sf TINYINT(1) NOT NULL DEFAULT 1,
    disparar_manychat TINYINT(1) NOT NULL DEFAULT 1,
    criar_se_nao_existir TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    total_recebidos INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_iw_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS inbound_webhook_recebimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    user_id INT NULL,
    payload_raw TEXT NOT NULL,
    status ENUM('pendente','processado','erro') NOT NULL DEFAULT 'pendente',
    erro_msg TEXT NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL,
    INDEX idx_iwr_webhook (webhook_id),
    INDEX idx_iwr_status (status),
    INDEX idx_iwr_recebido (recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Migrações defensivas
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN oferta_codigo VARCHAR(500) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_webhook TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_sf TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_manychat TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhook_recebimentos MODIFY COLUMN status ENUM('pendente','processado','erro','ignorado') NOT NULL DEFAULT 'pendente'"); } catch (Throwable $e) {}

// Token
$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? $_GET['token'] ?? ''));
if (strlen($token) !== 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Token inválido']); exit;
}

$st = $pdo->prepare("SELECT * FROM inbound_webhooks WHERE token = :t LIMIT 1");
$st->execute([':t' => $token]);
$ihw = $st->fetch(PDO::FETCH_ASSOC);
if (!$ihw) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Webhook não encontrado']); exit;
}
if ((int)$ihw['ativo'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Webhook pausado']); exit;
}

// Captura payload
$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST ?: [];
    if (!$payload && $rawBody !== '') {
        parse_str($rawBody, $tmp);
        if (is_array($tmp)) $payload = $tmp;
    }
}

// Insere na fila
$ins = $pdo->prepare("INSERT INTO inbound_webhook_recebimentos (webhook_id, payload_raw, status) VALUES (:w, :p, 'pendente')");
$ins->execute([':w' => (int)$ihw['id'], ':p' => $rawBody !== '' ? $rawBody : json_encode($payload, JSON_UNESCAPED_UNICODE)]);
$recId = (int)$pdo->lastInsertId();

// Responde imediato
echo json_encode(['ok' => true, 'queued' => $recId]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { @ob_end_flush(); @flush(); }

// ── Helper: pega valor de path com dot notation (data.buyer.email) ──
function iw_get_value(array $data, string $path): ?string {
    if ($path === '') return null;
    if (strpos($path, '.') === false) {
        $v = $data[$path] ?? null;
        return is_scalar($v) ? (string)$v : null;
    }
    $cur = $data;
    foreach (explode('.', $path) as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
        $cur = $cur[$p];
    }
    return is_scalar($cur) ? (string)$cur : null;
}

function iw_get_certificado_extra(PDO $pdo, int $userId, string $origem): array {
    $st = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND status = 'emitido' ORDER BY id DESC LIMIT 1");
    $st->execute([':uid' => $userId]);
    $cert = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cert) {
        throw new RuntimeException('Usuário sem certificado emitido para reenvio');
    }
    return [
        'codigo_certificado' => $cert['codigo_uid'] ?? '',
        'curso' => $cert['course'] ?? '',
        'emitido_em' => $cert['emitido_em'] ?? '',
        'pdf_url' => $cert['pdf_url'] ?? '',
        'certificado_id' => $cert['id'] ?? null,
        'origem' => $origem,
    ];
}

function iw_get_first_mapped(array $payload, array $map, array $keys): string {
    foreach ($keys as $key) {
        $path = (string)($map[$key] ?? $key);
        $value = iw_get_value($payload, $path);
        if ($value !== null && trim($value) !== '') {
            return trim($value);
        }
    }
    return '';
}

function iw_disparar_integracoes(PDO $pdo, array $ihw, string $evento, int $userId, array $extra = []): bool {
    $user = [];
    try {
        $st = $pdo->prepare("SELECT id,nome,email,telefone FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $user = [
                'id' => $row['id'] ?? $userId,
                'nome' => $row['nome'] ?? null,
                'email' => $row['email'] ?? null,
                'telefone' => $row['telefone'] ?? null,
            ];
        }
    } catch (Throwable $e) {}
    if (!$user) $user = ['id' => $userId, 'nome' => null, 'email' => null, 'telefone' => null];

    $extra['origem'] = $extra['origem'] ?? 'inbound_webhook';
    $extra['inbound_id'] = $extra['inbound_id'] ?? (int)($ihw['id'] ?? 0);
    $ok = false;
    if ((int)($ihw['disparar_webhook'] ?? 1) === 1 && function_exists('disparar_evento_webhooks')) {
        try { disparar_evento_webhooks($pdo, $evento, $user, $extra); $ok = true; } catch (Throwable $e) {}
    }
    if ((int)($ihw['disparar_sf'] ?? 1) === 1 && function_exists('sf_disparar_evento')) {
        try { $ok = sf_disparar_evento($pdo, $evento, $user, $extra) || $ok; } catch (Throwable $e) {}
    }
    if ((int)($ihw['disparar_manychat'] ?? 1) === 1 && function_exists('mc_disparar_evento')) {
        try { $ok = mc_disparar_evento($pdo, $evento, $user, $extra) || $ok; } catch (Throwable $e) {}
    }
    if (function_exists('whatsapp_event_notifications_dispatch')) {
        try { $ok = whatsapp_event_notifications_dispatch($pdo, $evento, $user, $extra) || $ok; } catch (Throwable $e) {}
    }
    return $ok;
}


function iw_disparar_eventos_diretos(PDO $pdo, array $ihw, int $userId, array $extra = []): void {
    $id = (int)($ihw['id'] ?? 0);
    if ($id <= 0) return;

    if ((int)($ihw['disparar_webhook'] ?? 1) === 1) {
        iw_disparar_integracoes($pdo, ['id'=>$id, 'disparar_webhook'=>1, 'disparar_sf'=>0, 'disparar_manychat'=>0], 'INBOUND_WEBHOOK_' . $id, $userId, $extra);
    }
    if ((int)($ihw['disparar_sf'] ?? 1) === 1) {
        iw_disparar_integracoes($pdo, ['id'=>$id, 'disparar_webhook'=>0, 'disparar_sf'=>1, 'disparar_manychat'=>0], 'INBOUND_SF_' . $id, $userId, $extra);
    }
    if ((int)($ihw['disparar_manychat'] ?? 1) === 1) {
        iw_disparar_integracoes($pdo, ['id'=>$id, 'disparar_webhook'=>0, 'disparar_sf'=>0, 'disparar_manychat'=>1], 'INBOUND_MANYCHAT_' . $id, $userId, $extra);
    }
}

// ── Processa ──────────────────────────────────────────────────────────────────
try {
    $map = [];
    if (!empty($ihw['payload_map_json'])) {
        $tmp = json_decode($ihw['payload_map_json'], true);
        if (is_array($tmp)) $map = $tmp;
    }
    $defaults = [
        'nome' => 'nome',
        'email' => 'email',
        'telefone' => 'telefone',
        'oferta' => 'oferta',
        'transacao' => 'data.purchase.transaction',
        'status_pagamento' => 'data.purchase.status',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term',
        'utm_content' => 'utm_content',
        'retorno_data' => 'retorno_data',
        'retorno_tipo' => 'retorno_tipo',
        'retorno_assunto' => 'retorno_assunto',
        'retorno_mensagem' => 'retorno_mensagem',
    ];
    foreach ($defaults as $k => $v) if (!isset($map[$k])) $map[$k] = $v;
    $ofertaRecebida = trim((string)(iw_get_value($payload, (string)$map['oferta']) ?? ''));
    $nome     = trim((string)(iw_get_value($payload, (string)$map['nome'])     ?? ''));
    $email    = trim((string)(iw_get_value($payload, (string)$map['email'])    ?? ''));
    $telefone = trim((string)(iw_get_value($payload, (string)$map['telefone']) ?? ''));

    // ── FILTRO DE OFERTA ──
    // Se oferta_codigo configurado, exige que o valor no payload corresponda
    $ofertaCfg = trim((string)($ihw['oferta_codigo'] ?? ''));
    if ($ofertaCfg !== '') {
        $aceitas = array_filter(array_map('trim', explode(',', $ofertaCfg)));
        $bateu = in_array($ofertaRecebida, $aceitas, true);
        if (!$bateu) {
            $lifetimeAttempt = course_access_try_grant_lifetime_purchase($pdo, [
                'offer_code' => $ofertaRecebida,
                'transaction_code' => iw_get_first_mapped($payload, $map, ['transacao', 'transaction', 'transaction_code']),
                'status' => iw_get_first_mapped($payload, $map, ['status_pagamento', 'status']),
                'event' => (string)($payload['event'] ?? $payload['evento'] ?? ''),
                'email' => $email,
                'phone' => $telefone,
                'payload' => $payload,
                'source' => 'inbound_webhook',
            ]);
            if (!empty($lifetimeAttempt['granted'])) {
                $uid = (int)($lifetimeAttempt['user_id'] ?? 0);
                if ($uid > 0) {
                    $paidRegistration = enrollment_register($pdo, [
                        'nome'=>$nome, 'email'=>$email, 'telefone'=>$telefone,
                        'codigo_turma'=>(string)($lifetimeAttempt['turma_codigo'] ?? ''),
                        'access_type'=>'lifetime', 'source'=>'inbound_webhook',
                        'offer_code'=>$ofertaRecebida,
                        'transaction_code'=>(string)($lifetimeAttempt['transaction_code'] ?? ''),
                        'grant_type'=>'paid', 'is_paid'=>true, 'payload'=>$payload,
                    ]);
                    iw_disparar_integracoes($pdo, $ihw, (string)$paidRegistration['event'], $uid, (array)$paidRegistration['extras']);
                    iw_disparar_integracoes($pdo, $ihw, 'INSCRICAO_VITALICIA', $uid, (array)$paidRegistration['extras']);
                    iw_disparar_integracoes($pdo, $ihw, 'ACESSO_VITALICIO_LIBERADO', $uid, [
                        'origem' => 'inbound_webhook',
                        'codigo_turma' => (string)($lifetimeAttempt['turma_codigo'] ?? ''),
                        'oferta' => $ofertaRecebida,
                        'transacao' => (string)($lifetimeAttempt['transaction_code'] ?? ''),
                    ]);
                }
                $pdo->prepare("UPDATE inbound_webhook_recebimentos SET status='processado', user_id=:u, processado_em=NOW() WHERE id=:i")
                    ->execute([':u' => $uid ?: null, ':i' => $recId]);
                $pdo->prepare("UPDATE inbound_webhooks SET total_recebidos = total_recebidos + 1 WHERE id = :i")
                    ->execute([':i' => (int)$ihw['id']]);
                exit;
            }
            $pdo->prepare("UPDATE inbound_webhook_recebimentos SET status='ignorado', erro_msg=:m, processado_em=NOW() WHERE id=:i")
                ->execute([':m' => 'Oferta nao corresponde. Recebida: ' . ($ofertaRecebida !== '' ? $ofertaRecebida : '(vazia)') . ' | Aceitas: ' . $ofertaCfg . ' | Vitalicio: ' . (string)($lifetimeAttempt['reason'] ?? 'nao_liberado'), ':i' => $recId]);
            exit;
        }
    }

    // Localiza usuário existente
    if (in_array((string)$ihw['evento'], ['INSCRITO', 'INSCRICAO_GRATUITA', 'INSCRICAO_VITALICIA', 'LIBERAR_ACESSO_VITALICIO'], true)) {
        $isPaidLifetime = (string)$ihw['evento'] === 'LIBERAR_ACESSO_VITALICIO';
        $accessType = in_array((string)$ihw['evento'], ['INSCRICAO_VITALICIA', 'LIBERAR_ACESSO_VITALICIO'], true) ? 'lifetime' : 'free';
        $transactionCode = '';
        $registrationTurmaCode = trim((string)($ihw['codigo_turma'] ?? ''));
        if ($isPaidLifetime && $registrationTurmaCode === '') {
            $purchaseUser = enrollment_find_user($pdo, $email, $telefone);
            $registrationTurmaCode = $purchaseUser ? course_access_user_turma_code($purchaseUser) : '';
        }
        if ($isPaidLifetime) {
            $statusPagamento = iw_get_first_mapped($payload, $map, ['status_pagamento', 'status']);
            $eventoPagamento = (string)($payload['event'] ?? $payload['evento'] ?? '');
            if (!course_access_purchase_is_approved($statusPagamento, $eventoPagamento)) {
                throw new RuntimeException('Pagamento ainda nao aprovado.');
            }
            $transactionCode = iw_get_first_mapped($payload, $map, ['transacao', 'transaction', 'transaction_code']);
            if ($transactionCode === '') throw new RuntimeException('Webhook sem codigo de transacao.');
            $turmaValidacao = enrollment_find_turma($pdo, $registrationTurmaCode);
            $offers = course_access_offer_codes((string)($turmaValidacao['lifetime_offer_codes'] ?? ''));
            if ($ofertaRecebida === '' || !in_array($ofertaRecebida, $offers, true)) {
                throw new RuntimeException('Oferta recebida nao libera acesso vitalicio nesta turma.');
            }
        }
        $registration = enrollment_register($pdo, [
            'nome'=>$nome, 'email'=>$email, 'telefone'=>$telefone,
            'codigo_turma'=>$registrationTurmaCode,
            'utm_source'=>iw_get_first_mapped($payload, $map, ['utm_source']),
            'utm_medium'=>iw_get_first_mapped($payload, $map, ['utm_medium']),
            'utm_campaign'=>iw_get_first_mapped($payload, $map, ['utm_campaign']),
            'utm_term'=>iw_get_first_mapped($payload, $map, ['utm_term']),
            'utm_content'=>iw_get_first_mapped($payload, $map, ['utm_content']),
            'access_type'=>$accessType, 'source'=>'inbound_webhook',
            'offer_code'=>$ofertaRecebida,
            'transaction_code'=>$transactionCode,
            'grant_type'=>$isPaidLifetime ? 'paid' : 'integration',
            'is_paid'=>$isPaidLifetime,
            'payload'=>$payload,
        ]);
        $userId = (int)$registration['user_id'];
        $codigoTurmaCfg = (string)$registration['codigo_turma'];
        if (function_exists('adicionar_tag') && !empty($ihw['tag_extra'])) {
            adicionar_tag($userId, (string)$ihw['tag_extra'], 'inbound_webhook', (int)$ihw['id']);
        }
        iw_disparar_integracoes($pdo, $ihw, (string)$registration['event'], $userId, (array)$registration['extras']);
        iw_disparar_integracoes($pdo, $ihw, (string)$registration['access_event'], $userId, (array)$registration['extras']);
        if ($accessType === 'lifetime') {
            iw_disparar_integracoes($pdo, $ihw, 'ACESSO_VITALICIO_LIBERADO', $userId, (array)$registration['extras']);
        }
        iw_disparar_eventos_diretos($pdo, $ihw, $userId, [
            'origem'=>'inbound_webhook', 'inbound_id'=>(int)$ihw['id'],
            'evento_base'=>(string)$ihw['evento'], 'codigo_turma'=>$codigoTurmaCfg,
            'payload_raw'=>$payload,
        ]);
        $pdo->prepare("UPDATE inbound_webhook_recebimentos SET status='processado', user_id=:u, processado_em=NOW() WHERE id=:i")
            ->execute([':u'=>$userId, ':i'=>$recId]);
        $pdo->prepare("UPDATE inbound_webhooks SET total_recebidos = total_recebidos + 1 WHERE id = :i")
            ->execute([':i'=>(int)$ihw['id']]);
        exit;
    }

    $userId = 0;
    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        $row = $st->fetch();
        if ($row) $userId = (int)$row['id'];
    }
    if ($userId === 0 && $telefone !== '') {
        $telLimpo = preg_replace('/\D+/', '', $telefone);
        if (strlen((string)$telLimpo) >= 12 && str_starts_with((string)$telLimpo, '55')) {
            $telLimpo = substr((string)$telLimpo, 2);
        }
        $st = $pdo->prepare("SELECT id FROM users WHERE telefone = :t OR telefone = :t2 LIMIT 1");
        $st->execute([':t' => $telefone, ':t2' => $telLimpo]);
        $row = $st->fetch();
        if ($row) $userId = (int)$row['id'];
    }

    $evento = (string)$ihw['evento'];
    $criar  = (int)$ihw['criar_se_nao_existir'] === 1;
    $codigoTurmaCfg = trim((string)($ihw['codigo_turma'] ?? ''));

    // Fallback: se não há turma fixa, pega a com janela aberta agora (mesma lógica do api_inscrever)
    if ($codigoTurmaCfg === '' && $evento !== 'LIBERAR_ACESSO_VITALICIO') {
        try {
            $stT = $pdo->prepare("SELECT codigo FROM turmas
                                  WHERE janela_inicio <= NOW() AND janela_fim >= NOW()
                                  ORDER BY janela_inicio DESC LIMIT 1");
            $stT->execute();
            $tmpCod = (string)($stT->fetchColumn() ?: '');
            if ($tmpCod !== '') $codigoTurmaCfg = $tmpCod;
        } catch (Throwable $e) {}
    }

    // Para INSCRITO sempre cria se não existir (caso Hotmart/Kiwify)
    $forcaCriar = ($evento === 'INSCRITO');

    if ($userId === 0 && ($criar || $forcaCriar)) {
        if ($email === '' && $telefone === '') {
            throw new RuntimeException('Payload sem email nem telefone — não é possível criar');
        }
        $telLimpo = preg_replace('/\D+/', '', $telefone) ?: bin2hex(random_bytes(3));
        $hash = password_hash($telLimpo, PASSWORD_DEFAULT);
        // Detecta coluna de turma
        $turmaCol = null;
        try {
            if ($pdo->query("SHOW COLUMNS FROM users LIKE 'codigo_turma'")->fetch()) $turmaCol = 'codigo_turma';
            elseif ($pdo->query("SHOW COLUMNS FROM users LIKE 'turma_id'")->fetch()) $turmaCol = 'turma_id';
        } catch (Throwable $e) {}

        $cols  = ['nome','email','telefone','senha_hash','created_at'];
        $vals  = [':n', ':e', ':t', ':s', 'NOW()'];
        $par   = [':n' => $nome !== '' ? $nome : ($email ?: $telefone), ':e' => $email, ':t' => $telefone, ':s' => $hash];
        if ($turmaCol === 'codigo_turma' && $codigoTurmaCfg !== '') {
            $cols[] = 'codigo_turma'; $vals[] = ':ct'; $par[':ct'] = $codigoTurmaCfg;
        }
        $sql = "INSERT INTO users (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
        $pdo->prepare($sql)->execute($par);
        $userId = (int)$pdo->lastInsertId();
    }

    if ($userId === 0) {
        throw new RuntimeException('Usuário não encontrado e criação desabilitada');
    }

    // Atualiza nome/telefone/email se vieram preenchidos no payload
    // (sobrescreve só quando o payload traz algo — preserva valores existentes em caso contrário)
    if ($nome !== '' || $telefone !== '' || $email !== '') {
        try {
            $pdo->prepare("UPDATE users
                              SET nome     = CASE WHEN :n_set = 1 THEN :n ELSE nome END,
                                  telefone = CASE WHEN :t_set = 1 THEN :t ELSE telefone END,
                                  email    = CASE WHEN :e_set = 1 AND (email IS NULL OR email = '') THEN :e ELSE email END
                            WHERE id = :id")
                ->execute([
                    ':n_set' => $nome     !== '' ? 1 : 0, ':n' => $nome,
                    ':t_set' => $telefone !== '' ? 1 : 0, ':t' => $telefone,
                    ':e_set' => $email    !== '' ? 1 : 0, ':e' => $email,
                    ':id'    => $userId,
                ]);
        } catch (Throwable $e) { /* não crítico */ }
    }

    // Tag extra (sempre aplicada se configurada)
    if (function_exists('adicionar_tag') && !empty($ihw['tag_extra'])) {
        adicionar_tag($userId, (string)$ihw['tag_extra'], 'inbound_webhook', (int)$ihw['id']);
    }

    // Lógica específica do evento
    if ($evento !== 'LIBERAR_ACESSO_VITALICIO') {
        $autoLifetimeAttempt = course_access_try_grant_lifetime_purchase($pdo, [
            'user_id' => $userId,
            'offer_code' => $ofertaRecebida,
            'transaction_code' => iw_get_first_mapped($payload, $map, ['transacao', 'transaction', 'transaction_code']),
            'status' => iw_get_first_mapped($payload, $map, ['status_pagamento', 'status']),
            'event' => (string)($payload['event'] ?? $payload['evento'] ?? ''),
            'email' => $email,
            'phone' => $telefone,
            'payload' => $payload,
            'source' => 'inbound_webhook',
        ]);
        if (!empty($autoLifetimeAttempt['granted'])) {
            iw_disparar_integracoes($pdo, $ihw, 'ACESSO_VITALICIO_LIBERADO', $userId, [
                'origem' => 'inbound_webhook',
                'codigo_turma' => (string)($autoLifetimeAttempt['turma_codigo'] ?? ''),
                'oferta' => $ofertaRecebida,
                'transacao' => (string)($autoLifetimeAttempt['transaction_code'] ?? ''),
            ]);
        }
    }

    switch ($evento) {
        case 'INSCRITO':
            if (function_exists('adicionar_tag')) {
                adicionar_tag($userId, 'INSCRITO', 'inbound_webhook', (int)$ihw['id']);
                if ($codigoTurmaCfg !== '') adicionar_tag($userId, 'INSCRITO_TURMA_' . $codigoTurmaCfg, 'inbound_webhook', (int)$ihw['id']);
            }
            // Loga em inscricao_logs (idem api_inscrever)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS inscricao_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL, codigo_turma VARCHAR(100) NULL,
                    utm_source VARCHAR(255) NULL, utm_medium VARCHAR(255) NULL,
                    utm_campaign VARCHAR(255) NULL, utm_term VARCHAR(255) NULL,
                    utm_content VARCHAR(255) NULL, is_novo TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT NOW(),
                    INDEX(user_id), INDEX(created_at))");
                $pdo->prepare("INSERT INTO inscricao_logs (user_id, codigo_turma, is_novo) VALUES (:u, :c, 1)")
                    ->execute([':u' => $userId, ':c' => $codigoTurmaCfg ?: null]);
            } catch (Throwable $e) {}
            iw_disparar_integracoes($pdo, $ihw, 'INSCRITO', $userId, [
                'codigo_turma' => $codigoTurmaCfg,
                'origem'       => 'inbound_webhook',
                'inbound_id'   => (int)$ihw['id'],
                'payload_raw'  => $payload,
            ]);
            break;

        case 'PRIMEIRO_LOGIN':
            try { $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :i")->execute([':i' => $userId]); } catch (Throwable $e) {}
            if (function_exists('adicionar_tag')) adicionar_tag($userId, 'PRIMEIRO_LOGIN', 'inbound_webhook', (int)$ihw['id']);
            iw_disparar_integracoes($pdo, $ihw, 'PRIMEIRO_LOGIN', $userId, ['origem' => 'inbound_webhook']);
            break;

        case 'VIU_AULA':
            $lessonId = (int)($ihw['lesson_id'] ?? 0);
            if ($lessonId <= 0) throw new RuntimeException('VIU_AULA sem lesson_id configurado');
            // Upsert lesson_progress
            $stP = $pdo->prepare("SELECT id FROM lesson_progress WHERE user_id = :u AND lesson_id = :l LIMIT 1");
            $stP->execute([':u' => $userId, ':l' => $lessonId]);
            if ($stP->fetch()) {
                $pdo->prepare("UPDATE lesson_progress SET status='completed', updated_at=NOW() WHERE user_id=:u AND lesson_id=:l")->execute([':u'=>$userId,':l'=>$lessonId]);
            } else {
                $pdo->prepare("INSERT INTO lesson_progress (user_id, lesson_id, status, created_at) VALUES (:u,:l,'completed',NOW())")->execute([':u'=>$userId,':l'=>$lessonId]);
            }
            iw_disparar_integracoes($pdo, $ihw, 'VIU_AULA_' . $lessonId, $userId, ['lesson_id' => $lessonId, 'origem' => 'inbound_webhook']);
            iw_disparar_integracoes($pdo, $ihw, 'ASSISTIU_ALGUMA_AULA', $userId, ['lesson_id' => $lessonId, 'origem' => 'inbound_webhook']);
            break;

        case 'CONCLUIU_TRILHA':
            // Marca todas obrigatórias como completed
            try {
                $stL = $pdo->query("SELECT id FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1");
                foreach ($stL->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                    $check = $pdo->prepare("SELECT id FROM lesson_progress WHERE user_id=:u AND lesson_id=:l LIMIT 1");
                    $check->execute([':u'=>$userId,':l'=>$lid]);
                    if ($check->fetch()) {
                        $pdo->prepare("UPDATE lesson_progress SET status='completed' WHERE user_id=:u AND lesson_id=:l")->execute([':u'=>$userId,':l'=>$lid]);
                    } else {
                        $pdo->prepare("INSERT INTO lesson_progress (user_id, lesson_id, status, created_at) VALUES (:u,:l,'completed',NOW())")->execute([':u'=>$userId,':l'=>$lid]);
                    }
                }
            } catch (Throwable $e) {}
            iw_disparar_integracoes($pdo, $ihw, 'CONCLUIU_TRILHA', $userId, ['origem' => 'inbound_webhook']);
            break;

        case 'CERT_EMITIDO':
            iw_disparar_integracoes($pdo, $ihw, 'CERT_EMITIDO', $userId, ['origem' => 'inbound_webhook']);
            break;

        case 'REENVIO_CERTIFICADO':
            iw_disparar_integracoes($pdo, $ihw, 'REENVIO_CERTIFICADO', $userId, iw_get_certificado_extra($pdo, $userId, 'inbound_webhook'));
            break;

        case 'AGENDAR_RETORNO':
            retorno_ensure_tables($pdo);
            $retornoData = iw_get_first_mapped($payload, $map, ['retorno_data', 'data_retorno', 'scheduled_at', 'data_hora']);
            $retornoTipo = iw_get_first_mapped($payload, $map, ['retorno_tipo', 'tipo', 'tipo_retorno']);
            $retornoAssunto = iw_get_first_mapped($payload, $map, ['retorno_assunto', 'assunto', 'subject']);
            $retornoMensagem = iw_get_first_mapped($payload, $map, ['retorno_mensagem', 'mensagem', 'message']);
            $agId = retorno_criar_agendamento($pdo, $userId, $retornoTipo ?: 'outro', $retornoData, $retornoMensagem, 'inbound_webhook', [
                'inbound_id' => (int)$ihw['id'],
                'payload_raw' => $payload,
            ], $retornoAssunto);
            if (function_exists('adicionar_tag')) adicionar_tag($userId, 'RETORNO_AGENDADO', 'inbound_webhook', $agId);
            break;

        case 'LIBERAR_ACESSO_VITALICIO':
            $statusPagamento = strtoupper(trim((string)(iw_get_value($payload, (string)$map['status_pagamento']) ?? '')));
            $eventoPagamento = strtoupper(trim((string)($payload['event'] ?? $payload['evento'] ?? '')));
            $aprovado = in_array($statusPagamento, ['APPROVED', 'APROVADO', 'COMPLETE', 'COMPLETO', 'PAID'], true)
                || in_array($eventoPagamento, ['PURCHASE_APPROVED', 'PURCHASE_COMPLETE', 'PURCHASE_COMPLETED'], true);
            if (!$aprovado) {
                throw new RuntimeException('Pagamento ainda nao aprovado. Status: ' . ($statusPagamento ?: $eventoPagamento ?: '(vazio)'));
            }

            $transactionCode = trim((string)(iw_get_value($payload, (string)$map['transacao']) ?? ''));
            if ($transactionCode === '') {
                $transactionCode = trim((string)(iw_get_value($payload, 'data.purchase.transaction') ?? ''));
            }
            if ($transactionCode === '') throw new RuntimeException('Webhook sem codigo de transacao.');

            if ($codigoTurmaCfg === '') {
                try {
                    $st = $pdo->prepare("SELECT codigo_turma, turma_codigo FROM users WHERE id = :id LIMIT 1");
                    $st->execute([':id' => $userId]);
                    $userTurma = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                    $codigoTurmaCfg = trim((string)($userTurma['codigo_turma'] ?? $userTurma['turma_codigo'] ?? ''));
                } catch (Throwable $e) {}
            }
            if ($codigoTurmaCfg === '') throw new RuntimeException('Aluno sem turma para validar a oferta vitalicia.');

            $st = $pdo->prepare("SELECT lifetime_offer_codes FROM turmas WHERE codigo = :codigo LIMIT 1");
            $st->execute([':codigo' => $codigoTurmaCfg]);
            $offerCodes = course_access_offer_codes((string)($st->fetchColumn() ?: ''));
            if (!$offerCodes) throw new RuntimeException('Turma sem codigo de oferta vitalicia configurado.');
            if ($ofertaRecebida === '' || !in_array($ofertaRecebida, $offerCodes, true)) {
                throw new RuntimeException('Oferta recebida nao libera acesso vitalicio nesta turma.');
            }

            course_access_grant_lifetime(
                $pdo,
                $userId,
                $transactionCode,
                $ofertaRecebida,
                $codigoTurmaCfg,
                $payload
            );
            if (function_exists('adicionar_tag')) {
                adicionar_tag($userId, 'ACESSO_VITALICIO', 'inbound_webhook', (int)$ihw['id']);
            }
            iw_disparar_integracoes($pdo, $ihw, 'ACESSO_VITALICIO_LIBERADO', $userId, [
                'origem' => 'inbound_webhook',
                'codigo_turma' => $codigoTurmaCfg,
                'oferta' => $ofertaRecebida,
                'transacao' => $transactionCode,
            ]);
            break;

        case 'TAG_CUSTOM':
        default:
            // Tag custom já foi aplicada acima. Apenas dispara o evento configurado (raw)
            iw_disparar_integracoes($pdo, $ihw, $evento, $userId, ['origem' => 'inbound_webhook', 'payload_raw' => $payload]);
            break;
    }

    iw_disparar_eventos_diretos($pdo, $ihw, $userId, [
        'origem' => 'inbound_webhook',
        'inbound_id' => (int)$ihw['id'],
        'evento_base' => $evento,
        'codigo_turma' => $codigoTurmaCfg,
        'payload_raw' => $payload,
    ]);

    $pdo->prepare("UPDATE inbound_webhook_recebimentos SET status='processado', user_id=:u, processado_em=NOW() WHERE id=:i")
        ->execute([':u' => $userId, ':i' => $recId]);
    $pdo->prepare("UPDATE inbound_webhooks SET total_recebidos = total_recebidos + 1 WHERE id = :i")
        ->execute([':i' => (int)$ihw['id']]);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE inbound_webhook_recebimentos SET status='erro', erro_msg=:m, processado_em=NOW() WHERE id=:i")
        ->execute([':m' => $e->getMessage(), ':i' => $recId]);
}
