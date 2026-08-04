<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

$userId = (int)($_SESSION['aluno_id'] ?? 0);
if ($userId <= 0) {
    $userId = aluno_restaurar_sessao_por_token();
}

if ($userId > 0) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/trilha.php?abrir_suporte=1');
    exit;
}

$fallback = trim((string)get_setting('whatsapp_help_url', ''));
if ($fallback === '') {
    $fallback = trim((string)get_setting('login_help_url', ''));
}
if ($fallback === '') {
    $fallback = 'https://wa.me/553184297036?text=' . rawurlencode('//QUERO_SUPORTE_MCQDC');
}

header('Location: ' . $fallback);
exit;
