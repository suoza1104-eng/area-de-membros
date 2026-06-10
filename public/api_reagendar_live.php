<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

function rl_log(string $msg, string $file = 'reagendar_live_api.log'): void {
    $dir = __DIR__ . '/../app/error_log';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    if (@file_put_contents($dir . '/' . $file, $line, FILE_APPEND) === false) {
        error_log('[reagendar_live_api] ' . $msg);
    }
}

function rl_json(bool $ok, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function rl_fmt_dt($value): ?string {
    $v = is_string($value) ? trim($value) : '';
    if ($v === '') return null;
    try { return (new DateTimeImmutable($v))->format('d/m/Y H:i'); } catch (Throwable $e) { return $v; }
}

function rl_get_setting_db(PDO $pdo, string $key, ?string $default = null): ?string {
    if (function_exists('get_setting')) {
        try {
            $v = get_setting($key);
            if ($v !== null && $v !== '') return (string)$v;
        } catch (Throwable $e) {}
    }
    try {
        $st = $pdo->prepare("SELECT valor FROM settings WHERE chave = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $v = $st->fetchColumn();
        return ($v !== false && (string)$v !== '') ? (string)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function rl_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $t]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function rl_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function rl_ensure_history(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reagendamentos_live (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        old_codigo_turma VARCHAR(80) NULL,
        new_codigo_turma VARCHAR(80) NULL,
        old_turma_live_at DATETIME NULL,
        new_turma_live_at DATETIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'reagendado',
        live_url TEXT NULL,
        sf_disparo_at DATETIME NULL,
        sf_delay_ms INT NOT NULL DEFAULT 500,
        sf_sent_at DATETIME NULL,
        expired_checked_at DATETIME NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(250) NULL,
        origem VARCHAR(30) NULL,
        webhook_url TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_reag_live_user (user_id),
        KEY idx_reag_live_status (status),
        KEY idx_reag_live_new_live (new_turma_live_at),
        KEY idx_reag_live_disparo (sf_disparo_at),
        KEY idx_reag_live_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        "ALTER TABLE reagendamentos_live ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'reagendado'",
        "ALTER TABLE reagendamentos_live ADD COLUMN live_url TEXT NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN sf_disparo_at DATETIME NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN sf_delay_ms INT NOT NULL DEFAULT 500",
        "ALTER TABLE reagendamentos_live ADD COLUMN sf_sent_at DATETIME NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN expired_checked_at DATETIME NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN ip VARCHAR(64) NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN user_agent VARCHAR(250) NULL",
        "ALTER TABLE reagendamentos_live ADD COLUMN origem VARCHAR(30) NULL AFTER user_agent",
        "ALTER TABLE reagendamentos_live ADD COLUMN webhook_url TEXT NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}

function rl_available_slots(PDO $pdo): array {
    $now = new DateTimeImmutable('now');
    $qty = (int)rl_get_setting_db($pdo, 'reagendar_opcoes_qtd', (string)rl_get_setting_db($pdo, 'reagendar_next_lives_count', '3'));
    if ($qty < 1) $qty = 1;
    if ($qty > 30) $qty = 30;
    $days = (int)rl_get_setting_db($pdo, 'reagendar_window_days', '30');
    if ($days < 1) $days = 1;
    if ($days > 365) $days = 365;
    $interval = (int)rl_get_setting_db($pdo, 'reagendar_availability_interval_days', '1');
    if ($interval < 1) $interval = 1;
    if ($interval > 365) $interval = 365;
    $time = trim((string)rl_get_setting_db($pdo, 'reagendar_live_time', '19:30'));
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '19:30';
    $blackouts = array_flip(array_filter(array_map('trim', explode(',', (string)rl_get_setting_db($pdo, 'reagendar_blackout_dates', '')))));

    $slots = [];
    for ($i = 0; $i <= $days && count($slots) < $qty; $i++) {
        $day = $now->modify('+' . $i . ' days');
        $key = $day->format('Y-m-d');
        if (isset($blackouts[$key])) continue;
        if ($i < 1 || (($i - 1) % $interval) !== 0) continue;
        $slot = new DateTimeImmutable($key . ' ' . $time . ':00');
        if ($slot <= $now) continue;
        $slots[$slot->format('Y-m-d H:i:s')] = $slot;
    }
    return $slots;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) rl_json(false, 'Payload invalido.');

$dtReq = trim((string)($data['data_live'] ?? ''));
if ($dtReq === '') rl_json(false, 'Informe a data da live.');

$alunoId = (int)($_SESSION['aluno_id'] ?? 0);
$guestId = (int)($_SESSION['reagendar_guest_uid'] ?? 0);
$guestExp = (int)($_SESSION['reagendar_guest_exp'] ?? 0);
if ($alunoId <= 0) {
    if ($guestId <= 0 || $guestExp <= time()) {
        rl_json(false, 'Sessao expirada. Abra o link novamente.');
    }
    $alunoId = $guestId;
}

try {
    $stU = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stU->execute([':id' => $alunoId]);
    $user = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$user) rl_json(false, 'Aluno nao encontrado.');

    $slot = (new DateTimeImmutable($dtReq))->format('Y-m-d H:i:s');
    $slots = rl_available_slots($pdo);
    if (empty($slots[$slot])) {
        rl_json(false, 'Esta data nao esta disponivel para reagendamento.');
    }

    $oldCodigo = (string)($user['codigo_turma'] ?? ($user['turma_codigo'] ?? ''));
    $oldLiveAt = (string)($user['turma_live_at'] ?? ($user['data_live'] ?? ''));
    $liveUrl = trim((string)rl_get_setting_db($pdo, 'reagendar_live_url', ''));
    $offsetMin = (int)rl_get_setting_db($pdo, 'reagendar_dispatch_offset_min', '0');
    $delayMs = (int)rl_get_setting_db($pdo, 'reagendar_dispatch_delay_ms', '500');
    if ($delayMs < 0) $delayMs = 0;
    if ($delayMs > 30000) $delayMs = 30000;
    $dispatchAt = $slots[$slot]->modify(($offsetMin >= 0 ? '+' : '') . $offsetMin . ' minutes')->format('Y-m-d H:i:s');

    $sets = [];
    $params = [':id' => $alunoId];
    if (rl_col_exists($pdo, 'users', 'turma_live_at')) {
        $sets[] = 'turma_live_at = :live_at';
        $params[':live_at'] = $slot;
    }
    if (rl_col_exists($pdo, 'users', 'data_live')) {
        $sets[] = 'data_live = :data_live';
        $params[':data_live'] = $slot;
    }
    if (!$sets) rl_json(false, 'O cadastro do aluno nao possui campo de data da live.');

    rl_ensure_history($pdo);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1')->execute($params);
    $pdo->prepare("INSERT INTO reagendamentos_live
        (user_id, old_codigo_turma, new_codigo_turma, old_turma_live_at, new_turma_live_at, status, live_url, sf_disparo_at, sf_delay_ms, ip, user_agent, origem, webhook_url, created_at)
        VALUES (:u, :oldc, :newc, :oldl, :newl, 'reagendado', :url, :sfat, :delay, :ip, :ua, 'aluno', :wh, NOW())")
        ->execute([
            ':u' => $alunoId,
            ':oldc' => $oldCodigo !== '' ? $oldCodigo : null,
            ':newc' => $oldCodigo !== '' ? $oldCodigo : null,
            ':oldl' => $oldLiveAt !== '' ? $oldLiveAt : null,
            ':newl' => $slot,
            ':url' => $liveUrl !== '' ? $liveUrl : null,
            ':sfat' => $dispatchAt,
            ':delay' => $delayMs,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            ':wh' => null,
        ]);
    $histId = (int)$pdo->lastInsertId();
    reagendamento_live_log($pdo, $histId, $alunoId, 'agendamento_criado', 'pendente', 'Reagendamento criado pela pagina publica.', [
        'new_turma_live_at' => $slot,
        'sf_disparo_at' => $dispatchAt,
        'origem' => 'aluno',
    ]);
    $tokenId = (int)($_SESSION['reagendar_token_id'] ?? 0);
    if ($tokenId > 0) {
        $pdo->prepare("UPDATE live_reschedule_tokens SET used_at = COALESCE(used_at, NOW()) WHERE id = :id AND user_id = :user_id")
            ->execute([':id' => $tokenId, ':user_id' => $alunoId]);
        unset($_SESSION['reagendar_token_id']);
    }
    $pdo->commit();

    $extra = [
        'reagendamento_id' => $histId,
        'codigo_turma' => $oldCodigo,
        'data_live' => $slots[$slot]->format('d/m/Y H:i'),
        'data_live_iso' => $slot,
        'live_url' => $liveUrl,
        'live_antiga' => rl_fmt_dt($oldLiveAt) ?? $oldLiveAt,
        'disparo_em' => rl_fmt_dt($dispatchAt),
        'disparo_em_iso' => $dispatchAt,
        'delay_ms' => $delayMs,
        'reagendamento' => [
            'id' => $histId,
            'turma_original' => $oldCodigo,
            'live_antiga' => rl_fmt_dt($oldLiveAt) ?? $oldLiveAt,
            'live_nova' => $slots[$slot]->format('d/m/Y H:i'),
            'live_nova_iso' => $slot,
            'live_url' => $liveUrl,
            'status' => 'reagendado',
        ],
    ];
    disparar_webhooks('LIVE_REAGENDADA', $alunoId, $extra);

    rl_log("Reagendou: user={$alunoId} turma_original={$oldCodigo} live={$slot}");
    rl_json(true, 'Reagendado com sucesso.', [
        'turma' => $oldCodigo,
        'live_nova' => $slots[$slot]->format('d/m/Y H:i'),
        'reagendamento_id' => $histId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    rl_log('Erro: ' . $e->getMessage());
    rl_json(false, 'Erro interno: ' . $e->getMessage());
}
