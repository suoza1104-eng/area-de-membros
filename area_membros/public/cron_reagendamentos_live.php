<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = trim((string)get_setting('cron_reagendamentos_token', ''));
$provided = trim((string)($_GET['token'] ?? ''));

if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$lockDir = __DIR__ . '/../app/error_log';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/cron_reagendamentos_live.lock';
$fh = @fopen($lockFile, 'c');
if (!$fh) {
    http_response_code(500);
    echo "lock_unavailable\n";
    exit;
}

if (!flock($fh, LOCK_EX | LOCK_NB)) {
    echo "already_running\n";
    exit;
}

try {
    require __DIR__ . '/../cron/processar_reagendamentos_live.php';
} finally {
    flock($fh, LOCK_UN);
    fclose($fh);
}
