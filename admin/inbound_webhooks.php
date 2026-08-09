<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/dom_pagamentos.php';
proteger_admin();

$pdo = getPDO();

// Tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS inbound_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    descricao TEXT NULL,
    evento VARCHAR(100) NOT NULL,
    lesson_id INT NULL,
    codigo_turma VARCHAR(100) NULL,
    tag_extra VARCHAR(200) NULL,
    token VARCHAR(64) NOT NULL,
    payload_map_json TEXT NULL,
    disparar_webhook TINYINT(1) NOT NULL DEFAULT 1,
    disparar_sf TINYINT(1) NOT NULL DEFAULT 1,
    disparar_manychat TINYINT(1) NOT NULL DEFAULT 1,
    direct_webhook_url VARCHAR(1000) NULL,
    direct_webhook_method VARCHAR(10) NOT NULL DEFAULT 'POST',
    direct_webhook_headers_json TEXT NULL,
    direct_webhook_payload_format VARCHAR(20) NOT NULL DEFAULT 'json',
    direct_sf_tags_text TEXT NULL,
    direct_sf_flows_text TEXT NULL,
    direct_sf_fields_json TEXT NULL,
    direct_manychat_tags_text TEXT NULL,
    direct_manychat_flows_text TEXT NULL,
    direct_manychat_fields_json TEXT NULL,
    criar_se_nao_existir TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    total_recebidos INT UNSIGNED NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_iw_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN oferta_codigo VARCHAR(500) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_webhook TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_sf TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE inbound_webhooks ADD COLUMN disparar_manychat TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
foreach ([
    'direct_webhook_url' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_webhook_url VARCHAR(1000) NULL",
    'direct_webhook_method' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_webhook_method VARCHAR(10) NOT NULL DEFAULT 'POST'",
    'direct_webhook_headers_json' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_webhook_headers_json TEXT NULL",
    'direct_webhook_payload_format' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_webhook_payload_format VARCHAR(20) NOT NULL DEFAULT 'json'",
    'direct_sf_tags_text' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_sf_tags_text TEXT NULL",
    'direct_sf_flows_text' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_sf_flows_text TEXT NULL",
    'direct_sf_fields_json' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_sf_fields_json TEXT NULL",
    'direct_manychat_tags_text' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_manychat_tags_text TEXT NULL",
    'direct_manychat_flows_text' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_manychat_flows_text TEXT NULL",
    'direct_manychat_fields_json' => "ALTER TABLE inbound_webhooks ADD COLUMN direct_manychat_fields_json TEXT NULL",
] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
$pdo->exec("CREATE TABLE IF NOT EXISTS inbound_webhook_recebimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    user_id INT NULL,
    payload_raw TEXT NOT NULL,
    status ENUM('pendente','processado','erro') NOT NULL DEFAULT 'pendente',
    erro_msg TEXT NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME NULL,
    INDEX idx_iwr_webhook (webhook_id),
    INDEX idx_iwr_status (status),
    INDEX idx_iwr_recebido (recebido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE inbound_webhook_recebimentos MODIFY COLUMN status ENUM('pendente','processado','erro','ignorado') NOT NULL DEFAULT 'pendente'"); } catch (Throwable $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    evento VARCHAR(80) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    metodo VARCHAR(10) NOT NULL DEFAULT 'POST',
    headers_json TEXT NULL,
    payload_format VARCHAR(20) NOT NULL DEFAULT 'json',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_webhooks_evento (evento),
    KEY idx_webhooks_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function iw_direct_event(string $tipo, int $id): string {
    return 'INBOUND_' . strtoupper($tipo) . '_' . $id;
}

function iw_clean_json_or_null(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $tmp = json_decode($raw, true);
    return is_array($tmp) ? json_encode($tmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

function iw_sync_direct_rules(PDO $pdo, int $id, array $cfg): void {
    if ($id <= 0) return;
    $nome = trim((string)($cfg['nome'] ?? 'Entrada #' . $id));

    $whEvent = iw_direct_event('WEBHOOK', $id);
    $whUrl = trim((string)($cfg['direct_webhook_url'] ?? ''));
    if ((int)($cfg['disparar_webhook'] ?? 0) === 1 && $whUrl !== '') {
        $method = strtoupper(trim((string)($cfg['direct_webhook_method'] ?? 'POST')));
        if (!in_array($method, ['POST','GET','PUT','PATCH'], true)) $method = 'POST';
        $format = strtolower(trim((string)($cfg['direct_webhook_payload_format'] ?? 'json')));
        if (!in_array($format, ['json','form'], true)) $format = 'json';
        $headers = iw_clean_json_or_null((string)($cfg['direct_webhook_headers_json'] ?? ''));
        $st = $pdo->prepare("SELECT id FROM webhooks WHERE evento = :e ORDER BY id DESC LIMIT 1");
        $st->execute([':e' => $whEvent]);
        $whId = (int)($st->fetchColumn() ?: 0);
        if ($whId > 0) {
            $pdo->prepare("UPDATE webhooks SET nome=:n,url=:u,metodo=:m,headers_json=:h,payload_format=:pf,ativo=1 WHERE id=:id")
                ->execute([':n'=>'[Entrada #' . $id . '] ' . $nome, ':u'=>$whUrl, ':m'=>$method, ':h'=>$headers, ':pf'=>$format, ':id'=>$whId]);
        } else {
            $pdo->prepare("INSERT INTO webhooks (nome,evento,url,metodo,headers_json,payload_format,ativo) VALUES (:n,:e,:u,:m,:h,:pf,1)")
                ->execute([':n'=>'[Entrada #' . $id . '] ' . $nome, ':e'=>$whEvent, ':u'=>$whUrl, ':m'=>$method, ':h'=>$headers, ':pf'=>$format]);
        }
    } else {
        try {
            $pdo->prepare("UPDATE webhooks SET ativo=0 WHERE evento=:e")->execute([':e' => $whEvent]);
        } catch (Throwable $e) {}
    }

    if (function_exists('sf_ensure_tables')) sf_ensure_tables($pdo);
    $sfEvent = iw_direct_event('SF', $id);
    $sfTags = trim((string)($cfg['direct_sf_tags_text'] ?? ''));
    $sfFlows = trim((string)($cfg['direct_sf_flows_text'] ?? ''));
    $sfFields = iw_clean_json_or_null((string)($cfg['direct_sf_fields_json'] ?? '')) ?? '[]';
    $sfHas = ($sfTags !== '' || $sfFlows !== '' || $sfFields !== '[]');
    if ((int)($cfg['disparar_sf'] ?? 0) === 1 && $sfHas) {
        $st = $pdo->prepare("SELECT id FROM superfuncionario_rules WHERE evento = :e ORDER BY id DESC LIMIT 1");
        $st->execute([':e' => $sfEvent]);
        $rid = (int)($st->fetchColumn() ?: 0);
        if ($rid > 0) {
            $pdo->prepare("UPDATE superfuncionario_rules SET nome=:n,is_active=1,tags_text=:t,flows_text=:f,fields_json=:fj WHERE id=:id")
                ->execute([':n'=>'[Entrada #' . $id . '] ' . $nome, ':t'=>$sfTags, ':f'=>$sfFlows, ':fj'=>$sfFields, ':id'=>$rid]);
        } else {
            $pdo->prepare("INSERT INTO superfuncionario_rules (nome,evento,is_active,tags_text,flows_text,fields_json) VALUES (:n,:e,1,:t,:f,:fj)")
                ->execute([':n'=>'[Entrada #' . $id . '] ' . $nome, ':e'=>$sfEvent, ':t'=>$sfTags, ':f'=>$sfFlows, ':fj'=>$sfFields]);
        }
    } else {
        try {
            $pdo->prepare("UPDATE superfuncionario_rules SET is_active=0 WHERE evento=:e")->execute([':e' => $sfEvent]);
        } catch (Throwable $e) {}
    }

    if (function_exists('mc_ensure_tables')) mc_ensure_tables($pdo);
    $mcEvent = iw_direct_event('MANYCHAT', $id);
    $mcTags = trim((string)($cfg['direct_manychat_tags_text'] ?? ''));
    $mcFlows = trim((string)($cfg['direct_manychat_flows_text'] ?? ''));
    $mcFields = iw_clean_json_or_null((string)($cfg['direct_manychat_fields_json'] ?? '')) ?? '[]';
    $mcHas = ($mcTags !== '' || $mcFlows !== '' || $mcFields !== '[]');
    if ((int)($cfg['disparar_manychat'] ?? 0) === 1 && $mcHas) {
        $st = $pdo->prepare("SELECT id FROM manychat_rules WHERE evento = :e ORDER BY id DESC LIMIT 1");
        $st->execute([':e' => $mcEvent]);
        $rid = (int)($st->fetchColumn() ?: 0);
        if ($rid > 0) {
            $pdo->prepare("UPDATE manychat_rules SET nome=:n,is_active=1,tags_text=:t,flows_text=:f,fields_json=:fj WHERE id=:id")
                ->execute([':n'=>'[Entrada #' . $id . '] ' . $nome, ':t'=>$mcTags, ':f'=>$mcFlows, ':fj'=>$mcFields, ':id'=>$rid]);
        } else {
            $pdo->prepare("INSERT INTO manychat_rules (nome,evento,is_active,tags_text,flows_text,fields_json) VALUES (:n,:e,1,:t,:f,:fj)")
                ->execute([':n'=>'[Entrada #' . $id . '] ' . $nome, ':e'=>$mcEvent, ':t'=>$mcTags, ':f'=>$mcFlows, ':fj'=>$mcFields]);
        }
    } else {
        try {
            $pdo->prepare("UPDATE manychat_rules SET is_active=0 WHERE evento=:e")->execute([':e' => $mcEvent]);
        } catch (Throwable $e) {}
    }
}

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($acao === 'salvar_dom') {
        set_setting('dom_pagamentos_enabled', isset($_POST['enabled']) ? '1' : '0');
        set_setting('dom_pagamentos_environment', in_array(($_POST['environment'] ?? ''), ['production','sandbox'], true) ? (string)$_POST['environment'] : 'production');
        $apiToken = trim((string)($_POST['api_token'] ?? ''));
        if ($apiToken !== '') set_setting('dom_pagamentos_api_token', $apiToken);
        set_setting('dom_pagamentos_require_signature', isset($_POST['require_signature']) ? '1' : '0');
        set_setting('dom_pagamentos_notes', trim((string)($_POST['notes'] ?? '')));
        echo json_encode(['ok'=>true]); exit;
    }

    if ($acao === 'dom_overview') {
        dom_ensure_schema($pdo);
        $start = trim((string)($_GET['start'] ?? date('Y-m-d', strtotime('-30 days'))));
        $end = trim((string)($_GET['end'] ?? date('Y-m-d')));
        $status = strtoupper(trim((string)($_GET['status'] ?? '')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
        $saleWhere = "provider='dom' AND last_received_at BETWEEN :start_dt AND :end_dt";
        $saleParams = [':start_dt'=>$start . ' 00:00:00', ':end_dt'=>$end . ' 23:59:59'];
        if ($status !== '') {
            $saleWhere .= " AND normalized_status = :status";
            $saleParams[':status'] = $status;
        }

        $eventStmt = $pdo->prepare("SELECT COUNT(*) FROM dom_webhook_events WHERE received_at BETWEEN :start_dt AND :end_dt");
        $eventStmt->execute([':start_dt'=>$start . ' 00:00:00', ':end_dt'=>$end . ' 23:59:59']);
        $eventsTotal = (int)$eventStmt->fetchColumn();

        $kpiStmt = $pdo->prepare("SELECT
            COUNT(*) total,
            SUM(normalized_status='APPROVED') approved,
            SUM(normalized_status='PENDING') pending,
            SUM(normalized_status IN ('REFUNDED','CHARGEBACK','CANCELED')) problems,
            SUM(matched_user_id IS NOT NULL) matched,
            COALESCE(SUM(CASE WHEN normalized_status='APPROVED' THEN gross_amount_cents ELSE 0 END),0) revenue_cents
            FROM payment_sales WHERE {$saleWhere}");
        $kpiStmt->execute($saleParams);
        $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $dailyStmt = $pdo->prepare("SELECT DATE(last_received_at) day, normalized_status, COUNT(*) qty, COALESCE(SUM(gross_amount_cents),0) cents
            FROM payment_sales WHERE {$saleWhere} GROUP BY DATE(last_received_at), normalized_status ORDER BY day ASC");
        $dailyStmt->execute($saleParams);
        $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

        $statusStmt = $pdo->prepare("SELECT normalized_status status, COUNT(*) qty, COALESCE(SUM(gross_amount_cents),0) cents
            FROM payment_sales WHERE {$saleWhere} GROUP BY normalized_status ORDER BY qty DESC");
        $statusStmt->execute($saleParams);
        $byStatus = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

        $recentStmt = $pdo->prepare("SELECT external_transaction_id,normalized_status,gross_amount_cents,product_name,buyer_name,buyer_email,matched_user_id,last_received_at
            FROM payment_sales WHERE {$saleWhere} ORDER BY last_received_at DESC LIMIT 12");
        $recentStmt->execute($saleParams);
        $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok'=>true,'filters'=>['start'=>$start,'end'=>$end,'status'=>$status],'events_total'=>$eventsTotal,'kpi'=>$kpi,'daily'=>$daily,'by_status'=>$byStatus,'recent'=>$recent], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($acao === 'dom_logs') {
        dom_ensure_schema($pdo);
        $start = trim((string)($_GET['start'] ?? date('Y-m-d', strtotime('-30 days'))));
        $end = trim((string)($_GET['end'] ?? date('Y-m-d')));
        $process = trim((string)($_GET['process'] ?? ''));
        $event = trim((string)($_GET['event'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
        $where = "received_at BETWEEN :start_dt AND :end_dt";
        $params = [':start_dt'=>$start . ' 00:00:00', ':end_dt'=>$end . ' 23:59:59'];
        if (in_array($process, ['success','ignored','error'], true)) {
            $where .= " AND process_status = :process";
            $params[':process'] = $process;
        }
        if ($event !== '') {
            $where .= " AND event_name = :event";
            $params[':event'] = $event;
        }
        $st = $pdo->prepare("SELECT id,event_name,external_transaction_id,provider_status,signature_valid,process_status,process_message,payload_json,received_at,processed_at FROM dom_webhook_events WHERE {$where} ORDER BY id DESC LIMIT 120");
        $st->execute($params);
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($acao === 'salvar') {
        $id        = (int)($_POST['id'] ?? 0);
        $nome      = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $evento    = trim((string)($_POST['evento'] ?? ''));
        $lessonId  = (int)($_POST['lesson_id'] ?? 0);
        $codTurma  = trim((string)($_POST['codigo_turma'] ?? ''));
        $tagExtra  = trim((string)($_POST['tag_extra'] ?? ''));
        $ofertaCod = trim((string)($_POST['oferta_codigo'] ?? ''));
        $mapJson   = trim((string)($_POST['payload_map_json'] ?? ''));
        $dispWebhook = isset($_POST['disparar_webhook']) ? 1 : 0;
        $dispSf = isset($_POST['disparar_sf']) ? 1 : 0;
        $dispManychat = isset($_POST['disparar_manychat']) ? 1 : 0;
        $directWebhookUrl = trim((string)($_POST['direct_webhook_url'] ?? ''));
        $directWebhookMethod = strtoupper(trim((string)($_POST['direct_webhook_method'] ?? 'POST')));
        $directWebhookHeaders = trim((string)($_POST['direct_webhook_headers_json'] ?? ''));
        $directWebhookFormat = strtolower(trim((string)($_POST['direct_webhook_payload_format'] ?? 'json')));
        $directSfTags = trim((string)($_POST['direct_sf_tags_text'] ?? ''));
        $directSfFlows = trim((string)($_POST['direct_sf_flows_text'] ?? ''));
        $directSfFields = trim((string)($_POST['direct_sf_fields_json'] ?? ''));
        $directManychatTags = trim((string)($_POST['direct_manychat_tags_text'] ?? ''));
        $directManychatFlows = trim((string)($_POST['direct_manychat_flows_text'] ?? ''));
        $directManychatFields = trim((string)($_POST['direct_manychat_fields_json'] ?? ''));
        $criar     = isset($_POST['criar_se_nao_existir']) ? 1 : 0;

        if ($mapJson === '') $mapJson = json_encode(['nome'=>'nome','email'=>'email','telefone'=>'telefone','oferta'=>'oferta','utm_source'=>'utm_source','utm_medium'=>'utm_medium','utm_campaign'=>'utm_campaign','utm_term'=>'utm_term','utm_content'=>'utm_content','retorno_data'=>'retorno_data','retorno_tipo'=>'retorno_tipo','retorno_assunto'=>'retorno_assunto','retorno_mensagem'=>'retorno_mensagem']);
        if ($nome === '' || $evento === '') { echo json_encode(['ok'=>false,'msg'=>'Nome e evento são obrigatórios']); exit; }
        if ($evento === 'VIU_AULA' && $lessonId <= 0) { echo json_encode(['ok'=>false,'msg'=>'Selecione a aula']); exit; }

        if ($id > 0) {
            $pdo->prepare("UPDATE inbound_webhooks SET nome=:n,descricao=:d,evento=:ev,lesson_id=:l,codigo_turma=:ct,tag_extra=:tg,oferta_codigo=:of,payload_map_json=:m,disparar_webhook=:dw,disparar_sf=:dsf,disparar_manychat=:dm,direct_webhook_url=:whu,direct_webhook_method=:whm,direct_webhook_headers_json=:whh,direct_webhook_payload_format=:whf,direct_sf_tags_text=:sft,direct_sf_flows_text=:sff,direct_sf_fields_json=:sfj,direct_manychat_tags_text=:mct,direct_manychat_flows_text=:mcf,direct_manychat_fields_json=:mcj,criar_se_nao_existir=:cr WHERE id=:id")
                ->execute([':n'=>$nome,':d'=>$descricao,':ev'=>$evento,':l'=>$lessonId?:null,':ct'=>$codTurma?:null,':tg'=>$tagExtra?:null,':of'=>$ofertaCod?:null,':m'=>$mapJson,':dw'=>$dispWebhook,':dsf'=>$dispSf,':dm'=>$dispManychat,':whu'=>$directWebhookUrl?:null,':whm'=>$directWebhookMethod?:'POST',':whh'=>$directWebhookHeaders?:null,':whf'=>$directWebhookFormat?:'json',':sft'=>$directSfTags?:null,':sff'=>$directSfFlows?:null,':sfj'=>$directSfFields?:null,':mct'=>$directManychatTags?:null,':mcf'=>$directManychatFlows?:null,':mcj'=>$directManychatFields?:null,':cr'=>$criar,':id'=>$id]);
        } else {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO inbound_webhooks (nome,descricao,evento,lesson_id,codigo_turma,tag_extra,oferta_codigo,token,payload_map_json,disparar_webhook,disparar_sf,disparar_manychat,direct_webhook_url,direct_webhook_method,direct_webhook_headers_json,direct_webhook_payload_format,direct_sf_tags_text,direct_sf_flows_text,direct_sf_fields_json,direct_manychat_tags_text,direct_manychat_flows_text,direct_manychat_fields_json,criar_se_nao_existir) VALUES (:n,:d,:ev,:l,:ct,:tg,:of,:tk,:m,:dw,:dsf,:dm,:whu,:whm,:whh,:whf,:sft,:sff,:sfj,:mct,:mcf,:mcj,:cr)")
                ->execute([':n'=>$nome,':d'=>$descricao,':ev'=>$evento,':l'=>$lessonId?:null,':ct'=>$codTurma?:null,':tg'=>$tagExtra?:null,':of'=>$ofertaCod?:null,':tk'=>$token,':m'=>$mapJson,':dw'=>$dispWebhook,':dsf'=>$dispSf,':dm'=>$dispManychat,':whu'=>$directWebhookUrl?:null,':whm'=>$directWebhookMethod?:'POST',':whh'=>$directWebhookHeaders?:null,':whf'=>$directWebhookFormat?:'json',':sft'=>$directSfTags?:null,':sff'=>$directSfFlows?:null,':sfj'=>$directSfFields?:null,':mct'=>$directManychatTags?:null,':mcf'=>$directManychatFlows?:null,':mcj'=>$directManychatFields?:null,':cr'=>$criar]);
            $id = (int)$pdo->lastInsertId();
        }
        iw_sync_direct_rules($pdo, $id, [
            'nome'=>$nome,
            'disparar_webhook'=>$dispWebhook,
            'disparar_sf'=>$dispSf,
            'disparar_manychat'=>$dispManychat,
            'direct_webhook_url'=>$directWebhookUrl,
            'direct_webhook_method'=>$directWebhookMethod,
            'direct_webhook_headers_json'=>$directWebhookHeaders,
            'direct_webhook_payload_format'=>$directWebhookFormat,
            'direct_sf_tags_text'=>$directSfTags,
            'direct_sf_flows_text'=>$directSfFlows,
            'direct_sf_fields_json'=>$directSfFields,
            'direct_manychat_tags_text'=>$directManychatTags,
            'direct_manychat_flows_text'=>$directManychatFlows,
            'direct_manychat_fields_json'=>$directManychatFields,
        ]);
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    if ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try { $pdo->prepare("UPDATE webhooks SET ativo=0 WHERE evento IN (:w,:s,:m)")->execute([':w'=>iw_direct_event('WEBHOOK', $id), ':s'=>iw_direct_event('SF', $id), ':m'=>iw_direct_event('MANYCHAT', $id)]); } catch (Throwable $e) {}
            try { $pdo->prepare("UPDATE superfuncionario_rules SET is_active=0 WHERE evento=:e")->execute([':e'=>iw_direct_event('SF', $id)]); } catch (Throwable $e) {}
            try { $pdo->prepare("UPDATE manychat_rules SET is_active=0 WHERE evento=:e")->execute([':e'=>iw_direct_event('MANYCHAT', $id)]); } catch (Throwable $e) {}
            $pdo->prepare("DELETE FROM inbound_webhook_recebimentos WHERE webhook_id = :id")->execute([':id'=>$id]);
            $pdo->prepare("DELETE FROM inbound_webhooks WHERE id = :id")->execute([':id'=>$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($acao === 'clonar') {
        $id = (int)($_POST['id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM inbound_webhooks WHERE id = :id");
        $r->execute([':id'=>$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok'=>false]); exit; }
        $token = bin2hex(random_bytes(32));
        $cloneName = '[Copia] '.$row['nome'];
        $pdo->prepare("INSERT INTO inbound_webhooks (nome,descricao,evento,lesson_id,codigo_turma,tag_extra,oferta_codigo,token,payload_map_json,disparar_webhook,disparar_sf,disparar_manychat,direct_webhook_url,direct_webhook_method,direct_webhook_headers_json,direct_webhook_payload_format,direct_sf_tags_text,direct_sf_flows_text,direct_sf_fields_json,direct_manychat_tags_text,direct_manychat_flows_text,direct_manychat_fields_json,criar_se_nao_existir,ativo) VALUES (:n,:d,:ev,:l,:ct,:tg,:of,:tk,:m,:dw,:dsf,:dm,:whu,:whm,:whh,:whf,:sft,:sff,:sfj,:mct,:mcf,:mcj,:cr,1)")
            ->execute([':n'=>$cloneName, ':d'=>$row['descricao'], ':ev'=>$row['evento'], ':l'=>$row['lesson_id'], ':ct'=>$row['codigo_turma'], ':tg'=>$row['tag_extra'], ':of'=>$row['oferta_codigo']??null, ':tk'=>$token, ':m'=>$row['payload_map_json'], ':dw'=>(int)($row['disparar_webhook'] ?? 1), ':dsf'=>(int)($row['disparar_sf'] ?? 1), ':dm'=>(int)($row['disparar_manychat'] ?? 1), ':whu'=>$row['direct_webhook_url'] ?? null, ':whm'=>$row['direct_webhook_method'] ?? 'POST', ':whh'=>$row['direct_webhook_headers_json'] ?? null, ':whf'=>$row['direct_webhook_payload_format'] ?? 'json', ':sft'=>$row['direct_sf_tags_text'] ?? null, ':sff'=>$row['direct_sf_flows_text'] ?? null, ':sfj'=>$row['direct_sf_fields_json'] ?? null, ':mct'=>$row['direct_manychat_tags_text'] ?? null, ':mcf'=>$row['direct_manychat_flows_text'] ?? null, ':mcj'=>$row['direct_manychat_fields_json'] ?? null, ':cr'=>$row['criar_se_nao_existir']]);
        $newId = (int)$pdo->lastInsertId();
        iw_sync_direct_rules($pdo, $newId, [
            'nome'=>$cloneName,
            'disparar_webhook'=>(int)($row['disparar_webhook'] ?? 1),
            'disparar_sf'=>(int)($row['disparar_sf'] ?? 1),
            'disparar_manychat'=>(int)($row['disparar_manychat'] ?? 1),
            'direct_webhook_url'=>$row['direct_webhook_url'] ?? '',
            'direct_webhook_method'=>$row['direct_webhook_method'] ?? 'POST',
            'direct_webhook_headers_json'=>$row['direct_webhook_headers_json'] ?? '',
            'direct_webhook_payload_format'=>$row['direct_webhook_payload_format'] ?? 'json',
            'direct_sf_tags_text'=>$row['direct_sf_tags_text'] ?? '',
            'direct_sf_flows_text'=>$row['direct_sf_flows_text'] ?? '',
            'direct_sf_fields_json'=>$row['direct_sf_fields_json'] ?? '',
            'direct_manychat_tags_text'=>$row['direct_manychat_tags_text'] ?? '',
            'direct_manychat_flows_text'=>$row['direct_manychat_flows_text'] ?? '',
            'direct_manychat_fields_json'=>$row['direct_manychat_fields_json'] ?? '',
        ]);
        echo json_encode(['ok'=>true,'id'=>$newId]); exit;
    }

    if ($acao === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare("UPDATE inbound_webhooks SET ativo = 1 - ativo WHERE id = :id")->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($acao === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $r = $pdo->prepare("SELECT * FROM inbound_webhooks WHERE id = :id"); $r->execute([':id'=>$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        echo $row ? json_encode(['ok'=>true,'data'=>$row]) : json_encode(['ok'=>false]); exit;
    }

    if ($acao === 'listar') {
        $rows = $pdo->query("SELECT id,nome,descricao,evento,lesson_id,codigo_turma,tag_extra,oferta_codigo,disparar_webhook,disparar_sf,disparar_manychat,token,ativo,total_recebidos,criado_em FROM inbound_webhooks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'data'=>$rows]); exit;
    }

    if ($acao === 'recebimentos') {
        $wid = (int)($_GET['webhook_id'] ?? 0);
        $st = $pdo->prepare("SELECT id,user_id,payload_raw,status,erro_msg,recebido_em,processado_em FROM inbound_webhook_recebimentos WHERE webhook_id = :w ORDER BY id DESC LIMIT 100");
        $st->execute([':w'=>$wid]);
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Ação desconhecida']); exit;
}

// Lessons disponíveis
$lessons = [];
try { $lessons = $pdo->query("SELECT id, titulo, ordem FROM lessons WHERE ativo = 1 ORDER BY ordem ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Turmas disponíveis (codigo)
$turmas = [];
try { $turmas = $pdo->query("SELECT codigo FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

$webhookBaseUrl = rtrim(BASE_URL, '/') . '/inbound_webhook.php?t=';
$firepayWebhookBaseUrl = preg_replace('~/public/?$~', '', rtrim(BASE_URL, '/')) . '/fp.php?t=';
$firepayMcqdcWebhookUrl = preg_replace('~/public/?$~', '', rtrim(BASE_URL, '/')) . '/firepay_mcqdc.php';
$domWebhookUrl = rtrim(BASE_URL, '/') . '/dom_webhook.php';
$view = (string)($_GET['view'] ?? 'generic');
if (!in_array($view, ['generic','dom'], true)) $view = 'generic';
$domTab = (string)($_GET['dom_tab'] ?? 'overview');
if (!in_array($domTab, ['overview','settings','logs'], true)) $domTab = 'overview';
$domEnabled = (string)get_setting('dom_pagamentos_enabled', '0') === '1';
$domEnvironment = (string)get_setting('dom_pagamentos_environment', 'production');
$domRequireSignature = (string)get_setting('dom_pagamentos_require_signature', '1') === '1';
$domHasToken = trim((string)get_setting('dom_pagamentos_api_token', '')) !== '';
$domNotes = (string)get_setting('dom_pagamentos_notes', '');

$currentMenu = 'inbound_webhooks';
$page_title  = 'Webhooks de Entrada';
require_once __DIR__ . '/_header.php';
?>
<style>
.iw-wrap { display: flex; gap: 24px; align-items: flex-start; }
.iw-list { flex: 1; min-width: 0; }
.iw-form-panel {
    width: 540px; flex-shrink: 0;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 12px; padding: 24px;
    position: sticky; top: 24px; max-height: calc(100vh - 48px); overflow-y: auto;
}
@media (max-width: 1100px) { .iw-wrap { flex-direction: column; } .iw-form-panel { width: 100%; position: static; max-height: none; } }

.iw-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 10px; padding: 16px 20px; margin-bottom: 12px;
}
.iw-card-top { display: flex; align-items: center; gap: 14px; }
.iw-card-info { flex: 1; min-width: 0; }
.iw-card-nome { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
.iw-card-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.iw-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
.iw-webhook-row {
    margin-top: 10px; padding: 8px 10px; background: #14142a;
    border: 1px solid var(--border); border-radius: 6px;
    display: flex; align-items: center; gap: 8px; font-size: 11px;
}
.iw-webhook-row code { flex: 1; overflow-x: auto; white-space: nowrap; color: #60a5fa; background: none; padding: 0; }
.iw-copy-btn { background: var(--accent,#6366f1); border: none; color: #fff; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
.ev-pill { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.ev-inscrito { background: #1a3520; color: #34d399; }
.ev-aula     { background: #1e3a5f; color: #60a5fa; }
.ev-trilha   { background: #3a2e10; color: #fbbf24; }
.ev-login    { background: #2a1a3a; color: #c084fc; }
.ev-cert     { background: #3a1a1a; color: #f87171; }
.ev-tag      { background: #3a3a4a; color: #aaa; }

.form-row { margin-bottom: 14px; }
.form-row label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 5px; font-weight: 600; }
.form-row input, .form-row select, .form-row textarea {
    width: 100%; box-sizing: border-box;
    background: var(--input-bg,#1e1e2e); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text); padding: 8px 12px; font-size: 14px;
}
.form-row textarea { min-height: 60px; resize: vertical; }
.map-row { display: flex; gap: 6px; margin-bottom: 4px; align-items: center; }
.map-row input { flex: 1; }
.iw-empty { text-align: center; color: var(--text-muted); padding: 48px 0; font-size: 15px; }

.iw-recv-modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: none; align-items: center; justify-content: center; z-index: 1000; }
.iw-recv-modal.visible { display: flex; }
.iw-recv-box {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 14px; padding: 24px; width: 800px; max-width: 95vw;
    max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;
}
.iw-recv-list { overflow-y: auto; flex: 1; }
.iw-recv-row { padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 12px; display: grid; grid-template-columns: 130px 90px 1fr; gap: 12px; align-items: start; }
.iw-recv-row pre { background: #0a0a1a; padding: 6px 10px; border-radius: 6px; overflow-x: auto; margin: 0; max-height: 100px; font-size: 11px; color: var(--text); }
.iw-st-processado { color: #4ade80; }
.iw-st-erro { color: #f87171; }
.iw-st-pendente { color: #fbbf24; }
.iw-st-ignorado { color: #94a3b8; }
.iw-integrations { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
.iw-integration-check { display:flex; align-items:center; gap:8px; padding:9px 10px; border:1px solid var(--border); border-radius:8px; background:rgba(255,255,255,.03); font-size:12px; cursor:pointer; }
.iw-integration-check input { width:auto; accent-color:var(--primary); }
.iw-int-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; border:1px solid var(--border); background:rgba(255,255,255,.05); color:var(--text-muted); }
.iw-int-badge.on.webhook { color:#7dd3fc; border-color:rgba(56,189,248,.3); background:rgba(56,189,248,.1); }
.iw-int-badge.on.sf { color:#c4b5fd; border-color:rgba(167,139,250,.3); background:rgba(167,139,250,.1); }
.iw-int-badge.on.manychat { color:#f9a8d4; border-color:rgba(236,72,153,.3); background:rgba(236,72,153,.1); }
.iw-direct-box { border:1px solid var(--border); border-radius:10px; padding:12px; margin-top:10px; background:rgba(255,255,255,.025); }
.iw-direct-title { font-size:12px; font-weight:800; margin-bottom:8px; display:flex; justify-content:space-between; gap:8px; align-items:center; }
.iw-direct-title code { color:#60a5fa; font-size:10px; background:rgba(96,165,250,.08); padding:2px 6px; border-radius:999px; }
.iw-direct-grid { display:grid; grid-template-columns:1fr 110px 110px; gap:8px; }
.iw-direct-box textarea { min-height:54px; font-size:12px; }
.iw-tabs{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);margin:12px 0 18px;padding-bottom:10px}
.iw-tabs a{padding:8px 12px;border-radius:8px;text-decoration:none;color:var(--text-muted);font-size:12px;font-weight:700}
.iw-tabs a.active,.iw-tabs a:hover{background:rgba(96,165,250,.12);color:#93c5fd}
.iw-dom-grid{display:grid;grid-template-columns:minmax(320px,560px) minmax(320px,1fr);gap:16px;align-items:start}
.iw-dom-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:18px}
.iw-dom-card h2{font-size:16px;margin:0 0 6px}.iw-dom-card p{font-size:12px;color:var(--text-muted);line-height:1.45}
.iw-dom-url{display:flex;gap:8px;align-items:center;background:#14142a;border:1px solid var(--border);border-radius:8px;padding:9px 10px}
.iw-dom-url code{flex:1;color:#60a5fa;overflow:auto;white-space:nowrap;background:transparent}
.iw-dom-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}
.iw-dom-kpi{border:1px solid var(--border);border-radius:8px;padding:10px;background:rgba(255,255,255,.03)}
.iw-dom-kpi small{display:block;color:var(--text-muted);font-size:10px;text-transform:uppercase}.iw-dom-kpi strong{font-size:20px}
.iw-dom-table{overflow:auto}.iw-dom-table table{width:100%;border-collapse:collapse}.iw-dom-table th,.iw-dom-table td{padding:8px 9px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:top}.iw-dom-table th{font-size:10px;color:var(--text-muted);text-transform:uppercase}
.iw-dom-pill{display:inline-flex;padding:2px 8px;border-radius:999px;background:#1f2937;font-size:10px}.iw-dom-pill.ok{background:#14532d;color:#86efac}.iw-dom-pill.warn{background:#3a2e10;color:#fbbf24}.iw-dom-pill.bad{background:#3a1a1a;color:#f87171}
.iw-dom-subtabs{display:flex;gap:6px;flex-wrap:wrap;margin:-6px 0 16px}
.iw-dom-subtabs a{padding:7px 10px;border:1px solid var(--border);border-radius:8px;color:var(--text-muted);text-decoration:none;font-size:12px;font-weight:700;background:rgba(255,255,255,.025)}
.iw-dom-subtabs a.active,.iw-dom-subtabs a:hover{color:#86efac;border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.1)}
.iw-dom-filters{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr)) auto;gap:10px;align-items:end;margin:12px 0 14px}
.iw-dom-filters .form-row{margin:0}.iw-dom-filters button{height:38px}
.iw-dom-wide{grid-column:1/-1}.iw-dom-bars{display:grid;gap:9px;margin-top:10px}
.iw-dom-bar{display:grid;grid-template-columns:120px 1fr 70px;gap:10px;align-items:center;font-size:12px}
.iw-dom-bar-track{height:12px;border-radius:999px;background:#111827;overflow:hidden;border:1px solid var(--border)}
.iw-dom-bar-fill{height:100%;border-radius:999px;background:#22c55e;min-width:2px}
.iw-dom-bar-fill.pending{background:#fbbf24}.iw-dom-bar-fill.bad{background:#f87171}.iw-dom-bar-fill.other{background:#60a5fa}
.iw-dom-log-card{border:1px solid var(--border);border-radius:10px;background:rgba(255,255,255,.025);padding:12px;margin-bottom:10px}
.iw-dom-log-top{display:grid;grid-template-columns:150px 150px 1fr auto;gap:10px;align-items:start}
.iw-dom-log-meta{font-size:11px;color:var(--text-muted);line-height:1.4}.iw-dom-log-title{font-weight:800;font-size:13px}
.iw-dom-log-card details{margin-top:9px}.iw-dom-log-card pre{max-height:260px;overflow:auto;background:#070d18;border:1px solid var(--border);border-radius:8px;padding:10px;font-size:11px;color:var(--text);white-space:pre-wrap}
@media(max-width:700px){.iw-integrations{grid-template-columns:1fr;}}
@media(max-width:900px){.iw-direct-grid{grid-template-columns:1fr;}}
@media(max-width:1000px){.iw-dom-grid{grid-template-columns:1fr}.iw-dom-kpis{grid-template-columns:1fr}}
@media(max-width:900px){.iw-dom-filters{grid-template-columns:1fr 1fr}.iw-dom-log-top{grid-template-columns:1fr}.iw-dom-bar{grid-template-columns:1fr}}
</style>

<div class="main-content">
  <div class="page-header">
    <div>
      <h1 class="page-title">Webhooks de Entrada</h1>
      <p class="page-subtitle">URLs que recebem dados de Hotmart, Kiwify, Eduzz e outras plataformas externas</p>
    </div>
    <?php if ($view === 'generic'): ?>
    <button class="btn btn-primary" onclick="iwNovo()">+ Novo webhook</button>
    <?php endif; ?>
  </div>

  <nav class="iw-tabs">
    <a href="inbound_webhooks.php?view=generic" class="<?= $view === 'generic' ? 'active' : '' ?>">Entradas gerais</a>
    <a href="inbound_webhooks.php?view=dom" class="<?= $view === 'dom' ? 'active' : '' ?>">DOM Pagamentos</a>
  </nav>

<?php if ($view === 'dom'): ?>
  <nav class="iw-dom-subtabs">
    <a href="inbound_webhooks.php?view=dom&dom_tab=overview" class="<?= $domTab === 'overview' ? 'active' : '' ?>">Visao geral</a>
    <a href="inbound_webhooks.php?view=dom&dom_tab=settings" class="<?= $domTab === 'settings' ? 'active' : '' ?>">Configuracoes</a>
    <a href="inbound_webhooks.php?view=dom&dom_tab=logs" class="<?= $domTab === 'logs' ? 'active' : '' ?>">Logs</a>
  </nav>

  <?php if ($domTab === 'overview'): ?>
    <div class="iw-dom-card">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
        <div><h2>Visao geral DOM</h2><p>Indicadores das vendas e eventos recebidos pela DOM Pagamentos.</p></div>
        <span class="iw-dom-pill <?= $domEnabled ? 'ok' : 'warn' ?>"><?= $domEnabled ? 'Integracao ativa' : 'Integracao pausada' ?></span>
      </div>
      <div class="iw-dom-filters">
        <div class="form-row"><label>Data inicial</label><input type="date" id="domOvStart" value="<?= date('Y-m-d', strtotime('-30 days')) ?>"></div>
        <div class="form-row"><label>Data final</label><input type="date" id="domOvEnd" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-row"><label>Status</label><select id="domOvStatus"><option value="">Todos</option><option value="APPROVED">Aprovadas</option><option value="PENDING">Pendentes</option><option value="REFUNDED">Reembolsadas</option><option value="CHARGEBACK">Chargeback</option><option value="CANCELED">Canceladas</option></select></div>
        <button class="btn btn-primary" type="button" onclick="domCarregarOverview()">Filtrar</button>
      </div>
      <div id="domOverview">Carregando...</div>
    </div>
  <?php elseif ($domTab === 'settings'): ?>
    <div class="iw-dom-grid">
      <div class="iw-dom-card">
        <h2>Configuracoes DOM</h2>
        <p>Configure o recebimento de pagamentos, assinatura JWT e URL do webhook.</p>
        <div class="iw-dom-kpis">
          <div class="iw-dom-kpi"><small>Status</small><strong><?= $domEnabled ? 'Ativo' : 'Pausado' ?></strong></div>
          <div class="iw-dom-kpi"><small>Token API</small><strong><?= $domHasToken ? 'OK' : 'Falta' ?></strong></div>
          <div class="iw-dom-kpi"><small>Assinatura</small><strong><?= $domRequireSignature ? 'Obrigatoria' : 'Opcional' ?></strong></div>
        </div>
        <div class="form-row"><label>URL para cadastrar na DOM</label><div class="iw-dom-url"><code id="domWebhookUrl"><?= htmlspecialchars($domWebhookUrl, ENT_QUOTES, 'UTF-8') ?></code><button class="iw-copy-btn" type="button" onclick="iwCopiar(document.getElementById('domWebhookUrl').textContent,this)">Copiar</button></div></div>
        <form id="domConfigForm" onsubmit="return domSalvar(event)">
          <div class="form-row"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="enabled" <?= $domEnabled ? 'checked' : '' ?> style="width:auto"><span>Integracao DOM ativa</span></label></div>
          <div class="form-row"><label>Ambiente</label><select name="environment"><option value="production" <?= $domEnvironment === 'production' ? 'selected' : '' ?>>Producao</option><option value="sandbox" <?= $domEnvironment === 'sandbox' ? 'selected' : '' ?>>Sandbox</option></select></div>
          <div class="form-row"><label>Token da API DOM</label><input type="password" name="api_token" placeholder="<?= $domHasToken ? 'Configurado - deixe vazio para manter' : 'Cole o token usado para validar o JWT' ?>"><div style="font-size:11px;color:var(--text-muted);margin-top:6px">A DOM usa este token para assinar o campo <code>signature</code> do webhook.</div></div>
          <div class="form-row"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="require_signature" <?= $domRequireSignature ? 'checked' : '' ?> style="width:auto"><span>Exigir assinatura valida</span></label></div>
          <div class="form-row"><label>Observacoes internas</label><textarea name="notes" placeholder="Ex.: conta DOM principal, produtos liberados, contato do suporte..."><?= htmlspecialchars($domNotes, ENT_QUOTES, 'UTF-8') ?></textarea></div>
          <button class="btn btn-primary" type="submit">Salvar DOM</button><span id="domSaveMsg" style="margin-left:8px;font-size:12px;color:#86efac"></span>
        </form>
      </div>
      <div class="iw-dom-card">
        <h2>Eventos recomendados</h2>
        <p>Na DOM, cadastre a URL acima e marque os eventos de pagamento.</p>
        <div class="iw-dom-bars">
          <?php foreach (['CHARGE-APPROVED','CHARGE-PENDING','CHARGE-REFUND','CHARGE-CHARGEBACK','CHARGE-NOT_AUTHORIZED','CHARGE-EXPIRE','CHARGE-REJECTED_ANTIFRAUD'] as $ev): ?>
            <div class="iw-dom-url"><code><?= htmlspecialchars($ev, ENT_QUOTES, 'UTF-8') ?></code></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="iw-dom-card">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
        <div><h2>Logs DOM</h2><p>Eventos recebidos com assinatura, status, processamento e payload completo.</p></div>
        <button class="btn btn-sm" type="button" onclick="domCarregarLogs()">Atualizar</button>
      </div>
      <div class="iw-dom-filters">
        <div class="form-row"><label>Data inicial</label><input type="date" id="domLogStart" value="<?= date('Y-m-d', strtotime('-30 days')) ?>"></div>
        <div class="form-row"><label>Data final</label><input type="date" id="domLogEnd" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-row"><label>Processo</label><select id="domLogProcess"><option value="">Todos</option><option value="success">Sucesso</option><option value="ignored">Ignorado</option><option value="error">Erro</option></select></div>
        <div class="form-row"><label>Evento</label><input type="text" id="domLogEvent" placeholder="CHARGE-APPROVED"></div>
        <button class="btn btn-primary" type="button" onclick="domCarregarLogs()">Filtrar</button>
      </div>
      <div id="domLogs">Carregando...</div>
    </div>
  <?php endif; ?>
  <?php if (false): ?>
  <div class="iw-dom-grid">
    <div class="iw-dom-card">
      <h2>DOM Pagamentos</h2>
      <p>Recebe eventos de transacao da DOM, valida a assinatura JWT com o token da API e grava as vendas em <code>payment_sales</code>. Eventos aprovados, reembolso e chargeback tambem alimentam os relatorios de vendas.</p>

      <div class="iw-dom-kpis">
        <div class="iw-dom-kpi"><small>Status</small><strong><?= $domEnabled ? 'Ativo' : 'Pausado' ?></strong></div>
        <div class="iw-dom-kpi"><small>Token API</small><strong><?= $domHasToken ? 'OK' : 'Falta' ?></strong></div>
        <div class="iw-dom-kpi"><small>Assinatura</small><strong><?= $domRequireSignature ? 'Obrigatoria' : 'Opcional' ?></strong></div>
      </div>

      <div class="form-row">
        <label>URL para cadastrar na DOM</label>
        <div class="iw-dom-url">
          <code id="domWebhookUrl"><?= htmlspecialchars($domWebhookUrl, ENT_QUOTES, 'UTF-8') ?></code>
          <button class="iw-copy-btn" type="button" onclick="iwCopiar(document.getElementById('domWebhookUrl').textContent,this)">Copiar</button>
        </div>
      </div>

      <form id="domConfigForm" onsubmit="return domSalvar(event)">
        <div class="form-row">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="enabled" <?= $domEnabled ? 'checked' : '' ?> style="width:auto">
            <span>Integração DOM ativa</span>
          </label>
        </div>
        <div class="form-row">
          <label>Ambiente</label>
          <select name="environment">
            <option value="production" <?= $domEnvironment === 'production' ? 'selected' : '' ?>>Produção</option>
            <option value="sandbox" <?= $domEnvironment === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
          </select>
        </div>
        <div class="form-row">
          <label>Token da API DOM</label>
          <input type="password" name="api_token" placeholder="<?= $domHasToken ? 'Configurado - deixe vazio para manter' : 'Cole o token usado para validar o JWT' ?>">
          <div style="font-size:11px;color:var(--text-muted);margin-top:6px">A DOM usa este token para assinar o campo <code>signature</code> do webhook.</div>
        </div>
        <div class="form-row">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="require_signature" <?= $domRequireSignature ? 'checked' : '' ?> style="width:auto">
            <span>Exigir assinatura válida</span>
          </label>
        </div>
        <div class="form-row">
          <label>Observações internas</label>
          <textarea name="notes" placeholder="Ex.: conta DOM principal, produtos liberados, contato do suporte..."><?= htmlspecialchars($domNotes, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <button class="btn btn-primary" type="submit">Salvar DOM</button>
        <span id="domSaveMsg" style="margin-left:8px;font-size:12px;color:#86efac"></span>
      </form>
    </div>

    <div class="iw-dom-card">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px">
        <div>
          <h2>Ultimos eventos DOM</h2>
          <p style="margin:0">Acompanhe assinatura, status e processamento das notificacoes recebidas.</p>
        </div>
        <button class="btn btn-sm" type="button" onclick="domCarregarLogs()">Atualizar</button>
      </div>
      <div class="iw-dom-table" id="domLogs">Carregando...</div>
    </div>
  </div>
  <?php endif; ?>
<?php else: ?>

  <div class="iw-wrap">
    <div class="iw-list">
      <div id="iwListCont"><div class="iw-empty">Carregando…</div></div>
    </div>

    <div class="iw-form-panel" id="iwFormPanel" style="display:none">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h3 style="margin:0;font-size:16px" id="iwFormTitle">Novo webhook</h3>
        <button onclick="iwFechar()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:20px;line-height:1">&times;</button>
      </div>

      <input type="hidden" id="iwId" value="0">

      <div class="form-row">
        <label>Nome *</label>
        <input type="text" id="iwNome" placeholder="Ex: Hotmart — Venda do Curso X">
      </div>

      <div class="form-row">
        <label>Descrição (opcional)</label>
        <textarea id="iwDescricao" placeholder="Ex: Recebe webhook da Hotmart quando uma venda é aprovada"></textarea>
      </div>

      <div class="form-row">
        <label>Evento que será disparado no sistema *</label>
        <select id="iwEvento" onchange="iwAtualizaCamposCondicionais()">
          <option value="INSCRITO">INSCRITO — cria aluno e libera acesso (principal — Hotmart/Kiwify)</option>
          <option value="FIREPAY">FIREPAY — recebe e registra pagamentos da Firepay</option>
          <option value="INSCRICAO_GRATUITA">INSCRICAO_GRATUITA — cria/atualiza aluno com acesso temporario da turma</option>
          <option value="INSCRICAO_VITALICIA">INSCRICAO_VITALICIA — cria/atualiza aluno com acesso vitalicio nao pago</option>
          <option value="PRIMEIRO_LOGIN">PRIMEIRO_LOGIN — marca como acessou a plataforma</option>
          <option value="VIU_AULA">VIU_AULA — marca aula como concluída</option>
          <option value="CONCLUIU_TRILHA">CONCLUIU_TRILHA — marca toda a trilha como concluída</option>
          <option value="CERT_EMITIDO">CERT_EMITIDO — dispara evento de certificado</option>
          <option value="REENVIO_CERTIFICADO">REENVIO_CERTIFICADO — dispara gatilho de reenvio do certificado</option>
          <option value="AGENDAR_RETORNO">AGENDAR_RETORNO — cria retorno agendado por payload</option>
          <option value="LIBERAR_ACESSO_VITALICIO">LIBERAR_ACESSO_VITALICIO — libera o curso após pagamento aprovado</option>
          <option value="TAG_CUSTOM">TAG_CUSTOM — apenas aplica tag e dispara evento custom</option>
        </select>
        <div id="iwFirepayInfo" style="display:none;font-size:11px;color:#fbbf24;margin-top:8px;background:#211b0d;padding:10px 12px;border-radius:6px;border:1px solid rgba(251,191,36,.3)">
          Esta entrada usa o formato fixo da Firepay. O mapeamento manual abaixo nao e utilizado. Nesta primeira etapa, vendas com <code>status: paid</code> entram nos relatorios e os demais status ficam preservados no log para mapeamento posterior. Ela relaciona um aluno existente por telefone ou email, mas nao cria matricula automaticamente.
        </div>
      </div>

      <div class="form-row">
        <label>Redirecionar para integracoes</label>
        <div class="iw-integrations">
          <label class="iw-integration-check">
            <input type="checkbox" id="iwDispararWebhook" checked>
            <span>Webhook</span>
          </label>
          <label class="iw-integration-check">
            <input type="checkbox" id="iwDispararSf" checked>
            <span>SuperFuncionario</span>
          </label>
          <label class="iw-integration-check">
            <input type="checkbox" id="iwDispararManychat" checked>
            <span>Manychat</span>
          </label>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
          O evento acima sera encaminhado somente para os canais marcados. As regras de cada canal continuam nas telas Webhooks, SuperFuncionario e Manychat.
        </div>

        <div class="iw-direct-box">
          <div class="iw-direct-title">
            <span>Gatilho direto Webhook</span>
            <code id="iwDirectWebhookEvent">INBOUND_WEBHOOK_novo</code>
          </div>
          <div class="iw-direct-grid">
            <input type="text" id="iwDirectWebhookUrl" placeholder="https://...">
            <select id="iwDirectWebhookMethod">
              <option value="POST">POST</option>
              <option value="GET">GET</option>
              <option value="PUT">PUT</option>
              <option value="PATCH">PATCH</option>
            </select>
            <select id="iwDirectWebhookFormat">
              <option value="json">JSON</option>
              <option value="form">Form</option>
            </select>
          </div>
          <textarea id="iwDirectWebhookHeaders" style="margin-top:8px" placeholder='Headers JSON opcionais. Ex.: {"Authorization":"Bearer TOKEN"}'></textarea>
        </div>

        <div class="iw-direct-box">
          <div class="iw-direct-title">
            <span>Gatilho direto SuperFuncionario</span>
            <code id="iwDirectSfEvent">INBOUND_SF_novo</code>
          </div>
          <textarea id="iwDirectSfTags" placeholder="Tags, uma por linha"></textarea>
          <textarea id="iwDirectSfFlows" style="margin-top:8px" placeholder="Flows IDs separados por virgula"></textarea>
          <textarea id="iwDirectSfFields" style="margin-top:8px" placeholder='Campos JSON. Ex.: [{"source":"user.email","dest":"EMAIL"}]'></textarea>
        </div>

        <div class="iw-direct-box">
          <div class="iw-direct-title">
            <span>Gatilho direto Manychat</span>
            <code id="iwDirectManychatEvent">INBOUND_MANYCHAT_novo</code>
          </div>
          <textarea id="iwDirectManychatTags" placeholder="Tags, uma por linha"></textarea>
          <textarea id="iwDirectManychatFlows" style="margin-top:8px" placeholder="flow_ns separados por linha, espaco ou virgula"></textarea>
          <textarea id="iwDirectManychatFields" style="margin-top:8px" placeholder='Campos JSON. Ex.: [{"source":"user.email","dest":"email_area"}]'></textarea>
        </div>
      </div>

      <div class="form-row" id="iwLessonWrap" style="display:none">
        <label>Aula a marcar como concluída *</label>
        <select id="iwLessonId">
          <option value="0">-- selecione --</option>
          <?php foreach ($lessons as $l): ?>
          <option value="<?= (int)$l['id'] ?>">Aula <?= (int)$l['ordem'] ?> — <?= htmlspecialchars((string)$l['titulo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row" id="iwTurmaWrap">
        <label>Turma a atribuir <span style="color:var(--text-muted);font-weight:400">(opcional)</span></label>
        <select id="iwCodigoTurma">
          <option value="">-- automática (turma com janela aberta na hora do webhook) --</option>
          <?php foreach ($turmas as $tc): ?>
          <option value="<?= htmlspecialchars((string)$tc) ?>"><?= htmlspecialchars((string)$tc) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
          Se nada for selecionado, o sistema usa a turma cuja <strong>janela de inscrição</strong> estiver aberta no momento do recebimento (mesma regra das inscrições orgânicas).
        </div>
      </div>

      <div class="form-row">
        <label>Tag extra a aplicar <span style="color:var(--text-muted);font-weight:400">(opcional)</span></label>
        <input type="text" id="iwTagExtra" placeholder="Ex: HOTMART_CURSO_X">
      </div>

      <div class="form-row">
        <label>Código(s) da oferta <span style="color:var(--text-muted);font-weight:400">(opcional — múltiplos separados por vírgula)</span></label>
        <input type="text" id="iwOfertaCodigo" placeholder="Ex: ZBF54VLP ou ZBF54VLP, OUTRA_OFF">
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px;background:#14142a;padding:8px 10px;border-radius:6px;border:1px solid var(--border)">
          <strong style="color:#fbbf24">Filtro de oferta:</strong> se preenchido, o sistema só processa o webhook quando o código vindo no campo <code>oferta</code> do mapeamento bater com algum dos valores listados aqui. <strong>Vazio = aceita todas as ofertas.</strong> Útil pra Hotmart quando um único webhook recebe várias ofertas mas você só quer liberar uma específica.
        </div>
      </div>

      <div class="form-row">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" id="iwCriarSeNaoExistir" checked>
          <span>Criar aluno automaticamente se não existir <span style="color:var(--text-muted);font-weight:400">(libera acesso instantâneo)</span></span>
        </label>
      </div>

      <div class="form-row">
        <label>Mapeamento do payload</label>
        <div style="display:flex;gap:6px;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;padding:0 4px">
          <span style="flex:1">Campo interno</span><span style="width:24px"></span>
          <span style="flex:1">Caminho no payload externo</span><span style="width:32px"></span>
        </div>
        <div id="iwMap"></div>
        <button type="button" onclick="iwAddMap()" style="background:none;border:1px dashed var(--border);color:var(--text-muted);border-radius:6px;padding:5px 10px;cursor:pointer;font-size:11px;margin-top:4px">+ Adicionar mapeamento</button>
        <div style="font-size:11px;color:var(--text-muted);margin-top:8px;background:#14142a;padding:8px 10px;border-radius:6px;border:1px solid var(--border)">
          <strong style="color:#60a5fa">Caminhos aninhados suportados.</strong> Para a Hotmart que envia <code>{"data":{"buyer":{"email":"..."}}}</code>, use <code>email ← data.buyer.email</code>.<br>
          O sistema procura aluno por email e telefone; se não achar e "Criar aluno" estiver marcado, cria com senha = telefone (só números).
        </div>
        <div style="font-size:11px;color:var(--text);margin-top:8px;background:#1a2a1a;padding:10px 12px;border-radius:6px;border:1px solid rgba(52,211,153,.3)">
          <strong style="color:#34d399">📌 Referência — Hotmart (event PURCHASE_APPROVED, v2.0.0):</strong>
          <table style="margin-top:6px;font-size:11px;width:100%;border-collapse:collapse">
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted);width:80px">nome</td><td><code>data.buyer.name</code> <span style="color:var(--text-muted)">(ou <code>data.buyer.first_name</code> só primeiro nome)</span></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">email</td><td><code>data.buyer.email</code></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">telefone</td><td><code>data.buyer.checkout_phone</code> <span style="color:var(--text-muted)">(número completo com DDD)</span></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">oferta</td><td><code>data.purchase.offer.code</code> <span style="color:var(--text-muted)">(ou <code>data.product.ucode</code> p/ produto)</span></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">transacao</td><td><code>data.purchase.transaction</code></td></tr>
            <tr><td style="padding:2px 8px 2px 0;color:var(--text-muted)">status_pagamento</td><td><code>data.purchase.status</code></td></tr>
          </table>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px">
        <button class="btn btn-primary" style="flex:1" onclick="iwSalvar()">Salvar</button>
        <button class="btn" style="flex:1" onclick="iwFechar()">Cancelar</button>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>

<div class="iw-recv-modal" id="iwRecvModal">
  <div class="iw-recv-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="margin:0" id="iwRecvTitle">Recebimentos</h3>
      <button onclick="iwRecvFechar()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:24px">&times;</button>
    </div>
    <div class="iw-recv-list" id="iwRecvList">Carregando…</div>
  </div>
</div>

<script>
const IW_VIEW = <?= json_encode($view) ?>;
const IW_DOM_TAB = <?= json_encode($domTab) ?>;
const IW_WEBHOOK_BASE = <?= json_encode($webhookBaseUrl) ?>;
const IW_FIREPAY_WEBHOOK_BASE = <?= json_encode($firepayWebhookBaseUrl) ?>;
const IW_FIREPAY_MCQDC_WEBHOOK_URL = <?= json_encode($firepayMcqdcWebhookUrl) ?>;
const EV_CLS = {
    'INSCRITO':'ev-inscrito','INSCRICAO_GRATUITA':'ev-inscrito','INSCRICAO_VITALICIA':'ev-inscrito','PRIMEIRO_LOGIN':'ev-login','VIU_AULA':'ev-aula',
    'CONCLUIU_TRILHA':'ev-trilha','CERT_EMITIDO':'ev-cert','REENVIO_CERTIFICADO':'ev-cert','AGENDAR_RETORNO':'ev-login','TAG_CUSTOM':'ev-tag'
};

document.addEventListener('DOMContentLoaded', () => {
    if (IW_VIEW === 'dom' && IW_DOM_TAB === 'overview') domCarregarOverview();
    else if (IW_VIEW === 'dom' && IW_DOM_TAB === 'logs') domCarregarLogs();
    else if (IW_VIEW !== 'dom') iwCarregar();
});

async function domSalvar(ev) {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    fd.append('acao', 'salvar_dom');
    const j = await (await fetch('inbound_webhooks.php', {method:'POST', body:fd})).json();
    const msg = document.getElementById('domSaveMsg');
    if (!j.ok) {
        msg.style.color = '#f87171';
        msg.textContent = 'Erro ao salvar';
        return false;
    }
    msg.style.color = '#86efac';
    msg.textContent = 'Salvo';
    setTimeout(() => location.href = 'inbound_webhooks.php?view=dom&dom_tab=settings', 500);
    return false;
}

async function domCarregarOverview() {
    const el = document.getElementById('domOverview');
    if (!el) return;
    el.textContent = 'Carregando...';
    const qs = new URLSearchParams({
        acao: 'dom_overview',
        start: document.getElementById('domOvStart')?.value || '',
        end: document.getElementById('domOvEnd')?.value || '',
        status: document.getElementById('domOvStatus')?.value || ''
    });
    const j = await (await fetch('inbound_webhooks.php?' + qs.toString())).json();
    if (!j.ok) { el.innerHTML = '<div class="iw-empty">Erro ao carregar dados DOM.</div>'; return; }
    const k = j.kpi || {};
    const byStatus = j.by_status || [];
    const maxStatus = Math.max(1, ...byStatus.map(x => parseInt(x.qty || 0)));
    const days = {};
    (j.daily || []).forEach(r => {
        if (!days[r.day]) days[r.day] = {day:r.day, qty:0, cents:0};
        days[r.day].qty += parseInt(r.qty || 0);
        days[r.day].cents += parseInt(r.cents || 0);
    });
    const daily = Object.values(days);
    const maxDay = Math.max(1, ...daily.map(x => x.qty));
    el.innerHTML = `
      <div class="iw-dom-kpis">
        <div class="iw-dom-kpi"><small>Eventos recebidos</small><strong>${j.events_total || 0}</strong></div>
        <div class="iw-dom-kpi"><small>Transacoes</small><strong>${k.total || 0}</strong></div>
        <div class="iw-dom-kpi"><small>Aprovadas</small><strong>${k.approved || 0}</strong></div>
        <div class="iw-dom-kpi"><small>Pendentes</small><strong>${k.pending || 0}</strong></div>
        <div class="iw-dom-kpi"><small>Com aluno cruzado</small><strong>${k.matched || 0}</strong></div>
        <div class="iw-dom-kpi"><small>Faturamento aprovado</small><strong>${moneyCents(k.revenue_cents || 0)}</strong></div>
      </div>
      <div class="iw-dom-grid">
        <div class="iw-dom-card">
          <h2>Status das transacoes</h2>
          <div class="iw-dom-bars">${byStatus.length ? byStatus.map(r => domBar(r.status || 'UNKNOWN', parseInt(r.qty || 0), maxStatus, moneyCents(r.cents || 0))).join('') : '<div class="iw-empty" style="padding:20px 0">Sem transacoes no periodo.</div>'}</div>
        </div>
        <div class="iw-dom-card">
          <h2>Volume por dia</h2>
          <div class="iw-dom-bars">${daily.length ? daily.map(r => domBar(formatDay(r.day), r.qty, maxDay, moneyCents(r.cents))).join('') : '<div class="iw-empty" style="padding:20px 0">Sem dados diarios.</div>'}</div>
        </div>
      </div>
      <div class="iw-dom-card iw-dom-wide" style="margin-top:16px">
        <h2>Ultimas transacoes</h2>
        <div class="iw-dom-table">${domRecentTable(j.recent || [])}</div>
      </div>`;
}

async function domCarregarLogs() {
    const el = document.getElementById('domLogs');
    if (!el) return;
    el.textContent = 'Carregando...';
    const qs = new URLSearchParams({
        acao: 'dom_logs',
        start: document.getElementById('domLogStart')?.value || '',
        end: document.getElementById('domLogEnd')?.value || '',
        process: document.getElementById('domLogProcess')?.value || '',
        event: document.getElementById('domLogEvent')?.value || ''
    });
    const j = await (await fetch('inbound_webhooks.php?' + qs.toString())).json();
    if (!j.ok || !j.data.length) {
        el.innerHTML = '<div class="iw-empty" style="padding:24px 0">Nenhum evento DOM recebido no filtro.</div>';
        return;
    }
    el.innerHTML = j.data.map(r => {
        const processCls = r.process_status === 'success' ? 'ok' : (r.process_status === 'error' ? 'bad' : 'warn');
        const payload = prettyJson(r.payload_json || '');
        return `<div class="iw-dom-log-card">
            <div class="iw-dom-log-top">
                <div class="iw-dom-log-meta">${fmtDate(r.received_at)}${r.processed_at?`<br>processado ${fmtDate(r.processed_at)}`:''}</div>
                <div><div class="iw-dom-log-title">${esc(r.event_name || '-')}</div><div class="iw-dom-log-meta">status: ${esc(r.provider_status || '-')}</div></div>
                <div><code>${esc(r.external_transaction_id || '-')}</code>${r.process_message?`<div style="font-size:11px;color:#f87171;margin-top:5px">${esc(r.process_message)}</div>`:''}</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                    <span class="iw-dom-pill ${parseInt(r.signature_valid||0)===1?'ok':'warn'}">${parseInt(r.signature_valid||0)===1?'assinatura OK':'nao validada'}</span>
                    <span class="iw-dom-pill ${processCls}">${esc(r.process_status || '-')}</span>
                </div>
            </div>
            <details><summary style="cursor:pointer;color:#93c5fd;font-size:12px">Ver payload</summary><pre>${esc(payload)}</pre></details>
        </div>`;
    }).join('');
}

function domBar(label, qty, max, detail) {
    const cls = String(label).includes('PENDING') ? 'pending' : (String(label).match(/REFUND|CHARGEBACK|CANCELED|error/i) ? 'bad' : (String(label).match(/APPROVED|Aprov/) ? '' : 'other'));
    const pct = Math.max(2, Math.round((qty / Math.max(1, max)) * 100));
    return `<div class="iw-dom-bar"><strong>${esc(label)}</strong><div class="iw-dom-bar-track"><div class="iw-dom-bar-fill ${cls}" style="width:${pct}%"></div></div><span>${qty} ${detail ? `<small style="color:var(--text-muted)">(${esc(detail)})</small>` : ''}</span></div>`;
}

function domRecentTable(rows) {
    if (!rows.length) return '<div class="iw-empty" style="padding:20px 0">Nenhuma transacao no periodo.</div>';
    return `<table><thead><tr><th>Data</th><th>Status</th><th>Comprador</th><th>Produto</th><th>Valor</th><th>Aluno</th></tr></thead><tbody>${rows.map(r => `<tr><td>${fmtDate(r.last_received_at)}</td><td><span class="iw-dom-pill ${r.normalized_status==='APPROVED'?'ok':(r.normalized_status==='PENDING'?'warn':'bad')}">${esc(r.normalized_status || '-')}</span></td><td>${esc(r.buyer_name || '-')}<div style="font-size:10px;color:var(--text-muted)">${esc(r.buyer_email || '')}</div></td><td>${esc(r.product_name || '-')}</td><td>${moneyCents(r.gross_amount_cents || 0)}</td><td>${r.matched_user_id ? 'uid ' + r.matched_user_id : '<span style="color:#fbbf24">sem match</span>'}</td></tr>`).join('')}</tbody></table>`;
}

function moneyCents(v) { return (Number(v || 0) / 100).toLocaleString('pt-BR', {style:'currency', currency:'BRL'}); }
function formatDay(v) { const p=String(v||'').split('-'); return p.length===3 ? `${p[2]}/${p[1]}` : String(v||''); }
function prettyJson(raw) { try { return JSON.stringify(JSON.parse(raw), null, 2); } catch(e) { return raw || ''; } }

async function iwCarregar() {
    const j = await (await fetch('inbound_webhooks.php?acao=listar')).json();
    const cont = document.getElementById('iwListCont');
    if (!j.ok || !j.data.length) {
        cont.innerHTML = '<div class="iw-empty">Nenhum webhook configurado.<br>Clique em <strong>+ Novo webhook</strong> para criar o primeiro.</div>';
        return;
    }
    cont.innerHTML = j.data.map(w => {
        const url = (w.evento === 'FIREPAY' && w.nome === 'FIREPAY - MCQDC')
            ? IW_FIREPAY_MCQDC_WEBHOOK_URL
            : ((w.evento === 'FIREPAY' ? IW_FIREPAY_WEBHOOK_BASE : IW_WEBHOOK_BASE) + w.token);
        const evCls = EV_CLS[w.evento] || 'ev-tag';
        return `<div class="iw-card">
            <div class="iw-card-top">
                <div class="iw-card-info">
                    <div class="iw-card-nome">${esc(w.nome)} <span style="font-size:11px;color:${w.ativo==1?'#4ade80':'#f87171'}">${w.ativo==1?'● ativo':'○ pausado'}</span></div>
                    <div class="iw-card-meta">
                        <span class="ev-pill ${evCls}">${w.evento}${w.lesson_id?(' #'+w.lesson_id):''}</span>
                        ${w.codigo_turma?`<span>turma: <strong>${esc(w.codigo_turma)}</strong></span>`:''}
                        ${w.tag_extra?`<span>tag: <strong>${esc(w.tag_extra)}</strong></span>`:''}
                        ${w.oferta_codigo?`<span style="color:#fbbf24">oferta: <strong>${esc(w.oferta_codigo)}</strong></span>`:''}
                        <span class="iw-int-badge ${parseInt(w.disparar_webhook||0)===1?'on webhook':''}">webhook</span>
                        <span class="iw-int-badge ${parseInt(w.disparar_sf||0)===1?'on sf':''}">sf</span>
                        <span class="iw-int-badge ${parseInt(w.disparar_manychat||0)===1?'on manychat':''}">manychat</span>
                        <span style="color:#60a5fa">eventos: INBOUND_*_${w.id}</span>
                        <span>📥 ${w.total_recebidos||0} recebimentos</span>
                    </div>
                </div>
                <div class="iw-card-actions">
                    <button class="btn btn-sm" onclick="iwVerRecebimentos(${w.id},'${esc(w.nome).replace(/'/g,"\\'")}')">📥</button>
                    <button class="btn btn-sm" onclick="iwToggle(${w.id})">${w.ativo==1?'⏸':'▶'}</button>
                    <button class="btn btn-sm" onclick="iwEditar(${w.id})">✏️</button>
                    <button class="btn btn-sm" onclick="iwClonar(${w.id})">🗐</button>
                    <button class="btn btn-sm btn-danger" onclick="iwDeletar(${w.id})">🗑</button>
                </div>
            </div>
            <div class="iw-webhook-row">
                <span style="color:var(--text-muted);font-weight:600">URL:</span>
                <code>${esc(url)}</code>
                <button class="iw-copy-btn" onclick="iwCopiar('${esc(url).replace(/'/g,"\\'")}', this)">Copiar</button>
            </div>
        </div>`;
    }).join('');
}

function iwNovo() {
    document.getElementById('iwId').value = 0;
    document.getElementById('iwNome').value = '';
    document.getElementById('iwDescricao').value = '';
    document.getElementById('iwEvento').value = 'INSCRITO';
    document.getElementById('iwDispararWebhook').checked = true;
    document.getElementById('iwDispararSf').checked = true;
    document.getElementById('iwDispararManychat').checked = true;
    document.getElementById('iwDirectWebhookUrl').value = '';
    document.getElementById('iwDirectWebhookMethod').value = 'POST';
    document.getElementById('iwDirectWebhookFormat').value = 'json';
    document.getElementById('iwDirectWebhookHeaders').value = '';
    document.getElementById('iwDirectSfTags').value = '';
    document.getElementById('iwDirectSfFlows').value = '';
    document.getElementById('iwDirectSfFields').value = '';
    document.getElementById('iwDirectManychatTags').value = '';
    document.getElementById('iwDirectManychatFlows').value = '';
    document.getElementById('iwDirectManychatFields').value = '';
    iwAtualizaEventosDiretos(0);
    document.getElementById('iwLessonId').value = 0;
    document.getElementById('iwCodigoTurma').value = '';
    document.getElementById('iwTagExtra').value = '';
    document.getElementById('iwOfertaCodigo').value = '';
    document.getElementById('iwCriarSeNaoExistir').checked = true;
    document.getElementById('iwMap').innerHTML = '';
    iwAddMap('nome','nome'); iwAddMap('email','email'); iwAddMap('telefone','telefone'); iwAddMap('oferta','oferta');
    iwAddMap('utm_source','utm_source'); iwAddMap('utm_medium','utm_medium'); iwAddMap('utm_campaign','utm_campaign'); iwAddMap('utm_term','utm_term'); iwAddMap('utm_content','utm_content');
    iwAddMap('transacao','data.purchase.transaction'); iwAddMap('status_pagamento','data.purchase.status');
    iwAddMap('retorno_data','retorno_data'); iwAddMap('retorno_tipo','retorno_tipo'); iwAddMap('retorno_assunto','retorno_assunto'); iwAddMap('retorno_mensagem','retorno_mensagem');
    document.getElementById('iwFormTitle').textContent = 'Novo webhook';
    document.getElementById('iwFormPanel').style.display = '';
    iwAtualizaCamposCondicionais();
}

async function iwEditar(id) {
    const j = await (await fetch('inbound_webhooks.php?acao=get&id=' + id)).json();
    if (!j.ok) return alert('Erro');
    const d = j.data;
    document.getElementById('iwId').value = d.id;
    document.getElementById('iwNome').value = d.nome || '';
    document.getElementById('iwDescricao').value = d.descricao || '';
    document.getElementById('iwEvento').value = d.evento;
    document.getElementById('iwDispararWebhook').checked = parseInt(d.disparar_webhook ?? 1) === 1;
    document.getElementById('iwDispararSf').checked = parseInt(d.disparar_sf ?? 1) === 1;
    document.getElementById('iwDispararManychat').checked = parseInt(d.disparar_manychat ?? 1) === 1;
    document.getElementById('iwDirectWebhookUrl').value = d.direct_webhook_url || '';
    document.getElementById('iwDirectWebhookMethod').value = d.direct_webhook_method || 'POST';
    document.getElementById('iwDirectWebhookFormat').value = d.direct_webhook_payload_format || 'json';
    document.getElementById('iwDirectWebhookHeaders').value = d.direct_webhook_headers_json || '';
    document.getElementById('iwDirectSfTags').value = d.direct_sf_tags_text || '';
    document.getElementById('iwDirectSfFlows').value = d.direct_sf_flows_text || '';
    document.getElementById('iwDirectSfFields').value = d.direct_sf_fields_json || '';
    document.getElementById('iwDirectManychatTags').value = d.direct_manychat_tags_text || '';
    document.getElementById('iwDirectManychatFlows').value = d.direct_manychat_flows_text || '';
    document.getElementById('iwDirectManychatFields').value = d.direct_manychat_fields_json || '';
    iwAtualizaEventosDiretos(parseInt(d.id || 0));
    document.getElementById('iwLessonId').value = d.lesson_id || 0;
    document.getElementById('iwCodigoTurma').value = d.codigo_turma || '';
    document.getElementById('iwTagExtra').value = d.tag_extra || '';
    document.getElementById('iwOfertaCodigo').value = d.oferta_codigo || '';
    document.getElementById('iwCriarSeNaoExistir').checked = parseInt(d.criar_se_nao_existir||0) === 1;
    document.getElementById('iwMap').innerHTML = '';
    const map = JSON.parse(d.payload_map_json || '{}');
    Object.entries(map).forEach(([k,v]) => iwAddMap(k,v));
    if (!Object.keys(map).length) {
        iwAddMap('nome','nome'); iwAddMap('email','email'); iwAddMap('telefone','telefone'); iwAddMap('oferta','oferta');
        iwAddMap('utm_source','utm_source'); iwAddMap('utm_medium','utm_medium'); iwAddMap('utm_campaign','utm_campaign'); iwAddMap('utm_term','utm_term'); iwAddMap('utm_content','utm_content');
        iwAddMap('transacao','data.purchase.transaction'); iwAddMap('status_pagamento','data.purchase.status');
        iwAddMap('retorno_data','retorno_data'); iwAddMap('retorno_tipo','retorno_tipo'); iwAddMap('retorno_assunto','retorno_assunto'); iwAddMap('retorno_mensagem','retorno_mensagem');
    }
    document.getElementById('iwFormTitle').textContent = 'Editar: ' + d.nome;
    document.getElementById('iwFormPanel').style.display = '';
    iwAtualizaCamposCondicionais();
}

function iwFechar() { document.getElementById('iwFormPanel').style.display = 'none'; }

function iwAtualizaCamposCondicionais() {
    const ev = document.getElementById('iwEvento').value;
    document.getElementById('iwLessonWrap').style.display = (ev === 'VIU_AULA') ? '' : 'none';
    document.getElementById('iwTurmaWrap').style.display  = (['INSCRITO','INSCRICAO_GRATUITA','INSCRICAO_VITALICIA'].includes(ev)) ? '' : 'none';
    document.getElementById('iwFirepayInfo').style.display = (ev === 'FIREPAY') ? '' : 'none';
}

function iwAtualizaEventosDiretos(id) {
    const suffix = parseInt(id || 0) > 0 ? String(id) : 'novo';
    document.getElementById('iwDirectWebhookEvent').textContent = 'INBOUND_WEBHOOK_' + suffix;
    document.getElementById('iwDirectSfEvent').textContent = 'INBOUND_SF_' + suffix;
    document.getElementById('iwDirectManychatEvent').textContent = 'INBOUND_MANYCHAT_' + suffix;
}

function iwAddMap(from, to) {
    const cont = document.getElementById('iwMap');
    const div = document.createElement('div');
    div.className = 'map-row';
    div.innerHTML = `
        <input type="text" value="${esc(from||'')}" placeholder="nome|email|telefone" class="iw-map-from">
        <span style="color:var(--text-muted);font-size:14px">←</span>
        <input type="text" value="${esc(to||'')}" placeholder="campo.do.payload" class="iw-map-to">
        <button type="button" onclick="this.parentNode.remove()" style="background:none;border:1px solid #553;color:#f87171;border-radius:4px;padding:3px 8px;cursor:pointer">×</button>`;
    cont.appendChild(div);
}

function iwColetarMap() {
    const map = {};
    document.querySelectorAll('#iwMap .map-row').forEach(row => {
        const f = row.querySelector('.iw-map-from').value.trim();
        const t = row.querySelector('.iw-map-to').value.trim();
        if (f && t) map[f] = t;
    });
    return map;
}

async function iwSalvar() {
    const nome = document.getElementById('iwNome').value.trim();
    if (!nome) return alert('Nome obrigatório');
    const fd = new FormData();
    fd.append('acao','salvar');
    fd.append('id', document.getElementById('iwId').value);
    fd.append('nome', nome);
    fd.append('descricao', document.getElementById('iwDescricao').value);
    fd.append('evento', document.getElementById('iwEvento').value);
    if (document.getElementById('iwDispararWebhook').checked) fd.append('disparar_webhook','1');
    if (document.getElementById('iwDispararSf').checked) fd.append('disparar_sf','1');
    if (document.getElementById('iwDispararManychat').checked) fd.append('disparar_manychat','1');
    fd.append('direct_webhook_url', document.getElementById('iwDirectWebhookUrl').value);
    fd.append('direct_webhook_method', document.getElementById('iwDirectWebhookMethod').value);
    fd.append('direct_webhook_payload_format', document.getElementById('iwDirectWebhookFormat').value);
    fd.append('direct_webhook_headers_json', document.getElementById('iwDirectWebhookHeaders').value);
    fd.append('direct_sf_tags_text', document.getElementById('iwDirectSfTags').value);
    fd.append('direct_sf_flows_text', document.getElementById('iwDirectSfFlows').value);
    fd.append('direct_sf_fields_json', document.getElementById('iwDirectSfFields').value);
    fd.append('direct_manychat_tags_text', document.getElementById('iwDirectManychatTags').value);
    fd.append('direct_manychat_flows_text', document.getElementById('iwDirectManychatFlows').value);
    fd.append('direct_manychat_fields_json', document.getElementById('iwDirectManychatFields').value);
    fd.append('lesson_id', document.getElementById('iwLessonId').value);
    fd.append('codigo_turma', document.getElementById('iwCodigoTurma').value);
    fd.append('tag_extra', document.getElementById('iwTagExtra').value);
    fd.append('oferta_codigo', document.getElementById('iwOfertaCodigo').value);
    if (document.getElementById('iwCriarSeNaoExistir').checked) fd.append('criar_se_nao_existir','1');
    fd.append('payload_map_json', JSON.stringify(iwColetarMap()));
    const j = await (await fetch('inbound_webhooks.php',{method:'POST',body:fd})).json();
    if (!j.ok) return alert('Erro: ' + (j.msg||''));
    iwFechar(); iwCarregar();
}

async function iwDeletar(id) {
    if (!confirm('Deletar este webhook e seus recebimentos?')) return;
    const fd = new FormData(); fd.append('acao','deletar'); fd.append('id',id);
    await fetch('inbound_webhooks.php',{method:'POST',body:fd}); iwCarregar();
}

async function iwClonar(id) {
    const fd = new FormData(); fd.append('acao','clonar'); fd.append('id',id);
    const j = await (await fetch('inbound_webhooks.php',{method:'POST',body:fd})).json();
    if (j.ok) { iwCarregar(); iwEditar(j.id); }
}

async function iwToggle(id) {
    const fd = new FormData(); fd.append('acao','toggle'); fd.append('id',id);
    await fetch('inbound_webhooks.php',{method:'POST',body:fd}); iwCarregar();
}

function iwCopiar(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        const old = btn.textContent; btn.textContent = '✓';
        setTimeout(()=>btn.textContent=old, 1200);
    });
}

async function iwVerRecebimentos(wid, nome) {
    document.getElementById('iwRecvTitle').textContent = 'Recebimentos — ' + nome;
    document.getElementById('iwRecvList').innerHTML = 'Carregando...';
    document.getElementById('iwRecvModal').classList.add('visible');
    const j = await (await fetch('inbound_webhooks.php?acao=recebimentos&webhook_id=' + wid)).json();
    if (!j.ok || !j.data.length) {
        document.getElementById('iwRecvList').innerHTML = '<div style="padding:20px;color:var(--text-muted);text-align:center">Nenhum recebimento ainda.</div>';
        return;
    }
    document.getElementById('iwRecvList').innerHTML = j.data.map(r => {
        let payload = '';
        try { payload = JSON.stringify(JSON.parse(r.payload_raw), null, 2); } catch(e) { payload = r.payload_raw || ''; }
        return `<div class="iw-recv-row">
            <div>
                <div style="font-size:11px">${fmtDate(r.recebido_em)}</div>
                ${r.processado_em?`<div style="font-size:10px;color:var(--text-muted)">${fmtDate(r.processado_em)}</div>`:''}
            </div>
            <div>
                <span class="iw-st-${r.status}">${r.status}</span>
                ${r.user_id?`<div style="font-size:10px;color:var(--text-muted)">uid: ${r.user_id}</div>`:''}
                ${r.erro_msg?`<div style="font-size:10px;color:#f87171">${esc(r.erro_msg).substring(0,80)}</div>`:''}
            </div>
            <pre>${esc(payload)}</pre>
        </div>`;
    }).join('');
}

function iwRecvFechar() { document.getElementById('iwRecvModal').classList.remove('visible'); }

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDate(dt) { if (!dt) return ''; return new Date(dt.replace(' ','T')).toLocaleString('pt-BR'); }
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
