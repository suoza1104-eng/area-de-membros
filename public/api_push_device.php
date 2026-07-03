<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../app/push_notifications.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function push_api_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') push_api_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin','same-site'], true)) push_api_out(['ok'=>false,'error'=>'origin_not_allowed'], 403);

$userId = (int)($_SESSION['aluno_id'] ?? 0);
if ($userId <= 0) $userId = aluno_restaurar_sessao_por_token();
if ($userId <= 0) push_api_out(['ok'=>false,'error'=>'not_logged'], 401);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$action = (string)($input['action'] ?? 'heartbeat');
$token = trim((string)($input['token'] ?? ''));
$clientId = strtolower(trim((string)($input['client_id'] ?? '')));
if (!preg_match('/^[a-z0-9-]{16,64}$/', $clientId)) push_api_out(['ok'=>false,'error'=>'invalid_client_id'], 422);
if (strlen($token) > 4096 || ($action === 'register' && $token === '')) push_api_out(['ok'=>false,'error'=>'invalid_token'], 422);

try {
    $pdo = getPDO();
    push_ensure_schema($pdo);
    $hash = $token !== '' ? hash('sha256', $token) : null;
    if ($action === 'disable') {
        $pdo->prepare("UPDATE push_devices SET status='revoked',notification_permission=:permission,last_seen_at=NOW() WHERE client_id=:client AND user_id=:uid")
            ->execute(['permission'=>(string)($input['permission']??'denied'),'client'=>$clientId,'uid'=>$userId]);
        push_api_out(['ok'=>true,'status'=>'revoked']);
    }
    if (!in_array($action, ['register','heartbeat','installed'], true)) push_api_out(['ok'=>false,'error'=>'invalid_action'], 422);

    $permission = in_array(($input['permission'] ?? ''), ['granted','denied','default'], true) ? (string)$input['permission'] : 'default';
    $installed = !empty($input['installed']) || $action === 'installed';
    $platform = substr(trim((string)($input['platform'] ?? 'web')), 0, 30) ?: 'web';
    $browser = substr(trim((string)($input['browser'] ?? '')), 0, 40) ?: null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null;
    $stmt = $pdo->prepare("INSERT INTO push_devices
        (user_id,client_id,token,token_hash,platform,browser,user_agent,notification_permission,status,installed_at,registered_at,last_seen_at,last_token_at)
        VALUES (:uid,:client,:token,:hash,:platform,:browser,:ua,:permission,'active',IF(:installed=1,NOW(),NULL),NOW(),NOW(),NOW())
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),client_id=VALUES(client_id),token=COALESCE(VALUES(token),token),token_hash=COALESCE(VALUES(token_hash),token_hash),platform=VALUES(platform),browser=VALUES(browser),
        user_agent=VALUES(user_agent),notification_permission=VALUES(notification_permission),status='active',uninstalled_at=NULL,
        installed_at=IF(VALUES(installed_at) IS NOT NULL,COALESCE(installed_at,VALUES(installed_at)),installed_at),last_seen_at=NOW(),last_token_at=NOW(),last_error=NULL");
    $stmt->execute(['uid'=>$userId,'client'=>$clientId,'token'=>$token!==''?$token:null,'hash'=>$hash,'platform'=>$platform,'browser'=>$browser,'ua'=>$ua,'permission'=>$permission,'installed'=>$installed?1:0]);
    $idStmt = $pdo->prepare('SELECT id,installed_at FROM push_devices WHERE client_id=:client LIMIT 1');
    $idStmt->execute(['client'=>$clientId]);
    $row = $idStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    push_api_out(['ok'=>true,'device_id'=>(int)($row['id']??0),'installed'=>!empty($row['installed_at'])]);
} catch (Throwable $e) {
    error_log('api_push_device: ' . $e->getMessage());
    push_api_out(['ok'=>false,'error'=>'server_error','message'=>'Não foi possível registrar este dispositivo.'], 500);
}
