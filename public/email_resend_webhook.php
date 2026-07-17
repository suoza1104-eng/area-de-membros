<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';

header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}

$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>1048576){http_response_code(413);echo json_encode(['ok'=>false]);exit;}
$payload=json_decode($raw,true);
if(!is_array($payload)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_json']);exit;}

$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$secret=email_resend_webhook_secret();
$signatureValid=false;
if($secret['configured']){
    $signatureValid=email_verify_resend_webhook($raw,$_SERVER,(string)$secret['webhook_secret']);
    if(!$signatureValid){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'invalid_signature']);exit;}
}else{
    email_save_settings($pdo,['resend_last_webhook_at'=>date('Y-m-d H:i:s'),'resend_last_webhook_event'=>'blocked_without_secret','resend_last_webhook_error'=>'RESEND_WEBHOOK_SECRET nao configurado']);
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'webhook_secret_not_configured']);
    exit;
}

try{
    $ok=email_process_resend_event($pdo,$payload,$signatureValid);
    echo json_encode(['ok'=>$ok],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    email_save_settings($pdo,['resend_last_webhook_at'=>date('Y-m-d H:i:s'),'resend_last_webhook_event'=>(string)($payload['type']??'unknown'),'resend_last_webhook_error'=>mb_substr($e->getMessage(),0,500)]);
    echo json_encode(['ok'=>true,'stored'=>false,'error'=>'processing_failed'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
