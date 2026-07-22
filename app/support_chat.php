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
        'support_agent_prompt_basic'=>support_agent_default_prompt('basic'),
        'support_agent_prompt_sales'=>support_agent_default_prompt('sales'),
        'support_agent_prompt_technical'=>support_agent_default_prompt('technical'),
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

function support_agent_default_prompt(string $type): string
{
    $common="Use apenas os dados do payload_aluno. A mensagem atual do aluno fica em mensagem_atual; memoria e historico_recente sao apenas contexto. Nao invente informacao. Se o pedido do aluno nao estiver coberto pelo prompt nem pelos dados do banco/payload, transfira para humano. Se faltar dado para responder com certeza, transfira para humano. Seja direto, humano e natural. Cumprimente apenas na primeira resposta do agente.";
    if($type==='sales')return $common." Em vendas, responda somente se houver preco, oferta, curso ou condicao no payload/contexto. Se nao houver, colete a duvida e transfira para humano. Nao prometa bonus, desconto, prazo ou garantia que nao esteja no payload.";
    if($type==='technical')return $common." Em suporte tecnico, ajude com login, acesso ao curso, video/audio, link de acesso, grupo, live e certificado. Para erro com arquivo, imagem ou situacao sem diagnostico no payload, transfira para humano.";
    return $common." Regras de certificado: se certificado.tem_certificado_emitido=true, informe que ja existe certificado e use certificado.link_verificacao ou certificado.pdf_url. Nao use certificado.link_emitir quando o certificado ja foi emitido. Se nao existe certificado emitido, verifique criterios_e_pendencias: aulas obrigatorias, aulas concluidas, percentual de avanco, aulas faltantes, eventos de live e demais pendencias. Diga exatamente o que falta. Para live, use data_live, eventos da live e reagendamentos. Para grupo e acesso, use os links do payload quando existirem.";
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

function support_chat_send(PDO $pdo,int $conversationId,string $senderType,string $senderId,string $senderName,string $body,array $attachment=[],array $metadata=[]): int
{
    $body=trim($body);if($body===''&&!$attachment)throw new InvalidArgumentException('Digite uma mensagem ou anexe um arquivo.');
    if(mb_strlen($body)>10000)throw new InvalidArgumentException('Mensagem muito longa.');
    $type=(string)($attachment['type']??'text');
    $st=$pdo->prepare("INSERT INTO support_messages(conversation_id,sender_type,sender_id,sender_name,message_type,body,attachment_url,attachment_name,attachment_mime,attachment_size,metadata_json) VALUES(:c,:st,:si,:sn,:mt,:b,:url,:an,:am,:az,:meta)");
    $st->execute(['c'=>$conversationId,'st'=>$senderType,'si'=>$senderId,'sn'=>$senderName,'mt'=>$type,'b'=>$body!==''?$body:null,'url'=>$attachment['url']??null,'an'=>$attachment['name']??null,'am'=>$attachment['mime']??null,'az'=>$attachment['size']??null,'meta'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
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

function support_agent_send_answer(PDO $pdo,int $conversationId,string $answer,bool $firstAgentReply): void
{
    $prepared=support_agent_prepare_answer($answer,$firstAgentReply);$parts=support_agent_split_answer((string)$prepared['body']);
    foreach($parts as $i=>$part){$last=$i===count($parts)-1;support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',$part,[],$last?$prepared['metadata']:[]);if(!$last){support_chat_typing($pdo,$conversationId,'bot','Agente de suporte');sleep(random_int(4,6));}}
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
        'max_tokens'=>max(500,min(12000,(int)get_setting('support_agent_max_tokens','3000'))),
        'pause_seconds'=>max(0,min(30,(int)get_setting('support_agent_pause_seconds','5'))),
        'model'=>trim((string)get_setting('whatsapp_ai_model','gpt-4.1-mini'))?:'gpt-4.1-mini',
        'api_key'=>trim((string)get_setting('whatsapp_ai_openai_api_key','')),
        'temperature'=>max(0,min(1,(float)get_setting('whatsapp_ai_temperature','0.2'))),
        'prompt_basic'=>trim((string)get_setting('support_agent_prompt_basic',''))?:support_agent_default_prompt('basic'),
        'prompt_sales'=>trim((string)get_setting('support_agent_prompt_sales',''))?:support_agent_default_prompt('sales'),
        'prompt_technical'=>trim((string)get_setting('support_agent_prompt_technical',''))?:support_agent_default_prompt('technical'),
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
            'grupos_whatsapp'=>$groupLinks,
        ],
        'tags'=>$tags,
        'curso'=>['aulas_obrigatorias'=>$required,'aulas_concluidas'=>$done,'percentual_avanco'=>$coursePct,'aulas_faltantes_obrigatorias'=>$missing,'aulas'=>$lessons,'visualizacoes'=>$views],
        'certificado'=>[
            'tem_certificado_emitido'=>$issuedCert!==null,
            'pode_iniciar_emissao_agora'=>$required>0&&$done>=$required,
            'regra_emissao'=>'O certificado so pode ser emitido quando todas as aulas obrigatorias estiverem concluidas; depois o aluno confirma a senha do certificado e o nome.',
            'criterios_e_pendencias'=>[
                'aulas_obrigatorias_total'=>$required,'aulas_obrigatorias_concluidas'=>$done,'percentual_obrigatorio'=>$coursePct,
                'aulas_faltantes'=>$missing,
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
    $conv=support_chat_detail($pdo,$conversationId);if($conv&&($conv['stage']??'')==='human')return;
    $pdo->prepare("UPDATE support_conversations SET status='pending',stage='human',priority=IF(priority='normal','high',priority),notes=CONCAT(COALESCE(notes,''),IF(COALESCE(notes,'')='','',\"\n\"),:n) WHERE id=:id")->execute(['n'=>'Agente transferiu para humano: '.$reason,'id'=>$conversationId]);
    $msg=trim((string)$cfg['handoff_message']);if($msg!=='')support_chat_send($pdo,$conversationId,'bot','support_agent','Agente de suporte',$msg);
}

function support_agent_is_human_request(string $body): bool
{
    $plain=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$body);$b=strtolower($plain!==false?$plain:$body);return str_contains($b,'atendimento humano')||str_contains($b,'falar com humano')||str_contains($b,'pessoa da equipe')||str_contains($b,'atendente humano')||str_contains($b,'quero atendente')||str_contains($b,'chamar atendente');
}

function support_agent_handle_student_message(PDO $pdo,int $conversationId,int $messageId): void
{
    $cfg=support_agent_config($pdo);if(!$cfg['enabled'])return;
    $conv=support_chat_detail($pdo,$conversationId);if(!$conv||($conv['stage']??'')==='human'||($conv['status']??'')==='closed')return;
    $st=$pdo->prepare("SELECT * FROM support_messages WHERE id=:id AND conversation_id=:c LIMIT 1");$st->execute(['id'=>$messageId,'c'=>$conversationId]);$msg=$st->fetch(PDO::FETCH_ASSOC);if(!$msg||($msg['sender_type']??'')!=='student')return;
    try{
        $pause=(int)($cfg['pause_seconds']??0);if($pause>0){sleep($pause);$newer=$pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE conversation_id=:c AND sender_type='student' AND id>:id");$newer->execute(['c'=>$conversationId,'id'=>$messageId]);if((int)$newer->fetchColumn()>0)return;}
        $body=trim((string)($msg['body']??''));$type=(string)($msg['message_type']??'text');
        if(support_agent_is_human_request($body)){support_agent_handoff($pdo,$conversationId,'Aluno pediu atendimento humano.',$cfg);return;}
        if(in_array($type,['image','video','file'],true)){support_agent_handoff($pdo,$conversationId,'Aluno enviou imagem/video/arquivo.', $cfg);return;}
        if($type==='audio'){$body=support_agent_transcribe_audio($pdo,$cfg,$msg);if($body===''){support_agent_handoff($pdo,$conversationId,'Audio sem transcricao confiavel.',$cfg);return;}$pdo->prepare("UPDATE support_messages SET body=:b,metadata_json=:m WHERE id=:id")->execute(['b'=>'Transcricao do audio: '.$body,'m'=>json_encode(['transcription'=>$body],JSON_UNESCAPED_UNICODE),'id'=>$messageId]);}
        $agentCount=$pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE conversation_id=:c AND sender_type='bot' AND sender_id='support_agent' AND id<:id");$agentCount->execute(['c'=>$conversationId,'id'=>$messageId]);$firstAgentReply=((int)$agentCount->fetchColumn())===0;
        $memSt=$pdo->prepare("SELECT * FROM support_agent_memory WHERE conversation_id=:c");$memSt->execute(['c'=>$conversationId]);$memory=$memSt->fetch(PDO::FETCH_ASSOC)?:['summary'=>'','token_count'=>0];
        $payload=support_agent_user_payload($pdo,(int)$conv['user_id']);$recent=array_values(array_filter(support_chat_messages($pdo,$conversationId,max(0,$messageId-25)),static fn($m)=>(int)($m['id']??0)<$messageId));$estimated=(int)((strlen(json_encode($payload,JSON_UNESCAPED_UNICODE))+strlen((string)$memory['summary'])+strlen($body))/4)+(int)($memory['token_count']??0);
        if($estimated>$cfg['max_tokens']){support_agent_handoff($pdo,$conversationId,'Limite de tokens/contexto atingido.',$cfg);return;}
        $system="Voce e um agente de atendimento, vendas e suporte tecnico da area de membros. Use estritamente o payload do aluno atual. Nunca revele dados de outro aluno. A mensagem do aluno que voce deve responder agora esta em mensagem_atual; historico_recente e memoria servem apenas como contexto, nao repita nem trate como nova pergunta. Cumprimente somente se primeira_resposta_do_agente=true; se for false, responda direto sem 'ola', 'oi', 'bom dia', 'boa tarde' ou 'boa noite'. Responda apenas o que tiver certeza pelo contexto. Se o pedido nao estiver nos prompts nem no payload/banco, action=handoff. Se faltar dado, action=handoff. Certificado: sempre olhe payload_aluno.certificado. Se tem_certificado_emitido=true, diga que ja existe e use link_verificacao ou pdf_url; nunca use link_emitir nesse caso. Se true mas link_verificacao/pdf_url estiverem vazios, action=handoff. Se false, olhe criterios_e_pendencias e diga exatamente o que falta, principalmente aulas_faltantes. Se pode_iniciar_emissao_agora=true, oriente a emitir pelo link_emitir e explique que a tela pedira senha e confirmacao do nome; nao informe senha se ela nao estiver no payload/contexto. Para live, use datas do payload. Para grupos e acesso, use payload_aluno.links e payload_aluno.variaveis_configuradas. Se a resposta ficar grande, escreva naturalmente em blocos curtos; o sistema pode dividir em mensagens com pausa. Para reagendamento, so confirme datas listadas em reagendamento.opcoes. Funcoes ativas: suporte_basico=".($cfg['basic']?'sim':'nao').", vendas=".($cfg['sales']?'sim':'nao').", suporte_tecnico=".($cfg['technical']?'sim':'nao').", reagendamento=".($cfg['reschedule']?'sim':'nao').". Prompts: suporte={$cfg['prompt_basic']} vendas={$cfg['prompt_sales']} tecnico={$cfg['prompt_technical']}";
        $input=[['role'=>'system','content'=>$system],['role'=>'user','content'=>json_encode(['mensagem_atual'=>$body,'primeira_resposta_do_agente'=>$firstAgentReply,'memoria'=>$memory['summary']??'','payload_aluno'=>$payload,'historico_recente'=>$recent],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
        $res=support_agent_call_openai($cfg,$input);$action=(string)($res['action']??'handoff');$confidence=(float)($res['confidence']??0);
        if($action==='confirm_reschedule'&&$cfg['reschedule']){$slot=(string)($res['selected_reschedule_iso']??'');if($slot!==''&&support_agent_reschedule_live($pdo,(int)$conv['user_id'],$slot))$res['answer']=trim((string)$res['answer'])?:'Pronto, sua aula ao vivo foi reagendada.';else $action='handoff';}
        if($action==='handoff'||$confidence<0.55){support_agent_handoff($pdo,$conversationId,(string)($res['reason']??'Baixa confianca.'),$cfg);}
        else{$answer=support_agent_normalize_answer_for_payload(trim((string)$res['answer'])?:'Consegui analisar seu caso, mas vou precisar de mais detalhes.',$payload);support_agent_send_answer($pdo,$conversationId,$answer,$firstAgentReply);$pdo->prepare("UPDATE support_conversations SET stage='agent' WHERE id=:id AND stage<>'human'")->execute(['id'=>$conversationId]);}
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
