<?php
if (($_GET['key'] ?? '') !== 'emerson2025') {
    http_response_code(403); exit('Acesso negado.');
}

header('Content-Type: text/plain; charset=utf-8');

$url = 'https://example.com/verificar?c=TESTE123';

echo "=== DIAGNOSTICO QR CODE ===\n\n";
echo "curl: " . (function_exists('curl_init') ? "SIM" : "NAO") . "\n";
echo "GD: "   . (function_exists('imagecreate') ? "SIM" : "NAO") . "\n\n";

if (!function_exists('curl_init')) { echo "ERRO: curl indisponivel\n"; exit; }

$apis = [
    'QRServer'     => 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($url) . '&format=png',
    'GoogleCharts' => 'https://chart.googleapis.com/chart?chs=150x150&cht=qr&choe=UTF-8&chl=' . urlencode($url),
];

foreach ($apis as $nome => $apiUrl) {
    echo "--- $nome ---\n";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP: $code | Bytes: " . strlen((string)$raw) . "\n";
    if ($err) echo "Erro: $err\n";
    echo ($raw && strlen((string)$raw) > 200) ? "RESULTADO: OK\n\n" : "RESULTADO: FALHOU\n\n";
}
