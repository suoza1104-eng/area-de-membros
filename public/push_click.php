<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/push_notifications.php';

$id = max(0, (int)($_GET['id'] ?? 0));
$target = trim((string)($_GET['url'] ?? ''));
try { $target = push_normalize_click_url($target); }
catch (Throwable $e) { $target = 'trilha.php'; }
try {
    if ($id > 0) {
        $pdo = getPDO();
        push_ensure_schema($pdo);
        $pdo->prepare("UPDATE push_delivery_logs SET clicked_at=COALESCE(clicked_at,NOW()),status=IF(status='accepted','clicked',status) WHERE id=:id")
            ->execute(['id'=>$id]);
        $pdo->prepare("UPDATE push_notifications n SET clicked_count=(SELECT COUNT(*) FROM push_delivery_logs l WHERE l.notification_id=n.id AND l.clicked_at IS NOT NULL) WHERE n.id=(SELECT notification_id FROM push_delivery_logs WHERE id=:id)")
            ->execute(['id'=>$id]);
    }
} catch (Throwable $e) {}
header('Location: ' . ($target ?: 'trilha.php'));
exit;
