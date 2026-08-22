<?php
// /cron/processar_lives.php
// Dispara webhooks de live por turma (fila de alunos) quando chegar a data/hora configurada.
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();
$agora = date('Y-m-d H:i:s');
$manualTurmaId = isset($GLOBALS['manual_live_turma_id']) ? (int)$GLOBALS['manual_live_turma_id'] : 0;
$startedAt = microtime(true);
$maxRuntimeSeconds = $manualTurmaId > 0 ? 105 : 170;
$maxTurmaRuntimeSeconds = $manualTurmaId > 0 ? 105 : 45;
$maxStudentsPerRun = 120;

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

function live_dispatch_ensure_schema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;

    try {
        foreach ([
            'live_dispatch_cursor_user_id' => "ALTER TABLE turmas ADD COLUMN live_dispatch_cursor_user_id INT UNSIGNED NOT NULL DEFAULT 0",
            'live_dispatch_started_at' => "ALTER TABLE turmas ADD COLUMN live_dispatch_started_at DATETIME NULL",
            'live_dispatch_finished_at' => "ALTER TABLE turmas ADD COLUMN live_dispatch_finished_at DATETIME NULL",
        ] as $column => $sql) {
            if (!column_exists($pdo, 'turmas', $column)) $pdo->exec($sql);
        }
    } catch (Throwable $e) {}

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS live_turma_dispatch_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                turma_id INT NULL,
                turma_codigo VARCHAR(80) NULL,
                trigger_type VARCHAR(30) NOT NULL DEFAULT 'cron',
                planned_at DATETIME NULL,
                started_at DATETIME NOT NULL,
                enqueued_at DATETIME NULL,
                finished_at DATETIME NULL,
                last_heartbeat_at DATETIME NULL,
                batch_runs INT NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                lock_token VARCHAR(64) NULL,
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

    foreach ([
        ['live_turma_dispatch_logs', 'trigger_type', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN trigger_type VARCHAR(30) NOT NULL DEFAULT 'cron' AFTER turma_codigo"],
        ['live_turma_dispatch_logs', 'enqueued_at', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN enqueued_at DATETIME NULL AFTER started_at"],
        ['live_turma_dispatch_logs', 'last_heartbeat_at', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN last_heartbeat_at DATETIME NULL AFTER finished_at"],
        ['live_turma_dispatch_logs', 'batch_runs', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN batch_runs INT NOT NULL DEFAULT 0 AFTER last_heartbeat_at"],
        ['live_turma_dispatch_logs', 'locked_until', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN locked_until DATETIME NULL AFTER batch_runs"],
        ['live_turma_dispatch_logs', 'lock_token', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN lock_token VARCHAR(64) NULL AFTER locked_until"],
        ['live_turma_dispatch_logs', 'manychat_ok', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN manychat_ok INT NOT NULL DEFAULT 0 AFTER sf_fail"],
        ['live_turma_dispatch_logs', 'manychat_fail', "ALTER TABLE live_turma_dispatch_logs ADD COLUMN manychat_fail INT NOT NULL DEFAULT 0 AFTER manychat_ok"],
        ['live_turma_dispatch_recipients', 'manychat_ok', "ALTER TABLE live_turma_dispatch_recipients ADD COLUMN manychat_ok TINYINT(1) NOT NULL DEFAULT 0 AFTER sf_fail"],
        ['live_turma_dispatch_recipients', 'manychat_fail', "ALTER TABLE live_turma_dispatch_recipients ADD COLUMN manychat_fail TINYINT(1) NOT NULL DEFAULT 0 AFTER manychat_ok"],
    ] as [$table, $column, $sql]) {
        try {
            if (!column_exists($pdo, $table, $column)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }

    $ready = true;
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

function live_dispatch_log_id(PDO $pdo, array $turma, bool $manual): int {
    live_dispatch_ensure_schema($pdo);
    $turmaId = (int)($turma['id'] ?? 0);
    $codigo = (string)($turma['codigo'] ?? '');
    $planned = (string)($turma['live_disparo_data'] ?? '');

    try {
        $st = $pdo->prepare("
            SELECT id
              FROM live_turma_dispatch_logs
             WHERE turma_id = :turma_id
               AND status IN ('iniciado','processando','queued')
          ORDER BY id DESC
             LIMIT 1
        ");
        $st->execute([':turma_id' => $turmaId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;

        $ins = $pdo->prepare("
            INSERT INTO live_turma_dispatch_logs
                (turma_id, turma_codigo, trigger_type, planned_at, started_at, enqueued_at, last_heartbeat_at, status, message)
            VALUES
                (:turma_id, :codigo, :trigger_type, :planned_at, NOW(), NOW(), NOW(), 'processando', :message)
        ");
        $ins->execute([
            ':turma_id' => $turmaId ?: null,
            ':codigo' => $codigo !== '' ? $codigo : null,
            ':trigger_type' => $manual ? 'manual' : 'cron',
            ':planned_at' => $planned !== '' ? $planned : null,
            ':message' => 'Disparo iniciado em lotes.',
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function live_dispatch_update_log(PDO $pdo, int $dispatchId, array $stats, string $status, string $message): void {
    if ($dispatchId <= 0) return;
    try {
        $pdo->prepare("
            UPDATE live_turma_dispatch_logs
               SET status = :status,
                   message = :message,
                   last_heartbeat_at = NOW(),
                   finished_at = IF(:finished=1, NOW(), finished_at),
                   batch_runs = batch_runs + 1,
                   total_alunos = total_alunos + :total,
                   elegiveis = elegiveis + :eligible,
                   webhook_ok = webhook_ok + :ok,
                   webhook_fail = webhook_fail + :fail,
                   sf_ok = sf_ok + :sf_ok,
                   sf_fail = sf_fail + :sf_fail,
                   manychat_ok = manychat_ok + :manychat_ok,
                   manychat_fail = manychat_fail + :manychat_fail
             WHERE id = :id
        ")->execute([
            ':status' => $status,
            ':message' => $message,
            ':finished' => $status === 'concluido' ? 1 : 0,
            ':total' => (int)($stats['seen'] ?? 0),
            ':eligible' => (int)($stats['eligible'] ?? 0),
            ':ok' => (int)($stats['sent'] ?? 0),
            ':fail' => (int)($stats['failed'] ?? 0),
            ':sf_ok' => (int)($stats['sf_ok'] ?? 0),
            ':sf_fail' => (int)($stats['sf_fail'] ?? 0),
            ':manychat_ok' => (int)($stats['manychat_ok'] ?? 0),
            ':manychat_fail' => (int)($stats['manychat_fail'] ?? 0),
            ':id' => $dispatchId,
        ]);
    } catch (Throwable $e) {}
}

function live_dispatch_recipient(PDO $pdo, int $dispatchId, array $turma, array $aluno, string $status, ?string $reason = null, ?string $payload = null): void {
    if ($dispatchId <= 0) return;
    try {
        $pdo->prepare("
            INSERT INTO live_turma_dispatch_recipients
                (dispatch_id, turma_id, turma_codigo, user_id, nome, email, telefone, status, skip_reason, payload_json, started_at, finished_at)
            VALUES
                (:dispatch, :turma_id, :turma_codigo, :user_id, :nome, :email, :telefone, :status, :reason, :payload, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                skip_reason = VALUES(skip_reason),
                payload_json = COALESCE(VALUES(payload_json), payload_json),
                attempts = attempts + IF(VALUES(status) IN ('sent','failed'), 1, 0),
                webhook_ok = IF(VALUES(status)='sent', 1, webhook_ok),
                webhook_fail = IF(VALUES(status)='failed', 1, webhook_fail),
                error_message = IF(VALUES(status)='failed', VALUES(skip_reason), error_message),
                finished_at = NOW()
        ")->execute([
            ':dispatch' => $dispatchId,
            ':turma_id' => (int)($turma['id'] ?? 0) ?: null,
            ':turma_codigo' => (string)($turma['codigo'] ?? '') ?: null,
            ':user_id' => (int)($aluno['id'] ?? 0),
            ':nome' => (string)($aluno['nome'] ?? '') ?: null,
            ':email' => (string)($aluno['email'] ?? '') ?: null,
            ':telefone' => (string)($aluno['telefone'] ?? '') ?: null,
            ':status' => $status,
            ':reason' => $reason,
            ':payload' => $payload,
        ]);
    } catch (Throwable $e) {}
}

function live_dispatch_advance_cursor(PDO $pdo, array $turma, int $userId): void {
    $turmaId = (int)($turma['id'] ?? 0);
    if ($turmaId <= 0 || $userId <= 0) return;
    try {
        $pdo->prepare("
            UPDATE turmas
               SET live_dispatch_cursor_user_id = GREATEST(COALESCE(live_dispatch_cursor_user_id, 0), :cursor)
             WHERE id = :id
        ")->execute([':cursor' => $userId, ':id' => $turmaId]);
        $pdo->prepare("
            UPDATE live_turma_dispatch_logs
               SET last_heartbeat_at = NOW()
             WHERE turma_id = :id
               AND status IN ('iniciado','processando','queued')
        ")->execute([':id' => $turmaId]);
    } catch (Throwable $e) {}
}

function live_dispatch_recipient_done(PDO $pdo, int $dispatchId, int $userId): bool {
    if ($dispatchId <= 0 || $userId <= 0) return false;
    try {
        $st = $pdo->prepare("
            SELECT 1
              FROM live_turma_dispatch_recipients
             WHERE dispatch_id = :dispatch_id
               AND user_id = :user_id
               AND status IN ('sent', 'skipped')
             LIMIT 1
        ");
        $st->execute([':dispatch_id' => $dispatchId, ':user_id' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function live_turma_sf_rule(array $turma): ?array {
    $tags = trim((string)($turma['sf_tags_text'] ?? ''));
    $flows = trim((string)($turma['sf_flows_text'] ?? ''));
    $fields = trim((string)($turma['sf_fields_json'] ?? ''));
    if ($tags === '' && $flows === '' && $fields === '') return null;
    return [
        'id' => 0,
        'tags_text' => $tags,
        'flows_text' => $flows,
        'fields_json' => $fields,
        'endpoint_override' => null,
    ];
}

function live_payload_extra(array $turma, array $aluno, array $payload): array {
    $extra = [
        'codigo_turma' => (string)($turma['codigo'] ?? ($aluno['codigo_turma'] ?? '')),
        'codigo_live' => (string)($turma['codigo_live'] ?? ''),
        'data_live' => (string)($turma['data_live'] ?? ''),
        'data_live_iso' => (string)($turma['data_live'] ?? ''),
        'live_disparo_data' => (string)($turma['live_disparo_data'] ?? ''),
        'andamento' => $aluno['andamento'] ?? null,
        'aulas_concluidas' => $aluno['aulas_concluidas'] ?? null,
        'aulas_totais' => $aluno['aulas_totais'] ?? null,
        'turma' => $payload['turma'] ?? [],
    ];
    $turmaRule = live_turma_sf_rule($turma);
    if ($turmaRule !== null) $extra['_integration_rule'] = $turmaRule;
    return $extra;
}

function live_already_logged(PDO $pdo, int $userId, string $codigo, ?string $plannedAt): bool {
    if ($userId <= 0 || $codigo === '' || !table_exists($pdo, 'webhook_logs')) return false;
    try {
        $sql = "SELECT 1 FROM webhook_logs WHERE user_id = :user AND evento = :evento";
        $params = [':user' => $userId, ':evento' => 'LIVE_TURMA_' . $codigo];
        if ($plannedAt) {
            $sql .= " AND created_at >= DATE_SUB(:planned, INTERVAL 12 HOUR)";
            $params[':planned'] = $plannedAt;
        }
        $sql .= " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

live_dispatch_ensure_schema($pdo);

// ----------------------------------------------------------------------------------
// 1) Pega turmas aptas: habilitado, não disparada, com URL, e hora de disparo <= agora
//    Regra: se live_disparo_data vazio -> usa data_live
// ----------------------------------------------------------------------------------
$whereManual = $manualTurmaId > 0 ? " AND t.id = :manual_id" : " AND t.live_disparo_data <= :agora";
$stmt = $pdo->prepare("
    SELECT t.*, lp.progress_at AS live_dispatch_last_progress_at
      FROM turmas t
 LEFT JOIN (
            SELECT turma_id, MAX(last_heartbeat_at) AS progress_at
              FROM live_turma_dispatch_logs
             WHERE status IN ('iniciado','processando','queued')
          GROUP BY turma_id
       ) lp ON lp.turma_id = t.id
     WHERE t.live_disparada = 0
       AND (
            (t.live_webhook_enabled = 1 AND t.webhook_live_url IS NOT NULL AND t.webhook_live_url <> '')
            OR t.sf_enabled = 1
       )
       AND t.live_disparo_data IS NOT NULL
       {$whereManual}
  ORDER BY
       CASE WHEN t.live_dispatch_started_at IS NULL AND t.live_dispatch_cursor_user_id = 0 THEN 0 ELSE 1 END ASC,
       COALESCE(lp.progress_at, t.live_dispatch_started_at, t.live_disparo_data) ASC,
       t.live_disparo_data ASC,
       t.id ASC
     LIMIT 5
");
if ($manualTurmaId > 0) {
    $stmt->execute([':manual_id' => $manualTurmaId]);
} else {
    $stmt->execute([':agora' => $agora]);
}
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];


// Total de aulas obrigatórias para cálculo de andamento
$totalObrigatoriasGlobal = total_aulas_obrigatorias($pdo);
if (!$turmas) {
    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = [
            'ok' => false,
            'message' => 'Nenhuma turma pendente encontrada para disparo manual.',
        ];
    }
    if (empty($GLOBALS['cron_manager_task_key'])) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => true,
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'message' => 'Nenhuma turma pendente para disparo de live.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (!empty($GLOBALS['cron_manager_task_key'])) {
        return;
    }
    exit;
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
    $turmaStartedAt = microtime(true);
    $codigo = (string)($turma['codigo'] ?? '');
    if ($codigo === '') continue;

    $url = trim((string)($turma['webhook_live_url'] ?? ''));
    $webhookEnabled = (int)($turma['live_webhook_enabled'] ?? 0) === 1 && $url !== '';
    $sfEnabled = (int)($turma['sf_enabled'] ?? 0) === 1;
    if (!$webhookEnabled && !$sfEnabled) continue;

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
    $dispatchId = live_dispatch_log_id($pdo, $turma, $manualTurmaId > 0);
    $cursor = max(0, (int)($turma['live_dispatch_cursor_user_id'] ?? 0));
    $stats = [
        'seen' => 0,
        'eligible' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'already_sent' => 0,
        'sf_ok' => 0,
        'sf_fail' => 0,
        'manychat_ok' => 0,
        'manychat_fail' => 0,
    ];

    // ----------------------------------------------------------------------------------
    // 3) Busca alunos da turma
    // ----------------------------------------------------------------------------------
    if ($tagIds && !$tagRelTable) {
        live_dispatch_update_log($pdo, $dispatchId, $stats, 'concluido', 'Disparo concluido sem destinatarios: filtro de tags sem tabela de relacao.');
        $pdo->prepare("UPDATE turmas SET live_disparada = 1, live_dispatch_finished_at = NOW(), live_dispatch_cursor_user_id = 0 WHERE id = :id")
            ->execute([':id' => $turma['id']]);
        continue;
    }

    if ($tagIds && $tagRelTable) {
        // tenta colunas padrão: user_id + tag_id
        // (se seu rel tiver nomes diferentes, eu ajusto quando você mandar o SQL do rel)
        $in = implode(',', array_fill(0, count($tagIds), '?'));

        $sql = "
            SELECT u.*
              FROM users u
              JOIN `$tagRelTable` ut ON ut.user_id = u.id
             WHERE u.codigo_turma = ?
               AND u.id > ?
               AND ut.tag_id IN ($in)
          GROUP BY u.id
          ORDER BY u.id ASC
             LIMIT {$maxStudentsPerRun}
        ";

        $params = array_merge([$codigo, $cursor], $tagIds);

        $stU = $pdo->prepare($sql);
        $stU->execute($params);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stU = $pdo->prepare("SELECT * FROM users WHERE codigo_turma = :c AND id > :cursor ORDER BY id ASC LIMIT {$maxStudentsPerRun}");
        $stU->execute([':c' => $codigo, ':cursor' => $cursor]);
        $alunos = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($cursor === 0) {
        try {
            $pdo->prepare("UPDATE turmas SET live_dispatch_started_at = COALESCE(live_dispatch_started_at, NOW()) WHERE id = :id")
                ->execute([':id' => $turma['id']]);
        } catch (Throwable $e) {}
    }

    if (!$alunos) {
        live_dispatch_update_log($pdo, $dispatchId, $stats, 'concluido', 'Disparo concluido. Nenhum aluno pendente neste lote.');
        $pdo->prepare("UPDATE turmas SET live_disparada = 1, live_dispatch_finished_at = NOW(), live_dispatch_cursor_user_id = 0 WHERE id = :id")
            ->execute([':id' => $turma['id']]);
        if ($manualTurmaId > 0) {
            $GLOBALS['manual_live_turma_result'] = [
                'ok' => true,
                'message' => 'Disparo manual concluido.',
                'stats' => ['elegiveis' => 0, 'sent' => 0, 'pending' => 0, 'processing' => 0, 'retryable_failed' => 0, 'failed' => 0],
            ];
        }
        continue;
    }

    // ----------------------------------------------------------------------------------
    // 4) Dispara para cada aluno + loga em webhook_logs
    // ----------------------------------------------------------------------------------
    $lastProcessedUserId = $cursor;
    $stoppedByBudget = false;
    foreach ($alunos as $aluno) {
        $elapsedGlobal = microtime(true) - $startedAt;
        $elapsedTurma = microtime(true) - $turmaStartedAt;
        if ($elapsedGlobal >= $maxRuntimeSeconds || $elapsedTurma >= $maxTurmaRuntimeSeconds) {
            $stoppedByBudget = true;
            break;
        }

        // Calcula andamento (0..100) para enviar no payload
$uid = (int)($aluno['id'] ?? 0);
$lastProcessedUserId = max($lastProcessedUserId, $uid);
$stats['seen']++;
$prog = calc_andamento($pdo, $uid, (int)$totalObrigatoriasGlobal);
$aluno['andamento']        = $prog['andamento'];
$aluno['aulas_concluidas'] = $prog['concluidas'];
$aluno['aulas_totais']     = $prog['total'];
if (live_dispatch_recipient_done($pdo, $dispatchId, $uid)) { $stats['already_sent']++; live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }
if ($excludeZero && (int)$aluno['andamento'] <= 0) { $stats['skipped']++; live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, 'skipped', 'andamento_zero'); live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }
if ($excludeTagIds && user_has_any_tag($pdo, $tagRelTable, $uid, $excludeTagIds)) { $stats['skipped']++; live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, 'skipped', 'tag_excluida'); live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }
if ($excludeCert && user_has_certificate($pdo, $uid)) { $stats['skipped']++; live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, 'skipped', 'certificado_emitido'); live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }
if ($excludePurchase && user_has_purchase($pdo, $uid)) { $stats['skipped']++; live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, 'skipped', 'compra_identificada'); live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }
if ($excludeRescheduled && user_has_active_live_reschedule($pdo, $uid, (string)($turma['data_live'] ?? ''))) { $stats['skipped']++; live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, 'skipped', 'live_reagendada'); live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }
if ($webhookEnabled && live_already_logged($pdo, $uid, $codigo, (string)($turma['live_disparo_data'] ?? ''))) { $stats['already_sent']++; live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, 'sent', 'ja_constava_em_webhook_logs'); live_dispatch_advance_cursor($pdo, $turma, $uid); continue; }

$payload = build_live_payload($turma, $aluno);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stats['eligible']++;

        $sentOk = false;
        $errors = [];
        $status = null;
        $resp = '';
        $err = '';

        if ($webhookEnabled) {
            [$status, $resp, $err] = post_json($url, $json ?: '{}', 15);
            $webhookOk = ($err === '' && $status >= 200 && $status < 400);
            if ($webhookOk) {
                $stats['sent']++;
                $sentOk = true;
            } else {
                $stats['failed']++;
                $errors[] = $err ?: ('Webhook HTTP ' . $status);
            }
        }

        if ($sfEnabled && function_exists('sf_disparar_evento')) {
            $sfUser = [
                'id' => $aluno['id'] ?? null,
                'nome' => $aluno['nome'] ?? null,
                'email' => $aluno['email'] ?? null,
                'telefone' => $aluno['telefone'] ?? null,
            ];
            $sfOk = sf_disparar_evento($pdo, 'LIVE_TURMA', $sfUser, live_payload_extra($turma, $aluno, $payload));
            if ($sfOk) {
                $stats['sf_ok']++;
                $sentOk = true;
            } else {
                $stats['sf_fail']++;
                $errors[] = 'SuperFuncionario sem envio confirmado';
            }
        }

        live_dispatch_recipient($pdo, $dispatchId, $turma, $aluno, $sentOk ? 'sent' : 'failed', $sentOk ? null : implode('; ', $errors), $json ?: '{}');
        live_dispatch_advance_cursor($pdo, $turma, $uid);

        // log no webhook_logs (se tabela existir)
        try {
            if ($webhookEnabled && table_exists($pdo, 'webhook_logs')) {
                $log = $pdo->prepare("
                    INSERT INTO webhook_logs
                        (webhook_id, user_id, evento, payload_json, response_status, response_body, error_message, created_at)
                    VALUES
                        (NULL, :u, :e, :p, :s, :b, :er, NOW())
                ");
                $log->execute([
                    ':u'  => $aluno['id'] ?? null,
                    ':e'  => 'LIVE_TURMA_' . $codigo,
                    ':p'  => $json,
                    ':s'  => $status ?: null,
                    ':b'  => $resp,
                    ':er' => $err ?: null,
                ]);
            }
        } catch (Throwable $e) {
            // falha no log não impede envio
        }

        if ($delay > 0) {
            usleep($delay * 1000); // ms -> µs
        }
    }

    // ----------------------------------------------------------------------------------
    // 5) Marca turma como disparada (mesmo sem alunos elegíveis)
    // ----------------------------------------------------------------------------------
    $completed = !$stoppedByBudget && count($alunos) < $maxStudentsPerRun;
    if ($completed) {
        $upd = $pdo->prepare("UPDATE turmas SET live_disparada = 1, live_dispatch_cursor_user_id = 0, live_dispatch_finished_at = NOW() WHERE id = :id");
        $upd->execute([':id' => $turma['id']]);
        live_dispatch_update_log($pdo, $dispatchId, $stats, 'concluido', 'Disparo concluido em lotes.');
    } else {
        $upd = $pdo->prepare("UPDATE turmas SET live_dispatch_cursor_user_id = :cursor WHERE id = :id");
        $upd->execute([':cursor' => $lastProcessedUserId, ':id' => $turma['id']]);
        live_dispatch_update_log($pdo, $dispatchId, $stats, 'processando', 'Lote processado parcialmente; proximo cron continua do ultimo aluno.');
    }

    if ($manualTurmaId > 0) {
        $GLOBALS['manual_live_turma_result'] = [
            'ok' => true,
            'message' => $completed ? 'Disparo manual concluido.' : 'Disparo manual iniciou em lotes; o cron continuara automaticamente.',
            'stats' => [
                'elegiveis' => $stats['eligible'] + $stats['already_sent'],
                'sent' => $stats['sent'] + $stats['already_sent'],
                'pending' => $completed ? 0 : 1,
                'processing' => $completed ? 0 : 1,
                'retryable_failed' => 0,
                'failed' => $stats['failed'],
            ],
        ];
        break;
    }

    if ((microtime(true) - $startedAt) >= $maxRuntimeSeconds) break;
}
