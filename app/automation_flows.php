<?php
declare(strict_types=1);

require_once __DIR__ . '/email_marketing.php';
require_once __DIR__ . '/email_flow_engine.php';
require_once __DIR__ . '/push_flow_engine.php';
require_once __DIR__ . '/voice_torpedo.php';

function automation_flows_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        description VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        draft_graph_json LONGTEXT NOT NULL,
        lock_version INT UNSIGNED NOT NULL DEFAULT 1,
        current_version_id BIGINT UNSIGNED NULL,
        published_at DATETIME NULL,
        created_by VARCHAR(150) NULL,
        updated_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_af_status (status),
        KEY idx_af_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        graph_json LONGTEXT NOT NULL,
        name_snapshot VARCHAR(180) NOT NULL,
        description_snapshot VARCHAR(500) NULL,
        published_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_af_version (flow_id,version_number),
        KEY idx_afv_flow (flow_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_code VARCHAR(100) NOT NULL,
        user_id INT NOT NULL,
        source_key CHAR(64) NOT NULL,
        payload_json LONGTEXT NULL,
        matched_flows INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_afe_source (source_key),
        KEY idx_afe_user (user_id),
        KEY idx_afe_event (event_code,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        last_error VARCHAR(1000) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        UNIQUE KEY uk_afr (flow_id,version_id,event_id),
        KEY idx_afr_status (status),
        KEY idx_afr_flow (flow_id,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        node_id VARCHAR(80) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        available_at DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
        lease_token VARCHAR(64) NULL,
        lease_until DATETIME NULL,
        input_json LONGTEXT NULL,
        output_json LONGTEXT NULL,
        last_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_afj (run_id,node_id),
        KEY idx_afj_due (status,available_at),
        KEY idx_afj_lease (lease_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!automation_flows_column_exists($pdo, 'automation_flow_jobs', 'channel')) {
        $pdo->exec("ALTER TABLE automation_flow_jobs ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'general' AFTER node_id");
    }
    try { $pdo->exec("ALTER TABLE automation_flow_jobs ADD KEY idx_afj_channel_due (channel,status,available_at)"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_channel_settings (
        channel VARCHAR(20) NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        min_interval_ms INT UNSIGNED NOT NULL DEFAULT 300,
        batch_size INT UNSIGNED NOT NULL DEFAULT 30,
        max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
        backoff_step_seconds INT UNSIGNED NOT NULL DEFAULT 30,
        backoff_max_seconds INT UNSIGNED NOT NULL DEFAULT 1800,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO automation_channel_settings (channel) VALUES ('email'),('push'),('manychat'),('superfuncionario'),('webhook')");
    if (function_exists('get_setting') && function_exists('set_setting') && get_setting('automation_channel_backfill_done', '') === '') {
        automation_flow_backfill_channels($pdo);
        set_setting('automation_channel_backfill_done', '1');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_steps (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        job_id BIGINT UNSIGNED NOT NULL,
        node_id VARCHAR(80) NOT NULL,
        node_type VARCHAR(30) NOT NULL,
        status VARCHAR(30) NOT NULL,
        output_json LONGTEXT NULL,
        error_message VARCHAR(1000) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        KEY idx_afs_run (run_id),
        KEY idx_afs_status (status),
        KEY idx_afs_started (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_flow_cancellation_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        run_id BIGINT UNSIGNED NULL,
        job_id BIGINT UNSIGNED NULL,
        user_id INT NULL,
        node_id VARCHAR(80) NULL,
        event_code VARCHAR(100) NULL,
        reason VARCHAR(500) NOT NULL,
        batch_key VARCHAR(190) NULL,
        payload_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_af_cancel_flow (flow_id,version_id),
        KEY idx_af_cancel_job (job_id),
        KEY idx_af_cancel_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function automation_flows_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute(['c' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function automation_flow_channels(): array
{
    return ['email', 'push', 'voice', 'manychat', 'superfuncionario', 'webhook'];
}

function automation_flow_channel_task_keys(): array
{
    $keys = ['automacoes'];
    foreach (automation_flow_channels() as $channel) $keys[] = 'automacoes_' . $channel;
    return $keys;
}

function automation_flow_job_channel(array $node): string
{
    $type = (string)($node['type'] ?? '');
    if ($type === 'email' || $type === 'ab_test') return 'email';
    if ($type === 'push') return 'push';
    if ($type === 'voice') return 'voice';
    if ($type === 'integration') {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $provider = (string)($config['provider'] ?? '');
        if ($provider === 'manychat') return 'manychat';
        if ($provider === 'superfuncionario') return 'superfuncionario';
        return 'webhook';
    }
    return 'general';
}

function automation_flow_find_node(array $graph, string $nodeId): ?array
{
    foreach ($graph['nodes'] ?? [] as $node) {
        if ((string)($node['id'] ?? '') === $nodeId) return $node;
    }
    return null;
}

function automation_flow_channel_settings(PDO $pdo, string $channel): array
{
    $defaults = ['enabled' => true, 'min_interval_ms' => 300, 'batch_size' => 30, 'max_attempts' => 5, 'backoff_step_seconds' => 30, 'backoff_max_seconds' => 1800];
    if (in_array($channel, ['voice', 'general', 'push'], true)) {
        // Voz, General e Push não usam pausa artificial entre envios de lote.
        return ['min_interval_ms' => 0, 'batch_size' => 200] + $defaults;
    }
    try {
        $st = $pdo->prepare("SELECT * FROM automation_channel_settings WHERE channel=:c LIMIT 1");
        $st->execute(['c' => $channel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $defaults;
        $backoffStep = max(1, min(3600, (int)$row['backoff_step_seconds']));
        return [
            'enabled' => (int)$row['enabled'] === 1,
            'min_interval_ms' => max(0, min(60000, (int)$row['min_interval_ms'])),
            'batch_size' => max(1, min(1000, (int)$row['batch_size'])),
            'max_attempts' => max(1, min(20, (int)$row['max_attempts'])),
            'backoff_step_seconds' => $backoffStep,
            'backoff_max_seconds' => max($backoffStep, min(86400, (int)$row['backoff_max_seconds'])),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

function automation_flow_backfill_channels(PDO $pdo): int
{
    $rows = $pdo->query("
        SELECT j.id, j.node_id, v.graph_json
          FROM automation_flow_jobs j
          JOIN automation_flow_runs r ON r.id = j.run_id
          JOIN automation_flow_versions v ON v.id = r.version_id
         WHERE j.channel = 'general'
           AND j.status IN ('queued','retry','scheduled','processing')
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return 0;
    $update = $pdo->prepare("UPDATE automation_flow_jobs SET channel=:c WHERE id=:id AND channel='general'");
    $fixed = 0;
    foreach ($rows as $row) {
        $graph = json_decode((string)$row['graph_json'], true) ?: [];
        $node = automation_flow_find_node($graph, (string)$row['node_id']);
        if (!$node) continue;
        $channel = automation_flow_job_channel($node);
        if ($channel === 'general') continue;
        $update->execute(['c' => $channel, 'id' => (int)$row['id']]);
        $fixed++;
    }
    return $fixed;
}

function automation_flow_blank_graph(): array
{
    return ['schemaVersion'=>3,'nodes'=>[['id'=>'trigger_'.bin2hex(random_bytes(4)),'type'=>'trigger','x'=>120,'y'=>130,'config'=>['label'=>'Inicio do fluxo','event'=>'INSCRITO','filter'=>'','advanceDuration'=>1,'advanceUnit'=>'hours']]],'edges'=>[],'viewport'=>['x'=>80,'y'=>60,'zoom'=>1]];
}

function automation_flow_decode_graph(string $json): array
{
    if (strlen($json) > 2 * 1024 * 1024) throw new InvalidArgumentException('O fluxo excede o limite de 2 MB.');
    $graph = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($graph)) throw new InvalidArgumentException('Estrutura do fluxo invalida.');
    return $graph;
}

function automation_flow_validate_graph(array $graph, bool $publish = false): array
{
    $errors = [];
    $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
    $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
    $allowed = ['trigger','condition','wait','email','ab_test','push','voice','action','integration','end'];
    $ids = []; $triggers = [];
    foreach ($nodes as $node) {
        $id = (string)($node['id'] ?? ''); $type = (string)($node['type'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{3,80}$/', $id) || isset($ids[$id])) $errors[] = 'Ha blocos com identificador invalido ou duplicado.';
        if (!in_array($type, $allowed, true)) $errors[] = 'Tipo de bloco invalido: ' . $type;
        $ids[$id] = true;
        if ($type === 'trigger') $triggers[] = $id;
        $c = is_array($node['config'] ?? null) ? $node['config'] : [];
        if ($type === 'trigger' && trim((string)($c['event'] ?? '')) === '') $errors[] = 'Configure o evento do gatilho.';
        if ($type === 'wait' && ((int)($c['duration'] ?? 0) < 1 || !in_array(($c['unit'] ?? ''), ['minutes','hours','days'], true))) $errors[] = 'Configure o temporizador.';
        if ($type === 'email') {
            if (!empty($c['abEnabled'])) {
                $variants = is_array($c['variants'] ?? null) ? $c['variants'] : [];
                $validVariants = 0;
                foreach ($variants as $variant) {
                    if ((int)($variant['templateVersionId'] ?? 0) > 0 && (int)($variant['weight'] ?? 0) > 0) $validVariants++;
                }
                if ($validVariants < 2) $errors[] = 'Configure pelo menos duas variantes validas no teste A/B/n do bloco de e-mail.';
            } elseif ((int)($c['templateVersionId'] ?? 0) < 1) {
                $errors[] = 'Selecione um modelo no bloco de e-mail.';
            }
        }
        if ($type === 'push' && (trim((string)($c['title'] ?? '')) === '' || trim((string)($c['body'] ?? '')) === '')) $errors[] = 'Configure titulo e mensagem no bloco push.';
        if ($type === 'voice' && (string)($c['messageMode'] ?? 'text_to_speech') === 'audio_url' && trim((string)($c['audioUrl'] ?? '')) === '' && (int)($c['audioMediaId'] ?? 0) < 1) $errors[] = 'Selecione um audio da biblioteca ou configure a URL de audio no bloco de voz.';
        if ($type === 'voice' && (string)($c['messageMode'] ?? 'text_to_speech') !== 'audio_url' && trim((string)($c['message'] ?? '')) === '') $errors[] = 'Configure a mensagem TTS no bloco de voz.';
        if ($type === 'action' && trim((string)($c['tag'] ?? '')) === '') $errors[] = 'Configure a tag no bloco de acao.';
        if ($type === 'integration' && !in_array(($c['provider'] ?? ''), ['webhook','superfuncionario','manychat'], true)) $errors[] = 'Configure a integracao.';
        if ($type === 'condition' && empty($c['rules'])) $errors[] = 'Adicione pelo menos uma regra na condicao.';
    }
    foreach ($edges as $edge) {
        if (empty($ids[(string)($edge['source'] ?? '')]) || empty($ids[(string)($edge['target'] ?? '')])) $errors[] = 'Uma conexao aponta para bloco inexistente.';
    }
    if ($publish && count($triggers) !== 1) $errors[] = 'O fluxo publicado deve ter exatamente um gatilho inicial.';
    return array_values(array_unique($errors));
}

function automation_flow_create(PDO $pdo, string $name, string $admin): int
{
    automation_flows_ensure_schema($pdo);
    $pdo->prepare("INSERT INTO automation_flows(name,status,draft_graph_json,created_by,updated_by) VALUES(:n,'draft',:g,:a,:a)")
        ->execute(['n'=>$name ?: 'Novo fluxo','g'=>json_encode(automation_flow_blank_graph(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'a'=>$admin]);
    return (int)$pdo->lastInsertId();
}

function automation_flow_find(PDO $pdo, int $id): ?array
{
    automation_flows_ensure_schema($pdo);
    $st=$pdo->prepare("SELECT f.*,v.version_number current_version_number FROM automation_flows f LEFT JOIN automation_flow_versions v ON v.id=f.current_version_id WHERE f.id=:id AND f.status<>'deleted' LIMIT 1");
    $st->execute(['id'=>$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function automation_flow_save(PDO $pdo, int $id, string $name, string $description, array $graph, int $lock, string $admin): void
{
    $errors = automation_flow_validate_graph($graph, false);
    if ($name === '' || $errors) throw new InvalidArgumentException($name === '' ? 'Informe o nome do fluxo.' : implode(' ', $errors));
    $st=$pdo->prepare("UPDATE automation_flows SET name=:n,description=:d,draft_graph_json=:g,updated_by=:a,lock_version=lock_version+1 WHERE id=:id AND lock_version=:l");
    $st->execute(['n'=>$name,'d'=>$description ?: null,'g'=>json_encode($graph, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'a'=>$admin,'id'=>$id,'l'=>$lock]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Fluxo alterado em outra aba. Recarregue a pagina.');
}

function automation_flow_publish(PDO $pdo, int $id, string $name, string $description, array $graph, int $lock, string $admin): void
{
    $errors = automation_flow_validate_graph($graph, true);
    if ($name === '' || $errors) throw new InvalidArgumentException($name === '' ? 'Informe o nome do fluxo.' : implode(' ', $errors));
    $json = json_encode($graph, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->beginTransaction();
    $st=$pdo->prepare("UPDATE automation_flows SET name=:n,description=:d,draft_graph_json=:g,updated_by=:a,lock_version=lock_version+1 WHERE id=:id AND lock_version=:l");
    $st->execute(['n'=>$name,'d'=>$description ?: null,'g'=>$json,'a'=>$admin,'id'=>$id,'l'=>$lock]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Fluxo alterado em outra aba. Recarregue a pagina.');
    $v=$pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM automation_flow_versions WHERE flow_id=:id');$v->execute(['id'=>$id]);$num=(int)$v->fetchColumn();
    $pdo->prepare('INSERT INTO automation_flow_versions(flow_id,version_number,graph_json,name_snapshot,description_snapshot,published_by) VALUES(:f,:v,:g,:n,:d,:a)')
        ->execute(['f'=>$id,'v'=>$num,'g'=>$json,'n'=>$name,'d'=>$description ?: null,'a'=>$admin]);
    $vid=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE automation_flows SET current_version_id=:v,status='active',published_at=NOW() WHERE id=:id")->execute(['v'=>$vid,'id'=>$id]);
    $pdo->commit();
}

function automation_flow_match_norm(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted) && $converted !== '') $value = $converted;
    $value = strtolower($value);
    $value = preg_replace('/\s+/', ' ', $value) ?: $value;
    return trim($value);
}

function automation_flow_extra_path_value(array $extra, array $path)
{
    $value = $extra;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return null;
        $value = $value[$key];
    }
    return is_scalar($value) ? (string)$value : null;
}

function automation_flow_payment_product_matches(array $extra, string $filter): bool
{
    $needle = automation_flow_match_norm($filter);
    if ($needle === '') return true;
    $paths = [
        ['product_name'], ['product_code'], ['checkout_id'], ['external_product_id'],
        ['produto_nome'], ['produto_id'], ['curso'], ['course'], ['course_name'],
        ['pagamento', 'product_name'], ['pagamento', 'product_code'], ['pagamento', 'checkout_id'],
        ['pagamento', 'produto_nome'], ['pagamento', 'produto_id'],
        ['metadata', 'product_name'], ['metadata', 'product_code'], ['metadata', 'checkout_id'],
        ['payload', 'product_name'], ['payload', 'product_code'], ['payload', 'checkout_id'],
    ];
    foreach ($paths as $path) {
        $value = automation_flow_extra_path_value($extra, $path);
        if ($value !== null && automation_flow_match_norm($value) === $needle) return true;
    }
    return false;
}

function automation_flow_payment_product_filters(array $config): array
{
    $values = [];
    if (isset($config['paymentProducts']) && is_array($config['paymentProducts'])) {
        foreach ($config['paymentProducts'] as $value) {
            $value = trim((string)$value);
            if ($value !== '') $values[] = $value;
        }
    }
    foreach ([$config['paymentProduct'] ?? '', $config['productFilter'] ?? ''] as $value) {
        $value = trim((string)$value);
        if ($value !== '') $values[] = $value;
    }
    return array_values(array_unique($values));
}

function automation_flow_trigger_matches(array $node, array $user, array $extra, string $event, int $flowId = 0, int $versionId = 0): bool
{
    $c = is_array($node['config'] ?? null) ? $node['config'] : [];
    if (strcasecmp((string)($c['event'] ?? ''), $event) !== 0) return false;
    if ($event === 'LIVE_LEMBRETE_AGENDADO' && isset($extra['_scheduled_flow_id'])) {
        if ((string)($extra['_scheduled_flow_kind'] ?? '') !== 'automation') return false;
        if ((int)($extra['_scheduled_flow_id'] ?? 0) !== $flowId) return false;
        if ((int)($extra['_scheduled_version_id'] ?? 0) !== $versionId) return false;
        if ((string)($extra['_scheduled_node_id'] ?? '') !== (string)($node['id'] ?? '')) return false;
    }
    $paymentProducts = automation_flow_payment_product_filters($c);
    if ($paymentProducts) {
        $matchedPaymentProduct = false;
        foreach ($paymentProducts as $paymentProduct) {
            if (automation_flow_payment_product_matches($extra, $paymentProduct)) {
                $matchedPaymentProduct = true;
                break;
            }
        }
        if (!$matchedPaymentProduct) return false;
    }
    $filter = trim((string)($c['filter'] ?? ''));
    if ($filter === '') return true;
    foreach ([$user['codigo_turma'] ?? '', $user['turma_codigo'] ?? '', $extra['codigo_turma'] ?? ''] as $value) {
        if (strcasecmp(trim((string)$value), $filter) === 0) return true;
    }
    return false;
}

function automation_flow_user_from_event_payload(int $userId, array $extra): array
{
    if ($userId > 0) return buscar_usuario_por_id($userId) ?: ['id'=>$userId];
    return [
        'id' => 0,
        'nome' => $extra['buyer_name'] ?? $extra['comprador_nome'] ?? null,
        'email' => $extra['buyer_email'] ?? $extra['comprador_email'] ?? null,
        'telefone' => $extra['buyer_phone'] ?? $extra['comprador_telefone'] ?? null,
        'documento' => $extra['buyer_document'] ?? $extra['comprador_documento'] ?? null,
        '_unidentified_buyer' => 1,
    ];
}

function automation_flow_capture_event(PDO $pdo, string $event, int $userId, array $extra = []): int
{
    if ($userId < 0 || trim($event) === '') return 0;
    automation_flows_ensure_schema($pdo);
    $flows = $pdo->query("SELECT f.id flow_id,f.current_version_id,v.graph_json FROM automation_flows f JOIN automation_flow_versions v ON v.id=f.current_version_id WHERE f.status='active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$flows) return 0;
    $user = automation_flow_user_from_event_payload($userId, $extra);
    $payload = json_encode($extra, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
    $source = hash('sha256', 'unified|' . strtoupper($event) . '|' . $userId . '|' . ($extra['event_id'] ?? $extra['transaction_code'] ?? $extra['lesson_id'] ?? $payload));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO automation_flow_events(event_code,user_id,source_key,payload_json) VALUES(:e,:u,:s,:p) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)')
            ->execute(['e'=>$event,'u'=>$userId,'s'=>$source,'p'=>$payload]);
        $st=$pdo->prepare('SELECT id FROM automation_flow_events WHERE source_key=:s');$st->execute(['s'=>$source]);$eventId=(int)$st->fetchColumn();
        if ($eventId <= 0) { $pdo->rollBack(); return 0; }
        $matched = 0;
        foreach ($flows as $flow) {
            $graph=json_decode((string)$flow['graph_json'], true) ?: [];
            $trigger=null; foreach (($graph['nodes'] ?? []) as $node) if (($node['type'] ?? '') === 'trigger') { $trigger=$node; break; }
            if (!$trigger || !automation_flow_trigger_matches($trigger, $user, $extra, $event, (int)$flow['flow_id'], (int)$flow['current_version_id'])) continue;
            $runInsert = $pdo->prepare("INSERT IGNORE INTO automation_flow_runs(flow_id,version_id,event_id,user_id,status) VALUES(:f,:v,:e,:u,'running')");
            $runInsert->execute(['f'=>$flow['flow_id'],'v'=>$flow['current_version_id'],'e'=>$eventId,'u'=>$userId]);
            if ($runInsert->rowCount() === 1) {
                $runId=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO automation_flow_jobs(run_id,node_id,channel,status,available_at,input_json) VALUES(:r,:n,:c,'queued',NOW(),'{}')")
                    ->execute(['r'=>$runId,'n'=>(string)$trigger['id'],'c'=>automation_flow_job_channel($trigger)]);
                $matched++;
            }
        }
        $pdo->prepare('UPDATE automation_flow_events SET matched_flows=:m WHERE id=:id')->execute(['m'=>$matched,'id'=>$eventId]);
        $pdo->commit();
        return $matched;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function automation_flow_next(array $graph, string $nodeId, string $handle = 'default'): ?string
{
    foreach ($graph['edges'] ?? [] as $edge) if (($edge['source'] ?? '') === $nodeId && ($edge['sourceHandle'] ?? 'default') === $handle) return (string)$edge['target'];
    return null;
}

function automation_flow_claim_channel(PDO $pdo, string $channel, ?int $flowId = null): ?array
{
    automation_flows_ensure_schema($pdo);
    $token = bin2hex(random_bytes(16));
    $flowWhere = $flowId !== null && $flowId > 0 ? ' AND r.flow_id = :flow_id' : '';
    $pdo->beginTransaction();
    $st = $pdo->prepare("
        SELECT j.id,j.node_id,j.available_at,r.user_id,v.graph_json
          FROM automation_flow_jobs j
          JOIN automation_flow_runs r ON r.id=j.run_id
          JOIN automation_flow_versions v ON v.id=r.version_id
         WHERE j.channel = :channel
           AND (
                (j.status IN ('queued','retry','scheduled') AND j.available_at<=NOW())
                OR (j.status='processing' AND j.lease_until<NOW())
               )
               {$flowWhere}
      ORDER BY j.available_at,j.id
         LIMIT 25
         FOR UPDATE
    ");
    $params = ['channel' => $channel];
    if ($flowWhere !== '') $params['flow_id'] = $flowId;
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $id = $channel === 'voice' ? automation_flow_pick_claim_id($pdo, $rows) : (int)($rows[0]['id'] ?? 0);
    if (!$id) { $pdo->commit(); return null; }
    $pdo->prepare("UPDATE automation_flow_jobs SET status='processing',attempts=attempts+1,lease_token=:t,lease_until=DATE_ADD(NOW(),INTERVAL 90 SECOND) WHERE id=:id")->execute(['t'=>$token,'id'=>$id]);
    $pdo->commit();
    $st=$pdo->prepare("SELECT j.*,r.flow_id,r.version_id,r.event_id,r.user_id,r.status run_status,v.graph_json,e.event_code,e.payload_json event_payload,e.payload_json payload_json FROM automation_flow_jobs j JOIN automation_flow_runs r ON r.id=j.run_id JOIN automation_flow_versions v ON v.id=r.version_id JOIN automation_flow_events e ON e.id=r.event_id WHERE j.id=:id AND j.lease_token=:t");
    $st->execute(['id'=>$id,'t'=>$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function automation_flow_pick_claim_id(PDO $pdo, array $rows): int
{
    if (!$rows) return 0;
    $best = null;
    $bestScore = PHP_INT_MAX;
    foreach ($rows as $idx => $row) {
        $score = 1000000 + $idx;
        try {
            $graph = json_decode((string)($row['graph_json'] ?? ''), true) ?: [];
            foreach ($graph['nodes'] ?? [] as $node) {
                if ((string)($node['id'] ?? '') !== (string)($row['node_id'] ?? '')) continue;
                $type = (string)($node['type'] ?? '');
                if (in_array($type, ['integration','email','push','action','end'], true)) $score = 100000 + $idx;
                elseif (in_array($type, ['condition','wait'], true)) $score = 200000 + $idx;
                elseif ($type === 'trigger') $score = 900000 + $idx;
                elseif ($type === 'voice') {
                    $score = 300000 + $idx;
                    $tags = automation_flow_voice_preferred_tags(is_array($node['config'] ?? null) ? $node['config'] : []);
                    if ($tags && automation_flow_user_has_any_tag_name($pdo, (int)($row['user_id'] ?? 0), $tags)) $score = $idx;
                }
                break;
            }
        } catch (Throwable $e) {}
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = (int)($row['id'] ?? 0);
        }
    }
    return (int)$best;
}

function automation_flow_voice_preferred_tags(array $config): array
{
    $raw = $config['preferredTags'] ?? [];
    if (is_string($raw)) $raw = preg_split('/[\r\n,]+/', $raw) ?: [];
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $tag) {
        $tag = trim((string)$tag);
        if ($tag !== '') $out[] = $tag;
    }
    return array_values(array_unique($out));
}

function automation_flow_user_has_any_tag_name(PDO $pdo, int $userId, array $tags): bool
{
    if ($userId <= 0 || !$tags) return false;
    $in = implode(',', array_fill(0, count($tags), '?'));
    try {
        $st = $pdo->prepare("SELECT 1 FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=? AND t.nome IN ($in) LIMIT 1");
        $st->execute(array_merge([$userId], $tags));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function automation_flow_pick_email_variant(array $variants, array $job): array
{
    $valid = [];
    $total = 0;
    foreach ($variants as $index => $variant) {
        $weight = max(0, (int)($variant['weight'] ?? 0));
        $versionId = (int)($variant['templateVersionId'] ?? 0);
        if ($weight <= 0 || $versionId <= 0) continue;
        $variant['_index'] = $index;
        $variant['weight'] = $weight;
        $valid[] = $variant;
        $total += $weight;
    }
    if (count($valid) < 2 || $total <= 0) throw new RuntimeException('Teste A/B/n sem variantes validas.');
    $seed = hexdec(substr(hash('sha256', 'automation_ab|' . $job['run_id'] . '|' . $job['node_id'] . '|' . $job['user_id']), 0, 8));
    $bucket = $seed % $total;
    $cursor = 0;
    foreach ($valid as $variant) {
        $cursor += (int)$variant['weight'];
        if ($bucket < $cursor) return $variant;
    }
    return $valid[count($valid) - 1];
}

function automation_flow_is_voice_concurrency_limit_error(string $error): bool
{
    if (function_exists('voice_is_concurrency_limit_error')) return voice_is_concurrency_limit_error($error);
    $normalized = mb_strtolower($error);
    return str_contains($normalized, 'limite de concorrencia')
        || str_contains($normalized, 'chamada de voz ativa')
        || str_contains($normalized, 'simultane')
        || str_contains($normalized, 'connection channel limit')
        || str_contains($normalized, 'over the limit')
        || str_contains($normalized, 'concurrent')
        || str_contains($normalized, 'rate limit')
        || str_contains($normalized, 'too many requests');
}

function automation_flow_str_limit(string $value, int $length): string
{
    if (function_exists('mb_substr')) return mb_substr($value, 0, $length);
    return substr($value, 0, $length);
}

function automation_flow_reschedule_voice_limit(PDO $pdo, array $job, array $input, string $error): string
{
    $provider = voice_provider($pdo);
    $cfg = (array)($provider['public'] ?? []);
    $spacing = max(0, min(3600, (int)($cfg['automation_queue_spacing_seconds'] ?? 75)));
    $step = max(1, min(3600, (int)($cfg['automation_concurrency_backoff_step_seconds'] ?? 90)));
    $max = max($step, min(86400, (int)($cfg['automation_concurrency_backoff_max_seconds'] ?? 3600)));

    $previousDelay = (int)($input['_voice_limit_delay_seconds'] ?? 0);
    $delay = min($max, max($spacing, $previousDelay + $step));
    $input['_voice_limit_delay_seconds'] = $delay;
    $input['_voice_limit_reschedules'] = (int)($input['_voice_limit_reschedules'] ?? 0) + 1;
    $input['_voice_limit_last_error'] = automation_flow_str_limit($error, 300);

    $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(j.available_at)) FROM automation_flow_jobs j JOIN automation_flow_runs r ON r.id=j.run_id WHERE r.flow_id=:flow AND j.node_id=:node AND j.status IN ('queued','retry','scheduled','processing')");
    $st->execute(['flow' => (int)$job['flow_id'], 'node' => (string)$job['node_id']]);
    $tail = (int)$st->fetchColumn();
    $targetTs = max(time() + $delay, $tail > 0 ? $tail + $spacing : 0);
    $availableAt = date('Y-m-d H:i:s', $targetTs);

    $pdo->prepare("UPDATE automation_flow_jobs SET status='scheduled',available_at=:a,input_json=:i,attempts=:attempts,last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")
        ->execute([
            'a' => $availableAt,
            'i' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempts' => max(0, (int)$job['attempts'] - 1),
            'e' => $error,
            'id' => (int)$job['id'],
            't' => (string)$job['lease_token'],
        ]);

    return $availableAt;
}

function automation_flow_reschedule_channel_retry(PDO $pdo, array $job, array $input, string $channel, array $channelSettings, string $error): string
{
    $step = $channelSettings['backoff_step_seconds'];
    $max = $channelSettings['backoff_max_seconds'];
    $attempt = max(1, (int)$job['attempts']);
    $delay = min($max, $step * $attempt);
    $availableAt = date('Y-m-d H:i:s', time() + $delay);
    $input['_retry_reschedules'] = (int)($input['_retry_reschedules'] ?? 0) + 1;
    $input['_retry_last_error'] = automation_flow_str_limit($error, 300);

    $pdo->prepare("UPDATE automation_flow_jobs SET status='retry',available_at=:a,input_json=:i,last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")
        ->execute([
            'a' => $availableAt,
            'i' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'e' => $error,
            'id' => (int)$job['id'],
            't' => (string)$job['lease_token'],
        ]);

    return $availableAt;
}

function automation_flow_voice_pacing_delay(PDO $pdo): int
{
    $provider = voice_provider($pdo);
    $cfg = (array)($provider['public'] ?? []);
    $spacing = max(0, min(3600, (int)($cfg['automation_queue_spacing_seconds'] ?? 75)));
    $perMinute = max(1, (int)($cfg['calls_per_minute'] ?? 1));
    $perHour = max(1, (int)($cfg['calls_per_hour'] ?? 300));
    $perDay = max(1, (int)($cfg['calls_per_day'] ?? 1000));
    $concurrency = max(1, (int)($cfg['concurrency_limit'] ?? 1));
    $delay = 0;

    if (function_exists('voice_active_call_count') && voice_active_call_count($pdo) >= $concurrency) {
        $delay = max($delay, max(1, $spacing));
    }

    if ($spacing > 0) {
        $last = (int)$pdo->query("SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM voice_call_attempts WHERE automation_job_id IS NOT NULL")->fetchColumn();
        if ($last > 0) $delay = max($delay, ($last + $spacing) - time());
    }

    $windows = [
        ['seconds' => 60, 'limit' => $perMinute],
        ['seconds' => 3600, 'limit' => $perHour],
        ['seconds' => 86400, 'limit' => $perDay],
    ];
    foreach ($windows as $window) {
        $seconds = (int)$window['seconds'];
        $st = $pdo->query("SELECT COUNT(*) c, UNIX_TIMESTAMP(MIN(created_at)) first_ts FROM voice_call_attempts WHERE created_at >= DATE_SUB(NOW(), INTERVAL $seconds SECOND)");
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($row['c'] ?? 0) >= (int)$window['limit']) {
            $first = (int)($row['first_ts'] ?? 0);
            if ($first > 0) $delay = max($delay, ($first + (int)$window['seconds'] + 1) - time());
        }
    }

    return max(0, min(86400, $delay));
}

function automation_flow_schedule_voice_pacing(PDO $pdo, array $job, array $input, int $delaySeconds, string $reason): string
{
    $provider = voice_provider($pdo);
    $cfg = (array)($provider['public'] ?? []);
    $spacing = max(0, min(3600, (int)($cfg['automation_queue_spacing_seconds'] ?? 75)));
    $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(j.available_at)) FROM automation_flow_jobs j JOIN automation_flow_runs r ON r.id=j.run_id WHERE r.flow_id=:flow AND j.node_id=:node AND j.status IN ('queued','retry','scheduled','processing')");
    $st->execute(['flow' => (int)$job['flow_id'], 'node' => (string)$job['node_id']]);
    $tail = (int)$st->fetchColumn();
    $targetTs = max(time() + max(1, $delaySeconds), $tail > 0 ? $tail + $spacing : 0);
    $availableAt = date('Y-m-d H:i:s', $targetTs);
    $input['_voice_pacing_reschedules'] = (int)($input['_voice_pacing_reschedules'] ?? 0) + 1;
    $input['_voice_pacing_reason'] = $reason;

    $pdo->prepare("UPDATE automation_flow_jobs SET status='scheduled',available_at=:a,input_json=:i,attempts=:attempts,last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")
        ->execute([
            'a' => $availableAt,
            'i' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempts' => max(0, (int)$job['attempts'] - 1),
            'e' => $reason,
            'id' => (int)$job['id'],
            't' => (string)$job['lease_token'],
        ]);

    return $availableAt;
}

function automation_flow_voice_deadline_at(array $config, array $job): ?DateTimeImmutable
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    $duration = max(0, (int)($config['maxQueueDuration'] ?? 0));
    $enabled = !empty($config['maxQueueEnabled']) || $duration > 0;
    if (!$enabled || $duration < 1) return null;
    $unit = in_array(($config['maxQueueUnit'] ?? ''), ['minutes','hours','days'], true) ? (string)$config['maxQueueUnit'] : 'hours';
    $createdAt = trim((string)($job['created_at'] ?? ''));
    if ($createdAt === '') return null;
    try {
        return (new DateTimeImmutable($createdAt, $tz))->modify('+' . $duration . ' ' . $unit);
    } catch (Throwable $e) {
        return null;
    }
}

function automation_flow_payload_batch_key(array $job, array $extra): string
{
    $parts = [
        'flow:' . (int)($job['flow_id'] ?? 0),
        'version:' . (int)($job['version_id'] ?? 0),
        'event:' . (string)($job['event_code'] ?? ''),
        'turma:' . (string)($extra['codigo_turma'] ?? ''),
        'live:' . (string)($extra['live_at'] ?? $extra['data_live'] ?? ''),
        'scheduled:' . (string)($extra['_scheduled_flow_id'] ?? ''),
    ];
    return implode('|', $parts);
}

function automation_flow_graph_node_type(array $graph, string $nodeId): string
{
    foreach ($graph['nodes'] ?? [] as $node) {
        if ((string)($node['id'] ?? '') === $nodeId) return (string)($node['type'] ?? 'unknown');
    }
    return 'unknown';
}

function automation_flow_cancel_expired_batch(PDO $pdo, array $job, array $graph, array $extra, int $currentStepId, string $reason): int
{
    $statuses = "'queued','retry','scheduled','processing'";
    $params = [
        'flow' => (int)$job['flow_id'],
        'version' => (int)$job['version_id'],
        'event' => (string)$job['event_code'],
    ];
    $where = [
        'r.flow_id=:flow',
        'r.version_id=:version',
        "j.status IN ($statuses)",
        'e.event_code=:event',
    ];
    $jsonFields = [
        'codigo_turma' => (string)($extra['codigo_turma'] ?? ''),
        'data_live' => (string)($extra['data_live'] ?? $extra['live_at'] ?? ''),
        '_scheduled_flow_id' => (string)($extra['_scheduled_flow_id'] ?? ''),
        '_scheduled_node_id' => (string)($extra['_scheduled_node_id'] ?? ''),
    ];
    foreach ($jsonFields as $field => $value) {
        if ($value === '') continue;
        $key = 'json_' . preg_replace('/[^a-z0-9_]+/i', '_', $field);
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$." . $field . "'))=:{$key}";
        $params[$key] = $value;
    }
    if (count($where) <= 4) {
        $where[] = 'j.run_id=:run';
        $params['run'] = (int)$job['run_id'];
    }
    $sql = "SELECT j.id,j.run_id,j.node_id,r.user_id,e.payload_json FROM automation_flow_jobs j JOIN automation_flow_runs r ON r.id=j.run_id JOIN automation_flow_events e ON e.id=r.event_id WHERE " . implode(' AND ', $where) . " ORDER BY j.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return 0;

    $batchKey = automation_flow_payload_batch_key($job, $extra);
    $output = json_encode(['canceled'=>true,'reason'=>$reason,'batch_key'=>$batchKey], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE automation_flow_jobs SET status='canceled',output_json=?,last_error=?,lease_token=NULL,lease_until=NULL WHERE id IN ($in)")
        ->execute(array_merge([$output, $reason], $ids));

    $stepInsert = $pdo->prepare("INSERT INTO automation_flow_steps(run_id,job_id,node_id,node_type,status,output_json,error_message,finished_at) VALUES(:run,:job,:node,:type,'canceled',:output,:reason,NOW())");
    $logInsert = $pdo->prepare("INSERT INTO automation_flow_cancellation_logs(flow_id,version_id,run_id,job_id,user_id,node_id,event_code,reason,batch_key,payload_json) VALUES(:flow,:version,:run,:job,:user,:node,:event,:reason,:batch,:payload)");
    foreach ($rows as $row) {
        if ((int)$row['id'] !== (int)$job['id']) {
            $stepInsert->execute([
                'run' => (int)$row['run_id'],
                'job' => (int)$row['id'],
                'node' => (string)$row['node_id'],
                'type' => automation_flow_graph_node_type($graph, (string)$row['node_id']),
                'output' => $output,
                'reason' => $reason,
            ]);
        }
        $logInsert->execute([
            'flow' => (int)$job['flow_id'],
            'version' => (int)$job['version_id'],
            'run' => (int)$row['run_id'],
            'job' => (int)$row['id'],
            'user' => (int)$row['user_id'],
            'node' => (string)$row['node_id'],
            'event' => (string)$job['event_code'],
            'reason' => $reason,
            'batch' => $batchKey,
            'payload' => (string)($row['payload_json'] ?? ''),
        ]);
    }
    if ($currentStepId > 0) {
        $pdo->prepare("UPDATE automation_flow_steps SET status='canceled',output_json=:output,error_message=:reason,finished_at=NOW() WHERE id=:id")
            ->execute(['output' => $output, 'reason' => $reason, 'id' => $currentStepId]);
    }
    $runIds = array_values(array_unique(array_map(static fn($r) => (int)$r['run_id'], $rows)));
    foreach ($runIds as $runId) {
        $pending = $pdo->prepare("SELECT COUNT(*) FROM automation_flow_jobs WHERE run_id=:run AND status IN ('queued','retry','scheduled','processing')");
        $pending->execute(['run' => $runId]);
        if ((int)$pending->fetchColumn() === 0) {
            $pdo->prepare("UPDATE automation_flow_runs SET status='canceled',last_error=:reason,finished_at=IF(finished_at IS NULL,NOW(),finished_at) WHERE id=:run AND status='running'")
                ->execute(['reason' => $reason, 'run' => $runId]);
        }
    }
    return count($rows);
}

function automation_flow_send_email(PDO $pdo, array $job, array $config, array $user): array
{
    $variant = null;
    if (!empty($config['abEnabled'])) {
        $variant = automation_flow_pick_email_variant(is_array($config['variants'] ?? null) ? $config['variants'] : [], $job);
        $versionId = (int)($variant['templateVersionId'] ?? 0);
    } else {
        $versionId=(int)($config['templateVersionId'] ?? 0);
    }
    $st=$pdo->prepare('SELECT * FROM email_template_versions WHERE id=:id');$st->execute(['id'=>$versionId]);$version=$st->fetch(PDO::FETCH_ASSOC);
    if (!$version) throw new RuntimeException('Modelo do bloco de email nao encontrado.');
    $variantId = $variant ? (string)($variant['id'] ?? ('v' . (((int)($variant['_index'] ?? 0)) + 1))) : '';
    if (email_is_suppressed($pdo, (string)($user['email'] ?? ''))) return ['skipped'=>'suppressed','template_version_id'=>$versionId] + ($variant ? ['variant_id'=>$variantId] : []);
    $user=email_flow_render_user($user, $job);
    $key=hash('sha256','automation|' . $job['run_id'] . '|' . $job['node_id'] . '|' . $job['user_id'] . ($variant ? '|' . $variantId : ''));
    $tags = ['automation_flow_id'=>(string)$job['flow_id'],'automation_run_id'=>(string)$job['run_id']];
    if ($variant) {
        $tags['ab_node'] = (string)$job['node_id'];
        $tags['ab_variant'] = $variantId;
    }
    $id=email_send_rendered_message($pdo, email_settings($pdo), ['flow_id'=>(int)$job['flow_id'],'flow_run_id'=>(int)$job['run_id']], $user, $version, $key, $tags);
    return ['message_id'=>$id,'template_version_id'=>$versionId] + ($variant ? ['variant_id'=>$variantId] : []);
}

function automation_flow_process_job(PDO $pdo, array $job): string
{
    if (($job['run_status'] ?? '') !== 'running') return 'skipped';
    $graph=json_decode((string)$job['graph_json'], true) ?: [];
    $node=null; foreach ($graph['nodes'] ?? [] as $n) if (($n['id'] ?? '') === $job['node_id']) { $node=$n; break; }
    if (!$node) throw new RuntimeException('Bloco nao encontrado.');
    $type=(string)$node['type']; $config=is_array($node['config'] ?? null) ? $node['config'] : [];
    $extra=json_decode((string)($job['event_payload'] ?? ''), true) ?: [];
    $user=automation_flow_user_from_event_payload((int)$job['user_id'], $extra);
    $input=json_decode((string)($job['input_json'] ?? ''), true) ?: [];
    $pdo->prepare("INSERT INTO automation_flow_steps(run_id,job_id,node_id,node_type,status) VALUES(:r,:j,:n,:t,'processing')")
        ->execute(['r'=>$job['run_id'],'j'=>$job['id'],'n'=>$job['node_id'],'t'=>$type]);
    $stepId=(int)$pdo->lastInsertId();
    try {
        $handle='default'; $output=[];
        if ($type === 'condition') { $result=email_flow_condition($pdo, $config, (int)$job['user_id'], $user); $handle=$result?'yes':'no'; $output=['result'=>$result,'route'=>$handle]; }
        elseif ($type === 'wait') {
            if (empty($input['_wait_ready'])) {
                $resume=push_flow_wait_until($config); $input['_wait_ready']=true;
                $pdo->prepare("UPDATE automation_flow_jobs SET status='scheduled',available_at=:a,input_json=:i,attempts=0,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")
                    ->execute(['a'=>$resume,'i'=>json_encode($input),'id'=>$job['id'],'t'=>$job['lease_token']]);
                $pdo->prepare("UPDATE automation_flow_steps SET status='scheduled',output_json=:o,finished_at=NOW() WHERE id=:id")->execute(['o'=>json_encode(['resume_at'=>$resume]),'id'=>$stepId]);
                return 'scheduled';
            }
        } elseif ($type === 'email') $output=automation_flow_send_email($pdo,$job,$config,$user);
        elseif ($type === 'push') $output=push_flow_send_push($pdo,$config,(int)$job['user_id'],$job);
        elseif ($type === 'voice') {
            $deadline = automation_flow_voice_deadline_at($config, $job);
            if ($deadline && $deadline < new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'))) {
                $reason = 'Bloco de voz cancelado: tempo maximo na fila ultrapassado em ' . $deadline->format('Y-m-d H:i:s') . '.';
                $pdo->beginTransaction();
                $canceled = automation_flow_cancel_expired_batch($pdo, $job, $graph, $extra, $stepId, $reason);
                $pdo->commit();
                return 'canceled';
            }
            $pacingDelay = automation_flow_voice_pacing_delay($pdo);
            if ($pacingDelay > 0) {
                $availableAt = automation_flow_schedule_voice_pacing($pdo, $job, $input, $pacingDelay, 'Fila de voz aguardando intervalo/limite configurado.');
                $pdo->prepare("UPDATE automation_flow_steps SET status='scheduled',output_json=:o,finished_at=NOW() WHERE id=:id")
                    ->execute([
                        'o' => json_encode(['voice_pacing' => true, 'available_at' => $availableAt, 'delay_seconds' => $pacingDelay], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'id' => $stepId,
                    ]);
                return 'scheduled';
            }
            $output=voice_automation_start_call($pdo,$config,$user,$job,$extra);
        }
        elseif ($type === 'action') { (($config['action'] ?? '') === 'remove_tag' ? remover_tag_usuario((int)$job['user_id'], (string)$config['tag']) : adicionar_tag((int)$job['user_id'], (string)$config['tag'], 'automation_flow', (int)$job['run_id'])); $output=['tag'=>$config['tag'] ?? '']; }
        elseif ($type === 'integration') $output=push_flow_dispatch_integration($pdo,$config,$user,$extra,$job);
        elseif ($type === 'trigger') $output=['event'=>$job['event_code']];
        elseif ($type === 'end') $output=['ended'=>true];
        else throw new RuntimeException('Bloco nao suportado.');
        $next = $type === 'end' ? null : automation_flow_next($graph, (string)$job['node_id'], $handle);
        $pdo->beginTransaction();
        if ($next) {
            $nextNode = automation_flow_find_node($graph, $next);
            $nextChannel = $nextNode ? automation_flow_job_channel($nextNode) : 'general';
            $pdo->prepare("INSERT IGNORE INTO automation_flow_jobs(run_id,node_id,channel,status,available_at,input_json) VALUES(:r,:n,:c,'queued',NOW(),'{}')")->execute(['r'=>$job['run_id'],'n'=>$next,'c'=>$nextChannel]);
        }
        $output['next_node']=$next;
        $pdo->prepare("UPDATE automation_flow_jobs SET status='completed',output_json=:o,lease_token=NULL,lease_until=NULL,last_error=NULL WHERE id=:id AND lease_token=:t")->execute(['o'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$job['id'],'t'=>$job['lease_token']]);
        $pdo->prepare("UPDATE automation_flow_steps SET status='completed',output_json=:o,finished_at=NOW() WHERE id=:id")->execute(['o'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$stepId]);
        $pending=$pdo->prepare("SELECT COUNT(*) FROM automation_flow_jobs WHERE run_id=:r AND status IN ('queued','retry','scheduled','processing')");$pending->execute(['r'=>$job['run_id']]);
        if ((int)$pending->fetchColumn() === 0) $pdo->prepare("UPDATE automation_flow_runs SET status='completed',finished_at=NOW() WHERE id=:id")->execute(['id'=>$job['run_id']]);
        $pdo->commit();
        return 'completed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err=automation_flow_str_limit($e->getMessage(),1000);
        if ($type === 'voice' && automation_flow_is_voice_concurrency_limit_error($err)) {
            $availableAt = automation_flow_reschedule_voice_limit($pdo, $job, $input, $err);
            $pdo->prepare("UPDATE automation_flow_steps SET status='scheduled',error_message=:e,output_json=:o,finished_at=NOW() WHERE id=:id")
                ->execute([
                    'e' => $err,
                    'o' => json_encode(['rescheduled_due_to_voice_limit' => true, 'available_at' => $availableAt], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $stepId,
                ]);
            return 'scheduled';
        }
        $channel = (string)($job['channel'] ?? 'general');
        if (in_array($channel, ['email', 'push', 'manychat', 'superfuncionario', 'webhook'], true)) {
            $channelSettings = automation_flow_channel_settings($pdo, $channel);
            if ((int)$job['attempts'] < $channelSettings['max_attempts']) {
                $availableAt = automation_flow_reschedule_channel_retry($pdo, $job, $input, $channel, $channelSettings, $err);
                $pdo->prepare("UPDATE automation_flow_steps SET status='retry',error_message=:e,output_json=:o,finished_at=NOW() WHERE id=:id")
                    ->execute([
                        'e' => $err,
                        'o' => json_encode(['retry_scheduled' => true, 'available_at' => $availableAt, 'attempt' => (int)$job['attempts']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'id' => $stepId,
                    ]);
                return 'retry';
            }
        }
        $next = $type === 'end' ? null : automation_flow_next($graph, (string)$job['node_id'], 'default');
        $output = ['error'=>$err,'continued'=>true,'next_node'=>$next];
        $pdo->beginTransaction();
        if ($next) {
            $nextNode = automation_flow_find_node($graph, $next);
            $nextChannel = $nextNode ? automation_flow_job_channel($nextNode) : 'general';
            $pdo->prepare("INSERT IGNORE INTO automation_flow_jobs(run_id,node_id,channel,status,available_at,input_json) VALUES(:r,:n,:c,'queued',NOW(),'{}')")->execute(['r'=>$job['run_id'],'n'=>$next,'c'=>$nextChannel]);
        }
        $pdo->prepare("UPDATE automation_flow_jobs SET status='failed',output_json=:o,last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")
            ->execute(['o'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'e'=>$err,'id'=>$job['id'],'t'=>$job['lease_token']]);
        $pdo->prepare("UPDATE automation_flow_steps SET status='failed',error_message=:e,output_json=:o,finished_at=NOW() WHERE id=:id")
            ->execute(['e'=>$err,'o'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$stepId]);
        $pending=$pdo->prepare("SELECT COUNT(*) FROM automation_flow_jobs WHERE run_id=:r AND status IN ('queued','retry','scheduled','processing')");$pending->execute(['r'=>$job['run_id']]);
        if ((int)$pending->fetchColumn() === 0) $pdo->prepare("UPDATE automation_flow_runs SET status='completed',last_error=:e,finished_at=NOW() WHERE id=:id")->execute(['e'=>$err,'id'=>$job['run_id']]);
        $pdo->commit();
        return 'failed';
    }
}

function automation_flow_process_channel(PDO $pdo, string $channel, int $limit, ?int $flowId = null): array
{
    automation_flows_ensure_schema($pdo);
    $done = ['processed'=>0,'completed'=>0,'scheduled'=>0,'retry'=>0,'failed'=>0,'skipped'=>0,'canceled'=>0];
    $settings = automation_flow_channel_settings($pdo, $channel);
    if (!$settings['enabled']) return $done;
    $minIntervalMs = $settings['min_interval_ms'];
    for ($i = 0; $i < $limit; $i++) {
        if ($i > 0 && $minIntervalMs > 0) usleep($minIntervalMs * 1000);
        $job = automation_flow_claim_channel($pdo, $channel, $flowId);
        if (!$job) break;
        $status = automation_flow_process_job($pdo, $job);
        $done['processed']++;
        if (isset($done[$status])) $done[$status]++;
    }
    return $done;
}

function automation_flow_process_flow_now(PDO $pdo, int $flowId, int $limitPerChannel = 20): array
{
    $totals = ['processed'=>0,'completed'=>0,'scheduled'=>0,'retry'=>0,'failed'=>0,'skipped'=>0,'canceled'=>0];
    foreach (array_merge(['general'], automation_flow_channels()) as $channel) {
        $partial = automation_flow_process_channel($pdo, $channel, $limitPerChannel, $flowId);
        foreach ($totals as $key => $value) $totals[$key] = $value + (int)($partial[$key] ?? 0);
    }
    return $totals;
}
