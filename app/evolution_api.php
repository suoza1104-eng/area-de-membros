<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function evolution_ensure_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_instances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            instance_key VARCHAR(120) NOT NULL,
            phone_number VARCHAR(30) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'DISCONNECTED',
            instance_token VARCHAR(120) NULL,
            pairing_code VARCHAR(80) NULL,
            qr_code_text LONGTEXT NULL,
            qr_base64 LONGTEXT NULL,
            last_response_json LONGTEXT NULL,
            last_error TEXT NULL,
            last_connected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_whatsapp_instances_key (instance_key),
            KEY idx_whatsapp_instances_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            chave VARCHAR(120) NOT NULL PRIMARY KEY,
            valor LONGTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_webhook_raw_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_ok TINYINT(1) NOT NULL DEFAULT 0,
            event_type VARCHAR(100) NULL,
            instance_key VARCHAR(120) NULL,
            group_id VARCHAR(160) NULL,
            action VARCHAR(60) NULL,
            participant_number VARCHAR(60) NULL,
            payload_raw LONGTEXT NOT NULL,
            headers_json TEXT NULL,
            source_ip VARCHAR(80) NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_wwrl_received (received_at),
            KEY idx_wwrl_event (event_type),
            KEY idx_wwrl_instance (instance_key),
            KEY idx_wwrl_group (group_id),
            KEY idx_wwrl_participant (participant_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function evolution_get_config(): array {
    return [
        'base_url' => rtrim((string)get_setting('evolution_base_url', ''), '/'),
        'apikey' => (string)get_setting('evolution_apikey', ''),
        'timeout' => max(3, (int)get_setting('evolution_timeout_seconds', '20')),
        'webhook_token' => evolution_get_webhook_token(),
    ];
}

function evolution_get_webhook_token(): string {
    $token = preg_replace('/[^a-f0-9]/i', '', (string)get_setting('evolution_webhook_token', ''));
    if (strlen($token) === 64) return strtolower($token);

    $token = bin2hex(random_bytes(32));
    set_setting('evolution_webhook_token', $token);
    return $token;
}

function evolution_set_config(string $baseUrl, string $apikey, int $timeout): void {
    set_setting('evolution_base_url', rtrim(trim($baseUrl), '/'));
    set_setting('evolution_apikey', trim($apikey));
    set_setting('evolution_timeout_seconds', (string)max(3, min(120, $timeout)));
}

function evolution_slug_instance(string $name): string {
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?: '';
    $base = trim($base, '-_');
    if ($base === '') {
        $base = 'spy-' . date('Ymd-His');
    }
    return substr($base, 0, 80);
}

function evolution_http(string $method, string $path, ?array $payload = null): array {
    $cfg = evolution_get_config();
    if ($cfg['base_url'] === '' || $cfg['apikey'] === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'raw' => '',
            'error' => 'Configure a URL e a API key da Evolution API antes de testar.',
        ];
    }
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'raw' => '',
            'error' => 'Extensao cURL indisponivel no PHP.',
        ];
    }

    $url = $cfg['base_url'] . '/' . ltrim($path, '/');
    $headers = [
        'apikey: ' . $cfg['apikey'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(10, (int)$cfg['timeout']),
        CURLOPT_TIMEOUT => (int)$cfg['timeout'],
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawString = is_string($raw) ? $raw : '';
    $data = null;
    if ($rawString !== '') {
        $decoded = json_decode($rawString, true);
        if (is_array($decoded)) $data = $decoded;
    }

    return [
        'ok' => $err === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $data,
        'raw' => $rawString,
        'error' => $err !== '' ? $err : ($status >= 400 ? 'HTTP ' . $status : ''),
    ];
}

function evolution_create_remote_instance(PDO $pdo, array $instance): array {
    $payload = [
        'instanceName' => $instance['instance_key'],
        'integration' => 'WHATSAPP-BAILEYS',
        'token' => $instance['instance_token'] ?: null,
        'qrcode' => true,
        'rejectCall' => true,
        'msgCall' => 'Este numero nao recebe chamadas.',
        'groupsIgnore' => false,
        'alwaysOnline' => false,
        'readMessages' => false,
        'readStatus' => false,
        'syncFullHistory' => false,
    ];
    if (!empty($instance['phone_number'])) {
        $payload['number'] = preg_replace('/\D+/', '', (string)$instance['phone_number']);
    }

    $res = evolution_http('POST', '/instance/create', $payload);
    evolution_update_instance_from_response($pdo, (int)$instance['id'], $res, 'CONNECTING');
    return $res;
}

function evolution_connect_instance(PDO $pdo, array $instance): array {
    $path = '/instance/connect/' . rawurlencode((string)$instance['instance_key']);
    $phone = preg_replace('/\D+/', '', (string)($instance['phone_number'] ?? ''));
    if ($phone !== '') {
        $path .= '?number=' . rawurlencode($phone);
    }
    $res = evolution_http('GET', $path);
    evolution_update_instance_from_response($pdo, (int)$instance['id'], $res, 'CONNECTING');
    return $res;
}

function evolution_fetch_state(PDO $pdo, array $instance): array {
    $res = evolution_http('GET', '/instance/connectionState/' . rawurlencode((string)$instance['instance_key']));
    $status = 'DISCONNECTED';
    $state = '';
    if (is_array($res['data'])) {
        $state = strtolower((string)($res['data']['instance']['state'] ?? $res['data']['state'] ?? ''));
    }
    if (in_array($state, ['open', 'connected'], true)) {
        $status = 'CONNECTED';
    } elseif (in_array($state, ['connecting', 'qrcode', 'pairing'], true)) {
        $status = 'CONNECTING';
    }
    evolution_update_instance_from_response($pdo, (int)$instance['id'], $res, $status);
    return $res;
}

function evolution_set_group_webhook(string $instanceKey, string $webhookUrl): array {
    return evolution_http('POST', '/webhook/set/' . rawurlencode($instanceKey), [
        'enabled' => true,
        'url' => $webhookUrl,
        'events' => ['GROUP_PARTICIPANTS_UPDATE'],
        'headers' => new stdClass(),
        'base64' => false,
    ]);
}

function evolution_find_webhook(string $instanceKey): array {
    return evolution_http('GET', '/webhook/find/' . rawurlencode($instanceKey));
}

function evolution_extract_raw_event_fields(array $payload): array {
    $eventType = (string)($payload['event'] ?? $payload['eventType'] ?? $payload['type'] ?? '');
    $instance = (string)($payload['instance'] ?? $payload['instanceName'] ?? $payload['instance_key'] ?? '');
    $data = $payload['data'] ?? [];
    if (!is_array($data)) $data = [];

    $groupId = (string)($data['id'] ?? $data['groupId'] ?? $data['remoteJid'] ?? $data['jid'] ?? $payload['groupId'] ?? '');
    $action = (string)($data['action'] ?? $payload['action'] ?? '');

    $participant = '';
    $participants = $data['participants'] ?? $data['participant'] ?? $payload['participants'] ?? null;
    if (is_array($participants)) {
        $first = reset($participants);
        if (is_scalar($first)) $participant = (string)$first;
        elseif (is_array($first)) $participant = (string)($first['id'] ?? $first['number'] ?? $first['jid'] ?? '');
    } elseif (is_scalar($participants)) {
        $participant = (string)$participants;
    }
    if ($participant === '') {
        $participant = (string)($data['participant'] ?? $data['number'] ?? $payload['participant'] ?? '');
    }

    return [
        'event_type' => $eventType !== '' ? $eventType : null,
        'instance_key' => $instance !== '' ? $instance : null,
        'group_id' => $groupId !== '' ? $groupId : null,
        'action' => $action !== '' ? $action : null,
        'participant_number' => $participant !== '' ? preg_replace('/[^0-9@._-]+/', '', $participant) : null,
    ];
}

function evolution_update_instance_from_response(PDO $pdo, int $id, array $res, string $fallbackStatus): void {
    $data = is_array($res['data']) ? $res['data'] : [];
    $qr = evolution_extract_qr($data);
    $state = strtolower((string)($data['instance']['state'] ?? $data['state'] ?? ''));
    $status = $fallbackStatus;
    if (in_array($state, ['open', 'connected'], true)) {
        $status = 'CONNECTED';
    } elseif (!$res['ok'] && $fallbackStatus !== 'CONNECTED') {
        $status = 'ERROR';
    }

    $stmt = $pdo->prepare("
        UPDATE whatsapp_instances
           SET status = :status,
               pairing_code = :pairing_code,
               qr_code_text = :qr_code_text,
               qr_base64 = :qr_base64,
               last_response_json = :last_response_json,
               last_error = :last_error,
               last_connected_at = IF(:status_connected = 1, NOW(), last_connected_at),
               updated_at = NOW()
         WHERE id = :id
         LIMIT 1
    ");
    $stmt->execute([
        ':status' => $status,
        ':pairing_code' => $qr['pairing_code'],
        ':qr_code_text' => $qr['qr_code_text'],
        ':qr_base64' => $qr['qr_base64'],
        ':last_response_json' => json_encode($data ?: ['raw' => $res['raw']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':last_error' => $res['ok'] ? null : ($res['error'] ?: 'Falha desconhecida'),
        ':status_connected' => $status === 'CONNECTED' ? 1 : 0,
        ':id' => $id,
    ]);
}

function evolution_extract_qr(array $data): array {
    $pairing = (string)($data['pairingCode'] ?? $data['pairing_code'] ?? '');
    $qrText = '';
    $qrBase64 = '';

    $candidates = [
        $data['base64'] ?? null,
        $data['qrcode']['base64'] ?? null,
        $data['qrcode']['base64Qr'] ?? null,
        $data['qrcode'] ?? null,
        $data['qr'] ?? null,
        $data['code'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') continue;
        $candidate = trim($candidate);
        if (stripos($candidate, 'data:image') === 0 || preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $candidate)) {
            if (strlen($candidate) > 300 && (stripos($candidate, 'data:image') === 0 || strpos($candidate, '/') !== false || strpos($candidate, '+') !== false)) {
                $qrBase64 = $candidate;
                continue;
            }
        }
        $qrText = $candidate;
    }

    return [
        'pairing_code' => $pairing !== '' ? $pairing : null,
        'qr_code_text' => $qrText !== '' ? $qrText : null,
        'qr_base64' => $qrBase64 !== '' ? $qrBase64 : null,
    ];
}

function evolution_get_instance(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM whatsapp_instances WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
