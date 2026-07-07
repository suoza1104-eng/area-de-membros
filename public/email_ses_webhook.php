<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>1048576){http_response_code(413);exit;}
$secret=getenv('SES_WEBHOOK_SECRET')?:'';$provided=(string)($_SERVER['HTTP_X_EMAIL_WEBHOOK_SECRET']??$_GET['token']??'');
if($secret===''||!hash_equals($secret,$provided)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'forbidden']);exit;}
$payload=json_decode($raw,true);if(!is_array($payload)){http_response_code(400);exit;}$event=$payload;
if(isset($payload['Type'])){
    if(!email_verify_sns_message($payload)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'invalid_signature']);exit;}
    if($payload['Type']==='SubscriptionConfirmation'){
        $url=(string)($payload['SubscribeURL']??'');$p=parse_url($url);$host=strtolower((string)($p['host']??''));
        if(($p['scheme']??'')==='https'&&preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/',$host)){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false]);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);echo json_encode(['ok'=>$code>=200&&$code<300,'subscription_confirmed'=>true]);exit;}
        http_response_code(400);exit;
    }
    $event=json_decode((string)($payload['Message']??''),true);if(!is_array($event))$event=[];
}
$pdo=getPDO();email_marketing_ensure_schema($pdo);$ok=email_process_ses_event($pdo,$event);echo json_encode(['ok'=>$ok],JSON_UNESCAPED_UNICODE);
