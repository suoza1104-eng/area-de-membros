<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/date_redirects.php';

$pdo = getPDO();
date_redirects_ensure_schema($pdo);
date_redirects_seed($pdo);

echo 'redirectors=' . $pdo->query('SELECT COUNT(*) FROM date_redirectors')->fetchColumn() . PHP_EOL;
echo 'links=' . $pdo->query('SELECT COUNT(*) FROM date_redirect_links')->fetchColumn() . PHP_EOL;
