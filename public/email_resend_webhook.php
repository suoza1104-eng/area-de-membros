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
$settings=email_settings($pdo);
$secret=trim((string)($settings['resend_webhook_secret']??''));
if($secret!==''){
    $provided=(string)($_SERVER['HTTP_X_RESEND_WEBHOOK_SECRET']??$_SERVER['HTTP_X_EMAIL_WEBHOOK_SECRET']??$_GET['token']??'');
    if(!hash_equals($secret,$provided)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'forbidden']);exit;}
}

try{
    $ok=email_process_resend_event($pdo,$payload);
    echo json_encode(['ok'=>$ok],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'processing_failed'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
