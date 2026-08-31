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

function automation_diagnostics_previous_sample_exclusions(PDO $pdo, int $flowId): array
{
    $exclusions = ['run_ids' => [], 'user_ids' => []];

    try {
        $st = $pdo->prepare("
            SELECT run_id_early, run_id_late, summary_json
            FROM automation_flow_diagnostics
            WHERE flow_id = :fid
            ORDER BY check_time DESC, id DESC
            LIMIT 1
        ");
        $st->execute([':fid' => $flowId]);
        $previous = $st->fetch(PDO::FETCH_ASSOC);
        if (!$previous) return $exclusions;

        foreach (['run_id_early', 'run_id_late'] as $key) {
            $runId = (int)($previous[$key] ?? 0);
            if ($runId > 0) $exclusions['run_ids'][] = $runId;
        }

        $summary = json_decode((string)($previous['summary_json'] ?? '{}'), true);
        if (is_array($summary)) {
            foreach (['sample_early', 'sample_late'] as $sampleKey) {
                $runId = (int)($summary[$sampleKey]['run_id'] ?? 0);
                $userId = (int)($summary[$sampleKey]['user_id'] ?? 0);
                if ($runId > 0) $exclusions['run_ids'][] = $runId;
                if ($userId > 0) $exclusions['user_ids'][] = $userId;
            }

            foreach (($summary['benchmark']['samples'] ?? []) as $sample) {
                if (!is_array($sample)) continue;
                $runId = (int)($sample['run_id'] ?? 0);
                $userId = (int)($sample['user_id'] ?? 0);
                if ($runId > 0) $exclusions['run_ids'][] = $runId;
                if ($userId > 0) $exclusions['user_ids'][] = $userId;
            }
        }

        $exclusions['run_ids'] = array_values(array_unique(array_filter(array_map('intval', $exclusions['run_ids']))));
        if ($exclusions['run_ids']) {
            $placeholders = [];
            $params = [];
            foreach ($exclusions['run_ids'] as $idx => $runId) {
                $ph = ':prev_run_' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = $runId;
            }
            $stUsers = $pdo->prepare("SELECT DISTINCT user_id FROM automation_flow_runs WHERE id IN (" . implode(',', $placeholders) . ") AND user_id IS NOT NULL AND user_id > 0");
            $stUsers->execute($params);
            foreach ($stUsers->fetchAll(PDO::FETCH_COLUMN) ?: [] as $userId) {
                $userId = (int)$userId;
                if ($userId > 0) $exclusions['user_ids'][] = $userId;
            }
        }

        $exclusions['user_ids'] = array_values(array_unique(array_filter(array_map('intval', $exclusions['user_ids']))));
    } catch (Throwable $e) {
        return ['run_ids' => [], 'user_ids' => []];
    }

    return $exclusions;
}

function automation_diagnostics_exclusion_sql(array $exclusions, string $alias = 'r', string $prefix = 'ex'): array
{
    $sql = '';
    $params = [];

    $runIds = array_values(array_unique(array_filter(array_map('intval', $exclusions['run_ids'] ?? []))));
    if ($runIds) {
        $placeholders = [];
        foreach ($runIds as $idx => $runId) {
            $ph = ':' . $prefix . '_run_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $runId;
        }
        $sql .= " AND {$alias}.id NOT IN (" . implode(',', $placeholders) . ")";
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $exclusions['user_ids'] ?? []))));
    if ($userIds) {
        $placeholders = [];
        foreach ($userIds as $idx => $userId) {
            $ph = ':' . $prefix . '_user_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $userId;
        }
        $sql .= " AND ({$alias}.user_id IS NULL OR {$alias}.user_id NOT IN (" . implode(',', $placeholders) . "))";
    }

    return [$sql, $params];
}

/**
 * Verifica a saúde das filas por canal (email/push/voz/manychat/superfuncionario/webhook):
 * canal desativado com pendências acumulando, fila represada além do esperado, ou pico
 * de falhas definitivas recentes que sugere integração quebrada.
 */
function automation_diagnose_channels(PDO $pdo, DateTimeImmutable $now): array
{
    $issues = [];
    $channels = automation_flow_channels();
    $allChannels = array_merge(['general'], $channels);
    $placeholders = implode(',', array_fill(0, count($allChannels), '?'));

    $pendingByChannel = [];
    try {
        $st = $pdo->prepare("
            SELECT channel, COUNT(*) pending, MIN(available_at) oldest_available_at
              FROM automation_flow_jobs
             WHERE status IN ('queued','retry','scheduled')
               AND channel IN ($placeholders)
          GROUP BY channel
        ");
        $st->execute($allChannels);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $pendingByChannel[(string)$row['channel']] = $row;
    } catch (Throwable $e) {
        return $issues;
    }

    foreach ($channels as $channel) {
        $settings = automation_flow_channel_settings($pdo, $channel);
        $row = $pendingByChannel[$channel] ?? null;
        $pending = (int)($row['pending'] ?? 0);
        if ($pending === 0) continue;

        if (!$settings['enabled']) {
            $issues[] = [
                'type' => 'channel_disabled_with_backlog',
                'channel' => $channel,
                'pending' => $pending,
                'message' => "O canal '{$channel}' está desativado em Automações > Canais de disparo, mas tem {$pending} etapa(s) esperando na fila.",
            ];
            continue;
        }

        if (empty($row['oldest_available_at'])) continue;
        $oldest = new DateTimeImmutable((string)$row['oldest_available_at'], $now->getTimezone());
        $ageMinutes = ($now->getTimestamp() - $oldest->getTimestamp()) / 60;
        $expectedMaxMinutes = max(10, ($settings['backoff_max_seconds'] / 60) + 5);

        if ($ageMinutes > $expectedMaxMinutes) {
            $issues[] = [
                'type' => 'channel_backlog_stalled',
                'channel' => $channel,
                'pending' => $pending,
                'oldest_minutes' => round($ageMinutes),
                'message' => "O canal '{$channel}' tem {$pending} etapa(s) na fila e a mais antiga espera há " . round($ageMinutes) . " min (acima do esperado, ~" . round($expectedMaxMinutes) . " min). Verifique se a tarefa 'automacoes_{$channel}' do cron está rodando.",
            ];
        }
    }

    try {
        $stFail = $pdo->prepare("
            SELECT channel, COUNT(*) c
              FROM automation_flow_jobs
             WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND channel IN ($placeholders)
          GROUP BY channel
        ");
        $stFail->execute($allChannels);
        foreach ($stFail->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $channel = (string)$row['channel'];
            $count = (int)$row['c'];
            if ($channel === 'general' || $count < 5) continue;
            $issues[] = [
                'type' => 'channel_failure_spike',
                'channel' => $channel,
                'count' => $count,
                'message' => "O canal '{$channel}' teve {$count} falha(s) definitiva(s) (após esgotar as tentativas) nas últimas 2h. Pode indicar integração quebrada: token expirado, URL inválida ou regra desativada.",
            ];
        }
    } catch (Throwable $e) {}

    return $issues;
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

    // 3. CANAIS DE DISPARO (email/push/voz/manychat/superfuncionario/webhook)
    $infraIssues = array_merge($infraIssues, automation_diagnose_channels($pdo, $now));

    // 4. VARREDURA FLUXO POR FLUXO
    foreach ($activeFlows as $flow) {
        $flowId = (int)$flow['id'];
        $flowName = (string)$flow['name'];
        $graphJson = (string)($flow['graph_json'] ?? '{}');
        $graph = json_decode($graphJson, true) ?: [];

        $theo = automation_flow_theoretical_duration($graph);
        $totalTheoMinutes = max(1, $theo['total_minutes']);

        $flowStatus = 'healthy';
        $issues = [];
        $previousSampleExclusions = automation_diagnostics_previous_sample_exclusions($pdo, $flowId);
        [$sampleExclusionSql, $sampleExclusionParams] = automation_diagnostics_exclusion_sql($previousSampleExclusions, 'r', 'sample_prev');

        // --- TESTE 1: DUAS AMOSTRAS EM ANDAMENTO / RECENTES (A = Mais Antiga, B = Mais Recente) ---
        $sampleEarly = null;
        $sampleLate = null;

        // Amostra A: Lead que entrou há mais tempo (tentando pegar > 60% do percurso)
        $earlyThresholdMinutes = max(15, (int)($totalTheoMinutes * 0.6));
        $stEarly = $pdo->prepare("
            SELECT r.id run_id, r.user_id, r.status, r.started_at, r.finished_at, u.nome, u.email
            FROM automation_flow_runs r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.flow_id = :fid AND r.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND r.started_at <= DATE_SUB(NOW(), INTERVAL :mins MINUTE)
            {$sampleExclusionSql}
            ORDER BY r.started_at DESC
            LIMIT 1
        ");
        $stEarly->execute([':fid' => $flowId, ':mins' => $earlyThresholdMinutes] + $sampleExclusionParams);
        $sampleEarly = $stEarly->fetch(PDO::FETCH_ASSOC) ?: null;

        // Se não achou com >60%, pega a execução em andamento mais antiga de hoje
        if (!$sampleEarly) {
            $stEarlyFallback = $pdo->prepare("
                SELECT r.id run_id, r.user_id, r.status, r.started_at, r.finished_at, u.nome, u.email
                FROM automation_flow_runs r
                LEFT JOIN users u ON u.id = r.user_id
                WHERE r.flow_id = :fid AND r.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                {$sampleExclusionSql}
                ORDER BY r.started_at ASC
                LIMIT 1
            ");
            $stEarlyFallback->execute([':fid' => $flowId] + $sampleExclusionParams);
            $sampleEarly = $stEarlyFallback->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Amostra B: Lead que entrou mais recentemente (para comparar com a Amostra A)
        $lateExclusions = $previousSampleExclusions;
        if ($sampleEarly) {
            $lateExclusions['run_ids'][] = (int)$sampleEarly['run_id'];
            if ((int)($sampleEarly['user_id'] ?? 0) > 0) {
                $lateExclusions['user_ids'][] = (int)$sampleEarly['user_id'];
            }
        }
        [$lateExclusionSql, $lateExclusionParams] = automation_diagnostics_exclusion_sql($lateExclusions, 'r', 'sample_late');
        $stLate = $pdo->prepare("
            SELECT r.id run_id, r.user_id, r.status, r.started_at, r.finished_at, u.nome, u.email
            FROM automation_flow_runs r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.flow_id = :fid AND r.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            {$lateExclusionSql}
            ORDER BY r.started_at DESC
            LIMIT 1
        ");
        $stLate->execute([':fid' => $flowId] + $lateExclusionParams);
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
        [$benchmarkExclusionSql, $benchmarkExclusionParams] = automation_diagnostics_exclusion_sql($previousSampleExclusions, 'r', 'bench_prev');
        $stCompleted = $pdo->prepare("
            SELECT r.id run_id, r.user_id, r.started_at, r.finished_at, u.nome, u.email,
                   TIMESTAMPDIFF(MINUTE, r.started_at, r.finished_at) AS duration_minutes
            FROM automation_flow_runs r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.flow_id = :fid AND r.status = 'completed' AND r.finished_at IS NOT NULL
              AND r.finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              {$benchmarkExclusionSql}
            ORDER BY r.finished_at DESC
            LIMIT 5
        ");
        $stCompleted->execute([':fid' => $flowId] + $benchmarkExclusionParams);
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

        if (count($lastCompleted) >= 3) {
            $durations = array_map(fn($row) => max(0, (int)$row['duration_minutes']), $lastCompleted);
            $avgDuration = array_sum($durations) / count($durations);
            $maxDuration = max($durations);
            $minDuration = min($durations);

            $benchmarkAnalysis['avg_duration_minutes'] = round($avgDuration, 1);
            $benchmarkAnalysis['max_duration_minutes'] = $maxDuration;
            $benchmarkAnalysis['min_duration_minutes'] = $minDuration;

            // Medição real de latência de fila (diferença entre a hora agendada available_at e o início real started_at)
            $stQueueDelay = $pdo->prepare("
                SELECT AVG(TIMESTAMPDIFF(MINUTE, j.available_at, s.started_at)) AS avg_queue_delay,
                       COUNT(*) AS sample_count
                FROM automation_flow_steps s
                JOIN automation_flow_jobs j ON j.id = s.job_id
                JOIN automation_flow_runs r ON r.id = s.run_id
                WHERE r.flow_id = :fid 
                  AND s.started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND j.available_at IS NOT NULL
                  AND s.started_at >= j.available_at
            ");
            $stQueueDelay->execute([':fid' => $flowId]);
            $queueDelayInfo = $stQueueDelay->fetch(PDO::FETCH_ASSOC) ?: [];
            $avgQueueDelay = (float)($queueDelayInfo['avg_queue_delay'] ?? 0);
            $queueSamples  = (int)($queueDelayInfo['sample_count'] ?? 0);

            $benchmarkAnalysis['avg_queue_delay_minutes'] = round($avgQueueDelay, 1);

            // Alerta crítico apenas se a latência REAL da fila/cron exceder 45 minutos em média
            if ($queueSamples >= 3 && $avgQueueDelay > 45) {
                $diffDelay = round($avgQueueDelay);
                $issues[] = [
                    'source' => 'sla_benchmark',
                    'type' => 'sla_excessive_delay',
                    'message' => "Atraso Crítico na Fila: O Cron está levando em média {$diffDelay} min para executar as etapas após o horário agendado.",
                    'avg_minutes' => round($avgQueueDelay),
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
                'user_id' => (int)($sampleEarly['user_id'] ?? 0),
                'started_at' => (string)$sampleEarly['started_at'],
                'status' => (string)$sampleEarly['status'],
                'analysis' => $earlyAnalysis,
            ] : null,
            'sample_late' => $sampleLate ? [
                'user' => $sampleLate['nome'] ?: $sampleLate['email'],
                'run_id' => (int)$sampleLate['run_id'],
                'user_id' => (int)($sampleLate['user_id'] ?? 0),
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

    // Limpa diagnósticos residuais de fluxos pausados, rascunhos ou excluídos
    try {
        $pdo->exec("
            DELETE FROM automation_flow_diagnostics 
            WHERE flow_id IN (SELECT id FROM automation_flows WHERE status != 'active')
        ");
    } catch (Throwable $e) {}

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
 * Fotografia do estado atual da fila de um canal: quanto está pendente, há quanto
 * tempo a etapa mais antiga espera, e quanto foi processado/falhou na última hora
 * (para estimar tempo restante de esvaziamento).
 */
function automation_channel_queue_snapshot(PDO $pdo, string $channel): array
{
    $settings = automation_flow_channel_settings($pdo, $channel);

    $statusCounts = ['queued' => 0, 'retry' => 0, 'scheduled' => 0, 'processing' => 0];
    $st = $pdo->prepare("SELECT status, COUNT(*) c FROM automation_flow_jobs WHERE channel=:c AND status IN ('queued','retry','scheduled','processing') GROUP BY status");
    $st->execute(['c' => $channel]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $statusCounts[(string)$row['status']] = (int)$row['c'];
    $pending = $statusCounts['queued'] + $statusCounts['retry'] + $statusCounts['scheduled'];

    $oldestMinutes = null;
    $st = $pdo->prepare("SELECT MIN(available_at) FROM automation_flow_jobs WHERE channel=:c AND status IN ('queued','retry','scheduled')");
    $st->execute(['c' => $channel]);
    $oldestStr = $st->fetchColumn();
    if ($oldestStr) $oldestMinutes = max(0, (int)round((time() - strtotime((string)$oldestStr)) / 60));

    $st = $pdo->prepare("
        SELECT SUM(s.status='completed') completed, SUM(s.status='failed') failed, SUM(s.status='canceled') canceled
          FROM automation_flow_steps s
          JOIN automation_flow_jobs j ON j.id = s.job_id
         WHERE j.channel = :c AND s.finished_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $st->execute(['c' => $channel]);
    $throughput = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $completedLastHour = (int)($throughput['completed'] ?? 0);
    $failedLastHour = (int)($throughput['failed'] ?? 0);
    $canceledLastHour = (int)($throughput['canceled'] ?? 0);

    $ratePerMinute = $completedLastHour / 60;
    $etaMinutes = ($pending > 0 && $ratePerMinute > 0.0001) ? (int)ceil($pending / $ratePerMinute) : null;

    return [
        'channel' => $channel,
        'enabled' => $settings['enabled'],
        'min_interval_ms' => $settings['min_interval_ms'],
        'pending' => $pending,
        'queued' => $statusCounts['queued'],
        'retry' => $statusCounts['retry'],
        'scheduled' => $statusCounts['scheduled'],
        'processing' => $statusCounts['processing'],
        'oldest_pending_minutes' => $oldestMinutes,
        'completed_last_hour' => $completedLastHour,
        'failed_last_hour' => $failedLastHour,
        'canceled_last_hour' => $canceledLastHour,
        'eta_minutes' => $etaMinutes,
    ];
}

/**
 * Fotografia da fila de todos os canais (general + os 6 canais de disparo).
 */
function automation_channel_queue_overview(PDO $pdo): array
{
    $out = [];
    foreach (array_merge(['general'], automation_flow_channels()) as $channel) {
        try {
            $out[$channel] = automation_channel_queue_snapshot($pdo, $channel);
        } catch (Throwable $e) {
            $out[$channel] = null;
        }
    }
    return $out;
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
            $thresholdMinutes = 30;
            if ($jStatus === 'retry') {
                // Uma etapa em retry pode legitimamente esperar até o backoff maximo configurado
                // do canal antes de ser reprocessada — só é "travada" se passar bem desse prazo.
                $jobChannel = (string)($j['channel'] ?? 'general');
                $channelSettings = automation_flow_channel_settings($pdo, $jobChannel);
                $thresholdMinutes = max(10, ($channelSettings['backoff_max_seconds'] / 60) + 5);
            }
            if ($delayedMinutes > $thresholdMinutes) {
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
