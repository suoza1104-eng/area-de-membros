<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';
require_once __DIR__ . '/hotmart_sf_shadow.php';

function hub_ensure_schema(PDO $pdo): void
{
    static $ready=false;
    if($ready)return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_sources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80) NOT NULL, name VARCHAR(150) NOT NULL,
        provider VARCHAR(80) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, webhook_key VARCHAR(64) NULL,
        payload_format VARCHAR(20) NOT NULL DEFAULT 'json', mapping_json LONGTEXT NULL, normalize_phone TINYINT(1) NOT NULL DEFAULT 1, auth_config_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hub_source_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach([
        "ALTER TABLE integration_sources ADD COLUMN webhook_key VARCHAR(64) NULL AFTER is_active",
        "ALTER TABLE integration_sources ADD COLUMN payload_format VARCHAR(20) NOT NULL DEFAULT 'json' AFTER webhook_key",
        "ALTER TABLE integration_sources ADD COLUMN mapping_json LONGTEXT NULL AFTER payload_format",
        "ALTER TABLE integration_sources ADD COLUMN normalize_phone TINYINT(1) NOT NULL DEFAULT 1 AFTER mapping_json",
        "ALTER TABLE integration_sources ADD UNIQUE KEY uq_hub_source_key (webhook_key)"
    ] as $migration){try{$pdo->exec($migration);}catch(Throwable $e){}}
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
    $hotmartMap=json_encode(['name'=>'data.buyer.name|data.subscriber.name','email'=>'data.buyer.email|data.subscriber.email','phone'=>'data.buyer.checkout_phone|data.buyer.phone','event'=>'event','transaction'=>'data.purchase.transaction'],JSON_UNESCAPED_SLASHES);
    $stmt=$pdo->prepare("INSERT IGNORE INTO integration_sources (slug,name,provider,is_active,webhook_key,payload_format,mapping_json,normalize_phone) VALUES ('hotmart','Hotmart','hotmart',1,:key,'json',:mapping,1)");
    $stmt->execute(['key'=>bin2hex(random_bytes(16)),'mapping'=>$hotmartMap]);
    $stmt=$pdo->prepare("UPDATE integration_sources SET mapping_json=:mapping,payload_format='json',normalize_phone=1,webhook_key=COALESCE(NULLIF(webhook_key,''),:key) WHERE slug='hotmart' AND (mapping_json IS NULL OR TRIM(mapping_json)='' OR TRIM(mapping_json)='{}')");
    $stmt->execute(['mapping'=>$hotmartMap,'key'=>bin2hex(random_bytes(16))]);
    $pdo->exec("INSERT IGNORE INTO integration_destinations (slug,name,adapter,is_active) VALUES
        ('superfuncionario','SuperFuncionario','superfuncionario',1),('manychat','ManyChat','manychat',1),('webhook','Webhook personalizado','webhook',1)");
    $sourceId=(int)$pdo->query("SELECT id FROM integration_sources WHERE slug='hotmart'")->fetchColumn();
    hub_ensure_routes_for_source($pdo,$sourceId,'hotmart');
    $sfRoute=$pdo->prepare("SELECT r.id,r.config_json FROM integration_routes r JOIN integration_destinations d ON d.id=r.destination_id WHERE r.source_id=:source AND d.slug='superfuncionario' LIMIT 1");
    $sfRoute->execute(['source'=>$sourceId]);$row=$sfRoute->fetch(PDO::FETCH_ASSOC)?:[];$current=json_decode((string)($row['config_json']??''),true);
    if((int)($row['id']??0)>0&&(!is_array($current)||empty($current['fields']))){$config=hub_default_route_config('superfuncionario','hotmart');$update=$pdo->prepare("UPDATE integration_routes SET config_json=:config,is_active=1,mode='shadow' WHERE id=:id");$update->execute(['config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>(int)$row['id']]);}
}

function hub_default_route_config(string $slug, string $provider='generic'): array
{
    if($slug==='superfuncionario'){
        if($provider==='hotmart'){
            $sf=hotmart_sf_shadow_config();
            return ['fixed_tag'=>$sf['fixed_tag'],'flow_id'=>$sf['flow_id'],'event_tag_prefix'=>$sf['event_tag_prefix'],'order_bump_prefix'=>$sf['order_bump_prefix'],'fields'=>$sf['fields']];
        }
        return ['fixed_tag'=>'','flow_id'=>'','event_tag_prefix'=>'','order_bump_prefix'=>'','fields'=>[]];
    }
    if($slug==='manychat')return ['tags'=>[],'flows'=>[],'fields'=>[]];
    return ['url'=>'','method'=>'POST','headers'=>[],'payload_template'=>['event'=>'{{event}}','data'=>'{{data}}']];
}

function hub_ensure_routes_for_source(PDO $pdo,int $sourceId,string $provider='generic'): void
{
    foreach (['superfuncionario'=>'Hotmart → SuperFuncionario','manychat'=>'Hotmart → ManyChat','webhook'=>'Hotmart → Webhook'] as $slug=>$name) {
        $st=$pdo->prepare("SELECT id FROM integration_destinations WHERE slug=:slug");$st->execute(['slug'=>$slug]);$destinationId=(int)$st->fetchColumn();
        $config=hub_default_route_config($slug,$provider);$active=($provider==='hotmart'&&$slug==='superfuncionario')?(int)hotmart_sf_shadow_config()['capture_enabled']:0;
        $sourceName=(string)($pdo->query("SELECT name FROM integration_sources WHERE id=".(int)$sourceId)->fetchColumn()?:'Fonte');
        $name=$sourceName.' → '.($slug==='superfuncionario'?'SuperFuncionario':($slug==='manychat'?'ManyChat':'Webhook'));
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

function hub_source_mapping(array $source): array
{
    $map=json_decode((string)($source['mapping_json']??''),true);
    return is_array($map)?$map:[];
}

function hub_source_value(array $source,array $payload,string $key)
{
    $map=hub_source_mapping($source);
    $path=trim((string)($map[$key]??$key));
    return $path===''?null:hotmart_sf_shadow_pick($payload,$path);
}

function hub_source_contact(array $source,array $payload): array
{
    $phoneRaw=(string)(hub_source_value($source,$payload,'phone')??'');
    $phone=preg_replace('/\D+/','',$phoneRaw);
    if((int)($source['normalize_phone']??1)===1&&$phone!==''){
        if(strpos($phone,'55')!==0&&strlen($phone)>=10&&strlen($phone)<=11)$phone='55'.$phone;
        if(preg_match('/^55(\d{2})(\d{8})$/',$phone,$m))$phone='55'.$m[1].'9'.$m[2];
        $phone='+'.$phone;
    }
    return ['name'=>trim((string)(hub_source_value($source,$payload,'name')??'')),
        'email'=>mb_strtolower(trim((string)(hub_source_value($source,$payload,'email')??'')),'UTF-8'),'phone'=>$phone];
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

function hub_build_delivery(array $route, array $payload, array $source=[]): array
{
    $config=json_decode((string)($route['config_json']??''),true)?:[];
    $adapter=(string)$route['adapter'];
    if($adapter==='superfuncionario'){
        $contact=$source?hub_source_contact($source,$payload):['name'=>'','email'=>'','phone'=>''];
        $event=mb_strtoupper(trim((string)(hub_source_value($source,$payload,'event')??$payload['event']??'')),'UTF-8');$actions=[];
        if(trim((string)($config['fixed_tag']??''))!=='')$actions[]=['action'=>'add_tag','tag_name'=>(string)$config['fixed_tag']];
        if(ctype_digit((string)($config['flow_id']??'')))$actions[]=['action'=>'send_flow','flow_id'=>(int)$config['flow_id']];
        if($event!==''&&trim((string)($config['event_tag_prefix']??''))!=='')$actions[]=['action'=>'add_tag','tag_name'=>mb_strtoupper((string)$config['event_tag_prefix'].$event,'UTF-8')];
        $orderBump=hotmart_sf_shadow_pick($payload,'data.purchase.order_bump.is_order_bump');if($orderBump!==null&&trim((string)($config['order_bump_prefix']??''))!=='')$actions[]=['action'=>'add_tag','tag_name'=>mb_strtoupper((string)$config['order_bump_prefix'].hotmart_sf_shadow_transform($orderBump,'lower'),'UTF-8')];
        foreach((array)($config['fields']??[]) as $field){if(!is_array($field))continue;$value=hotmart_sf_shadow_pick($payload,(string)($field['source']??''));if($value===null)continue;$value=hotmart_sf_shadow_transform($value,(string)($field['transform']??'trim'));if($value!=='')$actions[]=['action'=>'set_field_value','field_name'=>(string)($field['field_name']??$field['dest']??''),'value'=>$value];}
        return array_filter(['email'=>$contact['email']?:null,'phone'=>$contact['phone']?:null,'first_name'=>$contact['name']?:null,'actions'=>$actions],static fn($v)=>$v!==null);
    }
    $contact=$source?hub_source_contact($source,$payload):[];
    if($adapter==='manychat') return ['contact'=>$contact,'tags'=>hub_replace_templates((array)($config['tags']??[]),$payload),
        'flows'=>(array)($config['flows']??[]),'fields'=>hub_replace_templates((array)($config['fields']??[]),$payload)];
    return ['url'=>(string)($config['url']??''),'method'=>strtoupper((string)($config['method']??'POST')),
        'headers'=>(array)($config['headers']??[]),'body'=>hub_replace_templates($config['payload_template']??$payload,$payload)];
}

function hub_ingest(PDO $pdo,array $source,array $payload,string $raw=''): array
{
    hub_ensure_schema($pdo);$sourceId=(int)$source['id'];
    if((int)($source['is_active']??0)!==1)return ['event_id'=>0,'deliveries'=>0,'dispatched'=>false];
    $event=mb_strtoupper(trim((string)(hub_source_value($source,$payload,'event')??$payload['event']??$payload['evento']??'UNKNOWN')),'UTF-8');
    $tx=trim((string)(hub_source_value($source,$payload,'transaction')??''));$contact=hub_source_contact($source,$payload);
    $externalId=trim((string)($payload['id']??$payload['event_id']??''));
    $raw=$raw!==''?$raw:(string)json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($externalId==='')$externalId=hash('sha256',$raw);
    $stmt=$pdo->prepare("INSERT INTO integration_events (source_id,external_event_id,event_name,transaction_code,contact_email,contact_phone,raw_payload_json,received_at,updated_at) VALUES (:source,:external,:event,:tx,:email,:phone,:raw,NOW(),NOW()) ON DUPLICATE KEY UPDATE event_name=VALUES(event_name),transaction_code=VALUES(transaction_code),contact_email=VALUES(contact_email),contact_phone=VALUES(contact_phone),raw_payload_json=VALUES(raw_payload_json),updated_at=NOW()");
    $stmt->execute(['source'=>$sourceId,'external'=>$externalId,'event'=>$event,'tx'=>$tx?:null,'email'=>$contact['email']?:null,'phone'=>$contact['phone']?:null,'raw'=>$raw]);
    $find=$pdo->prepare("SELECT id FROM integration_events WHERE source_id=:source AND external_event_id=:external");$find->execute(['source'=>$sourceId,'external'=>$externalId]);$eventId=(int)$find->fetchColumn();
    $routes=$pdo->prepare("SELECT r.*,d.adapter FROM integration_routes r JOIN integration_destinations d ON d.id=r.destination_id WHERE r.source_id=:source AND r.is_active=1");$routes->execute(['source'=>$sourceId]);$prepared=0;
    foreach($routes->fetchAll(PDO::FETCH_ASSOC) as $route){$body=hub_build_delivery($route,$payload,$source);$delivery=$pdo->prepare("INSERT INTO integration_deliveries (event_id,route_id,destination_id,status,prepared_payload_json,created_at,updated_at) VALUES (:event,:route,:destination,'shadow',:payload,NOW(),NOW()) ON DUPLICATE KEY UPDATE prepared_payload_json=VALUES(prepared_payload_json),updated_at=NOW()");$delivery->execute(['event'=>$eventId,'route'=>(int)$route['id'],'destination'=>(int)$route['destination_id'],'payload'=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$prepared++;}
    return ['event_id'=>$eventId,'deliveries'=>$prepared,'dispatched'=>false];
}

function hub_ingest_hotmart(PDO $pdo, array $payload): array
{
    hub_ensure_schema($pdo);
    $source=$pdo->query("SELECT * FROM integration_sources WHERE slug='hotmart' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    return hub_ingest($pdo,$source,$payload);
}
