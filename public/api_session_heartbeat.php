<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$userId = (int)($_SESSION['aluno_id'] ?? 0);
$restored = false;
if ($userId <= 0) {
    $userId = aluno_restaurar_sessao_por_token();
    $restored = $userId > 0;
}

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'not_logged',
        'message' => 'Sua sessão expirou.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// A escrita/fechamento atualiza a atividade do arquivo de sessão.
$_SESSION['aluno_last_activity'] = time();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

echo json_encode([
    'ok' => true,
    'restored' => $restored,
    'user_id' => $userId,
], JSON_UNESCAPED_UNICODE);
