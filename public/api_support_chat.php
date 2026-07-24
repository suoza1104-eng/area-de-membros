<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/support_chat.php';

proteger_aluno();
$pdo = getPDO();
support_chat_ensure_schema($pdo);
support_chat_auto_close_idle($pdo);

if (empty($_SESSION['support_chat_public_csrf'])) {
    $_SESSION['support_chat_public_csrf'] = bin2hex(random_bytes(24));
}

function support_public_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function support_public_conversation(PDO $pdo, int $preferredId = 0): int
{
    $userId = (int)($_SESSION['aluno_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Aluno nao autenticado.');
    }
    if (get_setting('support_chat_student_enabled', '0') !== '1') {
        throw new RuntimeException('Suporte pelo agente desativado.');
    }
    if ($preferredId > 0) {
        $st = $pdo->prepare("SELECT id FROM support_conversations WHERE id=:id AND user_id=:u LIMIT 1");
        $st->execute(['id'=>$preferredId,'u'=>$userId]);
        if ((int)$st->fetchColumn() === $preferredId) {
            return $preferredId;
        }
    }
    return support_chat_get_or_create($pdo, $userId, 'app');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $api = (string)($_GET['api'] ?? 'init');
        if ($api === 'init') {
            $conversationId = support_public_conversation($pdo);
            support_chat_mark_read($pdo, $conversationId, 'student');
            $avatarUrl = trim((string)get_setting('support_chat_avatar_url', ''));
            $avatarSrc = '';
            if ($avatarUrl !== '') {
                $avatarSrc = preg_match('~^(?:https?:)?//|^data:|^/~i', $avatarUrl) ? $avatarUrl : $avatarUrl;
            }
            support_public_json([
                'ok' => true,
                'csrf' => (string)$_SESSION['support_chat_public_csrf'],
                'conversation_id' => $conversationId,
                'display_name' => trim((string)get_setting('support_chat_display_name', 'Suporte FERA')) ?: 'Suporte FERA',
                'avatar_src' => $avatarSrc,
                'font_scale' => max(0.85, min(1.35, (float)get_setting('support_chat_font_scale', '1.08'))),
                'welcome' => trim((string)get_setting('support_chat_welcome', '')),
                'messages' => support_chat_messages($pdo, $conversationId, 0),
                'typing' => support_chat_typing_state($pdo, $conversationId, 'student'),
            ]);
        }
        if ($api === 'messages') {
            $conversationId = support_public_conversation($pdo, (int)($_GET['conversation_id'] ?? 0));
            support_chat_mark_read($pdo, $conversationId, 'student');
            support_public_json([
                'ok' => true,
                'conversation_id' => $conversationId,
                'messages' => support_chat_messages($pdo, $conversationId, (int)($_GET['after'] ?? 0)),
                'typing' => support_chat_typing_state($pdo, $conversationId, 'student'),
            ]);
        }
        support_public_json(['ok' => false, 'error' => 'API invalida.'], 404);
    }

    if (!hash_equals((string)$_SESSION['support_chat_public_csrf'], (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Sessao expirada.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'feedback') {
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        $st = $pdo->prepare("SELECT user_id FROM support_conversations WHERE id=:id LIMIT 1");
        $st->execute(['id'=>$conversationId]);
        if ((int)$st->fetchColumn() !== (int)($_SESSION['aluno_id'] ?? 0)) {
            throw new RuntimeException('Atendimento invalido.');
        }
        support_chat_submit_feedback($pdo, $conversationId, (int)($_SESSION['aluno_id'] ?? 0), (int)($_POST['rating'] ?? 0), (string)($_POST['comment'] ?? ''));
        support_public_json(['ok' => true]);
    }
    $conversationId = support_public_conversation($pdo);
    if ($action === 'send') {
        $body = (string)($_POST['body'] ?? '');
        $attachment = [];
        if (!empty($_FILES['attachment']['tmp_name'])) {
            $attachment = support_chat_store_upload($_FILES['attachment']);
        }
        $messageId = support_chat_send($pdo, $conversationId, 'student', (string)($_SESSION['aluno_id'] ?? ''), (string)($_SESSION['aluno_nome'] ?? 'Aluno'), $body, $attachment);
        if (support_agent_config($pdo)['enabled']) {
            support_agent_handle_student_message($pdo, $conversationId, $messageId);
        } else {
            support_chat_run_automation($pdo, $conversationId);
        }
        support_public_json(['ok' => true, 'message_id' => $messageId, 'conversation_id' => $conversationId]);
    }
    if ($action === 'typing') {
        support_chat_typing($pdo, $conversationId, 'student', (string)($_SESSION['aluno_nome'] ?? 'Aluno'));
        support_public_json(['ok' => true]);
    }
    support_public_json(['ok' => false, 'error' => 'Acao invalida.'], 400);
} catch (Throwable $e) {
    support_public_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
