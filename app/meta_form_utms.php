<?php
declare(strict_types=1);

require_once __DIR__ . '/funcoes.php';

const MFU_DEFAULT_SHEET_URL = 'https://docs.google.com/spreadsheets/d/1lc1qvySO_yYVJ9OQPNmvMkQFwC9OigzAOHyICTmdTgk/edit?gid=0#gid=0';
const MFU_DEFAULT_SOURCE = 'formulario facebook';

function mfu_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    foreach ([
        "ALTER TABLE users ADD COLUMN utm_source VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN utm_medium VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN utm_campaign VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN utm_content VARCHAR(255) NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta_form_utm_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sheet_row INT NOT NULL,
            lead_id VARCHAR(80) NULL,
            user_id INT NULL,
            user_name VARCHAR(190) NULL,
            match_method VARCHAR(20) NULL,
            status VARCHAR(30) NOT NULL,
            email VARCHAR(190) NULL,
            phone_norm VARCHAR(30) NULL,
            utm_source VARCHAR(255) NULL,
            utm_medium VARCHAR(255) NULL,
            utm_campaign VARCHAR(255) NULL,
            utm_content VARCHAR(255) NULL,
            existing_utm_source VARCHAR(255) NULL,
            existing_utm_medium VARCHAR(255) NULL,
            existing_utm_campaign VARCHAR(255) NULL,
            existing_utm_content VARCHAR(255) NULL,
            message VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_mfu_sheet_row (sheet_row),
            KEY idx_mfu_user (user_id),
            KEY idx_mfu_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        "ALTER TABLE meta_form_utm_logs ADD COLUMN user_name VARCHAR(190) NULL AFTER user_id",
        "ALTER TABLE meta_form_utm_logs ADD COLUMN existing_utm_source VARCHAR(255) NULL AFTER utm_content",
        "ALTER TABLE meta_form_utm_logs ADD COLUMN existing_utm_medium VARCHAR(255) NULL AFTER existing_utm_source",
        "ALTER TABLE meta_form_utm_logs ADD COLUMN existing_utm_campaign VARCHAR(255) NULL AFTER existing_utm_medium",
        "ALTER TABLE meta_form_utm_logs ADD COLUMN existing_utm_content VARCHAR(255) NULL AFTER existing_utm_campaign",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }

    $done = true;
}

function mfu_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $st = $pdo->prepare("SELECT valor FROM settings WHERE chave=:key LIMIT 1");
        $st->execute([':key' => $key]);
        $value = $st->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function mfu_set_setting(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare("
        INSERT INTO settings (chave, valor) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE valor=VALUES(valor)
    ");
    $st->execute([':key' => $key, ':value' => $value]);
}

function mfu_csv_url(string $sheetUrl): string {
    $sheetUrl = trim($sheetUrl);
    if ($sheetUrl === '') $sheetUrl = MFU_DEFAULT_SHEET_URL;
    if (preg_match('#/spreadsheets/d/([^/]+)#', $sheetUrl, $m)) {
        $gid = '0';
        if (preg_match('/[?&#]gid=(\d+)/', $sheetUrl, $gm)) $gid = $gm[1];
        return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($m[1]) . '/export?format=csv&gid=' . rawurlencode($gid);
    }
    return $sheetUrl;
}

function mfu_fetch_csv(string $url, int $timeout = 30): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'area-membros-meta-form-utms/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Falha ao baixar planilha Google: HTTP ' . $status . ($error ? ' - ' . $error : ''));
        }
        return (string)$body;
    }

    $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) throw new RuntimeException('Falha ao baixar planilha Google.');
    return (string)$body;
}

function mfu_decode_csv(string $csv): array {
    $fp = fopen('php://temp', 'r+');
    if (!$fp) throw new RuntimeException('Falha ao abrir memoria temporaria.');
    fwrite($fp, $csv);
    rewind($fp);

    $rows = [];
    while (($row = fgetcsv($fp, null, ',', '"', '\\')) !== false) {
        $rows[] = array_map(static fn($v) => trim((string)$v), $row);
    }
    fclose($fp);

    if (!$rows) return [];
    $headers = array_map(static fn($h) => strtolower(trim((string)$h)), array_shift($rows));
    $out = [];
    foreach ($rows as $i => $row) {
        $assoc = ['_sheet_row' => $i + 2];
        foreach ($headers as $col => $name) {
            if ($name !== '') $assoc[$name] = $row[$col] ?? '';
        }
        $out[] = $assoc;
    }
    return $out;
}

function mfu_first(array $row, array $keys): string {
    foreach ($keys as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function mfu_normalize_email(string $email): string {
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function mfu_normalize_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if (strlen($digits) > 11 && str_starts_with($digits, '55')) $digits = substr($digits, 2);
    return strlen($digits) >= 10 ? substr($digits, -11) : '';
}

function mfu_user_phone_expr(): string {
    return "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''),' ',''),'-',''),'(',''),')',''),'+',''),'.',''),11)";
}

function mfu_blank_utm_condition(): string {
    return "COALESCE(NULLIF(TRIM(utm_source),''),NULLIF(TRIM(utm_medium),''),NULLIF(TRIM(utm_campaign),''),NULLIF(TRIM(utm_content),'')) IS NULL";
}

function mfu_load_users_for_matching(PDO $pdo): array {
    $expr = mfu_user_phone_expr();
    $rows = $pdo->query("
        SELECT id, nome, email, {$expr} AS phone_norm, utm_source, utm_medium, utm_campaign, utm_content
          FROM users
         ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byPhone = [];
    $byEmail = [];
    $byId = [];
    foreach ($rows as $user) {
        $id = (int)($user['id'] ?? 0);
        if ($id <= 0) continue;
        $email = mfu_normalize_email((string)($user['email'] ?? ''));
        $phone = mfu_normalize_phone((string)($user['phone_norm'] ?? ''));
        $item = [
            'id' => $id,
            'nome' => (string)($user['nome'] ?? ''),
            'email' => $email,
            'phone_norm' => $phone,
            'utm_source' => (string)($user['utm_source'] ?? ''),
            'utm_medium' => (string)($user['utm_medium'] ?? ''),
            'utm_campaign' => (string)($user['utm_campaign'] ?? ''),
            'utm_content' => (string)($user['utm_content'] ?? ''),
        ];
        $byId[$id] = $item;
        if ($phone !== '' && !isset($byPhone[$phone])) $byPhone[$phone] = $item;
        if ($email !== '' && !isset($byEmail[$email])) $byEmail[$email] = $item;
    }
    return ['by_phone' => $byPhone, 'by_email' => $byEmail, 'by_id' => $byId];
}

function mfu_forget_loaded_user(array &$users, int $userId): void {
    if (empty($users['by_id'][$userId])) return;
    $user = $users['by_id'][$userId];
    $phone = (string)($user['phone_norm'] ?? '');
    $email = (string)($user['email'] ?? '');
    unset($users['by_id'][$userId]);
    if ($phone !== '' && (int)($users['by_phone'][$phone]['id'] ?? 0) === $userId) unset($users['by_phone'][$phone]);
    if ($email !== '' && (int)($users['by_email'][$email]['id'] ?? 0) === $userId) unset($users['by_email'][$email]);
}

function mfu_find_blank_utm_user(array $users, string $phoneNorm, string $email): array {
    if ($phoneNorm !== '' && !empty($users['by_phone'][$phoneNorm])) return ['user' => $users['by_phone'][$phoneNorm], 'method' => 'phone'];
    if ($email !== '' && !empty($users['by_email'][$email])) return ['user' => $users['by_email'][$email], 'method' => 'email'];

    return ['user' => null, 'method' => null];
}

function mfu_user_has_any_utm(array $user): bool {
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $key) {
        if (trim((string)($user[$key] ?? '')) !== '') return true;
    }
    return false;
}

function mfu_log(PDO $pdo, array $data): void {
    $st = $pdo->prepare("
        INSERT INTO meta_form_utm_logs
            (sheet_row, lead_id, user_id, user_name, match_method, status, email, phone_norm,
             utm_source, utm_medium, utm_campaign, utm_content,
             existing_utm_source, existing_utm_medium, existing_utm_campaign, existing_utm_content,
             message)
        VALUES
            (:sheet_row, :lead_id, :user_id, :user_name, :match_method, :status, :email, :phone_norm,
             :utm_source, :utm_medium, :utm_campaign, :utm_content,
             :existing_utm_source, :existing_utm_medium, :existing_utm_campaign, :existing_utm_content,
             :message)
        ON DUPLICATE KEY UPDATE
            user_id=VALUES(user_id),
            user_name=VALUES(user_name),
            match_method=VALUES(match_method),
            status=VALUES(status),
            email=VALUES(email),
            phone_norm=VALUES(phone_norm),
            utm_source=VALUES(utm_source),
            utm_medium=VALUES(utm_medium),
            utm_campaign=VALUES(utm_campaign),
            utm_content=VALUES(utm_content),
            existing_utm_source=VALUES(existing_utm_source),
            existing_utm_medium=VALUES(existing_utm_medium),
            existing_utm_campaign=VALUES(existing_utm_campaign),
            existing_utm_content=VALUES(existing_utm_content),
            message=VALUES(message)
    ");
    $st->execute([
        ':sheet_row' => (int)($data['sheet_row'] ?? 0),
        ':lead_id' => ($data['lead_id'] ?? '') !== '' ? (string)$data['lead_id'] : null,
        ':user_id' => !empty($data['user_id']) ? (int)$data['user_id'] : null,
        ':user_name' => ($data['user_name'] ?? '') !== '' ? substr((string)$data['user_name'], 0, 190) : null,
        ':match_method' => ($data['match_method'] ?? '') !== '' ? (string)$data['match_method'] : null,
        ':status' => (string)($data['status'] ?? 'unknown'),
        ':email' => ($data['email'] ?? '') !== '' ? (string)$data['email'] : null,
        ':phone_norm' => ($data['phone_norm'] ?? '') !== '' ? (string)$data['phone_norm'] : null,
        ':utm_source' => ($data['utm_source'] ?? '') !== '' ? (string)$data['utm_source'] : null,
        ':utm_medium' => ($data['utm_medium'] ?? '') !== '' ? (string)$data['utm_medium'] : null,
        ':utm_campaign' => ($data['utm_campaign'] ?? '') !== '' ? (string)$data['utm_campaign'] : null,
        ':utm_content' => ($data['utm_content'] ?? '') !== '' ? (string)$data['utm_content'] : null,
        ':existing_utm_source' => ($data['existing_utm_source'] ?? '') !== '' ? (string)$data['existing_utm_source'] : null,
        ':existing_utm_medium' => ($data['existing_utm_medium'] ?? '') !== '' ? (string)$data['existing_utm_medium'] : null,
        ':existing_utm_campaign' => ($data['existing_utm_campaign'] ?? '') !== '' ? (string)$data['existing_utm_campaign'] : null,
        ':existing_utm_content' => ($data['existing_utm_content'] ?? '') !== '' ? (string)$data['existing_utm_content'] : null,
        ':message' => ($data['message'] ?? '') !== '' ? substr((string)$data['message'], 0, 500) : null,
    ]);
}

function mfu_sheet_rows_by_number(PDO $pdo): array {
    $sheetUrl = mfu_setting($pdo, 'meta_form_utms_sheet_url', MFU_DEFAULT_SHEET_URL);
    $rows = mfu_decode_csv(mfu_fetch_csv(mfu_csv_url($sheetUrl)));
    $out = [];
    foreach ($rows as $row) {
        $sheetRow = (int)($row['_sheet_row'] ?? 0);
        if ($sheetRow > 0) $out[$sheetRow] = $row;
    }
    return $out;
}

function mfu_reclassify_legacy_logs(PDO $pdo, int $limit = 5000): array {
    mfu_ensure_schema($pdo);
    $sheetRows = mfu_sheet_rows_by_number($pdo);
    $usersForMatching = mfu_load_users_for_matching($pdo);
    $limit = max(1, min(20000, $limit));
    $logs = $pdo->query("
        SELECT id, sheet_row
          FROM meta_form_utm_logs
         WHERE status IN ('not_found_or_has_utm','not_updated')
         ORDER BY id ASC
         LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = ['checked' => 0, 'already_has_utm' => 0, 'not_found' => 0, 'not_updated' => 0, 'missing_sheet_row' => 0];
    foreach ($logs as $logRow) {
        $stats['checked']++;
        $sheetRow = (int)($logRow['sheet_row'] ?? 0);
        $row = $sheetRows[$sheetRow] ?? null;
        if (!$row) {
            $stats['missing_sheet_row']++;
            continue;
        }

        $email = mfu_normalize_email(mfu_first($row, ['email', 'e-mail', 'mail']));
        $phone = mfu_normalize_phone(mfu_first($row, ['phone_number', 'telefone', 'phone', 'celular']));
        $match = mfu_find_blank_utm_user($usersForMatching, $phone, $email);
        $user = $match['user'];
        if (!$user) {
            $pdo->prepare("
                UPDATE meta_form_utm_logs
                   SET status='not_found',
                       match_method=NULL,
                       user_id=NULL,
                       user_name=NULL,
                       message='Nenhum aluno encontrado por telefone ou email.'
                 WHERE id=:id
            ")->execute([':id' => (int)$logRow['id']]);
            $stats['not_found']++;
            continue;
        }

        if (mfu_user_has_any_utm($user)) {
            $pdo->prepare("
                UPDATE meta_form_utm_logs
                   SET status='already_has_utm',
                       user_id=:user_id,
                       user_name=:user_name,
                       match_method=:match_method,
                       existing_utm_source=:utm_source,
                       existing_utm_medium=:utm_medium,
                       existing_utm_campaign=:utm_campaign,
                       existing_utm_content=:utm_content,
                       message='Aluno encontrado, mas ja tinha pelo menos uma UTM preenchida.'
                 WHERE id=:id
            ")->execute([
                ':user_id' => (int)$user['id'],
                ':user_name' => substr((string)($user['nome'] ?? ''), 0, 190) ?: null,
                ':match_method' => (string)$match['method'],
                ':utm_source' => trim((string)($user['utm_source'] ?? '')) ?: null,
                ':utm_medium' => trim((string)($user['utm_medium'] ?? '')) ?: null,
                ':utm_campaign' => trim((string)($user['utm_campaign'] ?? '')) ?: null,
                ':utm_content' => trim((string)($user['utm_content'] ?? '')) ?: null,
                ':id' => (int)$logRow['id'],
            ]);
            $stats['already_has_utm']++;
            continue;
        }

        $stats['not_updated']++;
    }

    return $stats;
}

function mfu_process_google_sheet(PDO $pdo, int $limit = 200, bool $dryRun = false): array {
    mfu_ensure_schema($pdo);

    $sheetUrl = mfu_setting($pdo, 'meta_form_utms_sheet_url', MFU_DEFAULT_SHEET_URL);
    $source = trim(mfu_setting($pdo, 'meta_form_utms_source', MFU_DEFAULT_SOURCE)) ?: MFU_DEFAULT_SOURCE;
    $lastRow = max(1, (int)mfu_setting($pdo, 'meta_form_utms_last_row', '1'));
    $rows = mfu_decode_csv(mfu_fetch_csv(mfu_csv_url($sheetUrl)));
    $usersForMatching = mfu_load_users_for_matching($pdo);

    $stats = [
        'downloaded_rows' => count($rows),
        'last_row_before' => $lastRow,
        'checked' => 0,
        'updated' => 0,
        'already_has_utm' => 0,
        'not_found' => 0,
        'not_updated' => 0,
        'missing_key_or_utm' => 0,
        'last_row_after' => $lastRow,
        'dry_run' => $dryRun,
    ];

    $blank = mfu_blank_utm_condition();
    $upd = $pdo->prepare("
        UPDATE users
           SET utm_source=:utm_source,
               utm_medium=:utm_medium,
               utm_campaign=:utm_campaign,
               utm_content=:utm_content
         WHERE id=:id
           AND {$blank}
         LIMIT 1
    ");

    foreach ($rows as $row) {
        $sheetRow = (int)($row['_sheet_row'] ?? 0);
        if ($sheetRow <= $lastRow) continue;
        if ($stats['checked'] >= max(1, min(1000, $limit))) break;

        $stats['checked']++;
        $email = mfu_normalize_email(mfu_first($row, ['email', 'e-mail', 'mail']));
        $phone = mfu_normalize_phone(mfu_first($row, ['phone_number', 'telefone', 'phone', 'celular']));
        $utmMedium = mfu_first($row, ['campaign_name']);
        $utmCampaign = mfu_first($row, ['adset_name']);
        $utmContent = mfu_first($row, ['ad_name']);
        $leadId = mfu_first($row, ['id', 'lead_id']);

        $log = [
            'sheet_row' => $sheetRow,
            'lead_id' => $leadId,
            'email' => $email,
            'phone_norm' => $phone,
            'utm_source' => $source,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'utm_content' => $utmContent,
        ];

        if (($email === '' && $phone === '') || $utmMedium === '' || $utmCampaign === '' || $utmContent === '') {
            $stats['missing_key_or_utm']++;
            if (!$dryRun) mfu_log($pdo, $log + ['status' => 'skipped', 'message' => 'Linha sem chave de busca ou sem campos de campanha/conjunto/anuncio.']);
            $stats['last_row_after'] = $sheetRow;
            continue;
        }

        $match = mfu_find_blank_utm_user($usersForMatching, $phone, $email);
        $user = $match['user'];
        if (!$user) {
            $stats['not_found']++;
            if (!$dryRun) mfu_log($pdo, $log + ['status' => 'not_found', 'message' => 'Nenhum aluno encontrado por telefone ou email.']);
            $stats['last_row_after'] = $sheetRow;
            continue;
        }

        if (mfu_user_has_any_utm($user)) {
            $stats['already_has_utm']++;
            if (!$dryRun) mfu_log($pdo, $log + [
                'user_id' => (int)$user['id'],
                'user_name' => (string)($user['nome'] ?? ''),
                'match_method' => (string)$match['method'],
                'status' => 'already_has_utm',
                'existing_utm_source' => (string)($user['utm_source'] ?? ''),
                'existing_utm_medium' => (string)($user['utm_medium'] ?? ''),
                'existing_utm_campaign' => (string)($user['utm_campaign'] ?? ''),
                'existing_utm_content' => (string)($user['utm_content'] ?? ''),
                'message' => 'Aluno encontrado, mas ja tinha pelo menos uma UTM preenchida.',
            ]);
            $stats['last_row_after'] = $sheetRow;
            continue;
        }

        if (!$dryRun) {
            $upd->execute([
                ':utm_source' => $source,
                ':utm_medium' => $utmMedium,
                ':utm_campaign' => $utmCampaign,
                ':utm_content' => $utmContent,
                ':id' => (int)$user['id'],
            ]);
            $changed = $upd->rowCount() > 0;
            if ($changed) mfu_forget_loaded_user($usersForMatching, (int)$user['id']);
            mfu_log($pdo, $log + [
                'user_id' => (int)$user['id'],
                'user_name' => (string)($user['nome'] ?? ''),
                'match_method' => (string)$match['method'],
                'status' => $changed ? 'updated' : 'not_updated',
                'message' => $changed ? 'UTMs atribuidas a partir da planilha Meta Forms.' : 'Aluno encontrado, mas nao estava mais com UTMs vazias.',
            ]);
            if ($changed) $stats['updated']++;
            else $stats['not_updated']++;
        } else {
            $stats['updated']++;
        }

        $stats['last_row_after'] = $sheetRow;
    }

    if (!$dryRun && $stats['last_row_after'] > $lastRow) {
        mfu_set_setting($pdo, 'meta_form_utms_last_row', (string)$stats['last_row_after']);
    }

    return $stats;
}
