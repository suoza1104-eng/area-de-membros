<?php
declare(strict_types=1);

require_once __DIR__ . '/hotmart_sf_shadow.php';

function hub_ensure_schema(PDO $pdo): void
{
    static $ready=false;
    if($ready)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_sources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL, name VARCHAR(150) NOT NULL,
        provider VARCHAR(80) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, auth_config_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hub_source_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_destinations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL, name VARCHAR(150) NOT NULL,
        adapter VARCHAR(40) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, config_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hub_destination_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_routes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id INT UNSIGNED NOT NULL, destination_id INT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, mode ENUM('shadow','active') NOT NULL DEFAULT 'shadow',
        event_filter_json LONGTEXT NULL, mapping_json LONGTEXT NULL, config_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hub_route (source_id,destination_id), KEY idx_hub_route_active (is_active,mode)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_id INT UNSIGNED NOT NULL, external_event_id VARCHAR(120) NOT NULL,
        event_name VARCHAR(120) NOT NULL, transaction_code VARCHAR(100) NULL, contact_email VARCHAR(255) NULL,
        contact_phone VARCHAR(40) NULL, raw_payload_json LONGTEXT NOT NULL, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hub_source_event (source_id,external_event_id), KEY idx_hub_event_name (event_name), KEY idx_hub_event_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_deliveries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id BIGINT UNSIGNED NOT NULL, route_id INT UNSIGNED NOT NULL,
        destination_id INT UNSIGNED NOT NULL, status ENUM('shadow','pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'shadow',
        prepared_payload_json LONGTEXT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, http_status INT NULL, last_error TEXT NULL,
        next_attempt_at DATETIME NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hub_delivery (event_id,route_id), KEY idx_hub_delivery_status (status,next_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    hub_seed_defaults($pdo);
    $ready=true;
}

function hub_seed_defaults(PDO $pdo): void
{
    $pdo->exec("INSERT IGNORE INTO integration_sources (slug,name,provider,is_active) VALUES ('hotmart','Hotmart','hotmart',1)");
    $pdo->exec("INSERT IGNORE INTO integration_destinations (slug,name,adapter,is_active) VALUES
        ('superfuncionario','SuperFuncionario','superfuncionario',1),('manychat','ManyChat','manychat',1),('webhook','Webhook personalizado','webhook',1)");
    $sourceId=(int)$pdo->query("SELECT id FROM integration_sources WHERE slug='hotmart'")->fetchColumn();
    foreach (['superfuncionario'=>'Hotmart → SuperFuncionario','manychat'=>'Hotmart → ManyChat','webhook'=>'Hotmart → Webhook'] as $slug=>$name) {
        $st=$pdo->prepare("SELECT id FROM integration_destinations WHERE slug=:slug");$st->execute(['slug'=>$slug]);$destinationId=(int)$st->fetchColumn();
        $config=[];$active=0;
        if($slug==='superfuncionario'){
            $sf=hotmart_sf_shadow_config();
            $config=['fixed_tag'=>$sf['fixed_tag'],'flow_id'=>$sf['flow_id'],'event_tag_prefix'=>$sf['event_tag_prefix'],'order_bump_prefix'=>$sf['order_bump_prefix'],'fields'=>$sf['fields']];
            $active=(int)$sf['capture_enabled'];
        } elseif($slug==='manychat') {
            $config=['tags'=>['RV_ENTRADA_WEBHOOK','RV_{{event}}'],'flows'=>[],'fields'=>[]];
        } else {
            $config=['url'=>'','method'=>'POST','headers'=>[],'payload_template'=>['event'=>'{{event}}','data'=>'{{data}}']];
        }
        $stmt=$pdo->prepare("INSERT IGNORE INTO integration_routes (source_id,destination_id,name,is_active,mode,config_json) VALUES (:source,:destination,:name,:active,'shadow',:config)");
        $stmt->execute(['source'=>$sourceId,'destination'=>$destinationId,'name'=>$name,'active'=>$active,'config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
}

function hub_routes(PDO $pdo): array
{
    hub_ensure_schema($pdo);
    return $pdo->query("SELECT r.*,s.slug source_slug,d.slug destination_slug,d.name destination_name,d.adapter
        FROM integration_routes r JOIN integration_sources s ON s.id=r.source_id JOIN integration_destinations d ON d.id=r.destination_id
        ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hub_replace_templates($value, array $payload)
{
    if (is_array($value)) { foreach($value as $k=>$v)$value[$k]=hub_replace_templates($v,$payload); return $value; }
    if (!is_string($value)) return $value;
    if ($value === '{{data}}') return $payload['data'] ?? [];
    return preg_replace_callback('/\{\{([^}]+)\}\}/', static function(array $m) use($payload): string {
        $v=hotmart_sf_shadow_pick($payload,trim($m[1]));
        return $v===null?'':hotmart_sf_shadow_transform($v,'trim');
    },$value);
}

function hub_build_delivery(array $route, array $payload): array
{
    $config=json_decode((string)($route['config_json']??''),true)?:[];
    $adapter=(string)$route['adapter'];
    if($adapter==='superfuncionario'){
        $sf=['capture_enabled'=>1,'fixed_tag'=>(string)($config['fixed_tag']??''),'flow_id'=>(string)($config['flow_id']??''),
            'event_tag_prefix'=>(string)($config['event_tag_prefix']??'RV_'),'order_bump_prefix'=>(string)($config['order_bump_prefix']??'RV_ORDER_BUMP_'),
            'fields'=>is_array($config['fields']??null)?$config['fields']:[]];
        return hotmart_sf_shadow_build_payload($payload,$sf);
    }
    $contact=['email'=>hotmart_sf_shadow_pick($payload,'data.buyer.email|data.subscriber.email'),
        'phone'=>hotmart_sf_shadow_phone($payload),'name'=>hotmart_sf_shadow_pick($payload,'data.buyer.name|data.subscriber.name')];
    if($adapter==='manychat') return ['contact'=>$contact,'tags'=>hub_replace_templates((array)($config['tags']??[]),$payload),
        'flows'=>(array)($config['flows']??[]),'fields'=>hub_replace_templates((array)($config['fields']??[]),$payload)];
    return ['url'=>(string)($config['url']??''),'method'=>strtoupper((string)($config['method']??'POST')),
        'headers'=>(array)($config['headers']??[]),'body'=>hub_replace_templates($config['payload_template']??$payload,$payload)];
}

function hub_ingest_hotmart(PDO $pdo, array $payload): array
{
    hub_ensure_schema($pdo);
    $sourceId=(int)$pdo->query("SELECT id FROM integration_sources WHERE slug='hotmart' LIMIT 1")->fetchColumn();
    $externalId=trim((string)($payload['id']??''));
    if($externalId==='')$externalId=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $event=mb_strtoupper(trim((string)($payload['event']??'UNKNOWN')),'UTF-8');
    $tx=trim((string)(hotmart_sf_shadow_pick($payload,'data.purchase.transaction')??''));
    $email=mb_strtolower(trim((string)(hotmart_sf_shadow_pick($payload,'data.buyer.email|data.subscriber.email')??'')),'UTF-8');
    $phone=hotmart_sf_shadow_phone($payload);
    $stmt=$pdo->prepare("INSERT INTO integration_events (source_id,external_event_id,event_name,transaction_code,contact_email,contact_phone,raw_payload_json,received_at,updated_at)
        VALUES (:source,:external,:event,:tx,:email,:phone,:raw,NOW(),NOW()) ON DUPLICATE KEY UPDATE event_name=VALUES(event_name),transaction_code=VALUES(transaction_code),contact_email=VALUES(contact_email),contact_phone=VALUES(contact_phone),raw_payload_json=VALUES(raw_payload_json),updated_at=NOW()");
    $stmt->execute(['source'=>$sourceId,'external'=>$externalId,'event'=>$event,'tx'=>$tx?:null,'email'=>$email?:null,'phone'=>$phone?:null,'raw'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $find=$pdo->prepare("SELECT id FROM integration_events WHERE source_id=:source AND external_event_id=:external");$find->execute(['source'=>$sourceId,'external'=>$externalId]);$eventId=(int)$find->fetchColumn();
    $routes=$pdo->prepare("SELECT r.*,d.adapter FROM integration_routes r JOIN integration_destinations d ON d.id=r.destination_id WHERE r.source_id=:source AND r.is_active=1");$routes->execute(['source'=>$sourceId]);
    $prepared=0;
    foreach($routes->fetchAll(PDO::FETCH_ASSOC) as $route){
        $body=hub_build_delivery($route,$payload);
        $delivery=$pdo->prepare("INSERT INTO integration_deliveries (event_id,route_id,destination_id,status,prepared_payload_json,created_at,updated_at) VALUES (:event,:route,:destination,'shadow',:payload,NOW(),NOW()) ON DUPLICATE KEY UPDATE prepared_payload_json=VALUES(prepared_payload_json),updated_at=NOW()");
        $delivery->execute(['event'=>$eventId,'route'=>(int)$route['id'],'destination'=>(int)$route['destination_id'],'payload'=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$prepared++;
    }
    return ['event_id'=>$eventId,'deliveries'=>$prepared,'dispatched'=>false];
}
