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
    $user_id = (int)($_SESSION['aluno_id'] ?? 0);
    $authRestored = false;
    if ($user_id <= 0) {
        $user_id = aluno_restaurar_sessao_por_token();
        $authRestored = $user_id > 0;
    }
    if ($user_id <= 0) {
        $basePath = trim((string)parse_url(BASE_URL, PHP_URL_PATH), '/');
        $nextPath = ($basePath !== '' ? $basePath . '/' : '') . 'aula.php?id=' . (int)($_POST['lesson_id'] ?? 0);
        json_out([
            'ok' => false,
            'error' => 'not_logged',
            'message' => 'Sua sessão expirou. Entre novamente para concluir a aula.',
            'login_url' => BASE_URL . '/login.php?next=' . urlencode($nextPath),
        ], 401);
    }

    // Libera o lock da sessao: nada mais grava em $_SESSION aqui, entao
    // outros cliques/abas do mesmo aluno nao ficam presos na fila enquanto
    // este request faz o trabalho lento (banco + webhooks).
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $lesson_id = (int)($_POST['lesson_id'] ?? 0);

    if ($lesson_id <= 0) {
        json_out(['ok' => false, 'error' => 'invalid_lesson'], 400);
    }

    $pdo = getPDO();
    $courseAccess = course_access_status($pdo, $user_id);
    if (!empty($courseAccess['expired'])) {
        json_out([
            'ok' => false,
            'error' => 'access_expired',
            'message' => 'Seu prazo máximo de acesso terminou. Libere o acesso vitalício para continuar.',
            'checkout_url' => (string)($courseAccess['checkout_url'] ?? ''),
        ], 403);
    }

    // Verifica se já existe progresso dessa aula
    $stmt = $pdo->prepare("SELECT id, status FROM lesson_progress WHERE user_id = :u AND lesson_id = :l LIMIT 1");
    $stmt->execute([':u' => $user_id, ':l' => $lesson_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $alreadyCompleted = false;

    $alreadyCompleted = $row && (string)($row['status'] ?? '') === 'completed';

    // Upsert atômico: dois cliques/abas simultâneos não geram erro de chave
    // duplicada e a operação continua idempotente.
    $save = $pdo->prepare("
        INSERT INTO lesson_progress
            (user_id, lesson_id, status, watched_seconds, created_at, completed_at)
        VALUES
            (:u, :l, 'completed', NULL, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            status = 'completed',
            completed_at = COALESCE(completed_at, NOW())
    ");
    $save->execute([':u' => $user_id, ':l' => $lesson_id]);

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
            $eventExtra = ['lesson_id' => $lesson_id, 'origem' => 'api_concluir_aula'];
            disparar_webhooks($tagNome, $user_id, $eventExtra);
            // Evento genérico usado pelo construtor de fluxos. Mantemos também
            // VIU_AULA_{id} para integrações que dependem da aula específica.
            disparar_webhooks('ASSISTIU_ALGUMA_AULA', $user_id, $eventExtra);
        }
    } catch (Throwable $e) {
        if (function_exists('registrar_log')) {
            registrar_log('warning', 'api_concluir_aula', 'Falha ao disparar webhook: ' . $e->getMessage());
        }
    }

    json_out([
        'ok' => true,
        'already_completed' => $alreadyCompleted,
        'auth_restored' => $authRestored,
        'lesson_id' => $lesson_id
    ], 200);

} catch (Throwable $e) {
    try {
        log_sistema('error', 'api_concluir_aula', 'Falha ao concluir aula', [
            'user_id' => $user_id ?? null,
            'lesson_id' => $lesson_id ?? (int)($_POST['lesson_id'] ?? 0),
            'erro' => $e->getMessage(),
        ], $e->getTraceAsString());
    } catch (Throwable $logError) {}
    // Nunca deixar HTML/Warning quebrar o JSON
    json_out([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}
