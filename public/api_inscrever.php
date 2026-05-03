<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@set_time_limit(60);
ignore_user_abort(true);

function api_response(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_json_response_no_exit(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api_safe_log(string $level, string $scope, string $message, array $context = []): void
{
    try {
        if (function_exists('log_sistema')) {
            log_sistema($level, $scope, $message, $context);
        }
    } catch (Throwable $e) {
        // nunca derruba o endpoint por falha de log
    }
}

function api_flush_and_continue(): void
{
    @ob_end_flush();
    @ob_flush();
    flush();

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
}

function col_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function safe_get_setting(string $key, string $default = ''): string
{
    try {
        if (function_exists('get_setting')) {
            return (string)get_setting($key, $default);
        }
    } catch (Throwable $e) {
        // ignora
    }
    return $default;
}

function safe_limpar_telefone(string $value): string
{
    try {
        if (function_exists('limpar_telefone')) {
            return (string)limpar_telefone($value);
        }
    } catch (Throwable $e) {
        // ignora
    }

    return preg_replace('/\D+/', '', $value) ?? '';
}

$raw = '';
$data = [];
$pdo = null;
$user_id = 0;
$foi_cadastrado = false;
$codigo_turma = null;
$data_live = null;

try {
    $raw = file_get_contents('php://input') ?: '';

    if (!empty($_POST)) {
        $data = $_POST;
    } elseif ($raw !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) {
            $data = $tmp;
        }
    }

    if (!$data) {
        api_safe_log('warning', 'api_inscrever', 'Payload vazio', [
            'raw' => mb_substr($raw, 0, 2000),
        ]);
        api_response(422, [
            'ok' => false,
            'error' => 'empty_payload',
        ]);
    }

    // mapeamentos configuráveis
    $mapNome   = safe_get_setting('inscricao_map_nome', 'nome');
    $mapEmail  = safe_get_setting('inscricao_map_email', 'email');
    $mapTel    = safe_get_setting('inscricao_map_telefone', 'telefone');

    $mapUSrc   = safe_get_setting('inscricao_map_utm_source', 'utm_source');
    $mapUMed   = safe_get_setting('inscricao_map_utm_medium', 'utm_medium');
    $mapUCamp  = safe_get_setting('inscricao_map_utm_campaign', 'utm_campaign');
    $mapUTerm  = safe_get_setting('inscricao_map_utm_term', 'utm_term');
    $mapUCont  = safe_get_setting('inscricao_map_utm_content', 'utm_content');

    $nome  = trim((string)($data[$mapNome]  ?? ''));
    $email = trim((string)($data[$mapEmail] ?? ''));
    $tel   = safe_limpar_telefone((string)($data[$mapTel] ?? ''));

    $utm_source   = trim((string)($data[$mapUSrc]  ?? ''));
    $utm_medium   = trim((string)($data[$mapUMed]  ?? ''));
    $utm_campaign = trim((string)($data[$mapUCamp] ?? ''));
    $utm_term     = trim((string)($data[$mapUTerm] ?? ''));
    $utm_content  = trim((string)($data[$mapUCont] ?? ''));

    if ($email === '' || $tel === '') {
        api_safe_log('warning', 'api_inscrever', 'Dados obrigatórios ausentes', [
            'payload' => $data,
        ]);
        api_response(422, [
            'ok' => false,
            'error' => 'missing_email_or_phone',
        ]);
    }

    $pdo = getPDO();

    // Garante existência da tabela de histórico de inscrições
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inscricao_logs (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NOT NULL,
                codigo_turma VARCHAR(100) NULL,
                utm_source   VARCHAR(255) NULL,
                utm_medium   VARCHAR(255) NULL,
                utm_campaign VARCHAR(255) NULL,
                utm_term     VARCHAR(255) NULL,
                utm_content  VARCHAR(255) NULL,
                is_novo      TINYINT(1)   NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT NOW(),
                INDEX idx_il_user (user_id),
                INDEX idx_il_date (created_at)
            )
        ");
    } catch (Throwable $e) {
        // não impede o fluxo
    }

    $pdo->beginTransaction();

    // pega turma pela janela atual
    $agora = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT *
          FROM turmas
         WHERE janela_inicio <= :agora
           AND janela_fim >= :agora
         ORDER BY janela_inicio DESC
         LIMIT 1
    ");
    $stmt->execute([':agora' => $agora]);
    $turma = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $codigo_turma = $turma['codigo'] ?? null;
    $data_live    = $turma['data_live'] ?? null;

    // busca por email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :e LIMIT 1");
    $stmt->execute([':e' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $upd = $pdo->prepare("
            UPDATE users
               SET nome = :n,
                   telefone = :t,
                   codigo_turma = COALESCE(:ct, codigo_turma),
                   data_live = COALESCE(:dl, data_live),
                   utm_source = :us,
                   utm_medium = :um,
                   utm_campaign = :uc,
                   utm_term = :ut,
                   utm_content = :uco
             WHERE id = :id
        ");
        $upd->execute([
            ':n'   => $nome !== '' ? $nome : (string)$user['nome'],
            ':t'   => $tel !== '' ? $tel : (string)$user['telefone'],
            ':ct'  => $codigo_turma,
            ':dl'  => $data_live,
            ':us'  => $utm_source,
            ':um'  => $utm_medium,
            ':uc'  => $utm_campaign,
            ':ut'  => $utm_term,
            ':uco' => $utm_content,
            ':id'  => (int)$user['id'],
        ]);

        $user_id = (int)$user['id'];
        $foi_cadastrado = false;
    } else {
        $senhaHash = password_hash($tel, PASSWORD_DEFAULT);

        $ins = $pdo->prepare("
            INSERT INTO users
                (nome, email, telefone, senha_hash, codigo_turma, data_live,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content, created_at)
            VALUES
                (:n, :e, :t, :sh, :ct, :dl, :us, :um, :uc, :ut, :uco, NOW())
        ");
        $ins->execute([
            ':n'   => $nome !== '' ? $nome : $email,
            ':e'   => $email,
            ':t'   => $tel,
            ':sh'  => $senhaHash,
            ':ct'  => $codigo_turma,
            ':dl'  => $data_live,
            ':us'  => $utm_source,
            ':um'  => $utm_medium,
            ':uc'  => $utm_campaign,
            ':ut'  => $utm_term,
            ':uco' => $utm_content,
        ]);

        $user_id = (int)$pdo->lastInsertId();
        $foi_cadastrado = ($user_id > 0);
    }

    // atualização opcional
    try {
        if ($data_live && col_exists($pdo, 'users', 'turma_live_at')) {
            $stmt = $pdo->prepare("
                UPDATE users
                   SET turma_live_at = :dl
                 WHERE id = :id
            ");
            $stmt->execute([
                ':dl' => $data_live,
                ':id' => $user_id,
            ]);
        }
    } catch (Throwable $e) {
        api_safe_log('warning', 'api_inscrever', 'Falha ao atualizar turma_live_at', [
            'user_id' => $user_id,
            'erro' => $e->getMessage(),
        ]);
    }

    // Historiza a inscrição (nova ou re-inscrição) — dentro da mesma transação
    if ($user_id > 0) {
        try {
            $logIns = $pdo->prepare("
                INSERT INTO inscricao_logs
                    (user_id, codigo_turma, utm_source, utm_medium, utm_campaign, utm_term, utm_content, is_novo, created_at)
                VALUES
                    (:uid, :ct, :us, :um, :uc, :ut, :uco, :novo, NOW())
            ");
            $logIns->execute([
                ':uid'  => $user_id,
                ':ct'   => $codigo_turma,
                ':us'   => $utm_source,
                ':um'   => $utm_medium,
                ':uc'   => $utm_campaign,
                ':ut'   => $utm_term,
                ':uco'  => $utm_content,
                ':novo' => $foi_cadastrado ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            api_safe_log('warning', 'api_inscrever', 'Falha ao registrar inscricao_log', [
                'user_id' => $user_id,
                'erro'    => $e->getMessage(),
            ]);
        }
    }

    // COMMIT DO CADASTRO PRINCIPAL
    $pdo->commit();

    // RESPONDE IMEDIATAMENTE PARA O HUB
    api_json_response_no_exit(200, [
        'ok' => true,
        'cadastrado' => $foi_cadastrado,
        'user_id' => $user_id,
        'codigo_turma' => $codigo_turma,
        'data_live' => $data_live,
    ]);

    api_flush_and_continue();

    // tarefas secundárias depois da resposta
    if ($foi_cadastrado) {
        try {
            if (function_exists('adicionar_tag')) {
                adicionar_tag($user_id, 'INSCRITO', 'inscricao', null);
            }
        } catch (Throwable $e) {
            api_safe_log('warning', 'api_inscrever', 'Falha ao adicionar tag INSCRITO', [
                'user_id' => $user_id,
                'erro' => $e->getMessage(),
            ]);
        }

        if ($codigo_turma) {
            try {
                if (function_exists('adicionar_tag')) {
                    adicionar_tag($user_id, 'INSCRITO_TURMA_' . $codigo_turma, 'inscricao', null);
                }
            } catch (Throwable $e) {
                api_safe_log('warning', 'api_inscrever', 'Falha ao adicionar tag da turma', [
                    'user_id' => $user_id,
                    'codigo_turma' => $codigo_turma,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        api_safe_log('info', 'api_inscrever', 'Inscricao cadastrada', [
            'user_id' => $user_id,
            'turma' => $codigo_turma,
            'payload' => $data,
        ]);

        try {
            if (function_exists('disparar_webhooks')) {
                disparar_webhooks('INSCRITO', $user_id, [
                    'codigo_turma' => $codigo_turma,
                    'codigo_live'  => $codigo_turma,
                    'data_live'    => $data_live,
                ]);
            }
        } catch (Throwable $e) {
            api_safe_log('warning', 'api_inscrever', 'Falha ao disparar webhooks INSCRITO', [
                'user_id' => $user_id,
                'codigo_turma' => $codigo_turma,
                'erro' => $e->getMessage(),
            ]);
        }
    } else {
        api_safe_log('info', 'api_inscrever', 'Inscricao recebida (usuario já existe)', [
            'user_id' => $user_id,
            'turma' => $codigo_turma,
            'payload' => $data,
        ]);
    }

    exit;

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_safe_log('error', 'api_inscrever', 'Erro interno no endpoint', [
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'payload_post' => $_POST ?? [],
        'payload_raw' => $raw,
    ]);

    api_response(500, [
        'ok' => false,
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ]);
}