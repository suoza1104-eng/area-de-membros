<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

function mql_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta_qualified_datasets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            dataset_id VARCHAR(80) NOT NULL,
            access_token TEXT NOT NULL,
            api_version VARCHAR(20) NOT NULL DEFAULT 'v25.0',
            event_name VARCHAR(80) NOT NULL DEFAULT 'Lead',
            lead_event_source VARCHAR(180) NOT NULL DEFAULT 'Area de Membros CRM',
            test_event_code VARCHAR(120) NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'production',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mql_datasets_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta_qualified_triggers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dataset_id INT NOT NULL,
            name VARCHAR(180) NOT NULL,
            event_type VARCHAR(40) NOT NULL DEFAULT 'tag_added',
            conditions_json TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mql_triggers_dataset (dataset_id),
            KEY idx_mql_triggers_event (event_type, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta_qualified_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            dataset_id INT NOT NULL,
            trigger_id INT NULL,
            user_id INT NOT NULL,
            event_name VARCHAR(80) NOT NULL DEFAULT 'Lead',
            event_key VARCHAR(190) NOT NULL,
            payload_json MEDIUMTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            last_http_status INT NULL,
            last_response MEDIUMTEXT NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_mql_event (event_key),
            KEY idx_mql_queue_status (status, next_attempt_at),
            KEY idx_mql_queue_user (user_id),
            KEY idx_mql_queue_dataset (dataset_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta_qualified_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            queue_id BIGINT NULL,
            dataset_id INT NULL,
            trigger_id INT NULL,
            user_id INT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message VARCHAR(500) NOT NULL,
            http_status INT NULL,
            request_json MEDIUMTEXT NULL,
            response_json MEDIUMTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mql_logs_created (created_at),
            KEY idx_mql_logs_queue (queue_id),
            KEY idx_mql_logs_dataset (dataset_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $done = true;
}

function mql_json(?string $json, array $default = []): array {
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : $default;
}

function mql_sha256(string $value): string {
    return hash('sha256', $value);
}

function mql_hash_email(?string $email): ?string {
    $email = mb_strtolower(trim((string)$email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? mql_sha256($email) : null;
}

function mql_hash_phone(?string $phone): ?string {
    $digits = preg_replace('/\D+/', '', (string)$phone) ?: '';
    if ($digits === '') return null;
    if (strlen($digits) >= 10 && strlen($digits) <= 11) $digits = '55' . $digits;
    return strlen($digits) >= 10 ? mql_sha256($digits) : null;
}

function mql_hash_plain(?string $value): ?string {
    $value = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$value) ?? ''));
    return $value !== '' ? mql_sha256($value) : null;
}

function mql_user_name_parts(?string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', (string)$name) ?? '');
    if ($name === '') return ['', ''];
    $parts = explode(' ', $name);
    $first = array_shift($parts) ?: '';
    $last = trim(implode(' ', $parts));
    return [$first, $last];
}

function mql_first_present(array $row, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') return trim((string)$row[$key]);
    }
    return '';
}

function mql_user(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT * FROM users WHERE id=:id LIMIT 1");
    $st->execute([':id' => $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function mql_user_tags(PDO $pdo, int $userId): array {
    try {
        $st = $pdo->prepare("SELECT t.nome FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id=:id");
        $st->execute([':id' => $userId]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function mql_table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :table");
        $st->execute([':table' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mql_user_progress(PDO $pdo, int $userId): array {
    $out = ['any_lesson' => false, 'completed_trail' => false, 'percent' => 0, 'done' => 0, 'required' => 0];
    try {
        if (!mql_table_exists($pdo, 'lesson_progress') || !mql_table_exists($pdo, 'lessons')) return $out;
        $lessonFilter = "l.ativo=1 AND l.conta_para_conclusao=1";
        $out['required'] = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo=1 AND conta_para_conclusao=1")->fetchColumn();
        if ($out['required'] <= 0) {
            $out['required'] = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo=1")->fetchColumn();
            $lessonFilter = "l.ativo=1";
        }
        $stAny = $pdo->prepare("SELECT COUNT(*) FROM lesson_progress WHERE user_id=:u AND status='completed'");
        $stAny->execute([':u' => $userId]);
        $out['any_lesson'] = (int)$stAny->fetchColumn() > 0;
        if ($out['required'] > 0) {
            $stDone = $pdo->prepare("
                SELECT COUNT(DISTINCT lp.lesson_id)
                  FROM lesson_progress lp
                  JOIN lessons l ON l.id=lp.lesson_id
                 WHERE lp.user_id=:u
                   AND lp.status='completed'
                   AND {$lessonFilter}
            ");
            $stDone->execute([':u' => $userId]);
            $out['done'] = (int)$stDone->fetchColumn();
            $out['percent'] = min(100, (int)floor(($out['done'] / max(1, $out['required'])) * 100));
            $out['completed_trail'] = $out['done'] >= $out['required'];
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function mql_user_has_certificate(PDO $pdo, int $userId, array $tagSet = []): bool {
    if (!empty($tagSet['cert_emitido'])) return true;
    try {
        if (!mql_table_exists($pdo, 'certificates')) return false;
        $st = $pdo->prepare("SELECT 1 FROM certificates WHERE user_id=:u AND status='emitido' LIMIT 1");
        $st->execute([':u' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mql_user_matches_conditions(PDO $pdo, array $user, array $conditions, array $event = []): bool {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return false;

    $triggerTags = array_values(array_filter(array_map('trim', (array)($conditions['trigger_tags'] ?? []))));
    if (!$triggerTags && trim((string)($conditions['trigger_tag'] ?? '')) !== '') {
        $triggerTags = [trim((string)$conditions['trigger_tag'])];
    }
    $includeTags = array_values(array_filter(array_map('trim', (array)($conditions['include_tags'] ?? []))));
    $excludeTags = array_values(array_filter(array_map('trim', (array)($conditions['exclude_tags'] ?? []))));
    $needsTags = $triggerTags || $includeTags || $excludeTags || (string)($conditions['certificate_status'] ?? '') !== '';
    $tags = $needsTags ? mql_user_tags($pdo, $userId) : [];
    $tagSet = array_fill_keys(array_map('mb_strtolower', $tags), true);

    foreach ($excludeTags as $tag) if (!empty($tagSet[mb_strtolower($tag)])) return false;

    $turma = trim((string)($conditions['turma'] ?? ''));
    if ($turma !== '' && trim((string)($user['codigo_turma'] ?? '')) !== $turma) return false;
    if (!empty($conditions['require_email']) && trim((string)($user['email'] ?? '')) === '') return false;
    if (!empty($conditions['require_phone']) && trim((string)($user['telefone'] ?? '')) === '') return false;

    $criteria = [];
    if ($triggerTags) {
        $eventTag = mb_strtolower(trim((string)($event['tag'] ?? '')));
        if ($eventTag !== '') {
            $criteria[] = in_array($eventTag, array_map('mb_strtolower', $triggerTags), true);
        } else {
            $criteria[] = (bool)array_intersect(array_map('mb_strtolower', $triggerTags), array_keys($tagSet));
        }
    }

    if ($includeTags) {
        $logic = (string)($conditions['include_tags_logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $matches = 0;
        foreach ($includeTags as $tag) if (!empty($tagSet[mb_strtolower($tag)])) $matches++;
        $criteria[] = $logic === 'any' ? $matches > 0 : $matches === count($includeTags);
    }

    $progressStatus = (string)($conditions['progress_status'] ?? '');
    $minProgress = max(0, min(100, (int)($conditions['min_progress'] ?? 0)));
    if ($progressStatus !== '' || $minProgress > 0) {
        $progress = mql_user_progress($pdo, $userId);
        if ($progressStatus === 'any_lesson') $criteria[] = $progress['any_lesson'];
        elseif ($progressStatus === 'completed_trail') $criteria[] = $progress['completed_trail'];
        elseif ($progressStatus === 'no_lesson') $criteria[] = !$progress['any_lesson'];
        if ($minProgress > 0) $criteria[] = $progress['percent'] >= $minProgress;
    }

    $certificateStatus = (string)($conditions['certificate_status'] ?? '');
    if ($certificateStatus !== '') {
        $hasCertificate = mql_user_has_certificate($pdo, $userId, $tagSet);
        if ($certificateStatus === 'issued') $criteria[] = $hasCertificate;
        elseif ($certificateStatus === 'not_issued') $criteria[] = !$hasCertificate;
    }

    if (!$criteria) return true;
    $matchMode = (string)($conditions['criteria_match_mode'] ?? 'all') === 'any' ? 'any' : 'all';
    return $matchMode === 'any' ? in_array(true, $criteria, true) : !in_array(false, $criteria, true);
}

function mql_build_payload(PDO $pdo, array $dataset, array $user): array {
    $emailHash = mql_hash_email($user['email'] ?? null);
    $phoneHash = mql_hash_phone($user['telefone'] ?? null);
    [$firstName, $lastName] = mql_user_name_parts($user['nome'] ?? '');
    $userId = (int)($user['id'] ?? 0);
    $metaLeadId = preg_replace('/\D+/', '', mql_first_present($user, ['meta_lead_id', 'lead_id', 'facebook_lead_id'])) ?: '';
    $userData = ['external_id' => [mql_sha256((string)$userId)]];
    if ($metaLeadId !== '') $userData['lead_id'] = (int)$metaLeadId;
    if ($emailHash) $userData['em'] = [$emailHash];
    if ($phoneHash) $userData['ph'] = [$phoneHash];
    if ($firstHash = mql_hash_plain($firstName)) $userData['fn'] = [$firstHash];
    if ($lastHash = mql_hash_plain($lastName)) $userData['ln'] = [$lastHash];

    $ip = mql_first_present($user, ['client_ip_address', 'ip_address', 'ip']);
    if ($ip !== '') $userData['client_ip_address'] = $ip;
    $userAgent = mql_first_present($user, ['client_user_agent', 'user_agent']);
    if ($userAgent !== '') $userData['client_user_agent'] = $userAgent;
    $fbc = mql_first_present($user, ['fbc', '_fbc']);
    if ($fbc === '' && trim((string)($user['fbclid'] ?? '')) !== '') {
        $fbc = 'fb.1.' . time() . '.' . trim((string)$user['fbclid']);
    }
    if ($fbc !== '') $userData['fbc'] = $fbc;
    $fbp = mql_first_present($user, ['fbp', '_fbp']);
    if ($fbp !== '') $userData['fbp'] = $fbp;

    return [
        'data' => [[
            'action_source' => 'system_generated',
            'event_name' => (string)($dataset['event_name'] ?: 'Lead'),
            'event_time' => time(),
            'custom_data' => [
                'event_source' => 'crm',
                'lead_event_source' => (string)($dataset['lead_event_source'] ?: 'Area de Membros CRM'),
            ],
            'user_data' => $userData,
        ]],
    ];
}

function mql_queue_user(PDO $pdo, int $datasetId, ?int $triggerId, int $userId, string $reason = 'trigger'): bool {
    mql_ensure_schema($pdo);
    $dataset = mql_row($pdo, "SELECT * FROM meta_qualified_datasets WHERE id=:id AND active=1", ['id' => $datasetId]);
    $user = mql_user($pdo, $userId);
    if (!$dataset || !$user) return false;

    $eventName = (string)($dataset['event_name'] ?: 'Lead');
    $eventKey = 'mql:' . $datasetId . ':' . ($triggerId ?: 0) . ':' . $userId . ':' . $eventName;
    $payload = mql_build_payload($pdo, $dataset, $user);
    $st = $pdo->prepare("
        INSERT IGNORE INTO meta_qualified_queue
            (dataset_id, trigger_id, user_id, event_name, event_key, payload_json, status, next_attempt_at)
        VALUES
            (:dataset_id, :trigger_id, :user_id, :event_name, :event_key, :payload_json, 'pending', NOW())
    ");
    $st->execute([
        ':dataset_id' => $datasetId,
        ':trigger_id' => $triggerId,
        ':user_id' => $userId,
        ':event_name' => $eventName,
        ':event_key' => $eventKey,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if ($st->rowCount() > 0) {
        mql_log($pdo, null, $datasetId, $triggerId, $userId, 'info', 'Lead qualificado enfileirado: ' . $reason);
        return true;
    }
    return false;
}

function mql_handle_user_event(int $userId, string $eventType, array $event = []): int {
    try {
        $pdo = getPDO();
        mql_ensure_schema($pdo);
        $user = mql_user($pdo, $userId);
        if (!$user) return 0;
        $triggers = mql_rows($pdo, "
            SELECT t.*, d.active dataset_active
              FROM meta_qualified_triggers t
              JOIN meta_qualified_datasets d ON d.id=t.dataset_id
             WHERE t.active=1 AND d.active=1 AND t.event_type=:event_type
        ", ['event_type' => $eventType]);
        $queued = 0;
        foreach ($triggers as $trigger) {
            $conditions = mql_json($trigger['conditions_json'] ?? null);
            if (!mql_user_matches_conditions($pdo, $user, $conditions, $event)) continue;
            if (mql_queue_user($pdo, (int)$trigger['dataset_id'], (int)$trigger['id'], $userId, $eventType)) $queued++;
        }
        return $queued;
    } catch (Throwable $e) {
        return 0;
    }
}

function mql_rows(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mql_row(PDO $pdo, string $sql, array $params = []): array {
    $rows = mql_rows($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function mql_log(PDO $pdo, ?int $queueId, ?int $datasetId, ?int $triggerId, ?int $userId, string $level, string $message, ?int $httpStatus = null, ?array $request = null, ?string $response = null, ?string $error = null): void {
    $st = $pdo->prepare("
        INSERT INTO meta_qualified_logs
            (queue_id, dataset_id, trigger_id, user_id, level, message, http_status, request_json, response_json, error_message)
        VALUES
            (:queue_id, :dataset_id, :trigger_id, :user_id, :level, :message, :http_status, :request_json, :response_json, :error_message)
    ");
    $st->execute([
        ':queue_id' => $queueId,
        ':dataset_id' => $datasetId,
        ':trigger_id' => $triggerId,
        ':user_id' => $userId,
        ':level' => $level,
        ':message' => $message,
        ':http_status' => $httpStatus,
        ':request_json' => $request ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':response_json' => $response,
        ':error_message' => $error,
    ]);
}

function mql_http_post_json(string $url, array $payload, int $timeout = 20): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$raw, 'error' => $error ?: null];
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) { $status = (int)$m[1]; break; }
    return ['status' => $status, 'body' => (string)$raw, 'error' => $raw === false ? 'Falha HTTP' : null];
}

function mql_process_queue(PDO $pdo, int $limit = 50): array {
    mql_ensure_schema($pdo);
    $rows = mql_rows($pdo, "
        SELECT q.*, d.dataset_id meta_dataset_id, d.access_token, d.api_version, d.event_name dataset_event_name,
               d.lead_event_source, d.test_event_code, d.mode
          FROM meta_qualified_queue q
          JOIN meta_qualified_datasets d ON d.id=q.dataset_id
         WHERE q.status IN ('pending','retry')
           AND d.active=1
           AND (q.next_attempt_at IS NULL OR q.next_attempt_at <= NOW())
         ORDER BY q.id ASC
         LIMIT " . max(1, min(200, $limit))
    );
    $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'retry' => 0];
    foreach ($rows as $row) {
        $queueId = (int)$row['id'];
        $stats['processed']++;
        $payload = mql_json($row['payload_json'] ?? null);
        $user = mql_user($pdo, (int)$row['user_id']);
        if ($user) {
            $payload = mql_build_payload($pdo, [
                'event_name' => (string)($row['dataset_event_name'] ?: $row['event_name'] ?: 'Lead'),
                'lead_event_source' => (string)($row['lead_event_source'] ?: 'Area de Membros CRM'),
            ], $user);
            $pdo->prepare("UPDATE meta_qualified_queue SET payload_json=:payload WHERE id=:id")
                ->execute([
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $queueId,
                ]);
        }
        if ((string)($row['mode'] ?? '') === 'test' && trim((string)($row['test_event_code'] ?? '')) !== '') {
            $payload['test_event_code'] = trim((string)$row['test_event_code']);
        }
        $url = 'https://graph.facebook.com/' . rawurlencode((string)$row['api_version']) . '/' . rawurlencode((string)$row['meta_dataset_id']) . '/events?access_token=' . rawurlencode((string)$row['access_token']);
        try {
            $res = mql_http_post_json($url, $payload);
            $ok = $res['status'] >= 200 && $res['status'] < 300 && !$res['error'];
            if ($ok) {
                $pdo->prepare("UPDATE meta_qualified_queue SET status='sent', sent_at=NOW(), attempts=attempts+1, last_http_status=:s, last_response=:r, last_error=NULL WHERE id=:id")
                    ->execute(['s' => $res['status'], 'r' => $res['body'], 'id' => $queueId]);
                mql_log($pdo, $queueId, (int)$row['dataset_id'], $row['trigger_id'] ? (int)$row['trigger_id'] : null, (int)$row['user_id'], 'info', 'Evento enviado para a Meta', $res['status'], $payload, $res['body']);
                $stats['sent']++;
            } else {
                $attempts = (int)$row['attempts'] + 1;
                $terminal = $attempts >= 5 || ($res['status'] >= 400 && $res['status'] < 500);
                $status = $terminal ? 'failed' : 'retry';
                $pdo->prepare("UPDATE meta_qualified_queue SET status=:st, attempts=attempts+1, next_attempt_at=DATE_ADD(NOW(), INTERVAL :delay MINUTE), last_http_status=:s, last_response=:r, last_error=:e WHERE id=:id")
                    ->execute(['st' => $status, 'delay' => min(60, max(5, $attempts * 5)), 's' => $res['status'] ?: null, 'r' => $res['body'], 'e' => $res['error'], 'id' => $queueId]);
                mql_log($pdo, $queueId, (int)$row['dataset_id'], $row['trigger_id'] ? (int)$row['trigger_id'] : null, (int)$row['user_id'], 'error', 'Falha ao enviar evento para a Meta', $res['status'] ?: null, $payload, $res['body'], $res['error']);
                $stats[$terminal ? 'failed' : 'retry']++;
            }
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE meta_qualified_queue SET status='retry', attempts=attempts+1, next_attempt_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE), last_error=:e WHERE id=:id")
                ->execute(['e' => $e->getMessage(), 'id' => $queueId]);
            mql_log($pdo, $queueId, (int)$row['dataset_id'], $row['trigger_id'] ? (int)$row['trigger_id'] : null, (int)$row['user_id'], 'error', 'Excecao ao enviar evento para a Meta', null, $payload, null, $e->getMessage());
            $stats['retry']++;
        }
    }
    return $stats;
}

function mql_scan_trigger(PDO $pdo, int $triggerId, int $limit = 1000): array {
    mql_ensure_schema($pdo);
    $trigger = mql_row($pdo, "SELECT * FROM meta_qualified_triggers WHERE id=:id AND active=1", ['id' => $triggerId]);
    if (!$trigger) return ['checked' => 0, 'matched' => 0, 'queued' => 0, 'already_queued' => 0];
    $conditions = mql_json($trigger['conditions_json'] ?? null);
    $max = max(1, min(100000, $limit));
    $batchSize = 500;
    $lastId = 0;
    $checked = 0; $matched = 0; $queued = 0; $alreadyQueued = 0;

    while ($checked < $max) {
        $rows = mql_rows($pdo, "
            SELECT *
              FROM users
             WHERE id > :last_id
             ORDER BY id ASC
             LIMIT " . min($batchSize, $max - $checked),
            ['last_id' => $lastId]
        );
        if (!$rows) break;
        foreach ($rows as $user) {
            $lastId = max($lastId, (int)($user['id'] ?? 0));
            $checked++;
            if (!mql_user_matches_conditions($pdo, $user, $conditions, ['manual_scan' => true])) continue;
            $matched++;
            if (mql_queue_user($pdo, (int)$trigger['dataset_id'], (int)$trigger['id'], (int)$user['id'], 'manual_scan')) $queued++;
            else $alreadyQueued++;
            if ($checked >= $max) break;
        }
    }
    mql_log($pdo, null, (int)$trigger['dataset_id'], (int)$trigger['id'], null, 'info', 'Varredura manual concluida', null, [
        'checked' => $checked,
        'matched' => $matched,
        'queued' => $queued,
        'already_queued' => $alreadyQueued,
    ]);
    return ['checked' => $checked, 'matched' => $matched, 'queued' => $queued, 'already_queued' => $alreadyQueued];
}
