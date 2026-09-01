<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/pagarme.php';

$pdo = getPDO();
$res = pagarme_sync_orders_api($pdo, 3);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
