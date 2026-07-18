<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/automation_flows.php';

$pdo = getPDO();
$result = automation_flow_process_queue($pdo, 50);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
