<?php
declare(strict_types=1);

if (empty($GLOBALS['cron_manager_task_key'])) {
    require_once __DIR__ . '/../app/cron_manager.php';
    $managedResult = cron_manager_execute(getPDO(), 'whatsapp_status', 'hosting', false);
    echo json_encode($managedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return;
}

// Consulta o estado real de conexao de cada instancia na Evolution API e atualiza
// whatsapp_instances.status, que so era atualizado manualmente (Gerar QR / Atualizar status).
require_once __DIR__ . '/../app/evolution_api.php';

$pdo = getPDO();
$instances = $pdo->query("SELECT * FROM whatsapp_instances WHERE is_enabled = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$checked = 0;
$changed = 0;
$errors = 0;
foreach ($instances as $instance) {
    $previousStatus = (string)$instance['status'];
    try {
        $res = evolution_fetch_state($pdo, $instance);
        if (empty($res['ok'])) {
            $errors++;
            continue;
        }
        $checked++;
        $updated = evolution_get_instance($pdo, (int)$instance['id']);
        if ($updated && (string)$updated['status'] !== $previousStatus) {
            $changed++;
        }
    } catch (Throwable $e) {
        $errors++;
    }
}

$result = [
    'total' => count($instances),
    'checked' => $checked,
    'changed' => $changed,
    'errors' => $errors,
];

if (PHP_SAPI === 'cli') {
    echo sprintf(
        "[%s] whatsapp_status total=%d checked=%d changed=%d errors=%d\n",
        date('Y-m-d H:i:s'),
        $result['total'],
        $result['checked'],
        $result['changed'],
        $result['errors']
    );
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
