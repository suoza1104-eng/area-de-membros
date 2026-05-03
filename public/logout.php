<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';

// Invalida o token de auto-login no banco e limpa o cookie
if (!empty($_COOKIE['am_token'])) {
    try {
        $pdo = getPDO();
        $pdo->prepare("DELETE FROM remember_tokens WHERE token = :tok")
            ->execute([':tok' => $_COOKIE['am_token']]);
    } catch (Throwable $e) {}
    setcookie('am_token', '', time() - 3600, '/');
}

unset($_SESSION['aluno_id']);
session_write_close();
header('Location: ' . BASE_URL . '/login.php');
exit;
