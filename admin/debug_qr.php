<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

header('Content-Type: text/plain; charset=utf-8');

$url = 'https://minha-area.com/verificar?c=TESTE123';

echo "=== DIAGNÓSTICO QR CODE ===\n\n";

echo "1. curl disponível: " . (function_exists('curl_init') ? "SIM" : "NÃO") . "\n";
echo "2. GD disponível: " . (function_exists('imagecreate') ? "SIM" : "NÃO") . "\n\n";

if (!function_exists('curl_init')) {
    echo "ERRO: curl não está disponível no PHP. Instale a extensão php-curl.\n";
    exit;
}

$apis = [
    'QRServer'     => 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($url) . '&format=png',
    'GoogleCharts' => 'https://chart.googleapis.com/chart?chs=150x150&cht=qr&choe=UTF-8&chl=' . urlencode($url),
];

foreach ($apis as $nome => $apiUrl) {
    echo "--- Testando $nome ---\n";
    echo "URL: $apiUrl\n";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER         => false,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP: $code\n";
    echo "Bytes recebidos: " . strlen((string)$raw) . "\n";
    if ($err) echo "Erro curl: $err\n";
    if ($raw && strlen((string)$raw) > 200) {
        echo "RESULTADO: OK — QR code recebido com sucesso!\n";
    } else {
        echo "RESULTADO: FALHOU — resposta vazia ou pequena demais\n";
    }
    echo "\n";
}
