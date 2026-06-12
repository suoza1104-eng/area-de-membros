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
            message_text LONGTEXT NOT NULL,
            message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_batch_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_wam_raw_log (raw_log_id),
            KEY idx_wam_group_time (group_id, message_at),
            KEY idx_wam_processed (processed_batch_id),
            KEY idx_wam_phone (sender_phone),
            KEY idx_wam_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

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
    if ($text === '') return null;

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

    $messageType = '';
    foreach (array_keys($message) as $k) {
        if (substr((string)$k, -7) === 'Message' || $k === 'conversation') {
            $messageType = (string)$k;
            break;
        }
    }
    if ($messageType === '') $messageType = $eventType ?: 'message';

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
            (raw_log_id, instance_key, group_id, sender_phone, sender_id, sender_name, user_id, message_type, message_text, message_at, created_at)
        VALUES
            (:raw_log_id, :instance_key, :group_id, :sender_phone, :sender_id, :sender_name, :user_id, :message_type, :message_text, :message_at, NOW())
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
        ':message_text' => $fields['message_text'],
        ':message_at' => $fields['message_at'],
    ]);
    return (int)$pdo->lastInsertId();
}

function whatsapp_ai_build_prompt(array $cfg, string $groupName, array $contexts, array $messages): array {
    $lines = [];
    foreach ($messages as $m) {
        $name = trim((string)($m['aluno_nome'] ?? ''));
        if ($name === '') $name = trim((string)($m['sender_name'] ?? ''));
        if ($name === '') $name = 'Participante';
        $phone = trim((string)($m['sender_phone'] ?? ''));
        $label = $name . ($phone !== '' ? ' (' . $phone . ')' : '');
        $lines[] = '[' . (string)$m['message_at'] . '] ' . $label . ': ' . trim((string)$m['message_text']);
    }

    return [
        ['role' => 'system', 'content' => (string)$cfg['prompt']],
        ['role' => 'user', 'content' => json_encode([
            'grupo' => $groupName,
            'criterios_adicionais' => (string)$cfg['criteria'],
            'contextos_anteriores' => array_values($contexts),
            'mensagens' => $lines,
            'instrucao_saida' => 'Responda somente JSON valido com as chaves: precisa_intervencao, nivel, categoria, resumo, resposta_sugerida, acoes, novo_contexto.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
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
                        'acoes' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                        'novo_contexto' => ['type' => 'string'],
                    ],
                    'required' => ['precisa_intervencao', 'nivel', 'categoria', 'resumo', 'resposta_sugerida', 'acoes', 'novo_contexto'],
                ],
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

