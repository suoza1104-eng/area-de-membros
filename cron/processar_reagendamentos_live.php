<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();
$startedAt = microtime(true);
$maxRuntimeSeconds = 90;
$limit = 80;

function rl_cron_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $st->execute([':column' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function rl_cron_dt(?string $dt): string
{
    if (!$dt) return '';
    try {
        return (new DateTimeImmutable($dt, new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string)$dt;
    }
}

function rl_cron_ensure_schema(PDO $pdo): void
{
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
        KEY idx_reag_live_created (created_at),
        KEY idx_reag_live_new_live (new_turma_live_at),
        KEY idx_reag_live_disparo (sf_disparo_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'status' => "ALTER TABLE reagendamentos_live ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'reagendado'",
        'live_url' => "ALTER TABLE reagendamentos_live ADD COLUMN live_url TEXT NULL",
        'sf_disparo_at' => "ALTER TABLE reagendamentos_live ADD COLUMN sf_disparo_at DATETIME NULL",
        'sf_delay_ms' => "ALTER TABLE reagendamentos_live ADD COLUMN sf_delay_ms INT NOT NULL DEFAULT 500",
        'sf_sent_at' => "ALTER TABLE reagendamentos_live ADD COLUMN sf_sent_at DATETIME NULL",
        'expired_checked_at' => "ALTER TABLE reagendamentos_live ADD COLUMN expired_checked_at DATETIME NULL",
        'ip' => "ALTER TABLE reagendamentos_live ADD COLUMN ip VARCHAR(64) NULL",
        'user_agent' => "ALTER TABLE reagendamentos_live ADD COLUMN user_agent VARCHAR(250) NULL",
        'origem' => "ALTER TABLE reagendamentos_live ADD COLUMN origem VARCHAR(30) NULL AFTER user_agent",
        'webhook_url' => "ALTER TABLE reagendamentos_live ADD COLUMN webhook_url TEXT NULL",
    ] as $column => $sql) {
        try {
            if (!rl_cron_column_exists($pdo, 'reagendamentos_live', $column)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }

    if (function_exists('reagendamento_live_ensure_logs')) {
        reagendamento_live_ensure_logs($pdo);
    }
}

function rl_cron_extra(array $r): array
{
    $codigo = (string)($r['new_codigo_turma'] ?: $r['old_codigo_turma']);
    $newLive = (string)($r['new_turma_live_at'] ?? '');
    return [
        'reagendamento_id' => (int)$r['id'],
        'codigo_turma' => $codigo,
        'data_live' => rl_cron_dt($newLive),
        'data_live_iso' => $newLive,
        'live_url' => (string)($r['live_url'] ?? ''),
        'status' => (string)($r['status'] ?? ''),
        'origem' => 'cron_reagendamentos_live',
        'reagendamento' => [
            'id' => (int)$r['id'],
            'turma_original' => $codigo,
            'live_antiga' => rl_cron_dt((string)($r['old_turma_live_at'] ?? '')),
            'live_nova' => rl_cron_dt($newLive),
            'live_nova_iso' => $newLive,
            'live_url' => (string)($r['live_url'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
        ],
    ];
}

function rl_cron_log(PDO $pdo, ?int $id, ?int $userId, string $step, string $status, string $message, array $context = []): void
{
    if (!function_exists('reagendamento_live_log')) return;
    try {
        reagendamento_live_log($pdo, $id, $userId, $step, $status, $message, $context);
    } catch (Throwable $e) {}
}

$stats = ['sent' => 0, 'failed' => 0, 'expired' => 0, 'checked' => 0];

try {
    rl_cron_ensure_schema($pdo);

    $expireGraceMin = (int)get_setting('reagendar_expire_grace_min', '10');
    if ($expireGraceMin < 0) $expireGraceMin = 10;
    if ($expireGraceMin > 1440) $expireGraceMin = 1440;

    $expired = $pdo->prepare("
        SELECT id, user_id
          FROM reagendamentos_live
         WHERE status IN ('reagendado','processando')
           AND sf_sent_at IS NULL
           AND new_turma_live_at IS NOT NULL
           AND new_turma_live_at <= DATE_SUB(NOW(), INTERVAL :grace MINUTE)
         ORDER BY new_turma_live_at ASC, id ASC
         LIMIT 200
    ");
    $expired->execute([':grace' => $expireGraceMin]);
    foreach ($expired->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pdo->prepare("UPDATE reagendamentos_live SET status='expirou', expired_checked_at=NOW() WHERE id=:id AND sf_sent_at IS NULL")
            ->execute([':id' => (int)$row['id']]);
        rl_cron_log($pdo, (int)$row['id'], (int)$row['user_id'], 'expiracao', 'sucesso', 'Reagendamento expirado pelo cron.');
        $stats['expired']++;
    }

    $due = $pdo->prepare("
        SELECT *
          FROM reagendamentos_live
         WHERE status = 'reagendado'
           AND sf_sent_at IS NULL
           AND sf_disparo_at IS NOT NULL
           AND sf_disparo_at <= NOW()
           AND (new_turma_live_at IS NULL OR new_turma_live_at > NOW())
         ORDER BY sf_disparo_at ASC, id ASC
         LIMIT {$limit}
    ");
    $due->execute();
    $rows = $due->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        if ((microtime(true) - $startedAt) >= $maxRuntimeSeconds) break;

        $claim = $pdo->prepare("
            UPDATE reagendamentos_live
               SET status = 'processando'
             WHERE id = :id
               AND status = 'reagendado'
               AND sf_sent_at IS NULL
             LIMIT 1
        ");
        $claim->execute([':id' => (int)$r['id']]);
        if ($claim->rowCount() !== 1) continue;

        $stats['checked']++;
        $extra = rl_cron_extra($r);
        rl_cron_log($pdo, (int)$r['id'], (int)$r['user_id'], 'lembrete_cron_inicio', 'pendente', 'Disparo de lembrete iniciado pelo cron.', [
            'evento' => 'LIVE_REAGENDAMENTO_LEMBRETE',
            'extra' => $extra,
        ]);

        $dispatchResult = function_exists('_disparar_webhooks_sync_resultado')
            ? _disparar_webhooks_sync_resultado('LIVE_REAGENDAMENTO_LEMBRETE', (int)$r['user_id'], $extra)
            : ['ok' => _disparar_webhooks_sync('LIVE_REAGENDAMENTO_LEMBRETE', (int)$r['user_id'], $extra)];
        $ok = (bool)($dispatchResult['ok'] ?? false);
        if ($ok) {
            $pdo->prepare("UPDATE reagendamentos_live SET status='enviado', sf_sent_at=NOW(), expired_checked_at=NULL WHERE id=:id AND status='processando'")
                ->execute([':id' => (int)$r['id']]);
            rl_cron_log($pdo, (int)$r['id'], (int)$r['user_id'], 'lembrete_cron_resultado', 'sucesso', 'Lembrete enviado pelo cron.', [
                'evento' => 'LIVE_REAGENDAMENTO_LEMBRETE',
                'resultado' => $dispatchResult,
            ]);
            $stats['sent']++;
        } else {
            $pdo->prepare("UPDATE reagendamentos_live SET status='reagendado' WHERE id=:id AND status='processando'")
                ->execute([':id' => (int)$r['id']]);
            rl_cron_log($pdo, (int)$r['id'], (int)$r['user_id'], 'lembrete_cron_resultado', 'falha', 'Integracao nao confirmou o disparo pelo cron.', [
                'evento' => 'LIVE_REAGENDAMENTO_LEMBRETE',
                'resultado' => $dispatchResult,
            ]);
            $stats['failed']++;
        }

        $delayMs = max(0, min(30000, (int)($r['sf_delay_ms'] ?? 500)));
        if ($delayMs > 0) usleep($delayMs * 1000);
    }

    echo json_encode(['ok' => true] + $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()] + $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    throw $e;
}
