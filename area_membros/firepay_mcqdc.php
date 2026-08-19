<?php
declare(strict_types=1);

if (in_array(($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo json_encode([
            'ok' => true,
            'endpoint' => 'firepay',
            'message' => 'Endpoint Firepay ativo. Envie webhooks por POST.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$_GET['t'] = '58ae38d6fc106c96ca7e752e8a7b1598eaf40627e37c4e8fa94b3043f322ce1b';

require __DIR__ . '/public/inbound_webhook.php';
