<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/meta_qualified_leads.php';

@set_time_limit(300);

$pdo = getPDO();
mql_ensure_schema($pdo);

$stats = mql_process_queue($pdo, 80);

echo json_encode([
    'ok' => true,
    'task' => 'meta_leads_qualificados',
    'stats' => $stats,
    'finished_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
