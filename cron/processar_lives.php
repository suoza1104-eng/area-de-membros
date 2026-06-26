<?php
// /cron/processar_lives.php
// Dispara webhooks de live por turma (fila de alunos) quando chegar a data/hora configurada.
// Também dispara para o SuperFuncionário se sf_enabled=1 na turma.
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key']) && empty($GLOBALS['manual_live_turma_id'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'lives_turma', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/superfuncionario_dispatcher.php';
require_once __DIR__ . '/../app/manychat_dispatcher.php';
require_once __DIR__ . '/../app/webhook_dispatcher.php';

$pdo = getPDO();
$agora = date('Y-m-d H:i:s');

/**
 * Verifica se uma tabela existe.
 */
function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Retorna o primeiro nome de tabela que existir.
 */
function first_existing_table(PDO $pdo, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t)) return $t;
    }
    return null;
}

/**
 * Sanitiza CSV de IDs (ex.: "1,2,3") => [1,2,3]
 */
function csv_ids(?string $csv): array {
    if (!$csv) return [];
    $parts = array_filter(array_map('trim', explode(',', $csv)));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $n = (int)$p;
        if ($n > 0) $out[] = $n;
    }
    return array_values(array_unique($out));
}

function live_filter_config($raw): array {
    $cfg = [
        'include_any'      => [],
        'exclude_any'      => [],
        'exclude_cert'     => 0,
        'exclude_zero'     => 0,
        'exclude_purchase' => 0,
        'exclude_rescheduled' => 1,
    ];

    $raw = trim((string)$raw);
    if ($raw === '') return $cfg;

    $json = json_decode($raw, true);
    if (is_array($json)) {
        foreach (['include_any', 'exclude_any'] as $k) {
            $cfg[$k] = array_values(array_unique(array_filter(array_map('intval', (array)($json[$k] ?? [])), fn($v) => $v > 0)));
        }
        foreach (['exclude_cert', 'exclude_zero', 'exclude_purchase'] as $k) {
            $cfg[$k] = (int)(!!($json[$k] ?? 0));
        }
        $cfg['exclude_rescheduled'] = array_key_exists('exclude_rescheduled', $json) ? (int)(!!$json['exclude_rescheduled']) : 1;
        return $cfg;
    }

    // Compatibilidade com formato antigo CSV: tratava como tags obrigatórias.
    $cfg['include_any'] = csv_ids($raw);
    return $cfg;
}

function user_has_any_tag(PDO $pdo, ?string $tagRelTable, int $userId, array $tagIds): bool {
    if (!$tagRelTable || $userId <= 0 || !$tagIds) return false;
    try {
        $in = implode(',', array_fill(0, count($tagIds), '?'));
        $st = $pdo->prepare("SELECT 1 FROM `$tagRelTable` WHERE user_id = ? AND tag_id IN ($in) LIMIT 1");
        $st->execute(array_merge([$userId], $tagIds));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_has_certificate(PDO $pdo, int $userId): bool {
    if ($userId <= 0 || !table_exists($pdo, 'certificates')) return false;
    try {
        $statusSql = column_exists($pdo, 'certificates', 'status') ? " AND status = 'emitido'" : "";
        $st = $pdo->prepare("SELECT 1 FROM certificates WHERE user_id = :u$statusSql LIMIT 1");
        $st->execute([':u' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_has_purchase(PDO $pdo, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        if (table_exists($pdo, 'hotmart_sales')) {
            $st = $pdo->prepare("SELECT 1 FROM hotmart_sales WHERE matched_user_id = :u AND LOWER(COALESCE(status,'')) IN ('aprovado','completo','approved','complete','paid') LIMIT 1");
            $st->execute([':u' => $userId]);
            if ($st->fetchColumn()) return true;
        }
        if (table_exists($pdo, 'live_event_recebimentos') && table_exists($pdo, 'live_events')) {
            $st = $pdo->prepare("SELECT 1 FROM live_event_recebimentos ler JOIN live_events le ON le.id = ler.event_id WHERE ler.user_id = :u AND le.tipo = 'compra' LIMIT 1");
            $st->execute([':u' => $userId]);
            if ($st->fetchColumn()) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

function user_has_active_live_reschedule(PDO $pdo, int $userId, ?string $turmaLiveAt): bool {
    if ($userId <= 0 || !table_exists($pdo, 'reagendamentos_live')) return false;
    try {
        $st = $pdo->prepare("
            SELECT new_turma_live_at
              FROM reagendamentos_live
             WHERE user_id = :u
               AND status IN ('reagendado', 'enviado')
               AND new_turma_live_at IS NOT NULL
               AND new_turma_live_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
          ORDER BY created_at DESC, id DESC
             LIMIT 1
        ");
        $st->execute([':u' => $userId]);
        $newLiveAt = (string)($st->fetchColumn() ?: '');
        if ($newLiveAt === '') return false;

        $newTs = strtotime($newLiveAt);
        $turmaTs = $turmaLiveAt ? strtotime($turmaLiveAt) : false;
        if (!$newTs || !$turmaTs) return true;
        return abs($newTs - $turmaTs) > 60;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_live_dispatch_log_table(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS live_turma_dispatch_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                turma_id INT NULL,
                turma_codigo VARCHAR(80) NULL,
                planned_at DATETIME NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                total_alunos INT NOT NULL DEFAULT 0,
                elegiveis INT NOT NULL DEFAULT 0,
                sf_ok INT NOT NULL DEFAULT 0,
                sf_fail INT NOT NULL DEFAULT 0,
                manychat_ok INT NOT NULL DEFAULT 0,
                manychat_fail INT NOT NULL DEFAULT 0,
                webhook_ok INT NOT NULL DEFAULT 0,
                webhook_fail INT NOT NULL DEFAULT 0,
                skipped_json LONGTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'iniciado',
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_live_dispatch_turma (turma_codigo),
                KEY idx_live_dispatch_started (started_at),
                KEY idx_live_dispatch_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}

    foreach ([
        'manychat_ok' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN manychat_ok INT NOT NULL DEFAULT 0 AFTER sf_fail",
        'manychat_fail' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN manychat_fail INT NOT NULL DEFAULT 0 AFTER manychat_ok",
        'trigger_type' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN trigger_type VARCHAR(30) NOT NULL DEFAULT 'cron' AFTER turma_codigo",
        'enqueued_at' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN enqueued_at DATETIME NULL AFTER started_at",
        'last_heartbeat_at' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN last_heartbeat_at DATETIME NULL AFTER finished_at",
        'batch_runs' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN batch_runs INT NOT NULL DEFAULT 0 AFTER last_heartbeat_at",
        'locked_until' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN locked_until DATETIME NULL AFTER batch_runs",
        'lock_token' => "ALTER TABLE live_turma_dispatch_logs ADD COLUMN lock_token VARCHAR(64) NULL AFTER locked_until",
    ] as $col => $sql) {
        try {
            if (!column_exists($pdo, 'live_turma_dispatch_logs', $col)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS live_turma_dispatch_recipients (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                dispatch_id INT NOT NULL,
                turma_id INT NULL,
                turma_codigo VARCHAR(80) NULL,
                user_id INT NOT NULL,
                nome VARCHAR(190) NULL,
                email VARCHAR(190) NULL,
                telefone VARCHAR(60) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                skip_reason VARCHAR(80) NULL,
                attempts INT NOT NULL DEFAULT 0,
                webhook_ok TINYINT(1) NOT NULL DEFAULT 0,
                webhook_fail TINYINT(1) NOT NULL DEFAULT 0,
                sf_ok TINYINT(1) NOT NULL DEFAULT 0,
                sf_fail TINYINT(1) NOT NULL DEFAULT 0,
                manychat_ok TINYINT(1) NOT NULL DEFAULT 0,
                manychat_fail TINYINT(1) NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                payload_json LONGTEXT NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_live_dispatch_user (dispatch_id, user_id),
                KEY idx_live_dispatch_status (dispatch_id, status),
                KEY idx_live_dispatch_turma_user (turma_codigo, user_id),
                KEY idx_live_dispatch_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}
}

function start_live_dispatch_log(PDO $pdo, array $turma): int {
    ensure_live_dispatch_log_table($pdo);
    try {
        $mensagemInicio = !empty($GLOBALS['manual_live_turma_id'])
            ? 'Administrador iniciou o processamento manual da turma.'
            : 'Cron iniciou processamento da turma.';
        $st = $pdo->prepare("
            INSERT INTO live_turma_dispatch_logs
                (turma_id, turma_codigo, planned_at, started_at, status, message)
            VALUES
                (:tid, :codigo, :planned, NOW(), 'iniciado', :message)
        ");
        $st->execute([
            ':tid' => (int)($turma['id'] ?? 0) ?: null,
            ':codigo' => (string)($turma['codigo'] ?? ''),
            ':planned' => $turma['live_disparo_data'] ?? null,
            ':message' => $mensagemInicio,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function finish_live_dispatch_log(PDO $pdo, int $logId, array $stats, string $status, string $message): void {
    if ($logId <= 0) return;
    try {
        $st = $pdo->prepare("
            UPDATE live_turma_dispatch_logs
               SET finished_at = NOW(),
                   total_alunos = :total,
                   elegiveis = :elegiveis,
                   sf_ok = :sf_ok,
                   sf_fail = :sf_fail,
                   manychat_ok = :manychat_ok,
                   manychat_fail = :manychat_fail,
                   webhook_ok = :webhook_ok,
                   webhook_fail = :webhook_fail,
                   skipped_json = :skipped,
                   status = :status,
                   message = :message
             WHERE id = :id
             LIMIT 1
        ");
        $st->execute([
            ':total' => (int)($stats['total_alunos'] ?? 0),
            ':elegiveis' => (int)($stats['elegiveis'] ?? 0),
            ':sf_ok' => (int)($stats['sf_ok'] ?? 0),
            ':sf_fail' => (int)($stats['sf_fail'] ?? 0),
            ':manychat_ok' => (int)($stats['manychat_ok'] ?? 0),
            ':manychat_fail' => (int)($stats['manychat_fail'] ?? 0),
            ':webhook_ok' => (int)($stats['webhook_ok'] ?? 0),
            ':webhook_fail' => (int)($stats['webhook_fail'] ?? 0),
            ':skipped' => json_encode((array)($stats['skipped'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => $status,
            ':message' => $message,
            ':id' => $logId,
        ]);
    } catch (Throwable $e) {}
}

function live_dispatch_trigger_type(): string {
    return !empty($GLOBALS['manual_live_turma_id']) ? 'manual' : 'cron';
}

function live_dispatch_open_status_sql(): string {
    return "'queued','iniciado','processando'";
}

function live_dispatch_create_or_reuse(PDO $pdo, array $turma, string $triggerType): int {
    ensure_live_dispatch_log_table($pdo);
    $turmaId = (int)($turma['id'] ?? 0);
    if ($turmaId <= 0) return 0;

    try {
        $st = $pdo->prepare("
            SELECT id
              FROM live_turma_dispatch_logs
             WHERE turma_id = :tid
               AND status IN (" . live_dispatch_open_status_sql() . ")
             ORDER BY id DESC
             LIMIT 1
        ");
        $st->execute([':tid' => $turmaId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            $pdo->prepare("
                UPDATE live_turma_dispatch_logs
                   SET trigger_type = IF(trigger_type='manual' OR :trigger_type='cron', trigger_type, :trigger_type2),
                       message = IF(:trigger_type3='manual', 'Disparo manual retomou a fila existente da turma.', message),
                       last_heartbeat_at = NOW()
                 WHERE id = :id
                 LIMIT 1
            ")->execute([
                ':trigger_type' => $triggerType,
                ':trigger_type2' => $triggerType,
                ':trigger_type3' => $triggerType,
                ':id' => $id,
            ]);
            return $id;
        }
    } catch (Throwable $e) {}

    try {
        $message = $triggerType === 'manual'
            ? 'Administrador enfileirou o disparo manual da turma.'
            : 'Cron enfileirou processamento da turma.';
        $st = $pdo->prepare("
            INSERT INTO live_turma_dispatch_logs
                (turma_id, turma_codigo, trigger_type, planned_at, started_at, last_heartbeat_at, status, message)
            VALUES
                (:tid, :codigo, :trigger_type, :planned, NOW(), NOW(), 'queued', :message)
        ");
        $st->execute([
            ':tid' => $turmaId,
            ':codigo' => (string)($turma['codigo'] ?? ''),
            ':trigger_type' => $triggerType,
            ':planned' => $turma['live_disparo_data'] ?? null,
            ':message' => $message,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function live_dispatch_row_count(PDO $pdo, int $dispatchId): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM live_turma_dispatch_recipients WHERE dispatch_id=:id");
        $st->execute([':id' => $dispatchId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function live_dispatch_record_recipient(PDO $pdo, int $dispatchId, array $turma, array $aluno, string $status, ?string $skipReason = null, ?array $payload = null): void {
    try {
        $st = $pdo->prepare("
            INSERT INTO live_turma_dispatch_recipients
                (dispatch_id, turma_id, turma_codigo, user_id, nome, email, telefone, status, skip_reason, payload_json, finished_at)
            VALUES
                (:dispatch_id, :turma_id, :turma_codigo, :user_id, :nome, :email, :telefone, :status, :skip_reason, :payload_json, IF(:is_terminal=1, NOW(), NULL))
            ON DUPLICATE KEY UPDATE
                nome=VALUES(nome),
                email=VALUES(email),
                telefone=VALUES(telefone),
                status=IF(status IN ('sent','skipped'), status, VALUES(status)),
                skip_reason=IF(status IN ('sent','skipped'), skip_reason, VALUES(skip_reason)),
                payload_json=IF(VALUES(payload_json) IS NULL, payload_json, VALUES(payload_json)),
                finished_at=IF(status IN ('sent','skipped'), finished_at, VALUES(finished_at))
        ");
        $st->execute([
            ':dispatch_id' => $dispatchId,
            ':turma_id' => (int)($turma['id'] ?? 0) ?: null,
            ':turma_codigo' => (string)($turma['codigo'] ?? ''),
            ':user_id' => (int)($aluno['id'] ?? 0),
            ':nome' => (string)($aluno['nome'] ?? ''),
            ':email' => (string)($aluno['email'] ?? ''),
            ':telefone' => (string)($aluno['telefone'] ?? ''),
            ':status' => $status,
            ':skip_reason' => $skipReason,
            ':payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':is_terminal' => in_array($status, ['skipped', 'failed'], true) ? 1 : 0,
        ]);
    } catch (Throwable $e) {}
}

function live_dispatch_enqueue_recipients(PDO $pdo, int $dispatchId, array $turma, ?string $tagRelTable, int $totalObrigatoriasGlobal): array {
    $codigo = (string)($turma['codigo'] ?? '');
    $stats = [
        'total_alunos' => 0,
        'elegiveis' => 0,
        'skipped' => [
            'include_tag_table_missing' => 0,
            'andamento_zero' => 0,
            'tag_excluida' => 0,
            'certificado' => 0,
            'compra' => 0,
            'live_reagendada' => 0,
            'bloqueado' => 0,
        ],
    ];
    if ($dispatchId <= 0 || $codigo === '') return $stats;

    $filterCfg = live_filter_config($turma['live_filter_tag_ids'] ?? null);
    $includeTagIds = $filterCfg['include_any'];
    $excludeTagIds = $filterCfg['exclude_any'];
    $excludeCert = (int)$filterCfg['exclude_cert'] === 1;
    $excludeZero = (int)$filterCfg['exclude_zero'] === 1;
    $excludePurchase = (int)$filterCfg['exclude_purchase'] === 1;
    $excludeRescheduled = (int)$filterCfg['exclude_rescheduled'] === 1;

    if ($includeTagIds && $tagRelTable) {
        $in = implode(',', array_fill(0, count($includeTagIds), '?'));
        $sql = "
            SELECT u.*
              FROM users u
              JOIN `$tagRelTable` ut ON ut.user_id = u.id
             WHERE u.codigo_turma = ?
               AND ut.tag_id IN ($in)
          GROUP BY u.id
          ORDER BY u.id ASC
        ";
        $stU = $pdo->prepare($sql);
        $stU->execute(array_merge([$codigo], $includeTagIds));
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stU = $pdo->prepare("SELECT * FROM users WHERE codigo_turma = :c ORDER BY id ASC");
        $stU->execute([':c' => $codigo]);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stats['total_alunos'] = count($alunos);
    foreach ($alunos as $aluno) {
        $uid = (int)($aluno['id'] ?? 0);
        if ($uid <= 0) continue;
        $skip = null;
        if (function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $uid)) {
            $skip = 'bloqueado';
        }

        $prog = calc_andamento($pdo, $uid, $totalObrigatoriasGlobal);
        $aluno['andamento'] = $prog['andamento'];
        $aluno['aulas_concluidas'] = $prog['concluidas'];
        $aluno['aulas_totais'] = $prog['total'];

        if ($skip === null && $includeTagIds && !$tagRelTable) $skip = 'include_tag_table_missing';
        if ($skip === null && $excludeZero && (int)$aluno['andamento'] <= 0) $skip = 'andamento_zero';
        if ($skip === null && $excludeTagIds && user_has_any_tag($pdo, $tagRelTable, $uid, $excludeTagIds)) $skip = 'tag_excluida';
        if ($skip === null && $excludeCert && user_has_certificate($pdo, $uid)) $skip = 'certificado';
        if ($skip === null && $excludePurchase && user_has_purchase($pdo, $uid)) $skip = 'compra';
        if ($skip === null && $excludeRescheduled && user_has_active_live_reschedule($pdo, $uid, (string)($turma['data_live'] ?? ''))) $skip = 'live_reagendada';

        if ($skip !== null) {
            $stats['skipped'][$skip] = (int)($stats['skipped'][$skip] ?? 0) + 1;
            live_dispatch_record_recipient($pdo, $dispatchId, $turma, $aluno, 'skipped', $skip, null);
            continue;
        }

        $stats['elegiveis']++;
        $payload = [
            'andamento' => $aluno['andamento'],
            'aulas_concluidas' => $aluno['aulas_concluidas'],
            'aulas_totais' => $aluno['aulas_totais'],
        ];
        live_dispatch_record_recipient($pdo, $dispatchId, $turma, $aluno, 'pending', null, $payload);
    }

    live_dispatch_refresh_summary($pdo, $dispatchId, false);
    try {
        $pdo->prepare("UPDATE live_turma_dispatch_logs SET enqueued_at=NOW(), status='processando', message='Fila criada; processamento em lotes pelo cron.' WHERE id=:id AND enqueued_at IS NULL")
            ->execute([':id' => $dispatchId]);
    } catch (Throwable $e) {}
    return $stats;
}

function live_dispatch_refresh_summary(PDO $pdo, int $dispatchId, bool $finishIfDone = true): array {
    $stats = [
        'total_alunos' => 0,
        'elegiveis' => 0,
        'sf_ok' => 0,
        'sf_fail' => 0,
        'manychat_ok' => 0,
        'manychat_fail' => 0,
        'webhook_ok' => 0,
        'webhook_fail' => 0,
        'skipped' => [],
        'pending' => 0,
        'processing' => 0,
        'failed' => 0,
        'retryable_failed' => 0,
        'sent' => 0,
    ];
    if ($dispatchId <= 0) return $stats;

    try {
        $st = $pdo->prepare("
            SELECT
                COUNT(*) total,
                SUM(status IN ('pending','processing','sent','failed')) elegiveis,
                SUM(status='pending') pending,
                SUM(status='processing') processing,
                SUM(status='sent') sent,
                SUM(status='failed') failed,
                SUM(status='failed' AND attempts < 3) retryable_failed,
                SUM(sf_ok=1) sf_ok,
                SUM(sf_fail=1) sf_fail,
                SUM(manychat_ok=1) manychat_ok,
                SUM(manychat_fail=1) manychat_fail,
                SUM(webhook_ok=1) webhook_ok,
                SUM(webhook_fail=1) webhook_fail
            FROM live_turma_dispatch_recipients
            WHERE dispatch_id=:id
        ");
        $st->execute([':id' => $dispatchId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['total_alunos' => 'total', 'elegiveis' => 'elegiveis', 'pending' => 'pending', 'processing' => 'processing', 'sent' => 'sent', 'failed' => 'failed', 'retryable_failed' => 'retryable_failed', 'sf_ok' => 'sf_ok', 'sf_fail' => 'sf_fail', 'manychat_ok' => 'manychat_ok', 'manychat_fail' => 'manychat_fail', 'webhook_ok' => 'webhook_ok', 'webhook_fail' => 'webhook_fail'] as $out => $in) {
            $stats[$out] = (int)($r[$in] ?? 0);
        }

        $skip = $pdo->prepare("SELECT skip_reason, COUNT(*) qtd FROM live_turma_dispatch_recipients WHERE dispatch_id=:id AND status='skipped' GROUP BY skip_reason");
        $skip->execute([':id' => $dispatchId]);
        foreach ($skip->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sr) {
            $key = (string)($sr['skip_reason'] ?? 'outro');
            $stats['skipped'][$key] = (int)($sr['qtd'] ?? 0);
        }

        $done = ($stats['pending'] <= 0 && $stats['processing'] <= 0 && $stats['retryable_failed'] <= 0);
        $status = 'processando';
        $message = 'Processamento em lotes pelo cron.';
        $finishedSql = '';
        if ($finishIfDone && $done) {
            $status = ($stats['failed'] > 0 || $stats['sf_fail'] > 0 || $stats['manychat_fail'] > 0 || $stats['webhook_fail'] > 0) ? 'concluido_com_falhas' : 'concluido';
            $message = $stats['elegiveis'] > 0 ? 'Fila concluida.' : 'Fila concluida sem alunos elegiveis apos os filtros.';
            $finishedSql = ', finished_at = IF(finished_at IS NULL, NOW(), finished_at)';
        }

        $up = $pdo->prepare("
            UPDATE live_turma_dispatch_logs
               SET total_alunos=:total,
                   elegiveis=:elegiveis,
                   sf_ok=:sf_ok,
                   sf_fail=:sf_fail,
                   manychat_ok=:manychat_ok,
                   manychat_fail=:manychat_fail,
                   webhook_ok=:webhook_ok,
                   webhook_fail=:webhook_fail,
                   skipped_json=:skipped,
                   status=:status,
                   message=:message,
                   last_heartbeat_at=NOW(),
                   locked_until=NULL,
                   lock_token=NULL
                   $finishedSql
             WHERE id=:id
             LIMIT 1
        ");
        $up->execute([
            ':total' => $stats['total_alunos'],
            ':elegiveis' => $stats['elegiveis'],
            ':sf_ok' => $stats['sf_ok'],
            ':sf_fail' => $stats['sf_fail'],
            ':manychat_ok' => $stats['manychat_ok'],
            ':manychat_fail' => $stats['manychat_fail'],
            ':webhook_ok' => $stats['webhook_ok'],
            ':webhook_fail' => $stats['webhook_fail'],
            ':skipped' => json_encode($stats['skipped'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => $status,
            ':message' => $message,
            ':id' => $dispatchId,
        ]);

        if ($finishIfDone && $done) {
            $tid = (int)$pdo->query("SELECT turma_id FROM live_turma_dispatch_logs WHERE id=" . (int)$dispatchId)->fetchColumn();
            if ($tid > 0) {
                $pdo->prepare("UPDATE turmas SET live_disparada=1 WHERE id=:id LIMIT 1")->execute([':id' => $tid]);
            }
        }
    } catch (Throwable $e) {}
    return $stats;
}

function live_dispatch_claim_batch(PDO $pdo, int $dispatchId, int $limit): array {
    $limit = max(1, min(200, $limit));
    try {
        $ids = $pdo->prepare("
            SELECT id
              FROM live_turma_dispatch_recipients
             WHERE dispatch_id=:id
               AND (
                    status='pending'
                    OR (status='processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
                    OR (status='failed' AND attempts < 3)
               )
             ORDER BY
               CASE
                 WHEN status='processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 0
                 WHEN status='failed' AND attempts < 3 THEN 1
                 WHEN status='pending' THEN 2
                 ELSE 3
               END,
               id ASC
             LIMIT {$limit}
        ");
        $ids->execute([':id' => $dispatchId]);
        $rowIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!$rowIds) return [];
        $in = implode(',', array_fill(0, count($rowIds), '?'));
        $up = $pdo->prepare("UPDATE live_turma_dispatch_recipients SET status='processing', attempts=attempts+1, started_at=IF(started_at IS NULL, NOW(), started_at), updated_at=NOW() WHERE id IN ($in)");
        $up->execute($rowIds);
        $st = $pdo->prepare("SELECT * FROM live_turma_dispatch_recipients WHERE id IN ($in) ORDER BY id ASC");
        $st->execute($rowIds);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Formata uma data/hora para BR: dd/mm/aaaa HH:MM:SS
 * Aceita strings 'Y-m-d H:i:s' ou ISO. Se falhar, retorna o valor original.
 */
function dt_br(?string $dt): ?string {
    if (!$dt) return null;
    try {
        $d = new DateTime($dt);
        return $d->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return $dt;
    }
}

/**
 * Conta quantas aulas obrigatórias existem (lessons.ativo=1 e conta_para_conclusao=1).
 * Fallback seguro caso coluna/tabela não exista.
 */
function total_aulas_obrigatorias(PDO $pdo): int {
    if (!table_exists($pdo, 'lessons')) return 0;

    // tenta com conta_para_conclusao
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1");
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // fallback: considera todas ativas como obrigatórias
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1");
            return (int)$st->fetchColumn();
        } catch (Throwable $e2) {
            return 0;
        }
    }
}

/**
 * Calcula andamento do aluno (0..100), baseado em lessons + lesson_progress.
 * Retorna [andamento, concluidas, total].
 */
function calc_andamento(PDO $pdo, int $userId, int $totalObrigatorias): array {
    if ($userId <= 0 || $totalObrigatorias <= 0) {
        return ['andamento' => 0, 'concluidas' => 0, 'total' => max(0, $totalObrigatorias)];
    }

    // caminho principal: join com lessons para respeitar "obrigatórias"
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) 
              FROM lesson_progress lp
              JOIN lessons l ON l.id = lp.lesson_id
             WHERE lp.user_id = :u
               AND lp.status = 'completed'
               AND l.ativo = 1
               AND l.conta_para_conclusao = 1
        ");
        $st->execute([':u' => $userId]);
        $concluidas = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        // fallback: conta só progressos completed (sem join)
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id = :u AND status = 'completed'");
            $st->execute([':u' => $userId]);
            $concluidas = (int)$st->fetchColumn();
        } catch (Throwable $e2) {
            $concluidas = 0;
        }
    }

    $andamento = $totalObrigatorias > 0 ? (int)round(($concluidas / $totalObrigatorias) * 100) : 0;
    if ($andamento < 0) $andamento = 0;
    if ($andamento > 100) $andamento = 100;

    return ['andamento' => $andamento, 'concluidas' => $concluidas, 'total' => $totalObrigatorias];
}


/**
 * Monta payload padrão da live.
 */
function build_live_payload(array $turma, array $aluno): array {
    $jan_ini_iso  = $turma['janela_inicio'] ?? null;
    $jan_fim_iso  = $turma['janela_fim'] ?? null;
    $data_live_iso = $turma['data_live'] ?? null;
    $disparo_iso   = $turma['live_disparo_data'] ?? null;

    $aluno_live_iso = $aluno['turma_live_at'] ?? null;

    return [
        'evento'    => 'LIVE_TURMA_' . ($turma['codigo'] ?? ''),
        'turma'     => [
            'id'                => $turma['id'] ?? null,
            'codigo'            => $turma['codigo'] ?? null,

            // Datas em BR (mantém *_iso para compatibilidade)
            'janela_inicio'     => dt_br($jan_ini_iso),
            'janela_inicio_iso' => $jan_ini_iso,

            'janela_fim'        => dt_br($jan_fim_iso),
            'janela_fim_iso'    => $jan_fim_iso,

            'data_live'         => dt_br($data_live_iso),
            'data_live_iso'     => $data_live_iso,

            'live_disparo_data'     => dt_br($disparo_iso),
            'live_disparo_data_iso' => $disparo_iso,

            'webhook_live_url'  => $turma['webhook_live_url'] ?? null,
            'delay_ms'          => $turma['delay_ms'] ?? null,
            'live_filter_tag_ids' => $turma['live_filter_tag_ids'] ?? null,
        ],
        'aluno'     => [
            'id'       => $aluno['id'] ?? null,
            'nome'     => $aluno['nome'] ?? null,
            'email'    => $aluno['email'] ?? null,
            'telefone' => $aluno['telefone'] ?? null,

            'codigo_turma' => $aluno['codigo_turma'] ?? null,

            'turma_live_at'     => dt_br($aluno_live_iso),
            'turma_live_at_iso' => $aluno_live_iso,

            // Andamento (0..100)
            'andamento'        => $aluno['andamento'] ?? null,
            'aulas_concluidas' => $aluno['aulas_concluidas'] ?? null,
            'aulas_totais'     => $aluno['aulas_totais'] ?? null,
        ],

        // Timestamp em BR (mantém ISO para compatibilidade)
        'timestamp'     => date('d/m/Y H:i:s'),
        'timestamp_iso' => date('c'),
    ];
}

/**
 * Envia POST JSON.
 */
function post_json(string $url, string $json, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp = curl_exec($ch);
    $st   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$st, $resp, $err];
}

function live_dispatch_send_recipient(PDO $pdo, int $dispatchId, array $turma, array $recipient, bool $hasSfCol, bool $hasManychatLiveRules): void {
    $codigo = (string)($turma['codigo'] ?? '');
    $uid = (int)($recipient['user_id'] ?? 0);
    if ($dispatchId <= 0 || $codigo === '' || $uid <= 0) return;

    $stU = $pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");
    $stU->execute([':id' => $uid]);
    $aluno = $stU->fetch(PDO::FETCH_ASSOC) ?: [
        'id' => $uid,
        'nome' => (string)($recipient['nome'] ?? ''),
        'email' => (string)($recipient['email'] ?? ''),
        'telefone' => (string)($recipient['telefone'] ?? ''),
        'codigo_turma' => $codigo,
    ];

    $snapshot = json_decode((string)($recipient['payload_json'] ?? ''), true);
    if (!is_array($snapshot)) $snapshot = [];
    foreach (['andamento', 'aulas_concluidas', 'aulas_totais'] as $k) {
        if (array_key_exists($k, $snapshot)) $aluno[$k] = $snapshot[$k];
    }

    $url = trim((string)($turma['webhook_live_url'] ?? ''));
    $whEnabled = (int)($turma['live_webhook_enabled'] ?? 1) === 1 && $url !== '';
    $sfEnabled = $hasSfCol && (int)($turma['sf_enabled'] ?? 0) === 1;
    $webhookOk = 0;
    $webhookFail = 0;
    $sfOkCount = 0;
    $sfFailCount = 0;
    $mcOkCount = 0;
    $mcFailCount = 0;
    $errors = [];

    $extra = [
        'codigo_turma' => $turma['codigo'],
        'codigo_live' => $turma['codigo_live'] ?? $turma['codigo'],
        'data_live' => $turma['data_live'],
        'data_live_br' => (function(?string $d): ?string {
            if (!$d) return null;
            try { return (new DateTime($d))->format('d/m/Y H:i'); } catch (Throwable $e) { return $d; }
        })($turma['data_live'] ?? null),
        'andamento' => $aluno['andamento'] ?? null,
        'aulas_concluidas' => $aluno['aulas_concluidas'] ?? null,
        'aulas_totais' => $aluno['aulas_totais'] ?? null,
    ];

    if ($whEnabled) {
        $payload = build_live_payload($turma, $aluno);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        [$status, $resp, $err] = post_json($url, $json ?: '{}', 15);
        if ($status >= 200 && $status < 300 && !$err) $webhookOk = 1;
        else {
            $webhookFail = 1;
            $errors[] = 'webhook direto: ' . ($err ?: ('HTTP ' . $status));
        }

        try {
            if (table_exists($pdo, 'webhook_logs')) {
                $pdo->prepare("
                    INSERT INTO webhook_logs
                        (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at)
                    VALUES (NULL, :u, :e, :p, :s, :b, :er, NOW())
                ")->execute([
                    ':u' => $uid,
                    ':e' => 'LIVE_TURMA_' . $codigo,
                    ':p' => $json,
                    ':s' => $status ?: null,
                    ':b' => $resp,
                    ':er' => $err ?: null,
                ]);
            }
        } catch (Throwable $e) {}
    }

    $userStd = [
        'id' => $aluno['id'] ?? $uid,
        'nome' => $aluno['nome'] ?? '',
        'email' => $aluno['email'] ?? '',
        'telefone' => $aluno['telefone'] ?? '',
    ];

    try {
        disparar_evento_webhooks($pdo, 'LIVE_TURMA', $userStd, $extra);
    } catch (Throwable $e) {
        $errors[] = 'webhook global: ' . $e->getMessage();
    }

    if ($sfEnabled) {
        try {
            $sfOk = sf_disparar_evento($pdo, 'LIVE_TURMA', $userStd, $extra);
            if ($sfOk) $sfOkCount = 1;
            else {
                $sfFailCount = 1;
                $errors[] = 'SuperFuncionario retornou falha';
            }
        } catch (Throwable $e) {
            $sfFailCount = 1;
            $errors[] = 'SuperFuncionario: ' . $e->getMessage();
        }
    }

    if (function_exists('whatsapp_event_notifications_dispatch')) {
        try {
            whatsapp_event_notifications_dispatch($pdo, 'LIVE_TURMA', $userStd, $extra);
            whatsapp_event_notifications_dispatch($pdo, 'LIVE_TURMA_' . $codigo, $userStd, $extra);
        } catch (Throwable $e) {}
    }

    if ($hasManychatLiveRules) {
        try {
            $mcOkGlobal = mc_disparar_evento($pdo, 'LIVE_TURMA', $userStd, $extra);
            $mcOkTurma = mc_disparar_evento($pdo, 'LIVE_TURMA_' . $codigo, $userStd, $extra);
            if ($mcOkGlobal || $mcOkTurma) $mcOkCount = 1;
            else {
                $mcFailCount = 1;
                $errors[] = 'ManyChat retornou falha';
            }
        } catch (Throwable $e) {
            $mcFailCount = 1;
            $errors[] = 'ManyChat: ' . $e->getMessage();
        }
    }

    $hasFailure = ($webhookFail || $sfFailCount || $mcFailCount) > 0;
    $newStatus = $hasFailure ? 'failed' : 'sent';
    try {
        $up = $pdo->prepare("
            UPDATE live_turma_dispatch_recipients
               SET status=:status,
                   webhook_ok=:webhook_ok,
                   webhook_fail=:webhook_fail,
                   sf_ok=:sf_ok,
                   sf_fail=:sf_fail,
                   manychat_ok=:manychat_ok,
                   manychat_fail=:manychat_fail,
                   error_message=:error_message,
                   finished_at=IF(:status2='sent', NOW(), finished_at),
                   updated_at=NOW()
             WHERE id=:id
             LIMIT 1
        ");
        $up->execute([
            ':status' => $newStatus,
            ':webhook_ok' => $webhookOk,
            ':webhook_fail' => $webhookFail,
            ':sf_ok' => $sfOkCount,
            ':sf_fail' => $sfFailCount,
            ':manychat_ok' => $mcOkCount,
            ':manychat_fail' => $mcFailCount,
            ':error_message' => $errors ? implode(' | ', array_slice($errors, 0, 6)) : null,
            ':status2' => $newStatus,
            ':id' => (int)$recipient['id'],
        ]);
    } catch (Throwable $e) {}
}

// ----------------------------------------------------------------------------------
// 1) Detecta se coluna sf_enabled existe (migration gradual)
// ----------------------------------------------------------------------------------
$hasSfCol = false;
try {
    $pdo->query("SELECT sf_enabled FROM turmas LIMIT 0");
    $hasSfCol = true;
} catch (Throwable $e) {}

$hasManychatLiveRules = false;
try {
    $mcCfg = mc_get_config($pdo);
    if ((int)$mcCfg['is_enabled'] === 1 && trim((string)$mcCfg['token']) !== '') {
        $stMc = $pdo->query("SELECT 1 FROM manychat_rules WHERE is_active = 1 AND (evento = 'LIVE_TURMA' OR evento LIKE 'LIVE_TURMA\\_%') LIMIT 1");
        $hasManychatLiveRules = (bool)($stMc ? $stMc->fetchColumn() : false);
    }
} catch (Throwable $e) {}

ensure_live_dispatch_log_table($pdo);

$sfOrClause = $hasSfCol ? "OR (sf_enabled = 1)" : "";
$manychatOrClause = $hasManychatLiveRules ? "OR 1=1" : "";
$manualTurmaId = (int)($GLOBALS['manual_live_turma_id'] ?? 0);

if ($manualTurmaId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE id = :id
           AND data_live IS NOT NULL
           AND (
               (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
               $sfOrClause
               $manychatOrClause
           )
         LIMIT 1
    ");
    $stmt->execute([':id' => $manualTurmaId]);
} else {
    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE live_disparada = 0
           AND live_disparo_data IS NOT NULL
           AND live_disparo_data <= :agora
           AND (data_live IS NULL OR data_live >= DATE_SUB(NOW(), INTERVAL 2 HOUR))
           AND (
               (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
               $sfOrClause
               $manychatOrClause
           )
      ORDER BY live_disparo_data ASC, id ASC
      LIMIT 10
    ");
    $stmt->execute([':agora' => $agora]);
}
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($manualTurmaId <= 0) {
    try {
        $stOpen = $pdo->query("
            SELECT t.*
             FROM live_turma_dispatch_logs l
             JOIN turmas t ON t.id = l.turma_id
             WHERE l.status IN (" . live_dispatch_open_status_sql() . ")
               AND (
                    EXISTS (SELECT 1 FROM live_turma_dispatch_recipients lr WHERE lr.dispatch_id = l.id LIMIT 1)
                    OR t.data_live >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               )
          GROUP BY t.id
          ORDER BY MIN(l.started_at) ASC, t.id ASC
             LIMIT 10
        ");
        $seen = [];
        foreach ($turmas as $t) $seen[(int)$t['id']] = true;
        foreach ($stOpen->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
            if (!isset($seen[(int)$t['id']])) $turmas[] = $t;
        }
    } catch (Throwable $e) {}
}

$totalObrigatoriasGlobal = total_aulas_obrigatorias($pdo);
$tagRelTable = first_existing_table($pdo, [
    'user_tags',
    'usuarios_tags',
    'aluno_tags',
    'users_tags',
    'tags_users',
    'user_tag_rel',
    'user_tag_relations',
]);

if (!$turmas) {
    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = ['ok' => false, 'message' => 'Turma indisponivel ou sem integracao ativa.'];
        return;
    }
    echo "Nenhuma turma pendente para processar.\n";
    return;
}

$processedDispatches = 0;
$processedRecipients = 0;
foreach ($turmas as $turma) {
    $codigo = (string)($turma['codigo'] ?? '');
    if ($codigo === '') continue;

    $dispatchLogId = live_dispatch_create_or_reuse($pdo, $turma, live_dispatch_trigger_type());
    if ($dispatchLogId <= 0) continue;

    $lockToken = bin2hex(random_bytes(16));
    try {
        $claim = $pdo->prepare("
            UPDATE live_turma_dispatch_logs
               SET locked_until=DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                   lock_token=:token,
                   last_heartbeat_at=NOW(),
                   batch_runs=batch_runs+1,
                   status='processando'
             WHERE id=:id
               AND status IN (" . live_dispatch_open_status_sql() . ")
               AND (locked_until IS NULL OR locked_until < NOW() OR lock_token=:token2)
             LIMIT 1
        ");
        $claim->execute([':token' => $lockToken, ':token2' => $lockToken, ':id' => $dispatchLogId]);
        if ($claim->rowCount() !== 1) continue;
    } catch (Throwable $e) {
        continue;
    }

    if (live_dispatch_row_count($pdo, $dispatchLogId) <= 0) {
        live_dispatch_enqueue_recipients($pdo, $dispatchLogId, $turma, $tagRelTable, (int)$totalObrigatoriasGlobal);
    }

    $delay = (int)($turma['delay_ms'] ?? 500);
    if ($delay < 0) $delay = 0;
    if ($delay > 30000) $delay = 30000;

    $batchLimit = (int)get_setting('live_dispatch_batch_limit', '20');
    if ($batchLimit < 1) $batchLimit = 1;
    if ($batchLimit > 200) $batchLimit = 200;
    if ($delay > 0) $batchLimit = min($batchLimit, max(1, (int)floor(45000 / max(1, $delay))));
    if ($manualTurmaId > 0) $batchLimit = min($batchLimit, 10);

    $recipients = live_dispatch_claim_batch($pdo, $dispatchLogId, $batchLimit);
    $batchStarted = microtime(true);
    foreach ($recipients as $recipient) {
        live_dispatch_send_recipient($pdo, $dispatchLogId, $turma, $recipient, $hasSfCol, $hasManychatLiveRules);
        $processedRecipients++;
        if ($delay > 0) usleep($delay * 1000);
        if ((microtime(true) - $batchStarted) >= 50) break;
    }

    $stats = live_dispatch_refresh_summary($pdo, $dispatchLogId, true);
    $processedDispatches++;
    if ($manualTurmaId > 0) {
        $remaining = (int)($stats['pending'] ?? 0) + (int)($stats['processing'] ?? 0) + (int)($stats['retryable_failed'] ?? 0);
        $GLOBALS['manual_live_turma_result'] = [
            'ok' => true,
            'message' => $remaining > 0 ? 'Disparo manual enfileirado. O cron continuara processando ate concluir.' : 'Disparo manual concluido.',
            'stats' => $stats,
            'dispatch_id' => $dispatchLogId,
        ];
    }
}

echo "Filas processadas: {$processedDispatches}; destinatarios neste lote: {$processedRecipients}\n";
return;

// ----------------------------------------------------------------------------------
// 2) Pega turmas aptas: não disparadas, com data de disparo <= agora, e
//    que tenham PELO MENOS webhook habilitado OU SF habilitado.
// ----------------------------------------------------------------------------------
$sfOrClause = $hasSfCol ? "OR (sf_enabled = 1)" : "";
$manychatOrClause = $hasManychatLiveRules ? "OR 1=1" : "";

$manualTurmaId = (int)($GLOBALS['manual_live_turma_id'] ?? 0);
if ($manualTurmaId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE id = :id
           AND live_disparada = 0
           AND data_live IS NOT NULL
           AND data_live > :agora
           AND (
               (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
               $sfOrClause
               $manychatOrClause
           )
         LIMIT 1
    ");
    $stmt->execute([':id' => $manualTurmaId, ':agora' => $agora]);
} else {
    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE live_disparada = 0
           AND live_disparo_data IS NOT NULL
           AND live_disparo_data <= :agora
           AND (
               (live_webhook_enabled = 1 AND webhook_live_url IS NOT NULL AND webhook_live_url <> '')
               $sfOrClause
               $manychatOrClause
           )
      ORDER BY live_disparo_data ASC, id ASC
    ");
    $stmt->execute([':agora' => $agora]);
}
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];


// Total de aulas obrigatórias para cálculo de andamento
$totalObrigatoriasGlobal = total_aulas_obrigatorias($pdo);
if (!$turmas) {
    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = ['ok' => false, 'message' => 'Turma indisponivel: ja disparada, live vencida ou sem integracao ativa.'];
        return;
    }
    echo "Nenhuma turma pendente para processar.\n";
    return;
}

// ----------------------------------------------------------------------------------
// 2) Descobre tabela de relação user->tags (se existir), para filtro opcional
// ----------------------------------------------------------------------------------
$tagRelTable = first_existing_table($pdo, [
    'user_tags',
    'usuarios_tags',
    'aluno_tags',
    'users_tags',
    'tags_users',
    'user_tag_rel',
    'user_tag_relations',
]);

foreach ($turmas as $turma) {
    $codigo = (string)($turma['codigo'] ?? '');
    if ($codigo === '') continue;

    $claim = $pdo->prepare("UPDATE turmas SET live_disparada=1 WHERE id=:id AND live_disparada=0 LIMIT 1");
    $claim->execute([':id' => (int)$turma['id']]);
    if ($claim->rowCount() !== 1) continue;

    $dispatchLogId = start_live_dispatch_log($pdo, $turma);
    $dispatchStats = [
        'total_alunos' => 0,
        'elegiveis' => 0,
        'sf_ok' => 0,
        'sf_fail' => 0,
        'manychat_ok' => 0,
        'manychat_fail' => 0,
        'webhook_ok' => 0,
        'webhook_fail' => 0,
        'skipped' => [
            'include_tag_table_missing' => 0,
            'andamento_zero' => 0,
            'tag_excluida' => 0,
            'certificado' => 0,
            'compra' => 0,
            'live_reagendada' => 0,
        ],
    ];

    $url       = trim((string)($turma['webhook_live_url'] ?? ''));
    $whEnabled = (int)($turma['live_webhook_enabled'] ?? 1) === 1 && $url !== '';
    $sfEnabled = $hasSfCol && (int)($turma['sf_enabled'] ?? 0) === 1;

    $delay = (int)($turma['delay_ms'] ?? 500);
    if ($delay < 0) $delay = 0;
    if ($delay > 30000) $delay = 30000; // 30s de safety

    $filterCfg = live_filter_config($turma['live_filter_tag_ids'] ?? null);
    $includeTagIds = $filterCfg['include_any'];
    $excludeTagIds = $filterCfg['exclude_any'];
    $excludeCert = (int)$filterCfg['exclude_cert'] === 1;
    $excludeZero = (int)$filterCfg['exclude_zero'] === 1;
    $excludePurchase = (int)$filterCfg['exclude_purchase'] === 1;
    $excludeRescheduled = (int)$filterCfg['exclude_rescheduled'] === 1;
    $tagIds = $includeTagIds;

    // ----------------------------------------------------------------------------------
    // 3) Busca alunos da turma
    // ----------------------------------------------------------------------------------
    if ($tagIds && $tagRelTable) {
        // tenta colunas padrão: user_id + tag_id
        // (se seu rel tiver nomes diferentes, eu ajusto quando você mandar o SQL do rel)
        $in = implode(',', array_fill(0, count($tagIds), '?'));

        $sql = "
            SELECT u.*
              FROM users u
              JOIN `$tagRelTable` ut ON ut.user_id = u.id
             WHERE u.codigo_turma = ?
               AND ut.tag_id IN ($in)
          GROUP BY u.id
          ORDER BY u.id ASC
        ";

        $params = array_merge([$codigo], $tagIds);

        $stU = $pdo->prepare($sql);
        $stU->execute($params);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stU = $pdo->prepare("SELECT * FROM users WHERE codigo_turma = :c ORDER BY id ASC");
        $stU->execute([':c' => $codigo]);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $dispatchStats['total_alunos'] = count($alunos);

    // ----------------------------------------------------------------------------------
    // 4) Dispara para cada aluno — webhook e/ou SuperFuncionário
    // ----------------------------------------------------------------------------------
    foreach ($alunos as $aluno) {
        $uid = (int)($aluno['id'] ?? 0);
        if (function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $uid)) {
            $dispatchStats['skipped']['bloqueado'] = (int)($dispatchStats['skipped']['bloqueado'] ?? 0) + 1;
            continue;
        }

        $prog = calc_andamento($pdo, $uid, (int)$totalObrigatoriasGlobal);
        $aluno['andamento']        = $prog['andamento'];
        $aluno['aulas_concluidas'] = $prog['concluidas'];
        $aluno['aulas_totais']     = $prog['total'];
        if ($includeTagIds && !$tagRelTable) { $dispatchStats['skipped']['include_tag_table_missing']++; continue; }
        if ($excludeZero && (int)$aluno['andamento'] <= 0) { $dispatchStats['skipped']['andamento_zero']++; continue; }
        if ($excludeTagIds && user_has_any_tag($pdo, $tagRelTable, $uid, $excludeTagIds)) { $dispatchStats['skipped']['tag_excluida']++; continue; }
        if ($excludeCert && user_has_certificate($pdo, $uid)) { $dispatchStats['skipped']['certificado']++; continue; }
        if ($excludePurchase && user_has_purchase($pdo, $uid)) { $dispatchStats['skipped']['compra']++; continue; }
        if ($excludeRescheduled && user_has_active_live_reschedule($pdo, $uid, (string)($turma['data_live'] ?? ''))) { $dispatchStats['skipped']['live_reagendada']++; continue; }
        $dispatchStats['elegiveis']++;

        // Extra disponível para resolução de campos SF e payload do webhook
        $extra = [
            'codigo_turma'     => $turma['codigo'],
            'codigo_live'      => $turma['codigo_live'] ?? $turma['codigo'],
            'data_live'        => $turma['data_live'],
            'data_live_br'     => (function(?string $d): ?string {
                if (!$d) return null;
                try { return (new DateTime($d))->format('d/m/Y H:i'); } catch (Throwable $e) { return $d; }
            })($turma['data_live']),
            'andamento'        => $aluno['andamento'],
            'aulas_concluidas' => $aluno['aulas_concluidas'],
            'aulas_totais'     => $aluno['aulas_totais'],
        ];

        // --- Webhook ---
        if ($whEnabled) {
            $payload = build_live_payload($turma, $aluno);
            $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);

            [$status, $resp, $err] = post_json($url, $json ?: '{}', 15);
            if ($status >= 200 && $status < 300 && !$err) $dispatchStats['webhook_ok']++;
            else $dispatchStats['webhook_fail']++;

            try {
                if (table_exists($pdo, 'webhook_logs')) {
                    $pdo->prepare("
                        INSERT INTO webhook_logs
                            (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at)
                        VALUES (NULL, :u, :e, :p, :s, :b, :er, NOW())
                    ")->execute([
                        ':u'  => $aluno['id'] ?? null,
                        ':e'  => 'LIVE_TURMA_' . $codigo,
                        ':p'  => $json,
                        ':s'  => $status ?: null,
                        ':b'  => $resp,
                        ':er' => $err ?: null,
                    ]);
                }
            } catch (Throwable $e) {}
        }

        // --- Regras globais: webhook e SF com evento LIVE_TURMA ---
        $userStd = [
            'id'       => $aluno['id']       ?? null,
            'nome'     => $aluno['nome']      ?? null,
            'email'    => $aluno['email']     ?? null,
            'telefone' => $aluno['telefone']  ?? null,
        ];
        try {
            disparar_evento_webhooks($pdo, 'LIVE_TURMA', $userStd, $extra);
        } catch (Throwable $e) {}
        if ($sfEnabled) {
            try {
                $sfOk = sf_disparar_evento($pdo, 'LIVE_TURMA', $userStd, $extra);
                if ($sfOk) $dispatchStats['sf_ok']++;
                else $dispatchStats['sf_fail']++;
            } catch (Throwable $e) {
                $dispatchStats['sf_fail']++;
            }
        }

        if (function_exists('whatsapp_event_notifications_dispatch')) {
            try {
                whatsapp_event_notifications_dispatch($pdo, 'LIVE_TURMA', $userStd, $extra);
                whatsapp_event_notifications_dispatch($pdo, 'LIVE_TURMA_' . $codigo, $userStd, $extra);
            } catch (Throwable $e) {}
        }

        if ($hasManychatLiveRules) {
            try {
                $mcOkGlobal = mc_disparar_evento($pdo, 'LIVE_TURMA', $userStd, $extra);
                $mcOkTurma = mc_disparar_evento($pdo, 'LIVE_TURMA_' . $codigo, $userStd, $extra);
                if ($mcOkGlobal || $mcOkTurma) $dispatchStats['manychat_ok']++;
                else $dispatchStats['manychat_fail']++;
            } catch (Throwable $e) {
                $dispatchStats['manychat_fail']++;
            }
        }

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    // ----------------------------------------------------------------------------------
    // 5) Marca turma como disparada (mesmo sem alunos elegíveis)
    // ----------------------------------------------------------------------------------
    $finalStatus = ($dispatchStats['sf_fail'] > 0 || $dispatchStats['manychat_fail'] > 0 || $dispatchStats['webhook_fail'] > 0) ? 'concluido_com_falhas' : 'concluido';
    $message = 'Turma marcada como disparada.';
    if ($dispatchStats['elegiveis'] <= 0) $message = 'Turma marcada como disparada, mas nenhum aluno ficou elegivel apos os filtros.';
    finish_live_dispatch_log($pdo, $dispatchLogId, $dispatchStats, $finalStatus, $message);
    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = [
            'ok' => true,
            'message' => $message,
            'stats' => $dispatchStats,
        ];
    }
}
