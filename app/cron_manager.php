<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function cron_manager_base_definitions(): array {
    return [
        'whatsapp_ai' => [
            'label' => 'IA WhatsApp',
            'description' => 'Processa pacotes de mensagens, alertas, tags e análises da IA.',
            'script' => __DIR__ . '/../cron/processar_whatsapp_ai.php',
            'interval' => 1,
            'timeout' => 300,
        ],
        'whatsapp_grupos' => [
            'label' => 'Grupos WhatsApp',
            'description' => 'Processa acoes programadas, mensagens e operacoes de campanhas em grupos de WhatsApp.',
            'script' => __DIR__ . '/../cron/processar_whatsapp_grupos.php',
            'interval' => 1,
            'timeout' => 300,
        ],
        'reagendamentos_live' => [
            'label' => 'Reagendamentos de live',
            'description' => 'Envia lembretes e encerra reagendamentos vencidos.',
            'script' => __DIR__ . '/../cron/processar_reagendamentos_live.php',
            'interval' => 1,
            'timeout' => 600,
        ],
        'lives_turma' => [
            'label' => 'Avisos de live por turma',
            'description' => 'Processa turmas com horário de disparo atingido.',
            'script' => __DIR__ . '/../cron/processar_lives.php',
            'interval' => 1,
            'timeout' => 120,
        ],
        'agendamentos_retorno' => [
            'label' => 'Agendamentos de retorno',
            'description' => 'Processa contatos e disparos de retorno vencidos.',
            'script' => __DIR__ . '/../cron/processar_agendamentos_retorno.php',
            'interval' => 1,
            'timeout' => 300,
        ],
        'metricas_negocio' => [
            'label' => 'Metricas do negocio',
            'description' => 'Sincroniza Meta Ads, reconcilia vendas Hotmart e recalcula atribuicoes.',
            'script' => __DIR__ . '/../cron/processar_metricas_negocio.php',
            'interval' => 30,
            'timeout' => 900,
        ],
        'fluxos_push' => [
            'label' => 'Fluxos de notificações push',
            'description' => 'Processa em lotes as etapas vencidas dos fluxos do aplicativo.',
            'script' => __DIR__ . '/../cron/processar_fluxos_push.php',
            'interval' => 1,
            'timeout' => 120,
        ],
        'automacoes' => [
            'label' => 'Automacoes centralizadas',
            'description' => 'Processa etapas vencidas dos fluxos unificados de email, push e integracoes.',
            'script' => __DIR__ . '/../cron/processar_automacoes.php',
            'interval' => 1,
            'timeout' => 180,
        ],
        'torpedo_voz' => [
            'label' => 'Torpedo de Voz',
            'description' => 'Processa fila de chamadas de voz, campanhas e eventos pendentes.',
            'script' => __DIR__ . '/../cron/processar_torpedo_voz.php',
            'interval' => 1,
            'timeout' => 300,
        ],
        'email_marketing' => [
            'label' => 'E-mail marketing',
            'description' => 'Processa campanhas e etapas de e-mail vencidas pelo Amazon SES.',
            'script' => __DIR__ . '/../cron/processar_emails.php',
            'interval' => 1,
            'timeout' => 120,
        ],
        'meta_leads_qualificados' => [
            'label' => 'Meta leads qualificados',
            'description' => 'Envia eventos CRM de leads qualificados para a API de Conversoes da Meta.',
            'script' => __DIR__ . '/../cron/processar_meta_leads_qualificados.php',
            'interval' => 1,
            'timeout' => 300,
        ],
    ];
}

function cron_manager_definitions(): array {
    $definitions = cron_manager_base_definitions();
    try {
        $pdo = getPDO();
        $rows = $pdo->query("
            SELECT task_key, label, description, script_file, default_interval_minutes, timeout_seconds
              FROM cron_custom_definitions
             WHERE active=1
             ORDER BY task_key
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $scriptFile = basename((string)$row['script_file']);
            $scriptPath = realpath(__DIR__ . '/../cron/' . $scriptFile);
            $cronDir = realpath(__DIR__ . '/../cron');
            if (!$scriptPath || !$cronDir || dirname($scriptPath) !== $cronDir || strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $definitions[(string)$row['task_key']] = [
                'label' => (string)$row['label'],
                'description' => (string)($row['description'] ?? ''),
                'script' => $scriptPath,
                'interval' => max(1, (int)$row['default_interval_minutes']),
                'timeout' => max(60, (int)$row['timeout_seconds']),
                'custom' => true,
                'script_file' => $scriptFile,
            ];
        }
    } catch (Throwable $e) {
        // A tabela ainda pode nao existir durante a primeira instalacao.
    }
    return $definitions;
}

function cron_manager_ensure_tables(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cron_managed_tasks (
            task_key VARCHAR(80) NOT NULL PRIMARY KEY,
            label VARCHAR(180) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            mode VARCHAR(30) NOT NULL DEFAULT 'redundant',
            primary_source VARCHAR(20) NOT NULL DEFAULT 'vps',
            interval_minutes INT NOT NULL DEFAULT 1,
            fallback_after_minutes INT NOT NULL DEFAULT 3,
            timeout_seconds INT NOT NULL DEFAULT 300,
            next_run_at DATETIME NULL,
            running_until DATETIME NULL,
            running_token VARCHAR(64) NULL,
            last_attempt_at DATETIME NULL,
            last_started_at DATETIME NULL,
            last_finished_at DATETIME NULL,
            last_success_at DATETIME NULL,
            last_source VARCHAR(20) NULL,
            last_status VARCHAR(30) NULL,
            last_duration_ms INT NULL,
            last_message TEXT NULL,
            total_runs BIGINT NOT NULL DEFAULT 0,
            total_success BIGINT NOT NULL DEFAULT 0,
            total_errors BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_cron_tasks_due (enabled, next_run_at),
            KEY idx_cron_tasks_running (running_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cron_managed_runs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            task_key VARCHAR(80) NOT NULL,
            source VARCHAR(20) NOT NULL,
            trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            status VARCHAR(30) NOT NULL DEFAULT 'running',
            run_token VARCHAR(64) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            duration_ms INT NULL,
            http_ip VARCHAR(64) NULL,
            output_text MEDIUMTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cron_run_token (run_token),
            KEY idx_cron_runs_task_started (task_key, started_at),
            KEY idx_cron_runs_status (status),
            KEY idx_cron_runs_source (source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cron_agent_heartbeats (
            source VARCHAR(20) NOT NULL PRIMARY KEY,
            last_seen_at DATETIME NOT NULL,
            last_task VARCHAR(80) NULL,
            last_result VARCHAR(40) NULL,
            remote_ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            total_requests BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cron_custom_definitions (
            task_key VARCHAR(80) NOT NULL PRIMARY KEY,
            label VARCHAR(180) NOT NULL,
            description VARCHAR(500) NULL,
            script_file VARCHAR(190) NOT NULL,
            default_interval_minutes INT NOT NULL DEFAULT 1,
            timeout_seconds INT NOT NULL DEFAULT 300,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cron_custom_script (script_file)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $insert = $pdo->prepare("
        INSERT INTO cron_managed_tasks
            (task_key, label, enabled, mode, primary_source, interval_minutes,
             fallback_after_minutes, timeout_seconds, next_run_at)
        VALUES
            (:task_key, :label, 1, 'redundant', 'vps', :interval_minutes, 3, :timeout_seconds, NOW())
        ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            timeout_seconds = IF(timeout_seconds <= 0, VALUES(timeout_seconds), timeout_seconds)
    ");
    foreach (cron_manager_definitions() as $key => $definition) {
        $insert->execute([
            ':task_key' => $key,
            ':label' => $definition['label'],
            ':interval_minutes' => $definition['interval'],
            ':timeout_seconds' => $definition['timeout'],
        ]);
    }

    try {
        $pdo->exec("
            UPDATE cron_managed_tasks
               SET timeout_seconds = 120
             WHERE task_key = 'lives_turma'
               AND timeout_seconds > 120
        ");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("
            UPDATE cron_managed_tasks
               SET interval_minutes = 1,
                   next_run_at = LEAST(COALESCE(next_run_at, NOW()), NOW())
             WHERE task_key = 'meta_leads_qualificados'
               AND interval_minutes > 1
        ");
    } catch (Throwable $e) {}

    cron_manager_recover_expired_runs($pdo);

    if (cron_manager_token($pdo) === '') {
        cron_manager_rotate_token($pdo);
    }
    $ensured = true;
}

function cron_manager_available_scripts(PDO $pdo): array {
    cron_manager_ensure_tables($pdo);
    $used = [];
    foreach (cron_manager_definitions() as $definition) {
        if (!empty($definition['script'])) {
            $used[basename((string)$definition['script'])] = true;
        }
    }

    $files = glob(__DIR__ . '/../cron/*.php') ?: [];
    $available = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (!isset($used[$name])) $available[] = $name;
    }
    sort($available, SORT_NATURAL | SORT_FLAG_CASE);
    return $available;
}

function cron_manager_create_custom_task(PDO $pdo, array $data): string {
    cron_manager_ensure_tables($pdo);

    $taskKey = strtolower(trim((string)($data['task_key'] ?? '')));
    $label = trim((string)($data['label'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $scriptFile = basename(trim((string)($data['script_file'] ?? '')));
    $interval = max(1, min(1440, (int)($data['interval_minutes'] ?? 1)));
    $timeout = max(60, min(7200, (int)($data['timeout_seconds'] ?? 300)));

    if (!preg_match('/^[a-z][a-z0-9_]{2,79}$/', $taskKey)) {
        throw new InvalidArgumentException('Use uma chave com letras minusculas, numeros e underline, iniciando por letra.');
    }
    if ($label === '' || strlen($label) > 180) {
        throw new InvalidArgumentException('Informe um nome de ate 180 caracteres.');
    }
    if (strlen($description) > 500) {
        throw new InvalidArgumentException('A descricao deve ter no maximo 500 caracteres.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $scriptFile)) {
        throw new InvalidArgumentException('Selecione um arquivo PHP valido.');
    }

    $cronDir = realpath(__DIR__ . '/../cron');
    $scriptPath = realpath(__DIR__ . '/../cron/' . $scriptFile);
    if (!$cronDir || !$scriptPath || dirname($scriptPath) !== $cronDir || strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION)) !== 'php') {
        throw new InvalidArgumentException('O arquivo precisa existir dentro da pasta cron.');
    }
    if (!in_array($scriptFile, cron_manager_available_scripts($pdo), true)) {
        throw new InvalidArgumentException('Esse arquivo ja esta cadastrado ou nao esta disponivel.');
    }
    if (isset(cron_manager_definitions()[$taskKey])) {
        throw new InvalidArgumentException('Essa chave de rotina ja existe.');
    }

    $pdo->beginTransaction();
    try {
        $custom = $pdo->prepare("
            INSERT INTO cron_custom_definitions
                (task_key, label, description, script_file, default_interval_minutes, timeout_seconds, active)
            VALUES
                (:task_key, :label, :description, :script_file, :interval_minutes, :timeout_seconds, 1)
        ");
        $custom->execute([
            ':task_key' => $taskKey,
            ':label' => $label,
            ':description' => $description !== '' ? $description : null,
            ':script_file' => $scriptFile,
            ':interval_minutes' => $interval,
            ':timeout_seconds' => $timeout,
        ]);

        $task = $pdo->prepare("
            INSERT INTO cron_managed_tasks
                (task_key, label, enabled, mode, primary_source, interval_minutes,
                 fallback_after_minutes, timeout_seconds, next_run_at)
            VALUES
                (:task_key, :label, 1, 'redundant', 'vps', :interval_minutes, 3, :timeout_seconds, NOW())
        ");
        $task->execute([
            ':task_key' => $taskKey,
            ':label' => $label,
            ':interval_minutes' => $interval,
            ':timeout_seconds' => $timeout,
        ]);
        $pdo->commit();
        return $taskKey;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Finaliza execucoes que perderam o processo PHP antes de chamar
 * cron_manager_finish(). Isso impede que o painel permaneça em "Executando"
 * depois do limite configurado para a rotina.
 */
function cron_manager_recover_expired_runs(PDO $pdo): int {
    $recovered = 0;
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query("
            SELECT task_key, running_token, last_started_at
              FROM cron_managed_tasks
             WHERE running_token IS NOT NULL
               AND (
                    (running_until IS NOT NULL AND running_until <= NOW())
                    OR (
                        last_started_at IS NOT NULL
                        AND DATE_ADD(last_started_at, INTERVAL GREATEST(60, LEAST(7200, timeout_seconds)) SECOND) <= NOW()
                    )
               )
             FOR UPDATE
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $finishRun = $pdo->prepare("
            UPDATE cron_managed_runs
               SET status='timeout',
                   finished_at=NOW(),
                   duration_ms=TIMESTAMPDIFF(MICROSECOND, started_at, NOW()) DIV 1000,
                   error_message='Execucao interrompida ou excedeu o tempo limite.'
             WHERE run_token=:run_token
               AND status='running'
        ");
        $releaseTask = $pdo->prepare("
            UPDATE cron_managed_tasks
               SET running_until=NULL,
                   running_token=NULL,
                   last_finished_at=NOW(),
                   last_status='timeout',
                   last_duration_ms=TIMESTAMPDIFF(MICROSECOND, last_started_at, NOW()) DIV 1000,
                   last_message='Execucao interrompida ou excedeu o tempo limite.',
                   total_errors=total_errors+1
             WHERE task_key=:task_key
               AND running_token=:run_token
        ");

        foreach ($rows as $row) {
            $runToken = (string)($row['running_token'] ?? '');
            if ($runToken === '') continue;

            $finishRun->execute([':run_token' => $runToken]);
            $releaseTask->execute([
                ':task_key' => (string)$row['task_key'],
                ':run_token' => $runToken,
            ]);
            if ($releaseTask->rowCount() === 1) $recovered++;
        }

        $pdo->commit();
        return $recovered;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cron_manager_heartbeat(PDO $pdo, string $source, string $taskKey, string $result = 'received', bool $countRequest = true): void {
    $st = $pdo->prepare("
        INSERT INTO cron_agent_heartbeats
            (source, last_seen_at, last_task, last_result, remote_ip, user_agent, total_requests)
        VALUES
            (:source, NOW(), :last_task, :last_result, :remote_ip, :user_agent, 1)
        ON DUPLICATE KEY UPDATE
            last_seen_at=NOW(),
            last_task=VALUES(last_task),
            last_result=VALUES(last_result),
            remote_ip=VALUES(remote_ip),
            user_agent=VALUES(user_agent),
            total_requests=total_requests+:request_increment
    ");
    $st->execute([
        ':source' => $source,
        ':last_task' => $taskKey,
        ':last_result' => $result,
        ':remote_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
        ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ':request_increment' => $countRequest ? 1 : 0,
    ]);
}

function cron_manager_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT valor FROM settings WHERE chave=:key LIMIT 1");
        $st->execute([':key' => $key]);
        $value = $st->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function cron_manager_token(PDO $pdo): string {
    return trim(cron_manager_setting($pdo, 'cron_manager_token', ''));
}

function cron_manager_rotate_token(PDO $pdo): string {
    $token = bin2hex(random_bytes(32));
    set_setting('cron_manager_token', $token);
    return $token;
}

function cron_manager_source_allowed(array $task, string $source, bool $force = false): array {
    if ($force) return [true, 'manual'];
    if (empty($task['enabled']) || (string)$task['mode'] === 'disabled') {
        return [false, 'disabled'];
    }

    $mode = (string)$task['mode'];
    if ($mode === 'vps') return [$source === 'vps', $source === 'vps' ? 'primary' : 'wrong_source'];
    if ($mode === 'hosting') return [$source === 'hosting', $source === 'hosting' ? 'primary' : 'wrong_source'];
    if ($mode !== 'redundant') return [false, 'invalid_mode'];

    $primary = (string)$task['primary_source'];
    if ($source === $primary) return [true, 'primary'];

    $lastSuccess = !empty($task['last_success_at']) ? strtotime((string)$task['last_success_at']) : false;
    $lastAttempt = !empty($task['last_attempt_at']) ? strtotime((string)$task['last_attempt_at']) : false;
    $intervalSeconds = max(1, (int)$task['interval_minutes']) * 60;
    $staleAfter = max(1, (int)$task['interval_minutes']) + max(1, (int)$task['fallback_after_minutes']);
    $isStale = !$lastSuccess || $lastSuccess <= time() - ($staleAfter * 60);
    $retryDue = !$lastAttempt || $lastAttempt <= time() - $intervalSeconds;
    $canFallback = $isStale && $retryDue;
    return [$canFallback, $canFallback ? 'fallback' : ($isStale ? 'not_due' : 'primary_healthy')];
}

function cron_manager_claim(PDO $pdo, string $taskKey, string $source, bool $force = false): array {
    $definitions = cron_manager_definitions();
    if (!isset($definitions[$taskKey])) {
        return ['claimed' => false, 'reason' => 'unknown_task'];
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM cron_managed_tasks WHERE task_key=:task_key LIMIT 1 FOR UPDATE");
        $st->execute([':task_key' => $taskKey]);
        $task = $st->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            $pdo->rollBack();
            return ['claimed' => false, 'reason' => 'task_not_registered'];
        }

        [$allowed, $role] = cron_manager_source_allowed($task, $source, $force);
        if (!$allowed) {
            $pdo->rollBack();
            return ['claimed' => false, 'reason' => $role, 'task' => $task];
        }

        $now = time();
        $runningUntil = !empty($task['running_until']) ? strtotime((string)$task['running_until']) : false;
        if ($runningUntil && $runningUntil > $now) {
            $pdo->rollBack();
            return ['claimed' => false, 'reason' => 'already_running', 'task' => $task];
        }

        $nextRun = !empty($task['next_run_at']) ? strtotime((string)$task['next_run_at']) : false;
        if (!$force && $role === 'primary' && $nextRun && $nextRun > $now) {
            $pdo->rollBack();
            return ['claimed' => false, 'reason' => 'not_due', 'task' => $task];
        }

        $runToken = bin2hex(random_bytes(32));
        $timeout = max(60, min(7200, (int)$task['timeout_seconds']));
        $interval = max(1, min(1440, (int)$task['interval_minutes']));
        $claim = $pdo->prepare("
            UPDATE cron_managed_tasks
               SET running_until=DATE_ADD(NOW(), INTERVAL :timeout SECOND),
                   running_token=:run_token,
                   last_attempt_at=NOW(),
                   last_started_at=NOW(),
                   last_source=:source,
                   last_status='running',
                   next_run_at=DATE_ADD(NOW(), INTERVAL :interval_minutes MINUTE),
                   total_runs=total_runs+1
             WHERE task_key=:task_key
        ");
        $claim->execute([
            ':timeout' => $timeout,
            ':run_token' => $runToken,
            ':source' => $source,
            ':interval_minutes' => $interval,
            ':task_key' => $taskKey,
        ]);

        $run = $pdo->prepare("
            INSERT INTO cron_managed_runs
                (task_key, source, trigger_type, status, run_token, started_at, http_ip)
            VALUES
                (:task_key, :source, :trigger_type, 'running', :run_token, NOW(), :http_ip)
        ");
        $run->execute([
            ':task_key' => $taskKey,
            ':source' => $source,
            ':trigger_type' => $force ? 'manual' : ($role === 'fallback' ? 'fallback' : 'scheduled'),
            ':run_token' => $runToken,
            ':http_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
        ]);
        $runId = (int)$pdo->lastInsertId();
        $pdo->commit();

        return [
            'claimed' => true,
            'task' => $task,
            'definition' => $definitions[$taskKey],
            'run_token' => $runToken,
            'run_id' => $runId,
            'role' => $role,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cron_manager_finish(
    PDO $pdo,
    string $taskKey,
    string $runToken,
    int $runId,
    string $status,
    int $durationMs,
    string $output,
    string $error = ''
): void {
    $success = $status === 'success';
    $message = trim($error !== '' ? $error : $output);
    if (strlen($message) > 2000) $message = substr($message, 0, 2000);
    if (strlen($output) > 60000) $output = substr($output, -60000);

    $pdo->beginTransaction();
    try {
        $run = $pdo->prepare("
            UPDATE cron_managed_runs
               SET status=:status, finished_at=NOW(), duration_ms=:duration_ms,
                   output_text=:output_text, error_message=:error_message
             WHERE id=:id AND run_token=:run_token
        ");
        $run->execute([
            ':status' => $status,
            ':duration_ms' => $durationMs,
            ':output_text' => $output !== '' ? $output : null,
            ':error_message' => $error !== '' ? $error : null,
            ':id' => $runId,
            ':run_token' => $runToken,
        ]);

        $task = $pdo->prepare("
            UPDATE cron_managed_tasks
               SET running_until=NULL,
                   running_token=NULL,
                   last_finished_at=NOW(),
                   last_success_at=IF(:success=1, NOW(), last_success_at),
                   last_status=:status,
                   last_duration_ms=:duration_ms,
                   last_message=:last_message,
                   total_success=total_success + IF(:success2=1,1,0),
                   total_errors=total_errors + IF(:success3=1,0,1)
             WHERE task_key=:task_key AND running_token=:run_token
        ");
        $task->execute([
            ':success' => $success ? 1 : 0,
            ':status' => $status,
            ':duration_ms' => $durationMs,
            ':last_message' => $message !== '' ? $message : null,
            ':success2' => $success ? 1 : 0,
            ':success3' => $success ? 1 : 0,
            ':task_key' => $taskKey,
            ':run_token' => $runToken,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cron_manager_execute(PDO $pdo, string $taskKey, string $source, bool $force = false): array {
    cron_manager_ensure_tables($pdo);
    $claim = cron_manager_claim($pdo, $taskKey, $source, $force);
    if (empty($claim['claimed'])) {
        return [
            'ok' => true,
            'executed' => false,
            'task' => $taskKey,
            'source' => $source,
            'reason' => $claim['reason'] ?? 'not_claimed',
        ];
    }

    $started = microtime(true);
    $status = 'success';
    $error = '';
    $output = '';
    $definition = $claim['definition'];
    $runToken = (string)$claim['run_token'];
    $runId = (int)$claim['run_id'];
    $role = (string)$claim['role'];
    @set_time_limit(max(60, (int)$definition['timeout']));

    ob_start();
    try {
        $GLOBALS['cron_manager_source'] = $source;
        $GLOBALS['cron_manager_task_key'] = $taskKey;
        require $definition['script'];
    } catch (Throwable $e) {
        $status = 'error';
        $error = $e->getMessage();
    } finally {
        $output = trim((string)ob_get_clean());
        unset($GLOBALS['cron_manager_source'], $GLOBALS['cron_manager_task_key']);
    }

    $durationMs = (int)round((microtime(true) - $started) * 1000);
    cron_manager_finish(
        $pdo,
        $taskKey,
        $runToken,
        $runId,
        $status,
        $durationMs,
        $output,
        $error
    );

    return [
        'ok' => $status === 'success',
        'executed' => true,
        'task' => $taskKey,
        'source' => $source,
        'role' => $role,
        'status' => $status,
        'duration_ms' => $durationMs,
        'output' => $output,
        'error' => $error,
    ];
}

function cron_manager_tasks(PDO $pdo): array {
    cron_manager_ensure_tables($pdo);
    $rows = $pdo->query("SELECT * FROM cron_managed_tasks ORDER BY task_key")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $definitions = cron_manager_definitions();
    foreach ($rows as &$row) {
        $definition = $definitions[(string)$row['task_key']] ?? [];
        $row['description'] = $definition['description'] ?? '';
    }
    unset($row);
    return $rows;
}

function cron_manager_runs(PDO $pdo, int $limit = 100): array {
    cron_manager_ensure_tables($pdo);
    $limit = max(1, min(500, $limit));
    return $pdo->query("
        SELECT * FROM cron_managed_runs
         ORDER BY id DESC
         LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cron_manager_heartbeats(PDO $pdo): array {
    cron_manager_ensure_tables($pdo);
    $rows = $pdo->query("SELECT * FROM cron_agent_heartbeats ORDER BY source")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) $out[(string)$row['source']] = $row;
    return $out;
}

function cron_manager_save_task(PDO $pdo, string $taskKey, array $data): void {
    if (!isset(cron_manager_definitions()[$taskKey])) {
        throw new InvalidArgumentException('Rotina de cron desconhecida.');
    }
    $mode = (string)($data['mode'] ?? 'redundant');
    if (!in_array($mode, ['disabled', 'vps', 'hosting', 'redundant'], true)) $mode = 'redundant';
    $primary = (string)($data['primary_source'] ?? 'vps');
    if (!in_array($primary, ['vps', 'hosting'], true)) $primary = 'vps';

    $st = $pdo->prepare("
        UPDATE cron_managed_tasks
           SET enabled=:enabled,
               mode=:mode,
               primary_source=:primary_source,
               interval_minutes=:interval_minutes,
               fallback_after_minutes=:fallback_after_minutes,
               next_run_at=LEAST(COALESCE(next_run_at, NOW()), NOW())
         WHERE task_key=:task_key
    ");
    $st->execute([
        ':enabled' => $mode === 'disabled' ? 0 : 1,
        ':mode' => $mode,
        ':primary_source' => $primary,
        ':interval_minutes' => max(1, min(1440, (int)($data['interval_minutes'] ?? 1))),
        ':fallback_after_minutes' => max(1, min(1440, (int)($data['fallback_after_minutes'] ?? 3))),
        ':task_key' => $taskKey,
    ]);
}
