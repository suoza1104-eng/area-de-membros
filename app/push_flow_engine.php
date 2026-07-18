<?php
declare(strict_types=1);

require_once __DIR__ . '/push_flows.php';
require_once __DIR__ . '/push_notifications.php';

function push_flow_engine_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    push_flows_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_code VARCHAR(100) NOT NULL,
        user_id INT NOT NULL,
        source_key CHAR(64) NOT NULL,
        payload_json LONGTEXT NULL,
        matched_flows INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_flow_event_source (source_key),
        KEY idx_push_flow_event_user (user_id),
        KEY idx_push_flow_event_code (event_code),
        KEY idx_push_flow_event_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_flow_run_event (flow_id, version_id, event_id),
        KEY idx_push_flow_run_status (status),
        KEY idx_push_flow_run_user (user_id),
        KEY idx_push_flow_run_flow (flow_id),
        KEY idx_push_flow_run_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        node_id VARCHAR(80) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 4,
        lease_token CHAR(32) NULL,
        lease_until DATETIME NULL,
        input_json LONGTEXT NULL,
        output_json LONGTEXT NULL,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_flow_job_node (run_id, node_id),
        KEY idx_push_flow_job_due (status, available_at),
        KEY idx_push_flow_job_lease (lease_until),
        KEY idx_push_flow_job_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_steps (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        job_id BIGINT UNSIGNED NOT NULL,
        node_id VARCHAR(80) NOT NULL,
        node_type VARCHAR(30) NOT NULL,
        attempt INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL,
        output_json LONGTEXT NULL,
        error_message VARCHAR(1000) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        KEY idx_push_flow_step_run (run_id),
        KEY idx_push_flow_step_job (job_id),
        KEY idx_push_flow_step_status (status),
        KEY idx_push_flow_step_started (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_live_batches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        trigger_node_id VARCHAR(80) NOT NULL,
        turma_codigo VARCHAR(100) NOT NULL,
        live_at DATETIME NOT NULL,
        reminder_at DATETIME NOT NULL,
        advance_value INT UNSIGNED NOT NULL,
        advance_unit VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        cursor_user_id INT UNSIGNED NOT NULL DEFAULT 0,
        total_candidates INT UNSIGNED NOT NULL DEFAULT 0,
        enqueued_runs INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        UNIQUE KEY uk_push_flow_live_batch (flow_id,version_id,trigger_node_id,turma_codigo,live_at,advance_value,advance_unit),
        KEY idx_push_flow_live_due (status,reminder_at),
        KEY idx_push_flow_live_at (live_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_flow_live_recipients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch_id BIGINT UNSIGNED NOT NULL,
        flow_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        turma_codigo VARCHAR(100) NOT NULL,
        live_at DATETIME NOT NULL,
        advance_value INT UNSIGNED NOT NULL,
        advance_unit VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_flow_live_recipient (flow_id,user_id,turma_codigo,live_at,advance_value,advance_unit),
        KEY idx_push_flow_live_recipient_batch (batch_id),
        KEY idx_push_flow_live_recipient_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_live_reminder_batches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_kind VARCHAR(20) NOT NULL,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        trigger_node_id VARCHAR(80) NOT NULL,
        turma_codigo VARCHAR(100) NOT NULL,
        live_at DATETIME NOT NULL,
        reminder_at DATETIME NOT NULL,
        advance_value INT UNSIGNED NOT NULL,
        advance_unit VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        cursor_user_id INT UNSIGNED NOT NULL DEFAULT 0,
        total_candidates INT UNSIGNED NOT NULL DEFAULT 0,
        enqueued_runs INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        UNIQUE KEY uk_auto_live_batch (flow_kind,flow_id,version_id,trigger_node_id,turma_codigo,live_at,advance_value,advance_unit),
        KEY idx_auto_live_due (status,reminder_at),
        KEY idx_auto_live_at (live_at),
        KEY idx_auto_live_kind (flow_kind,flow_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_live_reminder_recipients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch_id BIGINT UNSIGNED NOT NULL,
        flow_kind VARCHAR(20) NOT NULL,
        flow_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        turma_codigo VARCHAR(100) NOT NULL,
        live_at DATETIME NOT NULL,
        advance_value INT UNSIGNED NOT NULL,
        advance_unit VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_auto_live_recipient (flow_kind,flow_id,user_id,turma_codigo,live_at,advance_value,advance_unit),
        KEY idx_auto_live_recipient_batch (batch_id),
        KEY idx_auto_live_recipient_user (user_id),
        KEY idx_auto_live_recipient_kind (flow_kind,flow_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function push_flow_engine_enabled(): bool
{
    return push_setting_enabled('push_flow_engine_enabled', false);
}

function push_flow_event_source_key(string $event, int $userId, array $extra): string
{
    $identity = [];
    foreach (['event_id','transaction_code','agendamento_id','device_id','lesson_id','codigo_turma','codigo_live'] as $key) {
        if (isset($extra[$key]) && !is_array($extra[$key]) && (string)$extra[$key] !== '') $identity[$key] = (string)$extra[$key];
    }
    if (!$identity) $identity = $extra;
    $bucket = (int)floor(time() / 60);
    return hash('sha256', $event . '|' . $userId . '|' . json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . $bucket);
}

function push_flow_trigger_matches(array $node, array $user, array $extra, string $event, int $flowId = 0, int $versionId = 0): bool
{
    $config = is_array($node['config'] ?? null) ? $node['config'] : [];
    if ((string)($config['event'] ?? '') !== $event) return false;
    if ($event === 'LIVE_LEMBRETE_AGENDADO' && isset($extra['_scheduled_flow_id'])) {
        $kind = (string)($extra['_scheduled_flow_kind'] ?? 'push');
        if ($kind !== 'push' || (int)$extra['_scheduled_flow_id'] !== $flowId || (int)($extra['_scheduled_version_id'] ?? 0) !== $versionId || (string)($extra['_scheduled_node_id'] ?? '') !== (string)($node['id'] ?? '')) return false;
    }
    $filter = trim((string)($config['filter'] ?? ''));
    if ($filter === '') return true;
    $candidates = [];
    foreach (['codigo_turma','turma_codigo','curso','course'] as $key) {
        if (!empty($extra[$key]) && !is_array($extra[$key])) $candidates[] = (string)$extra[$key];
        if (!empty($user[$key]) && !is_array($user[$key])) $candidates[] = (string)$user[$key];
    }
    if (!empty($extra['turma']['codigo'])) $candidates[] = (string)$extra['turma']['codigo'];
    foreach ($candidates as $candidate) if (strcasecmp(trim($candidate), $filter) === 0) return true;
    return false;
}

function push_flow_capture_event(PDO $pdo, string $event, int $userId, array $extra = []): int
{
    if (!push_flow_engine_enabled() || $userId <= 0 || trim($event) === '') return 0;
    push_flow_engine_ensure_schema($pdo);
    static $flows = null;
    if ($flows === null) $flows = $pdo->query("SELECT f.id flow_id,f.current_version_id,v.graph_json FROM push_flows f JOIN push_flow_versions v ON v.id=f.current_version_id WHERE f.status='active' AND f.current_version_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$flows) return 0;
    $user = buscar_usuario_por_id($userId) ?: ['id'=>$userId];
    $sourceKey = push_flow_event_source_key($event, $userId, $extra);
    $payload = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (strlen((string)$payload) > 65000) {
        $summary = ['_truncated'=>true,'_sha256'=>hash('sha256',(string)$payload)];
        foreach (['event_id','transaction_code','agendamento_id','device_id','lesson_id','codigo_turma','codigo_live'] as $key) if (isset($extra[$key]) && !is_array($extra[$key])) $summary[$key] = (string)$extra[$key];
        $payload = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO push_flow_events (event_code,user_id,source_key,payload_json) VALUES (:event,:user,:source,:payload)');
        $insert->execute(['event'=>$event,'user'=>$userId,'source'=>$sourceKey,'payload'=>$payload]);
        if ($insert->rowCount() !== 1) { $pdo->rollBack(); return 0; }
        $eventId = (int)$pdo->lastInsertId();
        $matched = 0;
        foreach ($flows as $flow) {
            try { $graph = push_flow_decode_graph((string)$flow['graph_json']); } catch (Throwable $ignored) { continue; }
            $trigger = null;
            foreach ($graph['nodes'] ?? [] as $node) if (($node['type'] ?? '') === 'trigger') { $trigger = $node; break; }
            if (!$trigger || !push_flow_trigger_matches($trigger, $user, $extra, $event, (int)$flow['flow_id'], (int)$flow['current_version_id'])) continue;
            $run = $pdo->prepare("INSERT IGNORE INTO push_flow_runs (flow_id,version_id,event_id,user_id,status) VALUES (:flow,:version,:event,:user,'running')");
            $run->execute(['flow'=>(int)$flow['flow_id'],'version'=>(int)$flow['current_version_id'],'event'=>$eventId,'user'=>$userId]);
            if ($run->rowCount() !== 1) continue;
            $runId = (int)$pdo->lastInsertId();
            $input = json_encode(['event_id'=>$eventId,'event_code'=>$event], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare("INSERT INTO push_flow_jobs (run_id,node_id,status,available_at,input_json) VALUES (:run,:node,'queued',NOW(),:input)")
                ->execute(['run'=>$runId,'node'=>(string)$trigger['id'],'input'=>$input]);
            $matched++;
        }
        $pdo->prepare('UPDATE push_flow_events SET matched_flows=:matched WHERE id=:id')->execute(['matched'=>$matched,'id'=>$eventId]);
        $pdo->commit();
        return $matched;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function push_flow_claim_job(PDO $pdo): ?array
{
    $token = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $st = $pdo->query("SELECT id FROM push_flow_jobs WHERE ((status IN ('queued','retry','scheduled') AND available_at<=NOW()) OR (status='processing' AND lease_until<NOW())) ORDER BY available_at,id LIMIT 1 FOR UPDATE");
        $id = (int)($st ? $st->fetchColumn() : 0);
        if ($id <= 0) { $pdo->commit(); return null; }
        $up = $pdo->prepare("UPDATE push_flow_jobs SET status='processing',attempts=attempts+1,lease_token=:token,lease_until=DATE_ADD(NOW(),INTERVAL 90 SECOND),last_error=NULL WHERE id=:id AND ((status IN ('queued','retry','scheduled') AND available_at<=NOW()) OR (status='processing' AND lease_until<NOW()))");
        $up->execute(['token'=>$token,'id'=>$id]);
        if ($up->rowCount() !== 1) { $pdo->commit(); return null; }
        $pdo->commit();
        $st = $pdo->prepare("SELECT j.*,r.flow_id,r.version_id,r.event_id,r.user_id,r.status run_status,v.graph_json,e.event_code,e.payload_json event_payload FROM push_flow_jobs j JOIN push_flow_runs r ON r.id=j.run_id JOIN push_flow_versions v ON v.id=r.version_id JOIN push_flow_events e ON e.id=r.event_id WHERE j.id=:id AND j.lease_token=:token LIMIT 1");
        $st->execute(['id'=>$id,'token'=>$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function push_flow_graph_node(array $graph, string $nodeId): ?array
{
    foreach ($graph['nodes'] ?? [] as $node) if ((string)($node['id'] ?? '') === $nodeId) return $node;
    return null;
}

function push_flow_next_node(array $graph, string $nodeId, string $handle = 'default'): ?string
{
    foreach ($graph['edges'] ?? [] as $edge) {
        if ((string)($edge['source'] ?? '') !== $nodeId) continue;
        $edgeHandle = (string)($edge['sourceHandle'] ?? 'default');
        if ($edgeHandle === $handle) return (string)($edge['target'] ?? '');
    }
    return null;
}

function push_flow_user_has_tag(PDO $pdo, int $userId, string $tag): bool
{
    $st = $pdo->prepare('SELECT 1 FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=:user AND t.nome=:tag LIMIT 1');
    $st->execute(['user'=>$userId,'tag'=>$tag]);
    return (bool)$st->fetchColumn();
}

function push_flow_user_has_turma(PDO $pdo, int $userId, string $turma, array $user): bool
{
    foreach (['codigo_turma','turma_codigo'] as $key) if (isset($user[$key]) && strcasecmp(trim((string)$user[$key]), $turma) === 0) return true;
    try {
        $st = $pdo->prepare('SELECT 1 FROM inscricao_logs WHERE user_id=:user AND codigo_turma=:turma LIMIT 1');
        $st->execute(['user'=>$userId,'turma'=>$turma]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function push_flow_previous_push_clicked(PDO $pdo, int $runId, int $currentJobId, int $userId): bool
{
    if ($runId <= 0 || $currentJobId <= 0) return false;
    $st=$pdo->prepare("SELECT MAX(s.job_id) FROM push_flow_steps s JOIN push_flow_jobs j ON j.id=s.job_id WHERE s.run_id=:run AND s.node_type='push' AND s.status='completed' AND s.job_id<:job AND j.run_id=:run2");
    $st->execute(['run'=>$runId,'job'=>$currentJobId,'run2'=>$runId]);$pushJobId=(int)$st->fetchColumn();
    if($pushJobId<=0)return false;
    $clicked=$pdo->prepare("SELECT 1 FROM push_notifications n JOIN push_delivery_logs dl ON dl.notification_id=n.id WHERE n.target_type='flow_job' AND n.target_value=:target AND dl.user_id=:user AND dl.clicked_at IS NOT NULL LIMIT 1");
    $clicked->execute(['target'=>'flow_job:'.$pushJobId,'user'=>$userId]);return (bool)$clicked->fetchColumn();
}

function push_flow_evaluate_condition(PDO $pdo, array $config, int $userId, array $user, int $runId = 0, int $currentJobId = 0): bool
{
    $field = (string)($config['field'] ?? ''); $operator = (string)($config['operator'] ?? ''); $value = trim((string)($config['value'] ?? ''));
    if ($field === 'previous_push_clicked') return push_flow_previous_push_clicked($pdo,$runId,$currentJobId,$userId);
    if ($field === 'tag') $match = push_flow_user_has_tag($pdo, $userId, $value);
    elseif ($field === 'turma') $match = push_flow_user_has_turma($pdo, $userId, $value, $user);
    else {
        $actual = mb_strtolower(trim((string)($user['email'] ?? ''))); $wanted = mb_strtolower($value);
        $match = $operator === 'has' || $operator === 'not_has' ? str_contains($actual, $wanted) : $actual === $wanted;
    }
    return in_array($operator, ['not_has','not_equals'], true) ? !$match : $match;
}

function push_flow_wait_until(array $config): string
{
    $duration = max(1, (int)($config['duration'] ?? 1));
    $unit = in_array(($config['unit'] ?? ''), ['minutes','hours','days'], true) ? (string)$config['unit'] : 'hours';
    $due = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $due = $due->modify('+' . $duration . ' ' . $unit);
    if (empty($config['limitWindow'])) return $due->format('Y-m-d H:i:s');
    $start = (string)($config['windowStart'] ?? '08:00'); $end = (string)($config['windowEnd'] ?? '20:00'); $time = $due->format('H:i');
    if ($start < $end) {
        if ($time < $start) $due = new DateTimeImmutable($due->format('Y-m-d') . ' ' . $start, $due->getTimezone());
        elseif ($time > $end) $due = new DateTimeImmutable($due->modify('+1 day')->format('Y-m-d') . ' ' . $start, $due->getTimezone());
    } elseif ($time > $end && $time < $start) {
        $due = new DateTimeImmutable($due->format('Y-m-d') . ' ' . $start, $due->getTimezone());
    }
    return $due->format('Y-m-d H:i:s');
}

function push_flow_dispatch_integration(PDO $pdo, array $config, array $user, array $extra, array $job): array
{
    $provider = (string)($config['provider'] ?? ''); $target = trim((string)($config['target'] ?? ''));
    $event = $target !== '' ? $target : ('PUSH_FLOW_' . (int)$job['flow_id']);
    $extra['push_flow'] = ['flow_id'=>(int)$job['flow_id'],'run_id'=>(int)$job['run_id'],'node_id'=>(string)$job['node_id'],'idempotency_key'=>'flow-job-'.(int)$job['id']];
    $payloadRaw = trim((string)($config['payload'] ?? ''));
    if ($payloadRaw !== '') { $decoded = json_decode($payloadRaw, true); $extra['flow_payload'] = is_array($decoded) ? $decoded : $payloadRaw; }
    if ($provider === 'superfuncionario') {
        require_once __DIR__ . '/superfuncionario_dispatcher.php';
        if (!sf_disparar_evento($pdo, $event, $user, $extra)) throw new RuntimeException('SuperFuncionário não encontrou regra ativa ou recusou o disparo.');
    } elseif ($provider === 'manychat') {
        require_once __DIR__ . '/manychat_dispatcher.php';
        if (!mc_disparar_evento($pdo, $event, $user, $extra)) throw new RuntimeException('ManyChat não encontrou regra ativa ou recusou o disparo.');
    } elseif ($provider === 'webhook') {
        require_once __DIR__ . '/webhook_dispatcher.php';
        if (ctype_digit($target)) {
            $st = $pdo->prepare('SELECT * FROM webhooks WHERE id=:id AND ativo=1 LIMIT 1'); $st->execute(['id'=>(int)$target]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Webhook configurado não foi encontrado ou está inativo.');
            disparar_webhook_configurado($pdo, $row, $event, $user, $extra, true);
        } else disparar_evento_webhooks($pdo, $event, $user, $extra);
    } else throw new RuntimeException('Integração não suportada.');
    return ['provider'=>$provider,'target'=>$target,'event'=>$event];
}

function push_flow_render_template(string $template, array $user, array $extra): string
{
    $liveRaw=(string)($extra['data_live']??$extra['live_at']??'');$date='';$time='';
    if($liveRaw!==''){try{$live=new DateTimeImmutable($liveRaw,new DateTimeZone('America/Sao_Paulo'));$date=$live->format('d/m/Y');$time=$live->format('H:i');}catch(Throwable $ignored){}}
    $name=(string)($user['nome']??'');$email=(string)($user['email']??'');$phone=(string)($user['telefone']??'');
    return strtr($template,[
        '{{nome}}'=>$name,'{{email}}'=>$email,'{{telefone}}'=>$phone,
        '{{nome_url}}'=>rawurlencode($name),'{{email_url}}'=>rawurlencode($email),'{{telefone_url}}'=>rawurlencode($phone),
        '{{turma}}'=>(string)($extra['codigo_turma']??$user['codigo_turma']??$user['turma_codigo']??''),'{{codigo_turma}}'=>(string)($extra['codigo_turma']??''),
        '{{data_live}}'=>$date,'{{hora_live}}'=>$time,'{{codigo_live}}'=>(string)($extra['codigo_live']??''),'{{link_live}}'=>(string)($extra['link_live']??'trilha.php'),
    ]);
}

function push_flow_send_push(PDO $pdo, array $config, int $userId, array $job): array
{
    if (function_exists('usuario_bloqueado_disparos') && usuario_bloqueado_disparos($pdo, $userId)) return ['skipped'=>'user_blocked'];
    push_ensure_schema($pdo);
    $target = 'flow_job:' . (int)$job['id'];
    $st = $pdo->prepare("SELECT id FROM push_notifications WHERE target_type='flow_job' AND target_value=:target ORDER BY id DESC LIMIT 1"); $st->execute(['target'=>$target]); $notificationId = (int)$st->fetchColumn();
    $user=buscar_usuario_por_id($userId)?:['id'=>$userId];$extra=json_decode((string)($job['event_payload']??''),true)?:[];
    $title = mb_substr(trim(push_flow_render_template((string)($config['title'] ?? ''),$user,$extra)), 0, 150); $body = mb_substr(trim(push_flow_render_template((string)($config['body'] ?? ''),$user,$extra)), 0, 500); $clickUrl = push_normalize_click_url(trim(push_flow_render_template((string)($config['clickUrl'] ?? 'trilha.php'),$user,$extra)) ?: 'trilha.php');
    $devicesSt = $pdo->prepare("SELECT * FROM push_devices WHERE user_id=:user AND status='active' AND notification_permission='granted' AND token IS NOT NULL AND token<>''"); $devicesSt->execute(['user'=>$userId]); $devices = $devicesSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($notificationId <= 0) {
        $pdo->prepare("INSERT INTO push_notifications (title,body,click_url,target_type,target_value,total_targets,status,created_by) VALUES (:title,:body,:url,'flow_job',:target,:total,'processing','Motor de fluxos')")
            ->execute(['title'=>$title,'body'=>$body,'url'=>$clickUrl,'target'=>$target,'total'=>count($devices)]);
        $notificationId = (int)$pdo->lastInsertId();
    }
    if (!$devices) { $pdo->prepare("UPDATE push_notifications SET status='sent',finished_at=NOW() WHERE id=:id")->execute(['id'=>$notificationId]); return ['notification_id'=>$notificationId,'devices'=>0,'accepted'=>0,'failed'=>0]; }
    $retryable = 0;
    foreach ($devices as $device) {
        $log = $pdo->prepare('SELECT * FROM push_delivery_logs WHERE notification_id=:notification AND device_id=:device ORDER BY id DESC LIMIT 1'); $log->execute(['notification'=>$notificationId,'device'=>(int)$device['id']]); $delivery = $log->fetch(PDO::FETCH_ASSOC);
        if ($delivery && ($delivery['status'] ?? '') === 'accepted') continue;
        if (!$delivery) { $pdo->prepare("INSERT INTO push_delivery_logs (notification_id,device_id,user_id,status) VALUES (:notification,:device,:user,'queued')")->execute(['notification'=>$notificationId,'device'=>(int)$device['id'],'user'=>$userId]); $deliveryId=(int)$pdo->lastInsertId(); }
        else $deliveryId=(int)$delivery['id'];
        $result = push_send_to_device($pdo,$device,$notificationId,$deliveryId,$title,$body,$clickUrl);
        if (empty($result['accepted']) && empty($result['gone'])) $retryable++;
    }
    $stats = $pdo->prepare("SELECT SUM(status='accepted') accepted,SUM(status IN ('failed','uninstalled')) failed FROM push_delivery_logs WHERE notification_id=:id"); $stats->execute(['id'=>$notificationId]); $counts=$stats->fetch(PDO::FETCH_ASSOC)?:[]; $accepted=(int)($counts['accepted']??0); $failed=(int)($counts['failed']??0);
    $pdo->prepare("UPDATE push_notifications SET accepted_count=:accepted,failed_count=:failed,status=:status,finished_at=IF(:done=1,NOW(),NULL) WHERE id=:id")
        ->execute(['accepted'=>$accepted,'failed'=>$failed,'status'=>$retryable>0?'processing':($failed>0?'sent_with_failures':'sent'),'done'=>$retryable>0?0:1,'id'=>$notificationId]);
    if ($retryable > 0) throw new RuntimeException($retryable . ' dispositivo(s) tiveram falha temporária no Firebase.');
    return ['notification_id'=>$notificationId,'devices'=>count($devices),'accepted'=>$accepted,'failed'=>$failed];
}

function push_flow_schedule_next(PDO $pdo, array $graph, array $job, string $handle, array $input): ?string
{
    $next = push_flow_next_node($graph, (string)$job['node_id'], $handle);
    if (!$next) return null;
    $pdo->prepare("INSERT IGNORE INTO push_flow_jobs (run_id,node_id,status,available_at,input_json) VALUES (:run,:node,'queued',NOW(),:input)")
        ->execute(['run'=>(int)$job['run_id'],'node'=>$next,'input'=>json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR)]);
    return $next;
}

function push_flow_finish_run_if_done(PDO $pdo, int $runId): void
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM push_flow_jobs WHERE run_id=:run AND status IN ('queued','retry','scheduled','processing')"); $st->execute(['run'=>$runId]);
    if ((int)$st->fetchColumn() === 0) $pdo->prepare("UPDATE push_flow_runs SET status='completed',finished_at=NOW() WHERE id=:id AND status='running'")->execute(['id'=>$runId]);
}

function push_flow_process_job(PDO $pdo, array $job): string
{
    if (($job['run_status'] ?? '') !== 'running') { $pdo->prepare("UPDATE push_flow_jobs SET status='skipped',lease_token=NULL,lease_until=NULL WHERE id=:id")->execute(['id'=>(int)$job['id']]); return 'skipped'; }
    $graph = push_flow_decode_graph((string)$job['graph_json']); $node = push_flow_graph_node($graph, (string)$job['node_id']);
    if (!$node) throw new RuntimeException('Bloco não encontrado na versão publicada.');
    $type=(string)$node['type']; $config=is_array($node['config']??null)?$node['config']:[]; $input=json_decode((string)($job['input_json']??''),true)?:[]; $user=buscar_usuario_por_id((int)$job['user_id'])?:['id'=>(int)$job['user_id']]; $extra=json_decode((string)($job['event_payload']??''),true)?:[];
    $step = $pdo->prepare("INSERT INTO push_flow_steps (run_id,job_id,node_id,node_type,attempt,status) VALUES (:run,:job,:node,:type,:attempt,'processing')"); $step->execute(['run'=>(int)$job['run_id'],'job'=>(int)$job['id'],'node'=>(string)$job['node_id'],'type'=>$type,'attempt'=>(int)$job['attempts']]); $stepId=(int)$pdo->lastInsertId();
    try {
        $handle='default'; $output=[];
        if ($type==='condition') { $result=push_flow_evaluate_condition($pdo,$config,(int)$job['user_id'],$user,(int)$job['run_id'],(int)$job['id']); $handle=$result?'yes':'no'; $output=['result'=>$result,'route'=>$handle,'condition_field'=>(string)($config['field']??'')]; }
        elseif ($type==='wait') {
            if (empty($input['_wait_ready'])) {
                $resume=push_flow_wait_until($config); $input['_wait_ready']=true; $input['_wait_until']=$resume;
                $pdo->prepare("UPDATE push_flow_jobs SET status='scheduled',available_at=:resume,input_json=:input,attempts=0,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:token")
                    ->execute(['resume'=>$resume,'input'=>json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>(int)$job['id'],'token'=>$job['lease_token']]);
                $pdo->prepare("UPDATE push_flow_steps SET status='scheduled',output_json=:output,finished_at=NOW() WHERE id=:id")->execute(['output'=>json_encode(['resume_at'=>$resume]),'id'=>$stepId]); return 'scheduled';
            }
            $output=['resumed_at'=>date('c'),'scheduled_for'=>$input['_wait_until']??null];
        } elseif ($type==='action') {
            $tag=trim((string)($config['tag']??'')); $ok=($config['action']??'')==='remove_tag'?remover_tag_usuario((int)$job['user_id'],$tag):adicionar_tag((int)$job['user_id'],$tag,'push_flow',(int)$job['run_id']); if(!$ok)throw new RuntimeException('Não foi possível alterar a tag.'); $output=['action'=>$config['action']??'add_tag','tag'=>$tag];
        } elseif ($type==='integration') $output=push_flow_dispatch_integration($pdo,$config,$user,$extra,$job);
        elseif ($type==='push') $output=push_flow_send_push($pdo,$config,(int)$job['user_id'],$job);
        elseif ($type==='trigger') $output=['event'=>$job['event_code']];
        else throw new RuntimeException('Tipo de bloco não suportado pelo motor.');
        $pdo->beginTransaction();
        $next=push_flow_schedule_next($pdo,$graph,$job,$handle,$input); $output['next_node']=$next;
        $pdo->prepare("UPDATE push_flow_jobs SET status='completed',output_json=:output,lease_token=NULL,lease_until=NULL,last_error=NULL WHERE id=:id AND lease_token=:token")
            ->execute(['output'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR),'id'=>(int)$job['id'],'token'=>$job['lease_token']]);
        $pdo->prepare("UPDATE push_flow_steps SET status='completed',output_json=:output,finished_at=NOW() WHERE id=:id")
            ->execute(['output'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR),'id'=>$stepId]);
        $pdo->commit(); push_flow_finish_run_if_done($pdo,(int)$job['run_id']); return 'completed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=mb_substr($e->getMessage(),0,1000); $attempt=(int)$job['attempts']; $max=(int)$job['max_attempts'];
        if ($attempt < $max) { $delay=min(60,(int)pow(2,max(0,$attempt-1))); $pdo->prepare("UPDATE push_flow_jobs SET status='retry',available_at=DATE_ADD(NOW(),INTERVAL :delay MINUTE),last_error=:error,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:token")->execute(['delay'=>$delay,'error'=>$error,'id'=>(int)$job['id'],'token'=>$job['lease_token']]); $stepStatus='retry'; }
        else { $pdo->prepare("UPDATE push_flow_jobs SET status='failed',last_error=:error,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:token")->execute(['error'=>$error,'id'=>(int)$job['id'],'token'=>$job['lease_token']]); $pdo->prepare("UPDATE push_flow_runs SET status='failed',last_error=:error,finished_at=NOW() WHERE id=:id")->execute(['error'=>$error,'id'=>(int)$job['run_id']]); $stepStatus='failed'; }
        $pdo->prepare("UPDATE push_flow_steps SET status=:status,error_message=:error,finished_at=NOW() WHERE id=:id")->execute(['status'=>$stepStatus,'error'=>$error,'id'=>$stepId]); return $stepStatus;
    }
}

function push_flow_engine_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try { $st=$pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");$st->execute(['column'=>$column]);$cache[$key]=(bool)$st->fetch(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { $cache[$key]=false; }
    }
    return $cache[$key];
}

function push_flow_live_column(PDO $pdo): ?string
{
    foreach (['data_live','live_at','turma_live_at'] as $column) if (push_flow_engine_column_exists($pdo,'turmas',$column)) return $column;
    return null;
}

function push_flow_live_students(PDO $pdo, string $turma, int $afterUserId, int $limit): array
{
    $parts=[];$params=['cursor'=>$afterUserId];
    $userWhere=[];
    if(push_flow_engine_column_exists($pdo,'users','codigo_turma')){$userWhere[]='codigo_turma=:user_turma_1';$params['user_turma_1']=$turma;}
    if(push_flow_engine_column_exists($pdo,'users','turma_codigo')){$userWhere[]='turma_codigo=:user_turma_2';$params['user_turma_2']=$turma;}
    if($userWhere)$parts[]='SELECT id user_id FROM users WHERE '.implode(' OR ',$userWhere);
    try{$table=$pdo->query("SHOW TABLES LIKE 'inscricao_logs'");if($table&&$table->fetchColumn()){$parts[]='SELECT user_id FROM inscricao_logs WHERE codigo_turma=:log_turma';$params['log_turma']=$turma;}}catch(Throwable $ignored){}
    if(!$parts)return [];
    $limit=max(1,min(1000,$limit));$sql='SELECT DISTINCT user_id FROM ('.implode(' UNION ALL ',$parts).') candidates WHERE user_id>:cursor ORDER BY user_id LIMIT '.$limit;
    $st=$pdo->prepare($sql);$st->execute($params);return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
}

function automation_live_reminder_flows(PDO $pdo): array
{
    $flows = [];
    try {
        $rows = $pdo->query("SELECT 'push' flow_kind,f.id flow_id,f.current_version_id version_id,v.graph_json FROM push_flows f JOIN push_flow_versions v ON v.id=f.current_version_id WHERE f.status='active' AND f.current_version_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) $flows[] = $row;
    } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/email_flow_engine.php';
        email_flow_engine_ensure_schema($pdo);
        $rows = $pdo->query("SELECT 'email' flow_kind,f.id flow_id,f.current_version_id version_id,v.graph_json FROM email_flows f JOIN email_flow_versions v ON v.id=f.current_version_id WHERE f.status='active' AND f.current_version_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) $flows[] = $row;
    } catch (Throwable $e) {}
    return $flows;
}

function automation_live_reminder_turmas(PDO $pdo, string $liveColumn, string $filter): array
{
    if ($filter !== '') {
        $st = $pdo->prepare("SELECT codigo,`{$liveColumn}` live_at" . (push_flow_engine_column_exists($pdo, 'turmas', 'codigo_live') ? ',codigo_live' : '') . " FROM turmas WHERE codigo=:codigo AND `{$liveColumn}` IS NOT NULL LIMIT 1");
        $st->execute(['codigo' => $filter]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    return $pdo->query("SELECT codigo,`{$liveColumn}` live_at" . (push_flow_engine_column_exists($pdo, 'turmas', 'codigo_live') ? ',codigo_live' : '') . " FROM turmas WHERE codigo IS NOT NULL AND codigo<>'' AND `{$liveColumn}` IS NOT NULL ORDER BY `{$liveColumn}` ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function push_flow_prepare_live_batches(PDO $pdo): int
{
    push_flow_engine_ensure_schema($pdo);
    $liveColumn = push_flow_live_column($pdo);
    if ($liveColumn === null) return 0;
    $created = 0;
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    foreach (automation_live_reminder_flows($pdo) as $flow) {
        try { $graph = push_flow_decode_graph((string)$flow['graph_json']); } catch (Throwable $ignored) { continue; }
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? '') !== 'trigger' || ($node['config']['event'] ?? '') !== 'LIVE_LEMBRETE_AGENDADO') continue;
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $filter = trim((string)($config['filter'] ?? ''));
            $value = max(1, (int)($config['advanceDuration'] ?? 1));
            $unit = in_array(($config['advanceUnit'] ?? ''), ['minutes','hours','days'], true) ? (string)$config['advanceUnit'] : 'hours';
            foreach (automation_live_reminder_turmas($pdo, $liveColumn, $filter) as $turma) {
                $codigo = trim((string)($turma['codigo'] ?? ''));
                $liveRaw = trim((string)($turma['live_at'] ?? ''));
                if ($codigo === '' || $liveRaw === '') continue;
                try { $liveAt = new DateTimeImmutable($liveRaw, new DateTimeZone('America/Sao_Paulo')); } catch (Throwable $ignored) { continue; }
                if ($liveAt <= $now) continue;
                $reminderAt = $liveAt->modify('-' . $value . ' ' . $unit);
                if ($reminderAt > $now) continue;
                $insert = $pdo->prepare("INSERT IGNORE INTO automation_live_reminder_batches (flow_kind,flow_id,version_id,trigger_node_id,turma_codigo,live_at,reminder_at,advance_value,advance_unit,status) VALUES (:kind,:flow,:version,:node,:turma,:live,:reminder,:advance,:unit,'pending')");
                $insert->execute([
                    'kind' => (string)$flow['flow_kind'],
                    'flow' => (int)$flow['flow_id'],
                    'version' => (int)$flow['version_id'],
                    'node' => (string)$node['id'],
                    'turma' => $codigo,
                    'live' => $liveAt->format('Y-m-d H:i:s'),
                    'reminder' => $reminderAt->format('Y-m-d H:i:s'),
                    'advance' => $value,
                    'unit' => $unit,
                ]);
                $created += $insert->rowCount();
            }
        }
    }
    return $created;
}

function push_flow_enqueue_live_reminders(PDO $pdo, int $studentBatch = 500, int $maxBatches = 5): array
{
    push_flow_engine_ensure_schema($pdo);
    $created = push_flow_prepare_live_batches($pdo);
    $pdo->exec("UPDATE automation_live_reminder_batches SET status='expired',completed_at=NOW() WHERE status='pending' AND live_at<=NOW()");
    $limit = max(1, min(20, $maxBatches));
    $st = $pdo->prepare("SELECT b.* FROM automation_live_reminder_batches b WHERE b.status='pending' AND b.reminder_at<=NOW() AND b.live_at>NOW() AND ((b.flow_kind='push' AND EXISTS(SELECT 1 FROM push_flows f WHERE f.id=b.flow_id AND f.status='active' AND f.current_version_id=b.version_id)) OR (b.flow_kind='email' AND EXISTS(SELECT 1 FROM email_flows f WHERE f.id=b.flow_id AND f.status='active' AND f.current_version_id=b.version_id))) ORDER BY b.reminder_at,b.id LIMIT {$limit}");
    $st->execute();
    $batches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stats = ['created_batches' => $created, 'processed_batches' => 0, 'candidates' => 0, 'enqueued' => 0, 'push_enqueued' => 0, 'email_enqueued' => 0];
    $liveColumn = push_flow_live_column($pdo);
    foreach ($batches as $batch) {
        $codigoLive = '';
        if ($liveColumn) {
            $select = "`{$liveColumn}` live_value" . (push_flow_engine_column_exists($pdo, 'turmas', 'codigo_live') ? ',codigo_live' : '');
            $live = $pdo->prepare("SELECT {$select} FROM turmas WHERE codigo=:codigo LIMIT 1");
            $live->execute(['codigo' => $batch['turma_codigo']]);
            $liveRow = $live->fetch(PDO::FETCH_ASSOC) ?: [];
            $current = trim((string)($liveRow['live_value'] ?? ''));
            $codigoLive = trim((string)($liveRow['codigo_live'] ?? ''));
            try { $currentAt = $current !== '' ? new DateTimeImmutable($current, new DateTimeZone('America/Sao_Paulo')) : null; } catch (Throwable $ignored) { $currentAt = null; }
            if (!$currentAt || $currentAt->format('Y-m-d H:i:s') !== (string)$batch['live_at']) {
                $pdo->prepare("UPDATE automation_live_reminder_batches SET status='superseded',completed_at=NOW() WHERE id=:id")->execute(['id' => $batch['id']]);
                continue;
            }
        }
        $ids = push_flow_live_students($pdo, (string)$batch['turma_codigo'], (int)$batch['cursor_user_id'], $studentBatch);
        $last = (int)$batch['cursor_user_id'];
        $enqueued = 0;
        foreach ($ids as $userId) {
            $last = max($last, $userId);
            $recipient = $pdo->prepare("INSERT IGNORE INTO automation_live_reminder_recipients (batch_id,flow_kind,flow_id,user_id,turma_codigo,live_at,advance_value,advance_unit) VALUES (:batch,:kind,:flow,:user,:turma,:live,:advance,:unit)");
            $recipient->execute([
                'batch' => (int)$batch['id'],
                'kind' => (string)$batch['flow_kind'],
                'flow' => (int)$batch['flow_id'],
                'user' => $userId,
                'turma' => (string)$batch['turma_codigo'],
                'live' => (string)$batch['live_at'],
                'advance' => (int)$batch['advance_value'],
                'unit' => (string)$batch['advance_unit'],
            ]);
            if ($recipient->rowCount() !== 1) continue;
            $recipientId = (int)$pdo->lastInsertId();
            $extra = [
                'event_id' => 'live-reminder-' . (string)$batch['flow_kind'] . '-' . (int)$batch['flow_id'] . '-' . $recipientId,
                'codigo_turma' => (string)$batch['turma_codigo'],
                'codigo_live' => $codigoLive,
                'data_live' => (string)$batch['live_at'],
                'live_at' => (string)$batch['live_at'],
                'link_live' => 'trilha.php',
                'antecedencia_valor' => (int)$batch['advance_value'],
                'antecedencia_unidade' => (string)$batch['advance_unit'],
                '_scheduled_flow_kind' => (string)$batch['flow_kind'],
                '_scheduled_flow_id' => (int)$batch['flow_id'],
                '_scheduled_version_id' => (int)$batch['version_id'],
                '_scheduled_node_id' => (string)$batch['trigger_node_id'],
            ];
            try {
                if ((string)$batch['flow_kind'] === 'email') {
                    require_once __DIR__ . '/email_flow_engine.php';
                    $matched = email_flow_capture_event($pdo, 'LIVE_LEMBRETE_AGENDADO', $userId, $extra);
                } else {
                    $matched = push_flow_capture_event($pdo, 'LIVE_LEMBRETE_AGENDADO', $userId, $extra);
                }
            } catch (Throwable $e) {
                $pdo->prepare('DELETE FROM automation_live_reminder_recipients WHERE id=:id')->execute(['id' => $recipientId]);
                throw $e;
            }
            if ($matched === 0) {
                $pdo->prepare('DELETE FROM automation_live_reminder_recipients WHERE id=:id')->execute(['id' => $recipientId]);
            } else {
                $enqueued += $matched;
                $stats[(string)$batch['flow_kind'] . '_enqueued'] += $matched;
            }
        }
        $complete = count($ids) < $studentBatch;
        $pdo->prepare("UPDATE automation_live_reminder_batches SET cursor_user_id=:cursor,total_candidates=total_candidates+:candidates,enqueued_runs=enqueued_runs+:enqueued,status=:status,completed_at=IF(:done=1,NOW(),NULL) WHERE id=:id")->execute([
            'cursor' => $last,
            'candidates' => count($ids),
            'enqueued' => $enqueued,
            'status' => $complete ? 'completed' : 'pending',
            'done' => $complete ? 1 : 0,
            'id' => $batch['id'],
        ]);
        $stats['processed_batches']++;
        $stats['candidates'] += count($ids);
        $stats['enqueued'] += $enqueued;
    }
    return $stats;
}

function push_flow_process_due(PDO $pdo, int $batch = 50, int $maxSeconds = 45): array
{
    push_flow_engine_ensure_schema($pdo);
    if (!push_flow_engine_enabled()) return ['processed'=>0,'completed'=>0,'scheduled'=>0,'retry'=>0,'failed'=>0,'paused'=>true];
    $batch=max(1,min(200,$batch)); $started=microtime(true); $stats=['processed'=>0,'completed'=>0,'scheduled'=>0,'retry'=>0,'failed'=>0,'skipped'=>0];
    while ($stats['processed']<$batch && microtime(true)-$started<$maxSeconds) {
        $job=push_flow_claim_job($pdo); if(!$job)break;
        try { $result=push_flow_process_job($pdo,$job); }
        catch (Throwable $e) {
            $error=mb_substr($e->getMessage(),0,1000); $attempt=(int)$job['attempts']; $max=(int)$job['max_attempts'];
            if($attempt<$max){$delay=min(60,(int)pow(2,max(0,$attempt-1)));$pdo->prepare("UPDATE push_flow_jobs SET status='retry',available_at=DATE_ADD(NOW(),INTERVAL :delay MINUTE),last_error=:error,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:token")->execute(['delay'=>$delay,'error'=>$error,'id'=>(int)$job['id'],'token'=>$job['lease_token']]);$result='retry';}
            else{$pdo->prepare("UPDATE push_flow_jobs SET status='failed',last_error=:error,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:token")->execute(['error'=>$error,'id'=>(int)$job['id'],'token'=>$job['lease_token']]);$pdo->prepare("UPDATE push_flow_runs SET status='failed',last_error=:error,finished_at=NOW() WHERE id=:id")->execute(['error'=>$error,'id'=>(int)$job['run_id']]);$result='failed';}
        }
        $stats['processed']++; if(isset($stats[$result]))$stats[$result]++;
    }
    $stats['duration_ms']=(int)round((microtime(true)-$started)*1000); return $stats;
}
