<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

// Garante tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS live_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao TEXT NULL,
    tipo ENUM('acessou','oferta','compra','custom') NOT NULL DEFAULT 'acessou',
    tag_nome VARCHAR(200) NOT NULL,
    token VARCHAR(64) NOT NULL,
    payload_map_json TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    total_recebidos INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_le_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS live_event_recebimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NULL,
    payload_raw TEXT NOT NULL,
    status ENUM('pendente','processado','erro') NOT NULL DEFAULT 'pendente',
    erro_msg TEXT NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL,
    INDEX idx_ler_event (event_id),
    INDEX idx_ler_status (status),
    INDEX idx_ler_recebido (recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Token via ?t= ou ?token=
$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['t'] ?? $_GET['token'] ?? ''));
if (strlen($token) !== 64) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Token inválido']);
    exit;
}

// Encontra o evento
$st = $pdo->prepare("SELECT * FROM live_events WHERE token = :t LIMIT 1");
$st->execute([':t' => $token]);
$ev = $st->fetch(PDO::FETCH_ASSOC);
if (!$ev) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Evento não encontrado']);
    exit;
}
if ((int)$ev['ativo'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Evento pausado']);
    exit;
}

// Captura payload (JSON ou form-encoded)
$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST ?: [];
    if (!$payload && $rawBody !== '') {
        // Tenta parse URL-encoded simples
        parse_str($rawBody, $tmp);
        if (is_array($tmp)) $payload = $tmp;
    }
}

// Insere na fila (status pendente)
$ins = $pdo->prepare("INSERT INTO live_event_recebimentos (event_id, payload_raw, status) VALUES (:eid, :pl, 'pendente')");
$ins->execute([
    ':eid' => (int)$ev['id'],
    ':pl'  => $rawBody !== '' ? $rawBody : json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$recebimentoId = (int)$pdo->lastInsertId();

// Resposta imediata (não bloqueia o sistema externo)
echo json_encode(['ok' => true, 'queued' => $recebimentoId]);

// Tenta continuar processamento em background
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

// Helper: pega valor de path com dot notation (data.buyer.email) — fallback flat
function lw_get_value(array $data, string $path): ?string {
    if ($path === '') return null;
    // Tentativa 1: acesso direto (chave literal, mesmo que contenha pontos)
    if (array_key_exists($path, $data) && is_scalar($data[$path])) return (string)$data[$path];
    // Tentativa 2: dot notation
    if (strpos($path, '.') !== false) {
        $cur = $data;
        foreach (explode('.', $path) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
            $cur = $cur[$p];
        }
        return is_scalar($cur) ? (string)$cur : null;
    }
    return null;
}

// ── Processa este recebimento (síncrono após resposta) ────────────────────────
try {
    // Aplica mapeamento de payload (se houver)
    $map = [];
    if (!empty($ev['payload_map_json'])) {
        $tmp = json_decode($ev['payload_map_json'], true);
        if (is_array($tmp)) $map = $tmp;
    }
    // Mapeamento default: nome → nome, email → email, telefone → telefone
    $defaults = ['nome' => 'nome', 'email' => 'email', 'telefone' => 'telefone'];
    foreach ($defaults as $k => $v) {
        if (!isset($map[$k])) $map[$k] = $v;
    }

    $nome     = trim((string)(lw_get_value($payload, (string)$map['nome'])     ?? ''));
    $email    = trim((string)(lw_get_value($payload, (string)$map['email'])    ?? ''));
    $telefone = trim((string)(lw_get_value($payload, (string)$map['telefone']) ?? ''));

    // Fallback: se mapeamento não achou, tenta chaves padrão diretamente
    if ($nome     === '' && isset($payload['nome']))     $nome     = trim((string)$payload['nome']);
    if ($email    === '' && isset($payload['email']))    $email    = trim((string)$payload['email']);
    if ($telefone === '' && isset($payload['telefone'])) $telefone = trim((string)$payload['telefone']);

    if ($email === '' && $telefone === '') {
        throw new RuntimeException('Payload sem email nem telefone. Mapeamento usado: email=' . $map['email'] . ', telefone=' . $map['telefone'] . '. Chaves do payload: ' . implode(',', array_keys($payload)));
    }

    // Busca usuário existente
    $userId = 0;
    if ($email !== '') {
        $stU = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
        $stU->execute([':e' => $email]);
        $row = $stU->fetch();
        if ($row) $userId = (int)$row['id'];
    }
    if ($userId === 0 && $telefone !== '') {
        $telLimpo = preg_replace('/\D+/', '', $telefone);
        $stU = $pdo->prepare("SELECT id FROM users WHERE telefone = :t OR telefone = :t2 LIMIT 1");
        $stU->execute([':t' => $telefone, ':t2' => $telLimpo]);
        $row = $stU->fetch();
        if ($row) $userId = (int)$row['id'];
    }

    // Se não existe, cria
    if ($userId === 0) {
        $hash = password_hash($telefone !== '' ? preg_replace('/\D+/', '', $telefone) : bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
        $stIns = $pdo->prepare("INSERT INTO users (nome, email, telefone, senha_hash, created_at) VALUES (:n, :e, :t, :s, NOW())");
        $stIns->execute([
            ':n' => $nome !== '' ? $nome : ($email ?: $telefone),
            ':e' => $email,
            ':t' => $telefone,
            ':s' => $hash,
        ]);
        $userId = (int)$pdo->lastInsertId();
    }

    // Aplica tag configurada no evento
    if (function_exists('adicionar_tag')) {
        adicionar_tag($userId, (string)$ev['tag_nome'], 'live_event', (int)$ev['id']);
    }

    // Dispara evento (LIVE_ACESSOU / LIVE_OFERTA / LIVE_COMPRA / LIVE_EVENTO)
    $tipo  = (string)($ev['tipo'] ?? 'custom');
    $code  = strtoupper('LIVE_' . ($tipo === 'oferta' ? 'OFERTA' : ($tipo === 'compra' ? 'COMPRA' : ($tipo === 'acessou' ? 'ACESSOU' : 'EVENTO'))));
    $extras = [
        'live_event_id'   => (int)$ev['id'],
        'live_event_nome' => $ev['nome'],
        'live_event_tag'  => $ev['tag_nome'],
        'live_event_tipo' => $tipo,
        'payload_raw'     => $payload,
    ];
    if (function_exists('disparar_webhooks')) {
        disparar_webhooks($code, $userId, $extras);
    }

    // Marca recebimento como processado
    $pdo->prepare("UPDATE live_event_recebimentos SET status='processado', user_id=:u, processado_em=NOW() WHERE id=:id")
        ->execute([':u' => $userId, ':id' => $recebimentoId]);
    $pdo->prepare("UPDATE live_events SET total_recebidos = total_recebidos + 1 WHERE id = :id")
        ->execute([':id' => (int)$ev['id']]);

} catch (Throwable $e) {
    $pdo->prepare("UPDATE live_event_recebimentos SET status='erro', erro_msg=:msg, processado_em=NOW() WHERE id=:id")
        ->execute([':msg' => $e->getMessage(), ':id' => $recebimentoId]);
}
