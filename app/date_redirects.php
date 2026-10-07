<?php
declare(strict_types=1);

require_once __DIR__ . '/evolution_api.php';

function date_redirects_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS date_redirectors (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            status ENUM('active','paused') NOT NULL DEFAULT 'active',
            clicks_total INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            UNIQUE KEY uk_date_redirectors_slug (slug),
            KEY idx_date_redirectors_status (status, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        "ALTER TABLE date_redirectors ADD COLUMN redirect_type ENUM('date','link') NOT NULL DEFAULT 'date' AFTER status",
        "ALTER TABLE date_redirectors ADD COLUMN antifraud_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER redirect_type",
        "ALTER TABLE date_redirectors ADD COLUMN blocked_redirect_url TEXT NULL AFTER antifraud_enabled",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS date_redirect_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            redirector_id INT UNSIGNED NOT NULL,
            label VARCHAR(80) NOT NULL,
            url TEXT NOT NULL,
            starts_at DATETIME NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_date_redirect_links_redirector_time (redirector_id, starts_at),
            CONSTRAINT fk_date_redirect_links_redirector
                FOREIGN KEY (redirector_id) REFERENCES date_redirectors(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS date_redirect_clicks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            redirector_id INT UNSIGNED NOT NULL,
            link_id INT UNSIGNED NULL,
            slug VARCHAR(120) NOT NULL,
            destination_url TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'redirected',
            user_id INT NULL,
            phone VARCHAR(30) NULL,
            email VARCHAR(190) NULL,
            blacklist_id INT NULL,
            identifier_source VARCHAR(40) NULL,
            clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            referer VARCHAR(255) NULL,
            KEY idx_date_redirect_clicks_redirector (redirector_id, clicked_at),
            KEY idx_date_redirect_clicks_link (link_id, clicked_at),
            KEY idx_date_redirect_clicks_status (status, clicked_at),
            KEY idx_date_redirect_clicks_phone (phone),
            KEY idx_date_redirect_clicks_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        "ALTER TABLE date_redirect_clicks ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'redirected' AFTER destination_url",
        "ALTER TABLE date_redirect_clicks ADD COLUMN user_id INT NULL AFTER status",
        "ALTER TABLE date_redirect_clicks ADD COLUMN phone VARCHAR(30) NULL AFTER user_id",
        "ALTER TABLE date_redirect_clicks ADD COLUMN email VARCHAR(190) NULL AFTER phone",
        "ALTER TABLE date_redirect_clicks ADD COLUMN blacklist_id INT NULL AFTER email",
        "ALTER TABLE date_redirect_clicks ADD COLUMN identifier_source VARCHAR(40) NULL AFTER blacklist_id",
        "ALTER TABLE date_redirect_clicks ADD KEY idx_date_redirect_clicks_status (status, clicked_at)",
        "ALTER TABLE date_redirect_clicks ADD KEY idx_date_redirect_clicks_phone (phone)",
        "ALTER TABLE date_redirect_clicks ADD KEY idx_date_redirect_clicks_user (user_id)",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }

    foreach ([
        "ALTER TABLE users ADD COLUMN fraud_detected_at DATETIME NULL",
        "ALTER TABLE users ADD COLUMN fraud_source VARCHAR(80) NULL",
        "ALTER TABLE users ADD COLUMN fraud_reason TEXT NULL",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }

    $done = true;
}

function date_redirects_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function date_redirects_slugify(string $name): string
{
    $base = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if (!is_string($base) || $base === '') $base = $name;
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? $base;
    $base = trim($base, '-');
    return substr($base !== '' ? $base : 'redirect', 0, 90);
}

function date_redirects_unique_slug(PDO $pdo, string $name, int $ignoreId = 0): string
{
    $base = date_redirects_slugify($name);
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM date_redirectors WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($ignoreId > 0) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if (!$st->fetchColumn()) return $slug;
        $suffix = '-' . $i++;
        $slug = substr($base, 0, 100 - strlen($suffix)) . $suffix;
    }
}

function date_redirects_public_url(string $slug): string
{
    return rtrim(BASE_URL, '/') . '/r.php?s=' . rawurlencode($slug);
}

function date_redirects_valid_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function date_redirects_blocked_url(?string $url = null): string
{
    $url = trim((string)$url);
    if ($url !== '' && date_redirects_valid_url($url)) return $url;
    return rtrim(BASE_URL, '/') . '/obrigado.php';
}

function date_redirects_parse_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') throw new RuntimeException('Informe a data e hora.');
    $value = str_replace('T', ' ', $value);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16));
    if (!$dt) {
        $dt = DateTimeImmutable::createFromFormat('d/m/Y H:i', $value);
    }
    if (!$dt) throw new RuntimeException('Data invalida.');
    return $dt->format('Y-m-d H:i:s');
}

function date_redirects_seed(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM date_redirectors')->fetchColumn();
    if ($count > 0) return;

    $pdo->beginTransaction();
    try {
        $items = [
            ['name' => 'LIVES HOTWEBNAR MC_QDC', 'slug' => 'live_mcqdc', 'links' => [
                ['Link 17', 'https://professoremersonleite.applive.com.br/110926', '2026-09-11 18:00:00'],
                ['Link 18', 'https://professoremersonleite.applive.com.br/150926', '2026-09-15 18:00:00'],
                ['Link 19', 'https://professoremersonleite.applive.com.br/190926', '2026-09-19 18:00:00'],
                ['Link 20', 'https://professoremersonleite.applive.com.br/230926', '2026-09-23 18:00:00'],
                ['Link 21', 'https://professoremersonleite.applive.com.br/270926', '2026-09-27 18:00:00'],
            ]],
            ['name' => 'GRUPOS WEBINARIO MC_QDC', 'slug' => 'aula_ao_vivo_mc_qdc', 'links' => []],
        ];

        foreach ($items as $item) {
            $st = $pdo->prepare("INSERT INTO date_redirectors (name, slug, status) VALUES (:name, :slug, 'active')");
            $st->execute(['name' => $item['name'], 'slug' => $item['slug']]);
            $redirectorId = (int)$pdo->lastInsertId();
            $order = 1;
            foreach ($item['links'] as $link) {
                $ins = $pdo->prepare("
                    INSERT INTO date_redirect_links (redirector_id, label, url, starts_at, sort_order)
                    VALUES (:rid, :label, :url, :starts_at, :sort_order)
                ");
                $ins->execute([
                    'rid' => $redirectorId,
                    'label' => $link[0],
                    'url' => $link[1],
                    'starts_at' => $link[2],
                    'sort_order' => $order++,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function date_redirects_find_destination(PDO $pdo, string $slug): ?array
{
    $st = $pdo->prepare("SELECT * FROM date_redirectors WHERE slug = :slug AND deleted_at IS NULL LIMIT 1");
    $st->execute(['slug' => $slug]);
    $redirector = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$redirector || (string)$redirector['status'] !== 'active') return null;

    $isLinkMode = (string)($redirector['redirect_type'] ?? 'date') === 'link';
    $st = $pdo->prepare($isLinkMode
        ? "SELECT * FROM date_redirect_links WHERE redirector_id = :rid ORDER BY sort_order ASC, id ASC LIMIT 1"
        : "SELECT *
             FROM date_redirect_links
            WHERE redirector_id = :rid
              AND starts_at <= NOW()
            ORDER BY starts_at DESC, sort_order DESC, id DESC
            LIMIT 1"
    );
    $st->execute(['rid' => (int)$redirector['id']]);
    $link = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$link || !date_redirects_valid_url((string)$link['url'])) return null;
    return ['redirector' => $redirector, 'link' => $link, 'url' => (string)$link['url']];
}

function date_redirects_log_click(PDO $pdo, array $redirector, ?array $link, ?string $url, array $meta = []): void
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $referer = substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
    $pdo->prepare("
        INSERT INTO date_redirect_clicks
            (redirector_id, link_id, slug, destination_url, status, user_id, phone, email, blacklist_id, identifier_source, ip, user_agent, referer)
        VALUES
            (:rid, :lid, :slug, :url, :status, :user_id, :phone, :email, :blacklist_id, :identifier_source, :ip, :ua, :referer)
    ")->execute([
        'rid' => (int)$redirector['id'],
        'lid' => $link ? (int)$link['id'] : null,
        'slug' => (string)$redirector['slug'],
        'url' => $url,
        'status' => (string)($meta['status'] ?? 'redirected'),
        'user_id' => (int)($meta['user_id'] ?? 0) ?: null,
        'phone' => trim((string)($meta['phone'] ?? '')) ?: null,
        'email' => trim((string)($meta['email'] ?? '')) ?: null,
        'blacklist_id' => (int)($meta['blacklist_id'] ?? 0) ?: null,
        'identifier_source' => trim((string)($meta['identifier_source'] ?? '')) ?: null,
        'ip' => $ip !== '' ? $ip : null,
        'ua' => $ua !== '' ? $ua : null,
        'referer' => $referer !== '' ? $referer : null,
    ]);
    $pdo->prepare('UPDATE date_redirectors SET clicks_total = clicks_total + 1 WHERE id = :id')
        ->execute(['id' => (int)$redirector['id']]);
}

function date_redirects_extract_identity(PDO $pdo, array $query): array
{
    $phone = '';
    $email = '';
    $user = null;
    $source = '';

    foreach (['telefone', 'phone', 'whatsapp', 'celular', 'mobile', 'tel', 'lead_phone', 'aluno_telefone', 'contact_phone'] as $key) {
        if (!empty($query[$key])) {
            $phone = evolution_clean_whatsapp_phone((string)$query[$key]);
            if ($phone !== '') {
                $source = $key;
                break;
            }
        }
    }

    foreach (['email', 'e', 'mail', 'lead_email', 'aluno_email', 'contact_email'] as $key) {
        if (!empty($query[$key]) && filter_var((string)$query[$key], FILTER_VALIDATE_EMAIL)) {
            $email = strtolower(trim((string)$query[$key]));
            if ($source === '') $source = $key;
            break;
        }
    }

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
        $value = trim((string)($query[$key] ?? ''));
        if ($value === '') continue;
        if ($email === '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($value);
            if ($source === '') $source = $key;
        }
        if ($phone === '') {
            $candidatePhone = evolution_clean_whatsapp_phone($value);
            if (strlen($candidatePhone) >= 10) {
                $phone = $candidatePhone;
                if ($source === '') $source = $key;
            }
        }
    }

    $userId = (int)($query['user_id'] ?? $query['uid'] ?? $query['aluno_id'] ?? 0);
    try {
        if ($userId > 0) {
            $st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($user && $source === '') $source = 'user_id';
        }
        if (!$user && $email !== '') {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $st->execute([':email' => $email]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$user && $phone !== '') {
            $variants = evolution_phone_variants($phone);
            if ($variants) {
                $where = [];
                $params = [];
                $cleanExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
                foreach ($variants as $i => $variant) {
                    $key = ':p' . $i;
                    $where[] = "{$cleanExpr} = {$key}";
                    $params[$key] = $variant;
                }
                $st = $pdo->prepare('SELECT * FROM users WHERE ' . implode(' OR ', $where) . ' LIMIT 1');
                $st->execute($params);
                $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (Throwable $e) {}

    if ($user) {
        if ($phone === '') $phone = evolution_clean_whatsapp_phone((string)($user['telefone'] ?? ''));
        if ($email === '') $email = strtolower(trim((string)($user['email'] ?? '')));
    }

    return [
        'user' => $user,
        'user_id' => $user ? (int)($user['id'] ?? 0) : 0,
        'phone' => $phone,
        'email' => $email,
        'source' => $source,
    ];
}

function date_redirects_mark_fraud(PDO $pdo, ?array $user, string $phone, array $blacklist): void
{
    try {
        setcookie('am_fraud', '1', [
            'expires' => time() + (86400 * 3650),
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Throwable $e) {}

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return;
    $reason = trim((string)($blacklist['reason'] ?? '')) ?: 'Lista de fraude';
    try {
        user_dispatch_ensure_columns($pdo);
        $pdo->prepare("
            UPDATE users
               SET bloquear = 1,
                   bloqueado_em = COALESCE(bloqueado_em, NOW()),
                   fraud_detected_at = COALESCE(fraud_detected_at, NOW()),
                   fraud_source = 'date_redirect_antifraud',
                   fraud_reason = :reason
             WHERE id = :id
             LIMIT 1
        ")->execute([':reason' => $reason, ':id' => $userId]);
    } catch (Throwable $e) {}
    foreach (['FRAUDE', 'WHATSAPP_BLACKLIST_DETECTADO', 'BLOQUEAR'] as $tag) {
        try { adicionar_tag($userId, $tag, 'date_redirect_antifraud', null); } catch (Throwable $e) {}
    }
}

function date_redirects_notify_fraud(PDO $pdo, array $redirector, ?array $link, array $identity, array $blacklist): void
{
    try {
        $cfg = evolution_blacklist_get_config();
        if (empty($cfg['notify_enabled'])) return;
        $instanceKey = evolution_select_action_instance($pdo, null);
        $fields = [
            'participant_phone' => (string)($identity['phone'] ?? ''),
            'group_id' => '',
            'instance_key' => $instanceKey,
        ];
        $context = evolution_blacklist_contact_context($pdo, $fields, $identity['user'] ?? null, $blacklist);
        $context['grupo_nome'] = 'Redirecionador: ' . (string)($redirector['name'] ?? $redirector['slug'] ?? '');
        $context['grupo_id'] = (string)($redirector['slug'] ?? '');
        $context['status_remocao'] = 'Bloqueado antes de receber o link do grupo';
        $context['data_ocorrencia'] = date('d/m/Y H:i:s');
        $message = evolution_render_template((string)$cfg['message_template'], $context);
        evolution_blacklist_notify_recipients($pdo, $instanceKey, $message, $cfg['recipient_ids']);
        evolution_blacklist_notify_groups($pdo, $instanceKey, $message, $cfg['group_ids']);
    } catch (Throwable $e) {}
}

function date_redirects_antifraud_check(PDO $pdo, array $redirector, ?array $link): array
{
    $identity = date_redirects_extract_identity($pdo, $_GET);
    $phone = (string)($identity['phone'] ?? '');
    $blacklist = $phone !== '' ? evolution_find_active_blacklist($pdo, $phone) : null;
    $cookieBlocked = !empty($_COOKIE['am_fraud']);

    if (!$blacklist && !$cookieBlocked) {
        return ['blocked' => false, 'identity' => $identity, 'blacklist' => null];
    }

    if ($blacklist) {
        date_redirects_mark_fraud($pdo, $identity['user'] ?? null, $phone, $blacklist);
        date_redirects_notify_fraud($pdo, $redirector, $link, $identity, $blacklist);
    }

    return ['blocked' => true, 'identity' => $identity, 'blacklist' => $blacklist];
}
