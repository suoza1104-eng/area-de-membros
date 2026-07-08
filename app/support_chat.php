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
    foreach (['support_chat_student_enabled'=>'0','support_chat_test_mode'=>'1','support_chat_welcome'=>'Olá! Como podemos ajudar?','support_chat_offline_message'=>'Recebemos sua mensagem e responderemos assim que possível.'] as $key=>$value) {
        $st=$pdo->prepare("INSERT IGNORE INTO settings (chave,valor) VALUES (:k,:v)");
        try {$st->execute(['k'=>$key,'v'=>$value]);} catch (Throwable $ignored) {}
    }
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
