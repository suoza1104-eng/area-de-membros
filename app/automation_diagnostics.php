<?php
declare(strict_types=1);

require_once __DIR__ . '/automation_flows.php';

function automation_diagnostics_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_diagnostics (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        run_id_early BIGINT UNSIGNED NULL,
        run_id_late BIGINT UNSIGNED NULL,
        check_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'healthy',
        issue_title VARCHAR(255) NULL,
        issue_type VARCHAR(60) NULL,
        summary_json LONGTEXT NOT NULL,
        acknowledged TINYINT(1) NOT NULL DEFAULT 0,
        acknowledged_at DATETIME NULL,
        acknowledged_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_afd_flow (flow_id, check_time),
        KEY idx_afd_status (status, acknowledged),
        KEY idx_afd_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_diagnostics_global (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        check_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'healthy',
        issues_count INT UNSIGNED NOT NULL DEFAULT 0,
        summary_json LONGTEXT NOT NULL,
        acknowledged TINYINT(1) NOT NULL DEFAULT 0,
        acknowledged_at DATETIME NULL,
        acknowledged_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_adg_status (status, acknowledged),
        KEY idx_adg_check (check_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

/**
 * Calcula a duração teórica projetada do fluxo a partir dos nós Wait (em minutos).
 */
function automation_flow_theoretical_duration(array $graph): array
{
    $totalMinutes = 0;
    $waitNodes = [];
    $hasVoice = false;
    $voiceMaxMinutes = 0;

    foreach ($graph['nodes'] ?? [] as $node) {
        $type = (string)($node['type'] ?? '');
        $c = is_array($node['config'] ?? null) ? $node['config'] : [];
        if ($type === 'wait') {
            $duration = max(1, (int)($c['duration'] ?? 1));
            $unit = (string)($c['unit'] ?? 'hours');
            $mins = match ($unit) {
                'minutes' => $duration,
                'days' => $duration * 1440,
                default => $duration * 60,
            };
            $totalMinutes += $mins;
            $waitNodes[] = [
                'id' => (string)($node['id'] ?? ''),
                'label' => (string)($c['label'] ?? 'Aguardar'),
                'minutes' => $mins,
            ];
        } elseif ($type === 'voice' || $type === 'torpedo_voz') {
            $hasVoice = true;
            $qDuration = max(1, (int)($c['maxQueueDuration'] ?? 15));
            $qUnit = (string)($c['maxQueueUnit'] ?? 'minutes');
            $voiceMaxMinutes = match ($qUnit) {
                'hours' => $qDuration * 60,
                'days' => $qDuration * 1440,
                default => $qDuration,
            };
        }
    }

    return [
        'total_minutes' => $totalMinutes,
        'wait_nodes' => $waitNodes,
        'has_voice' => $hasVoice,
        'voice_max_minutes' => $voiceMaxMinutes,
    ];
}

/**
 * Executa a suíte completa de diagnósticos para todos os fluxos ativos.
 */
function automation_run_complete_diagnostics(PDO $pdo, string $triggeredBy = 'cron'): array
{
    automation_diagnostics_ensure_schema($pdo);

    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $checkTimeStr = $now->format('Y-m-d H:i:s');

    $activeFlows = $pdo->query("
        SELECT f.id, f.name, f.status, f.current_version_id, v.graph_json
        FROM automation_flows f
        LEFT JOIN automation_flow_versions v ON v.id = f.current_version_id
        WHERE f.status = 'active'
        ORDER BY f.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $flowReports = [];
    $totalIssues = 0;
    $infraIssues = [];

    // 1. CHECAGEM DE INFRAESTRUTURA & CRONS
    try {
        $cronTasks = $pdo->query("SELECT * FROM cron_managed_tasks WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($cronTasks as $ct) {
            $taskKey = (string)$ct['task_key'];
            $lastSuccess = $ct['last_success_at'] ? new DateTimeImmutable((string)$ct['last_success_at'], $now->getTimezone()) : null;
            if ($lastSuccess) {
                $diffMinutes = ($now->getTimestamp() - $lastSuccess->getTimestamp()) / 60;
                $maxInterval = max(15, (int)($ct['interval_minutes'] ?? 1) * 5);
                if ($diffMinutes > $maxInterval) {
                    $infraIssues[] = [
                        'type' => 'cron_gap',
                        'task_key' => $taskKey,
                        'label' => (string)($ct['label'] ?? $taskKey),
                        'last_success' => (string)$ct['last_success_at'],
                        'delayed_minutes' => round($diffMinutes, 1),
                        'message' => "O cron '{$ct['label']}' não executa com sucesso há " . round($diffMinutes) . " minutos.",
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        $infraIssues[] = ['type' => 'cron_check_error', 'message' => $e->getMessage()];
    }

    // 2. CHECAGEM DE JOBS ÓRFÃOS / PRESOS EM 'PROCESSING'
    try {
        $stuckJobs = (int)$pdo->query("
            SELECT COUNT(*) FROM automation_flow_jobs 
            WHERE status = 'processing' AND lease_until < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ")->fetchColumn();
        if ($stuckJobs > 0) {
            $infraIssues[] = [
                'type' => 'stuck_processing_jobs',
                'count' => $stuckJobs,
                'message' => "Existem {$stuckJobs} etapa(s) de automação travadas em 'processing' com lease estourado.",
            ];
        }
    } catch (Throwable $e) {
        // Ignora caso a tabela esteja vazia
    }

    // 3. VARREDURA FLUXO POR FLUXO
    foreach ($activeFlows as $flow) {
        $flowId = (int)$flow['id'];
        $flowName = (string)$flow['name'];
        $graphJson = (string)($flow['graph_json'] ?? '{}');
        $graph = json_decode($graphJson, true) ?: [];

        $theo = automation_flow_theoretical_duration($graph);
        $totalTheoMinutes = max(1, $theo['total_minutes']);

        $flowStatus = 'healthy';
        $issues = [];

        // --- TESTE 1: DUAS AMOSTRAS EM ANDAMENTO / RECENTES (A = Mais Antiga, B = Mais Recente) ---
        $sampleEarly = null;
        $sampleLate = null;

        // Amostra A: Lead que entrou há mais tempo (tentando pegar > 60% do percurso)
        $earlyThresholdMinutes = max(15, (int)($totalTheoMinutes * 0.6));
        $stEarly = $pdo->prepare("
            SELECT r.id run_id, r.user_id, r.status, r.started_at, r.finished_at, u.nome, u.email
            FROM automation_flow_runs r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.flow_id = :fid AND r.started_at <= DATE_SUB(NOW(), INTERVAL :mins MINUTE)
            ORDER BY r.started_at DESC
            LIMIT 1
        ");
        $stEarly->execute([':fid' => $flowId, ':mins' => $earlyThresholdMinutes]);
        $sampleEarly = $stEarly->fetch(PDO::FETCH_ASSOC) ?: null;

        // Se não achou com >60%, pega a execução em andamento mais antiga de hoje
        if (!$sampleEarly) {
            $stEarlyFallback = $pdo->prepare("
                SELECT r.id run_id, r.user_id, r.status, r.started_at, r.finished_at, u.nome, u.email
                FROM automation_flow_runs r
                LEFT JOIN users u ON u.id = r.user_id
                WHERE r.flow_id = :fid AND r.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY r.started_at ASC
                LIMIT 1
            ");
            $stEarlyFallback->execute([':fid' => $flowId]);
            $sampleEarly = $stEarlyFallback->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Amostra B: Lead que entrou mais recentemente (para comparar com a Amostra A)
        $stLate = $pdo->prepare("
            SELECT r.id run_id, r.user_id, r.status, r.started_at, r.finished_at, u.nome, u.email
            FROM automation_flow_runs r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.flow_id = :fid " . ($sampleEarly ? "AND r.id <> " . (int)$sampleEarly['run_id'] : "") . "
            ORDER BY r.started_at DESC
            LIMIT 1
        ");
        $stLate->execute([':fid' => $flowId]);
        $sampleLate = $stLate->fetch(PDO::FETCH_ASSOC) ?: null;

        // Análise detalhada das etapas das amostras A e B
        $earlyAnalysis = $sampleEarly ? automation_analyze_run_steps($pdo, (int)$sampleEarly['run_id'], $graph, $now, $sampleEarly) : null;
        $lateAnalysis = $sampleLate ? automation_analyze_run_steps($pdo, (int)$sampleLate['run_id'], $graph, $now, $sampleLate) : null;

        if ($earlyAnalysis && !empty($earlyAnalysis['issues'])) {
            foreach ($earlyAnalysis['issues'] as $iss) {
                $issues[] = [
                    'source' => 'sample_early',
                    'sample_user' => $sampleEarly['nome'] ?: $sampleEarly['email'],
                    'run_id' => (int)$sampleEarly['run_id'],
                ] + $iss;
            }
        }
        if ($lateAnalysis && !empty($lateAnalysis['issues'])) {
            foreach ($lateAnalysis['issues'] as $iss) {
                $issues[] = [
                    'source' => 'sample_late',
                    'sample_user' => $sampleLate['nome'] ?: $sampleLate['email'],
                    'run_id' => (int)$sampleLate['run_id'],
                ] + $iss;
            }
        }

        // --- TESTE 2: BENCHMARK DE SLA DOS ÚLTIMOS 5 CONCLUÍDOS ---
        $stCompleted = $pdo->prepare("
            SELECT r.id run_id, r.user_id, r.started_at, r.finished_at, u.nome, u.email,
                   TIMESTAMPDIFF(MINUTE, r.started_at, r.finished_at) AS duration_minutes
            FROM automation_flow_runs r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.flow_id = :fid AND r.status = 'completed' AND r.finished_at IS NOT NULL
            ORDER BY r.finished_at DESC
            LIMIT 5
        ");
        $stCompleted->execute([':fid' => $flowId]);
        $lastCompleted = $stCompleted->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $benchmarkAnalysis = [
            'samples_count' => count($lastCompleted),
            'avg_duration_minutes' => 0,
            'max_duration_minutes' => 0,
            'min_duration_minutes' => 0,
            'theoretical_minutes' => $totalTheoMinutes,
            'sla_deviation_ratio' => 1.0,
            'samples' => $lastCompleted,
        ];

        if ($lastCompleted) {
            $durations = array_map(fn($row) => max(0, (int)$row['duration_minutes']), $lastCompleted);
            $avgDuration = array_sum($durations) / count($durations);
            $maxDuration = max($durations);
            $minDuration = min($durations);
            $ratio = $totalTheoMinutes > 0 ? ($avgDuration / $totalTheoMinutes) : 1.0;

            $benchmarkAnalysis['avg_duration_minutes'] = round($avgDuration, 1);
            $benchmarkAnalysis['max_duration_minutes'] = $maxDuration;
            $benchmarkAnalysis['min_duration_minutes'] = $minDuration;
            // Se houver atraso significativo em relação à duração teórica projetada:
            $toleratedDelayMinutes = max(10, (int)($totalTheoMinutes * 0.5));
            $maxExpectedMinutes = $totalTheoMinutes + $toleratedDelayMinutes;

            if ($avgDuration > $maxExpectedMinutes) {
                $diffDelay = round($avgDuration - $totalTheoMinutes);
                $issues[] = [
                    'source' => 'sla_benchmark',
                    'type' => 'sla_excessive_delay',
                    'message' => "Desvio Crítico de SLA: Fluxo projetado para ~{$totalTheoMinutes} min, mas os últimos " . count($lastCompleted) . " leads levaram em média " . round($avgDuration) . " min (+{$diffDelay}m de atraso na fila/cron).",
                    'avg_minutes' => round($avgDuration),
                    'theo_minutes' => $totalTheoMinutes,
                    'delay_minutes' => $diffDelay,
                ];
            }
        }

        // --- TESTE 3: TORPEDO DE VOZ (Prazo Máximo de Fila Estourado) ---
        if (!empty($theo['has_voice'])) {
            $voiceMax = max(5, (int)$theo['voice_max_minutes']);
            $stVoiceTimeouts = $pdo->prepare("
                SELECT COUNT(*) FROM automation_flow_steps s
                JOIN automation_flow_runs r ON r.id = s.run_id
                WHERE r.flow_id = :fid AND s.node_type IN ('voice','torpedo_voz')
                  AND s.status = 'failed' AND (s.error_message LIKE '%tempo maximo%' OR s.error_message LIKE '%expirou%')
                  AND s.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stVoiceTimeouts->execute([':fid' => $flowId]);
            $voiceTimeoutCount = (int)$stVoiceTimeouts->fetchColumn();
            if ($voiceTimeoutCount > 0) {
                $issues[] = [
                    'source' => 'voice_deadline',
                    'type' => 'voice_queue_timeout',
                    'message' => "{$voiceTimeoutCount} disparo(s) de Torpedo de Voz cancelado(s) nas últimas 24h por ultrapassar o prazo máximo de {$voiceMax} minutos.",
                    'count' => $voiceTimeoutCount,
                ];
            }
        }

        // --- TESTE 4: GATILHO MUDO (Ghost Flow) ---
        $stCaptures = $pdo->prepare("
            SELECT COUNT(*) FROM automation_flow_runs 
            WHERE flow_id = :fid AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stCaptures->execute([':fid' => $flowId]);
        $captures24h = (int)$stCaptures->fetchColumn();

        $triggerNode = null;
        foreach ($graph['nodes'] ?? [] as $n) {
            if (($n['type'] ?? '') === 'trigger') { $triggerNode = $n; break; }
        }
        $triggerEvent = (string)($triggerNode['config']['event'] ?? '');

        if ($triggerEvent === 'PAGAMENTO_APROVADO' && $captures24h === 0) {
            $recentSales = (int)$pdo->query("
                SELECT COUNT(*) FROM inscricao_logs 
                WHERE data_criacao >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();
            if ($recentSales >= 5) {
                $issues[] = [
                    'source' => 'ghost_trigger',
                    'type' => 'no_captures_despite_sales',
                    'message' => "Gatilho Mudo Suspeito: Ocorreram {$recentSales} vendas nas últimas 24h, mas nenhuma foi capturada por este fluxo.",
                    'sales_count' => $recentSales,
                ];
            }
        }

        // --- CONSOLIDAÇÃO DO STATUS DO FLUXO ---
        if (!empty($issues)) {
            $hasCritical = false;
            foreach ($issues as $iss) {
                if (in_array(($iss['type'] ?? ''), ['stuck_step', 'failed_integration', 'sla_excessive_delay'], true)) {
                    $hasCritical = true;
                    break;
                }
            }
            $flowStatus = $hasCritical ? 'critical' : 'warning';
            $totalIssues++;
        }

        $summaryData = [
            'flow_id' => $flowId,
            'flow_name' => $flowName,
            'status' => $flowStatus,
            'checked_at' => $checkTimeStr,
            'theoretical_duration_minutes' => $totalTheoMinutes,
            'sample_early' => $sampleEarly ? [
                'user' => $sampleEarly['nome'] ?: $sampleEarly['email'],
                'run_id' => (int)$sampleEarly['run_id'],
                'started_at' => (string)$sampleEarly['started_at'],
                'status' => (string)$sampleEarly['status'],
                'analysis' => $earlyAnalysis,
            ] : null,
            'sample_late' => $sampleLate ? [
                'user' => $sampleLate['nome'] ?: $sampleLate['email'],
                'run_id' => (int)$sampleLate['run_id'],
                'started_at' => (string)$sampleLate['started_at'],
                'status' => (string)$sampleLate['status'],
                'analysis' => $lateAnalysis,
            ] : null,
            'benchmark' => $benchmarkAnalysis,
            'captures_24h' => $captures24h,
            'issues' => $issues,
        ];

        $jsonPayload = json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $issueType = !empty($issues) ? (string)($issues[0]['type'] ?? 'anomaly') : null;
        $issueTitle = !empty($issues) ? (string)($issues[0]['message'] ?? 'Inconsistência detectada') : null;

        $pdo->prepare("
            INSERT INTO automation_flow_diagnostics 
            (flow_id, run_id_early, run_id_late, check_time, status, issue_title, issue_type, summary_json, acknowledged)
            VALUES (:fid, :re, :rl, :ct, :st, :title, :type, :json, 0)
        ")->execute([
            ':fid' => $flowId,
            ':re' => $sampleEarly ? (int)$sampleEarly['run_id'] : null,
            ':rl' => $sampleLate ? (int)$sampleLate['run_id'] : null,
            ':ct' => $checkTimeStr,
            ':st' => $flowStatus,
            ':title' => $issueTitle,
            ':type' => $issueType,
            ':json' => $jsonPayload,
        ]);

        $flowReports[$flowId] = $summaryData;
    }

    $globalStatus = ($totalIssues > 0 || !empty($infraIssues)) ? 'warning' : 'healthy';
    $globalSummary = [
        'check_time' => $checkTimeStr,
        'status' => $globalStatus,
        'total_flows_checked' => count($activeFlows),
        'issues_count' => $totalIssues + count($infraIssues),
        'infra_issues' => $infraIssues,
        'flows' => $flowReports,
        'triggered_by' => $triggeredBy,
    ];

    $pdo->prepare("
        INSERT INTO automation_diagnostics_global (check_time, status, issues_count, summary_json, acknowledged)
        VALUES (:ct, :st, :ic, :json, 0)
    ")->execute([
        ':ct' => $checkTimeStr,
        ':st' => $globalStatus,
        ':ic' => count($infraIssues) + $totalIssues,
        ':json' => json_encode($globalSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return $globalSummary;
}

/**
 * Formata a diferença temporal em formato legível (+35m, +1h 20m, No horário).
 */
function automation_format_delay(?int $diffSeconds): string
{
    if ($diffSeconds === null) return '-';
    if (abs($diffSeconds) < 60) return 'No horário (0m)';
    $sign = $diffSeconds > 0 ? '+' : '-';
    $totalMinutes = (int)abs(round($diffSeconds / 60));
    $hours = (int)floor($totalMinutes / 60);
    $minutes = $totalMinutes % 60;
    if ($hours > 0) {
        return "{$sign}{$hours}h {$minutes}m";
    }
    return "{$sign}{$minutes}m";
}

/**
 * Analisa as etapas e jobs de uma execução específica para detectar travamentos ou atrasos.
 */
function automation_analyze_run_steps(PDO $pdo, int $runId, array $graph, DateTimeImmutable $now, ?array $runData = null): array
{
    if (!$runData) {
        $stRun = $pdo->prepare("SELECT started_at, finished_at, status FROM automation_flow_runs WHERE id = :rid");
        $stRun->execute([':rid' => $runId]);
        $runData = $stRun->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $stSteps = $pdo->prepare("
        SELECT s.* FROM automation_flow_steps s 
        WHERE s.run_id = :rid 
        ORDER BY s.id ASC
    ");
    $stSteps->execute([':rid' => $runId]);
    $steps = $stSteps->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stJobs = $pdo->prepare("
        SELECT j.* FROM automation_flow_jobs j 
        WHERE j.run_id = :rid 
        ORDER BY j.id ASC
    ");
    $stJobs->execute([':rid' => $runId]);
    $jobs = $stJobs->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $issues = [];
    $stepTimeline = [];

    $currentPlannedStr = (string)($runData['started_at'] ?? '');
    $currentPlanned = $currentPlannedStr !== '' ? new DateTimeImmutable($currentPlannedStr, $now->getTimezone()) : $now;

    foreach ($steps as $s) {
        $nodeType = (string)$s['node_type'];
        $status = (string)$s['status'];
        $startedAtStr = (string)$s['started_at'];
        $finishedAtStr = (string)($s['finished_at'] ?? $startedAtStr);
        $errMsg = (string)($s['error_message'] ?? '');
        $out = json_decode((string)($s['output_json'] ?? ''), true) ?: [];

        $actualDt = $finishedAtStr !== '' ? new DateTimeImmutable($finishedAtStr, $now->getTimezone()) : new DateTimeImmutable($startedAtStr, $now->getTimezone());

        $plannedAtStr = $currentPlanned->format('Y-m-d H:i:s');
        $diffSeconds = $actualDt->getTimestamp() - $currentPlanned->getTimestamp();

        // Se for um bloco wait com agendamento futuro
        if (!empty($out['resume_at'])) {
            try {
                $resumeDt = new DateTimeImmutable((string)$out['resume_at'], $now->getTimezone());
                if ($status === 'scheduled') {
                    $plannedAtStr = $currentPlanned->format('Y-m-d H:i:s');
                    $diffSeconds = $actualDt->getTimestamp() - $currentPlanned->getTimestamp();
                    $currentPlanned = $resumeDt;
                } else {
                    $currentPlanned = $resumeDt;
                    $plannedAtStr = $currentPlanned->format('Y-m-d H:i:s');
                    $diffSeconds = $actualDt->getTimestamp() - $currentPlanned->getTimestamp();
                }
            } catch (Throwable $e) {}
        }

        $delayFormatted = automation_format_delay($diffSeconds);
        $delaySeverity = abs($diffSeconds) > 1800 ? 'critical' : (abs($diffSeconds) > 300 ? 'warning' : 'ok');

        $timelineItem = [
            'node_id' => (string)$s['node_id'],
            'node_type' => $nodeType,
            'status' => $status,
            'started_at' => $startedAtStr,
            'finished_at' => $finishedAtStr,
            'planned_at' => $plannedAtStr,
            'delay_seconds' => $diffSeconds,
            'delay_formatted' => $delayFormatted,
            'delay_severity' => $delaySeverity,
            'error' => $errMsg,
        ];

        if ($status === 'failed') {
            $issues[] = [
                'type' => 'failed_step',
                'node_id' => (string)$s['node_id'],
                'node_type' => $nodeType,
                'message' => "O bloco [{$nodeType}] falhou com o erro: " . ($errMsg ?: 'Erro desconhecido'),
            ];
        }

        $stepTimeline[] = $timelineItem;
    }

    foreach ($jobs as $j) {
        $jStatus = (string)$j['status'];
        $availAtStr = (string)$j['available_at'];
        $availAt = new DateTimeImmutable($availAtStr, $now->getTimezone());

        if (in_array($jStatus, ['queued', 'scheduled', 'retry'], true)) {
            $delayedMinutes = ($now->getTimestamp() - $availAt->getTimestamp()) / 60;
            if ($delayedMinutes > 30) {
                $issues[] = [
                    'type' => 'stuck_step',
                    'node_id' => (string)$j['node_id'],
                    'message' => "Etapa agendada para {$availAtStr} está atrasada há " . round($delayedMinutes) . " minutos sem ser processada.",
                    'delayed_minutes' => round($delayedMinutes),
                ];
            }
        } elseif ($jStatus === 'processing') {
            $leaseUntilStr = (string)($j['lease_until'] ?? '');
            if ($leaseUntilStr !== '') {
                $leaseUntil = new DateTimeImmutable($leaseUntilStr, $now->getTimezone());
                if ($now > $leaseUntil) {
                    $issues[] = [
                        'type' => 'stuck_processing',
                        'node_id' => (string)$j['node_id'],
                        'message' => "Etapa em processamento travou e estourou o tempo de execução (lease vencido).",
                    ];
                }
            }
        }
    }

    return [
        'steps' => $stepTimeline,
        'jobs_count' => count($jobs),
        'issues' => $issues,
    ];
}

/**
 * Retorna o último diagnóstico ativo ou não reconhecido.
 */
function automation_get_latest_diagnostics(PDO $pdo): ?array
{
    automation_diagnostics_ensure_schema($pdo);

    $st = $pdo->query("
        SELECT * FROM automation_diagnostics_global 
        ORDER BY id DESC LIMIT 1
    ");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) return null;

    $summary = json_decode((string)$row['summary_json'], true) ?: [];
    $summary['id'] = (int)$row['id'];
    $summary['acknowledged'] = (bool)$row['acknowledged'];
    $summary['acknowledged_at'] = $row['acknowledged_at'];
    $summary['acknowledged_by'] = $row['acknowledged_by'];

    return $summary;
}

/**
 * Retorna o mapa de diagnósticos mais recentes por fluxo (indexado por flow_id).
 */
function automation_get_flow_diagnostics_map(PDO $pdo): array
{
    automation_diagnostics_ensure_schema($pdo);

    $st = $pdo->query("
        SELECT d1.*
        FROM automation_flow_diagnostics d1
        INNER JOIN (
            SELECT flow_id, MAX(id) max_id
            FROM automation_flow_diagnostics
            GROUP BY flow_id
        ) d2 ON d1.id = d2.max_id
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    $map = [];

    foreach ($rows as $r) {
        $fid = (int)$r['flow_id'];
        $summary = json_decode((string)$r['summary_json'], true) ?: [];
        $summary['id'] = (int)$r['id'];
        $summary['status'] = (string)$r['status'];
        $summary['acknowledged'] = (bool)$r['acknowledged'];
        $summary['issue_title'] = (string)($r['issue_title'] ?? '');
        $summary['issue_type'] = (string)($r['issue_type'] ?? '');
        $map[$fid] = $summary;
    }

    return $map;
}

/**
 * Registra a ciência do administrador para todos os alertas pendentes.
 */
function automation_acknowledge_diagnostics(PDO $pdo, string $userName = 'Admin'): bool
{
    automation_diagnostics_ensure_schema($pdo);
    $pdo->prepare("
        UPDATE automation_diagnostics_global 
        SET acknowledged = 1, acknowledged_at = NOW(), acknowledged_by = :user 
        WHERE acknowledged = 0
    ")->execute([':user' => $userName]);

    $pdo->prepare("
        UPDATE automation_flow_diagnostics 
        SET acknowledged = 1, acknowledged_at = NOW(), acknowledged_by = :user 
        WHERE acknowledged = 0
    ")->execute([':user' => $userName]);

    return true;
}
