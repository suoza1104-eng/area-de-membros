<?php
declare(strict_types=1);

require_once __DIR__ . '/push_flow_engine.php';

function push_campaigns_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    push_flow_engine_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_campaigns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        dispatch_type VARCHAR(20) NOT NULL DEFAULT 'instant',
        scheduled_at DATETIME NULL,
        filters_json LONGTEXT NOT NULL,
        total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
        eligible_recipients INT UNSIGNED NOT NULL DEFAULT 0,
        enqueued_recipients INT UNSIGNED NOT NULL DEFAULT 0,
        completed_runs INT UNSIGNED NOT NULL DEFAULT 0,
        failed_runs INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(1000) NULL,
        created_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_push_campaign_due (status,scheduled_at),
        KEY idx_push_campaign_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_campaign_flows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id BIGINT UNSIGNED NOT NULL,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        flow_name VARCHAR(150) NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_campaign_flow (campaign_id,flow_id,version_id),
        KEY idx_push_campaign_flow_campaign (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_campaign_recipients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        error_message VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        enqueued_at DATETIME NULL,
        UNIQUE KEY uk_push_campaign_recipient (campaign_id,user_id),
        KEY idx_push_campaign_recipient_queue (campaign_id,status,id),
        KEY idx_push_campaign_recipient_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_campaign_executions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id BIGINT UNSIGNED NOT NULL,
        recipient_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        flow_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NULL,
        run_id BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        error_message VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_push_campaign_execution (campaign_id,user_id,flow_id,version_id),
        KEY idx_push_campaign_execution_campaign (campaign_id,status),
        KEY idx_push_campaign_execution_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $index=$pdo->query("SHOW INDEX FROM push_notifications WHERE Key_name='idx_push_notification_target'");
        if(!$index||!$index->fetch(PDO::FETCH_ASSOC))$pdo->exec('ALTER TABLE push_notifications ADD INDEX idx_push_notification_target (target_type,target_value)');
    } catch(Throwable $ignored) {}
    $done = true;
}

function push_campaign_audience_where(array $filters, array &$params): string
{
    $where = ['u.id>0'];
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(u.nome LIKE :search OR u.email LIKE :search OR u.telefone LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    $turma = trim((string)($filters['turma'] ?? ''));
    if ($turma !== '') {
        $where[] = "(u.codigo_turma=:turma OR EXISTS(SELECT 1 FROM inscricao_logs il WHERE il.user_id=u.id AND il.codigo_turma=:turma_log))";
        $params['turma'] = $turma; $params['turma_log'] = $turma;
    }
    $tag = trim((string)($filters['tag'] ?? ''));
    if ($tag !== '') {
        $where[] = 'EXISTS(SELECT 1 FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=u.id AND t.nome=:tag)';
        $params['tag'] = $tag;
    }
    $progress = (string)($filters['progress'] ?? 'all');
    if ($progress === 'no_lesson') $where[] = "NOT EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.user_id=u.id AND lp.status='completed')";
    elseif ($progress === 'any_lesson') $where[] = "EXISTS(SELECT 1 FROM lesson_progress lp WHERE lp.user_id=u.id AND lp.status='completed')";
    elseif ($progress === 'completed_trail') $where[] = "(SELECT COUNT(DISTINCT lp.lesson_id) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id AND l.ativo=1 AND l.conta_para_conclusao=1 WHERE lp.user_id=u.id AND lp.status='completed') >= (SELECT COUNT(*) FROM lessons l2 WHERE l2.ativo=1 AND l2.conta_para_conclusao=1)";
    if (!empty($filters['only_push_enabled'])) {
        $where[] = "EXISTS(SELECT 1 FROM push_devices d WHERE d.user_id=u.id AND d.status='active' AND d.notification_permission='granted' AND d.token IS NOT NULL AND d.token<>'')";
    }
    return implode(' AND ', $where);
}

function push_campaign_preview(PDO $pdo, array $filters, int $limit = 30): array
{
    push_campaigns_ensure_schema($pdo);
    $params = [];$where = push_campaign_audience_where($filters,$params);
    $count=$pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");$count->execute($params);
    $sql="SELECT u.id,u.nome,u.email,u.telefone,u.codigo_turma,
        (SELECT COUNT(*) FROM push_devices d WHERE d.user_id=u.id AND d.status='active' AND d.notification_permission='granted' AND d.token IS NOT NULL AND d.token<>'') active_devices
        FROM users u WHERE {$where} ORDER BY u.id DESC LIMIT ".max(1,min(100,$limit));
    $st=$pdo->prepare($sql);$st->execute($params);
    return ['total'=>(int)$count->fetchColumn(),'users'=>$st->fetchAll(PDO::FETCH_ASSOC)?:[]];
}

function push_campaign_create(PDO $pdo, array $input, string $admin): int
{
    push_campaigns_ensure_schema($pdo);
    $name=mb_substr(trim((string)($input['name']??'')),0,180);if($name==='')throw new InvalidArgumentException('Informe o nome da campanha.');
    $type=(string)($input['dispatch_type']??'instant');if(!in_array($type,['instant','scheduled'],true))throw new InvalidArgumentException('Tipo de disparo inválido.');
    $scheduled=null;if($type==='scheduled'){$raw=trim((string)($input['scheduled_at']??''));try{$dt=new DateTimeImmutable($raw,new DateTimeZone('America/Sao_Paulo'));}catch(Throwable $e){throw new InvalidArgumentException('Data do agendamento inválida.');}if($dt<=new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))throw new InvalidArgumentException('Agende para uma data futura.');$scheduled=$dt->format('Y-m-d H:i:s');}
    $flowIds=array_values(array_unique(array_filter(array_map('intval',(array)($input['flow_ids']??[])),static fn($v)=>$v>0)));if(!$flowIds)throw new InvalidArgumentException('Selecione ao menos um fluxo publicado.');
    $marks=implode(',',array_fill(0,count($flowIds),'?'));$st=$pdo->prepare("SELECT f.id,f.name,f.current_version_id,v.version_number FROM push_flows f JOIN push_flow_versions v ON v.id=f.current_version_id WHERE f.status IN ('active','paused') AND f.id IN ({$marks})");$st->execute($flowIds);$flows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];if(count($flows)!==count($flowIds))throw new RuntimeException('Um dos fluxos selecionados não possui versão publicada.');
    $filters=is_array($input['filters']??null)?$input['filters']:[];$filters=['search'=>mb_substr(trim((string)($filters['search']??'')),0,150),'turma'=>mb_substr(trim((string)($filters['turma']??'')),0,100),'tag'=>mb_substr(trim((string)($filters['tag']??'')),0,100),'progress'=>(string)($filters['progress']??'all'),'only_push_enabled'=>!empty($filters['only_push_enabled'])];
    $params=[];$where=push_campaign_audience_where($filters,$params);
    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO push_campaigns (name,status,dispatch_type,scheduled_at,filters_json,created_by) VALUES (:name,:status,:type,:scheduled,:filters,:admin)")->execute(['name'=>$name,'status'=>$type==='scheduled'?'scheduled':'queued','type'=>$type,'scheduled'=>$scheduled,'filters'=>json_encode($filters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'admin'=>$admin]);$id=(int)$pdo->lastInsertId();
        $flowInsert=$pdo->prepare('INSERT INTO push_campaign_flows (campaign_id,flow_id,version_id,flow_name,version_number) VALUES (:campaign,:flow,:version,:name,:number)');foreach($flows as $flow)$flowInsert->execute(['campaign'=>$id,'flow'=>$flow['id'],'version'=>$flow['current_version_id'],'name'=>$flow['name'],'number'=>$flow['version_number']]);
        $insert="INSERT IGNORE INTO push_campaign_recipients (campaign_id,user_id,status) SELECT :campaign,u.id,'queued' FROM users u WHERE {$where}";$params['campaign']=$id;$aud=$pdo->prepare($insert);$aud->execute($params);$total=$aud->rowCount();
        if($total===0)throw new RuntimeException('Nenhum aluno corresponde aos filtros escolhidos.');
        $eligibleSt=$pdo->prepare("SELECT COUNT(*) FROM push_campaign_recipients cr WHERE cr.campaign_id=:id AND EXISTS(SELECT 1 FROM push_devices d WHERE d.user_id=cr.user_id AND d.status='active' AND d.notification_permission='granted' AND d.token IS NOT NULL AND d.token<>'')");$eligibleSt->execute(['id'=>$id]);$eligible=(int)$eligibleSt->fetchColumn();
        $pdo->prepare('UPDATE push_campaigns SET total_recipients=:total,eligible_recipients=:eligible WHERE id=:id')->execute(['total'=>$total,'eligible'=>$eligible,'id'=>$id]);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function push_flow_start_campaign_run(PDO $pdo, array $campaign, array $recipient, array $flow): array
{
    $version=$pdo->prepare('SELECT graph_json FROM push_flow_versions WHERE id=:version AND flow_id=:flow LIMIT 1');$version->execute(['version'=>$flow['version_id'],'flow'=>$flow['flow_id']]);$graphRaw=$version->fetchColumn();if($graphRaw===false)throw new RuntimeException('Versão do fluxo não encontrada.');$graph=push_flow_decode_graph((string)$graphRaw);$trigger=null;foreach($graph['nodes']??[] as $node)if(($node['type']??'')==='trigger'){$trigger=$node;break;}if(!$trigger)throw new RuntimeException('Fluxo sem gatilho inicial.');
    $source=hash('sha256','push-campaign|'.$campaign['id'].'|'.$recipient['user_id'].'|'.$flow['flow_id'].'|'.$flow['version_id']);$payload=json_encode(['event_id'=>'push-campaign-'.$campaign['id'].'-'.$recipient['user_id'].'-'.$flow['flow_id'],'campaign_id'=>(int)$campaign['id'],'campaign_name'=>(string)$campaign['name'],'origem'=>'push_campaign'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT IGNORE INTO push_flow_events (event_code,user_id,source_key,payload_json,matched_flows) VALUES ('CAMPANHA_PUSH',:user,:source,:payload,1)")->execute(['user'=>$recipient['user_id'],'source'=>$source,'payload'=>$payload]);$eventSt=$pdo->prepare('SELECT id FROM push_flow_events WHERE source_key=:source');$eventSt->execute(['source'=>$source]);$eventId=(int)$eventSt->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO push_flow_runs (flow_id,version_id,event_id,user_id,status) VALUES (:flow,:version,:event,:user,'running')")->execute(['flow'=>$flow['flow_id'],'version'=>$flow['version_id'],'event'=>$eventId,'user'=>$recipient['user_id']]);$runSt=$pdo->prepare('SELECT id FROM push_flow_runs WHERE flow_id=:flow AND version_id=:version AND event_id=:event');$runSt->execute(['flow'=>$flow['flow_id'],'version'=>$flow['version_id'],'event'=>$eventId]);$runId=(int)$runSt->fetchColumn();
    $input=json_encode(['event_id'=>$eventId,'event_code'=>'CAMPANHA_PUSH','campaign_id'=>(int)$campaign['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$pdo->prepare("INSERT IGNORE INTO push_flow_jobs (run_id,node_id,status,available_at,input_json) VALUES (:run,:node,'queued',NOW(),:input)")->execute(['run'=>$runId,'node'=>(string)$trigger['id'],'input'=>$input]);return ['event_id'=>$eventId,'run_id'=>$runId];
}

function push_campaign_process_due(PDO $pdo, int $recipientBatch = 100): array
{
    push_campaigns_ensure_schema($pdo);if(!push_flow_engine_enabled())return ['campaigns'=>0,'recipients'=>0,'executions'=>0,'paused'=>true];
    $pdo->exec("UPDATE push_campaigns SET status='processing',started_at=COALESCE(started_at,NOW()) WHERE status IN ('queued','scheduled') AND (scheduled_at IS NULL OR scheduled_at<=NOW())");
    $campaigns=$pdo->query("SELECT * FROM push_campaigns WHERE status='processing' ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC)?:[];$stats=['campaigns'=>count($campaigns),'recipients'=>0,'executions'=>0];
    foreach($campaigns as $campaign){
        $flowsSt=$pdo->prepare('SELECT * FROM push_campaign_flows WHERE campaign_id=:campaign ORDER BY id');$flowsSt->execute(['campaign'=>$campaign['id']]);$flows=$flowsSt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $recSt=$pdo->prepare("SELECT * FROM push_campaign_recipients WHERE campaign_id=:campaign AND status='queued' ORDER BY id LIMIT ".max(1,min(500,$recipientBatch)));$recSt->execute(['campaign'=>$campaign['id']]);$recipients=$recSt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($recipients as $recipient){$errors=[];foreach($flows as $flow){try{$pdo->beginTransaction();$execution=$pdo->prepare("INSERT IGNORE INTO push_campaign_executions (campaign_id,recipient_id,user_id,flow_id,version_id,status) VALUES (:campaign,:recipient,:user,:flow,:version,'queued')");$execution->execute(['campaign'=>$campaign['id'],'recipient'=>$recipient['id'],'user'=>$recipient['user_id'],'flow'=>$flow['flow_id'],'version'=>$flow['version_id']]);$started=push_flow_start_campaign_run($pdo,$campaign,$recipient,$flow);$pdo->prepare("UPDATE push_campaign_executions SET event_id=:event,run_id=:run,status='running',error_message=NULL WHERE campaign_id=:campaign AND user_id=:user AND flow_id=:flow AND version_id=:version")->execute(['event'=>$started['event_id'],'run'=>$started['run_id'],'campaign'=>$campaign['id'],'user'=>$recipient['user_id'],'flow'=>$flow['flow_id'],'version'=>$flow['version_id']]);$pdo->commit();$stats['executions']++;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=mb_substr($e->getMessage(),0,1000);$errors[]=$error;$pdo->prepare("INSERT INTO push_campaign_executions (campaign_id,recipient_id,user_id,flow_id,version_id,status,error_message) VALUES (:campaign,:recipient,:user,:flow,:version,'failed',:error) ON DUPLICATE KEY UPDATE status='failed',error_message=VALUES(error_message)")->execute(['campaign'=>$campaign['id'],'recipient'=>$recipient['id'],'user'=>$recipient['user_id'],'flow'=>$flow['flow_id'],'version'=>$flow['version_id'],'error'=>$error]);}}
            $status=$errors?'error':'enqueued';$pdo->prepare('UPDATE push_campaign_recipients SET status=:status,error_message=:error,enqueued_at=NOW() WHERE id=:id')->execute(['status'=>$status,'error'=>$errors?mb_substr(implode(' | ',$errors),0,1000):null,'id'=>$recipient['id']]);$stats['recipients']++;}
        $leftSt=$pdo->prepare("SELECT COUNT(*) FROM push_campaign_recipients WHERE campaign_id=:campaign AND status='queued'");$leftSt->execute(['campaign'=>$campaign['id']]);$left=(int)$leftSt->fetchColumn();
        $pdo->prepare("UPDATE push_campaign_executions ce JOIN push_flow_runs r ON r.id=ce.run_id SET ce.status=r.status,ce.error_message=r.last_error WHERE ce.campaign_id=:campaign AND ce.status IN ('queued','running') AND r.status IN ('completed','failed','cancelled')")->execute(['campaign'=>$campaign['id']]);
        $metrics=$pdo->prepare("SELECT SUM(status='enqueued') enqueued FROM push_campaign_recipients WHERE campaign_id=:campaign");$metrics->execute(['campaign'=>$campaign['id']]);$enqueued=(int)$metrics->fetchColumn();$runs=$pdo->prepare("SELECT SUM(status='completed') completed,SUM(status='failed') failed,SUM(status IN ('queued','running')) active FROM push_campaign_executions WHERE campaign_id=:campaign");$runs->execute(['campaign'=>$campaign['id']]);$runMetrics=$runs->fetch(PDO::FETCH_ASSOC)?:[];
        $done=$left===0&&(int)($runMetrics['active']??0)===0;$pdo->prepare("UPDATE push_campaigns SET enqueued_recipients=:enqueued,completed_runs=:completed,failed_runs=:failed,status=IF(:done=1,'completed','processing'),finished_at=IF(:done2=1,NOW(),NULL) WHERE id=:id")->execute(['enqueued'=>$enqueued,'completed'=>(int)($runMetrics['completed']??0),'failed'=>(int)($runMetrics['failed']??0),'done'=>$done?1:0,'done2'=>$done?1:0,'id'=>$campaign['id']]);
    }
    return $stats;
}
