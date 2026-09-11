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
        echo 'Link indisponivel.';
        exit;
    }
    date_redirects_log_click($pdo, $dest['redirector'], $dest['link'], $dest['url']);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $dest['url'], true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Nao foi possivel redirecionar agora.';
}
