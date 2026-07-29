<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function telegram_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_groups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        chat_id BIGINT NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL DEFAULT '',
        username VARCHAR(120) NULL,
        type VARCHAR(40) NOT NULL DEFAULT 'group',
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        member_count INT UNSIGNED NOT NULL DEFAULT 0,
        joined_count INT UNSIGNED NOT NULL DEFAULT 0,
        left_count INT UNSIGNED NOT NULL DEFAULT 0,
        message_count INT UNSIGNED NOT NULL DEFAULT 0,
        bot_status VARCHAR(40) NULL,
        can_restrict_members TINYINT(1) NOT NULL DEFAULT 0,
        can_delete_messages TINYINT(1) NOT NULL DEFAULT 0,
        last_event_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_tg_groups_status (status),
        KEY idx_tg_groups_last_event (last_event_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_members (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        telegram_user_id BIGINT NOT NULL,
        username VARCHAR(120) NULL,
        first_name VARCHAR(160) NULL,
        last_name VARCHAR(160) NULL,
        language_code VARCHAR(20) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'member',
        is_bot TINYINT(1) NOT NULL DEFAULT 0,
        joined_at DATETIME NULL,
        left_at DATETIME NULL,
        last_seen_at DATETIME NULL,
        message_count INT UNSIGNED NOT NULL DEFAULT 0,
        risk_score DECIMAL(5,2) NOT NULL DEFAULT 0,
        last_ai_label VARCHAR(80) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uk_tg_member (group_id, telegram_user_id),
        KEY idx_tg_member_status (status),
        KEY idx_tg_member_user (telegram_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        update_id BIGINT NULL,
        group_id BIGINT UNSIGNED NULL,
        telegram_user_id BIGINT NULL,
        event_type VARCHAR(60) NOT NULL,
        message_id BIGINT NULL,
        message_text TEXT NULL,
        payload_json LONGTEXT NULL,
        processed_status VARCHAR(30) NOT NULL DEFAULT 'processed',
        error_message TEXT NULL,
        received_at DATETIME NOT NULL,
        KEY idx_tg_events_group (group_id),
        KEY idx_tg_events_user (telegram_user_id),
        KEY idx_tg_events_type (event_type),
        KEY idx_tg_events_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_auto_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NULL,
        name VARCHAR(180) NOT NULL,
        trigger_type VARCHAR(40) NOT NULL DEFAULT 'scheduled',
        message_kind VARCHAR(30) NOT NULL DEFAULT 'text',
        message_text TEXT NOT NULL,
        media_url VARCHAR(1000) NULL,
        buttons_json LONGTEXT NULL,
        parse_mode VARCHAR(20) NULL,
        send_at DATETIME NULL,
        repeat_minutes INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_sent_at DATETIME NULL,
        next_run_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_tg_auto_status (status),
        KEY idx_tg_auto_next (next_run_at),
        KEY idx_tg_auto_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    telegram_add_column_if_missing($pdo, 'telegram_auto_messages', 'message_kind', "ALTER TABLE telegram_auto_messages ADD COLUMN message_kind VARCHAR(30) NOT NULL DEFAULT 'text' AFTER trigger_type");
    telegram_add_column_if_missing($pdo, 'telegram_auto_messages', 'media_url', "ALTER TABLE telegram_auto_messages ADD COLUMN media_url VARCHAR(1000) NULL AFTER message_text");
    telegram_add_column_if_missing($pdo, 'telegram_auto_messages', 'buttons_json', "ALTER TABLE telegram_auto_messages ADD COLUMN buttons_json LONGTEXT NULL AFTER media_url");
    telegram_add_column_if_missing($pdo, 'telegram_auto_messages', 'parse_mode', "ALTER TABLE telegram_auto_messages ADD COLUMN parse_mode VARCHAR(20) NULL AFTER buttons_json");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_ai_rules (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        group_id BIGINT UNSIGNED NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        mode VARCHAR(30) NOT NULL DEFAULT 'suggest',
        prompt TEXT NOT NULL,
        min_confidence DECIMAL(4,2) NOT NULL DEFAULT 0.80,
        action_policy VARCHAR(40) NOT NULL DEFAULT 'reply_only',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_tg_ai_enabled (is_enabled),
        KEY idx_tg_ai_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_ai_actions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id BIGINT UNSIGNED NULL,
        group_id BIGINT UNSIGNED NULL,
        telegram_user_id BIGINT NULL,
        rule_id BIGINT UNSIGNED NULL,
        action VARCHAR(40) NOT NULL DEFAULT 'none',
        confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
        reason TEXT NULL,
        suggested_reply TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        executed_at DATETIME NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_tg_ai_status (status),
        KEY idx_tg_ai_group (group_id),
        KEY idx_tg_ai_event (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function telegram_add_column_if_missing(PDO $pdo, string $table, string $column, string $sql): void
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
        $st->execute(['column'=>$column]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
    } catch (Throwable $e) {}
}

function telegram_setting(string $key, string $default = ''): string
{
    return trim((string)get_setting('telegram_' . $key, $default));
}

function telegram_set_setting(string $key, string $value): void
{
    set_setting('telegram_' . $key, $value);
}

function telegram_bot_token(): string
{
    return telegram_setting('bot_token');
}

function telegram_webhook_secret(): string
{
    $secret = telegram_setting('webhook_secret');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(24));
        telegram_set_setting('webhook_secret', $secret);
    }
    return $secret;
}

function telegram_webhook_url(): string
{
    return rtrim(BASE_URL, '/') . '/telegram_webhook.php?token=' . rawurlencode(telegram_webhook_secret());
}

function telegram_api_request(string $method, array $payload = []): array
{
    $token = telegram_bot_token();
    if ($token === '') throw new RuntimeException('Token do bot Telegram nao configurado.');
    if (!function_exists('curl_init')) throw new RuntimeException('Extensao cURL nao habilitada.');
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') throw new RuntimeException('Falha HTTP Telegram: ' . ($err ?: 'resposta vazia'));
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('Telegram retornou JSON invalido.');
    if ($code < 200 || $code >= 300 || empty($json['ok'])) {
        throw new RuntimeException('Telegram HTTP ' . $code . ': ' . substr((string)($json['description'] ?? $raw), 0, 800));
    }
    return $json;
}

function telegram_install_webhook(): array
{
    telegram_refresh_bot_profile();
    return telegram_api_request('setWebhook', [
        'url' => telegram_webhook_url(),
        'secret_token' => telegram_webhook_secret(),
        'allowed_updates' => ['message', 'edited_message', 'chat_member', 'my_chat_member'],
        'drop_pending_updates' => false,
    ]);
}

function telegram_refresh_bot_profile(): array
{
    $res = telegram_api_request('getMe');
    $bot = is_array($res['result'] ?? null) ? $res['result'] : [];
    if (!empty($bot['username'])) telegram_set_setting('bot_username', (string)$bot['username']);
    if (!empty($bot['id'])) telegram_set_setting('bot_id', (string)$bot['id']);
    return $bot;
}

function telegram_private_bot_url(): string
{
    $username = telegram_setting('bot_username');
    return $username !== '' ? 'https://t.me/' . ltrim($username, '@') : '';
}

function telegram_reply_markup(array|string|null $buttons): ?array
{
    if (is_string($buttons)) $buttons = json_decode($buttons, true);
    if (!is_array($buttons) || !$buttons) return null;
    $rows = [];
    foreach ($buttons as $button) {
        if (!is_array($button)) continue;
        $text = trim((string)($button['text'] ?? ''));
        $url = trim((string)($button['url'] ?? ''));
        if ($text === '' || $url === '') continue;
        if (!preg_match('~^(https://|tg://)~i', $url)) continue;
        $rows[] = [['text'=>mb_substr($text, 0, 64), 'url'=>$url]];
    }
    return $rows ? ['inline_keyboard'=>$rows] : null;
}

function telegram_send_message(int|string $chatId, string $text): array
{
    return telegram_send_rich_message($chatId, ['message_kind'=>'text','message_text'=>$text]);
}

function telegram_send_rich_message(int|string $chatId, array $message, array $ctx = []): array
{
    $kind = in_array((string)($message['message_kind'] ?? 'text'), ['text','photo','video'], true) ? (string)$message['message_kind'] : 'text';
    $text = trim(telegram_render_vars((string)($message['message_text'] ?? ''), $ctx));
    $mediaUrl = trim(telegram_render_vars((string)($message['media_url'] ?? ''), $ctx));
    $buttons = telegram_reply_markup($message['buttons_json'] ?? null);
    $parseMode = in_array((string)($message['parse_mode'] ?? ''), ['HTML','MarkdownV2'], true) ? (string)$message['parse_mode'] : null;
    if ($text === '' && $kind === 'text') throw new InvalidArgumentException('Mensagem vazia.');
    $payload = ['chat_id'=>$chatId];
    if ($buttons) $payload['reply_markup'] = $buttons;
    if ($parseMode) $payload['parse_mode'] = $parseMode;
    if ($kind === 'photo') {
        if ($mediaUrl === '') throw new InvalidArgumentException('Informe a URL da imagem.');
        $payload['photo'] = $mediaUrl;
        if ($text !== '') $payload['caption'] = mb_substr($text, 0, 1024);
        return telegram_api_request('sendPhoto', $payload);
    }
    if ($kind === 'video') {
        if ($mediaUrl === '') throw new InvalidArgumentException('Informe a URL do video.');
        $payload['video'] = $mediaUrl;
        if ($text !== '') $payload['caption'] = mb_substr($text, 0, 1024);
        return telegram_api_request('sendVideo', $payload);
    }
    $payload['text'] = mb_substr($text, 0, 3900);
    $payload['disable_web_page_preview'] = true;
    return telegram_api_request('sendMessage', $payload);
}

function telegram_ban_member(int|string $chatId, int|string $userId, bool $deleteMessages = false): array
{
    return telegram_api_request('banChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
        'revoke_messages' => $deleteMessages,
    ]);
}

function telegram_render_vars(string $text, array $ctx): string
{
    $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : [];
    $chat = is_array($ctx['chat'] ?? null) ? $ctx['chat'] : [];
    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return strtr($text, [
        '{{nome}}' => $name !== '' ? $name : (string)($user['username'] ?? ''),
        '{{username}}' => (string)($user['username'] ?? ''),
        '{{grupo}}' => (string)($chat['title'] ?? ''),
        '{{chat_id}}' => (string)($chat['id'] ?? ''),
        '{{telegram_id}}' => (string)($user['id'] ?? ''),
    ]);
}

function telegram_upsert_group(PDO $pdo, array $chat, array $botMember = []): int
{
    $chatId = (int)($chat['id'] ?? 0);
    if ($chatId === 0) return 0;
    $title = trim((string)($chat['title'] ?? $chat['username'] ?? ('Chat ' . $chatId)));
    $pdo->prepare("INSERT INTO telegram_groups
        (chat_id,title,username,type,status,bot_status,can_restrict_members,can_delete_messages,last_event_at,created_at,updated_at)
        VALUES (:chat,:title,:username,:type,'active',:bot,:restrict,:delete,NOW(),NOW(),NOW())
        ON DUPLICATE KEY UPDATE title=VALUES(title),username=VALUES(username),type=VALUES(type),status='active',
        bot_status=COALESCE(VALUES(bot_status),bot_status),can_restrict_members=GREATEST(can_restrict_members,VALUES(can_restrict_members)),
        can_delete_messages=GREATEST(can_delete_messages,VALUES(can_delete_messages)),last_event_at=NOW(),updated_at=NOW()")
        ->execute([
            'chat'=>$chatId,
            'title'=>$title,
            'username'=>trim((string)($chat['username'] ?? '')) ?: null,
            'type'=>(string)($chat['type'] ?? 'group'),
            'bot'=>trim((string)($botMember['status'] ?? '')) ?: null,
            'restrict'=>!empty($botMember['can_restrict_members']) ? 1 : 0,
            'delete'=>!empty($botMember['can_delete_messages']) ? 1 : 0,
        ]);
    $st = $pdo->prepare('SELECT id FROM telegram_groups WHERE chat_id=:chat LIMIT 1');
    $st->execute(['chat'=>$chatId]);
    return (int)$st->fetchColumn();
}

function telegram_upsert_member(PDO $pdo, int $groupId, array $user, string $status = 'member'): void
{
    if ($groupId <= 0 || empty($user['id'])) return;
    $pdo->prepare("INSERT INTO telegram_members
        (group_id,telegram_user_id,username,first_name,last_name,language_code,status,is_bot,joined_at,last_seen_at,created_at,updated_at)
        VALUES (:group_id,:user_id,:username,:first_name,:last_name,:language_code,:status,:is_bot,IF(:joined=1,NOW(),NULL),NOW(),NOW(),NOW())
        ON DUPLICATE KEY UPDATE username=VALUES(username),first_name=VALUES(first_name),last_name=VALUES(last_name),
        language_code=VALUES(language_code),status=VALUES(status),is_bot=VALUES(is_bot),last_seen_at=NOW(),
        joined_at=IF(VALUES(joined_at) IS NOT NULL,COALESCE(joined_at,VALUES(joined_at)),joined_at),
        left_at=IF(:lefted=1,NOW(),left_at),updated_at=NOW()")
        ->execute([
            'group_id'=>$groupId,
            'user_id'=>(int)$user['id'],
            'username'=>trim((string)($user['username'] ?? '')) ?: null,
            'first_name'=>trim((string)($user['first_name'] ?? '')) ?: null,
            'last_name'=>trim((string)($user['last_name'] ?? '')) ?: null,
            'language_code'=>trim((string)($user['language_code'] ?? '')) ?: null,
            'status'=>$status,
            'is_bot'=>!empty($user['is_bot']) ? 1 : 0,
            'joined'=>in_array($status, ['member','administrator','creator'], true) ? 1 : 0,
            'lefted'=>in_array($status, ['left','kicked'], true) ? 1 : 0,
        ]);
}

function telegram_record_event(PDO $pdo, ?int $updateId, int $groupId, ?int $userId, string $type, ?int $messageId, string $text, array $payload): int
{
    $pdo->prepare("INSERT INTO telegram_events
        (update_id,group_id,telegram_user_id,event_type,message_id,message_text,payload_json,received_at)
        VALUES (:update_id,:group_id,:user_id,:type,:message_id,:text,:payload,NOW())")
        ->execute([
            'update_id'=>$updateId,
            'group_id'=>$groupId > 0 ? $groupId : null,
            'user_id'=>$userId,
            'type'=>$type,
            'message_id'=>$messageId,
            'text'=>$text !== '' ? mb_substr($text, 0, 60000) : null,
            'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    return (int)$pdo->lastInsertId();
}

function telegram_handle_update(PDO $pdo, array $update): array
{
    telegram_ensure_schema($pdo);
    $updateId = isset($update['update_id']) ? (int)$update['update_id'] : null;
    if (isset($update['my_chat_member'])) {
        return telegram_handle_chat_member($pdo, $updateId, $update['my_chat_member'], true);
    }
    if (isset($update['chat_member'])) {
        return telegram_handle_chat_member($pdo, $updateId, $update['chat_member'], false);
    }
    if (isset($update['message']) || isset($update['edited_message'])) {
        return telegram_handle_message($pdo, $updateId, $update['message'] ?? $update['edited_message'], isset($update['edited_message']));
    }
    telegram_record_event($pdo, $updateId, 0, null, 'ignored', null, '', $update);
    return ['ok'=>true,'type'=>'ignored'];
}

function telegram_handle_chat_member(PDO $pdo, ?int $updateId, array $cm, bool $botUpdate): array
{
    $chat = is_array($cm['chat'] ?? null) ? $cm['chat'] : [];
    $new = is_array($cm['new_chat_member'] ?? null) ? $cm['new_chat_member'] : [];
    $old = is_array($cm['old_chat_member'] ?? null) ? $cm['old_chat_member'] : [];
    $user = is_array($new['user'] ?? null) ? $new['user'] : (is_array($cm['from'] ?? null) ? $cm['from'] : []);
    $groupId = telegram_upsert_group($pdo, $chat, $botUpdate ? $new : []);
    $status = (string)($new['status'] ?? 'member');
    if (!$botUpdate) telegram_upsert_member($pdo, $groupId, $user, $status);
    $type = $botUpdate ? 'bot_status' : (in_array($status, ['left','kicked'], true) ? 'member_left' : 'member_joined');
    telegram_record_event($pdo, $updateId, $groupId, isset($user['id']) ? (int)$user['id'] : null, $type, null, '', $cm);
    if ($groupId > 0 && !$botUpdate) {
        if ($type === 'member_joined') $pdo->prepare('UPDATE telegram_groups SET joined_count=joined_count+1,member_count=member_count+1,last_event_at=NOW(),updated_at=NOW() WHERE id=:id')->execute(['id'=>$groupId]);
        if ($type === 'member_left') $pdo->prepare('UPDATE telegram_groups SET left_count=left_count+1,member_count=IF(member_count>0,member_count-1,0),last_event_at=NOW(),updated_at=NOW() WHERE id=:id')->execute(['id'=>$groupId]);
        telegram_run_auto_messages($pdo, $groupId, $type, ['user'=>$user,'chat'=>$chat]);
    }
    return ['ok'=>true,'type'=>$type,'status'=>$status];
}

function telegram_handle_message(PDO $pdo, ?int $updateId, array $message, bool $edited = false): array
{
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $user = is_array($message['from'] ?? null) ? $message['from'] : [];
    $groupId = telegram_upsert_group($pdo, $chat);
    telegram_upsert_member($pdo, $groupId, $user, 'member');
    $text = trim((string)($message['text'] ?? $message['caption'] ?? ''));
    $eventId = telegram_record_event($pdo, $updateId, $groupId, isset($user['id']) ? (int)$user['id'] : null, $edited ? 'message_edited' : 'message', isset($message['message_id']) ? (int)$message['message_id'] : null, $text, $message);
    if ($groupId > 0) {
        $pdo->prepare('UPDATE telegram_groups SET message_count=message_count+1,last_event_at=NOW(),updated_at=NOW() WHERE id=:id')->execute(['id'=>$groupId]);
        if (!empty($user['id'])) $pdo->prepare('UPDATE telegram_members SET message_count=message_count+1,last_seen_at=NOW(),updated_at=NOW() WHERE group_id=:g AND telegram_user_id=:u')->execute(['g'=>$groupId,'u'=>(int)$user['id']]);
    }
    if ($text !== '' && !$edited) telegram_evaluate_ai($pdo, $eventId, $groupId, (int)($user['id'] ?? 0), $text, ['chat'=>$chat,'user'=>$user,'message'=>$message]);
    return ['ok'=>true,'type'=>$edited ? 'message_edited' : 'message'];
}

function telegram_run_auto_messages(PDO $pdo, int $groupId, string $trigger, array $ctx): void
{
    $st = $pdo->prepare("SELECT * FROM telegram_auto_messages WHERE status='active' AND trigger_type=:trigger AND (group_id IS NULL OR group_id=:group_id) ORDER BY id ASC");
    $st->execute(['trigger'=>$trigger,'group_id'=>$groupId]);
    $groupChat = $pdo->prepare('SELECT chat_id FROM telegram_groups WHERE id=:id LIMIT 1');
    $groupChat->execute(['id'=>$groupId]);
    $chatId = (string)$groupChat->fetchColumn();
    if ($chatId === '') return;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        telegram_send_rich_message($chatId, $row, $ctx);
        $pdo->prepare('UPDATE telegram_auto_messages SET sent_count=sent_count+1,last_sent_at=NOW(),updated_at=NOW() WHERE id=:id')->execute(['id'=>(int)$row['id']]);
    }
}

function telegram_ai_output_text(array $response): string
{
    if (isset($response['output_text'])) return (string)$response['output_text'];
    foreach ($response['output'] ?? [] as $out) foreach ($out['content'] ?? [] as $c) if (isset($c['text'])) return (string)$c['text'];
    return '';
}

function telegram_call_openai(string $text, string $prompt): array
{
    $apiKey = trim((string)get_setting('whatsapp_ai_openai_api_key', ''));
    if ($apiKey === '') $apiKey = trim((string)get_setting('openai_api_key', ''));
    if ($apiKey === '') throw new RuntimeException('Chave OpenAI nao configurada.');
    $payload = [
        'model' => trim((string)get_setting('telegram_ai_model', 'gpt-5.4-mini')) ?: 'gpt-5.4-mini',
        'input' => [
            ['role'=>'system','content'=>$prompt . "\nRetorne apenas JSON: {\"action\":\"none|reply|ban|flag\",\"confidence\":0.0,\"reason\":\"...\",\"reply\":\"...\"}."],
            ['role'=>'user','content'=>$text],
        ],
        'max_output_tokens' => 700,
    ];
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT=>45,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') throw new RuntimeException('Falha OpenAI: ' . $err);
    $decoded = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . substr((string)($decoded['error']['message'] ?? $raw), 0, 800));
    $json = json_decode(telegram_ai_output_text(is_array($decoded) ? $decoded : []), true);
    if (!is_array($json)) throw new RuntimeException('IA retornou formato invalido.');
    return $json;
}

function telegram_generate_message_ai(string $instruction, array $context = []): array
{
    $instruction = trim($instruction);
    if ($instruction === '') throw new InvalidArgumentException('Descreva o que a IA deve criar.');
    $apiKey = trim((string)get_setting('whatsapp_ai_openai_api_key', ''));
    if ($apiKey === '') $apiKey = trim((string)get_setting('openai_api_key', ''));
    if ($apiKey === '') throw new RuntimeException('Chave OpenAI nao configurada.');
    $payload = [
        'model' => trim((string)get_setting('telegram_ai_model', 'gpt-5.4-mini')) ?: 'gpt-5.4-mini',
        'input' => [
            ['role'=>'system','content'=>'Voce cria mensagens para grupos Telegram de alunos. Seja direto, humano, com bom ritmo, sem exagerar em emojis. Pode usar variaveis: {{nome}}, {{username}}, {{grupo}}, {{chat_id}}, {{telegram_id}}. Retorne apenas JSON: {"name":"...","message":"...","button_suggestions":[{"text":"...","url":"https://..."}]}'],
            ['role'=>'user','content'=>json_encode(['instruction'=>$instruction,'context'=>$context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ],
        'max_output_tokens' => 900,
    ];
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT=>45,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') throw new RuntimeException('Falha OpenAI: ' . $err);
    $decoded = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . substr((string)($decoded['error']['message'] ?? $raw), 0, 800));
    $json = json_decode(telegram_ai_output_text(is_array($decoded) ? $decoded : []), true);
    if (!is_array($json)) throw new RuntimeException('IA retornou formato invalido.');
    return [
        'name' => mb_substr(trim((string)($json['name'] ?? 'Mensagem criada com IA')), 0, 180),
        'message' => trim((string)($json['message'] ?? '')),
        'button_suggestions' => is_array($json['button_suggestions'] ?? null) ? array_slice($json['button_suggestions'], 0, 4) : [],
    ];
}

function telegram_evaluate_ai(PDO $pdo, int $eventId, int $groupId, int $telegramUserId, string $text, array $ctx): void
{
    if (telegram_setting('ai_enabled', '0') !== '1') return;
    $st = $pdo->prepare("SELECT * FROM telegram_ai_rules WHERE is_enabled=1 AND (group_id IS NULL OR group_id=:group_id) ORDER BY group_id DESC,id ASC LIMIT 1");
    $st->execute(['group_id'=>$groupId]);
    $rule = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rule) return;
    try {
        $ai = telegram_call_openai($text, (string)$rule['prompt']);
        $action = in_array((string)($ai['action'] ?? 'none'), ['none','reply','ban','flag'], true) ? (string)$ai['action'] : 'none';
        $confidence = max(0, min(1, (float)($ai['confidence'] ?? 0)));
        $status = $confidence >= (float)$rule['min_confidence'] ? 'ready' : 'pending';
        $pdo->prepare("INSERT INTO telegram_ai_actions
            (event_id,group_id,telegram_user_id,rule_id,action,confidence,reason,suggested_reply,status,created_at,updated_at)
            VALUES (:event,:group_id,:user,:rule,:action,:confidence,:reason,:reply,:status,NOW(),NOW())")
            ->execute([
                'event'=>$eventId,
                'group_id'=>$groupId,
                'user'=>$telegramUserId ?: null,
                'rule'=>(int)$rule['id'],
                'action'=>$action,
                'confidence'=>$confidence,
                'reason'=>mb_substr((string)($ai['reason'] ?? ''), 0, 2000),
                'reply'=>mb_substr((string)($ai['reply'] ?? ''), 0, 3900),
                'status'=>$status,
            ]);
        $actionId = (int)$pdo->lastInsertId();
        if ($status === 'ready' && (string)$rule['mode'] === 'auto') telegram_execute_ai_action($pdo, $actionId);
    } catch (Throwable $e) {
        $pdo->prepare("INSERT INTO telegram_ai_actions (event_id,group_id,telegram_user_id,rule_id,action,status,error_message,created_at,updated_at) VALUES (:event,:group_id,:user,:rule,'none','failed',:error,NOW(),NOW())")
            ->execute(['event'=>$eventId,'group_id'=>$groupId,'user'=>$telegramUserId ?: null,'rule'=>(int)$rule['id'],'error'=>mb_substr($e->getMessage(),0,1000)]);
    }
}

function telegram_execute_ai_action(PDO $pdo, int $actionId): void
{
    $st = $pdo->prepare("SELECT a.*,g.chat_id FROM telegram_ai_actions a JOIN telegram_groups g ON g.id=a.group_id WHERE a.id=:id LIMIT 1");
    $st->execute(['id'=>$actionId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a || in_array((string)$a['status'], ['executed','ignored'], true)) return;
    try {
        $action = (string)$a['action'];
        if ($action === 'reply' && trim((string)$a['suggested_reply']) !== '') telegram_send_message((string)$a['chat_id'], (string)$a['suggested_reply']);
        elseif ($action === 'ban' && (int)$a['telegram_user_id'] !== 0) telegram_ban_member((string)$a['chat_id'], (int)$a['telegram_user_id'], true);
        elseif ($action === 'none') {
            $pdo->prepare("UPDATE telegram_ai_actions SET status='ignored',updated_at=NOW() WHERE id=:id")->execute(['id'=>$actionId]);
            return;
        }
        $pdo->prepare("UPDATE telegram_ai_actions SET status='executed',executed_at=NOW(),updated_at=NOW(),error_message=NULL WHERE id=:id")->execute(['id'=>$actionId]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE telegram_ai_actions SET status='failed',error_message=:error,updated_at=NOW() WHERE id=:id")->execute(['id'=>$actionId,'error'=>mb_substr($e->getMessage(),0,1000)]);
    }
}

function telegram_process_due(PDO $pdo, int $limit = 50): array
{
    telegram_ensure_schema($pdo);
    $stats = ['messages'=>0,'ai_actions'=>0,'errors'=>0];
    $st = $pdo->prepare("SELECT m.*,g.chat_id FROM telegram_auto_messages m LEFT JOIN telegram_groups g ON g.id=m.group_id WHERE m.status='active' AND m.trigger_type='scheduled' AND m.next_run_at IS NOT NULL AND m.next_run_at<=NOW() ORDER BY m.next_run_at ASC LIMIT :limit");
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
        try {
            $chatIds = [];
            if (!empty($m['chat_id'])) $chatIds[] = (string)$m['chat_id'];
            else $chatIds = array_map('strval', $pdo->query("SELECT chat_id FROM telegram_groups WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach ($chatIds as $chatId) telegram_send_rich_message($chatId, $m);
            $next = (int)$m['repeat_minutes'] > 0 ? date('Y-m-d H:i:s', time() + ((int)$m['repeat_minutes'] * 60)) : null;
            $status = $next ? 'active' : 'sent';
            $pdo->prepare("UPDATE telegram_auto_messages SET status=:status,sent_count=sent_count+1,last_sent_at=NOW(),next_run_at=:next,updated_at=NOW() WHERE id=:id")->execute(['status'=>$status,'next'=>$next,'id'=>(int)$m['id']]);
            $stats['messages']++;
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }
    $actions = $pdo->query("SELECT id FROM telegram_ai_actions WHERE status='ready' ORDER BY id ASC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($actions as $id) {
        telegram_execute_ai_action($pdo, (int)$id);
        $stats['ai_actions']++;
    }
    return $stats;
}
