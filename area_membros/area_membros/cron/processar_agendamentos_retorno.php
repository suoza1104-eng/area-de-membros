<?php
declare(strict_types=1);

// Processa agendamentos de retorno vencidos. Configure este arquivo no cron a cada minuto.
require_once __DIR__ . '/../app/retorno_agendamentos.php';

$pdo = getPDO();
$result = retorno_processar_devidos($pdo, 50);

if (PHP_SAPI === 'cli') {
    echo sprintf(
        "[%s] agendamentos=%d enviados=%d erros=%d\n",
        date('Y-m-d H:i:s'),
        (int)$result['total'],
        (int)$result['enviados'],
        (int)$result['erros']
    );
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
}
