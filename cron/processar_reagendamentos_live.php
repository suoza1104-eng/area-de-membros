<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();

function rl_cron_table_exists(PDO $pdo, string $t): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $t]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function rl_cron_dt(?string $v): string {
    if (!$v) return '';
    try { return (new DateTimeImmutable($v))->format('d/m/Y H:i'); } catch (Throwable $e) { return (string)$v; }
}

function rl_cron_user(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT id, nome, email, telefone, codigo_turma, turma_codigo FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId];
}

function rl_cron_extra(array $r): array {
    $codigo = (string)($r['new_codigo_turma'] ?: $r['old_codigo_turma']);
    return [
        'reagendamento_id' => (int)$r['id'],
        'codigo_turma' => $codigo,
        'data_live' => rl_cron_dt((string)$r['new_turma_live_at']),
        'data_live_iso' => (string)$r['new_turma_live_at'],
        'live_url' => (string)($r['live_url'] ?? ''),
        'status' => (string)($r['status'] ?? ''),
        'reagendamento' => [
            'id' => (int)$r['id'],
            'turma_original' => $codigo,
            'live_antiga' => rl_cron_dt((string)($r['old_turma_live_at'] ?? '')),
            'live_nova' => rl_cron_dt((string)$r['new_turma_live_at']),
            'live_nova_iso' => (string)$r['new_turma_live_at'],
            'live_url' => (string)($r['live_url'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
        ],
    ];
}

function rl_cron_reagendamento_acessou(PDO $pdo, array $r): bool {
    if (!rl_cron_table_exists($pdo, 'live_event_recebimentos') || !rl_cron_table_exists($pdo, 'live_events')) {
        return false;
    }
    try {
        $st = $pdo->prepare("
            SELECT 1
              FROM live_event_recebimentos ler
              JOIN live_events le ON le.id = ler.event_id
             WHERE ler.user_id = :user_id
               AND ler.status = 'processado'
               AND le.tipo = 'acessou'
               AND COALESCE(ler.processado_em, ler.recebido_em) >= :reag_created_at
             LIMIT 1
        ");
        $st->execute([
            ':user_id' => (int)($r['user_id'] ?? 0),
            ':reag_created_at' => (string)($r['created_at'] ?? '1970-01-01 00:00:00'),
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

if (!rl_cron_table_exists($pdo, 'reagendamentos_live')) {
    echo "Tabela reagendamentos_live nao existe.\n";
    exit;
}

$sent = 0;
$expired = 0;
$expireGraceMin = (int)get_setting('reagendar_expire_grace_min', '10');
if ($expireGraceMin < 0) $expireGraceMin = 0;
if ($expireGraceMin > 1440) $expireGraceMin = 1440;
$dispatchGraceMin = (int)get_setting('reagendar_dispatch_grace_min', '180');
if ($dispatchGraceMin < 1) $dispatchGraceMin = 1;
if ($dispatchGraceMin > 1440) $dispatchGraceMin = 1440;

try {
    $rows = $pdo->query("SELECT * FROM reagendamentos_live
        WHERE status = 'reagendado'
          AND sf_disparo_at IS NOT NULL
          AND sf_sent_at IS NULL
          AND sf_disparo_at <= NOW()
          AND sf_disparo_at >= DATE_SUB(NOW(), INTERVAL {$dispatchGraceMin} MINUTE)
        ORDER BY sf_disparo_at ASC
        LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $claim = $pdo->prepare("
            UPDATE reagendamentos_live
               SET status = 'processando_cron'
             WHERE id = :id
               AND status = 'reagendado'
               AND sf_sent_at IS NULL
             LIMIT 1
        ");
        $claim->execute([':id' => (int)$r['id']]);
        if ($claim->rowCount() !== 1) continue;

        $extra = rl_cron_extra($r);
        $dispatchTs = !empty($r['sf_disparo_at']) ? strtotime((string)$r['sf_disparo_at']) : false;
        $atrasoSeg = $dispatchTs ? max(0, time() - $dispatchTs) : 0;
        reagendamento_live_log($pdo, (int)$r['id'], (int)$r['user_id'], 'lembrete_inicio', 'pendente', 'Horario do lembrete atingido pelo cron.', [
            'sf_disparo_at' => (string)($r['sf_disparo_at'] ?? ''),
            'new_turma_live_at' => (string)($r['new_turma_live_at'] ?? ''),
            'atraso_segundos' => $atrasoSeg,
            'atraso_minutos' => round($atrasoSeg / 60, 2),
        ]);
        $ok = _disparar_webhooks_sync('LIVE_REAGENDAMENTO_LEMBRETE', (int)$r['user_id'], $extra);
        if ($ok) {
            $pdo->prepare("UPDATE reagendamentos_live SET status='enviado', sf_sent_at = NOW() WHERE id = :id")->execute([':id' => (int)$r['id']]);
            reagendamento_live_log($pdo, (int)$r['id'], (int)$r['user_id'], 'lembrete_resultado', 'sucesso', 'SuperFuncionario confirmou o envio.', [
                'evento' => 'LIVE_REAGENDAMENTO_LEMBRETE',
                'extra' => $extra,
            ]);
            $sent++;
        } else {
            $pdo->prepare("UPDATE reagendamentos_live SET status='reagendado' WHERE id=:id AND status='processando_cron'")
                ->execute([':id' => (int)$r['id']]);
            reagendamento_live_log($pdo, (int)$r['id'], (int)$r['user_id'], 'lembrete_resultado', 'falha', 'SuperFuncionario nao confirmou o envio; reagendamento permanece pendente.', [
                'evento' => 'LIVE_REAGENDAMENTO_LEMBRETE',
                'extra' => $extra,
            ]);
        }
        $delay = max(0, min(30000, (int)($r['sf_delay_ms'] ?? 500)));
        if ($delay > 0) usleep($delay * 1000);
    }
} catch (Throwable $e) {
    echo "Erro lembretes: " . $e->getMessage() . "\n";
}

try {
    $rows = $pdo->query("SELECT r.* FROM reagendamentos_live r
        WHERE r.status IN ('reagendado', 'enviado')
          AND r.new_turma_live_at <= DATE_SUB(NOW(), INTERVAL {$expireGraceMin} MINUTE)
          AND r.expired_checked_at IS NULL
        ORDER BY r.new_turma_live_at ASC
        LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $acessou = rl_cron_reagendamento_acessou($pdo, $r);
        if ($acessou) {
            $pdo->prepare("UPDATE reagendamentos_live SET expired_checked_at=NOW() WHERE id=:id")->execute([':id' => (int)$r['id']]);
            if (function_exists('definir_tag_estado_reagendamento')) {
                definir_tag_estado_reagendamento((int)$r['user_id'], 'concluido', 'reagendamento_live_cron', (int)$r['id']);
            }
            reagendamento_live_log($pdo, (int)$r['id'], (int)$r['user_id'], 'reagendamento_concluido', 'sucesso', 'Live reagendada passou e o aluno acessou a live.', [
                'new_turma_live_at' => (string)($r['new_turma_live_at'] ?? ''),
                'expire_grace_min' => $expireGraceMin,
            ]);
        } else {
            $pdo->prepare("UPDATE reagendamentos_live SET status='expirou', expired_checked_at=NOW() WHERE id=:id")->execute([':id' => (int)$r['id']]);
            $r['status'] = 'expirou';
            if (function_exists('definir_tag_estado_reagendamento')) {
                definir_tag_estado_reagendamento((int)$r['user_id'], 'expirado', 'reagendamento_live_cron', (int)$r['id']);
            }
            reagendamento_live_log($pdo, (int)$r['id'], (int)$r['user_id'], 'reagendamento_expirado', 'falha', 'Live reagendada passou e o aluno nao acessou a live.', [
                'new_turma_live_at' => (string)($r['new_turma_live_at'] ?? ''),
                'sf_disparo_at' => (string)($r['sf_disparo_at'] ?? ''),
                'expire_grace_min' => $expireGraceMin,
            ]);
            disparar_webhooks('LIVE_REAGENDAMENTO_EXPIRADO', (int)$r['user_id'], rl_cron_extra($r));
            $expired++;
        }
    }
} catch (Throwable $e) {
    echo "Erro expirados: " . $e->getMessage() . "\n";
}

echo "Lembretes enviados: {$sent}; expirados: {$expired}\n";
