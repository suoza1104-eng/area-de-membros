<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function date_redirects_ensure_schema(PDO $pdo): void
{
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
            clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            referer VARCHAR(255) NULL,
            KEY idx_date_redirect_clicks_redirector (redirector_id, clicked_at),
            KEY idx_date_redirect_clicks_link (link_id, clicked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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

    $st = $pdo->prepare("
        SELECT *
          FROM date_redirect_links
         WHERE redirector_id = :rid
           AND starts_at <= NOW()
         ORDER BY starts_at DESC, sort_order DESC, id DESC
         LIMIT 1
    ");
    $st->execute(['rid' => (int)$redirector['id']]);
    $link = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$link) {
        $st = $pdo->prepare("
            SELECT *
              FROM date_redirect_links
             WHERE redirector_id = :rid
             ORDER BY starts_at ASC, sort_order ASC, id ASC
             LIMIT 1
        ");
        $st->execute(['rid' => (int)$redirector['id']]);
        $link = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$link || !date_redirects_valid_url((string)$link['url'])) return null;
    return ['redirector' => $redirector, 'link' => $link, 'url' => (string)$link['url']];
}

function date_redirects_log_click(PDO $pdo, array $redirector, ?array $link, ?string $url): void
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $referer = substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
    $pdo->prepare("
        INSERT INTO date_redirect_clicks (redirector_id, link_id, slug, destination_url, ip, user_agent, referer)
        VALUES (:rid, :lid, :slug, :url, :ip, :ua, :referer)
    ")->execute([
        'rid' => (int)$redirector['id'],
        'lid' => $link ? (int)$link['id'] : null,
        'slug' => (string)$redirector['slug'],
        'url' => $url,
        'ip' => $ip !== '' ? $ip : null,
        'ua' => $ua !== '' ? $ua : null,
        'referer' => $referer !== '' ? $referer : null,
    ]);
    $pdo->prepare('UPDATE date_redirectors SET clicks_total = clicks_total + 1 WHERE id = :id')
        ->execute(['id' => (int)$redirector['id']]);
}
