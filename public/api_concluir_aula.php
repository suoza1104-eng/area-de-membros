<?php
declare(strict_types=1);

/**
 * Endpoint: marca uma aula como concluída para o aluno logado.
 * Retorna sempre JSON (mesmo em erro) para não quebrar o fetch do front.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/funcoes.php';

// Garante sessão ativa (funcoes.php normalmente já faz, mas aqui é blindado)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (empty($_SESSION['aluno_id'])) {
        json_out(['ok' => false, 'error' => 'not_logged'], 401);
    }

    $user_id   = (int)$_SESSION['aluno_id'];
    // Libera o lock da sessao: nada mais grava em $_SESSION aqui, entao
    // outros cliques/abas do mesmo aluno nao ficam presos na fila enquanto
    // este request faz o trabalho lento (banco + webhooks).
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $lesson_id = (int)($_POST['lesson_id'] ?? 0);

    if ($lesson_id <= 0) {
        json_out(['ok' => false, 'error' => 'invalid_lesson'], 400);
    }

    $pdo = getPDO();

    // Verifica se já existe progresso dessa aula
    $stmt = $pdo->prepare("SELECT id, status FROM lesson_progress WHERE user_id = :u AND lesson_id = :l LIMIT 1");
    $stmt->execute([':u' => $user_id, ':l' => $lesson_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $alreadyCompleted = false;

    if ($row) {
        $alreadyCompleted = (string)($row['status'] ?? '') === 'completed';

        // Se já estava completed, não precisa atualizar (mas é sucesso idempotente)
        if (!$alreadyCompleted) {
            $upd = $pdo->prepare("UPDATE lesson_progress SET status='completed', completed_at=NOW() WHERE id = :id");
            $upd->execute([':id' => (int)$row['id']]);
        }
    } else {
        // Insere novo progresso como completed
        $ins = $pdo->prepare("
            INSERT INTO lesson_progress (user_id, lesson_id, status, watched_seconds, created_at, completed_at)
            VALUES (:u, :l, 'completed', NULL, NOW(), NOW())
        ");
        $ins->execute([':u' => $user_id, ':l' => $lesson_id]);
    }

    // Tag e webhooks (não devem impedir a conclusão)
    $tagNome = 'VIU_AULA_' . $lesson_id;
    try {
        if (function_exists('adicionar_tag')) {
            adicionar_tag($user_id, $tagNome, 'aula', $lesson_id);
        }
    } catch (Throwable $e) {
        // Ignora (log interno, se existir)
        if (function_exists('registrar_log')) {
            registrar_log('warning', 'api_concluir_aula', 'Falha ao adicionar tag: ' . $e->getMessage());
        }
    }

    try {
        if (function_exists('disparar_webhooks')) {
            disparar_webhooks($tagNome, $user_id, ['lesson_id' => $lesson_id]);
        }
    } catch (Throwable $e) {
        if (function_exists('registrar_log')) {
            registrar_log('warning', 'api_concluir_aula', 'Falha ao disparar webhook: ' . $e->getMessage());
        }
    }

    json_out([
        'ok' => true,
        'already_completed' => $alreadyCompleted,
        'lesson_id' => $lesson_id
    ], 200);

} catch (Throwable $e) {
    // Nunca deixar HTML/Warning quebrar o JSON
    json_out([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}
