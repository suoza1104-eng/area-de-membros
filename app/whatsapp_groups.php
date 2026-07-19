<?php
declare(strict_types=1);

require_once __DIR__ . '/evolution_api.php';
require_once __DIR__ . '/webhook_dispatcher.php';

function whatsapp_groups_ensure_tables(PDO $pdo): void {
    evolution_ensure_tables($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            description TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            default_instance_key VARCHAR(120) NULL,
            spy_instance_key VARCHAR(120) NULL,
            max_leads_per_group INT NOT NULL DEFAULT 0,
            rotate_when_full TINYINT(1) NOT NULL DEFAULT 1,
            verify_with_spy TINYINT(1) NOT NULL DEFAULT 0,
            rate_per_minute INT NOT NULL DEFAULT 6,
            rate_per_hour INT NOT NULL DEFAULT 120,
            cooldown_seconds INT NOT NULL DEFAULT 8,
            public_url TEXT NULL,
            total_entries INT NOT NULL DEFAULT 0,
            total_sent INT NOT NULL DEFAULT 0,
            total_errors INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wgc_slug (slug),
            KEY idx_wgc_status (status),
            KEY idx_wgc_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_campaign_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            group_id VARCHAR(160) NOT NULL,
            group_name VARCHAR(180) NULL,
            invite_url TEXT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'detected',
            current_members INT NOT NULL DEFAULT 0,
            max_members INT NOT NULL DEFAULT 0,
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wgcg_campaign_group (campaign_id, group_id),
            KEY idx_wgcg_campaign (campaign_id),
            KEY idx_wgcg_group (group_id),
            KEY idx_wgcg_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_scheduled_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NULL,
            group_id VARCHAR(160) NULL,
            instance_key VARCHAR(120) NULL,
            title VARCHAR(180) NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            payload_json LONGTEXT NULL,
            scheduled_at DATETIME NOT NULL,
            recurrence VARCHAR(20) NOT NULL DEFAULT 'once',
            recurrence_interval INT NOT NULL DEFAULT 1,
            status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 3,
            last_error TEXT NULL,
            sent_at DATETIME NULL,
            next_run_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wgsa_due (status, scheduled_at),
            KEY idx_wgsa_campaign (campaign_id),
            KEY idx_wgsa_group (group_id),
            KEY idx_wgsa_type (action_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_action_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action_id INT NULL,
            campaign_id INT NULL,
            group_id VARCHAR(160) NULL,
            instance_key VARCHAR(120) NULL,
            action_type VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL,
            http_status INT NULL,
            request_json LONGTEXT NULL,
            response_body LONGTEXT NULL,
            error_message TEXT NULL,
            message_id VARCHAR(180) NULL,
            confirmed_by_spy TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_wgal_action (action_id),
            KEY idx_wgal_campaign (campaign_id),
            KEY idx_wgal_status (status),
            KEY idx_wgal_group (group_id),
            KEY idx_wgal_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_keyword_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            campaign_id INT NULL,
            group_id VARCHAR(160) NULL,
            keyword VARCHAR(180) NOT NULL,
            match_mode VARCHAR(20) NOT NULL DEFAULT 'contains',
            trigger_event VARCHAR(120) NOT NULL DEFAULT 'WHATSAPP_GRUPO_PALAVRA_CHAVE',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wgkr_campaign (campaign_id),
            KEY idx_wgkr_group (group_id),
            KEY idx_wgkr_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_group_connection_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NULL,
            instance_key VARCHAR(120) NULL,
            status_before VARCHAR(40) NULL,
            status_after VARCHAR(40) NULL,
            action VARCHAR(40) NOT NULL,
            http_status INT NULL,
            response_body LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_wgcl_instance (instance_key),
            KEY idx_wgcl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function whatsapp_groups_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function whatsapp_groups_slug(string $value): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');
    return substr($slug !== '' ? $slug : 'campanha-' . date('Ymd-His'), 0, 110);
}

function whatsapp_groups_action_types(): array {
    return [
        'send_text' => 'Mensagem de texto',
        'send_media' => 'Midia por URL',
        'send_audio' => 'Audio',
        'send_document' => 'Documento',
        'send_video' => 'Video',
        'send_location' => 'Localizacao',
        'send_contact' => 'Contato',
        'send_reaction' => 'Reacao',
        'send_buttons' => 'Botoes',
        'send_list' => 'Lista',
        'send_poll' => 'Enquete',
        'group_open' => 'Abrir grupo',
        'group_close' => 'Fechar grupo',
        'group_lock' => 'Travar edicao',
        'group_unlock' => 'Destravar edicao',
        'group_subject' => 'Alterar titulo',
        'group_description' => 'Alterar descricao',
        'group_picture' => 'Alterar foto',
    ];
}

function whatsapp_groups_status_label(string $status): string {
    return [
        'draft' => 'Rascunho',
        'active' => 'Ativa',
        'paused' => 'Pausada',
        'archived' => 'Arquivada',
        'scheduled' => 'Programada',
        'processing' => 'Processando',
        'sent' => 'Enviada',
        'error' => 'Erro',
        'cancelled' => 'Cancelada',
    ][$status] ?? $status;
}

function whatsapp_groups_public_campaign_url(string $slug): string {
    return rtrim(BASE_URL, '/') . '/whatsapp_group_join.php?c=' . rawurlencode($slug);
}

function whatsapp_groups_log_connection(PDO $pdo, ?array $instance, string $action, array $response, ?string $before = null, ?string $after = null): void {
    try {
        $pdo->prepare("
            INSERT INTO whatsapp_group_connection_logs
                (instance_id, instance_key, status_before, status_after, action, http_status, response_body, error_message, created_at)
            VALUES
                (:instance_id, :instance_key, :before, :after, :action, :http_status, :response, :error, NOW())
        ")->execute([
            ':instance_id' => $instance ? (int)($instance['id'] ?? 0) : null,
            ':instance_key' => $instance ? (string)($instance['instance_key'] ?? '') : null,
            ':before' => $before,
            ':after' => $after,
            ':action' => $action,
            ':http_status' => (int)($response['status'] ?? 0) ?: null,
            ':response' => substr((string)($response['raw'] ?? json_encode($response['data'] ?? null)), 0, 65000),
            ':error' => substr((string)($response['error'] ?? ''), 0, 1000),
        ]);
    } catch (Throwable $e) {}
}

function whatsapp_groups_select_instance(PDO $pdo, ?string $preferred, string $role = 'sender'): string {
    $preferred = trim((string)$preferred);
    if ($preferred !== '') {
        try {
            $st = $pdo->prepare("SELECT instance_key FROM whatsapp_instances WHERE instance_key=:k AND status='CONNECTED' AND is_enabled=1 LIMIT 1");
            $st->execute([':k' => $preferred]);
            $found = trim((string)($st->fetchColumn() ?: ''));
            if ($found !== '') return $found;
        } catch (Throwable $e) {}
    }

    $roleSql = $role === 'administrator'
        ? "(FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 OR FIND_IN_SET('reserve', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0)"
        : "(FIND_IN_SET('sender', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 OR FIND_IN_SET('administrator', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 OR FIND_IN_SET('reserve', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0 OR FIND_IN_SET('spy', COALESCE(NULLIF(operational_roles,''), operational_role)) > 0)";

    try {
        $row = $pdo->query("
            SELECT instance_key
              FROM whatsapp_instances
             WHERE status='CONNECTED'
               AND is_enabled=1
               AND {$roleSql}
             ORDER BY role_priority ASC, id ASC
             LIMIT 1
        ")->fetchColumn();
        return trim((string)($row ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function whatsapp_groups_evolution_send(string $instanceKey, string $groupId, string $type, array $payload): array {
    $instanceKey = trim($instanceKey);
    $groupId = trim($groupId);
    if ($instanceKey === '') return ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Nenhuma instancia conectada disponivel.'];
    if ($groupId === '' && !in_array($type, ['send_contact'], true)) return ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Grupo/destino vazio.'];

    if ($type === 'send_text') {
        return evolution_http('POST', '/message/sendText/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'text' => (string)($payload['text'] ?? ''),
            'linkPreview' => !empty($payload['link_preview']),
            'mentionsEveryOne' => !empty($payload['mentions_everyone']),
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if (in_array($type, ['send_media', 'send_document', 'send_video'], true)) {
        $mediaType = (string)($payload['media_type'] ?? '');
        if ($type === 'send_document') $mediaType = 'document';
        if ($type === 'send_video') $mediaType = 'video';
        if ($mediaType === '') $mediaType = 'image';
        return evolution_http('POST', '/message/sendMedia/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'mediatype' => $mediaType,
            'media' => (string)($payload['media_url'] ?? ''),
            'fileName' => (string)($payload['file_name'] ?? ''),
            'caption' => (string)($payload['caption'] ?? ''),
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if ($type === 'send_audio') {
        return evolution_http('POST', '/message/sendWhatsAppAudio/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'audio' => (string)($payload['media_url'] ?? $payload['audio_url'] ?? ''),
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if ($type === 'send_location') {
        return evolution_http('POST', '/message/sendLocation/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'name' => (string)($payload['location_name'] ?? ''),
            'address' => (string)($payload['location_address'] ?? ''),
            'latitude' => (float)($payload['latitude'] ?? 0),
            'longitude' => (float)($payload['longitude'] ?? 0),
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if ($type === 'send_contact') {
        return evolution_http('POST', '/message/sendContact/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'contact' => [[
                'fullName' => (string)($payload['contact_name'] ?? ''),
                'wuid' => evolution_clean_whatsapp_phone((string)($payload['contact_phone'] ?? '')),
                'phoneNumber' => evolution_clean_whatsapp_phone((string)($payload['contact_phone'] ?? '')),
            ]],
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if ($type === 'send_reaction') {
        return evolution_http('POST', '/message/sendReaction/' . rawurlencode($instanceKey), [
            'reactionMessage' => [
                'key' => [
                    'remoteJid' => $groupId,
                    'id' => (string)($payload['message_id'] ?? ''),
                ],
                'reaction' => (string)($payload['reaction'] ?? ''),
            ],
        ]);
    }

    if ($type === 'send_poll') {
        $values = $payload['poll_options'] ?? [];
        if (is_string($values)) {
            $values = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $values))));
        }
        return evolution_http('POST', '/message/sendPoll/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'name' => (string)($payload['poll_name'] ?? ''),
            'selectableCount' => max(1, (int)($payload['selectable_count'] ?? 1)),
            'values' => array_values($values),
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if ($type === 'send_buttons') {
        $values = $payload['poll_options'] ?? [];
        if (is_string($values)) {
            $values = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $values))));
        }
        $buttons = [];
        foreach ($values as $i => $label) {
            $buttons[] = ['buttonId' => 'btn_' . ($i + 1), 'buttonText' => ['displayText' => (string)$label], 'type' => 1];
        }
        return evolution_http('POST', '/message/sendButtons/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'title' => (string)($payload['subject'] ?? ''),
            'description' => (string)($payload['text'] ?? ''),
            'footer' => (string)($payload['caption'] ?? ''),
            'buttons' => $buttons,
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    if ($type === 'send_list') {
        $values = $payload['poll_options'] ?? [];
        if (is_string($values)) {
            $values = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $values))));
        }
        $rows = [];
        foreach ($values as $i => $label) {
            $rows[] = ['title' => (string)$label, 'rowId' => 'row_' . ($i + 1)];
        }
        return evolution_http('POST', '/message/sendList/' . rawurlencode($instanceKey), [
            'number' => $groupId,
            'title' => (string)($payload['subject'] ?? 'Opcoes'),
            'description' => (string)($payload['text'] ?? ''),
            'buttonText' => (string)($payload['caption'] ?? 'Ver opcoes'),
            'footerText' => '',
            'sections' => [[
                'title' => (string)($payload['poll_name'] ?? 'Opcoes'),
                'rows' => $rows,
            ]],
            'delay' => max(0, (int)($payload['delay_ms'] ?? 0)),
        ]);
    }

    $settingMap = [
        'group_open' => 'not_announcement',
        'group_close' => 'announcement',
        'group_lock' => 'locked',
        'group_unlock' => 'unlocked',
    ];
    if (isset($settingMap[$type])) {
        return evolution_http('POST', '/group/updateSetting/' . rawurlencode($instanceKey), [
            'groupJid' => $groupId,
            'action' => $settingMap[$type],
        ]);
    }

    if ($type === 'group_subject') {
        return evolution_http('POST', '/group/updateGroupSubject/' . rawurlencode($instanceKey), [
            'groupJid' => $groupId,
            'subject' => (string)($payload['subject'] ?? ''),
        ]);
    }

    if ($type === 'group_description') {
        return evolution_http('POST', '/group/updateGroupDescription/' . rawurlencode($instanceKey), [
            'groupJid' => $groupId,
            'description' => (string)($payload['description'] ?? ''),
        ]);
    }

    if ($type === 'group_picture') {
        return evolution_http('POST', '/group/updateGroupPicture/' . rawurlencode($instanceKey), [
            'groupJid' => $groupId,
            'image' => (string)($payload['media_url'] ?? $payload['picture_url'] ?? ''),
        ]);
    }

    return ['ok' => false, 'status' => 0, 'raw' => '', 'error' => 'Tipo de acao nao suportado: ' . $type];
}

function whatsapp_groups_execute_action(PDO $pdo, array $action): array {
    whatsapp_groups_ensure_tables($pdo);
    $payload = json_decode((string)($action['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) $payload = [];

    $campaign = null;
    if (!empty($action['campaign_id'])) {
        $st = $pdo->prepare("SELECT * FROM whatsapp_group_campaigns WHERE id=:id LIMIT 1");
        $st->execute([':id' => (int)$action['campaign_id']]);
        $campaign = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $type = (string)$action['action_type'];
    $needsAdmin = in_array($type, ['group_open','group_close','group_lock','group_unlock','group_subject','group_description','group_picture'], true);
    $instanceKey = whatsapp_groups_select_instance(
        $pdo,
        (string)($action['instance_key'] ?? ($campaign['default_instance_key'] ?? '')),
        $needsAdmin ? 'administrator' : 'sender'
    );
    $groupId = trim((string)($action['group_id'] ?? ''));
    if ($groupId === '' && !empty($campaign['id'])) {
        $st = $pdo->prepare("
            SELECT group_id
              FROM whatsapp_group_campaign_groups
             WHERE campaign_id=:cid
               AND is_active=1
             ORDER BY is_current DESC, id ASC
             LIMIT 1
        ");
        $st->execute([':cid' => (int)$campaign['id']]);
        $groupId = trim((string)($st->fetchColumn() ?: ''));
    }

    $response = whatsapp_groups_evolution_send($instanceKey, $groupId, $type, $payload);
    $ok = !empty($response['ok']);
    $messageId = '';
    if (is_array($response['data'])) {
        $messageId = (string)($response['data']['key']['id'] ?? $response['data']['messageId'] ?? $response['data']['id'] ?? '');
    }

    try {
        $pdo->prepare("
            INSERT INTO whatsapp_group_action_logs
                (action_id, campaign_id, group_id, instance_key, action_type, status, http_status, request_json, response_body, error_message, message_id, created_at)
            VALUES
                (:action_id, :campaign_id, :group_id, :instance_key, :action_type, :status, :http_status, :request_json, :response_body, :error_message, :message_id, NOW())
        ")->execute([
            ':action_id' => (int)($action['id'] ?? 0) ?: null,
            ':campaign_id' => (int)($action['campaign_id'] ?? 0) ?: null,
            ':group_id' => $groupId ?: null,
            ':instance_key' => $instanceKey ?: null,
            ':action_type' => $type,
            ':status' => $ok ? 'sent' : 'error',
            ':http_status' => (int)($response['status'] ?? 0) ?: null,
            ':request_json' => json_encode(['payload' => $payload, 'group_id' => $groupId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':response_body' => substr((string)($response['raw'] ?? json_encode($response['data'] ?? null)), 0, 65000),
            ':error_message' => substr((string)($response['error'] ?? ''), 0, 1000),
            ':message_id' => $messageId ?: null,
        ]);
    } catch (Throwable $e) {}

    if (!empty($action['id'])) {
        if ($ok) {
            try {
                disparar_evento_webhooks($pdo, 'WHATSAPP_GRUPO_MSG_ENVIADA', [], [
                    'campaign_id' => (int)($action['campaign_id'] ?? 0) ?: null,
                    'group_id' => $groupId,
                    'instance_key' => $instanceKey,
                    'action_id' => (int)$action['id'],
                    'action_type' => $type,
                    'message_id' => $messageId,
                    'origem' => 'whatsapp_groups_worker',
                ]);
            } catch (Throwable $e) {}
            $nextRun = whatsapp_groups_next_run((string)($action['recurrence'] ?? 'once'), (int)($action['recurrence_interval'] ?? 1), (string)$action['scheduled_at']);
            if ($nextRun !== null) {
                $pdo->prepare("
                    UPDATE whatsapp_group_scheduled_actions
                       SET status='scheduled', attempts=0, sent_at=NOW(), scheduled_at=:next_run, next_run_at=:next_run, last_error=NULL
                     WHERE id=:id
                     LIMIT 1
                ")->execute([':next_run' => $nextRun, ':id' => (int)$action['id']]);
            } else {
                $pdo->prepare("UPDATE whatsapp_group_scheduled_actions SET status='sent', sent_at=NOW(), last_error=NULL WHERE id=:id LIMIT 1")
                    ->execute([':id' => (int)$action['id']]);
            }
            if (!empty($campaign['id'])) {
                $pdo->prepare("UPDATE whatsapp_group_campaigns SET total_sent=total_sent+1, updated_at=NOW() WHERE id=:id LIMIT 1")
                    ->execute([':id' => (int)$campaign['id']]);
            }
        } else {
            try {
                disparar_evento_webhooks($pdo, 'WHATSAPP_GRUPO_MSG_FALHOU', [], [
                    'campaign_id' => (int)($action['campaign_id'] ?? 0) ?: null,
                    'group_id' => $groupId,
                    'instance_key' => $instanceKey,
                    'action_id' => (int)$action['id'],
                    'action_type' => $type,
                    'error' => (string)($response['error'] ?: $response['raw'] ?? 'Falha no envio'),
                    'origem' => 'whatsapp_groups_worker',
                ]);
            } catch (Throwable $e) {}
            $attempts = (int)($action['attempts'] ?? 0) + 1;
            $max = max(1, (int)($action['max_attempts'] ?? 3));
            $status = $attempts >= $max ? 'error' : 'scheduled';
            $delay = min(60, 10 * $attempts);
            $pdo->prepare("
                UPDATE whatsapp_group_scheduled_actions
                   SET status=:status, attempts=:attempts, scheduled_at=DATE_ADD(NOW(), INTERVAL {$delay} SECOND), last_error=:error
                 WHERE id=:id
                 LIMIT 1
            ")->execute([
                ':status' => $status,
                ':attempts' => $attempts,
                ':error' => substr((string)($response['error'] ?: $response['raw'] ?? 'Falha no envio'), 0, 1000),
                ':id' => (int)$action['id'],
            ]);
            if (!empty($campaign['id'])) {
                $pdo->prepare("UPDATE whatsapp_group_campaigns SET total_errors=total_errors+1,last_error=:e,updated_at=NOW() WHERE id=:id LIMIT 1")
                    ->execute([':e' => substr((string)($response['error'] ?: $response['raw'] ?? 'Falha no envio'), 0, 1000), ':id' => (int)$campaign['id']]);
            }
        }
    }

    return $response + ['instance_key' => $instanceKey, 'group_id' => $groupId];
}

function whatsapp_groups_next_run(string $recurrence, int $interval, string $from): ?string {
    $interval = max(1, $interval);
    if ($recurrence === 'daily') return date('Y-m-d H:i:s', strtotime($from . ' +' . $interval . ' day'));
    if ($recurrence === 'weekly') return date('Y-m-d H:i:s', strtotime($from . ' +' . $interval . ' week'));
    if ($recurrence === 'monthly') return date('Y-m-d H:i:s', strtotime($from . ' +' . $interval . ' month'));
    return null;
}

function whatsapp_groups_process_due(PDO $pdo, int $limit = 30): array {
    whatsapp_groups_ensure_tables($pdo);
    $limit = max(1, min(100, $limit));
    $stats = ['processed' => 0, 'sent' => 0, 'errors' => 0];

    $rows = $pdo->query("
        SELECT a.*
          FROM whatsapp_group_scheduled_actions a
          LEFT JOIN whatsapp_group_campaigns c ON c.id = a.campaign_id
         WHERE a.status='scheduled'
           AND a.scheduled_at <= NOW()
           AND (c.id IS NULL OR c.status='active')
         ORDER BY a.scheduled_at ASC, a.id ASC
         LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $stats['processed']++;
        try {
            $pdo->prepare("UPDATE whatsapp_group_scheduled_actions SET status='processing' WHERE id=:id AND status='scheduled' LIMIT 1")
                ->execute([':id' => (int)$row['id']]);
            $response = whatsapp_groups_execute_action($pdo, $row);
            if (!empty($response['ok'])) $stats['sent']++;
            else $stats['errors']++;
        } catch (Throwable $e) {
            $stats['errors']++;
            try {
                $pdo->prepare("UPDATE whatsapp_group_scheduled_actions SET status='error', last_error=:e WHERE id=:id LIMIT 1")
                    ->execute([':e' => substr($e->getMessage(), 0, 1000), ':id' => (int)$row['id']]);
            } catch (Throwable $ignored) {}
        }
        $cooldown = 0;
        if (!empty($row['campaign_id'])) {
            try {
                $st = $pdo->prepare("SELECT cooldown_seconds FROM whatsapp_group_campaigns WHERE id=:id LIMIT 1");
                $st->execute([':id' => (int)$row['campaign_id']]);
                $cooldown = max(0, (int)($st->fetchColumn() ?: 0));
            } catch (Throwable $e) {}
        }
        if ($cooldown > 0 && $stats['processed'] < count($rows)) sleep(min(20, $cooldown));
    }

    return $stats;
}

function whatsapp_groups_payload_from_post(array $post): array {
    return [
        'text' => trim((string)($post['text'] ?? '')),
        'caption' => trim((string)($post['caption'] ?? '')),
        'media_url' => trim((string)($post['media_url'] ?? '')),
        'media_type' => trim((string)($post['media_type'] ?? 'image')),
        'file_name' => trim((string)($post['file_name'] ?? '')),
        'poll_name' => trim((string)($post['poll_name'] ?? '')),
        'poll_options' => trim((string)($post['poll_options'] ?? '')),
        'selectable_count' => max(1, (int)($post['selectable_count'] ?? 1)),
        'location_name' => trim((string)($post['location_name'] ?? '')),
        'location_address' => trim((string)($post['location_address'] ?? '')),
        'latitude' => trim((string)($post['latitude'] ?? '')),
        'longitude' => trim((string)($post['longitude'] ?? '')),
        'contact_name' => trim((string)($post['contact_name'] ?? '')),
        'contact_phone' => trim((string)($post['contact_phone'] ?? '')),
        'message_id' => trim((string)($post['message_id'] ?? '')),
        'reaction' => trim((string)($post['reaction'] ?? '')),
        'subject' => trim((string)($post['subject'] ?? '')),
        'description' => trim((string)($post['group_description'] ?? '')),
        'link_preview' => !empty($post['link_preview']),
        'mentions_everyone' => !empty($post['mentions_everyone']),
        'delay_ms' => max(0, (int)($post['delay_ms'] ?? 0)),
    ];
}

function whatsapp_groups_process_keyword_message(PDO $pdo, int $rawLogId, array $payload): array {
    whatsapp_groups_ensure_tables($pdo);
    if (!function_exists('whatsapp_ai_extract_message_fields')) {
        $aiFile = __DIR__ . '/whatsapp_ai.php';
        if (is_file($aiFile)) require_once $aiFile;
    }
    if (!function_exists('whatsapp_ai_extract_message_fields')) {
        return ['matched' => 0, 'error' => 'Extrator de mensagens indisponivel.'];
    }

    $fields = whatsapp_ai_extract_message_fields($payload);
    if (!$fields || (string)($fields['chat_type'] ?? 'group') !== 'group') return ['matched' => 0];
    $text = trim((string)($fields['message_text'] ?? ''));
    $groupId = trim((string)($fields['group_id'] ?? ''));
    if ($text === '' || $groupId === '') return ['matched' => 0];

    $campaignIds = [];
    try {
        $st = $pdo->prepare("SELECT campaign_id FROM whatsapp_group_campaign_groups WHERE group_id=:gid AND is_active=1");
        $st->execute([':gid' => $groupId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $campaignIds[] = (int)$row['campaign_id'];
    } catch (Throwable $e) {}

    $where = ["is_active=1", "(group_id IS NULL OR group_id='' OR group_id=:gid)"];
    $params = [':gid' => $groupId];
    if ($campaignIds) {
        $keys = [];
        foreach (array_values(array_unique($campaignIds)) as $i => $cid) {
            $key = ':cid' . $i;
            $keys[] = $key;
            $params[$key] = $cid;
        }
        $where[] = "(campaign_id IS NULL OR campaign_id=0 OR campaign_id IN (" . implode(',', $keys) . "))";
    } else {
        $where[] = "(campaign_id IS NULL OR campaign_id=0)";
    }

    $rules = [];
    try {
        $st = $pdo->prepare("SELECT * FROM whatsapp_group_keyword_rules WHERE " . implode(' AND ', $where));
        $st->execute($params);
        $rules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['matched' => 0, 'error' => $e->getMessage()];
    }

    $matched = 0;
    $lower = strtolower($text);
    foreach ($rules as $rule) {
        $keyword = strtolower(trim((string)$rule['keyword']));
        if ($keyword === '') continue;
        $mode = (string)($rule['match_mode'] ?? 'contains');
        $ok = $mode === 'equals'
            ? $lower === $keyword
            : ($mode === 'starts' ? str_starts_with($lower, $keyword) : str_contains($lower, $keyword));
        if (!$ok) continue;
        $matched++;
        $event = strtoupper(trim((string)($rule['trigger_event'] ?? 'WHATSAPP_GRUPO_PALAVRA_CHAVE'))) ?: 'WHATSAPP_GRUPO_PALAVRA_CHAVE';
        $phone = (string)($fields['sender_phone'] ?? '');
        $user = evolution_find_user_by_phone($pdo, $phone);
        $extra = [
            'group_id' => $groupId,
            'keyword' => (string)$rule['keyword'],
            'message_text' => $text,
            'raw_log_id' => $rawLogId,
            'sender_phone' => $phone,
            'sender_name' => (string)($fields['sender_name'] ?? ''),
            'campaign_id' => (int)($rule['campaign_id'] ?? 0) ?: null,
            'origem' => 'whatsapp_group_keyword',
        ];
        try {
            if ($user && !empty($user['id'])) {
                adicionar_tag((int)$user['id'], $event, 'whatsapp_group_keyword', $rawLogId);
                disparar_webhooks($event, (int)$user['id'], $extra);
            } else {
                disparar_evento_webhooks($pdo, $event, [], $extra);
            }
        } catch (Throwable $e) {
            try {
                $pdo->prepare("UPDATE whatsapp_webhook_raw_logs SET trigger_error=:e WHERE id=:id LIMIT 1")
                    ->execute([':e' => substr($e->getMessage(), 0, 1000), ':id' => $rawLogId]);
            } catch (Throwable $ignored) {}
        }
    }

    return ['matched' => $matched];
}
