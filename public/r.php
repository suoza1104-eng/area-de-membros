<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/date_redirects.php';

$slug = trim((string)($_GET['s'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9_-]{1,120}$/', $slug)) {
    http_response_code(404);
    echo 'Link nao encontrado.';
    exit;
}

try {
    $pdo = getPDO();
    date_redirects_ensure_schema($pdo);
    $dest = date_redirects_find_destination($pdo, $slug);
    if (!$dest) {
        http_response_code(404);
        echo 'Este redirecionamento ainda nao esta disponivel.';
        exit;
    }
    $meta = ['status' => 'redirected'];
    $targetUrl = $dest['url'];
    $redirector = $dest['redirector'];
    $link = $dest['link'];
    $matchedSlug = (string)($dest['matched_slug'] ?? $slug);

    if ((string)($redirector['redirect_type'] ?? 'date') === 'link' && (int)($redirector['antifraud_enabled'] ?? 0) === 1) {
        $check = date_redirects_antifraud_check($pdo, $redirector, $link);
        $identity = $check['identity'] ?? [];
        $blacklist = $check['blacklist'] ?? null;
        $meta = [
            'status' => !empty($check['blocked']) ? 'blocked_silent' : 'redirected',
            'user_id' => (int)($identity['user_id'] ?? 0),
            'phone' => (string)($identity['phone'] ?? ''),
            'email' => (string)($identity['email'] ?? ''),
            'blacklist_id' => $blacklist ? (int)($blacklist['id'] ?? 0) : null,
            'identifier_source' => (string)($identity['source'] ?? ''),
            'slug' => $matchedSlug,
        ];
        if (!empty($check['blocked'])) {
            $targetUrl = date_redirects_blocked_url((string)($redirector['blocked_redirect_url'] ?? ''));
        }
    }
    $meta['slug'] = $matchedSlug;

    date_redirects_log_click($pdo, $redirector, $link, $targetUrl, $meta);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $targetUrl, true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Nao foi possivel redirecionar agora.';
}
