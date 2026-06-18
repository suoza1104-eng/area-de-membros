<?php
declare(strict_types=1);

function whatsapp_event_notifications_ensure_tables(PDO $pdo): void {
    foreach ([
        'whatsapp_number' => "ALTER TABLE admin_equipe ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER email",
        'whatsapp_blacklist_exempt' => "ALTER TABLE admin_equipe ADD COLUMN whatsapp_blacklist_exempt TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_number",
    ] as $column => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM admin_equipe LIKE :column");
            $st->execute([':column' => $column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
        } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_event_notification_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            event_code VARCHAR(120) NOT NULL,
            instance_key VARCHAR(120) NULL,
            message_template LONGTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wenr_event_active (event_code, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_event_notification_rule_groups (
            rule_id INT NOT NULL,
            group_id VARCHAR(160) NOT NULL,
            PRIMARY KEY (rule_id, group_id),
            KEY idx_wenrg_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_event_notification_rule_team (
            rule_id INT NOT NULL,
            admin_equipe_id INT NOT NULL,
            PRIMARY KEY (rule_id, admin_equipe_id),
            KEY idx_wenrt_team (admin_equipe_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_event_notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rule_id INT NOT NULL,
            event_code VARCHAR(120) NOT NULL,
            user_id INT NULL,
            destination_type VARCHAR(20) NOT NULL,
            destination_id VARCHAR(180) NULL,
            destination_name VARCHAR(180) NULL,
            instance_key VARCHAR(120) NULL,
            message_text LONGTEXT NULL,
            status VARCHAR(30) NOT NULL,
            http_status INT NULL,
            response_body LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_wenl_rule (rule_id),
            KEY idx_wenl_event (event_code),
            KEY idx_wenl_status (status),
            KEY idx_wenl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function whatsapp_event_notifications_clean_phone(?string $value): string {
    return preg_replace('/\D+/', '', (string)$value) ?: '';
}

function whatsapp_event_notifications_http(string $instanceKey, string $number, string $message): array {
    $baseUrl = rtrim((string)get_setting('evolution_base_url', ''), '/');
    $apiKey = trim((string)get_setting('evolution_apikey', ''));
    $timeout = max(3, (int)get_setting('evolution_timeout_seconds', '20'));
    if ($baseUrl === '' || $apiKey === '') {
        return ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Evolution API nao configurada.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Extensao cURL indisponivel.'];
    }
    $instanceKey = trim($instanceKey);
    $number = trim($number);
    $message = trim($message);
    if ($instanceKey === '' || $number === '' || $message === '') {
        return ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Instancia, destino ou mensagem vazio.'];
    }

    $url = $baseUrl . '/message/sendText/' . rawurlencode($instanceKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POSTFIELDS => json_encode([
            'number' => $number,
            'text' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    return [
        'ok' => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'raw' => $raw,
        'error' => $error !== '' ? $error : ($status >= 400 ? 'HTTP ' . $status : ''),
    ];
}

function whatsapp_event_notifications_flatten(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) {
            $out += whatsapp_event_notifications_flatten($value, $path);
        } elseif (is_bool($value)) {
            $out[$path] = $value ? 'Sim' : 'Nao';
        } elseif ($value === null) {
            $out[$path] = '';
        } elseif (is_scalar($value)) {
            $out[$path] = (string)$value;
        }
    }
    return $out;
}

function whatsapp_event_notifications_context(PDO $pdo, string $eventCode, array $user, array $extra): array {
    $userId = (int)($user['id'] ?? 0);
    $userData = $user;
    if ($userId > 0) {
        try {
            $st = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $userId]);
            $full = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($full) && $full) $userData = array_merge($full, $user);
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT t.nome ORDER BY t.nome SEPARATOR ', ') FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = :uid");
            $st->execute([':uid' => $userId]);
            $userData['tags'] = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT codigo_turma ORDER BY codigo_turma SEPARATOR ', ') FROM inscricao_logs WHERE user_id = :uid AND codigo_turma IS NOT NULL AND codigo_turma <> ''");
            $st->execute([':uid' => $userId]);
            $userData['turmas'] = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
    }

    $context = [
        'evento' => $eventCode,
        'data_evento' => date('d/m/Y H:i:s'),
        'user' => $userData,
        'extra' => $extra,
    ];
    $flat = whatsapp_event_notifications_flatten($context);
    foreach ($userData as $key => $value) {
        if (is_scalar($value) || $value === null) $flat[(string)$key] = (string)($value ?? '');
    }
    return $flat;
}

function whatsapp_event_notifications_render(string $template, array $context, array $destination = []): string {
    $context = array_merge($context, whatsapp_event_notifications_flatten(['destino' => $destination]));
    $replace = [];
    foreach ($context as $key => $value) {
        $replace['{{' . $key . '}}'] = (string)$value;
    }
    return strtr($template, $replace);
}

function whatsapp_event_notifications_log(
    PDO $pdo,
    int $ruleId,
    string $eventCode,
    ?int $userId,
    string $destinationType,
    string $destinationId,
    string $destinationName,
    string $instanceKey,
    string $message,
    array $response
): void {
    try {
        $st = $pdo->prepare("
            INSERT INTO whatsapp_event_notification_logs
                (rule_id, event_code, user_id, destination_type, destination_id, destination_name,
                 instance_key, message_text, status, http_status, response_body, error_message, created_at)
            VALUES
                (:rule_id, :event_code, :user_id, :destination_type, :destination_id, :destination_name,
                 :instance_key, :message_text, :status, :http_status, :response_body, :error_message, NOW())
        ");
        $st->execute([
            ':rule_id' => $ruleId,
            ':event_code' => $eventCode,
            ':user_id' => $userId ?: null,
            ':destination_type' => $destinationType,
            ':destination_id' => $destinationId,
            ':destination_name' => $destinationName,
            ':instance_key' => $instanceKey,
            ':message_text' => $message,
            ':status' => !empty($response['ok']) ? 'sent' : 'error',
            ':http_status' => (int)($response['status'] ?? 0) ?: null,
            ':response_body' => (string)($response['raw'] ?? ''),
            ':error_message' => (string)($response['error'] ?? ''),
        ]);
    } catch (Throwable $e) {
        @error_log('whatsapp_event_notifications_log: ' . $e->getMessage());
    }
}

function whatsapp_event_notifications_dispatch(PDO $pdo, string $eventCode, array $user = [], array $extra = []): bool {
    static $running = false;
    if ($running) return false;
    $eventCode = trim($eventCode);
    if ($eventCode === '') return false;

    try {
        whatsapp_event_notifications_ensure_tables($pdo);
        $st = $pdo->prepare("SELECT * FROM whatsapp_event_notification_rules WHERE is_active = 1 AND event_code = :event ORDER BY id ASC");
        $st->execute([':event' => $eventCode]);
        $rules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        @error_log('whatsapp_event_notifications_dispatch setup: ' . $e->getMessage());
        return false;
    }
    if (!$rules) return false;

    $running = true;
    $sentAny = false;
    $context = whatsapp_event_notifications_context($pdo, $eventCode, $user, $extra);
    $userId = (int)($user['id'] ?? 0);

    try {
        foreach ($rules as $rule) {
            $ruleId = (int)$rule['id'];
            $template = (string)$rule['message_template'];
            $fallbackInstance = trim((string)($rule['instance_key'] ?? ''));

            $stGroups = $pdo->prepare("
                SELECT rg.group_id, g.group_name, g.instance_key
                  FROM whatsapp_event_notification_rule_groups rg
                  LEFT JOIN whatsapp_groups g ON g.group_id = rg.group_id
                 WHERE rg.rule_id = :rid
                 ORDER BY COALESCE(g.group_name, rg.group_id)
            ");
            $stGroups->execute([':rid' => $ruleId]);
            foreach ($stGroups->fetchAll(PDO::FETCH_ASSOC) ?: [] as $group) {
                $groupId = trim((string)$group['group_id']);
                $groupName = trim((string)($group['group_name'] ?? '')) ?: $groupId;
                $instanceKey = trim((string)($group['instance_key'] ?? '')) ?: $fallbackInstance;
                $message = whatsapp_event_notifications_render($template, $context, [
                    'tipo' => 'grupo',
                    'id' => $groupId,
                    'nome' => $groupName,
                ]);
                $response = whatsapp_event_notifications_http($instanceKey, $groupId, $message);
                whatsapp_event_notifications_log($pdo, $ruleId, $eventCode, $userId, 'group', $groupId, $groupName, $instanceKey, $message, $response);
                if (!empty($response['ok'])) $sentAny = true;
            }

            $stTeam = $pdo->prepare("
                SELECT e.id, e.nome, e.whatsapp_number
                  FROM whatsapp_event_notification_rule_team rt
                  JOIN admin_equipe e ON e.id = rt.admin_equipe_id
                 WHERE rt.rule_id = :rid
                   AND e.ativo = 1
                 ORDER BY e.nome
            ");
            $stTeam->execute([':rid' => $ruleId]);
            foreach ($stTeam->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
                $phone = whatsapp_event_notifications_clean_phone((string)($member['whatsapp_number'] ?? ''));
                $memberName = trim((string)($member['nome'] ?? '')) ?: ('Equipe #' . (int)$member['id']);
                $instanceKey = $fallbackInstance;
                if ($instanceKey === '') {
                    try {
                        $instanceKey = trim((string)($pdo->query("SELECT instance_key FROM whatsapp_instances WHERE status = 'CONNECTED' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: ''));
                    } catch (Throwable $e) {}
                }
                $message = whatsapp_event_notifications_render($template, $context, [
                    'tipo' => 'equipe',
                    'id' => (string)$member['id'],
                    'nome' => $memberName,
                    'telefone' => $phone,
                ]);
                $response = $phone !== ''
                    ? whatsapp_event_notifications_http($instanceKey, $phone, $message)
                    : ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Membro sem WhatsApp cadastrado.'];
                whatsapp_event_notifications_log($pdo, $ruleId, $eventCode, $userId, 'team', (string)$member['id'], $memberName, $instanceKey, $message, $response);
                if (!empty($response['ok'])) $sentAny = true;
            }
        }
    } catch (Throwable $e) {
        @error_log('whatsapp_event_notifications_dispatch: ' . $e->getMessage());
    } finally {
        $running = false;
    }
    return $sentAny;
}
