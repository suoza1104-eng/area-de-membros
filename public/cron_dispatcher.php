<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/cron_manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$pdo = getPDO();
cron_manager_ensure_tables($pdo);

$provided = trim((string)(
    $_SERVER['HTTP_X_CRON_TOKEN']
    ?? $_POST['token']
    ?? $_GET['token']
    ?? ''
));
$expected = cron_manager_token($pdo);
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$source = strtolower(trim((string)($_POST['source'] ?? $_GET['source'] ?? 'vps')));
if (!in_array($source, ['vps', 'hosting'], true)) $source = 'vps';
$taskKey = trim((string)($_POST['task'] ?? $_GET['task'] ?? ''));
cron_manager_heartbeat($pdo, $source, $taskKey !== '' ? $taskKey : 'health');

if ($taskKey === 'list') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach (array_keys(cron_manager_definitions()) as $registeredTaskKey) {
        echo $registeredTaskKey . PHP_EOL;
    }
    exit;
}

if ($taskKey === 'health') {
    echo json_encode([
        'ok' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'tasks' => array_map(static fn($row) => [
            'task_key' => $row['task_key'],
            'enabled' => (bool)$row['enabled'],
            'mode' => $row['mode'],
            'primary_source' => $row['primary_source'],
            'last_success_at' => $row['last_success_at'],
            'last_status' => $row['last_status'],
        ], cron_manager_tasks($pdo)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $result = cron_manager_execute($pdo, $taskKey, $source, false);
    // Compatibilidade com agentes instalados antes da criação dos fluxos push.
    // Eles já chamam agendamentos_retorno a cada minuto; a verificação adicional
    // é barata e o gerenciador impede execução duplicada quando o agente novo
    // também solicitar fluxos_push diretamente.
    if ($taskKey === 'agendamentos_retorno') {
        $result['companion_fluxos_push'] = cron_manager_execute($pdo, 'fluxos_push', $source, false);
    }
    cron_manager_heartbeat($pdo, $source, $taskKey, (string)($result['reason'] ?? $result['status'] ?? 'ok'), false);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    cron_manager_heartbeat($pdo, $source, $taskKey, 'error', false);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'executed' => false,
        'task' => $taskKey,
        'source' => $source,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
