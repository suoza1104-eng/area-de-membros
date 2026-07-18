<?php
declare(strict_types=1);

require_once __DIR__ . '/email_marketing.php';
require_once __DIR__ . '/email_flow_engine.php';
require_once __DIR__ . '/push_flow_engine.php';

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
    $allowed = ['trigger','condition','wait','email','ab_test','push','action','integration','end'];
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
        if ($type === 'email' && (int)($c['templateVersionId'] ?? 0) < 1) $errors[] = 'Selecione um modelo no bloco de e-mail.';
        if ($type === 'push' && (trim((string)($c['title'] ?? '')) === '' || trim((string)($c['body'] ?? '')) === '')) $errors[] = 'Configure titulo e mensagem no bloco push.';
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
    $filter = trim((string)($c['filter'] ?? ''));
    if ($filter === '') return true;
    foreach ([$user['codigo_turma'] ?? '', $user['turma_codigo'] ?? '', $extra['codigo_turma'] ?? ''] as $value) {
        if (strcasecmp(trim((string)$value), $filter) === 0) return true;
    }
    return false;
}

function automation_flow_capture_event(PDO $pdo, string $event, int $userId, array $extra = []): int
{
    if ($userId < 1 || trim($event) === '') return 0;
    automation_flows_ensure_schema($pdo);
    $flows = $pdo->query("SELECT f.id flow_id,f.current_version_id,v.graph_json FROM automation_flows f JOIN automation_flow_versions v ON v.id=f.current_version_id WHERE f.status='active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$flows) return 0;
    $user = buscar_usuario_por_id($userId) ?: ['id'=>$userId];
    $payload = json_encode($extra, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
    $source = hash('sha256', 'unified|' . strtoupper($event) . '|' . $userId . '|' . ($extra['event_id'] ?? $extra['transaction_code'] ?? $extra['lesson_id'] ?? $payload));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO automation_flow_events(event_code,user_id,source_key,payload_json) VALUES(:e,:u,:s,:p)')
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
                $pdo->prepare("INSERT IGNORE INTO automation_flow_jobs(run_id,node_id,status,available_at,input_json) VALUES(:r,:n,'queued',NOW(),'{}')")
                    ->execute(['r'=>$runId,'n'=>(string)$trigger['id']]);
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

function automation_flow_claim(PDO $pdo): ?array
{
    automation_flows_ensure_schema($pdo);
    $token=bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    $id=(int)$pdo->query("SELECT id FROM automation_flow_jobs WHERE ((status IN ('queued','retry','scheduled') AND available_at<=NOW()) OR (status='processing' AND lease_until<NOW())) ORDER BY available_at,id LIMIT 1 FOR UPDATE")->fetchColumn();
    if (!$id) { $pdo->commit(); return null; }
    $pdo->prepare("UPDATE automation_flow_jobs SET status='processing',attempts=attempts+1,lease_token=:t,lease_until=DATE_ADD(NOW(),INTERVAL 90 SECOND) WHERE id=:id")->execute(['t'=>$token,'id'=>$id]);
    $pdo->commit();
    $st=$pdo->prepare("SELECT j.*,r.flow_id,r.version_id,r.event_id,r.user_id,r.status run_status,v.graph_json,e.event_code,e.payload_json event_payload,e.payload_json payload_json FROM automation_flow_jobs j JOIN automation_flow_runs r ON r.id=j.run_id JOIN automation_flow_versions v ON v.id=r.version_id JOIN automation_flow_events e ON e.id=r.event_id WHERE j.id=:id AND j.lease_token=:t");
    $st->execute(['id'=>$id,'t'=>$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function automation_flow_send_email(PDO $pdo, array $job, array $config, array $user): array
{
    $versionId=(int)($config['templateVersionId'] ?? 0);
    $st=$pdo->prepare('SELECT * FROM email_template_versions WHERE id=:id');$st->execute(['id'=>$versionId]);$version=$st->fetch(PDO::FETCH_ASSOC);
    if (!$version) throw new RuntimeException('Modelo do bloco de email nao encontrado.');
    if (email_is_suppressed($pdo, (string)($user['email'] ?? ''))) return ['skipped'=>'suppressed'];
    $user=email_flow_render_user($user, $job);
    $key=hash('sha256','automation|' . $job['run_id'] . '|' . $job['node_id'] . '|' . $job['user_id']);
    $id=email_send_rendered_message($pdo, email_settings($pdo), ['flow_run_id'=>(int)$job['run_id']], $user, $version, $key, ['automation_flow_id'=>(string)$job['flow_id'],'automation_run_id'=>(string)$job['run_id']]);
    return ['message_id'=>$id,'template_version_id'=>$versionId];
}

function automation_flow_process_job(PDO $pdo, array $job): string
{
    if (($job['run_status'] ?? '') !== 'running') return 'skipped';
    $graph=json_decode((string)$job['graph_json'], true) ?: [];
    $node=null; foreach ($graph['nodes'] ?? [] as $n) if (($n['id'] ?? '') === $job['node_id']) { $node=$n; break; }
    if (!$node) throw new RuntimeException('Bloco nao encontrado.');
    $type=(string)$node['type']; $config=is_array($node['config'] ?? null) ? $node['config'] : [];
    $user=buscar_usuario_por_id((int)$job['user_id']) ?: ['id'=>(int)$job['user_id']];
    $extra=json_decode((string)($job['event_payload'] ?? ''), true) ?: [];
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
        elseif ($type === 'action') { (($config['action'] ?? '') === 'remove_tag' ? remover_tag_usuario((int)$job['user_id'], (string)$config['tag']) : adicionar_tag((int)$job['user_id'], (string)$config['tag'], 'automation_flow', (int)$job['run_id'])); $output=['tag'=>$config['tag'] ?? '']; }
        elseif ($type === 'integration') $output=push_flow_dispatch_integration($pdo,$config,$user,$extra,$job);
        elseif ($type === 'trigger') $output=['event'=>$job['event_code']];
        elseif ($type === 'end') $output=['ended'=>true];
        else throw new RuntimeException('Bloco nao suportado.');
        $next = $type === 'end' ? null : automation_flow_next($graph, (string)$job['node_id'], $handle);
        $pdo->beginTransaction();
        if ($next) $pdo->prepare("INSERT IGNORE INTO automation_flow_jobs(run_id,node_id,status,available_at,input_json) VALUES(:r,:n,'queued',NOW(),'{}')")->execute(['r'=>$job['run_id'],'n'=>$next]);
        $output['next_node']=$next;
        $pdo->prepare("UPDATE automation_flow_jobs SET status='completed',output_json=:o,lease_token=NULL,lease_until=NULL,last_error=NULL WHERE id=:id AND lease_token=:t")->execute(['o'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$job['id'],'t'=>$job['lease_token']]);
        $pdo->prepare("UPDATE automation_flow_steps SET status='completed',output_json=:o,finished_at=NOW() WHERE id=:id")->execute(['o'=>json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$stepId]);
        $pending=$pdo->prepare("SELECT COUNT(*) FROM automation_flow_jobs WHERE run_id=:r AND status IN ('queued','retry','scheduled','processing')");$pending->execute(['r'=>$job['run_id']]);
        if ((int)$pending->fetchColumn() === 0) $pdo->prepare("UPDATE automation_flow_runs SET status='completed',finished_at=NOW() WHERE id=:id")->execute(['id'=>$job['run_id']]);
        $pdo->commit();
        return 'completed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err=mb_substr($e->getMessage(),0,1000);$attempt=(int)$job['attempts'];$max=(int)$job['max_attempts'];
        if ($attempt < $max) { $pdo->prepare("UPDATE automation_flow_jobs SET status='retry',available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")->execute(['e'=>$err,'id'=>$job['id'],'t'=>$job['lease_token']]); $status='retry'; }
        else { $pdo->prepare("UPDATE automation_flow_jobs SET status='failed',last_error=:e,lease_token=NULL,lease_until=NULL WHERE id=:id AND lease_token=:t")->execute(['e'=>$err,'id'=>$job['id'],'t'=>$job['lease_token']]); $pdo->prepare("UPDATE automation_flow_runs SET status='failed',last_error=:e,finished_at=NOW() WHERE id=:id")->execute(['e'=>$err,'id'=>$job['run_id']]); $status='failed'; }
        $pdo->prepare("UPDATE automation_flow_steps SET status=:s,error_message=:e,finished_at=NOW() WHERE id=:id")->execute(['s'=>$status,'e'=>$err,'id'=>$stepId]);
        return $status;
    }
}

function automation_flow_process_queue(PDO $pdo, int $limit = 50): array
{
    $done=['processed'=>0,'completed'=>0,'scheduled'=>0,'retry'=>0,'failed'=>0,'skipped'=>0];
    for ($i=0; $i<$limit; $i++) {
        $job=automation_flow_claim($pdo);
        if (!$job) break;
        $status=automation_flow_process_job($pdo,$job);
        $done['processed']++;
        if (isset($done[$status])) $done[$status]++;
    }
    return $done;
}
