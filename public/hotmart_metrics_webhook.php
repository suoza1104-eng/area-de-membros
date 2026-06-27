<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics.php';

header('Content-Type: application/json; charset=utf-8');
function hmw_reply(int $status, array $data): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function hmw_datetime($value): ?string {
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) { $ts=(int)$value; if($ts>9999999999)$ts=(int)floor($ts/1000); return date('Y-m-d H:i:s',$ts); }
    $ts=strtotime((string)$value); return $ts ? date('Y-m-d H:i:s',$ts) : null;
}
function hmw_status(string $event, string $status): string {
    $event=strtoupper(trim($event));
    if(in_array($event,['PURCHASE_APPROVED','PURCHASE_COMPLETE'],true))return 'APPROVED';
    $v=strtoupper(trim($status ?: $event));
    if(strpos($v,'REFUND')!==false)return 'REFUNDED';
    if(strpos($v,'CHARGEBACK')!==false)return 'CHARGEBACK';
    if(strpos($v,'CANCEL')!==false)return 'CANCELED';
    if(strpos($v,'APPROV')!==false||strpos($v,'COMPLET')!==false||strpos($v,'PAID')!==false)return 'APPROVED';
    if(strpos($v,'PEND')!==false||strpos($v,'WAIT')!==false)return 'PENDING';
    return $v ?: 'PENDING';
}
function hmw_producer_net(array $commissions): float {
    $total=0.0;
    foreach($commissions as $commission){
        $source=strtoupper((string)($commission['source']??''));
        if($source==='PRODUCER'||$source==='COPRODUCER')$total+=(float)($commission['value']??0);
    }
    return $total;
}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')hmw_reply(405,['ok'=>false,'message'=>'Metodo nao permitido']);
$token=(string)(get_setting('metrics_hotmart_hottok','')?:'');
if($token==='')hmw_reply(503,['ok'=>false,'message'=>'HOTTOK ainda nao configurado']);
$headers=function_exists('getallheaders')?getallheaders():[];
$provided='';foreach($headers as $key=>$value)if(in_array(strtolower((string)$key),['x-hotmart-hottok','hotmart-hottok'],true))$provided=(string)$value;
if(!hash_equals($token,$provided))hmw_reply(401,['ok'=>false,'message'=>'Nao autorizado']);
$raw=(string)file_get_contents('php://input');
$payload=json_decode($raw,true);
if(!is_array($payload))hmw_reply(400,['ok'=>false,'message'=>'JSON invalido']);

$pdo=getPDO();metrics_ensure_schema($pdo);
$eventId=(string)($payload['id']??hash('sha256',$raw));$event=(string)($payload['event']??'');
$data=is_array($payload['data']??null)?$payload['data']:[];
$purchase=is_array($data['purchase']??null)?$data['purchase']:[];$buyer=is_array($data['buyer']??null)?$data['buyer']:[];$product=is_array($data['product']??null)?$data['product']:[];$offer=is_array($purchase['offer']??null)?$purchase['offer']:[];
$transaction=trim((string)($purchase['transaction']??''));
if($transaction==='')hmw_reply(422,['ok'=>false,'message'=>'Transacao ausente']);
$email=normalize_email_value($buyer['email']??'');$phoneRaw=trim((string)($buyer['checkout_phone_code']??'').(string)($buyer['checkout_phone']??''));$phone=normalize_phone_value($phoneRaw);
$matched=hotmart_find_matching_user($pdo,$email,$phone);$status=hmw_status($event,(string)($purchase['status']??''));
$net=(float)($purchase['price']['value']??0);$gross=(float)($purchase['full_price']['value']??($purchase['original_offer_price']['value']??$net));$producer=hmw_producer_net((array)($data['commissions']??[]));if($producer<=0)$producer=$net;
$sale=hotmart_build_sale_data_from_array([
 'webhook_event'=>$event,'webhook_event_id'=>$eventId,'transaction_code'=>$transaction,'status'=>$status,
 'transaction_date'=>hmw_datetime($purchase['order_date']??null),'payment_confirmed_at'=>hmw_datetime($purchase['approved_date']??null),
 'refund_or_chargeback_at'=>in_array($status,['REFUNDED','CHARGEBACK','CANCELED'],true)?hmw_datetime($payload['creation_date']??null):null,
 'product_code'=>$product['id']??null,'product_name'=>$product['name']??'','price_code'=>$offer['code']??'','price_name'=>$offer['name']??'',
 'currency'=>$purchase['price']['currency_value']??($purchase['full_price']['currency_value']??'BRL'),'gross_revenue'=>$gross,'net_revenue'=>$net,'producer_net'=>$producer,
 'refunded_value'=>$status==='REFUNDED'?$net:0,'chargeback_value'=>$status==='CHARGEBACK'?$net:0,
 'buyer_name'=>$buyer['name']??'','buyer_email'=>$email,'buyer_phone_raw'=>$phoneRaw,'buyer_phone_norm'=>$phone,'raw_payload_json'=>$raw,
],$matched);

try{
 $pdo->beginTransaction();
 hotmart_upsert_sale_live($pdo,$sale);
 hotmart_upsert_sale_legacy($pdo,$sale);
 $stmt=$pdo->prepare("INSERT INTO hotmart_webhook_events (event_id,event_name,transaction_code,process_status,process_message,payload_json,received_at,processed_at) VALUES (:id,:event,:tx,'success','Processado',:payload,NOW(),NOW()) ON DUPLICATE KEY UPDATE process_status='success',process_message='Reprocessado',processed_at=NOW()");
 $stmt->execute(['id'=>$eventId,'event'=>$event,'tx'=>$transaction,'payload'=>$raw]);
 $payment=(string)($purchase['payment']['type']??'');$installments=(int)($purchase['payment']['installments_number']??0);$origin=(string)($purchase['origin']['src']??'hotmart');
 $pdo->prepare("UPDATE hotmart_sales_live SET payment_type=:payment,installments_number=:installments,sale_origin=:origin,sales_channel='hotmart' WHERE transaction_code=:tx")->execute(['payment'=>$payment?:null,'installments'=>$installments?:null,'origin'=>$origin?:null,'tx'=>$transaction]);
 $pdo->commit();
 hmw_reply(200,['ok'=>true,'transaction'=>$transaction,'status'=>$status,'match_method'=>$sale['match_method']]);
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 app_log('Falha no webhook de metricas Hotmart',['event_id'=>$eventId,'transaction'=>$transaction,'error'=>$e->getMessage()]);
 hmw_reply(500,['ok'=>false,'message'=>'Falha ao processar evento']);
}
