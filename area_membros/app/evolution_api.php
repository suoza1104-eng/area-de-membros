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
            operational_role VARCHAR(30) NOT NULL DEFAULT 'spy',
            operational_roles VARCHAR(120) NOT NULL DEFAULT 'spy',
            role_priority INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
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
    foreach ([
        'operational_role' => "ALTER TABLE whatsapp_instances ADD COLUMN operational_role VARCHAR(30) NOT NULL DEFAULT 'spy' AFTER instance_token",
        'operational_roles' => "ALTER TABLE whatsapp_instances ADD COLUMN operational_roles VARCHAR(120) NOT NULL DEFAULT 'spy' AFTER operational_role",
        'role_priority' => "ALTER TABLE whatsapp_instances ADD COLUMN role_priority INT NOT NULL DEFAULT 100 AFTER operational_roles",
        'is_enabled' => "ALTER TABLE whatsapp_instances ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER role_priority",
    ] as $column => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM whatsapp_instances LIKE :c");
            $st->execute([':c' => $column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }
    try {
        $pdo->exec("
            UPDATE whatsapp_instances
               SET operational_roles=operational_role
             WHERE COALESCE(operational_roles,'')=''
                OR (operational_roles='spy' AND operational_role IN ('administrator','reserve'))
        ");
    } catch (Throwable $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            chave VARCHAR(120) NOT NULL PRIMARY KEY,
            valor LONGTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'whatsapp_number' => "ALTER TABLE admin_equipe ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER email",
        'whatsapp_blacklist_exempt' => "ALTER TABLE admin_equipe ADD COLUMN whatsapp_blacklist_exempt TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_number",
    ] as $column => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM admin_equipe LIKE :c");
            $st->execute([':c' => $column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_webhook_raw_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_ok TINYINT(1) NOT NULL DEFAULT 0,
            event_type VARCHAR(100) NULL,
            instance_key VARCHAR(120) NULL,
            group_id VARCHAR(160) NULL,
            action VARCHAR(60) NULL,
            participant_number VARCHAR(60) NULL,
            participant_phone VARCHAR(30) NULL,
            participant_id VARCHAR(120) NULL,
            author_id VARCHAR(120) NULL,
            interpreted_event VARCHAR(80) NULL,
            user_id INT NULL,
            trigger_status VARCHAR(30) NULL,
            trigger_error TEXT NULL,
            payload_raw LONGTEXT NOT NULL,
            headers_json TEXT NULL,
            source_ip VARCHAR(80) NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_wwrl_received (received_at),
            KEY idx_wwrl_event (event_type),
            KEY idx_wwrl_instance (instance_key),
            KEY idx_wwrl_group (group_id),
            KEY idx_wwrl_participant (participant_number),
            KEY idx_wwrl_phone (participant_phone),
            KEY idx_wwrl_user (user_id),
            KEY idx_wwrl_interpreted (interpreted_event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'participant_phone' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN participant_phone VARCHAR(30) NULL AFTER participant_number",
        'participant_id' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN participant_id VARCHAR(120) NULL AFTER participant_phone",
        'author_id' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN author_id VARCHAR(120) NULL AFTER participant_id",
        'interpreted_event' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN interpreted_event VARCHAR(80) NULL AFTER author_id",
        'user_id' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN user_id INT NULL AFTER interpreted_event",
        'trigger_status' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN trigger_status VARCHAR(30) NULL AFTER user_id",
        'trigger_error' => "ALTER TABLE whatsapp_webhook_raw_logs ADD COLUMN trigger_error TEXT NULL AFTER trigger_status",
    ];
    foreach ($columns as $column => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM whatsapp_webhook_raw_logs LIKE :c");
            $st->execute([':c' => $column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {}
    }

    foreach ([
        'idx_wwrl_phone' => 'participant_phone',
        'idx_wwrl_user' => 'user_id',
        'idx_wwrl_interpreted' => 'interpreted_event',
    ] as $idx => $column) {
        try {
            $st = $pdo->prepare("SHOW INDEX FROM whatsapp_webhook_raw_logs WHERE Key_name = :k");
            $st->execute([':k' => $idx]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE whatsapp_webhook_raw_logs ADD KEY {$idx} ({$column})");
            }
        } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(160) NOT NULL,
            instance_key VARCHAR(120) NULL,
            group_name VARCHAR(180) NULL,
            codigo_turma VARCHAR(100) NULL,
            picture_url TEXT NULL,
            is_ignored TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_whatsapp_groups_group (group_id),
            KEY idx_whatsapp_groups_instance (instance_key),
            KEY idx_whatsapp_groups_ignored (is_ignored),
            KEY idx_whatsapp_groups_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'codigo_turma' => "ALTER TABLE whatsapp_groups ADD COLUMN codigo_turma VARCHAR(100) NULL AFTER group_name",
        'picture_url' => "ALTER TABLE whatsapp_groups ADD COLUMN picture_url TEXT NULL AFTER group_name",
        'is_ignored' => "ALTER TABLE whatsapp_groups ADD COLUMN is_ignored TINYINT(1) NOT NULL DEFAULT 0 AFTER picture_url",
    ] as $column => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM whatsapp_groups LIKE :c");
            $st->execute([':c' => $column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {}
    }
    try {
        $st = $pdo->prepare("SHOW INDEX FROM whatsapp_groups WHERE Key_name = 'idx_whatsapp_groups_ignored'");
        $st->execute();
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE whatsapp_groups ADD KEY idx_whatsapp_groups_ignored (is_ignored)");
        }
    } catch (Throwable $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_blacklist_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_number VARCHAR(30) NOT NULL,
            reason TEXT NULL,
            origem VARCHAR(80) NOT NULL DEFAULT 'manual',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wbl_phone (phone_number),
            KEY idx_wbl_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_trusted_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            phone_number VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_whatsapp_trusted_phone (phone_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ((int)get_setting('whatsapp_trusted_team_migrated', '0') !== 1) {
        try {
            $pdo->exec("
                INSERT IGNORE INTO whatsapp_trusted_numbers (name, phone_number, created_at)
                SELECT nome, REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp_number,' ',''),'-',''),'(',''),')',''),'+',''),'.',''), NOW()
                  FROM admin_equipe
                 WHERE ativo=1
                   AND COALESCE(whatsapp_blacklist_exempt,1)=1
                   AND COALESCE(whatsapp_number,'')<>''
            ");
            set_setting('whatsapp_trusted_team_migrated', '1');
        } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_blacklist_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raw_log_id INT NOT NULL,
            blacklist_id INT NULL,
            user_id INT NULL,
            instance_key VARCHAR(120) NULL,
            group_id VARCHAR(160) NULL,
            participant_phone VARCHAR(30) NULL,
            participant_id VARCHAR(120) NULL,
            is_team_protected TINYINT(1) NOT NULL DEFAULT 0,
            removal_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            removal_http_status INT NULL,
            removal_response LONGTEXT NULL,
            notification_status VARCHAR(30) NULL,
            notification_recipients INT NOT NULL DEFAULT 0,
            notification_sent INT NOT NULL DEFAULT 0,
            notification_errors LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wba_raw_log (raw_log_id),
            KEY idx_wba_created (created_at),
            KEY idx_wba_phone (participant_phone),
            KEY idx_wba_user (user_id),
            KEY idx_wba_removal (removal_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raw_log_id INT NOT NULL,
            event_type VARCHAR(100) NULL,
            instance_key VARCHAR(120) NULL,
            group_id VARCHAR(160) NULL,
            action VARCHAR(60) NULL,
            interpreted_event VARCHAR(80) NULL,
            participant_phone VARCHAR(30) NULL,
            participant_id VARCHAR(120) NULL,
            author_id VARCHAR(120) NULL,
            user_id INT NULL,
            blacklist_id INT NULL,
            is_blacklisted TINYINT(1) NOT NULL DEFAULT 0,
            trigger_status VARCHAR(30) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wge_raw_log (raw_log_id),
            KEY idx_wge_created (created_at),
            KEY idx_wge_group (group_id),
            KEY idx_wge_phone (participant_phone),
            KEY idx_wge_user (user_id),
            KEY idx_wge_blacklisted (is_blacklisted),
            KEY idx_wge_event (interpreted_event)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(160) NOT NULL,
            instance_key VARCHAR(120) NULL,
            participant_phone VARCHAR(30) NULL,
            participant_id VARCHAR(120) NULL,
            user_id INT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 1,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wgm_group_participant (group_id, participant_id),
            KEY idx_wgm_group (group_id),
            KEY idx_wgm_phone (participant_phone),
            KEY idx_wgm_user (user_id),
            KEY idx_wgm_current (is_current),
            KEY idx_wgm_synced (synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function evolution_guess_turma_code_from_group_name(?string $name): ?string {
    $name = trim((string)$name);
    if ($name === '') return null;
    if (preg_match('/\b(\d{2})[\/\-.](\d{2})[\/\-.](\d{2,4})\b/', $name, $m)) {
        $yy = (string)$m[3];
        if (strlen($yy) === 4) $yy = substr($yy, -2);
        return $m[1] . $m[2] . $yy;
    }
    return null;
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
    $webhook = [
        'enabled' => true,
        'url' => $webhookUrl,
        'events' => ['GROUP_PARTICIPANTS_UPDATE', 'MESSAGES_UPSERT'],
        'headers' => new stdClass(),
        'base64' => false,
    ];

    return evolution_http('POST', '/webhook/set/' . rawurlencode($instanceKey), [
        'webhook' => $webhook,
    ]);
}

function evolution_select_action_instance(PDO $pdo, ?string $preferredInstance = null): string {
    $preferredInstance = trim((string)$preferredInstance);
    try {
        $st = $pdo->query("
            SELECT instance_key
              FROM whatsapp_instances
             WHERE is_enabled = 1
               AND status = 'CONNECTED'
               AND (
                    FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0
                    OR FIND_IN_SET('reserve', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0
               )
             ORDER BY
               CASE WHEN FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 THEN 0 ELSE 1 END,
               role_priority ASC, id ASC
             LIMIT 1
        ");
        $selected = trim((string)($st->fetchColumn() ?: ''));
        if ($selected !== '') return $selected;
    } catch (Throwable $e) {}
    return $preferredInstance;
}

function evolution_select_messaging_instance(PDO $pdo, ?string $preferredInstance = null): string {
    $preferredInstance = trim((string)$preferredInstance);
    if ($preferredInstance !== '') {
        try {
            $st = $pdo->prepare("SELECT instance_key FROM whatsapp_instances WHERE instance_key=:key AND is_enabled=1 AND status='CONNECTED' LIMIT 1");
            $st->execute([':key' => $preferredInstance]);
            $found = trim((string)($st->fetchColumn() ?: ''));
            if ($found !== '') return $found;
        } catch (Throwable $e) {}
    }
    try {
        return trim((string)($pdo->query("
            SELECT instance_key
              FROM whatsapp_instances
             WHERE is_enabled=1 AND status='CONNECTED'
             ORDER BY
               CASE
                 WHEN FIND_IN_SET('spy', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 THEN 0
                 WHEN FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 THEN 1
                 ELSE 2
               END,
               role_priority, id
             LIMIT 1
        ")->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return $preferredInstance;
    }
}

function evolution_find_webhook(string $instanceKey): array {
    return evolution_http('GET', '/webhook/find/' . rawurlencode($instanceKey));
}

function evolution_find_group_info(string $instanceKey, string $groupId): array {
    $instanceKey = trim($instanceKey);
    $groupId = trim($groupId);
    if ($instanceKey === '' || $groupId === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'raw' => '',
            'error' => 'Instancia ou grupo vazio.',
        ];
    }

    return evolution_http(
        'GET',
        '/group/findGroupInfos/' . rawurlencode($instanceKey) . '?groupJid=' . rawurlencode($groupId)
    );
}

function evolution_fetch_all_groups(string $instanceKey, bool $getParticipants = false): array {
    $instanceKey = trim($instanceKey);
    if ($instanceKey === '') {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'raw' => '',
            'error' => 'Instancia vazia.',
        ];
    }

    return evolution_http(
        'GET',
        '/group/fetchAllGroups/' . rawurlencode($instanceKey) . '?getParticipants=' . ($getParticipants ? 'true' : 'false')
    );
}

function evolution_extract_group_subject($data): ?string {
    if (!is_array($data)) return null;

    $candidates = [
        $data['group']['subject'] ?? null,
        $data['subject'] ?? null,
        $data['data']['subject'] ?? null,
        $data['response']['subject'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $subject = trim((string)$candidate);
        if ($subject !== '') return $subject;
    }

    foreach ($data as $item) {
        if (!is_array($item)) continue;
        $subject = evolution_extract_group_subject($item);
        if ($subject !== null) return $subject;
    }

    return null;
}

function evolution_extract_group_picture_url($data): ?string {
    if (!is_array($data)) return null;

    $candidates = [
        $data['group']['pictureUrl'] ?? null,
        $data['group']['picture_url'] ?? null,
        $data['pictureUrl'] ?? null,
        $data['picture_url'] ?? null,
        $data['data']['pictureUrl'] ?? null,
        $data['response']['pictureUrl'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $url = trim((string)$candidate);
        if ($url !== '') return $url;
    }

    foreach ($data as $item) {
        if (!is_array($item)) continue;
        $url = evolution_extract_group_picture_url($item);
        if ($url !== null) return $url;
    }

    return null;
}

function evolution_extract_group_rows($data): array {
    if (!is_array($data)) return [];

    $rows = [];
    $candidateLists = [
        $data,
        $data['groups'] ?? null,
        $data['data'] ?? null,
        $data['response'] ?? null,
    ];

    foreach ($candidateLists as $candidate) {
        if (!is_array($candidate)) continue;
        foreach ($candidate as $item) {
            if (!is_array($item)) continue;
            $id = trim((string)($item['id'] ?? $item['jid'] ?? $item['groupJid'] ?? ''));
            $subject = trim((string)($item['subject'] ?? $item['name'] ?? ''));
            $pictureUrl = trim((string)($item['pictureUrl'] ?? $item['picture_url'] ?? ''));
            if ($id !== '' && $subject !== '') {
                $rows[$id] = [
                    'subject' => $subject,
                    'picture_url' => $pictureUrl !== '' ? $pictureUrl : null,
                ];
            }
        }
    }

    return $rows;
}

function evolution_extract_group_participants_from_node($node): array {
    if (!is_array($node)) return [];

    $lists = [];
    foreach (['participants', 'participantsData', 'members', 'groupParticipants'] as $key) {
        if (isset($node[$key]) && is_array($node[$key])) {
            $lists[] = $node[$key];
        }
    }

    $participants = [];
    foreach ($lists as $list) {
        foreach ($list as $item) {
            $phone = '';
            $participantId = '';
            if (is_scalar($item)) {
                $participantId = (string)$item;
                $phone = evolution_clean_whatsapp_phone((string)$item);
            } elseif (is_array($item)) {
                $jid = $item['jid'] ?? $item['id'] ?? null;
                if (is_array($jid)) {
                    $phone = (string)($jid['phoneNumber'] ?? $jid['user'] ?? '');
                    $participantId = (string)($jid['id'] ?? $jid['_serialized'] ?? '');
                } else {
                    $participantId = (string)($item['id'] ?? $item['jid'] ?? $item['participant'] ?? $item['user'] ?? '');
                }
                if ($phone === '') {
                    $phone = (string)($item['phoneNumber'] ?? $item['number'] ?? $item['phone'] ?? $item['participant'] ?? $participantId);
                }
            }

            $cleanPhone = evolution_clean_whatsapp_phone($phone);
            $cleanId = evolution_clean_whatsapp_id($participantId);
            if ($cleanId === '' && $cleanPhone !== '') {
                $cleanId = $cleanPhone . '@s.whatsapp.net';
            }
            if ($cleanId === '' && $cleanPhone === '') continue;
            $participants[$cleanId !== '' ? $cleanId : $cleanPhone] = [
                'participant_phone' => $cleanPhone !== '' ? $cleanPhone : null,
                'participant_id' => $cleanId !== '' ? $cleanId : null,
            ];
        }
    }

    return array_values($participants);
}

function evolution_extract_group_participants($data, string $targetGroupId = ''): array {
    if (!is_array($data)) return [];
    $targetGroupId = trim($targetGroupId);

    $direct = evolution_extract_group_participants_from_node($data);
    if ($direct) return $direct;

    foreach ($data as $item) {
        if (!is_array($item)) continue;
        $itemGroupId = trim((string)($item['id'] ?? $item['jid'] ?? $item['groupJid'] ?? $item['group_id'] ?? ''));
        if ($targetGroupId !== '' && $itemGroupId !== '' && $itemGroupId !== $targetGroupId) {
            continue;
        }
        $found = evolution_extract_group_participants($item, $targetGroupId);
        if ($found) return $found;
    }

    return [];
}

function evolution_extract_raw_event_fields(array $payload): array {
    $eventType = (string)($payload['event'] ?? $payload['eventType'] ?? $payload['type'] ?? '');
    $instance = (string)($payload['instance'] ?? $payload['instanceName'] ?? $payload['instance_key'] ?? '');
    $data = $payload['data'] ?? [];
    if (!is_array($data)) $data = [];

    $groupId = (string)($data['id'] ?? $data['groupId'] ?? $data['remoteJid'] ?? $data['jid'] ?? $payload['groupId'] ?? '');
    $action = (string)($data['action'] ?? $payload['action'] ?? '');

    $participant = '';
    $participantId = '';
    $participantPhone = '';
    $participants = $data['participants'] ?? $data['participant'] ?? $payload['participants'] ?? null;
    if (is_array($participants)) {
        $first = reset($participants);
        if (is_scalar($first)) $participant = (string)$first;
        elseif (is_array($first)) {
            $participantPhone = (string)($first['phoneNumber'] ?? $first['number'] ?? '');
            $participantId = (string)($first['id'] ?? $first['jid'] ?? '');
            $participant = $participantPhone !== '' ? $participantPhone : $participantId;
        }
    } elseif (is_scalar($participants)) {
        $participant = (string)$participants;
    }
    if ($participant === '') {
        $participantsData = $data['participantsData'] ?? [];
        if (is_array($participantsData)) {
            $firstData = reset($participantsData);
            if (is_array($firstData)) {
                $jid = $firstData['jid'] ?? [];
                if (is_array($jid)) {
                    $participantPhone = (string)($jid['phoneNumber'] ?? '');
                    $participantId = (string)($jid['id'] ?? '');
                    $participant = $participantPhone !== '' ? $participantPhone : $participantId;
                }
                if ($participant === '') {
                    $participant = (string)($firstData['phoneNumber'] ?? '');
                }
            }
        }
    }
    if ($participant === '') {
        $participant = (string)($data['participant'] ?? $data['phoneNumber'] ?? $data['number'] ?? $payload['participant'] ?? '');
    }
    if ($participantPhone === '') {
        $participantPhone = $participant;
    }
    if ($participantId === '' && strpos($participant, '@lid') !== false) {
        $participantId = $participant;
    }

    $authorId = (string)($data['author'] ?? $payload['author'] ?? '');
    $cleanPhone = evolution_clean_whatsapp_phone($participantPhone);
    $cleanParticipantId = evolution_clean_whatsapp_id($participantId);
    $cleanAuthorId = evolution_clean_whatsapp_id($authorId);
    $interpreted = evolution_interpret_group_participant_action($action, $cleanParticipantId, $cleanAuthorId);

    return [
        'event_type' => $eventType !== '' ? $eventType : null,
        'instance_key' => $instance !== '' ? $instance : null,
        'group_id' => $groupId !== '' ? $groupId : null,
        'action' => $action !== '' ? $action : null,
        'participant_number' => $participant !== '' ? preg_replace('/[^A-Za-z0-9@._-]+/', '', $participant) : null,
        'participant_phone' => $cleanPhone !== '' ? $cleanPhone : null,
        'participant_id' => $cleanParticipantId !== '' ? $cleanParticipantId : null,
        'author_id' => $cleanAuthorId !== '' ? $cleanAuthorId : null,
        'interpreted_event' => $interpreted,
    ];
}

function evolution_extract_raw_event_fields_all(array $payload): array {
    $data = $payload['data'] ?? [];
    if (!is_array($data)) $data = [];

    $candidates = [];
    $participants = $data['participants'] ?? $data['participant'] ?? $payload['participants'] ?? null;
    if (is_array($participants)) {
        foreach ($participants as $participant) {
            if (is_scalar($participant) || is_array($participant)) $candidates[] = $participant;
        }
    } elseif (is_scalar($participants) && trim((string)$participants) !== '') {
        $candidates[] = $participants;
    }

    if (!$candidates && !empty($data['participantsData']) && is_array($data['participantsData'])) {
        foreach ($data['participantsData'] as $participant) {
            if (is_array($participant)) $candidates[] = $participant;
        }
    }

    if (!$candidates) return [evolution_extract_raw_event_fields($payload)];

    $all = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        if (is_array($candidate) && isset($candidate['jid']) && is_array($candidate['jid'])) {
            $candidate = [
                'phoneNumber' => $candidate['jid']['phoneNumber'] ?? $candidate['phoneNumber'] ?? '',
                'id' => $candidate['jid']['id'] ?? $candidate['id'] ?? '',
            ];
        }
        $copy = $payload;
        $copyData = $data;
        $copyData['participants'] = [$candidate];
        unset($copyData['participant'], $copyData['participantsData']);
        $copy['data'] = $copyData;
        unset($copy['participants'], $copy['participant']);

        $fields = evolution_extract_raw_event_fields($copy);
        $key = implode('|', [
            (string)($fields['participant_phone'] ?? ''),
            (string)($fields['participant_id'] ?? ''),
            (string)($fields['participant_number'] ?? ''),
        ]);
        if ($key === '||' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $all[] = $fields;
    }

    return $all ?: [evolution_extract_raw_event_fields($payload)];
}

function evolution_clean_whatsapp_phone(?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '[object Object]') return '';
    $value = preg_replace('/@.*$/', '', $value) ?? $value;
    return preg_replace('/\D+/', '', $value) ?? '';
}

function evolution_clean_whatsapp_id(?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '[object Object]') return '';
    return preg_replace('/[^A-Za-z0-9@._-]+/', '', $value) ?? '';
}

function evolution_interpret_group_participant_action(?string $action, ?string $participantId, ?string $authorId): ?string {
    $action = strtolower(trim((string)$action));
    $participantId = trim((string)$participantId);
    $authorId = trim((string)$authorId);

    if ($action === 'add') return 'WHATSAPP_GRUPO_ENTROU';
    if ($action === 'remove') {
        if ($participantId !== '' && $authorId !== '' && $participantId === $authorId) {
            return 'WHATSAPP_GRUPO_SAIU';
        }
        return 'WHATSAPP_GRUPO_REMOVIDO_ADMIN';
    }
    if ($action === 'promote') return 'WHATSAPP_GRUPO_PROMOVIDO_ADMIN';
    if ($action === 'demote') return 'WHATSAPP_GRUPO_REBAIXADO_ADMIN';
    return null;
}

function evolution_tag_for_interpreted_event(?string $event): ?string {
    $event = trim((string)$event);
    return in_array($event, [
        'WHATSAPP_GRUPO_ENTROU',
        'WHATSAPP_GRUPO_SAIU',
        'WHATSAPP_GRUPO_REMOVIDO_ADMIN',
    ], true) ? $event : null;
}

function evolution_phone_variants(?string $phone): array {
    $phone = evolution_clean_whatsapp_phone($phone);
    if ($phone === '') return [];

    $variants = [$phone];
    if (strpos($phone, '55') === 0 && strlen($phone) > 11) {
        $withoutCountry = substr($phone, 2);
        $variants[] = $withoutCountry;
        if (strlen($withoutCountry) >= 11) {
            $variants[] = substr($withoutCountry, -11);
        }
        if (strlen($withoutCountry) >= 10) {
            $variants[] = substr($withoutCountry, -10);
        }
    } else {
        $variants[] = '55' . $phone;
    }
    if (strlen($phone) >= 11) {
        $variants[] = substr($phone, -11);
    }
    if (strlen($phone) >= 10) {
        $variants[] = substr($phone, -10);
    }

    $expanded = $variants;
    foreach ($variants as $variant) {
        $variant = evolution_clean_whatsapp_phone($variant);
        if ($variant === '') continue;

        $local = $variant;
        if (strpos($local, '55') === 0 && strlen($local) > 11) {
            $local = substr($local, 2);
        }

        // Brasil: alguns provedores entregam celular sem o nono digito.
        // Compara tanto DDD+8 digitos quanto DDD+9+8 digitos.
        if (strlen($local) === 10) {
            $withNine = substr($local, 0, 2) . '9' . substr($local, 2);
            $expanded[] = $withNine;
            $expanded[] = '55' . $withNine;
        } elseif (strlen($local) === 11 && substr($local, 2, 1) === '9') {
            $withoutNine = substr($local, 0, 2) . substr($local, 3);
            $expanded[] = $withoutNine;
            $expanded[] = '55' . $withoutNine;
        }
    }

    $variants = $expanded;
    return array_values(array_unique(array_filter($variants)));
}

function evolution_find_active_blacklist(PDO $pdo, ?string $phone): ?array {
    $variants = evolution_phone_variants($phone);
    if (!$variants) return null;

    $where = [];
    $params = [];
    foreach ($variants as $i => $variant) {
        $key = ':p' . $i;
        $where[] = "phone_number = {$key}";
        $params[$key] = $variant;
    }
    if (!$where) return null;

    try {
        $st = $pdo->prepare("
            SELECT *
              FROM whatsapp_blacklist_numbers
             WHERE is_active = 1
               AND (" . implode(' OR ', $where) . ")
             ORDER BY id DESC
             LIMIT 1
        ");
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && $row ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function evolution_blacklist_default_message(): string {
    return "🚨 *AVISO - LISTA DE FRAUDE*\n\n"
        . "Um número da Lista de fraude entrou em um grupo monitorado e foi removido.\n\n"
        . "*Número:* {{numero}}\n"
        . "*Grupo:* {{grupo_nome}}\n"
        . "*Motivo:* {{motivo_blacklist}}\n"
        . "*Aluno identificado:* {{aluno_identificado}}\n"
        . "*Nome:* {{aluno_nome}}\n"
        . "*E-mail:* {{aluno_email}}\n"
        . "*Turmas:* {{turmas}}\n"
        . "*Tags:* {{tags}}\n"
        . "*Primeira entrada:* {{primeira_entrada}}\n"
        . "*Data da ocorrência:* {{data_ocorrencia}}\n"
        . "*Remoção:* {{status_remocao}}";
}

function evolution_blacklist_get_config(): array {
    $recipientIds = json_decode((string)get_setting('whatsapp_blacklist_notify_team_ids', '[]'), true);
    if (!is_array($recipientIds)) $recipientIds = [];
    $groupIds = json_decode((string)get_setting('whatsapp_blacklist_notify_group_ids', '[]'), true);
    if (!is_array($groupIds)) $groupIds = [];
    return [
        'auto_remove' => (int)get_setting('whatsapp_blacklist_auto_remove', '1') === 1,
        'notify_enabled' => (int)get_setting('whatsapp_blacklist_notify_enabled', '1') === 1,
        'recipient_ids' => array_values(array_unique(array_filter(array_map('intval', $recipientIds)))),
        'group_ids' => array_values(array_unique(array_filter(array_map('strval', $groupIds)))),
        'message_template' => (string)get_setting('whatsapp_blacklist_message_template', evolution_blacklist_default_message()),
    ];
}

function evolution_blacklist_set_config(array $data): void {
    $recipientIds = $data['recipient_ids'] ?? [];
    if (!is_array($recipientIds)) $recipientIds = [];
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
    $groupIds = $data['group_ids'] ?? [];
    if (!is_array($groupIds)) $groupIds = [];
    $groupIds = array_values(array_unique(array_filter(array_map('strval', $groupIds))));
    set_setting('whatsapp_blacklist_auto_remove', !empty($data['auto_remove']) ? '1' : '0');
    set_setting('whatsapp_blacklist_notify_enabled', !empty($data['notify_enabled']) ? '1' : '0');
    set_setting('whatsapp_blacklist_notify_team_ids', json_encode($recipientIds));
    set_setting('whatsapp_blacklist_notify_group_ids', json_encode($groupIds));
    set_setting(
        'whatsapp_blacklist_message_template',
        trim((string)($data['message_template'] ?? '')) ?: evolution_blacklist_default_message()
    );
}

function evolution_is_team_protected_phone(PDO $pdo, ?string $phone): bool {
    $variants = evolution_phone_variants($phone);
    if (!$variants) return false;
    $where = [];
    $params = [];
    $cleanExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_number,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    foreach ($variants as $i => $variant) {
        $key = ':tp' . $i;
        $where[] = "({$cleanExpr} = {$key} OR CONCAT('55', {$cleanExpr}) = {$key})";
        $params[$key] = $variant;
    }
    try {
        $st = $pdo->prepare("
            SELECT id
              FROM whatsapp_trusted_numbers
             WHERE (" . implode(' OR ', $where) . ")
             LIMIT 1
        ");
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function evolution_remove_group_participant(string $instanceKey, string $groupId, string $participant): array {
    $instanceKey = trim($instanceKey);
    $groupId = trim($groupId);
    $participant = trim($participant);
    if ($instanceKey === '' || $groupId === '' || $participant === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '', 'error' => 'Instancia, grupo ou participante vazio.'];
    }
    return evolution_http('POST', '/group/updateParticipant/' . rawurlencode($instanceKey), [
        'groupJid' => $groupId,
        'action' => 'remove',
        'participants' => [$participant],
    ]);
}

function evolution_remove_group_participant_with_failover(PDO $pdo, string $groupId, string $participant): array {
    $keys = [];
    try {
        $rows = $pdo->query("
            SELECT instance_key
              FROM whatsapp_instances
             WHERE is_enabled=1
               AND status='CONNECTED'
               AND (
                    FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0
                    OR FIND_IN_SET('reserve', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0
               )
             ORDER BY
               CASE WHEN FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 THEN 0 ELSE 1 END,
               role_priority, id
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $key) {
            $key = trim((string)$key);
            if ($key !== '') $keys[] = $key;
        }
    } catch (Throwable $e) {}
    $last = ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '', 'error' => 'Nenhuma instância administradora ou reserva disponível.'];
    foreach (array_values(array_unique($keys)) as $instanceKey) {
        $last = evolution_remove_group_participant($instanceKey, $groupId, $participant);
        $last['instance_key'] = $instanceKey;
        if (!empty($last['ok'])) return $last;
    }
    return $last;
}

function evolution_send_text(string $instanceKey, string $number, string $text): array {
    $instanceKey = trim($instanceKey);
    $number = evolution_clean_whatsapp_phone($number);
    $text = trim($text);
    if ($instanceKey === '' || $number === '' || $text === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '', 'error' => 'Instancia, destinatario ou mensagem vazios.'];
    }
    return evolution_http('POST', '/message/sendText/' . rawurlencode($instanceKey), [
        'number' => $number,
        'text' => $text,
    ]);
}

function evolution_blacklist_contact_context(PDO $pdo, array $fields, ?array $user, array $blacklist): array {
    $userId = (int)($user['id'] ?? 0);
    $groupId = trim((string)($fields['group_id'] ?? ''));
    $groupName = '';
    $firstEntry = '';
    $tags = [];
    $turmas = [];

    try {
        $st = $pdo->prepare("SELECT group_name FROM whatsapp_groups WHERE group_id = :gid LIMIT 1");
        $st->execute([':gid' => $groupId]);
        $groupName = trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {}

    if ($userId > 0) {
        try {
            $st = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT t.nome ORDER BY t.nome SEPARATOR ', ') FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = :uid");
            $st->execute([':uid' => $userId]);
            $tagText = trim((string)($st->fetchColumn() ?: ''));
            if ($tagText !== '') $tags[] = $tagText;
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT codigo_turma ORDER BY codigo_turma SEPARATOR ', ') FROM inscricao_logs WHERE user_id = :uid AND codigo_turma IS NOT NULL AND codigo_turma <> ''");
            $st->execute([':uid' => $userId]);
            $turmaText = trim((string)($st->fetchColumn() ?: ''));
            if ($turmaText !== '') $turmas[] = $turmaText;
        } catch (Throwable $e) {}
        $currentTurma = trim((string)($user['codigo_turma'] ?? $user['turma_codigo'] ?? ''));
        if ($currentTurma !== '') $turmas[] = $currentTurma;
        try {
            $st = $pdo->prepare("SELECT MIN(created_at) FROM whatsapp_group_events WHERE user_id = :uid AND interpreted_event = 'WHATSAPP_GRUPO_ENTROU'");
            $st->execute([':uid' => $userId]);
            $firstEntry = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}
    }

    if ($firstEntry === '') {
        $variants = evolution_phone_variants((string)($fields['participant_phone'] ?? ''));
        if ($variants) {
            $ph = [];
            $params = [];
            foreach ($variants as $i => $variant) {
                $key = ':fe' . $i;
                $ph[] = $key;
                $params[$key] = $variant;
            }
            try {
                $st = $pdo->prepare("SELECT MIN(created_at) FROM whatsapp_group_events WHERE participant_phone IN (" . implode(',', $ph) . ") AND interpreted_event = 'WHATSAPP_GRUPO_ENTROU'");
                $st->execute($params);
                $firstEntry = trim((string)($st->fetchColumn() ?: ''));
            } catch (Throwable $e) {}
        }
    }
    if ($firstEntry === '') $firstEntry = date('Y-m-d H:i:s');

    $formatDate = static function (?string $value): string {
        if (!$value) return 'Não disponível';
        try { return (new DateTime($value))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $value; }
    };

    return [
        'numero' => (string)($fields['participant_phone'] ?? ''),
        'grupo_id' => $groupId,
        'grupo_nome' => $groupName !== '' ? $groupName : $groupId,
        'motivo_blacklist' => trim((string)($blacklist['reason'] ?? '')) ?: 'Não informado',
        'origem_blacklist' => trim((string)($blacklist['origem'] ?? '')) ?: 'Não informada',
        'aluno_identificado' => $userId > 0 ? 'Sim' : 'Não',
        'aluno_id' => $userId > 0 ? (string)$userId : 'Não identificado',
        'aluno_nome' => trim((string)($user['nome'] ?? '')) ?: 'Não identificado',
        'aluno_email' => trim((string)($user['email'] ?? '')) ?: 'Não identificado',
        'turmas' => $turmas ? implode(', ', array_values(array_unique($turmas))) : 'Nenhuma turma encontrada',
        'tags' => $tags ? implode(', ', array_values(array_unique($tags))) : 'Nenhuma tag encontrada',
        'primeira_entrada' => $formatDate($firstEntry),
        'data_ocorrencia' => date('d/m/Y H:i:s'),
        'status_remocao' => 'Pendente',
    ];
}

function evolution_render_template(string $template, array $context): string {
    $replace = [];
    foreach ($context as $key => $value) {
        $replace['{{' . $key . '}}'] = (string)$value;
    }
    return strtr($template, $replace);
}

function evolution_blacklist_notify_recipients(PDO $pdo, string $instanceKey, string $message, array $recipientIds): array {
    if (!$recipientIds) return ['recipients' => 0, 'sent' => 0, 'errors' => []];
    $ph = implode(',', array_fill(0, count($recipientIds), '?'));
    try {
        $st = $pdo->prepare("SELECT id, nome, whatsapp_number FROM admin_equipe WHERE ativo = 1 AND id IN ($ph) ORDER BY nome");
        $st->execute($recipientIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['recipients' => 0, 'sent' => 0, 'errors' => [$e->getMessage()]];
    }

    $sent = 0;
    $errors = [];
    foreach ($rows as $row) {
        $phone = evolution_clean_whatsapp_phone((string)($row['whatsapp_number'] ?? ''));
        if ($phone === '') {
            $errors[] = (string)($row['nome'] ?? ('Equipe #' . (int)$row['id'])) . ': sem WhatsApp cadastrado';
            continue;
        }
        $res = evolution_send_text($instanceKey, $phone, $message);
        if (!empty($res['ok'])) {
            $sent++;
        } else {
            $detail = trim((string)($res['raw'] ?? $res['error'] ?? 'Falha desconhecida'));
            $errors[] = (string)($row['nome'] ?? $phone) . ': ' . substr($detail, 0, 400);
        }
    }
    return ['recipients' => count($rows), 'sent' => $sent, 'errors' => $errors];
}

function evolution_blacklist_notify_groups(PDO $pdo, string $fallbackInstanceKey, string $message, array $groupIds): array {
    if (!$groupIds) return ['recipients' => 0, 'sent' => 0, 'errors' => []];
    $sent = 0;
    $errors = [];
    foreach ($groupIds as $groupId) {
        $groupId = trim((string)$groupId);
        if ($groupId === '') continue;
        $instanceKey = $fallbackInstanceKey;
        try {
            $st = $pdo->prepare("SELECT instance_key FROM whatsapp_groups WHERE group_id = :gid LIMIT 1");
            $st->execute([':gid' => $groupId]);
            $groupInstance = trim((string)($st->fetchColumn() ?: ''));
            if ($groupInstance !== '') $instanceKey = $groupInstance;
        } catch (Throwable $e) {}
        $res = evolution_http('POST', '/message/sendText/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'text' => $message,
        ]);
        if (!empty($res['ok'])) $sent++;
        else $errors[] = $groupId . ': ' . substr(trim((string)($res['raw'] ?? $res['error'] ?? 'Falha desconhecida')), 0, 400);
    }
    return ['recipients' => count($groupIds), 'sent' => $sent, 'errors' => $errors];
}

function evolution_handle_blacklist_entry(PDO $pdo, int $logId, array $fields, ?array $user, array $blacklist): array {
    $phone = (string)($fields['participant_phone'] ?? '');
    $protected = evolution_is_team_protected_phone($pdo, $phone);
    $eventInstanceKey = trim((string)($fields['instance_key'] ?? ''));
    $instanceKey = evolution_select_action_instance($pdo, $eventInstanceKey);
    $groupId = trim((string)($fields['group_id'] ?? ''));
    $participant = trim((string)($fields['participant_id'] ?? ''));
    if ($participant === '' || strpos($participant, '@lid') !== false) $participant = $phone;
    $cfg = evolution_blacklist_get_config();

    $removalStatus = $protected ? 'protected_team' : (!empty($cfg['auto_remove']) ? 'pending' : 'disabled');
    $removalResponse = null;
    $removalHttpStatus = null;
    if (!$protected && !empty($cfg['auto_remove'])) {
        $remove = evolution_remove_group_participant_with_failover($pdo, $groupId, $participant);
        $instanceKey = trim((string)($remove['instance_key'] ?? '')) ?: $instanceKey;
        $removalStatus = !empty($remove['ok']) ? 'removed' : 'error';
        $removalHttpStatus = (int)($remove['status'] ?? 0);
        $removalResponse = (string)($remove['raw'] ?? $remove['error'] ?? '');
    }

    $context = evolution_blacklist_contact_context($pdo, $fields, $user, $blacklist);
    $context['status_remocao'] = [
        'removed' => 'Removido automaticamente',
        'error' => 'Falha ao remover',
        'disabled' => 'Remoção automática desativada',
        'protected_team' => 'Ignorado: número protegido da equipe',
    ][$removalStatus] ?? $removalStatus;

    $notify = ['recipients' => 0, 'sent' => 0, 'errors' => []];
    $notificationStatus = $protected ? 'skipped_protected' : 'disabled';
    if (!$protected && !empty($cfg['notify_enabled'])) {
        $message = evolution_render_template((string)$cfg['message_template'], $context);
        $notify = evolution_blacklist_notify_recipients($pdo, $instanceKey, $message, $cfg['recipient_ids']);
        $groupNotify = evolution_blacklist_notify_groups($pdo, $instanceKey, $message, $cfg['group_ids']);
        $notify['recipients'] += $groupNotify['recipients'];
        $notify['sent'] += $groupNotify['sent'];
        $notify['errors'] = array_merge($notify['errors'], $groupNotify['errors']);
        $notificationStatus = $notify['recipients'] <= 0
            ? 'no_recipients'
            : ($notify['sent'] === $notify['recipients'] ? 'sent' : ($notify['sent'] > 0 ? 'partial' : 'error'));
    }

    try {
        $st = $pdo->prepare("
            INSERT INTO whatsapp_blacklist_actions
                (raw_log_id, blacklist_id, user_id, instance_key, group_id, participant_phone, participant_id,
                 is_team_protected, removal_status, removal_http_status, removal_response,
                 notification_status, notification_recipients, notification_sent, notification_errors, created_at, updated_at)
            VALUES
                (:raw_log_id, :blacklist_id, :user_id, :instance_key, :group_id, :participant_phone, :participant_id,
                 :is_team_protected, :removal_status, :removal_http_status, :removal_response,
                 :notification_status, :notification_recipients, :notification_sent, :notification_errors, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                is_team_protected = VALUES(is_team_protected),
                removal_status = VALUES(removal_status),
                removal_http_status = VALUES(removal_http_status),
                removal_response = VALUES(removal_response),
                notification_status = VALUES(notification_status),
                notification_recipients = VALUES(notification_recipients),
                notification_sent = VALUES(notification_sent),
                notification_errors = VALUES(notification_errors),
                updated_at = NOW()
        ");
        $st->execute([
            ':raw_log_id' => $logId,
            ':blacklist_id' => (int)($blacklist['id'] ?? 0) ?: null,
            ':user_id' => (int)($user['id'] ?? 0) ?: null,
            ':instance_key' => $instanceKey ?: null,
            ':group_id' => $groupId ?: null,
            ':participant_phone' => $phone ?: null,
            ':participant_id' => (string)($fields['participant_id'] ?? '') ?: null,
            ':is_team_protected' => $protected ? 1 : 0,
            ':removal_status' => $removalStatus,
            ':removal_http_status' => $removalHttpStatus,
            ':removal_response' => $removalResponse,
            ':notification_status' => $notificationStatus,
            ':notification_recipients' => (int)$notify['recipients'],
            ':notification_sent' => (int)$notify['sent'],
            ':notification_errors' => $notify['errors'] ? json_encode($notify['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {}

    return [
        'protected_team' => $protected,
        'removal_status' => $removalStatus,
        'notification_status' => $notificationStatus,
        'notification_sent' => (int)$notify['sent'],
    ];
}

function evolution_upsert_group(PDO $pdo, array $fields): void {
    $groupId = trim((string)($fields['group_id'] ?? ''));
    if ($groupId === '') return;

    try {
        $st = $pdo->prepare("
            INSERT INTO whatsapp_groups (group_id, instance_key, first_seen_at, last_seen_at)
            VALUES (:gid, :inst, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                instance_key = COALESCE(VALUES(instance_key), instance_key),
                last_seen_at = NOW()
        ");
        $st->execute([
            ':gid' => $groupId,
            ':inst' => $fields['instance_key'] ?? null,
        ]);
    } catch (Throwable $e) {}

    evolution_refresh_group_name_if_needed($pdo, $fields);
}

function evolution_sync_groups_for_instance(PDO $pdo, string $instanceKey): int {
    $instanceKey = trim($instanceKey);
    if ($instanceKey === '') return 0;

    $res = evolution_fetch_all_groups($instanceKey);
    if (!$res['ok']) return 0;

    $groups = evolution_extract_group_rows($res['data']);
    $updated = 0;
    foreach ($groups as $groupId => $group) {
        try {
            $groupName = substr((string)($group['subject'] ?? ''), 0, 180);
            $turmaCode = evolution_guess_turma_code_from_group_name($groupName);
            $st = $pdo->prepare("
                INSERT INTO whatsapp_groups (group_id, instance_key, group_name, codigo_turma, picture_url, first_seen_at, last_seen_at)
                VALUES (:gid, :inst, :name, :codigo_turma, :picture_url, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    instance_key = COALESCE(VALUES(instance_key), instance_key),
                    group_name = VALUES(group_name),
                    codigo_turma = COALESCE(whatsapp_groups.codigo_turma, VALUES(codigo_turma)),
                    picture_url = COALESCE(VALUES(picture_url), picture_url),
                    last_seen_at = NOW()
            ");
            $st->execute([
                ':gid' => $groupId,
                ':inst' => $instanceKey,
                ':name' => $groupName,
                ':codigo_turma' => $turmaCode,
                ':picture_url' => $group['picture_url'] ?? null,
            ]);
            $updated++;
        } catch (Throwable $e) {}
    }

    return $updated;
}

function evolution_sync_group_members(PDO $pdo, string $instanceKey, string $groupId): array {
    evolution_ensure_tables($pdo);
    $instanceKey = trim($instanceKey);
    $groupId = trim($groupId);
    if ($instanceKey === '' || $groupId === '') {
        return ['ok' => false, 'group_id' => $groupId, 'members' => 0, 'matched' => 0, 'error' => 'Instancia ou grupo vazio.'];
    }

    $res = evolution_find_group_info($instanceKey, $groupId);
    $participants = $res['ok'] ? evolution_extract_group_participants($res['data'], $groupId) : [];

    if (!$participants) {
        $all = evolution_fetch_all_groups($instanceKey, true);
        if ($all['ok']) {
            $participants = evolution_extract_group_participants($all['data'], $groupId);
        }
        if (!$participants && !$res['ok'] && !$all['ok']) {
            return ['ok' => false, 'group_id' => $groupId, 'members' => 0, 'matched' => 0, 'error' => (string)($res['error'] ?: $all['error'] ?: 'Falha ao consultar grupo.')];
        }
    }

    $syncAt = date('Y-m-d H:i:s');
    $seen = [];
    $matched = 0;

    foreach ($participants as $participant) {
        $phone = evolution_clean_whatsapp_phone((string)($participant['participant_phone'] ?? ''));
        $participantId = evolution_clean_whatsapp_id((string)($participant['participant_id'] ?? ''));
        if ($participantId === '' && $phone !== '') $participantId = $phone . '@s.whatsapp.net';
        if ($participantId === '' && $phone === '') continue;

        $seen[] = $participantId;
        $user = evolution_find_user_by_phone($pdo, $phone);
        $userId = $user ? (int)($user['id'] ?? 0) : 0;
        if ($userId > 0) {
            $matched++;
            try { adicionar_tag($userId, 'WHATSAPP_GRUPO_ENTROU', 'whatsapp_group_sync', null); } catch (Throwable $e) {}
        }

        $pdo->prepare("
            INSERT INTO whatsapp_group_members
                (group_id, instance_key, participant_phone, participant_id, user_id, is_current, first_seen_at, last_seen_at, synced_at)
            VALUES
                (:group_id, :instance_key, :participant_phone, :participant_id, :user_id, 1, NOW(), NOW(), :synced_at)
            ON DUPLICATE KEY UPDATE
                instance_key = VALUES(instance_key),
                participant_phone = COALESCE(VALUES(participant_phone), participant_phone),
                user_id = VALUES(user_id),
                is_current = 1,
                last_seen_at = NOW(),
                synced_at = VALUES(synced_at)
        ")->execute([
            ':group_id' => $groupId,
            ':instance_key' => $instanceKey,
            ':participant_phone' => $phone !== '' ? $phone : null,
            ':participant_id' => $participantId,
            ':user_id' => $userId > 0 ? $userId : null,
            ':synced_at' => $syncAt,
        ]);
    }

    $pdo->prepare("UPDATE whatsapp_group_members SET is_current = 0, synced_at = :sync_at WHERE group_id = :gid AND synced_at <> :sync_at")
        ->execute([':sync_at' => $syncAt, ':gid' => $groupId]);

    return ['ok' => true, 'group_id' => $groupId, 'members' => count($seen), 'matched' => $matched, 'error' => ''];
}

function evolution_sync_all_group_members(PDO $pdo, int $limit = 80): array {
    evolution_ensure_tables($pdo);
    $limit = max(1, min(300, $limit));

    $rows = $pdo->query("
        SELECT group_id, instance_key
          FROM whatsapp_groups
         WHERE COALESCE(is_ignored, 0) = 0
           AND group_id IS NOT NULL
           AND group_id <> ''
           AND instance_key IS NOT NULL
           AND instance_key <> ''
         ORDER BY last_seen_at DESC
         LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $processed = 0;
    $members = 0;
    $matched = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $res = evolution_sync_group_members($pdo, (string)($row['instance_key'] ?? ''), (string)($row['group_id'] ?? ''));
        $processed++;
        if (!empty($res['ok'])) {
            $members += (int)($res['members'] ?? 0);
            $matched += (int)($res['matched'] ?? 0);
        } else {
            $failed++;
        }
    }

    return ['processed' => $processed, 'members' => $members, 'matched' => $matched, 'failed' => $failed];
}

function evolution_refresh_group_name_if_needed(PDO $pdo, array $fields): void {
    $groupId = trim((string)($fields['group_id'] ?? ''));
    $instanceKey = trim((string)($fields['instance_key'] ?? ''));
    if ($groupId === '' || $instanceKey === '') return;

    try {
        $st = $pdo->prepare("SELECT group_name, codigo_turma, picture_url FROM whatsapp_groups WHERE group_id = :gid LIMIT 1");
        $st->execute([':gid' => $groupId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $currentName = trim((string)($row['group_name'] ?? ''));
        $currentTurma = trim((string)($row['codigo_turma'] ?? ''));
        $currentPicture = trim((string)($row['picture_url'] ?? ''));
        if ($currentName !== '' && $currentTurma !== '' && $currentPicture !== '') return;
    } catch (Throwable $e) {
        return;
    }

    $res = evolution_find_group_info($instanceKey, $groupId);
    if (!$res['ok']) return;

    $subject = evolution_extract_group_subject($res['data']);
    $pictureUrl = evolution_extract_group_picture_url($res['data']);
    $turmaCode = evolution_guess_turma_code_from_group_name($subject);
    if (($subject === null || $subject === '') && ($pictureUrl === null || $pictureUrl === '')) return;

    try {
        $st = $pdo->prepare("
            UPDATE whatsapp_groups
               SET group_name = COALESCE(:name, group_name),
                   codigo_turma = COALESCE(codigo_turma, :codigo_turma),
                   picture_url = COALESCE(:picture_url, picture_url),
                   instance_key = COALESCE(:inst, instance_key),
                   last_seen_at = NOW()
             WHERE group_id = :gid
             LIMIT 1
        ");
        $st->execute([
            ':name' => $subject !== null && $subject !== '' ? substr($subject, 0, 180) : null,
            ':codigo_turma' => $turmaCode,
            ':picture_url' => $pictureUrl !== null && $pictureUrl !== '' ? $pictureUrl : null,
            ':inst' => $instanceKey !== '' ? $instanceKey : null,
            ':gid' => $groupId,
        ]);
    } catch (Throwable $e) {}
}

function evolution_is_group_ignored(PDO $pdo, ?string $groupId): bool {
    $groupId = trim((string)$groupId);
    if ($groupId === '') return false;

    try {
        $st = $pdo->prepare("SELECT is_ignored FROM whatsapp_groups WHERE group_id = :gid LIMIT 1");
        $st->execute([':gid' => $groupId]);
        return (int)($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function evolution_record_group_event(PDO $pdo, int $logId, array $fields, ?int $userId, ?array $blacklist, string $status): void {
    try {
        $st = $pdo->prepare("
            INSERT INTO whatsapp_group_events
                (raw_log_id, event_type, instance_key, group_id, action, interpreted_event,
                 participant_phone, participant_id, author_id, user_id, blacklist_id, is_blacklisted,
                 trigger_status, created_at)
            VALUES
                (:raw_log_id, :event_type, :instance_key, :group_id, :action, :interpreted_event,
                 :participant_phone, :participant_id, :author_id, :user_id, :blacklist_id, :is_blacklisted,
                 :trigger_status, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                blacklist_id = VALUES(blacklist_id),
                is_blacklisted = VALUES(is_blacklisted),
                trigger_status = VALUES(trigger_status)
        ");
        $st->execute([
            ':raw_log_id' => $logId,
            ':event_type' => $fields['event_type'] ?? null,
            ':instance_key' => $fields['instance_key'] ?? null,
            ':group_id' => $fields['group_id'] ?? null,
            ':action' => $fields['action'] ?? null,
            ':interpreted_event' => $fields['interpreted_event'] ?? null,
            ':participant_phone' => $fields['participant_phone'] ?? null,
            ':participant_id' => $fields['participant_id'] ?? null,
            ':author_id' => $fields['author_id'] ?? null,
            ':user_id' => $userId ?: null,
            ':blacklist_id' => $blacklist ? (int)($blacklist['id'] ?? 0) : null,
            ':is_blacklisted' => $blacklist ? 1 : 0,
            ':trigger_status' => $status,
        ]);
    } catch (Throwable $e) {}
}

function evolution_find_user_by_phone(PDO $pdo, ?string $phone): ?array {
    $variants = evolution_phone_variants($phone);
    if (!$variants) return null;

    $cleanExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    $where = [];
    $params = [];
    foreach ($variants as $i => $variant) {
        $key = ':p' . $i;
        $where[] = "({$cleanExpr} = {$key} OR CONCAT('55', {$cleanExpr}) = {$key})";
        $params[$key] = $variant;
    }
    if (!$where) return null;

    try {
        $st = $pdo->prepare("SELECT * FROM users WHERE " . implode(' OR ', $where) . " ORDER BY id DESC LIMIT 1");
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && $row ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function evolution_resolve_group_event_phone(PDO $pdo, array $fields): array {
    if (trim((string)($fields['participant_phone'] ?? '')) !== '') return $fields;
    $participantId = trim((string)($fields['participant_id'] ?? ''));
    $groupId = trim((string)($fields['group_id'] ?? ''));
    if ($participantId === '') return $fields;
    try {
        $sql = "
            SELECT participant_phone
              FROM whatsapp_group_members
             WHERE participant_id = :pid
               AND participant_phone IS NOT NULL
               AND participant_phone <> ''
        ";
        $params = [':pid' => $participantId];
        if ($groupId !== '') {
            $sql .= " AND group_id = :gid";
            $params[':gid'] = $groupId;
        }
        $sql .= " ORDER BY is_current DESC, last_seen_at DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $phone = evolution_clean_whatsapp_phone((string)($st->fetchColumn() ?: ''));
        if ($phone !== '') $fields['participant_phone'] = $phone;
    } catch (Throwable $e) {}
    return $fields;
}

function evolution_process_group_event(PDO $pdo, int $logId, array $fields): array {
    $fields = evolution_resolve_group_event_phone($pdo, $fields);
    if (!empty($fields['participant_phone'])) {
        try {
            $pdo->prepare("UPDATE whatsapp_webhook_raw_logs SET participant_phone = :phone WHERE id = :id LIMIT 1")
                ->execute([':phone' => $fields['participant_phone'], ':id' => $logId]);
        } catch (Throwable $e) {}
    }
    $event = (string)($fields['interpreted_event'] ?? '');
    $tag = evolution_tag_for_interpreted_event($event);
    if ($tag === '') $tag = null;
    evolution_upsert_group($pdo, $fields);

    if (evolution_is_group_ignored($pdo, $fields['group_id'] ?? null)) {
        $pdo->prepare("UPDATE whatsapp_webhook_raw_logs SET trigger_status = 'ignored_group' WHERE id = :id LIMIT 1")
            ->execute([':id' => $logId]);
        evolution_record_group_event($pdo, $logId, $fields, null, null, 'ignored_group');
        return ['status' => 'ignored_group', 'user_id' => null, 'event' => $event];
    }

    if ($event === '' || $tag === null) {
        $pdo->prepare("UPDATE whatsapp_webhook_raw_logs SET trigger_status = 'ignored' WHERE id = :id LIMIT 1")
            ->execute([':id' => $logId]);
        evolution_record_group_event($pdo, $logId, $fields, null, null, 'ignored');
        return ['status' => 'ignored', 'user_id' => null, 'event' => $event];
    }

    $user = evolution_find_user_by_phone($pdo, (string)($fields['participant_phone'] ?? ''));
    $userId = $user ? (int)($user['id'] ?? 0) : 0;
    $blacklist = strtolower((string)($fields['action'] ?? '')) === 'add'
        ? evolution_find_active_blacklist($pdo, (string)($fields['participant_phone'] ?? ''))
        : null;
    $blacklistAction = null;
    if ($blacklist) {
        $blacklistAction = evolution_handle_blacklist_entry($pdo, $logId, $fields, $user, $blacklist);
    }
    $blacklistEffective = $blacklist && empty($blacklistAction['protected_team']);

    $extra = [
        'telefone' => $fields['participant_phone'] ?? null,
        'group_id' => $fields['group_id'] ?? null,
        'participant_id' => $fields['participant_id'] ?? null,
        'author_id' => $fields['author_id'] ?? null,
        'action_original' => $fields['action'] ?? null,
        'tipo_interpretado' => $event,
        'payload_log_id' => $logId,
        'origem' => 'evolution_group_participants_update',
    ];
    if ($blacklist) {
        $extra['blacklist'] = [
            'id' => (int)($blacklist['id'] ?? 0),
            'reason' => $blacklist['reason'] ?? null,
            'origem' => $blacklist['origem'] ?? null,
        ];
    }

    if ($userId <= 0) {
        $status = $blacklistEffective
            ? 'blacklist_detected_no_user'
            : ($blacklist ? 'blacklist_protected_team' : 'user_not_found');
        $pdo->prepare("
            UPDATE whatsapp_webhook_raw_logs
               SET trigger_status = :status, trigger_error = NULL
             WHERE id = :id
             LIMIT 1
        ")->execute([':status' => $status, ':id' => $logId]);
        evolution_record_group_event($pdo, $logId, $fields, null, $blacklist, $status);
        if ($blacklistEffective && function_exists('whatsapp_event_notifications_dispatch')) {
            whatsapp_event_notifications_dispatch($pdo, 'WHATSAPP_BLACKLIST_DETECTADO', [], $extra);
        }
        return [
            'status' => $status,
            'user_id' => null,
            'event' => $event,
            'blacklisted' => (bool)$blacklist,
            'blacklist_action' => $blacklistAction,
        ];
    }

    try {
        adicionar_tag($userId, $tag, 'whatsapp_group', $logId);
        disparar_webhooks($event, $userId, $extra);
        if ($blacklistEffective) {
            adicionar_tag($userId, 'WHATSAPP_BLACKLIST_DETECTADO', 'whatsapp_blacklist', $logId);
            disparar_webhooks('WHATSAPP_BLACKLIST_DETECTADO', $userId, $extra);
        }
        $status = $blacklistEffective ? 'blacklist_detected' : ($blacklist ? 'blacklist_protected_team' : 'triggered');
        $pdo->prepare("
            UPDATE whatsapp_webhook_raw_logs
               SET user_id = :uid, trigger_status = :status, trigger_error = NULL
             WHERE id = :id
             LIMIT 1
        ")->execute([':uid' => $userId, ':status' => $status, ':id' => $logId]);
        evolution_record_group_event($pdo, $logId, $fields, $userId, $blacklist, $status);
        return [
            'status' => $status,
            'user_id' => $userId,
            'event' => $event,
            'blacklisted' => (bool)$blacklist,
            'blacklist_action' => $blacklistAction,
        ];
    } catch (Throwable $e) {
        $pdo->prepare("
            UPDATE whatsapp_webhook_raw_logs
               SET user_id = :uid, trigger_status = 'error', trigger_error = :err
             WHERE id = :id
             LIMIT 1
        ")->execute([
            ':uid' => $userId,
            ':err' => substr($e->getMessage(), 0, 1000),
            ':id' => $logId,
        ]);
        evolution_record_group_event($pdo, $logId, $fields, $userId, $blacklist, 'error');
        return ['status' => 'error', 'user_id' => $userId, 'event' => $event, 'error' => $e->getMessage()];
    }
}

function evolution_backfill_unmatched_group_events(PDO $pdo, int $limit = 500): array {
    $limit = max(1, min(2000, $limit));
    $processed = 0;
    $matched = 0;
    $stillMissing = 0;

    try {
        $rows = $pdo->query("
            SELECT *
              FROM whatsapp_webhook_raw_logs
             WHERE token_ok = 1
               AND (user_id IS NULL OR user_id = 0)
             ORDER BY id DESC
             LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['processed' => 0, 'matched' => 0, 'still_missing' => 0, 'error' => $e->getMessage()];
    }

    foreach ($rows as $row) {
        $processed++;
        $payload = json_decode((string)($row['payload_raw'] ?? ''), true);
        $fields = is_array($payload) ? evolution_extract_raw_event_fields($payload) : [];

        foreach ([
            'event_type',
            'instance_key',
            'group_id',
            'action',
            'participant_number',
            'participant_phone',
            'participant_id',
            'author_id',
            'interpreted_event',
        ] as $key) {
            if (empty($fields[$key]) && isset($row[$key])) {
                $fields[$key] = $row[$key];
            }
        }

        $phone = (string)($fields['participant_phone'] ?? '');
        $user = evolution_find_user_by_phone($pdo, $phone);
        $userId = $user ? (int)($user['id'] ?? 0) : 0;
        $blacklist = strtolower((string)($fields['action'] ?? '')) === 'add'
            ? evolution_find_active_blacklist($pdo, $phone)
            : null;

        evolution_upsert_group($pdo, $fields);

        if (evolution_is_group_ignored($pdo, $fields['group_id'] ?? null)) {
            try {
                $pdo->prepare("UPDATE whatsapp_webhook_raw_logs SET trigger_status = 'ignored_group', trigger_error = NULL WHERE id = :id LIMIT 1")
                    ->execute([':id' => (int)$row['id']]);
            } catch (Throwable $e) {}
            evolution_record_group_event($pdo, (int)$row['id'], $fields, null, null, 'ignored_group');
            $stillMissing++;
            continue;
        }

        if ($userId <= 0) {
            $stillMissing++;
            evolution_record_group_event($pdo, (int)$row['id'], $fields, null, $blacklist, $blacklist ? 'blacklist_detected_no_user' : 'user_not_found');
            continue;
        }

        $matched++;
        try {
            $st = $pdo->prepare("
                UPDATE whatsapp_webhook_raw_logs
                   SET event_type = COALESCE(:event_type, event_type),
                       instance_key = COALESCE(:instance_key, instance_key),
                       group_id = COALESCE(:group_id, group_id),
                       action = COALESCE(:action, action),
                       participant_number = COALESCE(:participant_number, participant_number),
                       participant_phone = COALESCE(:participant_phone, participant_phone),
                       participant_id = COALESCE(:participant_id, participant_id),
                       author_id = COALESCE(:author_id, author_id),
                       interpreted_event = COALESCE(:interpreted_event, interpreted_event),
                       user_id = :user_id,
                       trigger_status = 'identified_backfill',
                       trigger_error = NULL
                 WHERE id = :id
                 LIMIT 1
            ");
            $st->execute([
                ':event_type' => $fields['event_type'] ?? null,
                ':instance_key' => $fields['instance_key'] ?? null,
                ':group_id' => $fields['group_id'] ?? null,
                ':action' => $fields['action'] ?? null,
                ':participant_number' => $fields['participant_number'] ?? null,
                ':participant_phone' => $fields['participant_phone'] ?? null,
                ':participant_id' => $fields['participant_id'] ?? null,
                ':author_id' => $fields['author_id'] ?? null,
                ':interpreted_event' => $fields['interpreted_event'] ?? null,
                ':user_id' => $userId,
                ':id' => (int)$row['id'],
            ]);
        } catch (Throwable $e) {}

        evolution_record_group_event($pdo, (int)$row['id'], $fields, $userId, $blacklist, $blacklist ? 'blacklist_detected_backfill' : 'identified_backfill');
    }

    return [
        'processed' => $processed,
        'matched' => $matched,
        'still_missing' => $stillMissing,
    ];
}

function evolution_apply_tags_to_identified_group_events(PDO $pdo, int $limit = 1000): array {
    $limit = max(1, min(5000, $limit));
    $processed = 0;
    $tagged = 0;
    $skipped = 0;

    try {
        $rows = $pdo->query("
            SELECT l.id, l.user_id, l.interpreted_event, l.participant_phone,
                   ge.is_blacklisted, g.is_ignored AS group_is_ignored
              FROM whatsapp_webhook_raw_logs l
              LEFT JOIN whatsapp_group_events ge ON ge.raw_log_id = l.id
              LEFT JOIN whatsapp_groups g ON g.group_id = l.group_id
             WHERE l.token_ok = 1
               AND l.user_id IS NOT NULL
               AND l.user_id > 0
               AND l.interpreted_event IS NOT NULL
               AND l.interpreted_event <> ''
               AND COALESCE(g.is_ignored, 0) = 0
             ORDER BY l.id DESC
             LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['processed' => 0, 'tagged' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
    }

    foreach ($rows as $row) {
        $processed++;
        $userId = (int)($row['user_id'] ?? 0);
        $event = (string)($row['interpreted_event'] ?? '');
        $tag = evolution_tag_for_interpreted_event($event);
        if ($userId <= 0 || $tag === null) {
            $skipped++;
            continue;
        }

        $ok = adicionar_tag($userId, $tag, 'whatsapp_group_backfill', (int)$row['id']);
        if ($ok) $tagged++;
        else $skipped++;

        if ((int)($row['is_blacklisted'] ?? 0) === 1) {
            if (adicionar_tag($userId, 'WHATSAPP_BLACKLIST_DETECTADO', 'whatsapp_blacklist_backfill', (int)$row['id'])) {
                $tagged++;
            }
        }
    }

    return [
        'processed' => $processed,
        'tagged' => $tagged,
        'skipped' => $skipped,
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
