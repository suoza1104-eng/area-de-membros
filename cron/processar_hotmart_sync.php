<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/hotmart_api.php';

$pdo = getPDO();
$res = hotmart_sync_sales_api($pdo, 7);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
