<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

const VOICE_PROVIDER_TELNYX = 'telnyx';
const VOICE_PROVIDER_BASE_URL = 'https://api.telnyx.com';

function voice_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function voice_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_providers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(40) NOT NULL,
        name VARCHAR(160) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        environment VARCHAR(30) NOT NULL DEFAULT 'test',
        encrypted_credentials LONGTEXT NULL,
        public_configuration LONGTEXT NULL,
        connection_status VARCHAR(40) NOT NULL DEFAULT 'pending',
        last_tested_at DATETIME NULL,
        last_error VARCHAR(1000) NULL,
        created_by VARCHAR(150) NULL,
        updated_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_voice_provider (provider),
        KEY idx_voice_provider_enabled (enabled,provider)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_phone_numbers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider_id INT UNSIGNED NULL,
        provider_number_id VARCHAR(120) NULL,
        phone_e164 VARCHAR(32) NOT NULL,
        friendly_name VARCHAR(160) NULL,
        country VARCHAR(10) NULL,
        region VARCHAR(80) NULL,
        type VARCHAR(40) NULL,
        source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
        capabilities_json LONGTEXT NULL,
        inbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
        outbound_enabled TINYINT(1) NOT NULL DEFAULT 1,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        verification_status VARCHAR(40) NOT NULL DEFAULT 'manual',
        connection_id VARCHAR(120) NULL,
        outbound_profile_id VARCHAR(120) NULL,
        monthly_cost DECIMAL(12,4) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'active',
        metadata_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_voice_phone (phone_e164),
        KEY idx_voice_phone_provider (provider_id,is_default,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_campaigns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        description VARCHAR(500) NULL,
        provider_id INT UNSIGNED NULL,
        phone_number_id BIGINT UNSIGNED NULL,
        list_id BIGINT UNSIGNED NULL,
        message_mode VARCHAR(40) NOT NULL DEFAULT 'text_to_speech',
        audio_id BIGINT UNSIGNED NULL,
        voice_id VARCHAR(120) NULL,
        ai_assistant_id VARCHAR(120) NULL,
        message_template LONGTEXT NULL,
        machine_message_template LONGTEXT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'draft',
        scheduled_at DATETIME NULL,
        timezone VARCHAR(80) NOT NULL DEFAULT 'America/Sao_Paulo',
        allowed_start_time TIME NULL,
        allowed_end_time TIME NULL,
        allowed_weekdays_json LONGTEXT NULL,
        concurrency_limit INT UNSIGNED NOT NULL DEFAULT 1,
        calls_per_minute INT UNSIGNED NOT NULL DEFAULT 10,
        max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
        retry_strategy_json LONGTEXT NULL,
        answering_machine_detection TINYINT(1) NOT NULL DEFAULT 0,
        record_calls TINYINT(1) NOT NULL DEFAULT 0,
        transcribe_calls TINYINT(1) NOT NULL DEFAULT 0,
        gather_enabled TINYINT(1) NOT NULL DEFAULT 0,
        transfer_enabled TINYINT(1) NOT NULL DEFAULT 0,
        cost_limit DECIMAL(12,4) NULL,
        settings_json LONGTEXT NULL,
        totals_json LONGTEXT NULL,
        created_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_voice_campaign_status (status,scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_campaign_recipients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id BIGINT UNSIGNED NOT NULL,
        contact_id BIGINT UNSIGNED NULL,
        user_id INT NULL,
        name VARCHAR(180) NULL,
        first_name VARCHAR(100) NULL,
        phone_original VARCHAR(60) NULL,
        phone_e164 VARCHAR(32) NOT NULL,
        variables_json LONGTEXT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        attempts_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt_id BIGINT UNSIGNED NULL,
        scheduled_at DATETIME NULL,
        last_called_at DATETIME NULL,
        completed_at DATETIME NULL,
        final_result VARCHAR(60) NULL,
        answered_by VARCHAR(40) NULL,
        audio_completion VARCHAR(40) NULL,
        interaction_result VARCHAR(80) NULL,
        duration_seconds INT UNSIGNED NULL,
        billable_seconds INT UNSIGNED NULL,
        cost DECIMAL(12,5) NULL,
        currency VARCHAR(10) NULL,
        failure_code VARCHAR(80) NULL,
        failure_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_voice_recipient_due (status,scheduled_at),
        KEY idx_voice_recipient_campaign (campaign_id,status),
        KEY idx_voice_recipient_phone (phone_e164)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_call_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        campaign_id BIGINT UNSIGNED NULL,
        recipient_id BIGINT UNSIGNED NULL,
        user_id INT NULL,
        automation_run_id BIGINT UNSIGNED NULL,
        automation_job_id BIGINT UNSIGNED NULL,
        provider_id INT UNSIGNED NULL,
        provider_call_id VARCHAR(120) NULL,
        call_control_id VARCHAR(180) NULL,
        call_leg_id VARCHAR(180) NULL,
        call_session_id VARCHAR(180) NULL,
        command_id VARCHAR(120) NULL,
        idempotency_key VARCHAR(100) NOT NULL,
        from_number VARCHAR(32) NOT NULL,
        to_number VARCHAR(32) NOT NULL,
        attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(40) NOT NULL DEFAULT 'created',
        answered_by VARCHAR(40) NULL,
        hangup_cause VARCHAR(120) NULL,
        hangup_source VARCHAR(80) NULL,
        started_at DATETIME NULL,
        ringing_at DATETIME NULL,
        answered_at DATETIME NULL,
        audio_started_at DATETIME NULL,
        audio_ended_at DATETIME NULL,
        ended_at DATETIME NULL,
        duration_seconds INT UNSIGNED NULL,
        billable_seconds INT UNSIGNED NULL,
        cost DECIMAL(12,5) NULL,
        currency VARCHAR(10) NULL,
        recording_url VARCHAR(1000) NULL,
        transcription LONGTEXT NULL,
        provider_response_json LONGTEXT NULL,
        error_json LONGTEXT NULL,
        settings_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_voice_attempt_idem (idempotency_key),
        KEY idx_voice_attempt_call_control (call_control_id),
        KEY idx_voice_attempt_leg (call_leg_id),
        KEY idx_voice_attempt_session (call_session_id),
        KEY idx_voice_attempt_status (status,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE voice_call_attempts ADD COLUMN settings_json LONGTEXT NULL AFTER error_json"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(40) NOT NULL,
        provider_event_id VARCHAR(180) NOT NULL,
        event_type VARCHAR(120) NOT NULL,
        normalized_event VARCHAR(80) NOT NULL,
        call_control_id VARCHAR(180) NULL,
        call_leg_id VARCHAR(180) NULL,
        call_session_id VARCHAR(180) NULL,
        campaign_id BIGINT UNSIGNED NULL,
        recipient_id BIGINT UNSIGNED NULL,
        attempt_id BIGINT UNSIGNED NULL,
        user_id INT NULL,
        occurred_at DATETIME NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        processing_status VARCHAR(40) NOT NULL DEFAULT 'received',
        signature_valid TINYINT(1) NOT NULL DEFAULT 0,
        payload_json LONGTEXT NULL,
        processing_error VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_voice_event (provider,provider_event_id),
        KEY idx_voice_event_attempt (attempt_id,event_type),
        KEY idx_voice_event_type (event_type,occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_media (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider_id INT UNSIGNED NULL,
        name VARCHAR(180) NOT NULL,
        media_type VARCHAR(40) NOT NULL DEFAULT 'uploaded_audio',
        source VARCHAR(40) NOT NULL DEFAULT 'local',
        local_path VARCHAR(500) NULL,
        public_url VARCHAR(1000) NULL,
        provider_media_id VARCHAR(180) NULL,
        mime_type VARCHAR(100) NULL,
        file_size BIGINT UNSIGNED NULL,
        duration_seconds INT UNSIGNED NULL,
        checksum VARCHAR(128) NULL,
        transcription LONGTEXT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'active',
        metadata_json LONGTEXT NULL,
        created_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_voice_media_status (status,media_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_clones (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider_id INT UNSIGNED NULL,
        name VARCHAR(180) NOT NULL,
        provider_voice_id VARCHAR(180) NULL,
        source_audio_id BIGINT UNSIGNED NULL,
        ref_text LONGTEXT NULL,
        language VARCHAR(30) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'draft',
        consent_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        consent_user_id INT NULL,
        consent_confirmed_at DATETIME NULL,
        provider_response_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_suppression_list (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        phone_e164 VARCHAR(32) NOT NULL,
        reason VARCHAR(120) NOT NULL DEFAULT 'manual',
        source VARCHAR(80) NOT NULL DEFAULT 'admin',
        campaign_id BIGINT UNSIGNED NULL,
        contact_id BIGINT UNSIGNED NULL,
        permanent TINYINT(1) NOT NULL DEFAULT 1,
        expires_at DATETIME NULL,
        notes VARCHAR(500) NULL,
        created_by VARCHAR(150) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_voice_suppression_phone (phone_e164),
        KEY idx_voice_suppression_active (permanent,expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_webhook_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(40) NOT NULL,
        event_id VARCHAR(180) NULL,
        headers_json_masked LONGTEXT NULL,
        payload_json LONGTEXT NULL,
        signature_valid TINYINT(1) NOT NULL DEFAULT 0,
        http_status SMALLINT UNSIGNED NOT NULL DEFAULT 202,
        processing_status VARCHAR(40) NOT NULL DEFAULT 'received',
        error VARCHAR(1000) NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        KEY idx_voice_webhook_event (provider,event_id),
        KEY idx_voice_webhook_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voice_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        actor VARCHAR(150) NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(80) NOT NULL,
        entity_id VARCHAR(80) NULL,
        before_json LONGTEXT NULL,
        after_json LONGTEXT NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_voice_audit_entity (entity_type,entity_id),
        KEY idx_voice_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT INTO voice_providers(provider,name,enabled,environment,public_configuration,connection_status)
        VALUES('telnyx','Telnyx',0,'test','{}','pending')
        ON DUPLICATE KEY UPDATE name=VALUES(name)");
}

function voice_secret_key(): string
{
    $seed = (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('APP_VERSION') ? APP_VERSION : 'voice');
    return hash('sha256', $seed, true);
}

function voice_encrypt_secret(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', voice_secret_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) throw new RuntimeException('Nao foi possivel criptografar a credencial.');
    return base64_encode($iv . $cipher);
}

function voice_decrypt_secret(string $encoded): string
{
    if ($encoded === '') return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', voice_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function voice_config_array(?string $json): array
{
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function voice_provider(PDO $pdo, string $provider = VOICE_PROVIDER_TELNYX): array
{
    voice_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM voice_providers WHERE provider=:p LIMIT 1");
    $st->execute(['p' => $provider]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Provedor de voz nao encontrado.');
    $row['public'] = voice_config_array($row['public_configuration'] ?? '{}');
    $row['credentials'] = voice_config_array($row['encrypted_credentials'] ?? '{}');
    if (!empty($row['credentials']['api_key'])) $row['credentials']['api_key_plain'] = voice_decrypt_secret((string)$row['credentials']['api_key']);
    return $row;
}

function voice_mask_secret(string $value): string
{
    if ($value === '') return '';
    return strlen($value) <= 8 ? str_repeat('*', strlen($value)) : substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

function voice_mask_phone(string $phone): string
{
    $phone = trim($phone);
    if (strlen($phone) <= 7) return $phone;
    return substr($phone, 0, 4) . str_repeat('*', max(2, strlen($phone) - 8)) . substr($phone, -4);
}

function voice_normalize_e164(string $phone, string $countryCode = '55'): string
{
    $phone = trim($phone);
    if ($phone === '') return '';
    if (preg_match('/^\+[1-9]\d{7,14}$/', $phone)) return $phone;
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') return '';
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    if (!str_starts_with($digits, $countryCode)) $digits = $countryCode . $digits;
    $e164 = '+' . $digits;
    return preg_match('/^\+[1-9]\d{7,14}$/', $e164) ? $e164 : '';
}

function voice_user_phone(array $user): string
{
    foreach (['telefone','phone','celular','whatsapp','telefone_whatsapp'] as $key) {
        $value = trim((string)($user[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function voice_render_template(string $template, array $user, array $extra = []): string
{
    return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)(?:\|([^}]+))?\s*\}\}/', function ($m) use ($user, $extra) {
        $key = (string)$m[1];
        $fallback = isset($m[2]) ? trim((string)$m[2]) : '';
        $value = '';
        if (str_contains($key, '.')) {
            [$root, $field] = explode('.', $key, 2);
            $source = $root === 'extra' ? $extra : ($root === 'user' ? $user : []);
            $value = (string)($source[$field] ?? '');
        } else {
            $value = (string)($user[$key] ?? $extra[$key] ?? '');
        }
        return $value !== '' ? $value : $fallback;
    }, $template);
}

function voice_telnyx_request(PDO $pdo, string $method, string $path, array $body = [], ?array $provider = null): array
{
    $provider = $provider ?: voice_provider($pdo);
    $apiKey = (string)($provider['credentials']['api_key_plain'] ?? getenv('TELNYX_API_KEY') ?: '');
    if ($apiKey === '') throw new RuntimeException('Configure a API Key da Telnyx.');
    if (!function_exists('curl_init')) throw new RuntimeException('Extensao cURL indisponivel no PHP.');

    $url = VOICE_PROVIDER_BASE_URL . $path;
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
    $payload = '';
    if ($method !== 'GET') {
        $payload = voice_json($body);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => max(3, min(60, (int)(($provider['public']['http_timeout'] ?? 15)))),
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false || $errno) throw new RuntimeException('Erro Telnyx: ' . ($error ?: 'falha HTTP'));
    $responseBody = substr((string)$raw, $headerSize);
    $decoded = json_decode($responseBody, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $responseBody, 'request' => $payload];
}

function voice_save_telnyx_config(PDO $pdo, array $data, string $actor): void
{
    voice_ensure_schema($pdo);
    $current = voice_provider($pdo);
    $credentials = voice_config_array($current['encrypted_credentials'] ?? '{}');
    $apiKey = trim((string)($data['api_key'] ?? ''));
    if ($apiKey !== '') $credentials['api_key'] = voice_encrypt_secret($apiKey);
    $public = [
        'public_key' => trim((string)($data['public_key'] ?? '')),
        'connection_id' => trim((string)($data['connection_id'] ?? '')),
        'outbound_voice_profile_id' => trim((string)($data['outbound_voice_profile_id'] ?? '')),
        'organization_id' => trim((string)($data['organization_id'] ?? '')),
        'default_from_number' => voice_normalize_e164((string)($data['default_from_number'] ?? ''), preg_replace('/\D+/', '', (string)($data['default_country_code'] ?? '55')) ?: '55'),
        'webhook_base_url' => rtrim(trim((string)($data['webhook_base_url'] ?? '')), '/'),
        'api_version' => trim((string)($data['api_version'] ?? 'v2')) ?: 'v2',
        'http_timeout' => max(3, min(60, (int)($data['http_timeout'] ?? 15))),
        'max_retries' => max(0, min(5, (int)($data['max_retries'] ?? 1))),
        'retry_interval_seconds' => max(1, min(3600, (int)($data['retry_interval_seconds'] ?? 30))),
        'concurrency_limit' => max(1, min(500, (int)($data['concurrency_limit'] ?? 1))),
        'calls_per_minute' => max(1, min(5000, (int)($data['calls_per_minute'] ?? 10))),
        'calls_per_hour' => max(1, min(100000, (int)($data['calls_per_hour'] ?? 300))),
        'calls_per_day' => max(1, min(1000000, (int)($data['calls_per_day'] ?? 1000))),
        'daily_cost_limit' => max(0, (float)($data['daily_cost_limit'] ?? 0)),
        'default_call_limit_secs' => max(10, min(14400, (int)($data['default_call_limit_secs'] ?? 120))),
        'default_timeout_secs' => max(5, min(120, (int)($data['default_timeout_secs'] ?? 30))),
        'default_country_code' => preg_replace('/\D+/', '', (string)($data['default_country_code'] ?? '55')) ?: '55',
        'timezone' => trim((string)($data['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo',
        'allowed_destinations' => trim((string)($data['allowed_destinations'] ?? '+55')),
        'test_allowed_numbers' => trim((string)($data['test_allowed_numbers'] ?? '')),
        'record_calls_default' => !empty($data['record_calls_default']) ? 1 : 0,
        'transcribe_calls_default' => !empty($data['transcribe_calls_default']) ? 1 : 0,
        'amd_default' => !empty($data['amd_default']) ? 1 : 0,
        'tts_provider' => trim((string)($data['tts_provider'] ?? 'Telnyx')),
        'default_voice' => trim((string)($data['default_voice'] ?? '')),
        'default_language' => trim((string)($data['default_language'] ?? 'pt-BR')) ?: 'pt-BR',
        'debug_mode' => !empty($data['debug_mode']) ? 1 : 0,
    ];
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $env = in_array(($data['environment'] ?? 'test'), ['test','production'], true) ? (string)$data['environment'] : 'test';
    $pdo->prepare("UPDATE voice_providers SET enabled=:e,environment=:env,encrypted_credentials=:cred,public_configuration=:cfg,updated_by=:actor,connection_status=IF(connection_status='authenticated','authenticated','pending') WHERE provider='telnyx'")
        ->execute(['e'=>$enabled,'env'=>$env,'cred'=>voice_json($credentials),'cfg'=>voice_json($public),'actor'=>$actor]);
    voice_audit($pdo, $actor, 'provider_config_saved', 'voice_provider', 'telnyx', [], ['enabled'=>$enabled,'environment'=>$env]);
}

function voice_audit(PDO $pdo, string $actor, string $action, string $entityType, string $entityId = '', array $before = [], array $after = []): void
{
    try {
        $pdo->prepare("INSERT INTO voice_audit_logs(actor,action,entity_type,entity_id,before_json,after_json,ip_address,user_agent) VALUES(:actor,:action,:type,:id,:before,:after,:ip,:ua)")
            ->execute([
                'actor'=>$actor,
                'action'=>$action,
                'type'=>$entityType,
                'id'=>$entityId ?: null,
                'before'=>$before ? voice_json($before) : null,
                'after'=>$after ? voice_json($after) : null,
                'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
                'ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
    } catch (Throwable $e) {}
}

function voice_test_connection(PDO $pdo): array
{
    $provider = voice_provider($pdo);
    $result = voice_telnyx_request($pdo, 'GET', '/v2/phone_numbers?page[size]=1', [], $provider);
    $status = $result['ok'] ? 'authenticated' : 'error';
    $pdo->prepare("UPDATE voice_providers SET connection_status=:s,last_tested_at=NOW(),last_error=:e WHERE provider='telnyx'")
        ->execute(['s'=>$status,'e'=>$result['ok'] ? null : mb_substr((string)$result['raw'], 0, 1000)]);
    return $result;
}

function voice_public_key_parseable(string $publicKey): bool
{
    $publicKey = trim($publicKey);
    if ($publicKey === '') return false;
    $key = ctype_xdigit($publicKey) ? @hex2bin($publicKey) : base64_decode($publicKey, true);
    return $key !== false && strlen((string)$key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
}

function voice_http_health(string $url, int $timeout = 8): array
{
    if ($url === '' || !function_exists('curl_init')) return ['ok'=>false,'status'=>0,'error'=>'URL ou cURL indisponivel.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => max(3, min(20, $timeout)),
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok'=>$status >= 200 && $status < 300, 'status'=>$status, 'error'=>$err, 'body'=>is_string($body) ? mb_substr($body, 0, 300) : ''];
}

function voice_diagnostic_item(string $key, string $label, string $status, string $detail = '', array $meta = []): array
{
    return ['key'=>$key,'label'=>$label,'status'=>$status,'detail'=>$detail,'meta'=>$meta];
}

function voice_telnyx_diagnostics(PDO $pdo): array
{
    voice_ensure_schema($pdo);
    $provider = voice_provider($pdo);
    $cfg = (array)$provider['public'];
    $items = [];
    $ready = true;
    $apiOk = false;

    try {
        $api = voice_telnyx_request($pdo, 'GET', '/v2/call_control_applications?page[size]=1', [], $provider);
        $apiOk = $api['ok'];
        $items[] = voice_diagnostic_item('api_key', 'API Key valida', $apiOk ? 'ok' : 'error', $apiOk ? 'Autenticacao Telnyx OK.' : 'HTTP ' . (int)$api['status']);
    } catch (Throwable $e) {
        $items[] = voice_diagnostic_item('api_key', 'API Key valida', 'error', $e->getMessage());
    }

    $connectionId = trim((string)($cfg['connection_id'] ?? ''));
    if ($connectionId === '') {
        $items[] = voice_diagnostic_item('connection_id', 'Connection ID existe', 'pending', 'Connection ID nao configurado.');
    } elseif ($apiOk) {
        try {
            $r = voice_telnyx_request($pdo, 'GET', '/v2/call_control_applications/' . rawurlencode($connectionId), [], $provider);
            $items[] = voice_diagnostic_item('connection_id', 'Connection ID existe', $r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Call Control Application encontrada.' : 'HTTP ' . (int)$r['status']);
        } catch (Throwable $e) {
            $items[] = voice_diagnostic_item('connection_id', 'Connection ID existe', 'error', $e->getMessage());
        }
    }

    $profileId = trim((string)($cfg['outbound_voice_profile_id'] ?? ''));
    if ($profileId === '') {
        $items[] = voice_diagnostic_item('outbound_profile', 'Outbound Voice Profile existe', 'pending', 'Outbound Voice Profile nao configurado.');
    } elseif ($apiOk) {
        try {
            $r = voice_telnyx_request($pdo, 'GET', '/v2/outbound_voice_profiles/' . rawurlencode($profileId), [], $provider);
            $data = is_array($r['body']['data'] ?? null) ? $r['body']['data'] : [];
            $enabled = array_key_exists('enabled', $data) ? (bool)$data['enabled'] : true;
            $items[] = voice_diagnostic_item('outbound_profile', 'Outbound Voice Profile existe', $r['ok'] && $enabled ? 'ok' : 'error', $r['ok'] ? ($enabled ? 'Perfil ativo para saida.' : 'Perfil encontrado, mas desativado.') : 'HTTP ' . (int)$r['status']);
        } catch (Throwable $e) {
            $items[] = voice_diagnostic_item('outbound_profile', 'Outbound Voice Profile existe', 'error', $e->getMessage());
        }
    }

    $number = voice_normalize_e164((string)($cfg['default_from_number'] ?? ''), (string)($cfg['default_country_code'] ?? '55'));
    if ($number === '') {
        $items[] = voice_diagnostic_item('number_exists', 'Numero existe', 'pending', 'Numero padrao de origem nao configurado.');
        $items[] = voice_diagnostic_item('number_active', 'Numero esta Active', 'pending', 'Aguardando numero padrao.');
    } elseif ($apiOk) {
        try {
            $r = voice_telnyx_request($pdo, 'GET', '/v2/phone_numbers?filter[phone_number]=' . rawurlencode($number), [], $provider);
            $rows = is_array($r['body']['data'] ?? null) ? $r['body']['data'] : [];
            $found = $r['ok'] && count($rows) > 0;
            $status = strtolower((string)($rows[0]['status'] ?? $rows[0]['phone_number_status'] ?? ''));
            $active = $found && ($status === '' || in_array($status, ['active','enabled'], true));
            $items[] = voice_diagnostic_item('number_exists', 'Numero existe', $found ? 'ok' : 'error', $found ? 'Numero localizado na Telnyx.' : 'Numero nao localizado pela API.');
            $items[] = voice_diagnostic_item('number_active', 'Numero esta Active', $active ? 'ok' : ($found ? 'warning' : 'pending'), $found ? ('Status retornado: ' . ($status ?: 'nao informado')) : 'Sem numero localizado.');
        } catch (Throwable $e) {
            $items[] = voice_diagnostic_item('number_exists', 'Numero existe', 'error', $e->getMessage());
            $items[] = voice_diagnostic_item('number_active', 'Numero esta Active', 'pending', 'Nao foi possivel validar status.');
        }
    }

    $webhookBase = rtrim((string)($cfg['webhook_base_url'] ?? ''), '/') ?: rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
    $webhookUrl = $webhookBase . '/telnyx_voice_webhook.php?health=1';
    $wh = voice_http_health($webhookUrl, (int)($cfg['http_timeout'] ?? 8));
    $items[] = voice_diagnostic_item('webhook_http', 'Webhook responde HTTP 200', $wh['ok'] ? 'ok' : 'error', $wh['ok'] ? 'Endpoint publico respondeu.' : ('HTTP ' . (int)$wh['status'] . ' ' . (string)$wh['error']), ['url'=>$webhookUrl]);

    $publicKey = trim((string)($cfg['public_key'] ?? getenv('TELNYX_PUBLIC_KEY') ?: ''));
    $pkOk = voice_public_key_parseable($publicKey);
    $items[] = voice_diagnostic_item('public_key', 'Public Key valida', $pkOk ? 'ok' : 'error', $pkOk ? 'Formato Ed25519 aceito pelo servidor.' : 'Public key ausente ou em formato invalido.');

    foreach ($items as $item) {
        if (in_array($item['status'], ['error','pending'], true)) $ready = false;
    }
    $summary = ['ok'=>0,'warning'=>0,'pending'=>0,'error'=>0];
    foreach ($items as $item) $summary[$item['status']] = ($summary[$item['status']] ?? 0) + 1;
    $pdo->prepare("UPDATE voice_providers SET connection_status=:s,last_tested_at=NOW(),last_error=:e WHERE provider='telnyx'")
        ->execute(['s'=>$ready ? 'ready' : ($summary['error'] > 0 ? 'error' : 'pending'), 'e'=>$ready ? null : voice_json($summary)]);
    return ['ready'=>$ready,'summary'=>$summary,'items'=>$items,'tested_at'=>date('Y-m-d H:i:s')];
}

function voice_allowed_test_number(array $provider, string $to): bool
{
    $list = preg_split('/[\s,;]+/', (string)($provider['public']['test_allowed_numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $country = (string)($provider['public']['default_country_code'] ?? '55');
    foreach ($list as $item) if (voice_normalize_e164((string)$item, $country) === $to) return true;
    return false;
}

function voice_create_telnyx_call(PDO $pdo, array $args): array
{
    voice_ensure_schema($pdo);
    $provider = voice_provider($pdo);
    if (empty($provider['enabled'])) throw new RuntimeException('Provedor Telnyx esta inativo.');
    $cfg = (array)$provider['public'];
    $country = (string)($cfg['default_country_code'] ?? '55');
    $from = voice_normalize_e164((string)($args['from'] ?? $cfg['default_from_number'] ?? ''), $country);
    $to = voice_normalize_e164((string)($args['to'] ?? ''), $country);
    if ($from === '' || $to === '') throw new InvalidArgumentException('Numero de origem ou destino invalido em E.164.');
    $blocked = $pdo->prepare("SELECT id FROM voice_suppression_list WHERE phone_e164=:p AND (permanent=1 OR expires_at>NOW()) LIMIT 1");
    $blocked->execute(['p'=>$to]);
    if ($blocked->fetchColumn()) throw new RuntimeException('Telefone esta na lista de bloqueio de voz.');
    $connectionId = trim((string)($args['connection_id'] ?? $cfg['connection_id'] ?? ''));
    if ($connectionId === '') throw new RuntimeException('Configure o connection_id da Telnyx.');

    $commandId = (string)($args['command_id'] ?? bin2hex(random_bytes(16)));
    $idem = (string)($args['idempotency_key'] ?? hash('sha256', 'voice|' . $commandId . '|' . $to));
    $body = [
        'connection_id' => $connectionId,
        'from' => $from,
        'to' => $to,
        'command_id' => $commandId,
        'client_state' => base64_encode(voice_json(['idempotency_key'=>$idem,'campaign_id'=>(int)($args['campaign_id'] ?? 0),'recipient_id'=>(int)($args['recipient_id'] ?? 0),'user_id'=>(int)($args['user_id'] ?? 0)])),
    ];
    if (!empty($args['answering_machine_detection'])) $body['answering_machine_detection'] = 'detect';
    if (!empty($args['audio_url'])) $body['audio_url'] = (string)$args['audio_url'];
    if (!empty($args['time_limit_secs'])) $body['time_limit_secs'] = max(10, min(14400, (int)$args['time_limit_secs']));
    if (!empty($args['timeout_secs'])) $body['timeout_secs'] = max(5, min(120, (int)$args['timeout_secs']));
    $settings = [
        'message_mode'=>(string)($args['message_mode'] ?? 'text_to_speech'),
        'message'=>(string)($args['message'] ?? ''),
        'audio_url'=>(string)($args['audio_url'] ?? ''),
        'voice'=>(string)($args['voice'] ?? $cfg['default_voice'] ?? ''),
        'language'=>(string)($args['language'] ?? $cfg['default_language'] ?? 'pt-BR'),
    ];

    $insertAttempt = $pdo->prepare("INSERT IGNORE INTO voice_call_attempts(campaign_id,recipient_id,user_id,automation_run_id,automation_job_id,provider_id,command_id,idempotency_key,from_number,to_number,status,provider_response_json,settings_json) VALUES(:c,:r,:u,:ar,:aj,:p,:cmd,:idem,:f,:t,'api_requested',NULL,:settings)");
    $insertAttempt->execute([
            'c'=>(int)($args['campaign_id'] ?? 0) ?: null,
            'r'=>(int)($args['recipient_id'] ?? 0) ?: null,
            'u'=>(int)($args['user_id'] ?? 0) ?: null,
            'ar'=>(int)($args['automation_run_id'] ?? 0) ?: null,
            'aj'=>(int)($args['automation_job_id'] ?? 0) ?: null,
            'p'=>(int)$provider['id'],
            'cmd'=>$commandId,
            'idem'=>$idem,
            'f'=>$from,
            't'=>$to,
            'settings'=>voice_json($settings),
        ]);
    $attemptId = (int)$pdo->query("SELECT id FROM voice_call_attempts WHERE idempotency_key=" . $pdo->quote($idem) . " LIMIT 1")->fetchColumn();
    if ($insertAttempt->rowCount() === 0) return ['attempt_id'=>$attemptId,'status'=>'duplicate_skipped'];
    $result = voice_telnyx_request($pdo, 'POST', '/v2/calls', $body, $provider);
    $data = is_array($result['body']['data'] ?? null) ? $result['body']['data'] : [];
    $status = $result['ok'] ? 'api_accepted' : 'failed';
    $pdo->prepare("UPDATE voice_call_attempts SET status=:s,provider_call_id=:pc,call_control_id=:cc,call_leg_id=:cl,call_session_id=:cs,provider_response_json=:resp,error_json=:err WHERE id=:id")
        ->execute([
            's'=>$status,
            'pc'=>(string)($data['id'] ?? ''),
            'cc'=>(string)($data['call_control_id'] ?? ''),
            'cl'=>(string)($data['call_leg_id'] ?? ''),
            'cs'=>(string)($data['call_session_id'] ?? ''),
            'resp'=>voice_json($result['body'] ?? $result['raw']),
            'err'=>$result['ok'] ? null : voice_json(['status'=>$result['status'],'body'=>$result['raw']]),
            'id'=>$attemptId,
        ]);
    if (!$result['ok']) throw new RuntimeException('Telnyx recusou a chamada: HTTP ' . $result['status']);
    return ['attempt_id'=>$attemptId,'status'=>$status,'call_control_id'=>(string)($data['call_control_id'] ?? ''),'call_leg_id'=>(string)($data['call_leg_id'] ?? '')];
}

function voice_telnyx_speak_attempt(PDO $pdo, array $attempt, array $payload = []): array
{
    $settings = voice_config_array($attempt['settings_json'] ?? '{}');
    $message = trim((string)($settings['message'] ?? ''));
    $callControlId = trim((string)($payload['call_control_id'] ?? $attempt['call_control_id'] ?? ''));
    if ($message === '' || $callControlId === '') return ['skipped'=>'missing_tts_or_call_control'];
    $body = [
        'payload' => $message,
        'payload_type' => 'text',
        'language' => (string)($settings['language'] ?? 'pt-BR'),
        'client_state' => base64_encode(voice_json(['attempt_id'=>(int)$attempt['id'],'action'=>'speak'])),
        'command_id' => bin2hex(random_bytes(16)),
    ];
    if (trim((string)($settings['voice'] ?? '')) !== '') $body['voice'] = trim((string)$settings['voice']);
    $result = voice_telnyx_request($pdo, 'POST', '/v2/calls/' . rawurlencode($callControlId) . '/actions/speak', $body);
    $history = voice_config_array($attempt['provider_response_json'] ?? '{}');
    $history['last_speak_command'] = ['http_status'=>$result['status'],'ok'=>$result['ok'],'sent_at'=>date('Y-m-d H:i:s')];
    $pdo->prepare("UPDATE voice_call_attempts SET provider_response_json=:r WHERE id=:id")
        ->execute(['r'=>voice_json($history),'id'=>(int)$attempt['id']]);
    return $result;
}

function voice_send_test_call(PDO $pdo, string $to, string $message, string $audioUrl, string $actor): array
{
    $provider = voice_provider($pdo);
    $to = voice_normalize_e164($to, (string)($provider['public']['default_country_code'] ?? '55'));
    if ($to === '') throw new InvalidArgumentException('Telefone de teste invalido.');
    if (!voice_allowed_test_number($provider, $to)) throw new RuntimeException('Inclua este telefone em Numeros autorizados para teste antes de ligar.');
    $mode = trim($audioUrl) !== '' ? 'audio_url' : 'text_to_speech';
    $result = voice_create_telnyx_call($pdo, [
        'to'=>$to,
        'audio_url'=>$mode === 'audio_url' ? $audioUrl : '',
        'message_mode'=>$mode,
        'message'=>$message,
        'idempotency_key'=>hash('sha256','voice-test|' . $to . '|' . microtime(true)),
        'command_id'=>bin2hex(random_bytes(16)),
        'time_limit_secs'=>120,
        'timeout_secs'=>30,
    ]);
    voice_audit($pdo, $actor, 'test_call_created', 'voice_call_attempt', (string)$result['attempt_id'], [], ['to'=>voice_mask_phone($to),'mode'=>$mode,'message_chars'=>strlen($message)]);
    return $result;
}

function voice_automation_start_call(PDO $pdo, array $config, array $user, array $job, array $extra = []): array
{
    $phone = (string)($config['phoneField'] ?? '') !== '' ? (string)($user[(string)$config['phoneField']] ?? '') : voice_user_phone($user);
    $provider = voice_provider($pdo);
    $country = (string)($provider['public']['default_country_code'] ?? '55');
    $to = voice_normalize_e164($phone, $country);
    if ($to === '') return ['skipped'=>'invalid_phone'];
    $mode = (string)($config['messageMode'] ?? 'text_to_speech');
    $message = voice_render_template((string)($config['message'] ?? ''), $user, $extra);
    $audioUrl = trim((string)($config['audioUrl'] ?? ''));
    if ($mode === 'audio_url' && $audioUrl === '') throw new RuntimeException('Configure a URL de audio no bloco de voz.');
    if ($mode !== 'audio_url' && trim($message) === '') throw new RuntimeException('Configure a mensagem TTS no bloco de voz.');
    return voice_create_telnyx_call($pdo, [
        'to'=>$to,
        'from'=>(string)($config['fromNumber'] ?? ''),
        'audio_url'=>$mode === 'audio_url' ? $audioUrl : '',
        'message_mode'=>$mode,
        'message'=>$message,
        'answering_machine_detection'=>!empty($config['answeringMachineDetection']),
        'time_limit_secs'=>(int)($config['timeLimitSecs'] ?? 120),
        'timeout_secs'=>(int)($config['timeoutSecs'] ?? 30),
        'user_id'=>(int)($user['id'] ?? $job['user_id'] ?? 0),
        'automation_run_id'=>(int)($job['run_id'] ?? 0),
        'automation_job_id'=>(int)($job['id'] ?? 0),
        'idempotency_key'=>hash('sha256','automation-voice|' . ($job['run_id'] ?? '') . '|' . ($job['node_id'] ?? '') . '|' . ($job['user_id'] ?? '')),
    ]);
}

function voice_normalize_telnyx_event(string $eventType, array $payload): string
{
    if ($eventType === 'call.initiated') return 'initiated';
    if ($eventType === 'call.ringing') return 'ringing';
    if ($eventType === 'call.answered') return 'answered';
    if (in_array($eventType, ['call.playback.started','call.speak.started'], true)) return 'audio_started';
    if (in_array($eventType, ['call.playback.ended','call.speak.ended'], true)) return 'audio_completed';
    if (in_array($eventType, ['call.dtmf.received','call.gather.ended'], true)) return 'interacted';
    if ($eventType === 'call.machine.detection.ended') {
        $result = strtolower((string)($payload['result'] ?? $payload['answering_machine_detection'] ?? ''));
        if (str_contains($result, 'human')) return 'answered_human';
        if (str_contains($result, 'machine') || str_contains($result, 'voicemail')) return 'answered_machine';
        return 'amd_unknown';
    }
    if ($eventType === 'call.hangup') {
        $cause = strtolower((string)($payload['hangup_cause'] ?? $payload['cause'] ?? ''));
        if (str_contains($cause, 'busy')) return 'busy';
        if (str_contains($cause, 'reject')) return 'rejected';
        if (str_contains($cause, 'timeout') || str_contains($cause, 'no_answer')) return 'no_answer';
        if (str_contains($cause, 'fail') || str_contains($cause, 'error')) return 'failed';
        return 'completed';
    }
    return str_replace(['call.','streaming.','.'], ['', 'stream_', '_'], $eventType);
}

function voice_telnyx_signature_valid(array $headers, string $raw, string $publicKey, int $toleranceSeconds = 300): bool
{
    if ($publicKey === '' || !function_exists('sodium_crypto_sign_verify_detached')) return false;
    $map = [];
    foreach ($headers as $k => $v) $map[strtolower((string)$k)] = is_array($v) ? implode(',', $v) : (string)$v;
    $sig = trim($map['telnyx-signature-ed25519'] ?? '');
    $ts = trim($map['telnyx-timestamp'] ?? '');
    if ($sig === '' || $ts === '' || abs(time() - (int)$ts) > $toleranceSeconds) return false;
    $signature = base64_decode($sig, true);
    $key = ctype_xdigit($publicKey) ? @hex2bin($publicKey) : base64_decode($publicKey, true);
    if ($signature === false || $key === false || strlen((string)$key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
    return sodium_crypto_sign_verify_detached($signature, $ts . '|' . $raw, (string)$key);
}

function voice_handle_telnyx_webhook(PDO $pdo, string $raw, array $headers, bool $failover = false): array
{
    voice_ensure_schema($pdo);
    $provider = voice_provider($pdo);
    $publicKey = trim((string)($provider['public']['public_key'] ?? getenv('TELNYX_PUBLIC_KEY') ?: ''));
    $valid = voice_telnyx_signature_valid($headers, $raw, $publicKey);
    $payload = json_decode($raw, true);
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $eventId = (string)($data['id'] ?? '');
    $eventType = (string)($data['event_type'] ?? '');
    $eventPayload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
    $http = ($publicKey !== '' && !$valid) ? 400 : 202;
    $status = $http === 400 ? 'invalid_signature' : ($publicKey === '' ? 'signature_missing' : 'received');
    $pdo->prepare("INSERT INTO voice_webhook_logs(provider,event_id,headers_json_masked,payload_json,signature_valid,http_status,processing_status,error,processed_at) VALUES('telnyx',:event,:headers,:payload,:valid,:http,:status,:error,NOW())")
        ->execute(['event'=>$eventId ?: null,'headers'=>voice_json(voice_mask_headers($headers)),'payload'=>$raw,'valid'=>$valid?1:0,'http'=>$http,'status'=>$status,'error'=>$publicKey === '' ? 'Public key nao configurada.' : null]);
    if ($http !== 202 || $publicKey === '') return ['http_status'=>$http,'status'=>$status];
    if ($eventId === '' || $eventType === '') return ['http_status'=>400,'status'=>'invalid_payload'];

    $normalized = voice_normalize_telnyx_event($eventType, $eventPayload);
    $attempt = voice_find_attempt_by_payload($pdo, $eventPayload);
    try {
        $pdo->prepare("INSERT IGNORE INTO voice_events(provider,provider_event_id,event_type,normalized_event,call_control_id,call_leg_id,call_session_id,campaign_id,recipient_id,attempt_id,user_id,occurred_at,processed_at,processing_status,signature_valid,payload_json) VALUES('telnyx',:eid,:etype,:norm,:cc,:cl,:cs,:campaign,:recipient,:attempt,:user,:occurred,NOW(),'processed',1,:payload)")
            ->execute([
                'eid'=>$eventId,
                'etype'=>$eventType,
                'norm'=>$normalized,
                'cc'=>(string)($eventPayload['call_control_id'] ?? ''),
                'cl'=>(string)($eventPayload['call_leg_id'] ?? ''),
                'cs'=>(string)($eventPayload['call_session_id'] ?? ''),
                'campaign'=>(int)($attempt['campaign_id'] ?? 0) ?: null,
                'recipient'=>(int)($attempt['recipient_id'] ?? 0) ?: null,
                'attempt'=>(int)($attempt['id'] ?? 0) ?: null,
                'user'=>(int)($attempt['user_id'] ?? 0) ?: null,
                'occurred'=>voice_iso_to_mysql((string)($data['occurred_at'] ?? '')),
                'payload'=>voice_json($payload),
            ]);
        if (!empty($attempt['id'])) voice_apply_attempt_event($pdo, (int)$attempt['id'], $normalized, $eventPayload, (int)($attempt['user_id'] ?? 0), $eventId);
    } catch (Throwable $e) {
        return ['http_status'=>202,'status'=>'stored_error','error'=>$e->getMessage()];
    }
    return ['http_status'=>202,'status'=>'processed','event'=>$normalized,'failover'=>$failover];
}

function voice_mask_headers(array $headers): array
{
    $out = [];
    foreach ($headers as $k => $v) {
        $lk = strtolower((string)$k);
        $value = is_array($v) ? implode(',', $v) : (string)$v;
        $out[$k] = str_contains($lk, 'signature') ? voice_mask_secret($value) : $value;
    }
    return $out;
}

function voice_iso_to_mysql(string $value): ?string
{
    if ($value === '') return null;
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function voice_find_attempt_by_payload(PDO $pdo, array $payload): array
{
    $where = []; $params = [];
    foreach (['call_control_id','call_leg_id','call_session_id'] as $key) {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value !== '') { $where[] = "$key=:$key"; $params[$key] = $value; }
    }
    if (!$where) return [];
    $st = $pdo->prepare("SELECT * FROM voice_call_attempts WHERE " . implode(' OR ', $where) . " ORDER BY id DESC LIMIT 1");
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function voice_apply_attempt_event(PDO $pdo, int $attemptId, string $event, array $payload, int $userId = 0, string $eventId = ''): void
{
    $map = [
        'initiated'=>['status'=>'initiated','field'=>'started_at'],
        'ringing'=>['status'=>'ringing','field'=>'ringing_at'],
        'answered'=>['status'=>'answered','field'=>'answered_at'],
        'answered_human'=>['status'=>'answered','answered_by'=>'human'],
        'answered_machine'=>['status'=>'answered','answered_by'=>'machine'],
        'audio_started'=>['status'=>'playing','field'=>'audio_started_at'],
        'audio_completed'=>['status'=>'finished','field'=>'audio_ended_at'],
        'busy'=>['status'=>'finished','final'=>'busy'],
        'no_answer'=>['status'=>'finished','final'=>'no_answer'],
        'rejected'=>['status'=>'finished','final'=>'rejected'],
        'failed'=>['status'=>'failed','final'=>'failed'],
        'completed'=>['status'=>'finished','final'=>'completed'],
    ];
    $m = $map[$event] ?? [];
    if (!$m) return;
    $sets = ['status=:status'];
    $params = ['status'=>$m['status'],'id'=>$attemptId];
    if (!empty($m['field'])) $sets[] = $m['field'] . '=COALESCE(' . $m['field'] . ',NOW())';
    if (!empty($m['answered_by'])) { $sets[] = "answered_by=:answered_by"; $params['answered_by'] = $m['answered_by']; }
    if (!empty($m['final'])) $sets[] = "ended_at=COALESCE(ended_at,NOW())";
    if (isset($payload['hangup_cause'])) { $sets[] = "hangup_cause=:hangup_cause"; $params['hangup_cause'] = (string)$payload['hangup_cause']; }
    if (isset($payload['hangup_source'])) { $sets[] = "hangup_source=:hangup_source"; $params['hangup_source'] = (string)$payload['hangup_source']; }
    $pdo->prepare("UPDATE voice_call_attempts SET " . implode(',', $sets) . " WHERE id=:id")->execute($params);
    if ($event === 'answered') {
        try {
            $st = $pdo->prepare("SELECT * FROM voice_call_attempts WHERE id=:id LIMIT 1");
            $st->execute(['id'=>$attemptId]);
            $attempt = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $settings = voice_config_array($attempt['settings_json'] ?? '{}');
            if (($settings['message_mode'] ?? 'text_to_speech') !== 'audio_url') voice_telnyx_speak_attempt($pdo, $attempt, $payload);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE voice_call_attempts SET error_json=:e WHERE id=:id")->execute(['e'=>voice_json(['speak_error'=>$e->getMessage()]),'id'=>$attemptId]);
        }
    }
    if ($userId > 0) {
        $code = [
            'answered'=>'VOICE_CALL_ANSWERED',
            'answered_human'=>'VOICE_CALL_HUMAN',
            'answered_machine'=>'VOICE_CALL_MACHINE',
            'audio_completed'=>'VOICE_CALL_AUDIO_COMPLETED',
            'busy'=>'VOICE_CALL_BUSY',
            'no_answer'=>'VOICE_CALL_NOT_ANSWERED',
            'rejected'=>'VOICE_CALL_REJECTED',
            'failed'=>'VOICE_CALL_FAILED',
            'completed'=>'VOICE_CALL_COMPLETED',
            'interacted'=>'VOICE_CALL_DTMF_RECEIVED',
        ][$event] ?? '';
        if ($code !== '' && function_exists('capturar_fluxos_automacao')) {
            try { capturar_fluxos_automacao($code, $userId, ['event_id'=>$eventId,'voice_attempt_id'=>$attemptId,'voice_event'=>$event]); } catch (Throwable $e) {}
        }
    }
}

function voice_process_queue(PDO $pdo, int $limit = 25): array
{
    voice_ensure_schema($pdo);
    $done = ['queued'=>0,'started'=>0,'skipped'=>0,'failed'=>0];
    $rows = $pdo->query("SELECT r.*,c.provider_id,c.phone_number_id,c.message_mode,c.message_template,c.answering_machine_detection,c.status campaign_status,n.phone_e164 from_number
        FROM voice_campaign_recipients r
        JOIN voice_campaigns c ON c.id=r.campaign_id
        LEFT JOIN voice_phone_numbers n ON n.id=c.phone_number_id
        WHERE r.status IN ('queued','scheduled','pending') AND (r.scheduled_at IS NULL OR r.scheduled_at<=NOW()) AND c.status='running'
        ORDER BY COALESCE(r.scheduled_at,r.created_at),r.id LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        try {
            voice_create_telnyx_call($pdo, [
                'campaign_id'=>(int)$row['campaign_id'],
                'recipient_id'=>(int)$row['id'],
                'user_id'=>(int)($row['user_id'] ?? 0),
                'from'=>(string)($row['from_number'] ?? ''),
                'to'=>(string)$row['phone_e164'],
                'answering_machine_detection'=>(int)$row['answering_machine_detection'] === 1,
                'message_mode'=>(string)($row['message_mode'] ?? 'text_to_speech'),
                'message'=>voice_render_template((string)($row['message_template'] ?? ''), ['id'=>(int)($row['user_id'] ?? 0),'nome'=>(string)($row['name'] ?? ''),'primeiro_nome'=>(string)($row['first_name'] ?? '')], voice_config_array($row['variables_json'] ?? '{}')),
                'idempotency_key'=>hash('sha256','campaign-voice|' . $row['campaign_id'] . '|' . $row['id'] . '|' . ((int)$row['attempts_count'] + 1)),
            ]);
            $pdo->prepare("UPDATE voice_campaign_recipients SET status='dialing',attempts_count=attempts_count+1,last_called_at=NOW() WHERE id=:id")->execute(['id'=>$row['id']]);
            $done['started']++;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE voice_campaign_recipients SET status='failed',failure_message=:e WHERE id=:id")->execute(['e'=>mb_substr($e->getMessage(),0,500),'id'=>$row['id']]);
            $done['failed']++;
        }
        $done['queued']++;
    }
    return $done;
}

function voice_dashboard_stats(PDO $pdo): array
{
    voice_ensure_schema($pdo);
    $one = fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    return [
        'campaigns'=>$one("SELECT COUNT(*) FROM voice_campaigns"),
        'running'=>$one("SELECT COUNT(*) FROM voice_campaigns WHERE status='running'"),
        'scheduled'=>$one("SELECT COUNT(*) FROM voice_campaign_recipients WHERE status IN ('queued','scheduled','pending')"),
        'initiated'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE status IN ('api_accepted','initiated','ringing','answered','playing','finished')"),
        'ringing'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE status='ringing'"),
        'answered'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE answered_at IS NOT NULL OR answered_by IS NOT NULL"),
        'human'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE answered_by='human'"),
        'machine'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE answered_by='machine'"),
        'failed'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE status='failed'"),
        'completed'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE status='finished'"),
        'audio_completed'=>$one("SELECT COUNT(*) FROM voice_call_attempts WHERE audio_ended_at IS NOT NULL"),
        'blocked'=>$one("SELECT COUNT(*) FROM voice_suppression_list WHERE permanent=1 OR expires_at>NOW()"),
    ];
}

function voice_media_options(PDO $pdo): array
{
    voice_ensure_schema($pdo);
    return $pdo->query("SELECT id,name,public_url FROM voice_media WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
