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
    foreach (['support_chat_student_enabled'=>'0','support_chat_test_mode'=>'1','support_chat_welcome'=>'Olá! Como podemos ajudar?','support_chat_offline_message'=>'Recebemos sua mensagem e responderemos assim que possível.'] as $key=>$value) {
        $st=$pdo->prepare("INSERT IGNORE INTO settings (chave,valor) VALUES (:k,:v)");
        try {$st->execute(['k'=>$key,'v'=>$value]);} catch (Throwable $ignored) {}
    }
    foreach ([
        'support_agent_enabled'=>'0',
        'support_agent_basic_enabled'=>'1',
        'support_agent_sales_enabled'=>'0',
        'support_agent_technical_enabled'=>'1',
        'support_agent_reschedule_enabled'=>'1',
        'support_agent_max_tokens'=>'3000',
        'support_agent_pause_seconds'=>'5',
        'support_agent_prompt_basic'=>'Responda duvidas basicas de acesso, certificado, aula ao vivo, andamento do curso e suporte da area de membros.',
        'support_agent_prompt_sales'=>'Quando vendas estiver ativo, responda duvidas comerciais somente com base nos dados do contexto. Nao invente preco, bonus ou prazo.',
        'support_agent_prompt_technical'=>'Ajude em problemas tecnicos comuns: login, acesso ao curso, certificado, audio/video, app e notificacoes.',
        'support_agent_handoff_message'=>'Vou encaminhar seu atendimento para uma pessoa da equipe analisar com seguranca.',
        'support_crm_stages_json'=>'[{"id":"agent","label":"Com agente","condition":"status=open"},{"id":"human","label":"Humano pendente","condition":"stage=human"},{"id":"done","label":"Concluido","condition":"status=closed"}]',
    ] as $key=>$value) {
        $st=$pdo->prepare("INSERT IGNORE INTO settings (chave,valor) VALUES (:k,:v)");
        try {$st->execute(['k'=>$key,'v'=>$value]);} catch (Throwable $ignored) {}
    }
}

function support_chat_table_exists(PDO $pdo,string $table): bool {try{$st=$pdo->prepare("SHOW TABLES LIKE :t");$st->execute(['t'=>$table]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}
function support_chat_column_exists(PDO $pdo,string $table,string $column): bool {try{$st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");$st->execute(['c'=>$column]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}

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
    $st=$pdo->prepare("SELECT id FROM support_conversations WHERE user_id=:u AND status<>'closed' ORDER BY id DESC LIMIT 1");
    $st->execute(['u'=>$userId]);$id=(int)$st->fetchColumn();if($id>0)return $id;
    $pdo->prepare("INSERT INTO support_conversations(user_id,channel,subject) VALUES(:u,:c,'Atendimento pelo aplicativo')")->execute(['u'=>$userId,'c'=>$channel]);
    return (int)$pdo->lastInsertId();
}

function support_chat_conversations(PDO $pdo,string $filter='open'): array
{
    $where=$filter==='all'?'1=1':($filter==='unassigned'?"c.status<>'closed' AND c.assigned_to IS NULL":($filter==='closed'?"c.status='closed'":"c.status<>'closed'"));
    return $pdo->query("SELECT c.*,u.nome user_name,u.email user_email,u.telefone user_phone,
        (SELECT body FROM support_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_body,
        (SELECT message_type FROM support_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_type
        FROM support_conversations c JOIN users u ON u.id=c.user_id WHERE {$where} ORDER BY c.last_message_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function support_chat_detail(PDO $pdo,int $conversationId): ?array
{
    $st=$pdo->prepare("SELECT c.*,u.nome user_name,u.email user_email,u.telefone user_phone FROM support_conversations c JOIN users u ON u.id=c.user_id WHERE c.id=:id LIMIT 1");
    $st->execute(['id'=>$conversationId]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function support_chat_messages(PDO $pdo,int $conversationId,int $after=0): array
{
    $st=$pdo->prepare("SELECT * FROM support_messages WHERE conversation_id=:c AND id>:a ORDER BY id ASC LIMIT 300");
    $st->execute(['c'=>$conversationId,'a'=>$after]);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
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

function support_chat_send(PDO $pdo,int $conversationId,string $senderType,string $senderId,string $senderName,string $body,array $attachment=[]): int
{
    $body=trim($body);if($body===''&&!$attachment)throw new InvalidArgumentException('Digite uma mensagem ou anexe um arquivo.');
    if(mb_strlen($body)>10000)throw new InvalidArgumentException('Mensagem muito longa.');
    $type=(string)($attachment['type']??'text');
    $st=$pdo->prepare("INSERT INTO support_messages(conversation_id,sender_type,sender_id,sender_name,message_type,body,attachment_url,attachment_name,attachment_mime,attachment_size,metadata_json) VALUES(:c,:st,:si,:sn,:mt,:b,:url,:an,:am,:az,:meta)");
    $st->execute(['c'=>$conversationId,'st'=>$senderType,'si'=>$senderId,'sn'=>$senderName,'mt'=>$type,'b'=>$body!==''?$body:null,'url'=>$attachment['url']??null,'an'=>$attachment['name']??null,'am'=>$attachment['mime']??null,'az'=>$attachment['size']??null,'meta'=>null]);
    $id=(int)$pdo->lastInsertId();$student=$senderType==='student';
    $pdo->prepare("UPDATE support_conversations SET last_message_at=NOW(),status=IF(status='closed','open',status),unread_admin=unread_admin+:ua,unread_student=unread_student+:us WHERE id=:id")
        ->execute(['ua'=>$student?1:0,'us'=>$student?0:1,'id'=>$conversationId]);
    if(!$student && $senderType!=='bot' && get_setting('support_chat_student_enabled','0')==='1') {
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
        'max_tokens'=>max(500,min(12000,(int)get_setting('support_agent_max_tokens','3000'))),
        'pause_seconds'=>max(0,min(30,(int)get_setting('support_agent_pause_seconds','5'))),
        'model'=>trim((string)get_setting('whatsapp_ai_model','gpt-4.1-mini'))?:'gpt-4.1-mini',
        'api_key'=>trim((string)get_setting('whatsapp_ai_openai_api_key','')),
        'temperature'=>max(0,min(1,(float)get_setting('whatsapp_ai_temperature','0.2'))),
        'prompt_basic'=>(string)get_setting('support_agent_prompt_basic',''),
        'prompt_sales'=>(string)get_setting('support_agent_prompt_sales',''),
        'prompt_technical'=>(string)get_setting('support_agent_prompt_technical',''),
        'handoff_message'=>(string)get_setting('support_agent_handoff_message','Vou encaminhar seu atendimento para uma pessoa da equipe analisar com seguranca.'),
        'transcription_model'=>trim((string)get_setting('whatsapp_ai_transcription_model','gpt-4o-mini-transcribe'))?:'gpt-4o-mini-transcribe',
    ];
}

function support_agent_user_payload(PDO $pdo,int $userId): array
{
    $st=$pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");$st->execute(['id'=>$userId]);$user=$st->fetch(PDO::FETCH_ASSOC)?:[];
    if(!$user)throw new RuntimeException('Aluno nao encontrado para o agente.');
    $tags=[];if(support_chat_table_exists($pdo,'user_tags')&&support_chat_table_exists($pdo,'tags')){try{$tagDesc=support_chat_column_exists($pdo,'tags','descricao')?'t.descricao':"'' AS descricao";$q=$pdo->prepare("SELECT t.nome,{$tagDesc},ut.created_at FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=:u ORDER BY ut.created_at DESC");$q->execute(['u'=>$userId]);$tags=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $lessons=[];$required=0;$done=0;if(support_chat_table_exists($pdo,'lessons')){try{$required=(int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo=1 AND conta_para_conclusao=1")->fetchColumn();if($required<=0)$required=(int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo=1")->fetchColumn();if(support_chat_table_exists($pdo,'lesson_progress')){$q=$pdo->prepare("SELECT l.id,l.titulo,l.ordem,lp.status,lp.updated_at FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE lp.user_id=:u ORDER BY l.ordem,l.id");$q->execute(['u'=>$userId]);$lessons=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$done=count(array_filter($lessons,static fn($r)=>($r['status']??'')==='completed'));}}catch(Throwable $ignored){}}
    $views=[];if(support_chat_table_exists($pdo,'lesson_view_events')){try{$q=$pdo->prepare("SELECT l.titulo,l.ordem,MIN(v.viewed_at) first_viewed_at,MAX(v.viewed_at) last_viewed_at,COUNT(*) views FROM lesson_view_events v LEFT JOIN lessons l ON l.id=v.lesson_id WHERE v.user_id=:u GROUP BY v.lesson_id,l.titulo,l.ordem ORDER BY l.ordem,l.titulo LIMIT 80");$q->execute(['u'=>$userId]);$views=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $certificates=[];if(support_chat_table_exists($pdo,'certificates')){try{$q=$pdo->prepare("SELECT id,status,created_at,updated_at FROM certificates WHERE user_id=:u ORDER BY id DESC LIMIT 10");$q->execute(['u'=>$userId]);$certificates=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $live=[];if(support_chat_table_exists($pdo,'live_event_recebimentos')&&support_chat_table_exists($pdo,'live_events')){try{$q=$pdo->prepare("SELECT le.tipo,MIN(ler.created_at) first_at,MAX(ler.created_at) last_at,COUNT(*) total FROM live_event_recebimentos ler JOIN live_events le ON le.id=ler.event_id WHERE ler.user_id=:u AND ler.status='processado' GROUP BY le.tipo ORDER BY last_at DESC");$q->execute(['u'=>$userId]);$live=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $reschedules=[];if(support_chat_table_exists($pdo,'reagendamentos_live')){try{$q=$pdo->prepare("SELECT status,old_turma_live_at,new_turma_live_at,created_at FROM reagendamentos_live WHERE user_id=:u ORDER BY id DESC LIMIT 10");$q->execute(['u'=>$userId]);$reschedules=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}}
    $coursePct=$required>0?(int)floor(($done/max(1,$required))*100):0;
    return [
        'aluno'=>[
            'id'=>(int)$user['id'],'nome'=>(string)($user['nome']??''),'email'=>(string)($user['email']??''),'telefone'=>(string)($user['telefone']??''),
            'data_inscricao'=>(string)($user['created_at']??''),'codigo_turma'=>(string)($user['codigo_turma']??$user['turma_codigo']??''),
            'data_live'=>(string)($user['turma_live_at']??$user['data_live']??''),'acesso_vitalicio'=>(int)($user['acesso_vitalicio']??0)===1,
        ],
        'tags'=>$tags,
        'curso'=>['aulas_obrigatorias'=>$required,'aulas_concluidas'=>$done,'percentual_avanco'=>$coursePct,'aulas'=>$lessons,'visualizacoes'=>$views],
        'certificado'=>['tem_certificado'=>!empty($certificates),'link_emitir'=>rtrim((string)BASE_URL,'/').'/certificado.php','registros'=>$certificates],
        'live'=>['eventos'=>$live,'reagendamentos'=>$reschedules],
        'reagendamento'=>['opcoes'=>support_agent_available_reschedule_slots($pdo,2)],
    ];
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
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=:id LIMIT 1')->execute($params);
    if(support_chat_table_exists($pdo,'reagendamentos_live'))$pdo->prepare("INSERT INTO reagendamentos_live(user_id,old_codigo_turma,new_codigo_turma,old_turma_live_at,new_turma_live_at,status,origem,created_at) VALUES(:u,:oc,:nc,:ol,:nl,'reagendado','agente_suporte',NOW())")->execute(['u'=>$userId,'oc'=>$turma?:null,'nc'=>$turma?:null,'ol'=>$old?:null,'nl'=>$slot]);
    $pdo->commit();return true;
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
    $pdo->prepare("UPDATE support_conversations SET status='pending',stage='human',priority=IF(priority='normal','high',priority),notes=CONCAT(COALESCE(notes,''),IF(COALESCE(notes,'')='','',\"\n\"),:n) WHERE id=:id")->execute(['n'=>'Agente transferiu para humano: '.$reason,'id'=>$conversationId]);
    $msg=trim((string)$cfg['handoff_message']);if($msg!=='')support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',$msg);
}

function support_agent_handle_student_message(PDO $pdo,int $conversationId,int $messageId): void
{
    $cfg=support_agent_config($pdo);if(!$cfg['enabled'])return;
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv||($conv['stage']??'')==='human'||($conv['status']??'')==='closed')return;
    $st=$pdo->prepare("SELECT * FROM support_messages WHERE id=:id AND conversation_id=:c LIMIT 1");$st->execute(['id'=>$messageId,'c'=>$conversationId]);$msg=$st->fetch(PDO::FETCH_ASSOC);if(!$msg||($msg['sender_type']??'')!=='student')return;
    try{
        $pause=(int)($cfg['pause_seconds']??0);if($pause>0){sleep($pause);$newer=$pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE conversation_id=:c AND sender_type='student' AND id>:id");$newer->execute(['c'=>$conversationId,'id'=>$messageId]);if((int)$newer->fetchColumn()>0)return;}
        $body=trim((string)($msg['body']??''));$type=(string)($msg['message_type']??'text');
        if(in_array($type,['image','video','file'],true)){support_agent_handoff($pdo,$conversationId,'Aluno enviou imagem/video/arquivo.', $cfg);return;}
        if($type==='audio'){$body=support_agent_transcribe_audio($pdo,$cfg,$msg);if($body===''){support_agent_handoff($pdo,$conversationId,'Audio sem transcricao confiavel.',$cfg);return;}$pdo->prepare("UPDATE support_messages SET body=:b,metadata_json=:m WHERE id=:id")->execute(['b'=>'Transcricao do audio: '.$body,'m'=>json_encode(['transcription'=>$body],JSON_UNESCAPED_UNICODE),'id'=>$messageId]);}
        $lastBot=$pdo->prepare("SELECT COALESCE(MAX(id),0) FROM support_messages WHERE conversation_id=:c AND sender_type<>'student'");$lastBot->execute(['c'=>$conversationId]);$afterBot=(int)$lastBot->fetchColumn();
        $batchSt=$pdo->prepare("SELECT * FROM support_messages WHERE conversation_id=:c AND sender_type='student' AND id>:a AND id<=:id ORDER BY id ASC");$batchSt->execute(['c'=>$conversationId,'a'=>$afterBot,'id'=>$messageId]);$batch=$batchSt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($batch as $bm)if(in_array((string)($bm['message_type']??'text'),['image','video','file'],true)){support_agent_handoff($pdo,$conversationId,'Aluno enviou imagem/video/arquivo.', $cfg);return;}
        $parts=[];foreach($batch as $bm){$txt=trim((string)($bm['body']??''));if($txt!=='')$parts[]=$txt;}$body=trim(implode("\n",$parts))?:$body;
        $memSt=$pdo->prepare("SELECT * FROM support_agent_memory WHERE conversation_id=:c");$memSt->execute(['c'=>$conversationId]);$memory=$memSt->fetch(PDO::FETCH_ASSOC)?:['summary'=>'','token_count'=>0];
        $payload=support_agent_user_payload($pdo,(int)$conv['user_id']);$recent=support_chat_messages($pdo,$conversationId,max(0,$messageId-25));$estimated=(int)((strlen(json_encode($payload,JSON_UNESCAPED_UNICODE))+strlen((string)$memory['summary'])+strlen($body))/4)+(int)($memory['token_count']??0);
        if($estimated>$cfg['max_tokens']){support_agent_handoff($pdo,$conversationId,'Limite de tokens/contexto atingido.',$cfg);return;}
        $system="Voce e um agente de atendimento, vendas e suporte tecnico da area de membros. Use estritamente o payload do aluno atual. Nunca revele dados de outro aluno. Responda apenas o que tiver certeza pelo contexto. Se faltar dado, action=handoff. Para certificado, avalie progresso e registros antes de orientar. Para live, use datas do payload. Para reagendamento, so confirme datas listadas em reagendamento.opcoes. Funcoes ativas: suporte_basico=".($cfg['basic']?'sim':'nao').", vendas=".($cfg['sales']?'sim':'nao').", suporte_tecnico=".($cfg['technical']?'sim':'nao').", reagendamento=".($cfg['reschedule']?'sim':'nao').". Prompts: suporte={$cfg['prompt_basic']} vendas={$cfg['prompt_sales']} tecnico={$cfg['prompt_technical']}";
        $input=[['role'=>'system','content'=>$system],['role'=>'user','content'=>json_encode(['mensagem_atual'=>$body,'memoria'=>$memory['summary']??'','payload_aluno'=>$payload,'historico_recente'=>$recent],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
        $res=support_agent_call_openai($cfg,$input);$action=(string)($res['action']??'handoff');$confidence=(float)($res['confidence']??0);
        if($action==='confirm_reschedule'&&$cfg['reschedule']){$slot=(string)($res['selected_reschedule_iso']??'');if($slot!==''&&support_agent_reschedule_live($pdo,(int)$conv['user_id'],$slot))$res['answer']=trim((string)$res['answer'])?:'Pronto, sua aula ao vivo foi reagendada.';else $action='handoff';}
        if($action==='handoff'||$confidence<0.55){support_agent_handoff($pdo,$conversationId,(string)($res['reason']??'Baixa confianca.'),$cfg);}
        else{support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',trim((string)$res['answer'])?:'Consegui analisar seu caso, mas vou precisar de mais detalhes.');$pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);}
        $pdo->prepare("INSERT INTO support_agent_memory(conversation_id,summary,token_count,last_intent,last_confidence) VALUES(:c,:s,:t,:i,:cf) ON DUPLICATE KEY UPDATE summary=VALUES(summary),token_count=VALUES(token_count),last_intent=VALUES(last_intent),last_confidence=VALUES(last_confidence)")->execute(['c'=>$conversationId,'s'=>mb_substr((string)($res['memory_summary']??''),0,12000),'t'=>min(999999,(int)($res['tokens_estimate']??$estimated)),'i'=>mb_substr((string)($res['intent']??''),0,80),'cf'=>$confidence]);
    }catch(Throwable $e){support_agent_handoff($pdo,$conversationId,'Erro do agente: '.$e->getMessage(),$cfg);}
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
            elseif($n['type']==='condition'){$field=(string)($cfg['field']??'stage');$expected=mb_strtolower(trim((string)($cfg['value']??'')));$actual=mb_strtolower((string)($conv[$field]??''));$handle=str_contains($actual,$expected)?'yes':'no';}
            $current=$next[$current][$handle]??null;
        }
        $pdo->prepare("UPDATE support_automation_runs SET status='completed',log_json=:l,finished_at=NOW() WHERE id=:id")->execute(['l'=>json_encode($log,JSON_UNESCAPED_UNICODE),'id'=>$runId]);
    }
}
