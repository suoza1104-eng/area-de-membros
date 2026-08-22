<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();
course_access_ensure_schema($pdo);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}

function dt_local_value(?string $dbValue): string {
    if (!$dbValue) return '';
    $ts = strtotime($dbValue);
    if (!$ts) return '';
    return date('Y-m-d\TH:i', $ts);
}

function dt_br_short(?string $dbValue): string {
    if (!$dbValue) return '—';
    $ts = strtotime($dbValue);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function sort_ts(?string $dbValue): int {
    if (!$dbValue) return 0;
    $ts = strtotime($dbValue);
    return $ts ? (int)$ts : 0;
}

function carregar_status_disparos_live(PDO $pdo): array {
    $status = [];
    try {
        $st = $pdo->query("
            SELECT l.id AS dispatch_id,
                   l.turma_id,
                   l.status,
                   l.started_at,
                   l.finished_at,
                   COALESCE(SUM(r.status IN ('pending','processing','sent','failed')), 0) AS elegiveis,
                   COALESCE(SUM(r.status = 'sent'), 0) AS enviados,
                   COALESCE(SUM(r.status IN ('pending','processing') OR (r.status = 'failed' AND r.attempts < 3)), 0) AS faltam,
                   COALESCE(SUM(r.status = 'failed' AND r.attempts >= 3), 0) AS erros
              FROM live_turma_dispatch_logs l
              JOIN (
                    SELECT turma_id, MAX(id) AS dispatch_id
                      FROM live_turma_dispatch_logs
                     WHERE turma_id IS NOT NULL
                  GROUP BY turma_id
              ) ultimo ON ultimo.dispatch_id = l.id
         LEFT JOIN live_turma_dispatch_recipients r ON r.dispatch_id = l.id
          GROUP BY l.id, l.turma_id, l.status, l.started_at, l.finished_at
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $turmaId = (int)($row['turma_id'] ?? 0);
            if ($turmaId <= 0) continue;
            $status[$turmaId] = [
                'dispatch_id' => (int)($row['dispatch_id'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'started_at' => (string)($row['started_at'] ?? ''),
                'finished_at' => (string)($row['finished_at'] ?? ''),
                'elegiveis' => (int)($row['elegiveis'] ?? 0),
                'enviados' => (int)($row['enviados'] ?? 0),
                'faltam' => (int)($row['faltam'] ?? 0),
                'erros' => (int)($row['erros'] ?? 0),
            ];
        }
    } catch (Throwable $e) {}
    return $status;
}

function live_diag_dt(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i:s', $ts) : (string)$dt;
}

function live_diag_seconds(?string $start, ?string $end = null): int {
    if (!$start) return 0;
    $a = strtotime($start);
    $b = $end ? strtotime($end) : time();
    if (!$a || !$b || $b < $a) return 0;
    return max(0, $b - $a);
}

function live_diag_duration(int $seconds): string {
    if ($seconds <= 0) return '0s';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) return sprintf('%dh %02dm %02ds', $h, $m, $s);
    if ($m > 0) return sprintf('%dm %02ds', $m, $s);
    return $s . 's';
}

function live_disparo_diagnostico(PDO $pdo, int $turmaId): array {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
    $st->execute([':id' => $turmaId]);
    $turma = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$turma) return ['ok' => false, 'message' => 'Turma nao encontrada.'];

    $codigo = (string)($turma['codigo'] ?? '');
    $dispatch = null;
    try {
        $st = $pdo->prepare("SELECT * FROM live_turma_dispatch_logs WHERE turma_id = :id ORDER BY id DESC LIMIT 1");
        $st->execute([':id' => $turmaId]);
        $dispatch = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    $dispatchId = (int)($dispatch['id'] ?? 0);
    $recipientStats = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0, 'processing' => 0];
    $skipStats = [];
    $recent = [];
    $slow = [];
    $lastRecipientAt = '';
    $maxProcessedUserId = 0;
    if ($dispatchId > 0) {
        try {
            $rs = $pdo->prepare("SELECT status, COUNT(*) qtd FROM live_turma_dispatch_recipients WHERE dispatch_id = :id GROUP BY status");
            $rs->execute([':id' => $dispatchId]);
            foreach ($rs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $recipientStats[(string)$row['status']] = (int)$row['qtd'];
            }
            $ss = $pdo->prepare("SELECT COALESCE(skip_reason, '') reason, COUNT(*) qtd FROM live_turma_dispatch_recipients WHERE dispatch_id = :id AND status = 'skipped' GROUP BY COALESCE(skip_reason, '') ORDER BY qtd DESC");
            $ss->execute([':id' => $dispatchId]);
            foreach ($ss->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $skipStats[] = ['reason' => (string)$row['reason'], 'total' => (int)$row['qtd']];
            }
            $maxProcessedUserId = (int)$pdo->query("SELECT COALESCE(MAX(user_id),0) FROM live_turma_dispatch_recipients WHERE dispatch_id = " . $dispatchId . " AND status IN ('sent','skipped','failed')")->fetchColumn();
            $lastRecipientAt = (string)$pdo->query("SELECT COALESCE(MAX(updated_at),'') FROM live_turma_dispatch_recipients WHERE dispatch_id = " . $dispatchId)->fetchColumn();
            $rr = $pdo->prepare("
                SELECT user_id, nome, email, telefone, status, skip_reason, attempts,
                       webhook_ok, webhook_fail, sf_ok, sf_fail, manychat_ok, manychat_fail,
                       error_message, started_at, finished_at, updated_at,
                       TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) DIV 1000 AS duration_ms
                  FROM live_turma_dispatch_recipients
                 WHERE dispatch_id = :id
              ORDER BY updated_at DESC, id DESC
                 LIMIT 60
            ");
            $rr->execute([':id' => $dispatchId]);
            $recent = $rr->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $sr = $pdo->prepare("
                SELECT user_id, nome, email, telefone, status, skip_reason, attempts,
                       error_message, started_at, finished_at, updated_at,
                       TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) DIV 1000 AS duration_ms
                  FROM live_turma_dispatch_recipients
                 WHERE dispatch_id = :id
                   AND started_at IS NOT NULL
                   AND finished_at IS NOT NULL
              ORDER BY duration_ms DESC
                 LIMIT 10
            ");
            $sr->execute([':id' => $dispatchId]);
            $slow = $sr->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }

    $totalAlunos = 0;
    $remainingAfterCursor = 0;
    $nextUserId = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE codigo_turma = :codigo");
        $st->execute([':codigo' => $codigo]);
        $totalAlunos = (int)$st->fetchColumn();
        $cursor = max((int)($turma['live_dispatch_cursor_user_id'] ?? 0), $maxProcessedUserId);
        $st = $pdo->prepare("SELECT COUNT(*) total, COALESCE(MIN(id),0) next_user FROM users WHERE codigo_turma = :codigo AND id > :cursor");
        $st->execute([':codigo' => $codigo, ':cursor' => $cursor]);
        $rem = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $remainingAfterCursor = (int)($rem['total'] ?? 0);
        $nextUserId = (int)($rem['next_user'] ?? 0);
    } catch (Throwable $e) {
        $cursor = (int)($turma['live_dispatch_cursor_user_id'] ?? 0);
    }

    $cronTask = [];
    $cronRuns = [];
    try {
        $cronTask = $pdo->query("SELECT task_key, enabled, mode, timeout_seconds, next_run_at, running_until, running_token IS NOT NULL AS has_token, last_attempt_at, last_started_at, last_finished_at, last_success_at, last_status, last_duration_ms, last_message FROM cron_managed_tasks WHERE task_key='lives_turma' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $cronRuns = $pdo->query("SELECT id, source, trigger_type, status, started_at, finished_at, duration_ms, LEFT(COALESCE(output_text,''), 600) output_text, error_message FROM cron_managed_runs WHERE task_key='lives_turma' ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $status = (string)($dispatch['status'] ?? '');
    $active = in_array($status, ['queued', 'iniciado', 'processando'], true);
    $startedAt = (string)($dispatch['started_at'] ?? '');
    $finishedAt = (string)($dispatch['finished_at'] ?? '');
    $elapsed = live_diag_seconds($startedAt, $finishedAt ?: null);
    $processed = array_sum($recipientStats);
    $sent = (int)$recipientStats['sent'];
    $fail = (int)$recipientStats['failed'];
    $skipped = (int)$recipientStats['skipped'];
    $rate = $elapsed > 0 ? $processed / max(1, $elapsed) : 0.0;
    $etaSeconds = ($rate > 0 && $remainingAfterCursor > 0) ? (int)ceil($remainingAfterCursor / $rate) : 0;
    $heartbeatRaw = (string)($dispatch['last_heartbeat_at'] ?? '');
    if ($lastRecipientAt !== '' && (!$heartbeatRaw || strtotime($lastRecipientAt) > strtotime($heartbeatRaw))) $heartbeatRaw = $lastRecipientAt;
    $heartbeatAge = $heartbeatRaw ? max(0, time() - (int)strtotime($heartbeatRaw)) : 0;
    $locked = !empty($cronTask['has_token']);
    $stale = $active && $locked && $heartbeatAge > 180;

    return [
        'ok' => true,
        'turma' => [
            'id' => $turmaId,
            'codigo' => $codigo,
            'data_live' => live_diag_dt((string)($turma['data_live'] ?? '')),
            'live_disparo_data' => live_diag_dt((string)($turma['live_disparo_data'] ?? '')),
            'live_disparada' => (int)($turma['live_disparada'] ?? 0),
            'total_alunos' => $totalAlunos,
            'cursor' => (int)($cursor ?? 0),
            'next_user_id' => $nextUserId,
        ],
        'dispatch' => [
            'id' => $dispatchId,
            'status' => $status ?: 'sem_disparo',
            'active' => $active,
            'started_at' => live_diag_dt($startedAt),
            'finished_at' => live_diag_dt($finishedAt),
            'last_heartbeat_at' => live_diag_dt($heartbeatRaw),
            'heartbeat_age_seconds' => $heartbeatAge,
            'heartbeat_age' => live_diag_duration($heartbeatAge),
            'elapsed_seconds' => $elapsed,
            'elapsed' => live_diag_duration($elapsed),
            'eta_seconds' => $etaSeconds,
            'eta' => $etaSeconds > 0 ? live_diag_duration($etaSeconds) : '',
            'eta_at' => $etaSeconds > 0 ? date('d/m/Y H:i:s', time() + $etaSeconds) : '',
            'batch_runs' => (int)($dispatch['batch_runs'] ?? 0),
            'message' => (string)($dispatch['message'] ?? ''),
            'total_alunos_lidos' => (int)($dispatch['total_alunos'] ?? 0),
            'elegiveis_log' => (int)($dispatch['elegiveis'] ?? 0),
            'sf_ok' => (int)($dispatch['sf_ok'] ?? 0),
            'sf_fail' => (int)($dispatch['sf_fail'] ?? 0),
            'webhook_ok' => (int)($dispatch['webhook_ok'] ?? 0),
            'webhook_fail' => (int)($dispatch['webhook_fail'] ?? 0),
            'manychat_ok' => (int)($dispatch['manychat_ok'] ?? 0),
            'manychat_fail' => (int)($dispatch['manychat_fail'] ?? 0),
        ],
        'progress' => [
            'processed' => $processed,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $fail,
            'pending' => (int)$recipientStats['pending'] + (int)$recipientStats['processing'],
            'remaining_after_cursor' => $remainingAfterCursor,
            'percent' => $totalAlunos > 0 ? round(($processed / $totalAlunos) * 100, 2) : 0,
            'rate_per_minute' => round($rate * 60, 2),
            'skip_reasons' => $skipStats,
        ],
        'cron' => [
            'task' => $cronTask,
            'locked' => $locked,
            'stale' => $stale,
            'runs' => $cronRuns,
        ],
        'recent_recipients' => $recent,
        'slowest_recipients' => $slow,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['acao'] ?? '') === 'status_disparos_live') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['ok' => true, 'status' => carregar_status_disparos_live($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['acao'] ?? '') === 'diagnostico_disparo_live') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(live_disparo_diagnostico($pdo, (int)($_GET['turma_id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['acao'] ?? '') === 'disparar_live_turma_manual') {
    $turmaId = (int)($_POST['turma_id'] ?? 0);
    $confirmarRedisparo = (string)($_POST['confirmar_redisparo'] ?? '') === '1';
    if ($turmaId <= 0) {
        header('Location: turmas.php?err=' . urlencode('Turma invalida para disparo manual.'));
        exit;
    }

    try {
        $stTurmaDisparo = $pdo->prepare("SELECT codigo, data_live, live_disparada FROM turmas WHERE id = :id LIMIT 1");
        $stTurmaDisparo->execute([':id' => $turmaId]);
        $turmaDisparo = $stTurmaDisparo->fetch(PDO::FETCH_ASSOC);
        if (!$turmaDisparo) {
            header('Location: turmas.php?err=' . urlencode('Turma nao encontrada para disparo manual.'));
            exit;
        }

        $liveTsDisparo = sort_ts($turmaDisparo['data_live'] ?? null);
        if ($liveTsDisparo <= time()) {
            header('Location: turmas.php?err=' . urlencode('A data da live ja encerrou ou nao foi definida.'));
            exit;
        }

        $jaDisparada = (int)($turmaDisparo['live_disparada'] ?? 0) === 1;
        if ($jaDisparada && !$confirmarRedisparo) {
            header('Location: turmas.php?err=' . urlencode('Este aviso ja foi disparado. Confirme explicitamente para enviar novamente.'));
            exit;
        }

        try {
            $stFilaAtiva = $pdo->prepare("
                SELECT 1
                  FROM live_turma_dispatch_logs
                 WHERE turma_id = :id
                   AND status IN ('queued', 'iniciado', 'processando')
                 LIMIT 1
            ");
            $stFilaAtiva->execute([':id' => $turmaId]);
            if ($stFilaAtiva->fetchColumn()) {
                header('Location: turmas.php?err=' . urlencode('Ja existe um disparo desta turma em andamento. Aguarde a conclusao antes de tentar novamente.'));
                exit;
            }
        } catch (Throwable $e) {}

        $GLOBALS['manual_live_turma_id'] = $turmaId;
        ob_start();
        require __DIR__ . '/../cron/processar_lives.php';
        ob_end_clean();
        $resultado = $GLOBALS['manual_live_turma_result'] ?? null;
        if (!is_array($resultado) || empty($resultado['ok'])) {
            $motivo = is_array($resultado) ? (string)($resultado['message'] ?? '') : '';
            if ($motivo === '') $motivo = 'O disparo manual nao foi confirmado.';
            header('Location: turmas.php?err=' . urlencode($motivo));
            exit;
        }

        $stats = is_array($resultado['stats'] ?? null) ? $resultado['stats'] : [];
        $pendentes = (int)($stats['pending'] ?? 0) + (int)($stats['processing'] ?? 0) + (int)($stats['retryable_failed'] ?? 0);
        $resumo = sprintf(
            '%s Elegiveis: %d; enviados: %d; pendentes: %d; falhas finais: %d.',
            (string)($resultado['message'] ?? 'Disparo manual enfileirado.'),
            (int)($stats['elegiveis'] ?? 0),
            (int)($stats['sent'] ?? 0),
            $pendentes,
            (int)($stats['failed'] ?? 0)
        );
        header('Location: turmas.php?ok=' . urlencode($resumo));
        exit;
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        header('Location: turmas.php?err=' . urlencode('Erro no disparo manual: ' . $e->getMessage()));
        exit;
    }
}

// ===================== CLONE (pré-preenche formulário) =====================
$cloneFill = null;
if (isset($_GET['clone_fill'])) {
    $srcId = (int)$_GET['clone_fill'];
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
    $st->execute([':id' => $srcId]);
    $src = $st->fetch(PDO::FETCH_ASSOC);
    if ($src) {
        $baseCodigo = preg_replace('/_COPIA(_\d+)?$/', '', (string)$src['codigo']);
        $newCodigo  = $baseCodigo . '_COPIA';
        $suffix = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT id FROM turmas WHERE codigo = :c LIMIT 1");
            $chk->execute([':c' => $newCodigo]);
            if (!$chk->fetchColumn()) break;
            $newCodigo = $baseCodigo . '_COPIA_' . (++$suffix);
        }
        $cloneFill = $src;
        $cloneFill['codigo'] = $newCodigo;
        $cloneFill['id']     = 0; // força criação nova
    }
}

// ===================== SAVE =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $codigo      = trim((string)($_POST['codigo'] ?? ''));
    $codigoLive  = trim((string)($_POST['codigo_live'] ?? ''));
    $codigoLive  = ($codigoLive === '') ? null : $codigoLive;
    $ji          = (string)($_POST['janela_inicio'] ?? '');
    $jf          = (string)($_POST['janela_fim'] ?? '');
    $dl          = (string)($_POST['data_live'] ?? '');
    $senhaCert   = trim((string)($_POST['senha_certificado'] ?? ''));
    $accessDeadlineEnabled = isset($_POST['access_deadline_enabled']) ? 1 : 0;
    $accessDeadlineDays = max(1, min(3650, (int)($_POST['access_deadline_days'] ?? 30)));
    $accessDeadlineStart = (string)($_POST['access_deadline_start'] ?? 'cadastro');
    if (!in_array($accessDeadlineStart, ['cadastro', 'live'], true)) $accessDeadlineStart = 'cadastro';
    $accessCountdownEnabled = isset($_POST['access_countdown_enabled']) ? 1 : 0;
    $lifetimeCheckoutUrl = trim((string)($_POST['lifetime_checkout_url'] ?? ''));
    $lifetimeOfferCodes = implode(',', course_access_offer_codes((string)($_POST['lifetime_offer_codes'] ?? '')));
    $accessExpiredMessage = trim((string)($_POST['access_expired_message'] ?? ''));

    $jiDb = $ji ? date('Y-m-d H:i:s', strtotime($ji)) : null;
    $jfDb = $jf ? date('Y-m-d H:i:s', strtotime($jf)) : null;
    $dlDb = $dl ? date('Y-m-d H:i:s', strtotime($dl)) : null;

    if ($codigo === '' || !$jiDb || !$jfDb) {
        header('Location: turmas.php'); exit;
    }

    $hasCodigoLive   = col_exists($pdo, 'turmas', 'codigo_live');
    $hasCreatedAt    = col_exists($pdo, 'turmas', 'created_at');
    $hasLiveDisp     = col_exists($pdo, 'turmas', 'live_disparada');
    $hasSenhaCert    = col_exists($pdo, 'turmas', 'senha_certificado');

    // Migration: cria coluna senha_certificado se não existir
    if (!$hasSenhaCert) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN senha_certificado VARCHAR(255) NOT NULL DEFAULT ''"); $hasSenhaCert = true; } catch (Throwable $e) {}
    }

    if ($id > 0) {
        $set = []; $params = [':id' => $id];
        $set[] = "codigo = :c";         $params[':c']  = $codigo;
        $set[] = "janela_inicio = :ji"; $params[':ji'] = $jiDb;
        $set[] = "janela_fim = :jf";    $params[':jf'] = $jfDb;
        $set[] = "data_live = :dl";     $params[':dl'] = $dlDb;
        if ($hasCodigoLive) { $set[] = "codigo_live = :cl"; $params[':cl'] = $codigoLive; }
        if ($hasSenhaCert)  { $set[] = "senha_certificado = :sc"; $params[':sc'] = $senhaCert; }
        $set[] = "access_deadline_enabled = :ade"; $params[':ade'] = $accessDeadlineEnabled;
        $set[] = "access_deadline_days = :add"; $params[':add'] = $accessDeadlineDays;
        $set[] = "access_deadline_start = :ads"; $params[':ads'] = $accessDeadlineStart;
        $set[] = "access_countdown_enabled = :ace"; $params[':ace'] = $accessCountdownEnabled;
        $set[] = "lifetime_checkout_url = :lcu"; $params[':lcu'] = $lifetimeCheckoutUrl ?: null;
        $set[] = "lifetime_offer_codes = :loc"; $params[':loc'] = $lifetimeOfferCodes ?: null;
        $set[] = "access_expired_message = :aem"; $params[':aem'] = $accessExpiredMessage ?: null;

        try {
            $pdo->prepare("UPDATE turmas SET " . implode(", ", $set) . " WHERE id = :id")->execute($params);
        } catch (Throwable $e) {
            $msg = strpos((string)$e->getMessage(), '1062') !== false ? 'Código já existe em outra turma.' : 'Erro ao salvar turma.';
            header('Location: turmas.php?err=' . urlencode($msg)); exit;
        }
    } else {
        $cols = ["codigo", "janela_inicio", "janela_fim", "data_live"];
        $vals = [":c", ":ji", ":jf", ":dl"];
        $params = [':c'=>$codigo, ':ji'=>$jiDb, ':jf'=>$jfDb, ':dl'=>$dlDb];
        if ($hasCodigoLive) { $cols[] = "codigo_live"; $vals[] = ":cl"; $params[':cl'] = $codigoLive; }
        if ($hasSenhaCert)  { $cols[] = "senha_certificado"; $vals[] = ":sc"; $params[':sc'] = $senhaCert; }
        $cols[] = "access_deadline_enabled"; $vals[] = ":ade"; $params[':ade'] = $accessDeadlineEnabled;
        $cols[] = "access_deadline_days"; $vals[] = ":add"; $params[':add'] = $accessDeadlineDays;
        $cols[] = "access_deadline_start"; $vals[] = ":ads"; $params[':ads'] = $accessDeadlineStart;
        $cols[] = "access_countdown_enabled"; $vals[] = ":ace"; $params[':ace'] = $accessCountdownEnabled;
        $cols[] = "lifetime_checkout_url"; $vals[] = ":lcu"; $params[':lcu'] = $lifetimeCheckoutUrl ?: null;
        $cols[] = "lifetime_offer_codes"; $vals[] = ":loc"; $params[':loc'] = $lifetimeOfferCodes ?: null;
        $cols[] = "access_expired_message"; $vals[] = ":aem"; $params[':aem'] = $accessExpiredMessage ?: null;
        if ($hasCreatedAt)  { $cols[] = "created_at"; $vals[] = "NOW()"; }
        if ($hasLiveDisp)   { $cols[] = "live_disparada"; $vals[] = "0"; }

        try {
            $pdo->prepare("INSERT INTO turmas (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")")->execute($params);
        } catch (Throwable $e) {
            $msg = strpos((string)$e->getMessage(), '1062') !== false ? 'Código já existe em outra turma.' : 'Erro ao salvar turma.';
            header('Location: turmas.php?err=' . urlencode($msg)); exit;
        }
    }

    // Propaga data_live para alunos da turma
    if ($codigo !== '') {
        try {
            if (col_exists($pdo, 'users', 'data_live'))     $pdo->prepare("UPDATE users SET data_live = :dl WHERE codigo_turma = :c")->execute([':dl'=>$dlDb,':c'=>$codigo]);
            if (col_exists($pdo, 'users', 'turma_live_at')) $pdo->prepare("UPDATE users SET turma_live_at = :dl WHERE codigo_turma = :c")->execute([':dl'=>$dlDb,':c'=>$codigo]);
        } catch (Throwable $e) {}
    }

    header('Location: turmas.php'); exit;
}

if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM turmas WHERE id = :id")->execute([':id' => (int)$_GET['del']]);
    header('Location: turmas.php'); exit;
}

if (isset($_GET['reset_disparo'])) {
    $id = (int)$_GET['reset_disparo'];
    try { $pdo->prepare("UPDATE turmas SET live_disparada = 0 WHERE id = :id")->execute([':id' => $id]); } catch (Throwable $e) {}
    header('Location: turmas.php'); exit;
}

// ===================== LOAD =====================
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id");
    $st->execute([':id' => (int)$_GET['edit']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Clone pré-preenche como nova turma
if ($cloneFill) $edit = $cloneFill;

$turmas = $pdo->query("SELECT t.*,(SELECT COUNT(*) FROM users u WHERE u.codigo_turma=t.codigo) AS total_alunos FROM turmas t ORDER BY t.janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ultimosDisparosLive = [];
try {
    $stUltimosDisparos = $pdo->query("
        SELECT turma_id,
               MAX(CASE
                   WHEN status NOT IN ('queued', 'iniciado', 'processando')
                   THEN COALESCE(finished_at, started_at)
                   ELSE NULL
               END) AS disparado_em
          FROM live_turma_dispatch_logs
         GROUP BY turma_id
    ");
    foreach ($stUltimosDisparos->fetchAll(PDO::FETCH_ASSOC) ?: [] as $disparo) {
        $turmaDisparoId = (int)$disparo['turma_id'];
        $ultimosDisparosLive[$turmaDisparoId] = (string)($disparo['disparado_em'] ?? '');
    }
} catch (Throwable $e) {}
$statusDisparosLive = carregar_status_disparos_live($pdo);

$menu = 'turmas';
include __DIR__ . '/_header.php';
?>
<style>
.page-turmas { width: 100%; max-width: 1600px; min-width: 0; margin: 0 auto; }
.page-turmas .card { min-width: 0; }
.section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin: 18px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
.grid2t { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.grid3t { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
@media (max-width: 780px) { .grid2t, .grid3t { grid-template-columns: 1fr; } }
.field-lbl { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; font-weight: 500; }
.btn-sm { min-height: 26px; font-size: 11px; line-height: 1.2; padding: 4px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; }
.btn-sm:hover { background: rgba(255,255,255,.08); }
.btn-danger-sm { border-color: rgba(239,68,68,.3); color: #ef4444; }
.btn-danger-sm:hover { background: rgba(239,68,68,.12); }
.badge-ok   { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(34,197,94,.12); color:#4ade80; border:1px solid rgba(34,197,94,.25); }
.badge-off  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(255,255,255,.06); color:var(--muted); border:1px solid var(--border); }
.badge-warn { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(251,191,36,.12); color:#fbbf24; border:1px solid rgba(251,191,36,.25); }
.badge-error { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(239,68,68,.12); color:#fca5a5; border:1px solid rgba(239,68,68,.25); }
.live-dispatch-summary { min-width:180px; }
.live-dispatch-counts { display:flex; flex-wrap:wrap; gap:2px 10px; margin-top:5px; color:var(--muted); font-size:10px; line-height:1.45; }
.live-dispatch-counts strong { color:var(--text); font-weight:700; }
.live-status-button { appearance:none; cursor:pointer; font:inherit; font-weight:700; }
.live-status-button:hover { filter:brightness(1.12); }
.live-diag-modal { position:fixed; inset:0; z-index:1000; display:none; background:rgba(2,6,23,.74); backdrop-filter: blur(8px); }
.live-diag-modal.open { display:flex; align-items:stretch; justify-content:flex-end; }
.live-diag-panel { width:min(1120px, 100vw); height:100vh; overflow:auto; background:var(--bg-card); border-left:1px solid var(--border); box-shadow:-20px 0 60px rgba(0,0,0,.35); padding:18px; }
.live-diag-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:14px; }
.live-diag-title { margin:0; font-size:18px; }
.live-diag-sub { color:var(--muted); font-size:12px; margin-top:4px; }
.live-diag-close { min-width:34px; min-height:34px; border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,.04); color:var(--text); cursor:pointer; font-size:20px; line-height:1; }
.live-diag-kpis { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:10px; margin-bottom:14px; }
.live-diag-card { border:1px solid var(--border); border-radius:8px; padding:10px; background:rgba(255,255,255,.025); min-width:0; }
.live-diag-card span { display:block; color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px; }
.live-diag-card strong { display:block; font-size:18px; color:var(--text); overflow-wrap:anywhere; }
.live-diag-card small { display:block; color:var(--muted); font-size:11px; margin-top:4px; overflow-wrap:anywhere; }
.live-diag-progress { height:10px; border-radius:999px; background:rgba(255,255,255,.08); overflow:hidden; margin:8px 0 14px; border:1px solid var(--border); }
.live-diag-progress > div { height:100%; width:0%; background:#22c55e; transition:width .25s ease; }
.live-diag-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.live-diag-section { border:1px solid var(--border); border-radius:8px; padding:12px; background:rgba(255,255,255,.02); min-width:0; }
.live-diag-section h4 { margin:0 0 10px; font-size:13px; }
.live-diag-list { display:grid; gap:7px; font-size:12px; }
.live-diag-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; border-bottom:1px solid rgba(255,255,255,.06); padding-bottom:7px; }
.live-diag-row:last-child { border-bottom:0; padding-bottom:0; }
.live-diag-row span { color:var(--muted); }
.live-diag-row strong { text-align:right; overflow-wrap:anywhere; }
.live-diag-table-wrap { overflow:auto; border:1px solid var(--border); border-radius:8px; }
.live-diag-table { width:100%; border-collapse:collapse; font-size:11px; }
.live-diag-table th, .live-diag-table td { padding:7px 8px; border-bottom:1px solid rgba(255,255,255,.06); text-align:left; vertical-align:top; white-space:nowrap; }
.live-diag-table th { color:var(--muted); font-weight:700; background:rgba(255,255,255,.03); position:sticky; top:0; }
.live-diag-alert { border:1px solid rgba(239,68,68,.35); background:rgba(239,68,68,.09); color:#fecaca; padding:10px; border-radius:8px; margin-bottom:12px; font-size:12px; display:none; }
.live-diag-alert.show { display:block; }
@media (max-width: 980px) { .live-diag-kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); } .live-diag-grid { grid-template-columns:1fr; } }
.turmas-table-wrap { width: 100%; max-width:100%; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 4px; }
.table-turmas td, .table-turmas th { font-size: 12px; }
.table-turmas td { vertical-align: middle; }
.table-turmas th { user-select:none; }
.table-turmas .actions-head { width: 360px; }
.table-turmas .actions-cell { min-width: 330px; white-space: normal; }
.turma-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; max-width: 360px; }
.turma-actions form { display: inline-flex; }
.sort-head { appearance:none; border:0; background:transparent; color:inherit; font:inherit; font-weight:700; text-transform:inherit; letter-spacing:inherit; padding:0; cursor:pointer; display:inline-flex; align-items:center; gap:5px; }
.sort-head::after { content:"↕"; font-size:10px; color:var(--muted); opacity:.7; }
.sort-head.asc::after { content:"↑"; color:#facc15; opacity:1; }
.sort-head.desc::after { content:"↓"; color:#facc15; opacity:1; }
@media (max-width: 1750px) {
    .page-turmas { max-width: none; }
    .page-turmas .card { padding: 14px; }
    .turmas-table-wrap { overflow: visible; }
    .table-turmas,
    .table-turmas thead,
    .table-turmas tbody,
    .table-turmas th,
    .table-turmas td,
    .table-turmas tr { width: 100%; min-width: 0 !important; }
    .table-turmas,
    .table-turmas tbody { display: block; }
    .table-turmas thead { display: none; }
    .table-turmas tr {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0 12px;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 14px;
        margin-bottom: 10px;
        background: rgba(255,255,255,.02);
    }
    .table-turmas td {
        display: block;
        padding: 8px 0;
        border: 0;
        white-space: normal !important;
        overflow-wrap: anywhere;
    }
    .table-turmas td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 5px;
        color: var(--muted);
        font-size: 9.5px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .table-turmas .actions-cell {
        grid-column: 1 / -1;
        display: block;
        min-width: 0;
        margin-top: 4px;
        padding-top: 10px;
        border-top: 1px solid var(--border);
    }
    .table-turmas .actions-cell::before {
        display: block;
        margin-bottom: 8px;
    }
    .live-dispatch-summary { min-width: 0; }
    .turma-actions { max-width: none; align-items:stretch; }
    .turma-actions .btn-sm,
    .turma-actions form { flex: 1 1 120px; min-width:0; }
    .turma-actions form .btn-sm { width: 100%; }
}
@media (max-width: 800px) {
    .table-turmas tr { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .table-turmas .live-dispatch-summary { grid-column: 1 / -1; }
    .turma-actions .btn-sm,
    .turma-actions form { flex-basis: calc(50% - 6px); }
}
@media (max-width: 420px) {
    .page-turmas .card { padding: 12px; }
    .table-turmas tr { grid-template-columns: minmax(0, 1fr); padding: 9px 12px; }
    .table-turmas .live-dispatch-summary { grid-column: auto; }
    .turma-actions .btn-sm,
    .turma-actions form { flex-basis: 100%; }
}
</style>

<div class="page-turmas">

<?php if (isset($_GET['err']) && $_GET['err'] !== ''): ?>
    <div style="margin-bottom:12px;padding:10px 14px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:13px;">
        <?= h((string)$_GET['err']) ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['ok']) && $_GET['ok'] !== ''): ?>
    <div style="margin-bottom:12px;padding:10px 14px;border-radius:10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.25);color:#86efac;font-size:13px;">
        <?= h((string)$_GET['ok']) ?>
    </div>
<?php endif; ?>

<!-- ===== FORM ===== -->
<div class="card">
    <?php
    $isEdit  = $edit && (int)($edit['id'] ?? 0) > 0;
    $isClone = $cloneFill !== null;
    ?>
    <h4 style="margin:0 0 4px 0;">
        <?= $isClone ? 'Clonar turma — revise e salve' : ($isEdit ? 'Editar turma' : 'Nova turma') ?>
    </h4>
    <p style="margin:0 0 16px 0;font-size:12px;color:var(--muted);">
        <?= $isClone ? 'Dados pré-preenchidos da turma original. Ajuste o código e as datas antes de criar.' : 'Campos básicos da turma. Webhook e SF configuram-se nas páginas dedicadas.' ?>
    </p>

    <form method="post" id="form-turma">
        <input type="hidden" name="id" value="<?= $isEdit ? (int)$edit['id'] : 0 ?>">

        <!-- Identificação -->
        <p class="section-label">Identificação</p>
        <div class="grid2t">
            <label>
                <span class="field-lbl">Código da turma <span style="color:#ef4444">*</span></span>
                <input type="text" name="codigo" required value="<?= h($edit['codigo'] ?? '') ?>" placeholder="ex: TURMA_ABRIL_2025">
            </label>
            <label>
                <span class="field-lbl">Código da live <span style="color:var(--muted);font-weight:400">(slug opcional)</span></span>
                <input type="text" name="codigo_live" value="<?= h($edit['codigo_live'] ?? '') ?>" placeholder="ex: live-perfil-led-18dez">
            </label>
        </div>

        <!-- Certificado -->
        <p class="section-label">Certificado</p>
        <div style="max-width:480px;">
            <label>
                <span class="field-lbl">Senha do certificado desta turma</span>
                <input type="text" name="senha_certificado"
                    value="<?= h((string)($edit['senha_certificado'] ?? '')) ?>"
                    placeholder="Ex.: TURMA_ABRIL" autocomplete="off">
            </label>
            <div style="font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.6;">
                Usada quando o modo de senha está configurado como <strong>Variável</strong> em
                <a href="certificado_config.php" style="color:#facc15;">Configuração de Certificado</a>.
            </div>
        </div>

        <!-- Janelas e data -->
        <p class="section-label">Janela de Inscrição &amp; Data da Live</p>
        <div class="grid3t">
            <label>
                <span class="field-lbl">Janela início <span style="color:#ef4444">*</span></span>
                <input type="datetime-local" name="janela_inicio" required value="<?= h(dt_local_value($edit['janela_inicio'] ?? null)) ?>">
            </label>
            <label>
                <span class="field-lbl">Janela fim <span style="color:#ef4444">*</span></span>
                <input type="datetime-local" name="janela_fim" required value="<?= h(dt_local_value($edit['janela_fim'] ?? null)) ?>">
            </label>
            <label>
                <span class="field-lbl">Data/hora da live</span>
                <input type="datetime-local" name="data_live" value="<?= h(dt_local_value($edit['data_live'] ?? null)) ?>">
            </label>
        </div>

        <!-- Ações -->
        <p class="section-label">Prazo de acesso &amp; oferta vitalícia</p>
        <div class="grid2t">
            <div>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                    <input type="checkbox" name="access_deadline_enabled" value="1" <?= !empty($edit['access_deadline_enabled']) ? 'checked' : '' ?>>
                    <span class="field-lbl" style="margin:0;">Ativar prazo máximo de acesso nesta turma</span>
                </label>
                <div class="grid2t">
                    <label>
                        <span class="field-lbl">Prazo em dias</span>
                        <input type="number" name="access_deadline_days" min="1" max="3650" value="<?= (int)($edit['access_deadline_days'] ?? 30) ?>">
                    </label>
                    <label>
                        <span class="field-lbl">Iniciar contagem em</span>
                        <select name="access_deadline_start">
                            <option value="cadastro" <?= (($edit['access_deadline_start'] ?? 'cadastro') === 'cadastro') ? 'selected' : '' ?>>Inscrição na turma</option>
                            <option value="live" <?= (($edit['access_deadline_start'] ?? '') === 'live') ? 'selected' : '' ?>>Data da live</option>
                        </select>
                    </label>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                    <input type="checkbox" name="access_countdown_enabled" value="1" <?= !isset($edit['access_countdown_enabled']) || !empty($edit['access_countdown_enabled']) ? 'checked' : '' ?>>
                    <span class="field-lbl" style="margin:0;">Mostrar relógio regressivo para o aluno</span>
                </label>
            </div>
            <div>
                <label>
                    <span class="field-lbl">URL do checkout vitalício</span>
                    <input type="url" name="lifetime_checkout_url" value="<?= h((string)($edit['lifetime_checkout_url'] ?? '')) ?>" placeholder="https://pay.hotmart.com/...">
                </label>
                <label style="display:block;margin-top:12px;">
                    <span class="field-lbl">Código(s) da oferta vitalícia</span>
                    <input type="text" name="lifetime_offer_codes" value="<?= h((string)($edit['lifetime_offer_codes'] ?? '')) ?>" placeholder="ABC123, DEF456">
                </label>
            </div>
        </div>
        <label style="display:block;margin-top:12px;">
            <span class="field-lbl">Mensagem exibida após o prazo</span>
            <textarea name="access_expired_message" rows="3" placeholder="Seu prazo máximo de acesso terminou. Libere o acesso vitalício para continuar."><?= h((string)($edit['access_expired_message'] ?? '')) ?></textarea>
        </label>
        <div style="font-size:11.5px;color:var(--muted);margin-top:6px;line-height:1.5;">
            A liberação vitalícia ocorre somente após webhook de pagamento aprovado com um dos códigos de oferta configurados.
        </div>

        <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
            <button class="btn" type="submit">
                <?= $isClone ? 'Criar turma clonada' : ($isEdit ? 'Salvar alterações' : 'Criar turma') ?>
            </button>
            <?php if ($isEdit || $isClone): ?>
                <a class="btn-secondary" href="turmas.php">Cancelar</a>
            <?php endif; ?>
            <?php if ($isEdit): ?>
                <a class="btn-secondary" href="webhooks.php?live_edit=<?= (int)$edit['id'] ?>" style="margin-left:4px;">⚙️ Webhook</a>
                <a class="btn-secondary" href="superfuncionario.php?sf_edit=<?= (int)$edit['id'] ?>" style="margin-left:4px;">⚙️ SF</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ===== TABELA ===== -->
<div class="card">
    <h4 style="margin:0 0 12px 0;">Turmas cadastradas</h4>
    <?php if (!$turmas): ?>
        <p style="color:var(--muted);font-size:13px;">Nenhuma turma cadastrada ainda.</p>
    <?php else: ?>
    <div class="turmas-table-wrap">
    <table class="table table-turmas" id="turmas-sort-table" style="width:100%;">
        <thead>
        <tr>
            <th><button type="button" class="sort-head" data-sort="codigo">Código</button></th>
            <th><button type="button" class="sort-head" data-sort="alunos">Alunos</button></th>
            <th><button type="button" class="sort-head" data-sort="janela">Janela</button></th>
            <th><button type="button" class="sort-head" data-sort="live">Live</button></th>
            <th><button type="button" class="sort-head" data-sort="senha">Senha</button></th>
            <th><button type="button" class="sort-head" data-sort="webhook">Webhook</button></th>
            <th><button type="button" class="sort-head" data-sort="sf">SF</button></th>
            <th><button type="button" class="sort-head" data-sort="disparado">Disparado</button></th>
            <th>Envio da live</th>
            <th class="actions-head">Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($turmas as $t): ?>
            <?php
            $whEnabled = (int)($t['live_webhook_enabled'] ?? 0) === 1 && !empty($t['webhook_live_url']);
            $sfEnabled2 = (int)($t['sf_enabled'] ?? 0) === 1;
            $disparada = (int)($t['live_disparada'] ?? 0) === 1;
            $ultimoDisparo = $ultimosDisparosLive[(int)$t['id']] ?? '';
            $ultimoDisparoLabel = $ultimoDisparo !== '' ? dt_br_short($ultimoDisparo) : '';
            $statusDisparo = $statusDisparosLive[(int)$t['id']] ?? null;
            $statusDisparoCodigo = (string)($statusDisparo['status'] ?? '');
            $disparoEmAndamento = in_array($statusDisparoCodigo, ['queued', 'iniciado', 'processando'], true);
            $statusDisparoLabel = $disparoEmAndamento
                ? 'Disparando'
                : ($statusDisparoCodigo === 'concluido_com_falhas' ? 'Concluído com erros' : ($statusDisparoCodigo === 'concluido' ? 'Concluído' : 'Sem disparo'));
            $statusDisparoClasse = $disparoEmAndamento
                ? 'badge-warn'
                : ($statusDisparoCodigo === 'concluido_com_falhas' ? 'badge-error' : ($statusDisparoCodigo === 'concluido' ? 'badge-ok' : 'badge-off'));
            ?>
            <tr>
                <td data-label="Código" data-sort-codigo="<?= h(strtolower((string)$t['codigo'])) ?>">
                    <strong><?= h((string)$t['codigo']) ?></strong>
                    <?php if (!empty($t['codigo_live'])): ?>
                        <br><span style="font-size:10.5px;color:var(--muted);"><?= h((string)$t['codigo_live']) ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="Alunos" data-sort-alunos="<?= (int)($t['total_alunos'] ?? 0) ?>">
                    <strong style="font-size:15px;"><?= number_format((int)($t['total_alunos'] ?? 0), 0, ',', '.') ?></strong>
                </td>
                <td data-label="Janela" data-sort-janela="<?= sort_ts($t['janela_inicio'] ?? null) ?>" style="white-space:nowrap;font-size:11px;">
                    <?= h(dt_br_short($t['janela_inicio'] ?? null)) ?><br>
                    <span style="color:var(--muted)">→ <?= h(dt_br_short($t['janela_fim'] ?? null)) ?></span>
                </td>
                <td data-label="Live" data-sort-live="<?= sort_ts($t['data_live'] ?? null) ?>" style="white-space:nowrap;font-size:11px;"><?= h(dt_br_short($t['data_live'] ?? null)) ?></td>
                <td data-label="Senha" data-sort-senha="<?= h(strtolower((string)($t['senha_certificado'] ?? ''))) ?>" style="font-size:11px;color:var(--muted);"><?= h((string)($t['senha_certificado']??'—')) ?></td>
                <td data-label="Webhook" data-sort-webhook="<?= $whEnabled ? 2 : (!empty($t['webhook_live_url']) ? 1 : 0) ?>">
                    <?php if ($whEnabled): ?>
                        <span class="badge-ok">ON</span>
                    <?php elseif (!empty($t['webhook_live_url'])): ?>
                        <span class="badge-warn">OFF</span>
                    <?php else: ?>
                        <span class="badge-off">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="SF" data-sort-sf="<?= $sfEnabled2 ? 1 : 0 ?>">
                    <?php if ($sfEnabled2): ?>
                        <span class="badge-ok">ON</span>
                    <?php else: ?>
                        <span class="badge-off">OFF</span>
                    <?php endif; ?>
                </td>
                <td data-label="Disparado" data-sort-disparado="<?= $disparada ? 1 : 0 ?>">
                    <?php if ($disparada): ?>
                        <span class="badge-ok">Sim</span>
                        <?php if ($ultimoDisparoLabel !== ''): ?>
                            <br><span style="font-size:10px;color:var(--muted);white-space:nowrap;"><?= h($ultimoDisparoLabel) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge-off">Não</span>
                    <?php endif; ?>
                </td>
                <td class="live-dispatch-summary" data-label="Envio da live" data-live-dispatch-turma="<?= (int)$t['id'] ?>">
                    <button type="button" class="<?= h($statusDisparoClasse) ?> live-status-button" data-live-status data-live-diag-open="<?= (int)$t['id'] ?>"><?= h($statusDisparoLabel) ?></button>
                    <div class="live-dispatch-counts" <?= $statusDisparo ? '' : 'hidden' ?>>
                        <span>Enviados: <strong data-live-enviados><?= (int)($statusDisparo['enviados'] ?? 0) ?></strong></span>
                        <span>Faltam: <strong data-live-faltam><?= (int)($statusDisparo['faltam'] ?? 0) ?></strong></span>
                        <span>Erros: <strong data-live-erros><?= (int)($statusDisparo['erros'] ?? 0) ?></strong></span>
                    </div>
                </td>
                <td class="actions-cell" data-label="Ações">
                    <?php
                        $liveTs = sort_ts($t['data_live'] ?? null);
                        $manualLivePermitido = $liveTs > time();
                        $confirmacaoDisparo = $disparada
                            ? 'Esta live ja foi disparada' . ($ultimoDisparoLabel !== '' ? ' em ' . $ultimoDisparoLabel : '') . '. Tem certeza de que deseja disparar novamente? Os alunos elegiveis poderao receber o aviso outra vez.'
                            : 'Disparar agora os avisos de live desta turma? O cron nao enviara novamente.';
                    ?>
                    <div class="turma-actions">
                    <?php if ($disparoEmAndamento): ?>
                        <button type="button" class="btn-sm" data-live-progress-button data-live-diag-open="<?= (int)$t['id'] ?>" title="Abrir diagnostico ao vivo da fila">Disparo em andamento</button>
                    <?php elseif ($manualLivePermitido): ?>
                        <form method="post" onsubmit="return confirm(<?= h(json_encode($confirmacaoDisparo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)">
                            <input type="hidden" name="acao" value="disparar_live_turma_manual">
                            <input type="hidden" name="turma_id" value="<?= (int)$t['id'] ?>">
                            <?php if ($disparada): ?>
                                <input type="hidden" name="confirmar_redisparo" value="1">
                            <?php endif; ?>
                            <button type="submit" class="btn-sm"><?= $disparada ? 'Disparar novamente' : 'Disparar live' ?></button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn-sm" disabled title="<?= $disparada ? 'Avisos ja disparados' : 'Data da live encerrada ou nao definida' ?>">Live indisponivel</button>
                    <?php endif; ?>
                    <a href="?edit=<?= (int)$t['id'] ?>" class="btn-sm">Editar</a>
                    <a href="?clone_fill=<?= (int)$t['id'] ?>" class="btn-sm">Clonar</a>
                    <a href="webhooks.php?live_edit=<?= (int)$t['id'] ?>" class="btn-sm">⚙️ Webhook</a>
                    <a href="superfuncionario.php?sf_edit=<?= (int)$t['id'] ?>" class="btn-sm">⚙️ SF</a>
                    <a href="?del=<?= (int)$t['id'] ?>" class="btn-sm btn-danger-sm" onclick="return confirm('Remover turma?')">Remover</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="live-diag-modal" id="liveDiagModal" aria-hidden="true">
    <div class="live-diag-panel" role="dialog" aria-modal="true" aria-labelledby="liveDiagTitle">
        <div class="live-diag-head">
            <div>
                <h3 class="live-diag-title" id="liveDiagTitle">Diagnostico do disparo</h3>
                <div class="live-diag-sub" id="liveDiagSub">Carregando...</div>
            </div>
            <button type="button" class="live-diag-close" id="liveDiagClose" aria-label="Fechar">&times;</button>
        </div>
        <div class="live-diag-alert" id="liveDiagAlert"></div>
        <div class="live-diag-kpis">
            <div class="live-diag-card"><span>Status</span><strong id="ldStatus">-</strong><small id="ldStatusHint">-</small></div>
            <div class="live-diag-card"><span>Enviados</span><strong id="ldSent">0</strong><small id="ldSentHint">-</small></div>
            <div class="live-diag-card"><span>Restantes</span><strong id="ldRemaining">0</strong><small id="ldPercent">0%</small></div>
            <div class="live-diag-card"><span>Tempo</span><strong id="ldElapsed">0s</strong><small id="ldStarted">-</small></div>
            <div class="live-diag-card"><span>Previsao</span><strong id="ldEta">-</strong><small id="ldEtaAt">-</small></div>
            <div class="live-diag-card"><span>Velocidade</span><strong id="ldRate">0/min</strong><small id="ldHeartbeat">-</small></div>
        </div>
        <div class="live-diag-progress"><div id="ldProgressBar"></div></div>
        <div class="live-diag-grid">
            <div class="live-diag-section">
                <h4>Fila e Cron</h4>
                <div class="live-diag-list" id="ldCronList"></div>
            </div>
            <div class="live-diag-section">
                <h4>Canais e Filtros</h4>
                <div class="live-diag-list" id="ldChannelList"></div>
            </div>
        </div>
        <div class="live-diag-grid">
            <div class="live-diag-section">
                <h4>Ultimos envios</h4>
                <div class="live-diag-table-wrap"><table class="live-diag-table"><thead><tr><th>Aluno</th><th>Status</th><th>Canal</th><th>Duracao</th><th>Atualizado</th></tr></thead><tbody id="ldRecentRows"></tbody></table></div>
            </div>
            <div class="live-diag-section">
                <h4>Mais lentos</h4>
                <div class="live-diag-table-wrap"><table class="live-diag-table"><thead><tr><th>Aluno</th><th>Status</th><th>Duracao</th><th>Erro</th></tr></thead><tbody id="ldSlowRows"></tbody></table></div>
            </div>
        </div>
        <div class="live-diag-section">
            <h4>Ultimos crons</h4>
            <div class="live-diag-table-wrap"><table class="live-diag-table"><thead><tr><th>ID</th><th>Status</th><th>Inicio</th><th>Fim</th><th>Duracao</th><th>Mensagem</th></tr></thead><tbody id="ldCronRows"></tbody></table></div>
        </div>
    </div>
</div>

</div><!-- /.page-turmas -->

<?php if ($cloneFill): ?>
<script>document.getElementById('form-turma').scrollIntoView({behavior:'smooth',block:'start'});</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('turmas-sort-table');
    if (!table || !table.tBodies.length) return;

    var currentKey = '';
    var currentDir = 'asc';
    var liveDiagModal = document.getElementById('liveDiagModal');
    var liveDiagTurmaId = '';
    var liveDiagTimer = null;

    function txt(value) {
        return value === null || value === undefined || value === '' ? '-' : String(value);
    }

    function escHtml(value) {
        return txt(value).replace(/[&<>"']/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }

    function fmtMs(value) {
        var n = Number(value || 0);
        if (!n || n < 0) return '-';
        if (n >= 1000) return (n / 1000).toFixed(2) + 's';
        return Math.round(n) + 'ms';
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt(value);
    }

    function canal(row) {
        var out = [];
        if (Number(row.sf_ok || 0)) out.push('SF ok');
        if (Number(row.sf_fail || 0)) out.push('SF erro');
        if (Number(row.webhook_ok || 0)) out.push('Webhook ok');
        if (Number(row.webhook_fail || 0)) out.push('Webhook erro');
        if (Number(row.manychat_ok || 0)) out.push('ManyChat ok');
        if (Number(row.manychat_fail || 0)) out.push('ManyChat erro');
        return out.length ? out.join(', ') : '-';
    }

    function diagRow(label, value) {
        return '<div class="live-diag-row"><span>' + escHtml(label) + '</span><strong>' + escHtml(value) + '</strong></div>';
    }

    function atualizarIndicadorDisparo(cell, info) {
        var codigo = String(info.status || '');
        var ativo = ['queued', 'iniciado', 'processando'].indexOf(codigo) !== -1;
        var comErros = codigo === 'concluido_com_falhas';
        var concluido = codigo === 'concluido';
        var label = ativo ? 'Disparando' : (comErros ? 'Concluído com erros' : (concluido ? 'Concluído' : 'Sem disparo'));
        var classe = ativo ? 'badge-warn' : (comErros ? 'badge-error' : (concluido ? 'badge-ok' : 'badge-off'));
        var badge = cell.querySelector('[data-live-status]');
        var counts = cell.querySelector('.live-dispatch-counts');

        badge.className = classe + ' live-status-button';
        badge.textContent = label;
        counts.hidden = false;
        cell.querySelector('[data-live-enviados]').textContent = String(Number(info.enviados || 0));
        cell.querySelector('[data-live-faltam]').textContent = String(Number(info.faltam || 0));
        cell.querySelector('[data-live-erros]').textContent = String(Number(info.erros || 0));

        var progressButton = cell.closest('tr').querySelector('[data-live-progress-button]');
        if (progressButton && !ativo) {
            progressButton.textContent = label;
            progressButton.title = 'O disparo terminou. Atualize a pagina para liberar as acoes novamente.';
        }
        return ativo;
    }

    async function consultarAndamentoDisparos() {
        var haviaDisparoAtivo = !!table.querySelector('[data-live-dispatch-turma] [data-live-status].badge-warn');
        if (!haviaDisparoAtivo) return;

        var continuarConsultando = false;
        try {
            var url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('acao', 'status_disparos_live');
            var response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('HTTP ' + response.status);
            var payload = await response.json();
            var status = payload && payload.status ? payload.status : {};

            table.querySelectorAll('[data-live-dispatch-turma]').forEach(function (cell) {
                var turmaId = cell.getAttribute('data-live-dispatch-turma');
                if (status[turmaId]) {
                    continuarConsultando = atualizarIndicadorDisparo(cell, status[turmaId]) || continuarConsultando;
                }
            });
        } catch (e) {
            continuarConsultando = true;
            console.error('Falha ao atualizar o andamento do disparo:', e);
        }

        if (continuarConsultando) window.setTimeout(consultarAndamentoDisparos, 5000);
    }

    if (table.querySelector('[data-live-dispatch-turma] [data-live-status].badge-warn')) {
        window.setTimeout(consultarAndamentoDisparos, 2000);
    }

    async function carregarDiagnostico() {
        if (!liveDiagTurmaId) return;
        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('acao', 'diagnostico_disparo_live');
        url.searchParams.set('turma_id', liveDiagTurmaId);
        var response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
        if (!response.ok) throw new Error('HTTP ' + response.status);
        renderDiagnostico(await response.json());
    }

    function renderDiagnostico(data) {
        if (!data || !data.ok) {
            setText('liveDiagSub', data && data.message ? data.message : 'Falha ao carregar diagnostico.');
            return;
        }
        var turma = data.turma || {};
        var dispatch = data.dispatch || {};
        var progress = data.progress || {};
        var cron = data.cron || {};
        var task = cron.task || {};
        var alert = document.getElementById('liveDiagAlert');
        document.getElementById('liveDiagTitle').textContent = 'Disparo da turma ' + txt(turma.codigo);
        setText('liveDiagSub', 'Live: ' + txt(turma.data_live) + ' | Disparo planejado: ' + txt(turma.live_disparo_data));
        setText('ldStatus', txt(dispatch.status));
        setText('ldStatusHint', cron.locked ? (cron.stale ? 'Lock sem progresso' : 'Cron em execucao') : 'Sem lock ativo');
        setText('ldSent', Number(progress.sent || 0).toLocaleString('pt-BR'));
        setText('ldSentHint', 'Excluidos: ' + Number(progress.skipped || 0).toLocaleString('pt-BR') + ' | Erros: ' + Number(progress.failed || 0).toLocaleString('pt-BR'));
        setText('ldRemaining', Number(progress.remaining_after_cursor || 0).toLocaleString('pt-BR'));
        setText('ldPercent', Number(progress.percent || 0).toFixed(2).replace('.', ',') + '% de ' + Number(turma.total_alunos || 0).toLocaleString('pt-BR'));
        setText('ldElapsed', txt(dispatch.elapsed));
        setText('ldStarted', 'Inicio: ' + txt(dispatch.started_at));
        setText('ldEta', dispatch.eta || '-');
        setText('ldEtaAt', dispatch.eta_at ? ('Fim estimado: ' + dispatch.eta_at) : '-');
        setText('ldRate', Number(progress.rate_per_minute || 0).toFixed(2).replace('.', ',') + '/min');
        setText('ldHeartbeat', 'Ultimo progresso: ' + txt(dispatch.last_heartbeat_at) + ' (' + txt(dispatch.heartbeat_age) + ')');
        document.getElementById('ldProgressBar').style.width = Math.max(0, Math.min(100, Number(progress.percent || 0))) + '%';
        if (cron.stale) {
            alert.textContent = 'Atencao: existe lock de cron sem progresso recente. O recuperador automatico deve liberar a rotina para retomar.';
            alert.classList.add('show');
        } else {
            alert.classList.remove('show');
        }
        document.getElementById('ldCronList').innerHTML =
            diagRow('Timeout configurado', (task.timeout_seconds || 0) + 's') +
            diagRow('Lock ativo', cron.locked ? 'Sim' : 'Nao') +
            diagRow('Running until', task.running_until || '-') +
            diagRow('Proximo cron', task.next_run_at || '-') +
            diagRow('Ultima tentativa', task.last_attempt_at || '-') +
            diagRow('Ultimo status', task.last_status || '-') +
            diagRow('Mensagem', task.last_message || '-');
        var skips = (progress.skip_reasons || []).map(function (s) {
            return txt(s.reason || 'sem motivo') + ': ' + Number(s.total || 0).toLocaleString('pt-BR');
        }).join(' | ');
        document.getElementById('ldChannelList').innerHTML =
            diagRow('Cursor', turma.cursor || 0) +
            diagRow('Proximo user_id', turma.next_user_id || '-') +
            diagRow('SF ok / erro', Number(dispatch.sf_ok || 0).toLocaleString('pt-BR') + ' / ' + Number(dispatch.sf_fail || 0).toLocaleString('pt-BR')) +
            diagRow('Webhook ok / erro', Number(dispatch.webhook_ok || 0).toLocaleString('pt-BR') + ' / ' + Number(dispatch.webhook_fail || 0).toLocaleString('pt-BR')) +
            diagRow('ManyChat ok / erro', Number(dispatch.manychat_ok || 0).toLocaleString('pt-BR') + ' / ' + Number(dispatch.manychat_fail || 0).toLocaleString('pt-BR')) +
            diagRow('Filtros aplicados', skips || '-');
        document.getElementById('ldRecentRows').innerHTML = (data.recent_recipients || []).map(function (r) {
            return '<tr><td>' + escHtml((r.nome || '-') + ' #' + (r.user_id || '')) + '<br><span style="color:var(--muted)">' + escHtml(r.email || r.telefone || '') + '</span></td><td>' + escHtml(r.status || '-') + '<br><span style="color:var(--muted)">' + escHtml(r.skip_reason || r.error_message || '') + '</span></td><td>' + escHtml(canal(r)) + '</td><td>' + escHtml(fmtMs(r.duration_ms)) + '</td><td>' + escHtml(r.updated_at || r.finished_at || '-') + '</td></tr>';
        }).join('') || '<tr><td colspan="5">Nenhum destinatario registrado.</td></tr>';
        document.getElementById('ldSlowRows').innerHTML = (data.slowest_recipients || []).map(function (r) {
            return '<tr><td>' + escHtml((r.nome || '-') + ' #' + (r.user_id || '')) + '</td><td>' + escHtml(r.status || '-') + '</td><td>' + escHtml(fmtMs(r.duration_ms)) + '</td><td>' + escHtml(r.error_message || r.skip_reason || '-') + '</td></tr>';
        }).join('') || '<tr><td colspan="4">Sem duracoes registradas.</td></tr>';
        document.getElementById('ldCronRows').innerHTML = (cron.runs || []).map(function (r) {
            return '<tr><td>' + escHtml(r.id) + '</td><td>' + escHtml(r.status) + '</td><td>' + escHtml(r.started_at) + '</td><td>' + escHtml(r.finished_at || '-') + '</td><td>' + escHtml(fmtMs(r.duration_ms)) + '</td><td>' + escHtml(r.error_message || r.output_text || '-') + '</td></tr>';
        }).join('') || '<tr><td colspan="6">Sem execucoes.</td></tr>';
    }

    function abrirDiagnostico(turmaId) {
        liveDiagTurmaId = String(turmaId || '');
        if (!liveDiagTurmaId) return;
        liveDiagModal.classList.add('open');
        liveDiagModal.setAttribute('aria-hidden', 'false');
        setText('liveDiagSub', 'Carregando...');
        clearInterval(liveDiagTimer);
        carregarDiagnostico().catch(function (e) { setText('liveDiagSub', 'Falha ao carregar: ' + e.message); });
        liveDiagTimer = setInterval(function () {
            carregarDiagnostico().catch(function (e) { console.error('Falha no diagnostico ao vivo:', e); });
        }, 3000);
    }

    function fecharDiagnostico() {
        liveDiagModal.classList.remove('open');
        liveDiagModal.setAttribute('aria-hidden', 'true');
        liveDiagTurmaId = '';
        clearInterval(liveDiagTimer);
        liveDiagTimer = null;
    }

    document.querySelectorAll('[data-live-diag-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            abrirDiagnostico(btn.getAttribute('data-live-diag-open'));
        });
    });
    document.getElementById('liveDiagClose').addEventListener('click', fecharDiagnostico);
    liveDiagModal.addEventListener('click', function (event) {
        if (event.target === liveDiagModal) fecharDiagnostico();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && liveDiagModal.classList.contains('open')) fecharDiagnostico();
    });

    function readValue(row, key) {
        var cell = row.querySelector('[data-sort-' + key + ']');
        if (!cell) return '';
        var raw = cell.getAttribute('data-sort-' + key) || '';
        if (/^-?\d+(\.\d+)?$/.test(raw)) return Number(raw);
        return raw.toLowerCase();
    }

    table.querySelectorAll('.sort-head').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.getAttribute('data-sort');
            var dir = currentKey === key && currentDir === 'asc' ? 'desc' : 'asc';
            currentKey = key;
            currentDir = dir;

            table.querySelectorAll('.sort-head').forEach(function (b) {
                b.classList.remove('asc', 'desc');
            });
            btn.classList.add(dir);

            var rows = Array.from(table.tBodies[0].rows);
            rows.sort(function (a, b) {
                var av = readValue(a, key);
                var bv = readValue(b, key);
                if (av < bv) return dir === 'asc' ? -1 : 1;
                if (av > bv) return dir === 'asc' ? 1 : -1;
                return 0;
            });
            rows.forEach(function (row) { table.tBodies[0].appendChild(row); });
        });
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
