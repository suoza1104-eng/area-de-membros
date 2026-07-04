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
        install_event_sent_at DATETIME NULL,
        push_authorized_event_sent_at DATETIME NULL,
        uninstall_event_sent_at DATETIME NULL,
        UNIQUE KEY uk_push_device_client (client_id),
        UNIQUE KEY uk_push_device_token_hash (token_hash),
        KEY idx_push_device_user (user_id),
        KEY idx_push_device_status (status),
        KEY idx_push_device_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'install_event_sent_at' => 'DATETIME NULL',
        'push_authorized_event_sent_at' => 'DATETIME NULL',
        'uninstall_event_sent_at' => 'DATETIME NULL',
    ] as $column => $definition) {
        $check = $pdo->query('SHOW COLUMNS FROM push_devices LIKE ' . $pdo->quote($column));
        if (!$check || !$check->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE push_devices ADD COLUMN {$column} {$definition}");
        }
    }
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

function push_setting_enabled(string $key, bool $default = false): bool
{
    $value = get_setting($key, $default ? '1' : '0');
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function push_allowed_external_hosts(): array
{
    $defaults = ['professoremersonleite.com', 'hotmart.com', 'hotwebinar.com.br', 'firepay.com.br'];
    $configured = (string)(get_setting('push_allowed_external_hosts', implode("\n", $defaults)) ?? '');
    $baseHost = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
    $hosts = $baseHost !== '' ? [$baseHost] : [];
    foreach (preg_split('/[\s,;]+/', strtolower($configured)) ?: [] as $host) {
        $host = trim($host, " \t\n\r\0\x0B.");
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)) $hosts[] = $host;
    }
    return array_values(array_unique($hosts));
}

function push_normalize_click_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return 'trilha.php';
    if (mb_strlen($url) > 1000 || str_contains($url, "\r") || str_contains($url, "\n") || str_contains($url, '\\') || str_contains($url, '..')) {
        throw new InvalidArgumentException('O link da notificação é inválido.');
    }
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
        if (str_starts_with($url, '//') || str_starts_with($url, '#')) throw new InvalidArgumentException('Use um caminho interno ou uma URL HTTPS autorizada.');
        return ltrim($url, '/');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        throw new InvalidArgumentException('Links externos precisam usar HTTPS e não podem conter credenciais.');
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    foreach (push_allowed_external_hosts() as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) return $url;
    }
    throw new InvalidArgumentException('O domínio ' . $host . ' não está autorizado nas configurações de notificações.');
}

function push_app_icon_url(): string
{
    $icon = trim((string)(get_setting('push_app_icon_url', 'pwa-icon.svg') ?? ''));
    return $icon !== '' ? $icon : 'pwa-icon.svg';
}

function push_dispatch_lifecycle_event(PDO $pdo, int $deviceId, string $event): bool
{
    $map = [
        'APP_INSTALADO' => 'install_event_sent_at',
        'APP_NOTIFICACOES_AUTORIZADAS' => 'push_authorized_event_sent_at',
        'APP_DESINSTALADO_DETECTADO' => 'uninstall_event_sent_at',
    ];
    if ($deviceId <= 0 || !isset($map[$event])) return false;

    $column = $map[$event];
    $claim = $pdo->prepare("UPDATE push_devices SET {$column}=NOW() WHERE id=:id AND {$column} IS NULL");
    $claim->execute(['id' => $deviceId]);
    if ($claim->rowCount() !== 1) return false;

    $stmt = $pdo->prepare('SELECT * FROM push_devices WHERE id=:id LIMIT 1');
    $stmt->execute(['id' => $deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device || (int)$device['user_id'] <= 0) return false;
    $userId = (int)$device['user_id'];

    if ($event === 'APP_INSTALADO') {
        $tag = trim((string)(get_setting('push_tag_installed', '') ?? ''));
        if ($tag !== '') adicionar_tag($userId, $tag, 'app_instalado', $deviceId);
    } elseif ($event === 'APP_NOTIFICACOES_AUTORIZADAS') {
        $tag = trim((string)(get_setting('push_tag_authorized', '') ?? ''));
        if ($tag !== '') adicionar_tag($userId, $tag, 'app_notificacoes', $deviceId);
    } else {
        $tag = trim((string)(get_setting('push_tag_uninstalled', '') ?? ''));
        if ($tag !== '') adicionar_tag($userId, $tag, 'app_desinstalado', $deviceId);
        if (push_setting_enabled('push_uninstall_remove_installed_tag', true)) {
            $installedTag = trim((string)(get_setting('push_tag_installed', '') ?? ''));
            $other = $pdo->prepare("SELECT COUNT(*) FROM push_devices WHERE user_id=:uid AND id<>:id AND installed_at IS NOT NULL AND status='active'");
            $other->execute(['uid'=>$userId,'id'=>$deviceId]);
            if ($installedTag !== '' && (int)$other->fetchColumn() === 0) remover_tag_usuario($userId, $installedTag);
        }
    }

    disparar_webhooks($event, $userId, [
        'device_id' => $deviceId,
        'client_id' => (string)$device['client_id'],
        'platform' => (string)$device['platform'],
        'browser' => (string)($device['browser'] ?? ''),
        'notification_permission' => (string)$device['notification_permission'],
        'installed_at' => $device['installed_at'],
        'uninstalled_at' => $device['uninstalled_at'],
        'detection' => $event === 'APP_DESINSTALADO_DETECTADO' ? 'firebase_token_unregistered' : 'browser',
    ]);
    return true;
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
    $clickUrl = push_normalize_click_url($clickUrl);
    $trackingUrl = rtrim(BASE_URL, '/') . '/push_click.php?id=' . $deliveryLogId;
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
        push_dispatch_lifecycle_event($pdo, (int)$device['id'], 'APP_DESINSTALADO_DETECTADO');
    } elseif (!$accepted) {
        $pdo->prepare("UPDATE push_devices SET last_error=:error WHERE id=:id")->execute(['error'=>$error,'id'=>(int)$device['id']]);
    }
    return ['accepted'=>$accepted,'gone'=>$gone,'status'=>$status,'error'=>$error];
}
