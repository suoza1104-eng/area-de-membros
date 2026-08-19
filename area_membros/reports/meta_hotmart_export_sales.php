<?php
declare(strict_types=1);

require __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();
$sql = "SELECT id, transaction_code, status, transaction_date, payment_confirmed_at,
               product_name, price_name, gross_revenue, net_revenue, producer_net,
               refunded_value, chargeback_value, buyer_name, buyer_email,
               buyer_phone_raw, buyer_phone_norm, matched_user_id, sales_channel
        FROM hotmart_sales_live
        ORDER BY COALESCE(payment_confirmed_at, transaction_date) DESC, id DESC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
