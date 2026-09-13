<?php
declare(strict_types=1);

require_once __DIR__ . '/push_notifications.php';

function admin_app_ensure_schema(PDO $pdo): void
{
    push_ensure_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_push_devices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_type VARCHAR(20) NOT NULL DEFAULT 'admin',
        admin_id VARCHAR(80) NOT NULL DEFAULT 'admin',
        admin_name VARCHAR(150) NULL,
        client_id VARCHAR(80) NOT NULL,
        token TEXT NULL,
        token_hash CHAR(64) NULL,
        platform VARCHAR(30) NOT NULL DEFAULT 'web',
        browser VARCHAR(40) NULL,
        user_agent VARCHAR(500) NULL,
        notification_permission VARCHAR(20) NOT NULL DEFAULT 'default',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        installed_at DATETIME NULL,
        registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_token_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_error VARCHAR(500) NULL,
        UNIQUE KEY uk_admin_push_client (client_id),
        UNIQUE KEY uk_admin_push_token_hash (token_hash),
        KEY idx_admin_push_identity (admin_type, admin_id),
        KEY idx_admin_push_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_push_preferences (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_type VARCHAR(20) NOT NULL DEFAULT 'admin',
        admin_id VARCHAR(80) NOT NULL DEFAULT 'admin',
        event_code VARCHAR(80) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sound_key VARCHAR(40) NOT NULL DEFAULT 'cash',
        min_value_cents BIGINT NOT NULL DEFAULT 0,
        product_filter VARCHAR(255) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_admin_push_pref (admin_type, admin_id, event_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_push_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_code VARCHAR(80) NOT NULL,
        title VARCHAR(160) NOT NULL,
        body VARCHAR(600) NOT NULL,
        click_url VARCHAR(1000) NULL,
        payload_json LONGTEXT NULL,
        total_targets INT NOT NULL DEFAULT 0,
        accepted_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        KEY idx_admin_push_notification_event (event_code, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_push_delivery_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        notification_id BIGINT UNSIGNED NOT NULL,
        device_id BIGINT UNSIGNED NOT NULL,
        admin_type VARCHAR(20) NOT NULL,
        admin_id VARCHAR(80) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'queued',
        fcm_message_name VARCHAR(255) NULL,
        http_status INT NULL,
        response_body TEXT NULL,
        error_message VARCHAR(500) NULL,
        sent_at DATETIME NULL,
        clicked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_admin_push_delivery_notification (notification_id),
        KEY idx_admin_push_delivery_device (device_id),
        KEY idx_admin_push_delivery_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function admin_app_identity_from_session(): array
{
    $isTeam = (string)($_SESSION['admin_tipo'] ?? 'admin') === 'equipe';
    return [
        'admin_type' => $isTeam ? 'equipe' : 'admin',
        'admin_id' => $isTeam ? (string)($_SESSION['equipe_id'] ?? '0') : 'admin',
        'admin_name' => $isTeam ? (string)($_SESSION['equipe_nome'] ?? 'Equipe') : (string)($_SESSION['admin_nome'] ?? 'Administrador'),
    ];
}

function admin_app_events(): array
{
    return [
        'PAGAMENTO_APROVADO' => ['label'=>'Venda aprovada', 'default_sound'=>'cash', 'default_enabled'=>true],
        'PAGAMENTO_REEMBOLSADO' => ['label'=>'Reembolso', 'default_sound'=>'alert', 'default_enabled'=>true],
        'PAGAMENTO_CHARGEBACK' => ['label'=>'Chargeback', 'default_sound'=>'critical', 'default_enabled'=>true],
        'PAGAMENTO_CANCELADO' => ['label'=>'Pagamento cancelado', 'default_sound'=>'soft', 'default_enabled'=>false],
        'PAGAMENTO_AGUARDANDO' => ['label'=>'Pagamento pendente', 'default_sound'=>'soft', 'default_enabled'=>false],
    ];
}

function admin_app_default_pref(string $eventCode): array
{
    $event = admin_app_events()[$eventCode] ?? ['default_enabled'=>false, 'default_sound'=>'soft'];
    return [
        'enabled' => !empty($event['default_enabled']),
        'sound_key' => (string)($event['default_sound'] ?? 'soft'),
        'min_value_cents' => 0,
        'product_filter' => '',
    ];
}

function admin_app_pref_for(PDO $pdo, string $adminType, string $adminId, string $eventCode): array
{
    $default = admin_app_default_pref($eventCode);
    $st = $pdo->prepare("SELECT * FROM admin_push_preferences WHERE admin_type=:type AND admin_id=:id AND event_code=:event LIMIT 1");
    $st->execute(['type'=>$adminType, 'id'=>$adminId, 'event'=>$eventCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $default;
    return [
        'enabled' => (int)($row['enabled'] ?? 0) === 1,
        'sound_key' => (string)($row['sound_key'] ?? $default['sound_key']),
        'min_value_cents' => (int)($row['min_value_cents'] ?? 0),
        'product_filter' => (string)($row['product_filter'] ?? ''),
    ];
}

function admin_app_money_cents(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function admin_app_notification_text(string $eventCode, array $payload): array
{
    $buyer = trim((string)($payload['buyer_name'] ?? $payload['comprador_nome'] ?? ''));
    $product = trim((string)($payload['product_name'] ?? $payload['produto_nome'] ?? ''));
    $grossCents = (int)($payload['gross_amount_cents'] ?? round(((float)($payload['valor_bruto'] ?? 0)) * 100));
    $netCents = (int)($payload['net_amount_cents'] ?? round(((float)($payload['valor_liquido'] ?? 0)) * 100));
    $value = $netCents > 0 ? $netCents : $grossCents;
    $gateway = strtoupper((string)($payload['provider'] ?? $payload['gateway'] ?? ''));
    $productPart = $product !== '' ? ' - ' . $product : '';
    $buyerPart = $buyer !== '' ? $buyer : 'Novo comprador';

    if ($eventCode === 'PAGAMENTO_APROVADO') {
        return ['Venda aprovada: ' . admin_app_money_cents($value), $buyerPart . $productPart . ($gateway !== '' ? ' [' . $gateway . ']' : '')];
    }
    if ($eventCode === 'PAGAMENTO_REEMBOLSADO') {
        return ['Reembolso registrado', $buyerPart . $productPart];
    }
    if ($eventCode === 'PAGAMENTO_CHARGEBACK') {
        return ['Chargeback registrado', $buyerPart . $productPart];
    }
    if ($eventCode === 'PAGAMENTO_CANCELADO') {
        return ['Pagamento cancelado', $buyerPart . $productPart];
    }
    return ['Evento de pagamento', $buyerPart . $productPart];
}

function admin_app_send_to_device(PDO $pdo, array $device, int $notificationId, int $deliveryLogId, string $title, string $body, string $clickUrl, string $eventCode, string $soundKey): array
{
    $projectId = trim((string)(push_public_config()['projectId'] ?? ''));
    if ($projectId === '') throw new RuntimeException('Project ID do Firebase nao configurado.');
    $payload = [
        'message' => [
            'token' => (string)$device['token'],
            'data' => [
                'title' => $title,
                'body' => $body,
                'click_url' => $clickUrl,
                'notification_id' => (string)$notificationId,
                'delivery_log_id' => (string)$deliveryLogId,
                'event_code' => $eventCode,
                'sound_key' => $soundKey,
                'channel' => 'admin',
            ],
            'webpush' => ['headers' => ['Urgency' => 'high']],
        ],
    ];
    $response = push_http_post(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
        ['Authorization: Bearer ' . push_google_access_token(), 'Content-Type: application/json; charset=UTF-8'],
        (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $json = json_decode($response['body'], true);
    $accepted = $response['status'] >= 200 && $response['status'] < 300 && !empty($json['name']);
    $errorStatus = strtoupper((string)($json['error']['details'][0]['errorCode'] ?? ''));
    $gone = $errorStatus === 'UNREGISTERED';
    $status = $accepted ? 'accepted' : ($gone ? 'uninstalled' : 'failed');
    $error = $accepted ? null : substr((string)($json['error']['message'] ?? $response['body']), 0, 500);
    $pdo->prepare("UPDATE admin_push_delivery_logs SET status=:status,fcm_message_name=:name,http_status=:http,response_body=:body,error_message=:error,sent_at=NOW() WHERE id=:id")
        ->execute(['status'=>$status, 'name'=>$json['name'] ?? null, 'http'=>$response['status'], 'body'=>substr($response['body'], 0, 65000), 'error'=>$error, 'id'=>$deliveryLogId]);
    if ($gone) {
        $pdo->prepare("UPDATE admin_push_devices SET status='uninstalled',last_error=:error WHERE id=:id")
            ->execute(['error'=>$error, 'id'=>(int)$device['id']]);
    } elseif (!$accepted) {
        $pdo->prepare("UPDATE admin_push_devices SET last_error=:error WHERE id=:id")
            ->execute(['error'=>$error, 'id'=>(int)$device['id']]);
    }
    return ['accepted'=>$accepted, 'status'=>$status, 'error'=>$error];
}

function admin_app_notify_event(PDO $pdo, string $eventCode, array $payload): array
{
    admin_app_ensure_schema($pdo);
    if (!push_is_configured()) return ['ok'=>false, 'reason'=>'push_not_configured'];

    $events = admin_app_events();
    if (!isset($events[$eventCode])) return ['ok'=>false, 'reason'=>'event_not_supported'];

    $grossCents = (int)($payload['gross_amount_cents'] ?? round(((float)($payload['valor_bruto'] ?? 0)) * 100));
    $product = mb_strtolower(trim((string)($payload['product_name'] ?? $payload['produto_nome'] ?? '')));
    [$title, $body] = admin_app_notification_text($eventCode, $payload);
    $clickUrl = rtrim(BASE_URL_ADMIN, '/') . '/vendas_auditoria.php';
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    $devices = $pdo->query("SELECT * FROM admin_push_devices WHERE status='active' AND notification_permission='granted' AND token IS NOT NULL AND token<>'' ORDER BY last_seen_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $eligible = [];
    foreach ($devices as $device) {
        $pref = admin_app_pref_for($pdo, (string)$device['admin_type'], (string)$device['admin_id'], $eventCode);
        if (!$pref['enabled']) continue;
        if ($grossCents < (int)$pref['min_value_cents']) continue;
        $filter = mb_strtolower(trim((string)$pref['product_filter']));
        if ($filter !== '' && ($product === '' || !str_contains($product, $filter))) continue;
        $device['_sound_key'] = (string)$pref['sound_key'];
        $eligible[] = $device;
    }
    if (!$eligible) return ['ok'=>true, 'targets'=>0];

    $pdo->prepare("INSERT INTO admin_push_notifications (event_code,title,body,click_url,payload_json,total_targets) VALUES (:event,:title,:body,:click,:payload,:total)")
        ->execute(['event'=>$eventCode, 'title'=>$title, 'body'=>$body, 'click'=>$clickUrl, 'payload'=>$payloadJson, 'total'=>count($eligible)]);
    $notificationId = (int)$pdo->lastInsertId();
    $accepted = 0;
    $failed = 0;
    foreach ($eligible as $device) {
        $pdo->prepare("INSERT INTO admin_push_delivery_logs (notification_id,device_id,admin_type,admin_id,status) VALUES (:n,:d,:type,:admin,'queued')")
            ->execute(['n'=>$notificationId, 'd'=>(int)$device['id'], 'type'=>(string)$device['admin_type'], 'admin'=>(string)$device['admin_id']]);
        $deliveryLogId = (int)$pdo->lastInsertId();
        try {
            $result = admin_app_send_to_device($pdo, $device, $notificationId, $deliveryLogId, $title, $body, $clickUrl, $eventCode, (string)$device['_sound_key']);
            if (!empty($result['accepted'])) $accepted++; else $failed++;
        } catch (Throwable $e) {
            $failed++;
            $pdo->prepare("UPDATE admin_push_delivery_logs SET status='failed',error_message=:e,sent_at=NOW() WHERE id=:id")
                ->execute(['e'=>mb_substr($e->getMessage(), 0, 500), 'id'=>$deliveryLogId]);
        }
    }
    $pdo->prepare("UPDATE admin_push_notifications SET accepted_count=:a,failed_count=:f,finished_at=NOW() WHERE id=:id")
        ->execute(['a'=>$accepted, 'f'=>$failed, 'id'=>$notificationId]);
    return ['ok'=>true, 'targets'=>count($eligible), 'accepted'=>$accepted, 'failed'=>$failed, 'notification_id'=>$notificationId];
}
