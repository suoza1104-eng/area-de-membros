<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../app/config.php';

$pdo = getPDO();
$queries = [
    'hotmart_webhook_events' => "SELECT COUNT(*) c, MAX(processed_at) last_at FROM hotmart_webhook_events",
    'pagarme_webhook_events' => "SELECT COUNT(*) c, MAX(processed_at) last_at FROM pagarme_webhook_events",
    'dom_webhook_events' => "SELECT COUNT(*) c, MAX(processed_at) last_at FROM dom_webhook_events",
    'student_payment_events' => "SELECT COUNT(*) c, MAX(last_seen_at) last_at FROM student_payment_events",
    'hotmart_sales' => "SELECT COUNT(*) c, MAX(COALESCE(payment_confirmed_at,sale_date,created_at)) last_at FROM hotmart_sales",
    'hotmart_sales_live_hotmart' => "SELECT COUNT(*) c, MAX(COALESCE(payment_confirmed_at,transaction_date,updated_at)) last_at FROM hotmart_sales_live WHERE COALESCE(NULLIF(sales_channel,''),'hotmart')='hotmart'",
    'payment_sales_hotmart' => "SELECT COUNT(*) c, MAX(last_received_at) last_at FROM payment_sales WHERE provider='hotmart'",
    'payment_sales_pagarme' => "SELECT COUNT(*) c, MAX(last_received_at) last_at FROM payment_sales WHERE provider='pagarme'",
    'payment_sales_dom' => "SELECT COUNT(*) c, MAX(last_received_at) last_at FROM payment_sales WHERE provider='dom'",
    'student_payment_events_hotmart' => "SELECT COUNT(*) c, MAX(last_seen_at) last_at FROM student_payment_events WHERE provider='hotmart'",
    'student_payment_events_pagarme' => "SELECT COUNT(*) c, MAX(last_seen_at) last_at FROM student_payment_events WHERE provider='pagarme'",
    'student_payment_events_dom' => "SELECT COUNT(*) c, MAX(last_seen_at) last_at FROM student_payment_events WHERE provider='dom'",
];

foreach ($queries as $name => $sql) {
    try {
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'last_at' => null];
        echo $name . ' count=' . (string)$row['c'] . ' last=' . (string)$row['last_at'] . PHP_EOL;
    } catch (Throwable $e) {
        echo $name . ' ERROR ' . $e->getMessage() . PHP_EOL;
    }
}
