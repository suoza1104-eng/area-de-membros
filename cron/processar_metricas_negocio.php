<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics.php';

$pdo = getPDO();
metrics_ensure_schema($pdo);
$integration = metrics_active_integration($pdo);
$shouldSyncMeta = false;
if ($integration) {
    $last = !empty($integration['last_sync_at']) ? strtotime((string)$integration['last_sync_at']) : 0;
    $interval = max(5, (int)($integration['sync_interval_minutes'] ?? 30));
    $shouldSyncMeta = $last === 0 || (time() - $last) >= $interval * 60;
}

try {
    $result = metrics_sync_all($pdo, 3, $shouldSyncMeta);
    echo json_encode(['ok'=>true,'meta_executada'=>$shouldSyncMeta,'resultado'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    app_log('Falha no cron de metricas', ['error'=>$e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
