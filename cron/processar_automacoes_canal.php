<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/automation_flows.php';

$pdo = getPDO();
$taskKey = (string)($GLOBALS['cron_manager_task_key'] ?? '');
$channel = 'general';
if (strncmp($taskKey, 'automacoes_', 11) === 0) {
    $candidate = substr($taskKey, 11);
    if (in_array($candidate, automation_flow_channels(), true)) $channel = $candidate;
}

$settings = automation_flow_channel_settings($pdo, $channel);
$limit = max(1, min(200, (int)$settings['batch_size']));
$result = automation_flow_process_channel($pdo, $channel, $limit);
$result['channel'] = $channel;

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
