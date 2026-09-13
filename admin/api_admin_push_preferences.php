<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../app/admin_app_notifications.php';
proteger_admin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function admin_push_pref_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = getPDO();
    admin_app_ensure_schema($pdo);
    $identity = admin_app_identity_from_session();
    $events = admin_app_events();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $prefs = [];
        foreach ($events as $code => $meta) $prefs[$code] = admin_app_pref_for($pdo, $identity['admin_type'], $identity['admin_id'], $code);
        $devices = $pdo->prepare("SELECT id,platform,browser,notification_permission,status,installed_at,last_seen_at,last_error FROM admin_push_devices WHERE admin_type=:type AND admin_id=:admin ORDER BY last_seen_at DESC LIMIT 20");
        $devices->execute(['type'=>$identity['admin_type'], 'admin'=>$identity['admin_id']]);
        admin_push_pref_out(['ok'=>true, 'events'=>$events, 'prefs'=>$prefs, 'devices'=>$devices->fetchAll(PDO::FETCH_ASSOC) ?: [], 'push_configured'=>push_is_configured()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') admin_push_pref_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $code = (string)($input['event_code'] ?? '');
    if (!isset($events[$code])) admin_push_pref_out(['ok'=>false,'error'=>'invalid_event'], 422);
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $sound = (string)($input['sound_key'] ?? 'soft');
    if (!in_array($sound, ['cash','alert','critical','soft','silent'], true)) $sound = 'soft';
    $minValue = max(0, (int)round(((float)str_replace(',', '.', (string)($input['min_value'] ?? '0'))) * 100));
    $product = mb_substr(trim((string)($input['product_filter'] ?? '')), 0, 255);
    $pdo->prepare("INSERT INTO admin_push_preferences (admin_type,admin_id,event_code,enabled,sound_key,min_value_cents,product_filter)
        VALUES (:type,:admin,:event,:enabled,:sound,:min_value,:product)
        ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),sound_key=VALUES(sound_key),min_value_cents=VALUES(min_value_cents),product_filter=VALUES(product_filter)")
        ->execute(['type'=>$identity['admin_type'], 'admin'=>$identity['admin_id'], 'event'=>$code, 'enabled'=>$enabled, 'sound'=>$sound, 'min_value'=>$minValue, 'product'=>$product !== '' ? $product : null]);
    admin_push_pref_out(['ok'=>true]);
} catch (Throwable $e) {
    error_log('api_admin_push_preferences: ' . $e->getMessage());
    admin_push_pref_out(['ok'=>false,'error'=>'server_error','message'=>'Nao foi possivel salvar.'], 500);
}
