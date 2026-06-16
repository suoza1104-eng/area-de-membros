<?php
declare(strict_types=1);

require_once __DIR__ . '/evolution_api.php';

function whatsapp_ai_ensure_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_ai_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raw_log_id INT NOT NULL,
            instance_key VARCHAR(120) NULL,
            group_id VARCHAR(160) NOT NULL,
            sender_phone VARCHAR(30) NULL,
            sender_id VARCHAR(120) NULL,
            sender_name VARCHAR(180) NULL,
            user_id INT NULL,
            message_type VARCHAR(60) NULL,
            media_kind VARCHAR(30) NULL,
            media_url TEXT NULL,
            media_mime VARCHAR(120) NULL,
            media_base64 LONGTEXT NULL,
            transcription_text LONGTEXT NULL,
            transcription_status VARCHAR(30) NULL,
            transcription_error TEXT NULL,
            message_text LONGTEXT NOT NULL,
            message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_batch_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wam_raw_log (raw_log_id),
            KEY idx_wam_group_time (group_id, message_at),
            KEY idx_wam_processed (processed_batch_id),
            KEY idx_wam_phone (sender_phone),
            KEY idx_wam_user (user_id),
            KEY idx_wam_media (media_kind),
            KEY idx_wam_transcription (transcription_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'media_kind' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN media_kind VARCHAR(30) NULL AFTER message_type",
        'media_url' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN media_url TEXT NULL AFTER media_kind",
        'media_mime' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN media_mime VARCHAR(120) NULL AFTER media_url",
        'media_base64' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN media_base64 LONGTEXT NULL AFTER media_mime",
        'transcription_text' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN transcription_text LONGTEXT NULL AFTER media_base64",
        'transcription_status' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN transcription_status VARCHAR(30) NULL AFTER transcription_text",
        'transcription_error' => "ALTER TABLE whatsapp_ai_messages ADD COLUMN transcription_error TEXT NULL AFTER transcription_status",
    ] as $column => $sql) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM whatsapp_ai_messages LIKE :c");
            $st->execute([':c' => $column]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {}
    }

    foreach ([
        'idx_wam_media' => 'media_kind',
        'idx_wam_transcription' => 'transcription_status',
    ] as $idx => $column) {
        try {
            $st = $pdo->prepare("SHOW INDEX FROM whatsapp_ai_messages WHERE Key_name = :k");
            $st->execute([':k' => $idx]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE whatsapp_ai_messages ADD KEY {$idx} ({$column})");
            }
        } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_ai_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(160) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            model VARCHAR(120) NULL,
            window_start DATETIME NULL,
            window_end DATETIME NULL,
            message_count INT NOT NULL DEFAULT 0,
            prompt_text LONGTEXT NULL,
            request_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            summary LONGTEXT NULL,
            decision VARCHAR(80) NULL,
            category VARCHAR(80) NULL,
            severity VARCHAR(30) NULL,
            needs_intervention TINYINT(1) NOT NULL DEFAULT 0,
            suggested_response LONGTEXT NULL,
            next_context LONGTEXT NULL,
            input_tokens INT NULL,
            output_tokens INT NULL,
            total_tokens INT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            KEY idx_waib_group_created (group_id, created_at),
            KEY idx_waib_status (status),
            KEY idx_waib_intervention (needs_intervention)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_ai_contexts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(160) NOT NULL,
            batch_id INT NULL,
            summary LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_waic_group_created (group_id, created_at),
            KEY idx_waic_batch (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_ai_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(30) NOT NULL,
            groups_processed INT NOT NULL DEFAULT 0,
            batches_created INT NOT NULL DEFAULT 0,
            messages_processed INT NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            KEY idx_wair_started (started_at),
            KEY idx_wair_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_ai_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            group_id VARCHAR(160) NOT NULL,
            action_type VARCHAR(80) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            target_user_id INT NULL,
            target_phone VARCHAR(30) NULL,
            target_name VARCHAR(180) NULL,
            tag_name VARCHAR(120) NULL,
            event_name VARCHAR(120) NULL,
            message_text LONGTEXT NULL,
            payload_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            approved_by VARCHAR(180) NULL,
            approved_at DATETIME NULL,
            executed_at DATETIME NULL,
            ignored_by VARCHAR(180) NULL,
            ignored_at DATETIME NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_waia_batch (batch_id),
            KEY idx_waia_group (group_id),
            KEY idx_waia_status (status),
            KEY idx_waia_type (action_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function whatsapp_ai_default_prompt(): string {
    return "Voce e um analista tecnico de suporte em grupos de alunos de eletrica. Leia o pacote de mensagens e decida se a equipe deve intervir. Ignore conversas normais entre membros quando nao houver necessidade. Sinalize duvidas tecnicas, interesse de compra, baixo calao, conflito, suporte urgente, elogios relevantes e oportunidades de relacionamento. Quando sugerir resposta, seja direto, educado e com tom de equipe tecnica online.";
}

function whatsapp_ai_get_config(): array {
    return [
        'enabled' => (int)get_setting('whatsapp_ai_enabled', '0') === 1,
        'openai_api_key' => trim((string)get_setting('whatsapp_ai_openai_api_key', '')),
        'model' => trim((string)get_setting('whatsapp_ai_model', 'gpt-4.1-mini')),
        'interval_minutes' => max(1, min(120, (int)get_setting('whatsapp_ai_interval_minutes', '5'))),
        'active_from' => trim((string)get_setting('whatsapp_ai_active_from', '08:00')),
        'active_to' => trim((string)get_setting('whatsapp_ai_active_to', '22:00')),
        'max_tokens' => max(100, min(4000, (int)get_setting('whatsapp_ai_max_tokens', '800'))),
        'max_messages' => max(1, min(300, (int)get_setting('whatsapp_ai_max_messages', '80'))),
        'context_keep' => max(0, min(50, (int)get_setting('whatsapp_ai_context_keep', '6'))),
        'prompt' => (string)get_setting('whatsapp_ai_prompt', whatsapp_ai_default_prompt()),
        'criteria' => (string)get_setting('whatsapp_ai_criteria', ''),
        'temperature' => max(0, min(2, (float)get_setting('whatsapp_ai_temperature', '0.2'))),
        'transcription_model' => trim((string)get_setting('whatsapp_ai_transcription_model', 'gpt-4o-mini-transcribe')) ?: 'gpt-4o-mini-transcribe',
    ];
}

function whatsapp_ai_set_config(array $data): void {
    $pairs = [
        'whatsapp_ai_enabled' => !empty($data['enabled']) ? '1' : '0',
        'whatsapp_ai_openai_api_key' => trim((string)($data['openai_api_key'] ?? '')),
        'whatsapp_ai_model' => trim((string)($data['model'] ?? 'gpt-4.1-mini')) ?: 'gpt-4.1-mini',
        'whatsapp_ai_interval_minutes' => (string)max(1, min(120, (int)($data['interval_minutes'] ?? 5))),
        'whatsapp_ai_active_from' => preg_match('/^\d{2}:\d{2}$/', (string)($data['active_from'] ?? '')) ? (string)$data['active_from'] : '08:00',
        'whatsapp_ai_active_to' => preg_match('/^\d{2}:\d{2}$/', (string)($data['active_to'] ?? '')) ? (string)$data['active_to'] : '22:00',
        'whatsapp_ai_max_tokens' => (string)max(100, min(4000, (int)($data['max_tokens'] ?? 800))),
        'whatsapp_ai_max_messages' => (string)max(1, min(300, (int)($data['max_messages'] ?? 80))),
        'whatsapp_ai_context_keep' => (string)max(0, min(50, (int)($data['context_keep'] ?? 6))),
        'whatsapp_ai_prompt' => trim((string)($data['prompt'] ?? '')) ?: whatsapp_ai_default_prompt(),
        'whatsapp_ai_criteria' => trim((string)($data['criteria'] ?? '')),
        'whatsapp_ai_temperature' => (string)max(0, min(2, (float)($data['temperature'] ?? 0.2))),
        'whatsapp_ai_transcription_model' => trim((string)($data['transcription_model'] ?? 'gpt-4o-mini-transcribe')) ?: 'gpt-4o-mini-transcribe',
    ];
    foreach ($pairs as $key => $value) {
        set_setting($key, $value);
    }
}

function whatsapp_ai_is_active_now(array $cfg, ?DateTime $now = null): bool {
    if (!$cfg['enabled']) return false;
    $now = $now ?: new DateTime('now');
    $cur = $now->format('H:i');
    $from = (string)$cfg['active_from'];
    $to = (string)$cfg['active_to'];
    if ($from === $to) return true;
    if ($from < $to) return $cur >= $from && $cur <= $to;
    return $cur >= $from || $cur <= $to;
}

function whatsapp_ai_array_get(array $data, array $paths) {
    foreach ($paths as $path) {
        $cur = $data;
        $ok = true;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
            } else {
                $ok = false;
                break;
            }
        }
        if ($ok && $cur !== null && $cur !== '') return $cur;
    }
    return null;
}

function whatsapp_ai_find_media_node(array $message): array {
    foreach (['imageMessage', 'audioMessage', 'videoMessage', 'documentMessage', 'stickerMessage'] as $key) {
        if (isset($message[$key]) && is_array($message[$key])) {
            return [$key, $message[$key]];
        }
    }
    return ['', []];
}

function whatsapp_ai_media_kind_from_type(string $messageType): string {
    if ($messageType === 'imageMessage') return 'image';
    if ($messageType === 'audioMessage') return 'audio';
    if ($messageType === 'videoMessage') return 'video';
    if ($messageType === 'documentMessage') return 'document';
    if ($messageType === 'stickerMessage') return 'sticker';
    return '';
}

function whatsapp_ai_extract_media_base64($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strpos($value, 'base64,') !== false) {
        $value = substr($value, (int)strpos($value, 'base64,') + 7);
    }
    $value = preg_replace('/\s+/', '', $value) ?? $value;
    if ($value === '' || strlen($value) < 32) return '';
    return preg_match('/^[A-Za-z0-9+\/=_-]+$/', $value) ? $value : '';
}

function whatsapp_ai_extract_media_fields(array $payload, array $message, string $messageType): array {
    $mediaKind = whatsapp_ai_media_kind_from_type($messageType);
    $node = is_array($message[$messageType] ?? null) ? $message[$messageType] : [];

    $mediaUrl = (string)whatsapp_ai_array_get($payload, [
        'data.mediaUrl',
        'data.media_url',
        'data.url',
        'data.message.' . $messageType . '.url',
        'data.message.' . $messageType . '.mediaUrl',
        'data.message.' . $messageType . '.directPath',
        'mediaUrl',
        'media_url',
        'url',
        'message.' . $messageType . '.url',
        'message.' . $messageType . '.mediaUrl',
    ]);

    $mime = (string)whatsapp_ai_array_get($payload, [
        'data.mimetype',
        'data.mimeType',
        'data.message.' . $messageType . '.mimetype',
        'data.message.' . $messageType . '.mimeType',
        'mimetype',
        'mimeType',
        'message.' . $messageType . '.mimetype',
        'message.' . $messageType . '.mimeType',
    ]);
    if ($mime === '' && isset($node['mimetype'])) $mime = (string)$node['mimetype'];

    $base64 = whatsapp_ai_extract_media_base64(whatsapp_ai_array_get($payload, [
        'data.base64',
        'data.mediaBase64',
        'data.message.base64',
        'data.message.' . $messageType . '.base64',
        'base64',
        'mediaBase64',
        'message.base64',
        'message.' . $messageType . '.base64',
    ]));

    return [
        'media_kind' => $mediaKind !== '' ? $mediaKind : null,
        'media_url' => $mediaUrl !== '' && preg_match('/^https?:\/\//i', $mediaUrl) ? $mediaUrl : null,
        'media_mime' => $mime !== '' ? substr($mime, 0, 120) : null,
        'media_base64' => $base64 !== '' ? $base64 : null,
    ];
}

function whatsapp_ai_extract_message_fields(array $payload): ?array {
    $eventType = (string)whatsapp_ai_array_get($payload, ['event', 'event_type', 'type']);
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    $key = is_array($data['key'] ?? null) ? $data['key'] : [];

    $groupId = (string)whatsapp_ai_array_get($payload, [
        'data.key.remoteJid', 'data.remoteJid', 'data.chatId', 'data.message.key.remoteJid',
        'key.remoteJid', 'remoteJid', 'chatId'
    ]);
    if ($groupId === '' || strpos($groupId, '@g.us') === false) return null;

    $fromMe = (bool)($key['fromMe'] ?? whatsapp_ai_array_get($payload, ['data.key.fromMe', 'fromMe']) ?? false);
    if ($fromMe) return null;

    $message = is_array($data['message'] ?? null) ? $data['message'] : [];
    [$mediaMessageType] = whatsapp_ai_find_media_node($message);
    $text = (string)whatsapp_ai_array_get($payload, [
        'data.message.conversation',
        'data.message.extendedTextMessage.text',
        'data.message.imageMessage.caption',
        'data.message.videoMessage.caption',
        'data.text',
        'data.body',
        'message.conversation',
        'message.extendedTextMessage.text',
        'text',
        'body',
    ]);
    $text = trim($text);

    $senderId = (string)whatsapp_ai_array_get($payload, [
        'data.key.participant', 'data.participant', 'data.sender', 'data.participantId',
        'key.participant', 'participant', 'sender'
    ]);
    if ($senderId === '') {
        $senderId = (string)whatsapp_ai_array_get($payload, ['data.participantPn', 'participantPn']);
    }
    $senderPhone = evolution_clean_whatsapp_phone($senderId);

    $senderName = (string)whatsapp_ai_array_get($payload, [
        'data.pushName', 'data.notifyName', 'data.senderName', 'pushName', 'notifyName', 'senderName'
    ]);

    $messageType = $mediaMessageType;
    foreach (array_keys($message) as $k) {
        if ($messageType !== '') break;
        if (substr((string)$k, -7) === 'Message' || $k === 'conversation') {
            $messageType = (string)$k;
            break;
        }
    }
    if ($messageType === '') $messageType = $eventType ?: 'message';
    $media = whatsapp_ai_extract_media_fields($payload, $message, $messageType);

    if ($text === '' && !empty($media['media_kind'])) {
        $labels = [
            'image' => 'enviou uma imagem sem legenda',
            'audio' => 'enviou um audio sem transcricao',
            'video' => 'enviou um video sem legenda',
            'document' => 'enviou um documento sem legenda',
            'sticker' => 'enviou um sticker',
        ];
        $text = '[' . ($labels[(string)$media['media_kind']] ?? 'enviou uma midia') . ']';
    }
    if ($text === '') return null;

    $timestamp = whatsapp_ai_array_get($payload, [
        'data.messageTimestamp', 'data.timestamp', 'messageTimestamp', 'timestamp'
    ]);
    $messageAt = date('Y-m-d H:i:s');
    if (is_numeric($timestamp)) {
        $ts = (int)$timestamp;
        if ($ts > 20000000000) $ts = (int)floor($ts / 1000);
        if ($ts > 0) $messageAt = date('Y-m-d H:i:s', $ts);
    }

    return [
        'event_type' => $eventType,
        'instance_key' => (string)whatsapp_ai_array_get($payload, ['instance', 'instanceKey', 'data.instanceId', 'data.instance']),
        'group_id' => $groupId,
        'sender_phone' => $senderPhone !== '' ? $senderPhone : null,
        'sender_id' => $senderId !== '' ? $senderId : null,
        'sender_name' => $senderName !== '' ? substr($senderName, 0, 180) : null,
        'message_type' => substr($messageType, 0, 60),
        'media_kind' => $media['media_kind'],
        'media_url' => $media['media_url'],
        'media_mime' => $media['media_mime'],
        'media_base64' => $media['media_base64'],
        'message_text' => $text,
        'message_at' => $messageAt,
    ];
}

function whatsapp_ai_record_message(PDO $pdo, int $rawLogId, array $payload): ?int {
    whatsapp_ai_ensure_tables($pdo);
    $fields = whatsapp_ai_extract_message_fields($payload);
    if (!$fields) return null;

    try {
        $groupIgnored = evolution_is_group_ignored($pdo, $fields['group_id']);
        if ($groupIgnored) return null;
    } catch (Throwable $e) {}

    $user = !empty($fields['sender_phone']) ? evolution_find_user_by_phone($pdo, (string)$fields['sender_phone']) : null;
    $userId = $user ? (int)($user['id'] ?? 0) : 0;

    $st = $pdo->prepare("
        INSERT INTO whatsapp_ai_messages
            (raw_log_id, instance_key, group_id, sender_phone, sender_id, sender_name, user_id, message_type, media_kind, media_url, media_mime, media_base64, message_text, message_at, created_at)
        VALUES
            (:raw_log_id, :instance_key, :group_id, :sender_phone, :sender_id, :sender_name, :user_id, :message_type, :media_kind, :media_url, :media_mime, :media_base64, :message_text, :message_at, NOW())
        ON DUPLICATE KEY UPDATE raw_log_id = raw_log_id
    ");
    $st->execute([
        ':raw_log_id' => $rawLogId,
        ':instance_key' => $fields['instance_key'] ?: null,
        ':group_id' => $fields['group_id'],
        ':sender_phone' => $fields['sender_phone'],
        ':sender_id' => $fields['sender_id'],
        ':sender_name' => $fields['sender_name'],
        ':user_id' => $userId > 0 ? $userId : null,
        ':message_type' => $fields['message_type'],
        ':media_kind' => $fields['media_kind'],
        ':media_url' => $fields['media_url'],
        ':media_mime' => $fields['media_mime'],
        ':media_base64' => $fields['media_base64'],
        ':message_text' => $fields['message_text'],
        ':message_at' => $fields['message_at'],
    ]);
    return (int)$pdo->lastInsertId();
}

function whatsapp_ai_build_prompt(array $cfg, string $groupName, array $contexts, array $messages): array {
    $lines = [];
    $imageInputs = [];
    foreach ($messages as $m) {
        $name = trim((string)($m['aluno_nome'] ?? ''));
        if ($name === '') $name = trim((string)($m['sender_name'] ?? ''));
        if ($name === '') $name = 'Participante';
        $phone = trim((string)($m['sender_phone'] ?? ''));
        $label = $name . ($phone !== '' ? ' (' . $phone . ')' : '');
        $mediaKind = trim((string)($m['media_kind'] ?? ''));
        $text = trim((string)$m['message_text']);
        if ($mediaKind === 'audio') {
            $transcription = trim((string)($m['transcription_text'] ?? ''));
            if ($transcription !== '') {
                $text .= ' Transcricao do audio: ' . $transcription;
            } else {
                $status = trim((string)($m['transcription_status'] ?? ''));
                $text .= ' Audio recebido' . ($status !== '' ? ' (transcricao: ' . $status . ')' : '') . '.';
            }
        } elseif ($mediaKind !== '') {
            $text .= ' Midia recebida: ' . $mediaKind . '.';
        }
        $lines[] = '[' . (string)$m['message_at'] . '] ' . $label . ': ' . $text;

        if ($mediaKind === 'image') {
            $imageUrl = trim((string)($m['media_url'] ?? ''));
            $base64 = trim((string)($m['media_base64'] ?? ''));
            $mime = trim((string)($m['media_mime'] ?? 'image/jpeg')) ?: 'image/jpeg';
            if ($imageUrl !== '') {
                $imageInputs[] = ['type' => 'input_image', 'image_url' => $imageUrl, 'detail' => 'low'];
            } elseif ($base64 !== '') {
                $imageInputs[] = ['type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . $base64, 'detail' => 'low'];
            }
        }
    }

    $userContent = [[
        'type' => 'input_text',
        'text' => json_encode([
            'grupo' => $groupName,
            'criterios_adicionais' => (string)$cfg['criteria'],
            'contextos_anteriores' => array_values($contexts),
            'mensagens' => $lines,
            'instrucao_saida' => 'Responda somente JSON valido com as chaves: precisa_intervencao, nivel, categoria, resumo, resposta_sugerida, acoes, novo_contexto. Em acoes, use tipo: send_group_message, apply_tag, trigger_webhook ou internal_alert. Para tags use tag. Para webhooks use event_name. Para aluno use telefone ou user_id quando souber. Se houver imagem anexada, analise visualmente apenas o necessario para decidir se precisa intervencao.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]];
    foreach (array_slice($imageInputs, 0, 4) as $img) {
        $userContent[] = $img;
    }

    return [
        ['role' => 'system', 'content' => (string)$cfg['prompt']],
        ['role' => 'user', 'content' => $userContent],
    ];
}

function whatsapp_ai_call_openai(array $cfg, array $messages): array {
    $apiKey = (string)$cfg['openai_api_key'];
    if ($apiKey === '') {
        throw new RuntimeException('API key da OpenAI nao configurada.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensao cURL do PHP nao disponivel.');
    }

    $payload = [
        'model' => (string)$cfg['model'],
        'input' => $messages,
        'max_output_tokens' => (int)$cfg['max_tokens'],
        'temperature' => (float)$cfg['temperature'],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'whatsapp_group_analysis',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'precisa_intervencao' => ['type' => 'boolean'],
                        'nivel' => ['type' => 'string'],
                        'categoria' => ['type' => 'string'],
                        'resumo' => ['type' => 'string'],
                        'resposta_sugerida' => ['type' => 'string'],
                        'acoes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'tipo' => [
                                        'type' => 'string',
                                        'enum' => ['send_group_message', 'apply_tag', 'trigger_webhook', 'internal_alert'],
                                    ],
                                    'mensagem' => ['type' => ['string', 'null']],
                                    'resposta_sugerida' => ['type' => ['string', 'null']],
                                    'tag' => ['type' => ['string', 'null']],
                                    'event_name' => ['type' => ['string', 'null']],
                                    'telefone' => ['type' => ['string', 'null']],
                                    'user_id' => ['type' => ['integer', 'null']],
                                    'nome' => ['type' => ['string', 'null']],
                                    'observacao' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['tipo', 'mensagem', 'resposta_sugerida', 'tag', 'event_name', 'telefone', 'user_id', 'nome', 'observacao'],
                            ],
                        ],
                        'novo_contexto' => ['type' => 'string'],
                    ],
                    'required' => ['precisa_intervencao', 'nivel', 'categoria', 'resumo', 'resposta_sugerida', 'acoes', 'novo_contexto'],
                ],
                'strict' => true,
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new RuntimeException('Falha ao chamar OpenAI: ' . $err);
    }
    $decoded = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) ? (string)($decoded['error']['message'] ?? $raw) : $raw;
        throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . substr($msg, 0, 1000));
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta invalida da OpenAI.');
    }

    $text = '';
    if (isset($decoded['output_text'])) {
        $text = (string)$decoded['output_text'];
    } elseif (isset($decoded['output']) && is_array($decoded['output'])) {
        foreach ($decoded['output'] as $out) {
            foreach (($out['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $text .= (string)($content['text'] ?? '');
                }
            }
        }
    }
    $analysis = json_decode(trim($text), true);
    if (!is_array($analysis)) {
        throw new RuntimeException('OpenAI nao retornou JSON de analise valido.');
    }

    return ['raw' => $decoded, 'analysis' => $analysis, 'request' => $payload];
}

function whatsapp_ai_extension_from_mime(?string $mime): string {
    $mime = strtolower(trim((string)$mime));
    if (strpos($mime, 'mpeg') !== false || strpos($mime, 'mp3') !== false) return 'mp3';
    if (strpos($mime, 'ogg') !== false || strpos($mime, 'opus') !== false) return 'ogg';
    if (strpos($mime, 'wav') !== false) return 'wav';
    if (strpos($mime, 'mp4') !== false || strpos($mime, 'm4a') !== false) return 'm4a';
    if (strpos($mime, 'webm') !== false) return 'webm';
    return 'ogg';
}

function whatsapp_ai_audio_temp_file(array $message): string {
    $mime = (string)($message['media_mime'] ?? '');
    $ext = whatsapp_ai_extension_from_mime($mime);
    $tmp = tempnam(sys_get_temp_dir(), 'wai_audio_');
    if ($tmp === false) throw new RuntimeException('Nao foi possivel criar arquivo temporario de audio.');
    $file = $tmp . '.' . $ext;
    @rename($tmp, $file);

    $base64 = trim((string)($message['media_base64'] ?? ''));
    if ($base64 !== '') {
        $data = base64_decode(strtr($base64, '-_', '+/'), true);
        if ($data === false || $data === '') throw new RuntimeException('Base64 do audio invalido.');
        file_put_contents($file, $data);
        return $file;
    }

    $url = trim((string)($message['media_url'] ?? ''));
    if ($url === '') throw new RuntimeException('Audio sem URL ou base64 para transcricao.');
    if (!function_exists('curl_init')) throw new RuntimeException('Extensao cURL do PHP nao disponivel.');

    $fh = fopen($file, 'wb');
    if (!$fh) throw new RuntimeException('Nao foi possivel abrir arquivo temporario de audio.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_MAXFILESIZE => 15 * 1024 * 1024,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code >= 400 || filesize($file) <= 0) {
        @unlink($file);
        throw new RuntimeException('Falha ao baixar audio para transcricao: ' . ($err ?: 'HTTP ' . $code));
    }
    return $file;
}

function whatsapp_ai_transcribe_audio(PDO $pdo, array $cfg, array $message): ?string {
    $apiKey = (string)$cfg['openai_api_key'];
    if ($apiKey === '') return null;
    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        throw new RuntimeException('cURL/CURLFile nao disponivel para transcricao.');
    }

    $file = whatsapp_ai_audio_temp_file($message);
    try {
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => [
                'model' => (string)$cfg['transcription_model'],
                'file' => new CURLFile($file, (string)($message['media_mime'] ?? 'audio/ogg'), basename($file)),
                'response_format' => 'json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Falha na transcricao: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) ? (string)($decoded['error']['message'] ?? $raw) : $raw;
            throw new RuntimeException('Transcricao HTTP ' . $code . ': ' . substr($msg, 0, 1000));
        }
        $text = trim((string)($decoded['text'] ?? ''));
        $pdo->prepare("
            UPDATE whatsapp_ai_messages
               SET transcription_text=:text, transcription_status='done', transcription_error=NULL
             WHERE id=:id
        ")->execute([':text' => $text, ':id' => (int)$message['id']]);
        return $text;
    } finally {
        @unlink($file);
    }
}

function whatsapp_ai_prepare_media_for_messages(PDO $pdo, array $cfg, array $messages): array {
    foreach ($messages as &$message) {
        if ((string)($message['media_kind'] ?? '') !== 'audio') continue;
        if (trim((string)($message['transcription_text'] ?? '')) !== '') continue;
        if ((string)($message['transcription_status'] ?? '') === 'done') continue;
        try {
            $pdo->prepare("UPDATE whatsapp_ai_messages SET transcription_status='processing', transcription_error=NULL WHERE id=:id")
                ->execute([':id' => (int)$message['id']]);
            $text = whatsapp_ai_transcribe_audio($pdo, $cfg, $message);
            if ($text !== null && $text !== '') {
                $message['transcription_text'] = $text;
                $message['transcription_status'] = 'done';
            }
        } catch (Throwable $e) {
            $message['transcription_status'] = 'error';
            $message['transcription_error'] = $e->getMessage();
            $pdo->prepare("
                UPDATE whatsapp_ai_messages
                   SET transcription_status='error', transcription_error=:err
                 WHERE id=:id
            ")->execute([':err' => substr($e->getMessage(), 0, 1000), ':id' => (int)$message['id']]);
        }
    }
    unset($message);
    return $messages;
}

function whatsapp_ai_prune_contexts(PDO $pdo, string $groupId, int $keep): void {
    if ($keep <= 0) {
        $pdo->prepare("DELETE FROM whatsapp_ai_contexts WHERE group_id = :gid")->execute([':gid' => $groupId]);
        return;
    }
    $st = $pdo->prepare("
        DELETE c FROM whatsapp_ai_contexts c
        LEFT JOIN (
            SELECT id FROM whatsapp_ai_contexts
             WHERE group_id = :gid
             ORDER BY created_at DESC, id DESC
             LIMIT {$keep}
        ) keepers ON keepers.id = c.id
        WHERE c.group_id = :gid2 AND keepers.id IS NULL
    ");
    $st->execute([':gid' => $groupId, ':gid2' => $groupId]);
}

function whatsapp_ai_normalize_action_type(string $type): string {
    $type = strtolower(trim($type));
    $type = preg_replace('/[^a-z0-9_]+/', '_', $type) ?? $type;
    $map = [
        'responder' => 'send_group_message',
        'resposta' => 'send_group_message',
        'responder_grupo' => 'send_group_message',
        'enviar_mensagem' => 'send_group_message',
        'send_message' => 'send_group_message',
        'send_group_message' => 'send_group_message',
        'aplicar_tag' => 'apply_tag',
        'tag' => 'apply_tag',
        'apply_tag' => 'apply_tag',
        'disparar_webhook' => 'trigger_webhook',
        'webhook' => 'trigger_webhook',
        'trigger_webhook' => 'trigger_webhook',
        'alerta' => 'internal_alert',
        'criar_alerta' => 'internal_alert',
        'internal_alert' => 'internal_alert',
    ];
    return $map[$type] ?? ($type !== '' ? $type : 'internal_alert');
}

function whatsapp_ai_find_user_for_action(PDO $pdo, array $action): ?array {
    $userId = (int)($action['user_id'] ?? $action['aluno_id'] ?? $action['target_user_id'] ?? 0);
    if ($userId > 0) {
        $user = buscar_usuario_por_id($userId);
        if ($user) return $user;
    }

    $phone = (string)($action['telefone'] ?? $action['phone'] ?? $action['aluno_telefone'] ?? $action['target_phone'] ?? '');
    if ($phone !== '') {
        $user = evolution_find_user_by_phone($pdo, $phone);
        if ($user) return $user;
    }

    return null;
}

function whatsapp_ai_create_action(PDO $pdo, int $batchId, string $groupId, string $type, array $data): void {
    $type = whatsapp_ai_normalize_action_type($type);
    $user = whatsapp_ai_find_user_for_action($pdo, $data);
    $phone = evolution_clean_whatsapp_phone((string)($data['telefone'] ?? $data['phone'] ?? $data['aluno_telefone'] ?? $data['target_phone'] ?? ''));
    $message = trim((string)($data['message_text'] ?? $data['mensagem'] ?? $data['resposta'] ?? $data['resposta_sugerida'] ?? ''));
    $tag = trim((string)($data['tag'] ?? $data['tag_name'] ?? ''));
    $event = trim((string)($data['evento'] ?? $data['event'] ?? $data['event_name'] ?? ''));

    if ($type === 'apply_tag' && $tag === '') {
        $tag = 'IA_WHATSAPP_INTERVENCAO';
    }
    if ($type === 'trigger_webhook' && $event === '') {
        $event = 'IA_WHATSAPP_INTERVENCAO';
    }

    $st = $pdo->prepare("
        INSERT INTO whatsapp_ai_actions
            (batch_id, group_id, action_type, status, target_user_id, target_phone, target_name, tag_name, event_name, message_text, payload_json, created_at)
        VALUES
            (:batch_id, :group_id, :action_type, 'pending', :target_user_id, :target_phone, :target_name, :tag_name, :event_name, :message_text, :payload_json, NOW())
    ");
    $st->execute([
        ':batch_id' => $batchId,
        ':group_id' => $groupId,
        ':action_type' => $type,
        ':target_user_id' => $user ? (int)($user['id'] ?? 0) : null,
        ':target_phone' => $phone !== '' ? $phone : null,
        ':target_name' => $user ? (string)($user['nome'] ?? '') : (string)($data['nome'] ?? $data['name'] ?? ''),
        ':tag_name' => $tag !== '' ? $tag : null,
        ':event_name' => $event !== '' ? $event : null,
        ':message_text' => $message !== '' ? $message : null,
        ':payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function whatsapp_ai_queue_actions(PDO $pdo, int $batchId, string $groupId, array $analysis): void {
    $pdo->prepare("DELETE FROM whatsapp_ai_actions WHERE batch_id = :bid AND status = 'pending'")
        ->execute([':bid' => $batchId]);

    if (empty($analysis['precisa_intervencao'])) return;

    $suggested = trim((string)($analysis['resposta_sugerida'] ?? ''));
    if ($suggested !== '') {
        whatsapp_ai_create_action($pdo, $batchId, $groupId, 'send_group_message', [
            'resposta_sugerida' => $suggested,
            'categoria' => $analysis['categoria'] ?? null,
            'nivel' => $analysis['nivel'] ?? null,
            'origem' => 'suggested_response',
        ]);
    }

    $actions = $analysis['acoes'] ?? [];
    if (!is_array($actions)) return;
    foreach ($actions as $action) {
        if (!is_array($action)) continue;
        $type = (string)($action['tipo'] ?? $action['type'] ?? $action['acao'] ?? 'internal_alert');
        whatsapp_ai_create_action($pdo, $batchId, $groupId, $type, $action);
    }
}

function whatsapp_ai_send_group_message(PDO $pdo, string $groupId, string $message): array {
    $groupId = trim($groupId);
    $message = trim($message);
    if ($groupId === '' || $message === '') {
        throw new RuntimeException('Grupo ou mensagem vazios.');
    }

    $instanceKey = '';
    try {
        $st = $pdo->prepare("SELECT instance_key FROM whatsapp_groups WHERE group_id = :gid LIMIT 1");
        $st->execute([':gid' => $groupId]);
        $instanceKey = trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {}
    if ($instanceKey === '') {
        $instanceKey = trim((string)get_setting('evolution_webhook_instance_key', ''));
    }
    if ($instanceKey === '') {
        throw new RuntimeException('Instancia Evolution do grupo nao encontrada.');
    }

    $res = evolution_http('POST', '/message/sendText/' . rawurlencode($instanceKey), [
        'number' => $groupId,
        'text' => $message,
    ]);
    if (empty($res['ok'])) {
        $detail = trim((string)($res['raw'] ?? $res['error'] ?? ''));
        throw new RuntimeException('Falha ao enviar mensagem no grupo: ' . substr($detail, 0, 1000));
    }
    return $res;
}

function whatsapp_ai_approve_action(PDO $pdo, int $actionId, string $actor, ?string $messageOverride = null): array {
    whatsapp_ai_ensure_tables($pdo);
    $st = $pdo->prepare("SELECT * FROM whatsapp_ai_actions WHERE id = :id LIMIT 1");
    $st->execute([':id' => $actionId]);
    $action = $st->fetch(PDO::FETCH_ASSOC);
    if (!$action) throw new RuntimeException('Acao nao encontrada.');
    if ((string)$action['status'] !== 'pending') throw new RuntimeException('Acao ja processada.');

    $type = (string)$action['action_type'];
    $result = ['type' => $type, 'status' => 'executed'];
    $message = $messageOverride !== null ? trim($messageOverride) : trim((string)($action['message_text'] ?? ''));
    $userId = (int)($action['target_user_id'] ?? 0);

    try {
        if ($type === 'send_group_message') {
            $result = whatsapp_ai_send_group_message($pdo, (string)$action['group_id'], $message);
        } elseif ($type === 'apply_tag') {
            if ($userId <= 0) throw new RuntimeException('Acao de tag sem aluno identificado.');
            $tag = trim((string)($action['tag_name'] ?? ''));
            if ($tag === '') throw new RuntimeException('Nome da tag vazio.');
            if (!adicionar_tag($userId, $tag, 'whatsapp_ai', $actionId)) {
                throw new RuntimeException('Nao foi possivel aplicar a tag.');
            }
            $result = ['ok' => true, 'tag' => $tag, 'user_id' => $userId];
        } elseif ($type === 'trigger_webhook') {
            if ($userId <= 0) throw new RuntimeException('Webhook sem aluno identificado.');
            $event = trim((string)($action['event_name'] ?? ''));
            if ($event === '') throw new RuntimeException('Evento do webhook vazio.');
            disparar_webhooks($event, $userId, [
                'origem' => 'whatsapp_ai',
                'action_id' => $actionId,
                'batch_id' => (int)$action['batch_id'],
                'group_id' => (string)$action['group_id'],
                'payload' => json_decode((string)($action['payload_json'] ?? '{}'), true) ?: [],
            ]);
            $result = ['ok' => true, 'event' => $event, 'user_id' => $userId];
        } elseif ($type === 'internal_alert') {
            $result = ['ok' => true, 'alert' => true];
        } else {
            $result = ['ok' => true, 'manual_only' => true, 'type' => $type];
        }

        $up = $pdo->prepare("
            UPDATE whatsapp_ai_actions
               SET status='executed',
                   message_text=:message_text,
                   result_json=:result_json,
                   approved_by=:actor,
                   approved_at=NOW(),
                   executed_at=NOW(),
                   error_message=NULL
             WHERE id=:id
        ");
        $up->execute([
            ':message_text' => $message !== '' ? $message : null,
            ':result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':actor' => $actor,
            ':id' => $actionId,
        ]);
        return $result;
    } catch (Throwable $e) {
        $pdo->prepare("
            UPDATE whatsapp_ai_actions
               SET status='error', approved_by=:actor, approved_at=NOW(), error_message=:err
             WHERE id=:id
        ")->execute([
            ':actor' => $actor,
            ':err' => substr($e->getMessage(), 0, 1000),
            ':id' => $actionId,
        ]);
        throw $e;
    }
}

function whatsapp_ai_ignore_action(PDO $pdo, int $actionId, string $actor): void {
    $pdo->prepare("
        UPDATE whatsapp_ai_actions
           SET status='ignored', ignored_by=:actor, ignored_at=NOW()
         WHERE id=:id AND status='pending'
    ")->execute([':actor' => $actor, ':id' => $actionId]);
}

function whatsapp_ai_resolve_batch(PDO $pdo, int $batchId, string $actor): void {
    $pdo->prepare("
        UPDATE whatsapp_ai_actions
           SET status='ignored', ignored_by=:actor, ignored_at=NOW()
         WHERE batch_id=:bid AND status='pending'
    ")->execute([':actor' => $actor, ':bid' => $batchId]);
}

function whatsapp_ai_requeue_batch(PDO $pdo, int $batchId, string $actor): int {
    if ($batchId <= 0) throw new RuntimeException('Pacote invalido.');

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();

    try {
        $st = $pdo->prepare("SELECT id, status FROM whatsapp_ai_batches WHERE id = :id LIMIT 1 FOR UPDATE");
        $st->execute([':id' => $batchId]);
        $batch = $st->fetch(PDO::FETCH_ASSOC);
        if (!$batch) throw new RuntimeException('Pacote nao encontrado.');
        if ((string)$batch['status'] !== 'error') {
            throw new RuntimeException('Somente pacotes com erro podem ser reprocessados por aqui.');
        }

        $msgSt = $pdo->prepare("UPDATE whatsapp_ai_messages SET processed_batch_id = NULL WHERE processed_batch_id = :id");
        $msgSt->execute([':id' => $batchId]);
        $messageCount = $msgSt->rowCount();
        if ($messageCount <= 0) {
            throw new RuntimeException('Nenhuma mensagem encontrada para reprocessar neste pacote.');
        }

        $pdo->prepare("
            UPDATE whatsapp_ai_batches
               SET status='requeued',
                   error_message=:msg,
                   processed_at=NOW()
             WHERE id=:id
        ")->execute([
            ':msg' => 'Reaberto para reprocessamento por ' . $actor,
            ':id' => $batchId,
        ]);

        $pdo->prepare("DELETE FROM whatsapp_ai_actions WHERE batch_id = :id AND status IN ('pending', 'error')")
            ->execute([':id' => $batchId]);

        if ($ownTransaction) $pdo->commit();
        return $messageCount;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function whatsapp_ai_process_due(PDO $pdo, int $limitGroups = 10): array {
    whatsapp_ai_ensure_tables($pdo);
    $cfg = whatsapp_ai_get_config();
    $runId = 0;
    $stats = ['groups_processed' => 0, 'batches_created' => 0, 'messages_processed' => 0, 'skipped' => false, 'error' => null];

    $stRun = $pdo->prepare("INSERT INTO whatsapp_ai_runs (status, started_at) VALUES ('running', NOW())");
    $stRun->execute();
    $runId = (int)$pdo->lastInsertId();

    try {
        if (!whatsapp_ai_is_active_now($cfg)) {
            $stats['skipped'] = true;
            $pdo->prepare("UPDATE whatsapp_ai_runs SET status='skipped', finished_at=NOW() WHERE id=:id")->execute([':id' => $runId]);
            return $stats;
        }

        $minutes = (int)$cfg['interval_minutes'];
        $groups = $pdo->query("
            SELECT m.group_id, MIN(m.message_at) AS first_at, MAX(m.message_at) AS last_at, COUNT(*) AS total
              FROM whatsapp_ai_messages m
              LEFT JOIN whatsapp_groups g ON g.group_id = m.group_id
             WHERE m.processed_batch_id IS NULL
               AND COALESCE(g.is_ignored, 0) = 0
               AND m.message_at <= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
             GROUP BY m.group_id
             ORDER BY last_at ASC
             LIMIT " . max(1, min(50, $limitGroups)) . "
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($groups as $g) {
            $groupId = (string)$g['group_id'];
            $maxMessages = (int)$cfg['max_messages'];
            $stMsgs = $pdo->prepare("
                SELECT m.*, u.nome AS aluno_nome, u.email AS aluno_email, wg.group_name
                  FROM whatsapp_ai_messages m
                  LEFT JOIN users u ON u.id = m.user_id
                  LEFT JOIN whatsapp_groups wg ON wg.group_id = m.group_id
                 WHERE m.group_id = :gid
                   AND m.processed_batch_id IS NULL
                 ORDER BY m.message_at ASC, m.id ASC
                 LIMIT {$maxMessages}
            ");
            $stMsgs->execute([':gid' => $groupId]);
            $messages = $stMsgs->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$messages) continue;
            $messages = whatsapp_ai_prepare_media_for_messages($pdo, $cfg, $messages);

            $groupName = trim((string)($messages[0]['group_name'] ?? '')) ?: $groupId;
            $ctxSt = $pdo->prepare("SELECT summary FROM whatsapp_ai_contexts WHERE group_id = :gid ORDER BY created_at DESC, id DESC LIMIT " . (int)$cfg['context_keep']);
            $ctxSt->execute([':gid' => $groupId]);
            $contexts = array_reverse(array_map(static fn($r) => (string)$r['summary'], $ctxSt->fetchAll(PDO::FETCH_ASSOC) ?: []));
            $promptMessages = whatsapp_ai_build_prompt($cfg, $groupName, $contexts, $messages);

            $batchSt = $pdo->prepare("
                INSERT INTO whatsapp_ai_batches
                    (group_id, status, model, window_start, window_end, message_count, prompt_text, created_at)
                VALUES
                    (:gid, 'processing', :model, :start, :end, :count, :prompt, NOW())
            ");
            $batchSt->execute([
                ':gid' => $groupId,
                ':model' => (string)$cfg['model'],
                ':start' => (string)$messages[0]['message_at'],
                ':end' => (string)$messages[count($messages) - 1]['message_at'],
                ':count' => count($messages),
                ':prompt' => json_encode($promptMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $batchId = (int)$pdo->lastInsertId();
            $ids = implode(',', array_map('intval', array_column($messages, 'id')));
            $pdo->exec("UPDATE whatsapp_ai_messages SET processed_batch_id = {$batchId} WHERE id IN ({$ids})");

            try {
                $res = whatsapp_ai_call_openai($cfg, $promptMessages);
                $a = $res['analysis'];
                $usage = $res['raw']['usage'] ?? [];
                $up = $pdo->prepare("
                    UPDATE whatsapp_ai_batches
                       SET status='done',
                           request_json=:request_json,
                           response_json=:response_json,
                           summary=:summary,
                           decision=:decision,
                           category=:category,
                           severity=:severity,
                           needs_intervention=:needs,
                           suggested_response=:suggested,
                           next_context=:next_context,
                           input_tokens=:input_tokens,
                           output_tokens=:output_tokens,
                           total_tokens=:total_tokens,
                           processed_at=NOW()
                     WHERE id=:id
                ");
                $up->execute([
                    ':request_json' => json_encode($res['request'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':response_json' => json_encode($res['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':summary' => (string)($a['resumo'] ?? ''),
                    ':decision' => !empty($a['precisa_intervencao']) ? 'intervention' : 'no_action',
                    ':category' => (string)($a['categoria'] ?? ''),
                    ':severity' => (string)($a['nivel'] ?? ''),
                    ':needs' => !empty($a['precisa_intervencao']) ? 1 : 0,
                    ':suggested' => (string)($a['resposta_sugerida'] ?? ''),
                    ':next_context' => (string)($a['novo_contexto'] ?? ''),
                    ':input_tokens' => isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : null,
                    ':output_tokens' => isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : null,
                    ':total_tokens' => isset($usage['total_tokens']) ? (int)$usage['total_tokens'] : null,
                    ':id' => $batchId,
                ]);
                $nextContext = trim((string)($a['novo_contexto'] ?? ''));
                if ($nextContext !== '') {
                    $ctxIns = $pdo->prepare("INSERT INTO whatsapp_ai_contexts (group_id, batch_id, summary, created_at) VALUES (:gid, :bid, :summary, NOW())");
                    $ctxIns->execute([':gid' => $groupId, ':bid' => $batchId, ':summary' => $nextContext]);
                    whatsapp_ai_prune_contexts($pdo, $groupId, (int)$cfg['context_keep']);
                }
                whatsapp_ai_queue_actions($pdo, $batchId, $groupId, $a);
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE whatsapp_ai_batches SET status='error', error_message=:err, processed_at=NOW() WHERE id=:id")
                    ->execute([':err' => substr($e->getMessage(), 0, 1000), ':id' => $batchId]);
            }

            $stats['groups_processed']++;
            $stats['batches_created']++;
            $stats['messages_processed'] += count($messages);
        }

        $pdo->prepare("
            UPDATE whatsapp_ai_runs
               SET status='done', groups_processed=:g, batches_created=:b, messages_processed=:m, finished_at=NOW()
             WHERE id=:id
        ")->execute([
            ':g' => $stats['groups_processed'],
            ':b' => $stats['batches_created'],
            ':m' => $stats['messages_processed'],
            ':id' => $runId,
        ]);
        return $stats;
    } catch (Throwable $e) {
        $stats['error'] = $e->getMessage();
        if ($runId > 0) {
            $pdo->prepare("UPDATE whatsapp_ai_runs SET status='error', error_message=:err, finished_at=NOW() WHERE id=:id")
                ->execute([':err' => substr($e->getMessage(), 0, 1000), ':id' => $runId]);
        }
        return $stats;
    }
}
