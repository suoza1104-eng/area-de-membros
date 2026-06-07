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
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_whatsapp_groups_group (group_id),
            KEY idx_whatsapp_groups_instance (instance_key),
            KEY idx_whatsapp_groups_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

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
        'events' => ['GROUP_PARTICIPANTS_UPDATE'],
        'headers' => new stdClass(),
        'base64' => false,
    ];

    return evolution_http('POST', '/webhook/set/' . rawurlencode($instanceKey), [
        'webhook' => $webhook,
    ]);
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

function evolution_find_active_blacklist(PDO $pdo, ?string $phone): ?array {
    $phone = evolution_clean_whatsapp_phone($phone);
    if ($phone === '') return null;

    $variants = [$phone];
    if (strpos($phone, '55') === 0 && strlen($phone) > 11) {
        $variants[] = substr($phone, 2);
    }
    if (strlen($phone) >= 11) {
        $variants[] = substr($phone, -11);
    }
    if (strlen($phone) >= 10) {
        $variants[] = substr($phone, -10);
    }
    $variants = array_values(array_unique(array_filter($variants)));

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

function evolution_refresh_group_name_if_needed(PDO $pdo, array $fields): void {
    $groupId = trim((string)($fields['group_id'] ?? ''));
    $instanceKey = trim((string)($fields['instance_key'] ?? ''));
    if ($groupId === '' || $instanceKey === '') return;

    try {
        $st = $pdo->prepare("SELECT group_name FROM whatsapp_groups WHERE group_id = :gid LIMIT 1");
        $st->execute([':gid' => $groupId]);
        $current = trim((string)($st->fetchColumn() ?: ''));
        if ($current !== '') return;
    } catch (Throwable $e) {
        return;
    }

    $res = evolution_find_group_info($instanceKey, $groupId);
    if (!$res['ok']) return;

    $subject = evolution_extract_group_subject($res['data']);
    if ($subject === null || $subject === '') return;

    try {
        $st = $pdo->prepare("
            UPDATE whatsapp_groups
               SET group_name = :name,
                   instance_key = COALESCE(:inst, instance_key),
                   last_seen_at = NOW()
             WHERE group_id = :gid
             LIMIT 1
        ");
        $st->execute([
            ':name' => substr($subject, 0, 180),
            ':inst' => $instanceKey !== '' ? $instanceKey : null,
            ':gid' => $groupId,
        ]);
    } catch (Throwable $e) {}
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
    $phone = evolution_clean_whatsapp_phone($phone);
    if ($phone === '') return null;

    $variants = [$phone];
    if (strpos($phone, '55') === 0 && strlen($phone) > 11) {
        $variants[] = substr($phone, 2);
    }
    if (strlen($phone) >= 11) {
        $variants[] = substr($phone, -11);
    }
    if (strlen($phone) >= 10) {
        $variants[] = substr($phone, -10);
    }
    $variants = array_values(array_unique(array_filter($variants)));

    $cleanExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    $where = [];
    $params = [];
    foreach ($variants as $i => $variant) {
        $key = ':p' . $i;
        $where[] = "{$cleanExpr} = {$key}";
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

function evolution_process_group_event(PDO $pdo, int $logId, array $fields): array {
    $event = (string)($fields['interpreted_event'] ?? '');
    $tag = evolution_tag_for_interpreted_event($event);
    if ($tag === '') $tag = null;
    evolution_upsert_group($pdo, $fields);

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

    if ($userId <= 0) {
        $pdo->prepare("
            UPDATE whatsapp_webhook_raw_logs
               SET trigger_status = 'user_not_found', trigger_error = NULL
             WHERE id = :id
             LIMIT 1
        ")->execute([':id' => $logId]);
        evolution_record_group_event($pdo, $logId, $fields, null, $blacklist, $blacklist ? 'blacklist_detected_no_user' : 'user_not_found');
        return ['status' => $blacklist ? 'blacklist_detected_no_user' : 'user_not_found', 'user_id' => null, 'event' => $event, 'blacklisted' => (bool)$blacklist];
    }

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

    try {
        adicionar_tag($userId, $tag, 'whatsapp_group', $logId);
        disparar_webhooks($event, $userId, $extra);
        if ($blacklist) {
            adicionar_tag($userId, 'WHATSAPP_BLACKLIST_DETECTADO', 'whatsapp_blacklist', $logId);
            disparar_webhooks('WHATSAPP_BLACKLIST_DETECTADO', $userId, $extra);
        }
        $status = $blacklist ? 'blacklist_detected' : 'triggered';
        $pdo->prepare("
            UPDATE whatsapp_webhook_raw_logs
               SET user_id = :uid, trigger_status = :status, trigger_error = NULL
             WHERE id = :id
             LIMIT 1
        ")->execute([':uid' => $userId, ':status' => $status, ':id' => $logId]);
        evolution_record_group_event($pdo, $logId, $fields, $userId, $blacklist, $status);
        return ['status' => $status, 'user_id' => $userId, 'event' => $event, 'blacklisted' => (bool)$blacklist];
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
