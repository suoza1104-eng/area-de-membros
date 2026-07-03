<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function push_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_devices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        token TEXT NULL,
        token_hash CHAR(64) NULL,
        platform VARCHAR(30) NOT NULL DEFAULT 'web',
        browser VARCHAR(40) NULL,
        user_agent VARCHAR(500) NULL,
        notification_permission VARCHAR(20) NOT NULL DEFAULT 'default',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        installed_at DATETIME NULL,
        uninstalled_at DATETIME NULL,
        registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_token_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_error VARCHAR(500) NULL,
        UNIQUE KEY uk_push_device_client (client_id),
        UNIQUE KEY uk_push_device_token_hash (token_hash),
        KEY idx_push_device_user (user_id),
        KEY idx_push_device_status (status),
        KEY idx_push_device_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        body VARCHAR(500) NOT NULL,
        click_url VARCHAR(1000) NULL,
        target_type VARCHAR(30) NOT NULL DEFAULT 'device_test',
        target_value VARCHAR(255) NULL,
        total_targets INT NOT NULL DEFAULT 0,
        accepted_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        clicked_count INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'processing',
        created_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        KEY idx_push_notification_created (created_at),
        KEY idx_push_notification_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_delivery_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        notification_id BIGINT UNSIGNED NOT NULL,
        device_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'queued',
        fcm_message_name VARCHAR(255) NULL,
        http_status INT NULL,
        response_body TEXT NULL,
        error_message VARCHAR(500) NULL,
        sent_at DATETIME NULL,
        clicked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_push_delivery_notification (notification_id),
        KEY idx_push_delivery_device (device_id),
        KEY idx_push_delivery_user (user_id),
        KEY idx_push_delivery_status (status),
        KEY idx_push_delivery_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function push_public_config(): array
{
    return [
        'apiKey' => (string)(get_setting('push_firebase_api_key', '') ?? ''),
        'authDomain' => (string)(get_setting('push_firebase_auth_domain', '') ?? ''),
        'projectId' => (string)(get_setting('push_firebase_project_id', '') ?? ''),
        'storageBucket' => (string)(get_setting('push_firebase_storage_bucket', '') ?? ''),
        'messagingSenderId' => (string)(get_setting('push_firebase_sender_id', '') ?? ''),
        'appId' => (string)(get_setting('push_firebase_app_id', '') ?? ''),
    ];
}

function push_vapid_key(): string
{
    return trim((string)(get_setting('push_firebase_vapid_key', '') ?? ''));
}

function push_is_configured(): bool
{
    $cfg = push_public_config();
    return $cfg['apiKey'] !== '' && $cfg['projectId'] !== '' && $cfg['messagingSenderId'] !== ''
        && $cfg['appId'] !== '' && push_vapid_key() !== ''
        && trim((string)(get_setting('push_firebase_service_account', '') ?? '')) !== '';
}

function push_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function push_http_post(string $url, array $headers, string $body): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('Falha HTTP: ' . ($error ?: 'erro desconhecido'));
    return ['status' => $status, 'body' => (string)$response];
}

function push_google_access_token(): string
{
    static $cached = null;
    if (is_array($cached) && (int)$cached['expires'] > time() + 60) return (string)$cached['token'];

    $raw = trim((string)(get_setting('push_firebase_service_account', '') ?? ''));
    $service = json_decode($raw, true);
    if (!is_array($service)) throw new RuntimeException('JSON da conta de serviço do Firebase inválido.');
    $email = trim((string)($service['client_email'] ?? ''));
    $privateKey = (string)($service['private_key'] ?? '');
    $tokenUri = trim((string)($service['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    if ($email === '' || $privateKey === '') throw new RuntimeException('A conta de serviço não contém client_email/private_key.');

    $now = time();
    $header = push_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $claims = push_base64url(json_encode([
        'iss' => $email,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3500,
    ], JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Não foi possível assinar o token da conta de serviço.');
    }
    $jwt = $signingInput . '.' . push_base64url($signature);
    $response = push_http_post($tokenUri, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]));
    $json = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300 || empty($json['access_token'])) {
        throw new RuntimeException('Firebase OAuth recusou a autenticação: ' . substr($response['body'], 0, 350));
    }
    $cached = ['token' => (string)$json['access_token'], 'expires' => $now + (int)($json['expires_in'] ?? 3600)];
    return (string)$cached['token'];
}

function push_send_to_device(PDO $pdo, array $device, int $notificationId, int $deliveryLogId, string $title, string $body, string $clickUrl): array
{
    $projectId = trim((string)(push_public_config()['projectId'] ?? ''));
    if ($projectId === '') throw new RuntimeException('Project ID do Firebase não configurado.');
    $trackingUrl = 'push_click.php?id=' . $deliveryLogId;
    if ($clickUrl !== '') $trackingUrl .= '&url=' . rawurlencode($clickUrl);
    $payload = [
        'message' => [
            'token' => (string)$device['token'],
            'data' => [
                'title' => $title,
                'body' => $body,
                'click_url' => $trackingUrl,
                'notification_id' => (string)$notificationId,
                'delivery_log_id' => (string)$deliveryLogId,
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

    $pdo->prepare("UPDATE push_delivery_logs SET status=:status,fcm_message_name=:name,http_status=:http,response_body=:body,error_message=:error,sent_at=NOW() WHERE id=:id")
        ->execute(['status'=>$status,'name'=>$json['name']??null,'http'=>$response['status'],'body'=>substr($response['body'],0,65000),'error'=>$error,'id'=>$deliveryLogId]);
    if ($gone) {
        $pdo->prepare("UPDATE push_devices SET status='uninstalled',uninstalled_at=NOW(),last_error=:error WHERE id=:id")
            ->execute(['error'=>$error,'id'=>(int)$device['id']]);
    } elseif (!$accepted) {
        $pdo->prepare("UPDATE push_devices SET last_error=:error WHERE id=:id")->execute(['error'=>$error,'id'=>(int)$device['id']]);
    }
    return ['accepted'=>$accepted,'gone'=>$gone,'status'=>$status,'error'=>$error];
}
