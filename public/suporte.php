<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/support_chat.php';

$pdo = getPDO();
support_chat_ensure_schema($pdo);

$hadSession = !empty($_SESSION['aluno_id']);
$userId = (int)($_SESSION['aluno_id'] ?? 0);
if ($userId <= 0) {
    $userId = aluno_restaurar_sessao_por_token();
}

if ($userId > 0) {
    support_chat_log_event($pdo, 'support_entry', 0, $userId, 'student', (string)$userId, 'Aluno', 'chat', [
        'source' => 'public_suporte',
        'cookie_restored' => $hadSession ? 0 : 1,
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
    ]);
    header('Location: ' . rtrim(BASE_URL, '/') . '/trilha.php?abrir_suporte=1');
    exit;
}

$fallback = trim((string)get_setting('support_entry_whatsapp_url', ''));
if ($fallback === '') {
    $fallback = trim((string)get_setting('whatsapp_help_url', ''));
}
if ($fallback === '') {
    $fallback = trim((string)get_setting('login_help_url', ''));
}
if ($fallback === '') {
    $fallback = 'https://wa.me/553184297036?text=' . rawurlencode('//QUERO_SUPORTE_MCQDC');
}

support_chat_log_event($pdo, 'support_entry', 0, 0, 'visitor', '', 'Visitante', 'whatsapp', [
    'source' => 'public_suporte',
    'target_url' => $fallback,
    'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
]);

header('Location: ' . $fallback);
exit;
