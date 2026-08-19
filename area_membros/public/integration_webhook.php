<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/integration_hub.php';
header('Content-Type: application/json; charset=utf-8');
function hub_webhook_reply(int $status,array $body):void{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')hub_webhook_reply(405,['ok'=>false,'message'=>'Metodo nao permitido']);
$slug=strtolower(preg_replace('/[^a-z0-9_-]/','',(string)($_GET['source']??'')));$key=trim((string)($_GET['key']??''));
if($slug===''||$key==='')hub_webhook_reply(400,['ok'=>false,'message'=>'Fonte ou chave ausente']);
$pdo=getPDO();hub_ensure_schema($pdo);$stmt=$pdo->prepare("SELECT * FROM integration_sources WHERE slug=:slug LIMIT 1");$stmt->execute(['slug'=>$slug]);$source=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$source)hub_webhook_reply(404,['ok'=>false,'message'=>'Fonte nao encontrada']);
if((int)$source['is_active']!==1)hub_webhook_reply(403,['ok'=>false,'message'=>'Fonte pausada']);
if(!hash_equals((string)($source['webhook_key']??''),$key))hub_webhook_reply(401,['ok'=>false,'message'=>'Chave invalida']);
$raw=(string)file_get_contents('php://input');$format=strtolower((string)($source['payload_format']??'json'));$payload=[];
if($format==='form'){$payload=$_POST?:[];if(!$payload&&$raw!=='')parse_str($raw,$payload);}else{$payload=json_decode($raw,true);if(!is_array($payload)&&$format==='auto'){$payload=$_POST?:[];if(!$payload&&$raw!=='')parse_str($raw,$payload);}}
if(!is_array($payload)||!$payload)hub_webhook_reply(400,['ok'=>false,'message'=>'Payload invalido ou vazio']);
try{$result=hub_ingest($pdo,$source,$payload,$raw!==''?$raw:(string)json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));hub_webhook_reply(200,['ok'=>true,'event_id'=>$result['event_id'],'deliveries_prepared'=>$result['deliveries'],'dispatched'=>false]);}
catch(Throwable $e){log_sistema('error','integration_hub','Falha ao receber webhook',['source'=>$slug,'error'=>$e->getMessage()]);hub_webhook_reply(500,['ok'=>false,'message'=>'Falha ao armazenar evento']);}
