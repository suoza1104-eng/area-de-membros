<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function support_chat_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_conversations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'open',
        priority VARCHAR(16) NOT NULL DEFAULT 'normal',
        channel VARCHAR(20) NOT NULL DEFAULT 'test',
        subject VARCHAR(180) NULL,
        assigned_to VARCHAR(150) NULL,
        assigned_name VARCHAR(150) NULL,
        stage VARCHAR(40) NOT NULL DEFAULT 'novo',
        tags_json TEXT NULL,
        notes TEXT NULL,
        unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
        unread_student INT UNSIGNED NOT NULL DEFAULT 0,
        student_last_seen_at DATETIME NULL,
        admin_last_seen_at DATETIME NULL,
        last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_support_user (user_id), KEY idx_support_status (status),
        KEY idx_support_assignee (assigned_to), KEY idx_support_last (last_message_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        sender_type VARCHAR(20) NOT NULL,
        sender_id VARCHAR(150) NULL,
        sender_name VARCHAR(150) NULL,
        message_type VARCHAR(20) NOT NULL DEFAULT 'text',
        body TEXT NULL,
        attachment_url VARCHAR(1000) NULL,
        attachment_name VARCHAR(255) NULL,
        attachment_mime VARCHAR(120) NULL,
        attachment_size BIGINT UNSIGNED NULL,
        metadata_json TEXT NULL,
        reply_to_id BIGINT UNSIGNED NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_support_message_conv (conversation_id,id), KEY idx_support_message_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_typing (
        conversation_id BIGINT UNSIGNED NOT NULL,
        actor_type VARCHAR(20) NOT NULL,
        actor_name VARCHAR(150) NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (conversation_id,actor_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_automation_flows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft', graph_json LONGTEXT NOT NULL,
        created_by VARCHAR(150) NULL, updated_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_support_flow_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_automation_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, flow_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'running',
        log_json LONGTEXT NULL, started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL, KEY idx_support_run_conv (conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_agent_memory (
        conversation_id BIGINT UNSIGNED PRIMARY KEY,
        summary LONGTEXT NULL,
        token_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_intent VARCHAR(80) NULL,
        last_confidence DECIMAL(4,2) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_agent_processed_messages (
        message_id BIGINT UNSIGNED PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'processing',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_support_agent_processed_conv (conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NULL,
        user_id INT NULL,
        event_type VARCHAR(60) NOT NULL,
        actor_type VARCHAR(30) NULL,
        actor_id VARCHAR(150) NULL,
        actor_name VARCHAR(150) NULL,
        action_type VARCHAR(80) NULL,
        turma_codigo VARCHAR(120) NULL,
        metadata_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_support_events_created (created_at),
        KEY idx_support_events_type_created (event_type,created_at),
        KEY idx_support_events_conv (conversation_id),
        KEY idx_support_events_user (user_id),
        KEY idx_support_events_turma (turma_codigo),
        KEY idx_support_events_action (action_type,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_feedback (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        comment TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_support_feedback_created (created_at),
        KEY idx_support_feedback_conv (conversation_id),
        KEY idx_support_feedback_user (user_id),
        KEY idx_support_feedback_rating (rating)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['support_chat_student_enabled'=>'0','support_chat_test_mode'=>'1','support_chat_button_mode'=>'fixed','support_chat_welcome'=>'Olá! Como podemos ajudar?','support_chat_offline_message'=>'Recebemos sua mensagem e responderemos assim que possível.','support_chat_font_scale'=>'1.08','support_chat_avatar_url'=>'','support_chat_display_name'=>'Suporte FERA','support_chat_sound_enabled'=>'1'] as $key=>$value) {
        $st=$pdo->prepare("INSERT IGNORE INTO settings (chave,valor) VALUES (:k,:v)");
        try {$st->execute(['k'=>$key,'v'=>$value]);} catch (Throwable $ignored) {}
    }
    foreach ([
        'support_chat_auto_close_minutes'=>'30',
        'support_chat_closing_message'=>'Obrigado pelo contato. Fico a disposicao sempre que precisar.',
        'support_chat_human_idle_close_hours'=>'24',
        'support_chat_human_idle_message'=>'Como nao tivemos retorno, vou encerrar este atendimento por inatividade. Se ainda precisar de ajuda, chame aqui de novo detalhando seu problema.',
        'support_chat_followup_variations_json'=>'["Te ajudo em algo mais, {primeiro_nome}?","Posso ajudar com mais alguma coisa, {primeiro_nome}?","Ficou alguma duvida que eu possa resolver, {primeiro_nome}?"]',
    ] as $key=>$value) {
        $st=$pdo->prepare("INSERT IGNORE INTO settings (chave,valor) VALUES (:k,:v)");
        try {$st->execute(['k'=>$key,'v'=>$value]);} catch (Throwable $ignored) {}
    }
    foreach ([
        'support_agent_enabled'=>'0',
        'support_agent_basic_enabled'=>'1',
        'support_agent_sales_enabled'=>'0',
        'support_agent_technical_enabled'=>'1',
        'support_agent_reschedule_enabled'=>'1',
        'support_agent_certificate_enabled'=>'0',
        'support_agent_group_enabled'=>'1',
        'support_agent_max_tokens'=>'3000',
        'support_agent_pause_seconds'=>'5',
        'support_agent_prompt_basic'=>support_agent_default_prompt('basic'),
        'support_agent_prompt_sales'=>support_agent_default_prompt('sales'),
        'support_agent_prompt_technical'=>support_agent_default_prompt('technical'),
        'support_agent_prompt_reschedule'=>support_agent_default_prompt('reschedule'),
        'support_agent_prompt_certificate'=>support_agent_default_prompt('certificate'),
        'support_agent_prompt_group'=>support_agent_default_prompt('group'),
        'support_agent_group_link_template'=>'https://mais.red/wpp/MCQDC_{{codigo_turma}}',
        'support_agent_handoff_message'=>'Vou encaminhar seu atendimento para uma pessoa da equipe analisar com seguranca.',
        'support_agent_variable_map_json'=>json_encode(support_agent_default_variable_map(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'support_crm_stages_json'=>'[{"id":"agent","label":"Com agente","condition":"status=open"},{"id":"human","label":"Humano pendente","condition":"stage=human"},{"id":"done","label":"Concluido","condition":"status=closed"}]',
    ] as $key=>$value) {
        $st=$pdo->prepare("INSERT IGNORE INTO settings (chave,valor) VALUES (:k,:v)");
        try {$st->execute(['k'=>$key,'v'=>$value]);} catch (Throwable $ignored) {}
    }
    foreach(['support_agent_prompt_basic'=>'basic','support_agent_prompt_sales'=>'sales','support_agent_prompt_technical'=>'technical'] as $key=>$type){try{$pdo->prepare("UPDATE settings SET valor=:v WHERE chave=:k AND (valor='' OR valor LIKE 'Responda duvidas basicas%' OR valor LIKE 'Quando vendas estiver ativo%' OR valor LIKE 'Ajude em problemas tecnicos comuns%')")->execute(['v'=>support_agent_default_prompt($type),'k'=>$key]);}catch(Throwable $ignored){}}
    try{$pdo->prepare("UPDATE settings SET valor=:v WHERE chave='support_agent_variable_map_json' AND (valor='' OR valor IS NULL)")->execute(['v'=>json_encode(support_agent_default_variable_map(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}catch(Throwable $ignored){}
}

function support_chat_table_exists(PDO $pdo,string $table): bool {try{$st=$pdo->prepare("SHOW TABLES LIKE :t");$st->execute(['t'=>$table]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}
function support_chat_column_exists(PDO $pdo,string $table,string $column): bool {try{$st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");$st->execute(['c'=>$column]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}

function support_chat_user_turma(PDO $pdo,int $userId): string
{
    if($userId<=0||!support_chat_table_exists($pdo,'users'))return '';
    try{$st=$pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");$st->execute(['id'=>$userId]);$u=$st->fetch(PDO::FETCH_ASSOC)?:[];foreach(['codigo_turma','turma_codigo','turma','utm_campaign'] as $c){$v=trim((string)($u[$c]??''));if($v!=='')return mb_substr($v,0,120);}}catch(Throwable $ignored){}
    return '';
}

function support_chat_log_event(PDO $pdo,string $eventType,int $conversationId=0,int $userId=0,string $actorType='',string $actorId='',string $actorName='',string $actionType='',array $metadata=[]): void
{
    try{
        if(!support_chat_table_exists($pdo,'support_events'))return;
        if($userId<=0&&$conversationId>0){$st=$pdo->prepare("SELECT user_id FROM support_conversations WHERE id=:id LIMIT 1");$st->execute(['id'=>$conversationId]);$userId=(int)$st->fetchColumn();}
        $turma=support_chat_user_turma($pdo,$userId);
        $pdo->prepare("INSERT INTO support_events(conversation_id,user_id,event_type,actor_type,actor_id,actor_name,action_type,turma_codigo,metadata_json) VALUES(:c,:u,:e,:at,:ai,:an,:ac,:t,:m)")->execute([
            'c'=>$conversationId>0?$conversationId:null,'u'=>$userId>0?$userId:null,'e'=>mb_substr($eventType,0,60),
            'at'=>$actorType!==''?mb_substr($actorType,0,30):null,'ai'=>$actorId!==''?mb_substr($actorId,0,150):null,'an'=>$actorName!==''?mb_substr($actorName,0,150):null,
            'ac'=>$actionType!==''?mb_substr($actionType,0,80):null,'t'=>$turma!==''?$turma:null,'m'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null
        ]);
    }catch(Throwable $ignored){}
}

function support_chat_period_bounds(string $from,string $to): array
{
    $to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)?$to:date('Y-m-d');
    $from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)?$from:date('Y-m-d',strtotime('-29 days'));
    if(strtotime($from)>strtotime($to))[$from,$to]=[$to,$from];
    return [$from.' 00:00:00',$to.' 23:59:59',substr($from,0,10),substr($to,0,10)];
}

function support_chat_bucket_sql(string $bucket,string $column='created_at'): array
{
    if($bucket==='month')return ["DATE_FORMAT($column,'%Y-%m')","DATE_FORMAT($column,'%m/%Y')"];
    if($bucket==='week')return ["YEARWEEK($column,3)","CONCAT('Sem ',DATE_FORMAT(DATE_SUB(DATE($column),INTERVAL WEEKDAY($column) DAY),'%d/%m'))"];
    return ["DATE($column)","DATE_FORMAT($column,'%d/%m')"];
}

function support_chat_fetch_pairs(PDO $pdo,string $sql,array $params=[]): array
{
    try{$st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return [];}
}

function support_chat_analytics(PDO $pdo,string $from,string $to,string $bucket='day'): array
{
    [$fromDt,$toDt,$fromDate,$toDate]=support_chat_period_bounds($from,$to);if(!in_array($bucket,['day','week','month'],true))$bucket='day';[$keyExpr,$labelExpr]=support_chat_bucket_sql($bucket,'created_at');
    $params=['from'=>$fromDt,'to'=>$toDt];
    $clickParams=['cfrom1'=>$fromDt,'cto1'=>$toDt];$clickParts=["SELECT created_at FROM support_events WHERE event_type='support_button_click' AND created_at BETWEEN :cfrom1 AND :cto1"];
    if(support_chat_table_exists($pdo,'webhook_logs')&&support_chat_column_exists($pdo,'webhook_logs','evento')&&support_chat_column_exists($pdo,'webhook_logs','created_at')){
        $first=support_chat_fetch_pairs($pdo,"SELECT MIN(created_at) first_at FROM support_events WHERE event_type='support_button_click'");
        $firstAt=(string)($first[0]['first_at']??'');$clickParams['cfrom2']=$fromDt;$clickParams['cto2']=$toDt;
        $oldLimit=$firstAt!==''?" AND created_at<:first_click_event":'';if($firstAt!=='')$clickParams['first_click_event']=$firstAt;
        $clickParts[]="SELECT created_at FROM webhook_logs WHERE evento='BOTAO_HELP' AND created_at BETWEEN :cfrom2 AND :cto2{$oldLimit}";
    }
    $clickSource='('.implode(' UNION ALL ',$clickParts).') click_src';[$clickKey,$clickLabel]=support_chat_bucket_sql($bucket,'click_src.created_at');
    $clicks=support_chat_fetch_pairs($pdo,"SELECT {$clickKey} k,{$clickLabel} label,COUNT(*) total FROM {$clickSource} GROUP BY k,label ORDER BY k",$clickParams);
    $started=support_chat_fetch_pairs($pdo,"SELECT {$keyExpr} k,{$labelExpr} label,COUNT(*) total,SUM(channel='app') app,SUM(channel='test') test FROM support_conversations WHERE created_at BETWEEN :from AND :to GROUP BY k,label ORDER BY k",$params);
    [$closedKey,$closedLabel]=support_chat_bucket_sql($bucket,'closed_at');
    $closed=support_chat_fetch_pairs($pdo,"SELECT {$closedKey} k,{$closedLabel} label,COUNT(*) closed_total,SUM(EXISTS(SELECT 1 FROM support_messages m WHERE m.conversation_id=support_conversations.id AND m.sender_type='admin')) closed_human,SUM(EXISTS(SELECT 1 FROM support_messages m WHERE m.conversation_id=support_conversations.id AND m.sender_type='bot') AND NOT EXISTS(SELECT 1 FROM support_messages m2 WHERE m2.conversation_id=support_conversations.id AND m2.sender_type='admin')) closed_ai FROM support_conversations WHERE status='closed' AND closed_at BETWEEN :from AND :to GROUP BY k,label ORDER BY k",$params);
    $status=support_chat_fetch_pairs($pdo,"SELECT status label,COUNT(*) total FROM support_conversations GROUP BY status ORDER BY total DESC");
    [$agentKey,$agentLabel]=support_chat_bucket_sql($bucket,'a.dt');
    $agents=support_chat_fetch_pairs($pdo,"SELECT a.agent,{$agentKey} k,{$agentLabel} label,SUM(a.started) started,SUM(a.closed_total) closed_total FROM (SELECT COALESCE(NULLIF(assigned_name,''),'Sem atendente') agent,created_at dt,1 started,0 closed_total FROM support_conversations WHERE created_at BETWEEN :afrom1 AND :ato1 UNION ALL SELECT COALESCE(NULLIF(assigned_name,''),'Sem atendente') agent,closed_at dt,0 started,1 closed_total FROM support_conversations WHERE status='closed' AND closed_at BETWEEN :afrom2 AND :ato2) a GROUP BY a.agent,k,label ORDER BY k,a.agent",['afrom1'=>$fromDt,'ato1'=>$toDt,'afrom2'=>$fromDt,'ato2'=>$toDt]);
    $turmas=support_chat_fetch_pairs($pdo,"SELECT COALESCE(NULLIF(COALESCE(u.codigo_turma,u.turma_codigo,u.turma,u.utm_campaign),''),'Sem turma') turma,COUNT(*) total FROM support_conversations c JOIN users u ON u.id=c.user_id WHERE c.created_at BETWEEN :from AND :to GROUP BY turma ORDER BY total DESC LIMIT 12",$params);
    $actions=support_chat_fetch_pairs($pdo,"SELECT COALESCE(NULLIF(action_type,''),'outras') label,COUNT(*) total FROM support_events WHERE event_type='ai_action' AND created_at BETWEEN :from AND :to GROUP BY label ORDER BY total DESC",$params);
    $logs=support_chat_fetch_pairs($pdo,"SELECT e.*,u.nome user_name,u.email user_email FROM support_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.created_at BETWEEN :from AND :to ORDER BY e.id DESC LIMIT 160",$params);
    $one=static fn(string $sql,array $p=[])=> (int)((support_chat_fetch_pairs($pdo,$sql,$p)[0]['n']??0));
    $kpis=[
        'clicks'=>$one("SELECT COUNT(*) n FROM {$clickSource}",$clickParams),
        'started'=>$one("SELECT COUNT(*) n FROM support_conversations WHERE created_at BETWEEN :from AND :to",$params),
        'open'=>$one("SELECT COUNT(*) n FROM support_conversations WHERE status<>'closed'"),
        'closed'=>$one("SELECT COUNT(*) n FROM support_conversations WHERE status='closed' AND closed_at BETWEEN :from AND :to",$params),
        'human_closed'=>$one("SELECT COUNT(*) n FROM support_conversations c WHERE c.status='closed' AND c.closed_at BETWEEN :from AND :to AND EXISTS(SELECT 1 FROM support_messages m WHERE m.conversation_id=c.id AND m.sender_type='admin')",$params),
        'ai_closed'=>$one("SELECT COUNT(*) n FROM support_conversations c WHERE c.status='closed' AND c.closed_at BETWEEN :from AND :to AND EXISTS(SELECT 1 FROM support_messages m WHERE m.conversation_id=c.id AND m.sender_type='bot') AND NOT EXISTS(SELECT 1 FROM support_messages m2 WHERE m2.conversation_id=c.id AND m2.sender_type='admin')",$params),
    ];
    return ['from'=>$fromDate,'to'=>$toDate,'bucket'=>$bucket,'kpis'=>$kpis,'clicks'=>$clicks,'started'=>$started,'closed'=>$closed,'status'=>$status,'agents'=>$agents,'turmas'=>$turmas,'actions'=>$actions,'logs'=>$logs];
}

function support_chat_first_name(array $conv): string
{
    $name=trim((string)($conv['user_name']??''));
    return $name!==''?(explode(' ',$name)[0]??''):'';
}

function support_chat_random_followup(PDO $pdo,array $conv): string
{
    $raw=(string)get_setting('support_chat_followup_variations_json','');
    $items=json_decode($raw,true);
    if(!is_array($items)||!$items)$items=['Te ajudo em algo mais, {primeiro_nome}?','Posso ajudar com mais alguma coisa, {primeiro_nome}?'];
    $text=(string)$items[array_rand($items)];
    $first=support_chat_first_name($conv);
    return trim(str_replace('{primeiro_nome}',$first!==''?$first:'',str_replace(', {primeiro_nome}', $first!==''?', {primeiro_nome}':'', $text)));
}

function support_agent_is_closing_message(string $body): bool
{
    $plain=mb_strtolower(trim(preg_replace('/\s+/u',' ',$body)));
    if($plain===''||mb_strlen($plain)>140)return false;
    $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$plain);
    $plain=$ascii!==false?$ascii:$plain;
    $plain=preg_replace('/[^a-z0-9\s]+/i',' ',$plain);
    $plain=trim(preg_replace('/\s+/',' ',$plain));
    if($plain==='')return false;
    if(preg_match('/\bnao\s+(consigo|consegui|abre|abriu|funciona|recebi|entendi|sei|aparece|esta|tenho)\b/',$plain))return false;
    $positive=['obrigado','obrigada','obg','valeu','vlw','beleza','blz','show','ok','certo','perfeito','resolvido','resolveu','deu certo','entendido'];
    $closing=['so isso','era isso','nada','nada mais','nao precisa','nao obrigado','por enquanto nao','pode fechar','pode encerrar','sem mais duvidas','tudo certo','tudo resolvido'];
    foreach($closing as $term)if($plain===$term||str_contains($plain,$term))return true;
    foreach($positive as $term)if($plain===$term)return true;
    foreach($positive as $ok)foreach($closing as $end)if(str_contains($plain,$ok)&&str_contains($plain,$end))return true;
    if(preg_match('/\b(encerrar|fechar|finalizar)\s+(o\s+)?atendimento\b/',$plain))return true;
    if(preg_match('/^(ok|certo|perfeito|obrigado|obrigada|obg|valeu|vlw)\s+(obrigado|obrigada|obg|valeu|vlw)$/',$plain))return true;

    $b=mb_strtolower(trim(preg_replace('/\s+/u',' ',$body)));
    if($b===''||mb_strlen($b)>90)return false;
    if(preg_match('/\b(n[aã]o|nao)\s+(consigo|consegui|abre|abriu|funciona|recebi|entendi|sei|aparece|est[aá]|tenho)\b/u',$b))return false;
    $patterns=[
        '/^(obrigad[ao]|obg|valeu|vlw|beleza|blz|show|ok|certo|perfeito|resolvido|resolveu|deu certo)[.! ]*$/u',
        '/^(n[aã]o|nao|n[aã]o obrigado|nao obrigado|n[aã]o precisa|nao precisa|nada|s[oó] isso|so isso|era isso|por enquanto n[aã]o|por enquanto nao)[.! ]*$/u',
        '/\b(ok|certo|perfeito|obrigad[ao]|obg|valeu|vlw).{0,20}\b(s[oÃ³] isso|so isso|nada mais|n[aÃ£]o precisa|nao precisa|pode fechar)\b/u',
        '/\b(s[oÃ³] isso mesmo|so isso mesmo|tudo certo|tudo resolvido|era isso mesmo|sem mais duvidas)\b/u',
        '/\b(pode encerrar|pode fechar|encerrar atendimento|fechar atendimento|finalizar atendimento)\b/u',
    ];
    foreach($patterns as $p)if(preg_match($p,$b))return true;
    return false;
}

function support_chat_last_message(PDO $pdo,int $conversationId): ?array
{
    $st=$pdo->prepare("SELECT * FROM support_messages WHERE conversation_id=:c ORDER BY id DESC LIMIT 1");
    $st->execute(['c'=>$conversationId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function support_chat_message_meta(?array $message): array
{
    if(!$message)return [];
    $meta=json_decode((string)($message['metadata_json']??''),true);
    return is_array($meta)?$meta:[];
}

function support_chat_has_pending_close_prompt(PDO $pdo,int $conversationId): bool
{
    $last=support_chat_last_message($pdo,$conversationId);
    $meta=support_chat_message_meta($last);
    return !empty($meta['close_prompt'])&&($last['sender_type']??'')!=='student';
}

function support_chat_send_close_prompt(PDO $pdo,int $conversationId,string $actorType='bot',string $actorName='Agente de suporte'): void
{
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv||($conv['status']??'')==='closed')return;
    if(support_chat_has_pending_close_prompt($pdo,$conversationId))return;
    support_chat_send($pdo,$conversationId,$actorType,$actorType==='admin'?'admin':'support_agent',$actorName,support_chat_random_followup($pdo,$conv),[],['close_prompt'=>true]);
    support_chat_log_event($pdo,'close_prompt_sent',$conversationId,(int)($conv['user_id']??0),$actorType,$actorType==='admin'?'admin':'support_agent',$actorName,'ask_more_help');
}

function support_chat_close_with_feedback(PDO $pdo,int $conversationId,string $actorType='bot',string $actorName='Agente de suporte',bool $sendClosing=true): void
{
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv)return;
    if((string)($conv['status']??'')==='closed')return;
    if($sendClosing){
        $message=trim((string)get_setting('support_chat_closing_message','Obrigado pelo contato. Fico a disposicao sempre que precisar.'));
        if($message==='')$message='Obrigado pelo contato. Fico a disposicao sempre que precisar.';
        support_chat_send($pdo,$conversationId,$actorType,$actorType==='admin'?'admin':'support_agent',$actorName,$message,[],[
            'feedback'=>['prompt'=>'Como foi seu atendimento?','scale'=>[0,1,2,3,4,5],'conversation_id'=>$conversationId],
        ]);
    }
    $pdo->prepare("UPDATE support_conversations SET status='closed',stage='done',closed_at=NOW() WHERE id=:id")->execute(['id'=>$conversationId]);
    support_chat_log_event($pdo,'conversation_closed',$conversationId,(int)($conv['user_id']??0),$actorType,$actorType==='admin'?'admin':'support_agent',$actorName,'feedback_requested');
}

function support_chat_submit_feedback(PDO $pdo,int $conversationId,int $userId,int $rating,string $comment): void
{
    if($conversationId<=0||$userId<=0)throw new RuntimeException('Atendimento invalido.');
    $rating=max(0,min(5,$rating));$comment=mb_substr(trim($comment),0,3000);
    $st=$pdo->prepare("SELECT id FROM support_feedback WHERE conversation_id=:c AND user_id=:u ORDER BY id DESC LIMIT 1");$st->execute(['c'=>$conversationId,'u'=>$userId]);$id=(int)$st->fetchColumn();
    if($id>0)$pdo->prepare("UPDATE support_feedback SET rating=:r,comment=:m WHERE id=:id")->execute(['r'=>$rating,'m'=>$comment!==''?$comment:null,'id'=>$id]);
    else $pdo->prepare("INSERT INTO support_feedback(conversation_id,user_id,rating,comment) VALUES(:c,:u,:r,:m)")->execute(['c'=>$conversationId,'u'=>$userId,'r'=>$rating,'m'=>$comment!==''?$comment:null]);
    support_chat_log_event($pdo,'feedback_submitted',$conversationId,$userId,'student',(string)$userId,'Aluno','fps',['rating'=>$rating]);
}

function support_chat_close_for_human_inactivity(PDO $pdo,int $conversationId): void
{
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv||($conv['status']??'')==='closed')return;
    $message=trim((string)get_setting('support_chat_human_idle_message','Como nao tivemos retorno, vou encerrar este atendimento por inatividade. Se ainda precisar de ajuda, chame aqui de novo detalhando seu problema.'));
    if($message==='')$message='Como nao tivemos retorno, vou encerrar este atendimento por inatividade. Se ainda precisar de ajuda, chame aqui de novo detalhando seu problema.';
    support_chat_send($pdo,$conversationId,'bot','support_inactivity','Central de suporte',$message,[],['closed_by_inactivity'=>true]);
    $pdo->prepare("UPDATE support_conversations SET status='closed',stage='agent',assigned_to=NULL,assigned_name=NULL,closed_at=NOW() WHERE id=:id")->execute(['id'=>$conversationId]);
    support_chat_log_event($pdo,'conversation_closed',$conversationId,(int)($conv['user_id']??0),'bot','support_inactivity','Central de suporte','human_inactivity',['previous_assigned_name'=>(string)($conv['assigned_name']??'')]);
}

function support_chat_auto_close_idle(PDO $pdo): int
{
    $minutes=max(0,min(10080,(int)get_setting('support_chat_auto_close_minutes','30')));if($minutes<=0)return 0;
    $sql="SELECT c.id FROM support_conversations c WHERE c.status<>'closed' AND c.stage='agent' AND (c.assigned_name IS NULL OR c.assigned_name='') AND c.last_message_at<=DATE_SUB(NOW(),INTERVAL {$minutes} MINUTE) AND COALESCE((SELECT m.sender_type FROM support_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1),'')<>'student' LIMIT 30";
    $ids=array_map('intval',$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[]);
    foreach($ids as $id){
        if(support_chat_has_pending_close_prompt($pdo,$id))support_chat_close_with_feedback($pdo,$id,'bot','Agente de suporte',true);
        else support_chat_send_close_prompt($pdo,$id,'bot','Agente de suporte');
    }
    $hours=max(0,min(720,(int)get_setting('support_chat_human_idle_close_hours','24')));
    if($hours>0){
        $sql="SELECT c.id FROM support_conversations c WHERE c.status<>'closed' AND c.assigned_name IS NOT NULL AND c.assigned_name<>'' AND c.last_message_at<=DATE_SUB(NOW(),INTERVAL {$hours} HOUR) AND COALESCE((SELECT m.sender_type FROM support_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1),'')='admin' LIMIT 30";
        $humanIds=array_map('intval',$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[]);
        foreach($humanIds as $id)support_chat_close_for_human_inactivity($pdo,$id);
        $ids=array_merge($ids,$humanIds);
    }
    return count($ids);
}

function support_chat_feedback_analytics(PDO $pdo,string $from,string $to,string $bucket='day',string $turma=''): array
{
    [$fromDt,$toDt,$fromDate,$toDate]=support_chat_period_bounds($from,$to);if(!in_array($bucket,['day','week','month'],true))$bucket='day';[$keyExpr,$labelExpr]=support_chat_bucket_sql($bucket,'f.created_at');
    $turmaCols=[];foreach(['codigo_turma','turma_codigo','turma','utm_campaign'] as $col)if(support_chat_column_exists($pdo,'users',$col))$turmaCols[]="u.`{$col}`";$turmaExpr=$turmaCols?('COALESCE('.implode(',',$turmaCols).", '')"):"''";
    $params=['from'=>$fromDt,'to'=>$toDt];$where="f.created_at BETWEEN :from AND :to";
    if(trim($turma)!==''){$where.=" AND {$turmaExpr} LIKE :turma";$params['turma']='%'.trim($turma).'%';}
    $trend=support_chat_fetch_pairs($pdo,"SELECT {$keyExpr} k,{$labelExpr} label,COUNT(*) total,ROUND(AVG(f.rating),2) avg_rating FROM support_feedback f JOIN support_conversations c ON c.id=f.conversation_id JOIN users u ON u.id=f.user_id WHERE {$where} GROUP BY k,label ORDER BY k",$params);
    $dist=support_chat_fetch_pairs($pdo,"SELECT f.rating label,COUNT(*) total FROM support_feedback f JOIN support_conversations c ON c.id=f.conversation_id JOIN users u ON u.id=f.user_id WHERE {$where} GROUP BY f.rating ORDER BY f.rating",$params);
    $comments=support_chat_fetch_pairs($pdo,"SELECT f.*,u.nome user_name,u.email user_email,{$turmaExpr} turma FROM support_feedback f JOIN support_conversations c ON c.id=f.conversation_id JOIN users u ON u.id=f.user_id WHERE {$where} ORDER BY f.id DESC LIMIT 200",$params);
    $summary=support_chat_fetch_pairs($pdo,"SELECT COUNT(*) responses,ROUND(AVG(f.rating),2) avg_rating FROM support_feedback f JOIN support_conversations c ON c.id=f.conversation_id JOIN users u ON u.id=f.user_id WHERE {$where}",$params)[0]??['responses'=>0,'avg_rating'=>0];
    return ['from'=>$fromDate,'to'=>$toDate,'bucket'=>$bucket,'turma'=>$turma,'summary'=>$summary,'trend'=>$trend,'distribution'=>$dist,'comments'=>$comments];
}

function support_chat_feedback_ai_analysis(PDO $pdo,array $data): string
{
    $comments=array_slice(array_map(static fn($r)=>['nota'=>(int)($r['rating']??0),'aluno'=>(string)($r['user_name']??''),'turma'=>(string)($r['turma']??''),'opiniao'=>(string)($r['comment']??'')],$data['comments']??[]),0,120);
    $responses=(int)($data['summary']['responses']??0);$avg=(string)($data['summary']['avg_rating']??'0');
    if($responses<=0)return "Sem respostas de FPS no filtro selecionado.";
    $cfg=support_agent_config($pdo);$apiKey=(string)($cfg['api_key']??'');
    if($apiKey==='')return "Resumo: {$responses} resposta(s), nota media {$avg}.\n\nPontos criticos: configure a chave OpenAI para uma analise qualitativa completa dos textos.\n\nPontos positivos e melhorias devem ser avaliados a partir das opinioes da tabela.";
    $input=[['role'=>'system','content'=>'Analise pesquisas de atendimento FPS de uma central de suporte. Entregue em portugues: resumo, pontos criticos, pontos positivos, melhorias e conclusao. Seja objetivo e use somente os dados enviados.'],['role'=>'user','content'=>json_encode(['resumo'=>$data['summary']??[],'distribuicao'=>$data['distribution']??[],'comentarios'=>$comments],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
    $payload=['model'=>$cfg['model']??'gpt-4.1-mini','input'=>$input,'max_output_tokens'=>1200];
    if(strpos((string)($cfg['model']??''),'gpt-5')!==0)$payload['temperature']=0.2;
    $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>60]);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$raw==='')throw new RuntimeException('Falha ao chamar IA: '.$err);$decoded=json_decode($raw,true);if($code<200||$code>=300)throw new RuntimeException('IA HTTP '.$code.': '.mb_substr((string)($decoded['error']['message']??$raw),0,600));
    $text=(string)($decoded['output_text']??'');if($text==='')foreach($decoded['output']??[] as $out)foreach($out['content']??[] as $c)if(isset($c['text']))$text.=(string)$c['text'];
    return trim($text)!==''?trim($text):'A IA nao retornou texto para este filtro.';
}

function support_agent_default_prompt(string $type): string
{
    $common="Use apenas os dados do payload_aluno. A mensagem atual do aluno fica em mensagem_atual; memoria e historico_recente sao apenas contexto. Nao invente informacao. Se o pedido do aluno nao estiver coberto pelo prompt nem pelos dados do banco/payload, transfira para humano. Se faltar dado para responder com certeza, transfira para humano. Seja direto, humano e natural. Cumprimente apenas na primeira resposta do agente.";
    if($type==='sales')return $common." Em vendas, responda somente se houver preco, oferta, curso ou condicao no payload/contexto. Se nao houver, colete a duvida e transfira para humano. Nao prometa bonus, desconto, prazo ou garantia que nao esteja no payload.";
    if($type==='technical')return $common." Em suporte tecnico, ajude com login, acesso ao curso, video/audio, link de acesso, grupo, live e certificado. Para erro com arquivo, imagem ou situacao sem diagnostico no payload, transfira para humano.";
    if($type==='reschedule')return $common." Reagendamento de live: ofereca somente datas presentes em reagendamento.opcoes. Quando o aluno escolher uma data listada, confirme o reagendamento.";
    if($type==='certificate')return $common." Certificado: primeiro confira se todas as aulas obrigatorias foram concluidas, se houve live/aula ao vivo e se a aula 5 consta concluida. Se estiver apto, peca a senha do certificado. Com senha correta, o sistema pode gerar o certificado e enviar o link. Com senha errada, informe que nao conferiu e peca para verificar.";
    if($type==='group')return $common." Grupo de alunos: envie o link do grupo somente quando existir em payload_aluno.links.link_grupo_configurado ou em payload_aluno.links.grupos_whatsapp. Se nao existir link, transfira para humano.";
    return $common." Regras de certificado: antes de transferir para humano, avalie certificado.tem_certificado_emitido, certificado.link_verificacao, certificado.pdf_url, certificado.link_emitir, aulas obrigatorias, aulas concluidas, aulas faltantes, percentual de avanco, aula 5, data_live e eventos de live. Para emitir, o aluno precisa ter concluido todas as aulas obrigatorias, assistido a live/aula ao vivo, ter a senha da aula 5 e a senha da aula ao vivo. Se ja existe certificado emitido e houver link_verificacao/pdf_url, mande o link. Se nao existe certificado emitido e os criterios estiverem ok, mande o link_emitir e oriente a usar as duas senhas na tela de emissao. Se ainda falta algo, explique exatamente o que falta. Nao use certificado.link_emitir como certificado pronto quando o certificado ja foi emitido. Para grupo e acesso, use os links do payload quando existirem.";
}

function support_agent_default_variable_map(): array
{
    return [
        ['key'=>'link_certificado_emitido','label'=>'Link do certificado emitido','path'=>'certificado.link_verificacao','description'=>'Use quando o certificado ja foi emitido. Se existir PDF, use pdf_certificado_emitido como alternativa.'],
        ['key'=>'pdf_certificado_emitido','label'=>'PDF do certificado emitido','path'=>'certificado.pdf_url','description'=>'URL direta do PDF do certificado, quando o sistema ja gerou o arquivo.'],
        ['key'=>'link_emitir_certificado','label'=>'Link para emitir certificado','path'=>'certificado.link_emitir','description'=>'Use apenas quando nao existe certificado emitido e o aluno ja concluiu as aulas obrigatorias.'],
        ['key'=>'link_acesso','label'=>'Link de acesso direto','path'=>'links.acesso_direto_area_membros','description'=>'Magic link de acesso temporario para o aluno entrar na area de membros.'],
        ['key'=>'data_live','label'=>'Data da live','path'=>'aluno.data_live','description'=>'Data da aula ao vivo vinculada ao aluno.'],
        ['key'=>'link_grupo','label'=>'Link do grupo','path'=>'links.grupos_whatsapp.0.invite_url','description'=>'Primeiro link de convite de grupo WhatsApp encontrado para a turma/campanha.'],
    ];
}

function support_agent_payload_field_options(): array
{
    return [
        ['path'=>'aluno.id','label'=>'ID do aluno','description'=>'Identificador interno do aluno.'],
        ['path'=>'aluno.nome','label'=>'Nome do aluno','description'=>'Nome cadastrado do aluno.'],
        ['path'=>'aluno.email','label'=>'E-mail do aluno','description'=>'E-mail cadastrado do aluno.'],
        ['path'=>'aluno.telefone','label'=>'Telefone do aluno','description'=>'Telefone cadastrado do aluno.'],
        ['path'=>'aluno.data_inscricao','label'=>'Data de inscricao','description'=>'Data em que o aluno entrou no sistema.'],
        ['path'=>'aluno.codigo_turma','label'=>'Codigo da turma','description'=>'Turma/campanha vinculada ao aluno.'],
        ['path'=>'aluno.data_live','label'=>'Data da live','description'=>'Data da aula ao vivo vinculada ao aluno.'],
        ['path'=>'aluno.campos_personalizados','label'=>'Campos personalizados','description'=>'Campos extras do cadastro/importacao do aluno.'],
        ['path'=>'engajamento.aulas_assistidas','label'=>'Aulas assistidas','description'=>'Lista de aulas que o aluno assistiu.'],
        ['path'=>'engajamento.aulas_concluidas','label'=>'Total de aulas concluidas','description'=>'Quantidade de aulas obrigatorias concluidas.'],
        ['path'=>'engajamento.aulas_obrigatorias','label'=>'Total de aulas obrigatorias','description'=>'Quantidade de aulas exigidas para concluir.'],
        ['path'=>'engajamento.percentual_avanco','label'=>'Percentual de avanco','description'=>'Percentual de conclusao do curso.'],
        ['path'=>'engajamento.eventos','label'=>'Eventos do aluno','description'=>'Eventos acionados pelo aluno com datas.'],
        ['path'=>'certificado.tem_certificado_emitido','label'=>'Certificado ja emitido','description'=>'Indica se o aluno ja possui certificado emitido.'],
        ['path'=>'certificado.link_verificacao','label'=>'Link do certificado emitido','description'=>'Link publico para verificar/abrir certificado ja emitido.'],
        ['path'=>'certificado.pdf_url','label'=>'PDF do certificado emitido','description'=>'URL direta do PDF do certificado, quando existir.'],
        ['path'=>'certificado.link_emitir','label'=>'Link para emitir certificado','description'=>'Link para iniciar emissao quando o aluno ainda nao tem certificado emitido.'],
        ['path'=>'certificado.criterios_e_pendencias','label'=>'Pendencias do certificado','description'=>'O que falta para o aluno poder emitir certificado.'],
        ['path'=>'certificado.pode_iniciar_emissao_agora','label'=>'Pode emitir agora','description'=>'Indica se o aluno ja cumpre os criterios para emitir.'],
        ['path'=>'links.acesso_direto_area_membros','label'=>'Link de acesso direto','description'=>'Magic link temporario para o aluno acessar a area de membros.'],
        ['path'=>'links.grupos_whatsapp.0.invite_url','label'=>'Link do grupo WhatsApp','description'=>'Primeiro link de convite do grupo relacionado ao aluno.'],
        ['path'=>'live.eventos','label'=>'Eventos da live','description'=>'Eventos do aluno na live, como acesso, permanencia e oferta.'],
        ['path'=>'live.reagendamentos','label'=>'Reagendamentos da live','description'=>'Historico de reagendamentos de live do aluno.'],
        ['path'=>'reagendamento.opcoes','label'=>'Opcoes para reagendar live','description'=>'Datas que o agente pode oferecer ao aluno.'],
        ['path'=>'tags','label'=>'Tags do aluno','description'=>'Tags atuais vinculadas ao aluno.'],
        ['path'=>'compras','label'=>'Compras do aluno','description'=>'Cursos/produtos comprados pelo aluno.'],
    ];
}

function support_chat_admin_identity(): array
{
    $name=trim((string)($_SESSION['equipe_nome'] ?? $_SESSION['admin_nome'] ?? 'Administrador'));
    $id=(string)($_SESSION['equipe_id'] ?? $_SESSION['admin_user'] ?? 'admin');
    return ['id'=>$id,'name'=>$name !== '' ? $name : 'Administrador'];
}

function support_chat_get_or_create(PDO $pdo,int $userId,string $channel='test'): int
{
    if ($userId<=0) throw new InvalidArgumentException('Aluno inválido.');
    $u=$pdo->prepare("SELECT id FROM users WHERE id=:id LIMIT 1");$u->execute(['id'=>$userId]);
    if (!$u->fetchColumn()) throw new RuntimeException('Aluno não encontrado.');
    $st=$pdo->prepare("SELECT id FROM support_conversations WHERE user_id=:u ORDER BY id DESC LIMIT 1");
    $st->execute(['u'=>$userId]);$id=(int)$st->fetchColumn();if($id>0)return $id;
    $pdo->prepare("INSERT INTO support_conversations(user_id,channel,subject) VALUES(:u,:c,'Atendimento pelo aplicativo')")->execute(['u'=>$userId,'c'=>$channel]);
    $id=(int)$pdo->lastInsertId();support_chat_log_event($pdo,'conversation_started',$id,$userId,'student',(string)$userId,'Aluno','',['channel'=>$channel]);return $id;
}

function support_chat_conversations(PDO $pdo,string $filter='open',array $criteria=[]): array
{
    $where=$filter==='all'?'1=1':($filter==='unassigned'?"c.status<>'closed' AND c.assigned_to IS NULL":($filter==='closed'?"c.status='closed'":"c.status<>'closed'"));
    $turmaCols=[];foreach(['codigo_turma','turma_codigo','turma','utm_campaign'] as $col)if(support_chat_column_exists($pdo,'users',$col))$turmaCols[]="u.`{$col}`";$turmaExpr=$turmaCols?('COALESCE('.implode(',',$turmaCols).", '')"):"''";
    $params=[];$q=trim((string)($criteria['q']??''));if($q!==''){$where.=" AND (u.nome LIKE :q OR u.email LIKE :q OR u.telefone LIKE :q OR {$turmaExpr} LIKE :q".(ctype_digit($q)?" OR c.id=:qid OR u.id=:qid":"").")";$params['q']='%'.$q.'%';if(ctype_digit($q))$params['qid']=(int)$q;}
    $from=trim((string)($criteria['date_from']??''));if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$where.=" AND c.created_at>=:from";$params['from']=$from.' 00:00:00';}
    $to=trim((string)($criteria['date_to']??''));if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$where.=" AND c.created_at<=:to";$params['to']=$to.' 23:59:59';}
    $assignee=trim((string)($criteria['assignee']??''));if($assignee==='__ia__')$where.=" AND c.status<>'closed' AND c.stage='agent' AND (c.assigned_name IS NULL OR c.assigned_name='')";elseif($assignee!==''){$where.=" AND c.assigned_name=:assignee";$params['assignee']=$assignee;}
    $st=$pdo->prepare("SELECT c.*,u.nome user_name,u.email user_email,u.telefone user_phone,{$turmaExpr} user_turma,
        (SELECT body FROM support_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_body,
        (SELECT message_type FROM support_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_type
        FROM support_conversations c JOIN users u ON u.id=c.user_id WHERE {$where} ORDER BY c.last_message_at DESC,c.id DESC LIMIT 200");$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function support_chat_detail(PDO $pdo,int $conversationId): ?array
{
    $st=$pdo->prepare("SELECT c.*,u.nome user_name,u.email user_email,u.telefone user_phone FROM support_conversations c JOIN users u ON u.id=c.user_id WHERE c.id=:id LIMIT 1");
    $st->execute(['id'=>$conversationId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function support_chat_assign_conversation(PDO $pdo,int $conversationId,string $assignedName,string $actorId,string $actorName,string $reason='manual'): bool
{
    $assignedName=trim($assignedName);if($conversationId<=0||$assignedName==='')return false;
    $st=$pdo->prepare("SELECT assigned_name,status,stage,user_id FROM support_conversations WHERE id=:id LIMIT 1");$st->execute(['id'=>$conversationId]);$conv=$st->fetch(PDO::FETCH_ASSOC);if(!$conv)return false;
    $current=trim((string)($conv['assigned_name']??''));if(mb_strtolower($current)===mb_strtolower($assignedName)&&(string)($conv['status']??'')!=='closed'&&(string)($conv['stage']??'')==='em_atendimento')return false;
    $pdo->prepare("UPDATE support_conversations SET assigned_to=:a,assigned_name=:n,status='open',stage='em_atendimento',closed_at=NULL WHERE id=:id")->execute(['a'=>$assignedName,'n'=>$assignedName,'id'=>$conversationId]);
    support_chat_log_event($pdo,'assignment',$conversationId,(int)($conv['user_id']??0),'admin',$actorId,$actorName,'atribuir',['assigned_name'=>$assignedName,'previous_assigned_name'=>$current,'reason'=>$reason]);
    return true;
}

function support_chat_assignment_history(PDO $pdo,int $conversationId): array
{
    if($conversationId<=0)return [];
    return support_chat_fetch_pairs($pdo,"SELECT created_at,actor_name,actor_type,metadata_json FROM support_events WHERE conversation_id=:c AND event_type='assignment' ORDER BY id DESC LIMIT 80",['c'=>$conversationId]);
}

function support_chat_messages(PDO $pdo,int $conversationId,int $after=0): array
{
    $st=$pdo->prepare("SELECT * FROM support_messages WHERE conversation_id=:c AND id>:a ORDER BY id ASC LIMIT 300");
    $st->execute(['c'=>$conversationId,'a'=>$after]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function support_chat_recent_duplicate_id(PDO $pdo,int $conversationId,string $senderType,string $body,int $seconds=4): int
{
    $body=trim($body);if($conversationId<=0||$body==='')return 0;
    $st=$pdo->prepare("SELECT id FROM support_messages WHERE conversation_id=:c AND sender_type=:s AND message_type='text' AND attachment_url IS NULL AND body=:b AND created_at>=DATE_SUB(NOW(),INTERVAL :sec SECOND) ORDER BY id DESC LIMIT 1");
    $st->bindValue(':c',$conversationId,PDO::PARAM_INT);$st->bindValue(':s',$senderType);$st->bindValue(':b',$body);$st->bindValue(':sec',max(1,min(30,$seconds)),PDO::PARAM_INT);$st->execute();
    return (int)$st->fetchColumn();
}

function support_chat_store_upload(array $file): array
{
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber o arquivo.');
    if ((int)($file['size']??0)>20*1024*1024) throw new RuntimeException('O arquivo deve ter no máximo 20 MB.');
    $tmp=(string)$file['tmp_name'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'application/octet-stream';
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','audio/webm'=>'webm','audio/ogg'=>'ogg','audio/mpeg'=>'mp3','audio/mp4'=>'m4a','video/webm'=>'webm','video/mp4'=>'mp4','video/quicktime'=>'mov','application/pdf'=>'pdf','text/plain'=>'txt','application/zip'=>'zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
    if(!isset($allowed[$mime]))throw new RuntimeException('Tipo de arquivo não permitido.');
    $dir=__DIR__.'/../public/uploads/support_chat/'.date('Y/m');if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Falha ao preparar uploads.');
    $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];if(!move_uploaded_file($tmp,$dir.'/'.$name))throw new RuntimeException('Falha ao salvar o arquivo.');
    $original=(string)($file['name']??'arquivo');
    $type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'audio/')||preg_match('/^audio-/i',$original)?'audio':(str_starts_with($mime,'video/')?'video':'file'));
    return ['url'=>'uploads/support_chat/'.date('Y/m').'/'.$name,'name'=>mb_substr($original,0,255),'mime'=>$mime,'size'=>(int)$file['size'],'type'=>$type];
}

function support_chat_store_avatar(array $file): string
{
    if (($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return '';
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem.');
    if ((int)($file['size']??0)>3*1024*1024) throw new RuntimeException('A imagem deve ter no máximo 3 MB.');
    $tmp=(string)$file['tmp_name'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'application/octet-stream';
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if(!isset($allowed[$mime]))throw new RuntimeException('Envie uma imagem JPG, PNG, WEBP ou GIF.');
    $dir=__DIR__.'/../public/uploads/support_chat/avatar';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Falha ao preparar upload da imagem.');
    $name='support-avatar-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];if(!move_uploaded_file($tmp,$dir.'/'.$name))throw new RuntimeException('Falha ao salvar a imagem.');
    return 'uploads/support_chat/avatar/'.$name;
}

function support_chat_send(PDO $pdo,int $conversationId,string $senderType,string $senderId,string $senderName,string $body,array $attachment=[],array $metadata=[]): int
{
    $body=trim($body);if($body===''&&!$attachment)throw new InvalidArgumentException('Digite uma mensagem ou anexe um arquivo.');
    if(mb_strlen($body)>10000)throw new InvalidArgumentException('Mensagem muito longa.');
    if(!$attachment&&$body!==''){
        $dupeWindow=in_array($senderType,['bot','admin'],true)?90:12;
        $duplicateId=support_chat_recent_duplicate_id($pdo,$conversationId,$senderType,$body,$dupeWindow);
        if($duplicateId>0)return $duplicateId;
    }
    $type=(string)($attachment['type']??'text');
    $st=$pdo->prepare("INSERT INTO support_messages(conversation_id,sender_type,sender_id,sender_name,message_type,body,attachment_url,attachment_name,attachment_mime,attachment_size,metadata_json) VALUES(:c,:st,:si,:sn,:mt,:b,:url,:an,:am,:az,:meta)");
    $st->execute(['c'=>$conversationId,'st'=>$senderType,'si'=>$senderId,'sn'=>$senderName,'mt'=>$type,'b'=>$body!==''?$body:null,'url'=>$attachment['url']??null,'an'=>$attachment['name']??null,'am'=>$attachment['mime']??null,'az'=>$attachment['size']??null,'meta'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
    $id=(int)$pdo->lastInsertId();$student=$senderType==='student';
    support_chat_log_event($pdo,'message_sent',$conversationId,0,$senderType,$senderId,$senderName,$type,['message_id'=>$id,'has_attachment'=>!empty($attachment)]);
    $pdo->prepare("UPDATE support_conversations SET last_message_at=NOW(),stage=IF(status='closed','agent',stage),assigned_to=IF(status='closed',NULL,assigned_to),assigned_name=IF(status='closed',NULL,assigned_name),closed_at=IF(status='closed',NULL,closed_at),status=IF(status='closed','open',status),unread_admin=unread_admin+:ua,unread_student=unread_student+:us WHERE id=:id")
        ->execute(['ua'=>$student?1:0,'us'=>$student?0:1,'id'=>$conversationId]);
    if($senderType==='admin')$pdo->prepare("UPDATE support_conversations SET status='open' WHERE id=:id AND status='pending' AND stage='human'")->execute(['id'=>$conversationId]);
    if(!$student && get_setting('support_chat_student_enabled','0')==='1') {
        try { support_chat_push_student($pdo,$conversationId,$id,$body!==''?$body:'Você recebeu um novo anexo.'); }
        catch(Throwable $e) { @error_log('support_chat_push: '.$e->getMessage()); }
    }
    return $id;
}

function support_chat_push_student(PDO $pdo,int $conversationId,int $messageId,string $body): void
{
    require_once __DIR__.'/push_notifications.php';push_ensure_schema($pdo);
    $st=$pdo->prepare("SELECT user_id,student_last_seen_at FROM support_conversations WHERE id=:id");$st->execute(['id'=>$conversationId]);$conv=$st->fetch(PDO::FETCH_ASSOC);if(!$conv)return;
    if(!empty($conv['student_last_seen_at'])&&strtotime((string)$conv['student_last_seen_at'])>time()-30)return;
    $devices=$pdo->prepare("SELECT * FROM push_devices WHERE user_id=:u AND status='active' AND notification_permission='granted' AND token IS NOT NULL AND token<>''");$devices->execute(['u'=>(int)$conv['user_id']]);$rows=$devices->fetchAll(PDO::FETCH_ASSOC)?:[];if(!$rows)return;
    $title='Nova mensagem do suporte';$text=mb_substr(trim(preg_replace('/\s+/u',' ',$body)),0,180);$target='support_message:'.$messageId;
    $pdo->prepare("INSERT INTO push_notifications(title,body,click_url,target_type,target_value,total_targets,status,created_by) VALUES(:t,:b,'trilha.php?abrir_suporte=1','support_chat',:v,:n,'processing','Central de suporte')")->execute(['t'=>$title,'b'=>$text,'v'=>$target,'n'=>count($rows)]);$notificationId=(int)$pdo->lastInsertId();$accepted=0;$failed=0;
    foreach($rows as $device){$pdo->prepare("INSERT INTO push_delivery_logs(notification_id,device_id,user_id,status) VALUES(:n,:d,:u,'queued')")->execute(['n'=>$notificationId,'d'=>$device['id'],'u'=>$conv['user_id']]);$deliveryId=(int)$pdo->lastInsertId();$result=push_send_to_device($pdo,$device,$notificationId,$deliveryId,$title,$text,'trilha.php?abrir_suporte=1');empty($result['accepted'])?$failed++:$accepted++;}
    $pdo->prepare("UPDATE push_notifications SET accepted_count=:a,failed_count=:f,status=:s,finished_at=NOW() WHERE id=:id")->execute(['a'=>$accepted,'f'=>$failed,'s'=>$failed?'sent_with_failures':'sent','id'=>$notificationId]);
}

function support_agent_prepare_answer(string $answer,bool $firstAgentReply): array
{
    if(!$firstAgentReply){$clean=preg_replace('/^\s*(ol[aá]|oi|bom dia|boa tarde|boa noite)[,!.\s]*(?:[A-ZÁÀÂÃÉÊÍÓÔÕÚÇ][^.!?\n]{0,70})?[.!?\s]*/iu','',$answer,1);if(is_string($clean)&&trim($clean)!=='')$answer=trim($clean);}
    $buttons=[];if(preg_match('/https?:\/\/[^\s<>"\']+/iu',$answer,$m)){ $url=rtrim($m[0],".,;:)");$label=(stripos($url,'verificar_certificado')!==false||stripos($url,'uploads/certificates')!==false)?'Ver certificado':(stripos($url,'certificado.php')!==false?'Emitir certificado':'Abrir link');$answer=trim((string)preg_replace('/\s*[:;-]\s*$/u','',str_replace($m[0],'',$answer)));$buttons[]=['label'=>$label,'url'=>$url];if($answer==='')$answer='Use o botao abaixo para abrir o link.'; }
    return ['body'=>$answer,'metadata'=>$buttons?['buttons'=>$buttons]:[]];
}

function support_agent_split_answer(string $body,int $limit=650): array
{
    $body=trim($body);if(strlen($body)<=$limit)return[$body];
    $parts=[];$rest=$body;while(strlen($rest)>$limit&&count($parts)<4){$slice=substr($rest,0,$limit);$cuts=[strrpos($slice,"\n"),strrpos($slice,'. '),strrpos($slice,'! '),strrpos($slice,'? '),strrpos($slice,' ')];$cut=max(array_map(static fn($v)=>$v===false?0:(int)$v,$cuts));if($cut<120)$cut=$limit;$parts[]=trim(substr($rest,0,$cut+1));$rest=trim(substr($rest,$cut+1));}
    if($rest!=='')$parts[]=trim($rest);return array_values(array_filter($parts));
}

function support_agent_send_answer(PDO $pdo,int $conversationId,string $answer,bool $firstAgentReply,bool $sendFollowup=true): void
{
    $prepared=support_agent_prepare_answer($answer,$firstAgentReply);$parts=support_agent_split_answer((string)$prepared['body']);
    foreach($parts as $i=>$part){$last=$i===count($parts)-1;support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',$part,[],$last?$prepared['metadata']:[]);if(!$last){support_chat_typing($pdo,$conversationId,'bot','Agente de suporte');sleep(random_int(4,6));}}
    if($sendFollowup&&!preg_match('/como posso ajudar|em que posso ajudar/iu',(string)$prepared['body'])){
        $conv=support_chat_detail($pdo,$conversationId);
        if($conv&&($follow=support_chat_random_followup($pdo,$conv))!=='')support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',$follow);
    }
}

function support_agent_normalize_answer_for_payload(string $answer,array $payload): string
{
    $cert=$payload['certificado']??[];if(empty($cert['tem_certificado_emitido']))return $answer;
    $emit=(string)($cert['link_emitir']??'');$preferred=(string)($cert['pdf_url']??'');if($preferred==='')$preferred=(string)($cert['link_verificacao']??'');
    if($emit!==''&&$preferred!==''&&str_contains($answer,$emit))$answer=str_replace($emit,$preferred,$answer);
    return $answer;
}

function support_chat_mark_read(PDO $pdo,int $conversationId,string $viewer): void
{
    $sender=$viewer==='admin'?'student':'admin';$column=$viewer==='admin'?'unread_admin':'unread_student';$seen=$viewer==='admin'?'admin_last_seen_at':'student_last_seen_at';
    $pdo->prepare("UPDATE support_messages SET read_at=COALESCE(read_at,NOW()) WHERE conversation_id=:c AND sender_type=:s")->execute(['c'=>$conversationId,'s'=>$sender]);
    $pdo->prepare("UPDATE support_conversations SET {$column}=0,{$seen}=NOW() WHERE id=:c")->execute(['c'=>$conversationId]);
}

function support_chat_typing(PDO $pdo,int $conversationId,string $actor,string $name): void
{
    $pdo->prepare("INSERT INTO support_typing(conversation_id,actor_type,actor_name,expires_at) VALUES(:c,:a,:n,DATE_ADD(NOW(),INTERVAL 4 SECOND)) ON DUPLICATE KEY UPDATE actor_name=VALUES(actor_name),expires_at=VALUES(expires_at)")->execute(['c'=>$conversationId,'a'=>$actor,'n'=>$name]);
}

function support_chat_typing_state(PDO $pdo,int $conversationId,string $exclude): array
{
    $st=$pdo->prepare("SELECT actor_type,actor_name FROM support_typing WHERE conversation_id=:c AND actor_type<>:e AND expires_at>NOW()");$st->execute(['c'=>$conversationId,'e'=>$exclude]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function support_agent_config(PDO $pdo): array
{
    return [
        'enabled'=>get_setting('support_agent_enabled','0')==='1',
        'basic'=>get_setting('support_agent_basic_enabled','1')==='1',
        'sales'=>get_setting('support_agent_sales_enabled','0')==='1',
        'technical'=>get_setting('support_agent_technical_enabled','1')==='1',
        'reschedule'=>get_setting('support_agent_reschedule_enabled','1')==='1',
        'certificate'=>get_setting('support_agent_certificate_enabled','0')==='1',
        'group'=>get_setting('support_agent_group_enabled','1')==='1',
        'max_tokens'=>max(500,min(12000,(int)get_setting('support_agent_max_tokens','3000'))),
        'pause_seconds'=>max(0,min(30,(int)get_setting('support_agent_pause_seconds','5'))),
        'model'=>trim((string)get_setting('whatsapp_ai_model','gpt-4.1-mini'))?:'gpt-4.1-mini',
        'api_key'=>trim((string)get_setting('whatsapp_ai_openai_api_key','')),
        'temperature'=>max(0,min(1,(float)get_setting('whatsapp_ai_temperature','0.2'))),
        'prompt_basic'=>trim((string)get_setting('support_agent_prompt_basic',''))?:support_agent_default_prompt('basic'),
        'prompt_sales'=>trim((string)get_setting('support_agent_prompt_sales',''))?:support_agent_default_prompt('sales'),
        'prompt_technical'=>trim((string)get_setting('support_agent_prompt_technical',''))?:support_agent_default_prompt('technical'),
        'prompt_reschedule'=>trim((string)get_setting('support_agent_prompt_reschedule',''))?:support_agent_default_prompt('reschedule'),
        'prompt_certificate'=>trim((string)get_setting('support_agent_prompt_certificate',''))?:support_agent_default_prompt('certificate'),
        'prompt_group'=>trim((string)get_setting('support_agent_prompt_group',''))?:support_agent_default_prompt('group'),
        'group_link_template'=>trim((string)get_setting('support_agent_group_link_template','https://mais.red/wpp/MCQDC_{{codigo_turma}}')),
        'handoff_message'=>(string)get_setting('support_agent_handoff_message','Vou encaminhar seu atendimento para uma pessoa da equipe analisar com seguranca.'),
        'transcription_model'=>trim((string)get_setting('whatsapp_ai_transcription_model','gpt-4o-mini-transcribe'))?:'gpt-4o-mini-transcribe',
    ];
}

function support_agent_user_field_description(string $field): string
{
    $map=[
        'id'=>'ID interno do aluno.','nome'=>'Nome do aluno.','email'=>'E-mail cadastrado.','telefone'=>'Telefone/WhatsApp cadastrado.','created_at'=>'Data de inscricao/cadastro.','criado_em'=>'Data de inscricao/cadastro.',
        'codigo_turma'=>'Codigo da turma/campanha do aluno.','turma_codigo'=>'Codigo da turma/campanha do aluno.','turma'=>'Turma do aluno.','data_live'=>'Data da aula ao vivo gravada no aluno.','turma_live_at'=>'Data da aula ao vivo gravada no aluno.',
        'acesso_vitalicio'=>'Indica se o aluno comprou ou recebeu acesso vitalicio.','utm_source'=>'Origem UTM da inscricao.','utm_medium'=>'Midia UTM da inscricao.','utm_campaign'=>'Campanha UTM da inscricao.','utm_content'=>'Conteudo UTM.','utm_term'=>'Termo UTM.',
    ];return $map[$field]??('Campo personalizado do usuario: '.$field.'.');
}

function support_agent_user_public_fields(PDO $pdo,array $user): array
{
    $blocked=['senha','password','remember_token','token','reset_token','magic_token','api_key','secret','hash'];$out=[];
    foreach($user as $k=>$v){$lk=strtolower((string)$k);$deny=false;foreach($blocked as $b)if(str_contains($lk,$b)){$deny=true;break;}if($deny)continue;$out[$k]=['valor'=>$v,'descricao'=>support_agent_user_field_description((string)$k)];}
    return $out;
}

function support_agent_variable_map(PDO $pdo): array
{
    $map=json_decode((string)get_setting('support_agent_variable_map_json',''),true);if(!is_array($map)||!$map)$map=support_agent_default_variable_map();
    return array_values(array_filter($map,static fn($r)=>is_array($r)&&trim((string)($r['key']??''))!==''&&trim((string)($r['path']??''))!==''));
}

function support_agent_path_value(array $payload,string $path)
{
    $value=$payload;foreach(explode('.',$path) as $part){if($part==='')continue;if(is_array($value)&&array_key_exists($part,$value))$value=$value[$part];else return null;}return $value;
}

function support_agent_configured_variables(PDO $pdo,array $payload): array
{
    $out=[];foreach(support_agent_variable_map($pdo) as $row){$key=preg_replace('/[^a-z0-9_-]/i','',(string)$row['key']);if($key==='')continue;$out[$key]=['label'=>(string)($row['label']??$key),'path'=>(string)$row['path'],'description'=>(string)($row['description']??''),'value'=>support_agent_path_value($payload,(string)$row['path'])];}return $out;
}

function support_agent_user_payload(PDO $pdo,int $userId): array
{
    $st=$pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");$st->execute(['id'=>$userId]);$user=$st->fetch(PDO::FETCH_ASSOC)?:[];
    if(!$user)throw new RuntimeException('Aluno nao encontrado para o agente.');
    $tags=[];if(support_chat_table_exists($pdo,'user_tags')&&support_chat_table_exists($pdo,'tags')){try{$tagDesc=support_chat_column_exists($pdo,'tags','descricao')?'t.descricao':"'' AS descricao";$q=$pdo->prepare("SELECT t.nome,{$tagDesc},ut.created_at FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=:u ORDER BY ut.created_at DESC");$q->execute(['u'=>$userId]);$tags=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $lessons=[];$required=0;$done=0;$missing=[];if(support_chat_table_exists($pdo,'lessons')){try{if(support_chat_table_exists($pdo,'lesson_progress')){$q=$pdo->prepare("SELECT l.id,l.titulo,l.ordem,l.conta_para_conclusao,COALESCE(lp.status,'not_started') status,lp.updated_at FROM lessons l LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.user_id=:u WHERE l.ativo=1 ORDER BY l.ordem,l.id");$q->execute(['u'=>$userId]);$lessons=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}else{$lessons=$pdo->query("SELECT id,titulo,ordem,conta_para_conclusao,'not_started' status,NULL updated_at FROM lessons WHERE ativo=1 ORDER BY ordem,id")->fetchAll(PDO::FETCH_ASSOC)?:[];}$required=count(array_filter($lessons,static fn($r)=>(int)($r['conta_para_conclusao']??0)===1));if($required<=0)$required=count($lessons);foreach($lessons as $ls){$counts=(int)($ls['conta_para_conclusao']??0)===1||$required===count($lessons);if(!$counts)continue;if(($ls['status']??'')==='completed')$done++;else $missing[]=['id'=>(int)$ls['id'],'titulo'=>(string)($ls['titulo']??('Aula '.$ls['id'])),'ordem'=>$ls['ordem']??null,'status'=>(string)($ls['status']??'not_started')];}}catch(Throwable $ignored){}}
    $views=[];if(support_chat_table_exists($pdo,'lesson_view_events')){try{$q=$pdo->prepare("SELECT l.titulo,l.ordem,MIN(v.viewed_at) first_viewed_at,MAX(v.viewed_at) last_viewed_at,COUNT(*) views FROM lesson_view_events v LEFT JOIN lessons l ON l.id=v.lesson_id WHERE v.user_id=:u GROUP BY v.lesson_id,l.titulo,l.ordem ORDER BY l.ordem,l.titulo LIMIT 80");$q->execute(['u'=>$userId]);$views=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $certificates=[];if(support_chat_table_exists($pdo,'certificates')){try{$q=$pdo->prepare("SELECT id,course,codigo_uid,status,emitido_em,pdf_url,created_at,updated_at FROM certificates WHERE user_id=:u ORDER BY id DESC LIMIT 10");$q->execute(['u'=>$userId]);$certificates=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $live=[];if(support_chat_table_exists($pdo,'live_event_recebimentos')&&support_chat_table_exists($pdo,'live_events')){try{$q=$pdo->prepare("SELECT le.tipo,MIN(ler.created_at) first_at,MAX(ler.created_at) last_at,COUNT(*) total FROM live_event_recebimentos ler JOIN live_events le ON le.id=ler.event_id WHERE ler.user_id=:u AND ler.status='processado' GROUP BY le.tipo ORDER BY last_at DESC");$q->execute(['u'=>$userId]);$live=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $reschedules=[];if(support_chat_table_exists($pdo,'reagendamentos_live')){try{$q=$pdo->prepare("SELECT status,old_turma_live_at,new_turma_live_at,created_at FROM reagendamentos_live WHERE user_id=:u ORDER BY id DESC LIMIT 10");$q->execute(['u'=>$userId]);$reschedules=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $groupLinks=[];$turmaCodigo=(string)($user['codigo_turma']??$user['turma_codigo']??'');if($turmaCodigo!==''&&support_chat_table_exists($pdo,'whatsapp_group_campaigns')&&support_chat_table_exists($pdo,'whatsapp_group_campaign_groups')){try{$q=$pdo->prepare("SELECT c.name campanha,c.public_url,g.group_name,g.invite_url,g.current_members,g.max_members FROM whatsapp_group_campaigns c LEFT JOIN whatsapp_group_campaign_groups g ON g.campaign_id=c.id AND g.is_active=1 WHERE c.status='active' AND (c.slug=:t OR c.name=:t OR g.group_name=:t) ORDER BY g.is_current DESC,g.updated_at DESC LIMIT 5");$q->execute(['t'=>$turmaCodigo]);$groupLinks=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $groupTemplate=trim((string)get_setting('support_agent_group_link_template','https://mais.red/wpp/MCQDC_{{codigo_turma}}'));
    $configuredGroupLink='';
    if($groupTemplate!==''&&$turmaCodigo!=='')$configuredGroupLink=str_replace(['{{codigo_turma}}','{{codigo da turma}}','{{turma}}'],rawurlencode($turmaCodigo),$groupTemplate);
    $coursePct=$required>0?(int)floor(($done/max(1,$required))*100):0;$issuedCert=null;foreach($certificates as $cert)if(($cert['status']??'')==='emitido'){$issuedCert=$cert;break;}
    $certVerify=$issuedCert&&!empty($issuedCert['codigo_uid'])?rtrim((string)BASE_URL,'/').'/verificar_certificado.php?c='.rawurlencode((string)$issuedCert['codigo_uid']):'';
    $magicLink=function_exists('gerar_magic_link')?gerar_magic_link($userId,30,false):'';
    $payload=[
        'aluno'=>[
            'id'=>(int)$user['id'],'nome'=>(string)($user['nome']??''),'email'=>(string)($user['email']??''),'telefone'=>(string)($user['telefone']??''),
            'data_inscricao'=>(string)($user['created_at']??$user['criado_em']??''),'codigo_turma'=>$turmaCodigo,
            'data_live'=>(string)($user['turma_live_at']??$user['data_live']??''),'acesso_vitalicio'=>(int)($user['acesso_vitalicio']??0)===1,
        ],
        'campos_personalizados_usuario'=>support_agent_user_public_fields($pdo,$user),
        'links'=>[
            'acesso_direto_area_membros'=>$magicLink,
            'area_do_aluno'=>rtrim((string)BASE_URL,'/').'/trilha.php',
            'emitir_certificado'=>rtrim((string)BASE_URL,'/').'/certificado.php',
            'verificar_certificado_emitido'=>$certVerify,
            'pdf_certificado_emitido'=>(string)($issuedCert['pdf_url']??''),
            'link_grupo_configurado'=>$configuredGroupLink,
            'template_grupo_whatsapp'=>$groupTemplate,
            'grupos_whatsapp'=>$groupLinks,
        ],
        'tags'=>$tags,
        'curso'=>['aulas_obrigatorias'=>$required,'aulas_concluidas'=>$done,'percentual_avanco'=>$coursePct,'aulas_faltantes_obrigatorias'=>$missing,'aulas'=>$lessons,'visualizacoes'=>$views],
        'certificado'=>[
            'tem_certificado_emitido'=>$issuedCert!==null,
            'pode_iniciar_emissao_agora'=>$required>0&&$done>=$required,
            'regra_emissao'=>'Para emitir o certificado, o aluno precisa concluir todas as aulas obrigatorias, assistir a live/aula ao vivo, ter a senha da aula 5 e a senha da aula ao vivo; depois confirma a senha do certificado e o nome na tela de emissao.',
            'criterios_e_pendencias'=>[
                'aulas_obrigatorias_total'=>$required,'aulas_obrigatorias_concluidas'=>$done,'percentual_obrigatorio'=>$coursePct,
                'aulas_faltantes'=>$missing,
                'senhas_necessarias'=>['senha_da_aula_5','senha_da_aula_ao_vivo'],
                'senha_certificado'=>'Necessaria na tela de emissao; nao informe senha se ela nao estiver no payload/contexto.',
            ],
            'link_emitir'=>rtrim((string)BASE_URL,'/').'/certificado.php','link_verificacao'=>$certVerify,'pdf_url'=>(string)($issuedCert['pdf_url']??''),'registros'=>$certificates
        ],
        'live'=>['eventos'=>$live,'reagendamentos'=>$reschedules],
        'reagendamento'=>['opcoes'=>support_agent_available_reschedule_slots($pdo,2)],
    ];
    $payload['variaveis_configuradas']=support_agent_configured_variables($pdo,$payload);
    return $payload;
}

function support_agent_available_reschedule_slots(PDO $pdo,int $qty=2): array
{
    $time=trim((string)get_setting('reagendar_live_time','19:00'));if(!preg_match('/^\d{2}:\d{2}$/',$time))$time='19:00';
    $days=max(1,min(60,(int)get_setting('reagendar_live_days_ahead','14')));$interval=max(1,min(14,(int)get_setting('reagendar_live_interval_days','1')));
    $now=new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo'));$out=[];
    for($i=1;$i<=$days&&count($out)<$qty;$i++){if((($i-1)%$interval)!==0)continue;$slot=$now->modify("+{$i} days")->format('Y-m-d').' '.$time.':00';$dt=new DateTimeImmutable($slot,new DateTimeZone('America/Sao_Paulo'));if($dt>$now)$out[]=['iso'=>$dt->format('Y-m-d H:i:s'),'label'=>$dt->format('d/m/Y H:i')];}
    return $out;
}

function support_agent_reschedule_live(PDO $pdo,int $userId,string $slot): bool
{
    $slots=support_agent_available_reschedule_slots($pdo,8);$allowed=array_column($slots,'iso');if(!in_array($slot,$allowed,true))return false;
    $st=$pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");$st->execute(['id'=>$userId]);$user=$st->fetch(PDO::FETCH_ASSOC);if(!$user)return false;
    $sets=[];$params=['id'=>$userId,'slot'=>$slot];if(support_chat_column_exists($pdo,'users','turma_live_at'))$sets[]='turma_live_at=:slot';if(support_chat_column_exists($pdo,'users','data_live'))$sets[]='data_live=:slot';if(!$sets)return false;
    $old=(string)($user['turma_live_at']??$user['data_live']??'');$turma=(string)($user['codigo_turma']??$user['turma_codigo']??'');
    $liveUrl=trim((string)get_setting('reagendar_live_url',''));$offsetMin=(int)get_setting('reagendar_dispatch_offset_min','0');$delayMs=max(0,min(30000,(int)get_setting('reagendar_dispatch_delay_ms','500')));$dt=new DateTimeImmutable($slot,new DateTimeZone('America/Sao_Paulo'));$dispatchAt=$dt->modify(($offsetMin>=0?'+':'').$offsetMin.' minutes')->format('Y-m-d H:i:s');$histId=0;
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=:id LIMIT 1')->execute($params);
    if(support_chat_table_exists($pdo,'reagendamentos_live')){
        $cols=['user_id','old_codigo_turma','new_codigo_turma','old_turma_live_at','new_turma_live_at','status','origem','created_at'];$vals=[':u',':oc',':nc',':ol',':nl',"'reagendado'",':origem','NOW()'];$p=['u'=>$userId,'oc'=>$turma?:null,'nc'=>$turma?:null,'ol'=>$old?:null,'nl'=>$slot,'origem'=>'agente_suporte'];
        if(support_chat_column_exists($pdo,'reagendamentos_live','live_url')){$cols[]='live_url';$vals[]=':url';$p['url']=$liveUrl?:null;}
        if(support_chat_column_exists($pdo,'reagendamentos_live','sf_disparo_at')){$cols[]='sf_disparo_at';$vals[]=':sf';$p['sf']=$dispatchAt;}
        if(support_chat_column_exists($pdo,'reagendamentos_live','sf_delay_ms')){$cols[]='sf_delay_ms';$vals[]=':delay';$p['delay']=$delayMs;}
        if(support_chat_column_exists($pdo,'reagendamentos_live','ip')){$cols[]='ip';$vals[]=':ip';$p['ip']=$_SERVER['REMOTE_ADDR']??null;}
        if(support_chat_column_exists($pdo,'reagendamentos_live','user_agent')){$cols[]='user_agent';$vals[]=':ua';$p['ua']='support_chat';}
        $pdo->prepare('INSERT INTO reagendamentos_live('.implode(',',$cols).') VALUES('.implode(',',$vals).')')->execute($p);$histId=(int)$pdo->lastInsertId();
        try{reagendamento_live_log($pdo,$histId,$userId,'agendamento_criado','pendente','Reagendamento criado pela central de suporte.',['new_turma_live_at'=>$slot,'sf_disparo_at'=>$dispatchAt,'origem'=>'support_chat']);}catch(Throwable $ignored){}
    }
    $pdo->commit();
    try{definir_tag_estado_reagendamento($userId,'ativo','support_chat',$histId?:null);}catch(Throwable $ignored){}
    support_chat_dispatch_live_rescheduled($pdo,$userId,$histId,$turma,$old,$slot,$liveUrl,'support_chat');
    support_chat_log_event($pdo,'ai_action',0,$userId,'bot','support_agent','Agente de suporte','reagendamento_live',['reagendamento_id'=>$histId,'new_turma_live_at'=>$slot,'old_turma_live_at'=>$old]);return true;
}

function support_chat_dispatch_live_rescheduled(PDO $pdo,int $userId,int $histId,string $codigoTurma,string $oldLive,string $newLive,string $liveUrl,string $origem): void
{
    try{
        $dt=new DateTimeImmutable($newLive,new DateTimeZone('America/Sao_Paulo'));
        disparar_webhooks('LIVE_REAGENDADA',$userId,[
            'reagendamento_id'=>$histId?:null,
            'codigo_turma'=>$codigoTurma,
            'data_live'=>$dt->format('d/m/Y H:i'),
            'data_live_iso'=>$dt->format('Y-m-d H:i:s'),
            'live_url'=>$liveUrl,
            'origem'=>$origem,
            'reagendamento'=>[
                'id'=>$histId?:null,
                'turma_original'=>$codigoTurma,
                'live_antiga'=>$oldLive,
                'live_nova'=>$dt->format('d/m/Y H:i'),
                'live_nova_iso'=>$dt->format('Y-m-d H:i:s'),
                'live_url'=>$liveUrl,
                'status'=>'reagendado',
            ],
        ]);
    }catch(Throwable $ignored){}
}

function support_chat_dispatch_certificate_event(PDO $pdo,int $userId,array $cert,string $origem): void
{
    try{adicionar_tag($userId,'CERT_EMITIDO',$origem);}catch(Throwable $ignored){}
    try{disparar_webhooks('CERT_EMITIDO',$userId,[
        'codigo_certificado'=>$cert['codigo_uid']??'',
        'curso'=>$cert['course']??'',
        'emitido_em'=>$cert['emitido_em']??'',
        'pdf_url'=>$cert['pdf_url']??'',
        'certificado_id'=>$cert['id']??null,
        'origem'=>$origem,
    ]);}catch(Throwable $ignored){}
}

function support_agent_certificate_expected_password(PDO $pdo,int $userId): string
{
    $cfg=[];try{$st=$pdo->query("SELECT * FROM certificate_config WHERE id=1 LIMIT 1");$cfg=$st?($st->fetch(PDO::FETCH_ASSOC)?:[]):[];}catch(Throwable $ignored){}
    $type=(string)($cfg['senha_tipo']??'unica');$mode=(string)($cfg['senha_mode']??'fixa');$fixed=trim((string)($cfg['senha_fixa']??''));$parts=json_decode((string)($cfg['senha_partes_fixas']??'[]'),true);if(!is_array($parts))$parts=[];
    $variable='';
    if($mode==='variavel'){
        try{$st=$pdo->prepare("SELECT turma_id FROM inscricoes WHERE user_id=:u ORDER BY id DESC LIMIT 1");$st->execute(['u'=>$userId]);$turmaId=(int)$st->fetchColumn();if($turmaId>0){$st=$pdo->prepare("SELECT senha_certificado FROM turmas WHERE id=:id LIMIT 1");$st->execute(['id'=>$turmaId]);$variable=trim((string)$st->fetchColumn());}}catch(Throwable $ignored){}
    }
    if($type==='modular'){if($mode==='variavel')$parts[]=$variable;return implode('',array_map('trim',$parts));}
    if($mode==='variavel')return $variable;
    return $fixed!==''?$fixed:(defined('SENHA_CERTIFICADO')?(string)SENHA_CERTIFICADO:'');
}

function support_agent_certificate_input_password(string $body): string
{
    $candidate=trim($body);$candidate=(string)preg_replace('/^\s*(minha\s+)?senha\s*(do\s+certificado)?\s*(e|é|eh|:|-)?\s*/iu','',$candidate);
    $candidate=trim($candidate," \t\n\r\0\x0B\"'`.,;:!?");
    return mb_strlen($candidate)<=120?$candidate:'';
}

function support_agent_recent_certificate_context(PDO $pdo,int $conversationId,int $messageId): bool
{
    $st=$pdo->prepare("SELECT body FROM support_messages WHERE conversation_id=:c AND sender_type='bot' AND sender_id='support_agent' AND id<:id ORDER BY id DESC LIMIT 3");$st->execute(['c'=>$conversationId,'id'=>$messageId]);
    foreach($st->fetchAll(PDO::FETCH_COLUMN)?:[] as $body){$b=mb_strtolower((string)$body);if(str_contains($b,'certificado')&&str_contains($b,'senha'))return true;}
    return false;
}

function support_agent_generate_certificate(PDO $pdo,int $userId): array
{
    require_once __DIR__.'/certificado_pdf.php';
    $st=$pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");$st->execute(['id'=>$userId]);$user=$st->fetch(PDO::FETCH_ASSOC);if(!$user)throw new RuntimeException('Aluno nao encontrado.');
    $app=[];$cfg=[];try{$q=$pdo->query("SELECT * FROM app_config WHERE id=1 LIMIT 1");$app=$q?($q->fetch(PDO::FETCH_ASSOC)?:[]):[];}catch(Throwable $ignored){}try{$q=$pdo->query("SELECT * FROM certificate_config WHERE id=1 LIMIT 1");$cfg=$q?($q->fetch(PDO::FETCH_ASSOC)?:[]):[];}catch(Throwable $ignored){}
    $course=trim((string)($app['course_title']??'Trilha de Aulas'))?:'Trilha de Aulas';
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT * FROM certificates WHERE user_id=:u AND course=:c ORDER BY id DESC LIMIT 1");$st->execute(['u'=>$userId,'c'=>$course]);$cert=$st->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$cert||($cert['status']??'')!=='emitido'){$code='';$chars='abcdefghijklmnopqrstuvwxyz0123456789';for($i=0;$i<36;$i++)$code.=($i>0&&$i%9===0)?'-':$chars[random_int(0,strlen($chars)-1)];$now=date('Y-m-d H:i:s');$pdo->prepare("INSERT INTO certificates(user_id,course,codigo_uid,emitido_em,status) VALUES(:u,:c,:code,:dt,'emitido')")->execute(['u'=>$userId,'c'=>$course,'code'=>$code,'dt'=>$now]);$cert=['id'=>(int)$pdo->lastInsertId(),'user_id'=>$userId,'course'=>$course,'codigo_uid'=>$code,'emitido_em'=>$now,'status'=>'emitido','pdf_url'=>null];}
        $pdf=trim((string)($cert['pdf_url']??''));if($pdf===''){$pdf=gerar_pdf_certificado($user,$cert,$cfg);$pdo->prepare("UPDATE certificates SET pdf_url=:p WHERE id=:id")->execute(['p'=>$pdf,'id'=>(int)$cert['id']]);$cert['pdf_url']=$pdf;}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    support_chat_log_event($pdo,'ai_action',0,$userId,'bot','support_agent','Agente de suporte','geracao_certificado',['certificate_id'=>(int)($cert['id']??0),'pdf_url'=>(string)($cert['pdf_url']??'')]);
    $cert['course']=$cert['course']??$course;support_chat_dispatch_certificate_event($pdo,$userId,$cert,'agente_suporte');
    $cert['link_verificacao']=!empty($cert['codigo_uid'])?rtrim((string)BASE_URL,'/').'/verificar_certificado.php?c='.rawurlencode((string)$cert['codigo_uid']):'';
    return $cert;
}

function support_agent_call_openai(array $cfg,array $input): array
{
    if($cfg['api_key']==='')throw new RuntimeException('Chave OpenAI nao configurada.');
    $schema=['type'=>'object','additionalProperties'=>false,'properties'=>[
        'action'=>['type'=>'string','enum'=>['answer','handoff','reschedule_options','confirm_reschedule']],
        'confidence'=>['type'=>'number'],'intent'=>['type'=>'string'],'answer'=>['type'=>'string'],'reason'=>['type'=>'string'],
        'selected_reschedule_iso'=>['type'=>['string','null']],'memory_summary'=>['type'=>'string'],'tokens_estimate'=>['type'=>'integer'],
    ],'required'=>['action','confidence','intent','answer','reason','selected_reschedule_iso','memory_summary','tokens_estimate']];
    $payload=['model'=>$cfg['model'],'input'=>$input,'max_output_tokens'=>min(2000,(int)$cfg['max_tokens']),'text'=>['format'=>['type'=>'json_schema','name'=>'support_agent_response','strict'=>true,'schema'=>$schema]]];
    if(strpos((string)$cfg['model'],'gpt-5')!==0)$payload['temperature']=(float)$cfg['temperature'];
    $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['api_key'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>60]);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$raw==='')throw new RuntimeException('Falha ao chamar OpenAI: '.$err);$decoded=json_decode($raw,true);if($code<200||$code>=300)throw new RuntimeException('OpenAI HTTP '.$code.': '.mb_substr((string)($decoded['error']['message']??$raw),0,800));
    $text=(string)($decoded['output_text']??'');if($text==='')foreach($decoded['output']??[] as $out)foreach($out['content']??[] as $c)if(isset($c['text']))$text.=(string)$c['text'];
    $result=json_decode($text,true);if(!is_array($result))throw new RuntimeException('A IA retornou formato invalido.');return $result;
}

function support_agent_transcribe_audio(PDO $pdo,array $cfg,array $message): string
{
    $url=(string)($message['attachment_url']??'');$path=realpath(__DIR__.'/../public/'.$url);if(!$path||!is_file($path))throw new RuntimeException('Audio nao encontrado para transcricao.');
    $ch=curl_init('https://api.openai.com/v1/audio/transcriptions');$file=new CURLFile($path,(string)($message['attachment_mime']??'audio/webm'),basename($path));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['api_key']],CURLOPT_POSTFIELDS=>['model'=>$cfg['transcription_model'],'file'=>$file],CURLOPT_TIMEOUT=>60]);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$raw==='')throw new RuntimeException('Falha na transcricao: '.$err);$decoded=json_decode($raw,true);if($code<200||$code>=300)throw new RuntimeException('Transcricao HTTP '.$code.': '.mb_substr((string)($decoded['error']['message']??$raw),0,800));
    return trim((string)($decoded['text']??''));
}

function support_agent_handoff(PDO $pdo,int $conversationId,string $reason,array $cfg): void
{
    $conv=support_chat_detail($pdo,$conversationId);if($conv&&($conv['stage']??'')==='human')return;
    support_chat_log_event($pdo,'human_handoff',$conversationId,0,'bot','support_agent','Agente de suporte','handoff',['reason'=>$reason]);
    $pdo->prepare("UPDATE support_conversations SET status='pending',stage='human',priority=IF(priority='normal','high',priority),notes=CONCAT(COALESCE(notes,''),IF(COALESCE(notes,'')='','',\"\n\"),:n) WHERE id=:id")->execute(['n'=>'Agente transferiu para humano: '.$reason,'id'=>$conversationId]);
    $msg=trim((string)$cfg['handoff_message']);if($msg!=='')support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',$msg);
}

function support_agent_is_human_request(string $body): bool
{
    $plain=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$body);$b=strtolower($plain!==false?$plain:$body);return str_contains($b,'atendimento humano')||str_contains($b,'falar com humano')||str_contains($b,'pessoa da equipe')||str_contains($b,'atendente humano')||str_contains($b,'quero atendente')||str_contains($b,'chamar atendente');
}

function support_agent_is_certificate_request(string $body): bool
{
    $plain=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$body);$b=strtolower($plain!==false?$plain:$body);
    return str_contains($b,'certificado')||str_contains($b,'certificacao')||str_contains($b,'diploma')||str_contains($b,'certidao');
}

function support_agent_is_group_request(string $body): bool
{
    $plain=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$body);$b=strtolower($plain!==false?$plain:$body);
    return str_contains($b,'grupo')||str_contains($b,'whatsapp')||str_contains($b,'wpp');
}

function support_agent_is_greeting_only(string $body): bool
{
    $plain=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$body);$b=strtolower($plain!==false?$plain:$body);
    $b=trim((string)preg_replace('/[^\p{L}\p{N}\s]+/u',' ',$b));$b=(string)preg_replace('/\s+/u',' ',$b);
    return (bool)preg_match('/^(oi|ola|olá|bom dia|boa tarde|boa noite|e ai|e ai tudo bem|tudo bem|opa)$/iu',$b);
}

function support_agent_claim_message(PDO $pdo,int $conversationId,int $messageId): bool
{
    try{
        $st=$pdo->prepare("INSERT IGNORE INTO support_agent_processed_messages(message_id,conversation_id,status) VALUES(:m,:c,'processing')");
        $st->execute(['m'=>$messageId,'c'=>$conversationId]);
        return $st->rowCount()>0;
    }catch(Throwable $e){return true;}
}

function support_agent_finish_message(PDO $pdo,int $messageId,string $status='done'): void
{
    try{$pdo->prepare("UPDATE support_agent_processed_messages SET status=:s WHERE message_id=:m")->execute(['s'=>$status,'m'=>$messageId]);}catch(Throwable $ignored){}
}

function support_agent_has_live_completion(array $payload): bool
{
    $events=$payload['live']['eventos']??[];if(!is_array($events)||!$events)return false;
    foreach($events as $event){$type=strtolower((string)($event['tipo']??''));if($type!==''&&(str_contains($type,'oferta')||str_contains($type,'ficou')||str_contains($type,'assistiu')||str_contains($type,'acessou')||str_contains($type,'live')))return true;}
    return false;
}

function support_agent_lesson_completed_by_order(array $payload,int $order): bool
{
    $lessons=$payload['curso']['aulas']??[];if(!is_array($lessons))return false;
    foreach($lessons as $lesson){
        $title=strtolower((string)($lesson['titulo']??''));
        $matchesOrder=(int)($lesson['ordem']??0)===$order;
        $matchesTitle=(bool)preg_match('/\baula\s*0?'.preg_quote((string)$order,'/').'\b/',$title);
        if(($matchesOrder||$matchesTitle)&&($lesson['status']??'')==='completed')return true;
    }
    return false;
}

function support_agent_certificate_answer(array $payload): string
{
    $cert=$payload['certificado']??[];$course=$payload['curso']??[];$student=$payload['aluno']??[];
    $name=trim((string)($student['nome']??''));$first=$name!==''?explode(' ',$name)[0]:'';
    $prefix=$first!==''?$first.', ':'';
    if(!empty($cert['tem_certificado_emitido'])){
        $url=trim((string)($cert['pdf_url']??''));if($url==='')$url=trim((string)($cert['link_verificacao']??''));
        if($url!=='')return $prefix."seu certificado ja foi emitido. Vou deixar o acesso direto abaixo:\n".$url;
        $emit=trim((string)($cert['link_emitir']??''));return $prefix."nao encontrei um PDF ou link pronto do seu certificado salvo no sistema. Isso normalmente significa que voce ainda precisa emitir pela tela de certificado. Acesse abaixo e confirme seus dados usando a senha da aula 5 e a senha da aula ao vivo quando forem solicitadas.\n".$emit;
    }
    $required=(int)($course['aulas_obrigatorias']??0);$done=(int)($course['aulas_concluidas']??0);$missing=$course['aulas_faltantes_obrigatorias']??($cert['criterios_e_pendencias']['aulas_faltantes']??[]);
    $lessonsOk=$required>0&&$done>=$required;$liveOk=support_agent_has_live_completion($payload);$lesson5Ok=support_agent_lesson_completed_by_order($payload,5);
    if($lessonsOk&&$liveOk&&$lesson5Ok){
        $url=trim((string)($cert['link_emitir']??''));if($url!=='')return $prefix."analisei seu progresso: as aulas obrigatorias estao concluidas, a aula 5 consta como concluida e encontrei registro de live/aula ao vivo. Agora voce precisa emitir o certificado pela tela abaixo, usando a senha da aula 5 e a senha da aula ao vivo quando forem solicitadas.\n".$url;
    }
    $parts=[];if($required<=0)$parts[]='nao consegui confirmar a lista de aulas obrigatorias no seu cadastro';elseif(!$lessonsOk){$parts[]="voce concluiu {$done} de {$required} aulas obrigatorias";if(is_array($missing)&&$missing){$names=[];foreach(array_slice($missing,0,6) as $m)$names[]=trim((string)($m['titulo']??''));$names=array_values(array_filter($names));if($names)$parts[]='aulas pendentes: '.implode(', ',$names);}}
    if(!$lesson5Ok)$parts[]='a aula 5 ainda nao consta como concluida; ela e importante porque nela voce recebe uma das senhas';
    if(!$liveOk)$parts[]='nao encontrei no sistema a confirmacao da sua participacao na aula ao vivo/live; a outra senha vem da live';
    if(!$parts)$parts[]='ainda nao encontrei no sistema todos os criterios marcados como concluidos';
    return $prefix."seu certificado ainda nao aparece como emitido. Pelo status atual, para conseguir emitir falta: ".implode('; ',$parts).". Quando concluir esses pontos, acesse a tela de certificado e use a senha da aula 5 junto com a senha da aula ao vivo.";
}

function support_agent_certificate_flow(PDO $pdo,int $conversationId,int $messageId,array $conv,array $payload,string $body,bool $firstAgentReply): bool
{
    $cert=$payload['certificado']??[];$already=!empty($cert['tem_certificado_emitido']);
    if($already){$url=trim((string)($cert['pdf_url']??''));if($url==='')$url=trim((string)($cert['link_verificacao']??''));if($url!==''){$issued=[];foreach(($cert['registros']??[]) as $r){if(($r['status']??'')==='emitido'){$issued=$r;break;}}support_chat_dispatch_certificate_event($pdo,(int)$conv['user_id'],['codigo_uid'=>$issued['codigo_uid']??'','course'=>$issued['course']??'','emitido_em'=>$issued['emitido_em']??'','pdf_url'=>$url,'id'=>$issued['id']??null],'agente_suporte_envio');}support_chat_log_event($pdo,'ai_action',$conversationId,(int)$conv['user_id'],'bot','support_agent','Agente de suporte','envio_certificado',['url_found'=>$url!=='' ? 1 : 0]);support_agent_send_answer($pdo,$conversationId,$url!==''?"Seu certificado ja esta emitido. Vou deixar o link abaixo:\n".$url:'Seu certificado ja consta como emitido, mas nao encontrei o link salvo. Vou encaminhar para a equipe conferir.',$firstAgentReply);return true;}
    $missing=support_agent_certificate_answer($payload);
    $eligible=(int)($payload['curso']['aulas_obrigatorias']??0)>0&&(int)($payload['curso']['aulas_concluidas']??0)>=(int)($payload['curso']['aulas_obrigatorias']??0)&&support_agent_has_live_completion($payload)&&support_agent_lesson_completed_by_order($payload,5);
    if(!$eligible){support_agent_send_answer($pdo,$conversationId,$missing,$firstAgentReply);return true;}
    $password=support_agent_certificate_input_password($body);$expected=support_agent_certificate_expected_password($pdo,(int)$conv['user_id']);
    if($expected===''||$password===''||support_agent_is_certificate_request($password)){support_agent_send_answer($pdo,$conversationId,'Voce ja cumpre os criterios para emitir o certificado. Me envie a senha do certificado para eu validar e gerar o link.',$firstAgentReply);return true;}
    if(!hash_equals($expected,$password)){try{disparar_webhooks('CERT_SENHA_ERRADA',(int)$conv['user_id'],['motivo'=>'senha_incorreta','origem'=>'agente_suporte']);}catch(Throwable $ignored){}support_agent_send_answer($pdo,$conversationId,'Essa senha nao conferiu. Verifique a senha da aula 5 e da aula ao vivo e me envie novamente.',$firstAgentReply);return true;}
    $issued=support_agent_generate_certificate($pdo,(int)$conv['user_id']);$url=trim((string)($issued['pdf_url']??''));if($url==='')$url=trim((string)($issued['link_verificacao']??''));
    support_chat_log_event($pdo,'ai_action',$conversationId,(int)$conv['user_id'],'bot','support_agent','Agente de suporte','envio_certificado',['url_found'=>$url!=='' ? 1 : 0]);
    support_agent_send_answer($pdo,$conversationId,"Pronto, gerei seu certificado e disparei o gatilho de certificado emitido. Vou deixar o link abaixo:\n".$url,$firstAgentReply);return true;
}

function support_agent_group_answer(array $payload): string
{
    $link=trim((string)($payload['links']['link_grupo_configurado']??''));if($link===''){$groups=$payload['links']['grupos_whatsapp']??[];if(is_array($groups)&&isset($groups[0]['invite_url']))$link=trim((string)$groups[0]['invite_url']);}
    if($link==='')return 'Nao encontrei um link de grupo configurado para a sua turma. Vou encaminhar para a equipe conferir.';
    return "Aqui esta o link do grupo da sua turma:\n".$link;
}

function support_agent_handle_student_message(PDO $pdo,int $conversationId,int $messageId): void
{
    $cfg=support_agent_config($pdo);if(!$cfg['enabled'])return;
    if(!support_agent_claim_message($pdo,$conversationId,$messageId))return;
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv||($conv['stage']??'')==='human'||($conv['status']??'')==='closed'){support_agent_finish_message($pdo,$messageId,'skipped');return;}
    $st=$pdo->prepare("SELECT * FROM support_messages WHERE id=:id AND conversation_id=:c LIMIT 1");$st->execute(['id'=>$messageId,'c'=>$conversationId]);$msg=$st->fetch(PDO::FETCH_ASSOC);if(!$msg||($msg['sender_type']??'')!=='student'){support_agent_finish_message($pdo,$messageId,'skipped');return;}
    try{
        $pause=(int)($cfg['pause_seconds']??0);if($pause>0){sleep($pause);$newer=$pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE conversation_id=:c AND sender_type='student' AND id>:id");$newer->execute(['c'=>$conversationId,'id'=>$messageId]);if((int)$newer->fetchColumn()>0){support_agent_finish_message($pdo,$messageId,'skipped');return;}}
        $body=trim((string)($msg['body']??''));$type=(string)($msg['message_type']??'text');
        if(support_agent_is_human_request($body)){support_agent_handoff($pdo,$conversationId,'Aluno pediu atendimento humano.',$cfg);support_agent_finish_message($pdo,$messageId);return;}
        if(in_array($type,['image','video','file'],true)){support_agent_handoff($pdo,$conversationId,'Aluno enviou imagem/video/arquivo.', $cfg);support_agent_finish_message($pdo,$messageId);return;}
        if($type==='audio'){$body=support_agent_transcribe_audio($pdo,$cfg,$msg);if($body===''){support_agent_handoff($pdo,$conversationId,'Audio sem transcricao confiavel.',$cfg);support_agent_finish_message($pdo,$messageId);return;}$pdo->prepare("UPDATE support_messages SET body=:b,metadata_json=:m WHERE id=:id")->execute(['b'=>'Transcricao do audio: '.$body,'m'=>json_encode(['transcription'=>$body],JSON_UNESCAPED_UNICODE),'id'=>$messageId]);}
        if(support_agent_is_closing_message($body)){
            support_chat_close_with_feedback($pdo,$conversationId,'bot','Agente de suporte',true);
            support_agent_finish_message($pdo,$messageId);return;
        }
        $agentCount=$pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE conversation_id=:c AND sender_type='bot' AND sender_id='support_agent' AND id<:id");$agentCount->execute(['c'=>$conversationId,'id'=>$messageId]);$firstAgentReply=((int)$agentCount->fetchColumn())===0;
        $memSt=$pdo->prepare("SELECT * FROM support_agent_memory WHERE conversation_id=:c");$memSt->execute(['c'=>$conversationId]);$memory=$memSt->fetch(PDO::FETCH_ASSOC)?:['summary'=>'','token_count'=>0];
        $payload=support_agent_user_payload($pdo,(int)$conv['user_id']);
        if($type==='text'&&support_agent_is_greeting_only($body)){
            $name=trim((string)($payload['aluno']['nome']??$conv['user_name']??''));$first=$name!==''?explode(' ',$name)[0]:'';
            support_agent_send_answer($pdo,$conversationId,($first!==''?$first.', ':'').'oi. Como posso ajudar?',$firstAgentReply);
            $pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);
            support_agent_finish_message($pdo,$messageId);return;
        }
        $certificateContext=support_agent_is_certificate_request($body)||support_agent_recent_certificate_context($pdo,$conversationId,$messageId);
        if($cfg['certificate']&&$certificateContext){
            support_agent_certificate_flow($pdo,$conversationId,$messageId,$conv,$payload,$body,$firstAgentReply);
            $pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);support_agent_finish_message($pdo,$messageId);return;
        }
        if(!$cfg['certificate']&&support_agent_is_certificate_request($body)){
            $answer=support_agent_certificate_answer($payload);
            support_agent_send_answer($pdo,$conversationId,$answer,$firstAgentReply);$pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);support_agent_finish_message($pdo,$messageId);return;
        }
        if($cfg['group']&&support_agent_is_group_request($body)){
            support_chat_log_event($pdo,'ai_action',$conversationId,(int)$conv['user_id'],'bot','support_agent','Agente de suporte','envio_link_grupo');
            support_agent_send_answer($pdo,$conversationId,support_agent_group_answer($payload),$firstAgentReply);
            $pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);support_agent_finish_message($pdo,$messageId);return;
        }
        $recent=array_values(array_filter(support_chat_messages($pdo,$conversationId,max(0,$messageId-25)),static fn($m)=>(int)($m['id']??0)<$messageId));$estimated=(int)((strlen(json_encode($payload,JSON_UNESCAPED_UNICODE))+strlen((string)$memory['summary'])+strlen($body))/4)+(int)($memory['token_count']??0);
        if($estimated>$cfg['max_tokens']){support_agent_handoff($pdo,$conversationId,'Limite de tokens/contexto atingido.',$cfg);support_agent_finish_message($pdo,$messageId);return;}
        $system="Voce e um agente de atendimento, vendas e suporte tecnico da area de membros. Use estritamente o payload do aluno atual. Nunca revele dados de outro aluno. A mensagem do aluno que voce deve responder agora esta em mensagem_atual; historico_recente e memoria servem apenas como contexto, nao repita nem trate como nova pergunta. Se mensagem_atual for apenas saudacao curta, responda somente a saudacao e pergunte como pode ajudar; nao use intencoes antigas do historico. Cumprimente somente se primeira_resposta_do_agente=true; se for false, responda direto sem 'ola', 'oi', 'bom dia', 'boa tarde' ou 'boa noite'. Responda apenas o que tiver certeza pelo contexto. Se o pedido nao estiver nos prompts nem no payload/banco, action=handoff. Certificado: se a pergunta for sobre certificado, respeite a funcao certificado ativa; quando desativada, apenas oriente pelo link de emissao. Para live, use datas do payload. Para grupos e acesso, use payload_aluno.links, link_grupo_configurado e payload_aluno.variaveis_configuradas. Se a resposta ficar grande, escreva naturalmente em blocos curtos; o sistema pode dividir em mensagens com pausa. Para reagendamento, so confirme datas listadas em reagendamento.opcoes. Funcoes ativas: suporte_basico=".($cfg['basic']?'sim':'nao').", vendas=".($cfg['sales']?'sim':'nao').", suporte_tecnico=".($cfg['technical']?'sim':'nao').", reagendamento=".($cfg['reschedule']?'sim':'nao').", certificado=".($cfg['certificate']?'sim':'nao').", grupo=".($cfg['group']?'sim':'nao').". Prompts: suporte={$cfg['prompt_basic']} vendas={$cfg['prompt_sales']} tecnico={$cfg['prompt_technical']} reagendamento={$cfg['prompt_reschedule']} certificado={$cfg['prompt_certificate']} grupo={$cfg['prompt_group']}";
        $input=[['role'=>'system','content'=>$system],['role'=>'user','content'=>json_encode(['mensagem_atual'=>$body,'primeira_resposta_do_agente'=>$firstAgentReply,'memoria'=>$memory['summary']??'','payload_aluno'=>$payload,'historico_recente'=>$recent],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
        $res=support_agent_call_openai($cfg,$input);$action=(string)($res['action']??'handoff');$confidence=(float)($res['confidence']??0);
        if($action==='confirm_reschedule'&&$cfg['reschedule']){$slot=(string)($res['selected_reschedule_iso']??'');if($slot!==''&&support_agent_reschedule_live($pdo,(int)$conv['user_id'],$slot))$res['answer']=trim((string)$res['answer'])?:'Pronto, sua aula ao vivo foi reagendada.';else $action='handoff';}
        support_chat_log_event($pdo,'ai_action',$conversationId,(int)$conv['user_id'],'bot','support_agent','Agente de suporte',(string)($res['intent']??$action),['action'=>$action,'confidence'=>$confidence]);
        if($action==='handoff'||$confidence<0.55){support_agent_handoff($pdo,$conversationId,(string)($res['reason']??'Baixa confianca.'),$cfg);support_agent_finish_message($pdo,$messageId);}
        else{$answer=support_agent_normalize_answer_for_payload(trim((string)$res['answer'])?:'Consegui analisar seu caso, mas vou precisar de mais detalhes.',$payload);support_agent_send_answer($pdo,$conversationId,$answer,$firstAgentReply);$pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);}
        $pdo->prepare("INSERT INTO support_agent_memory(conversation_id,summary,token_count,last_intent,last_confidence) VALUES(:c,:s,:t,:i,:cf) ON DUPLICATE KEY UPDATE summary=VALUES(summary),token_count=VALUES(token_count),last_intent=VALUES(last_intent),last_confidence=VALUES(last_confidence)")->execute(['c'=>$conversationId,'s'=>mb_substr((string)($res['memory_summary']??''),0,12000),'t'=>min(999999,(int)($res['tokens_estimate']??$estimated)),'i'=>mb_substr((string)($res['intent']??''),0,80),'cf'=>$confidence]);
        support_agent_finish_message($pdo,$messageId);
    }catch(Throwable $e){support_agent_finish_message($pdo,$messageId,'error');support_agent_handoff($pdo,$conversationId,'Erro do agente: '.$e->getMessage(),$cfg);}
}

function support_chat_blank_graph(): array
{
    return ['schemaVersion'=>1,'nodes'=>[
        ['id'=>'trigger_start','type'=>'trigger','x'=>80,'y'=>150,'config'=>['label'=>'Aluno chamou no suporte']],
        ['id'=>'typing_start','type'=>'typing','x'=>350,'y'=>150,'config'=>['label'=>'Digitando','seconds'=>2]],
        ['id'=>'message_start','type'=>'message','x'=>620,'y'=>150,'config'=>['label'=>'Boas-vindas','text'=>'Olá {primeiro_nome}! Já recebemos sua mensagem. Como podemos ajudar?','buttons'=>[]]],
    ],'edges'=>[['id'=>'e1','source'=>'trigger_start','target'=>'typing_start','sourceHandle'=>'default'],['id'=>'e2','source'=>'typing_start','target'=>'message_start','sourceHandle'=>'default']],'viewport'=>['x'=>50,'y'=>40,'zoom'=>1]];
}

function support_chat_run_automation(PDO $pdo,int $conversationId): void
{
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv)return;
    $flows=$pdo->query("SELECT * FROM support_automation_flows WHERE status='active' ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($flows as $flow){
        $graph=json_decode((string)$flow['graph_json'],true);if(!is_array($graph))continue;$nodes=[];foreach($graph['nodes']??[] as $n)$nodes[$n['id']]=$n;
        $next=[];foreach($graph['edges']??[] as $e)$next[$e['source']][$e['sourceHandle']??'default']=$e['target'];
        $current=null;foreach($nodes as $n)if(($n['type']??'')==='trigger'){$current=$n['id'];break;}if(!$current)continue;
        $log=[];$steps=0;$pdo->prepare("INSERT INTO support_automation_runs(flow_id,conversation_id) VALUES(:f,:c)")->execute(['f'=>$flow['id'],'c'=>$conversationId]);$runId=(int)$pdo->lastInsertId();
        while($current&&isset($nodes[$current])&&$steps++<30){$n=$nodes[$current];$cfg=$n['config']??[];$handle='default';$log[]=['node'=>$current,'type'=>$n['type'],'at'=>date('c')];
            if($n['type']==='typing'){support_chat_typing($pdo,$conversationId,'admin','Assistente virtual');$seconds=max(0,min(5,(int)($cfg['seconds']??1)));if($seconds)usleep($seconds*1000000);}
            elseif($n['type']==='message'||$n['type']==='ai'){$text=(string)($cfg['text']??($n['type']==='ai'?'Olá! Sou o assistente virtual. Conte em poucas palavras como podemos ajudar.':''));$text=str_replace('{primeiro_nome}',explode(' ',trim((string)$conv['user_name']))[0]??'',$text);support_chat_send($pdo,$conversationId,'bot','automation','Assistente virtual',$text);}
            elseif($n['type']==='support_chat'){$text=trim((string)($cfg['text']??''));if($text==='')$text='Recebemos sua mensagem. Em instantes vamos continuar seu atendimento por aqui.';$text=str_replace('{primeiro_nome}',explode(' ',trim((string)$conv['user_name']))[0]??'',$text);$messageId=support_chat_send($pdo,$conversationId,'bot','automation','Central de suporte',$text);try{support_chat_push_student($pdo,$conversationId,$messageId,$text);}catch(Throwable $ignored){}if(($cfg['handoff']??'ia')==='human'){$pdo->prepare("UPDATE support_conversations SET status='pending',stage='human',priority=IF(priority='normal','high',priority),notes=CONCAT(COALESCE(notes,''),IF(COALESCE(notes,'')='','',\"\n\"),:n) WHERE id=:id")->execute(['n'=>'Automacao transferiu para humano pelo bloco Chat central.','id'=>$conversationId]);support_chat_log_event($pdo,'human_handoff',$conversationId,0,'automation','support_flow','Automacao','handoff',['source'=>'support_chat_block']);}else{$pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);}}
            elseif($n['type']==='condition'){$field=(string)($cfg['field']??'stage');$expected=mb_strtolower(trim((string)($cfg['value']??'')));$actual=mb_strtolower((string)($conv[$field]??''));$handle=str_contains($actual,$expected)?'yes':'no';}
            $current=$next[$current][$handle]??null;
        }
        $pdo->prepare("UPDATE support_automation_runs SET status='completed',log_json=:l,finished_at=NOW() WHERE id=:id")->execute(['l'=>json_encode($log,JSON_UNESCAPED_UNICODE),'id'=>$runId]);
    }
}
