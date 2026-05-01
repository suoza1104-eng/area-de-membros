<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
header('Content-Type: application/json');

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT created_at, mensagem, contexto_json FROM system_logs WHERE origem = 'api_inscrever' ORDER BY id DESC LIMIT 50");
$stmt->execute();
$out = [];
foreach ($stmt as $row) {
    $out[] = [
        'created_at' => $row['created_at'],
        'mensagem'   => $row['mensagem'],
        'contexto'   => json_decode($row['contexto_json'] ?? '[]', true),
    ];
}
echo json_encode(array_reverse($out));
