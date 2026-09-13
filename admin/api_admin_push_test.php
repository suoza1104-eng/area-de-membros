<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../app/admin_app_notifications.php';
proteger_admin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function admin_push_test_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') admin_push_test_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin','same-site'], true)) admin_push_test_out(['ok'=>false,'error'=>'origin_not_allowed'], 403);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$clientId = strtolower(trim((string)($input['client_id'] ?? '')));
if (!preg_match('/^[a-z0-9-]{16,80}$/', $clientId)) admin_push_test_out(['ok'=>false,'error'=>'invalid_client_id','message'=>'Dispositivo invalido. Ative as notificacoes novamente.'], 422);

try {
    $pdo = getPDO();
    admin_app_ensure_schema($pdo);
    if (!push_is_configured()) admin_push_test_out(['ok'=>false,'error'=>'push_not_configured','message'=>'Firebase Push ainda nao esta configurado.'], 422);

    $identity = admin_app_identity_from_session();
    $st = $pdo->prepare("SELECT * FROM admin_push_devices WHERE client_id=:client AND admin_type=:type AND admin_id=:admin AND status='active' LIMIT 1");
    $st->execute(['client'=>$clientId, 'type'=>$identity['admin_type'], 'admin'=>$identity['admin_id']]);
    $device = $st->fetch(PDO::FETCH_ASSOC);
    if (!$device || empty($device['token'])) {
        admin_push_test_out(['ok'=>false,'error'=>'device_not_registered','message'=>'Este dispositivo ainda nao esta registrado. Clique em Ativar notificacoes antes do teste.'], 404);
    }
    if (($device['notification_permission'] ?? '') !== 'granted') {
        admin_push_test_out(['ok'=>false,'error'=>'permission_not_granted','message'=>'A permissao de notificacoes ainda nao foi concedida neste dispositivo.'], 422);
    }

    $title = 'Teste push administrativo';
    $body = 'Se esta bandeirinha apareceu, este dispositivo esta pronto para receber alertas de venda.';
    $clickUrl = rtrim(BASE_URL_ADMIN, '/') . '/admin_app.php';
    $payload = [
        'test' => true,
        'client_id' => $clientId,
        'admin_type' => $identity['admin_type'],
        'admin_id' => $identity['admin_id'],
    ];
    $pdo->prepare("INSERT INTO admin_push_notifications (event_code,title,body,click_url,payload_json,total_targets) VALUES ('ADMIN_PUSH_TEST',:title,:body,:click,:payload,1)")
        ->execute(['title'=>$title, 'body'=>$body, 'click'=>$clickUrl, 'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $notificationId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO admin_push_delivery_logs (notification_id,device_id,admin_type,admin_id,status) VALUES (:n,:d,:type,:admin,'queued')")
        ->execute(['n'=>$notificationId, 'd'=>(int)$device['id'], 'type'=>$identity['admin_type'], 'admin'=>$identity['admin_id']]);
    $deliveryLogId = (int)$pdo->lastInsertId();

    $result = admin_app_send_to_device($pdo, $device, $notificationId, $deliveryLogId, $title, $body, $clickUrl, 'ADMIN_PUSH_TEST', 'cash');
    $accepted = !empty($result['accepted']);
    $pdo->prepare("UPDATE admin_push_notifications SET accepted_count=:a,failed_count=:f,finished_at=NOW() WHERE id=:id")
        ->execute(['a'=>$accepted ? 1 : 0, 'f'=>$accepted ? 0 : 1, 'id'=>$notificationId]);

    if (!$accepted) {
        admin_push_test_out(['ok'=>false,'error'=>'push_failed','message'=>'O Firebase recusou o envio: ' . (string)($result['error'] ?? 'erro desconhecido')], 502);
    }

    admin_push_test_out(['ok'=>true, 'notification_id'=>$notificationId, 'delivery_log_id'=>$deliveryLogId]);
} catch (Throwable $e) {
    error_log('api_admin_push_test: ' . $e->getMessage());
    admin_push_test_out(['ok'=>false,'error'=>'server_error','message'=>'Nao foi possivel enviar o teste push.'], 500);
}
