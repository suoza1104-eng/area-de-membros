<?php
// FILE: app/funcoes.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/webhook_dispatcher.php';
require_once __DIR__ . '/superfuncionario_dispatcher.php';
require_once __DIR__ . '/manychat_dispatcher.php';

function proteger_aluno(): void {
    if (empty($_SESSION['aluno_id'])) {
        $next = '';
        $reqUri = $_SERVER['REQUEST_URI'] ?? '';
        // só aceita paths relativos dentro do próprio site (evita open redirect)
        if ($reqUri !== '' && strpos($reqUri, '://') === false) {
            $next = '?next=' . urlencode(ltrim($reqUri, '/'));
        }
        header('Location: ' . BASE_URL . '/login.php' . $next);
        exit;
    }
}

function proteger_admin(): void {
    if (empty($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
        header('Location: ' . BASE_URL_ADMIN . '/index.php');
        exit;
    }
}

function redirecionar(string $url): void {
    header('Location: ' . $url);
    exit;
}

function limpar_telefone(string $tel): string {
    return preg_replace('/\D+/', '', $tel);
}

function buscar_usuario_por_email(string $email): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :e LIMIT 1");
    $stmt->execute([':e' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function buscar_usuario_por_id(int $id): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function user_dispatch_ensure_columns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE users ADD COLUMN bloquear TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN bloqueado_em DATETIME NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN desbloqueado_em DATETIME NULL"); } catch (Throwable $e) {}
    try {
        $pdo->exec("
            UPDATE users u
            JOIN user_tags ut ON ut.user_id = u.id
            JOIN tags t ON t.id = ut.tag_id
               SET u.bloquear = 1,
                   u.bloqueado_em = COALESCE(u.bloqueado_em, ut.created_at, NOW())
             WHERE UPPER(t.nome) = 'BLOQUEAR'
               AND u.bloquear <> 1
        ");
    } catch (Throwable $e) {}
    $done = true;
}

function normalizar_tag_sistema(string $tag): string {
    $tag = trim($tag);
    if ($tag === '') return '';
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $tag);
    if (is_string($ascii) && $ascii !== '') $tag = $ascii;
    $tag = strtoupper($tag);
    $tag = preg_replace('/[^A-Z0-9]+/', '_', $tag) ?? $tag;
    return trim($tag, '_');
}

function usuario_bloqueado_disparos(PDO $pdo, ?int $userId): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    try {
        user_dispatch_ensure_columns($pdo);
        $st = $pdo->prepare("SELECT bloquear FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        return (int)($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function aplicar_tag_sistema_disparo(int $userId, string $tagNome): void {
    if ($userId <= 0) return;
    $tag = normalizar_tag_sistema($tagNome);
    if ($tag !== 'BLOQUEAR' && $tag !== 'DESBLOQUEAR') return;

    $pdo = getPDO();
    user_dispatch_ensure_columns($pdo);

    if ($tag === 'BLOQUEAR') {
        $desbloquearTagId = obter_ou_criar_tag_id('DESBLOQUEAR');
        $pdo->prepare("UPDATE users SET bloquear = 1, bloqueado_em = NOW() WHERE id = :id LIMIT 1")
            ->execute([':id' => $userId]);
        if ($desbloquearTagId > 0) {
            $pdo->prepare("DELETE FROM user_tags WHERE user_id = :u AND tag_id = :t")
                ->execute([':u' => $userId, ':t' => $desbloquearTagId]);
        }
        return;
    }

    $bloquearTagId = obter_ou_criar_tag_id('BLOQUEAR');
    $pdo->prepare("UPDATE users SET bloquear = 0, desbloqueado_em = NOW() WHERE id = :id LIMIT 1")
        ->execute([':id' => $userId]);
    if ($bloquearTagId > 0) {
        $pdo->prepare("DELETE FROM user_tags WHERE user_id = :u AND tag_id = :t")
            ->execute([':u' => $userId, ':t' => $bloquearTagId]);
    }
}

function adicionar_tag_ao_usuario(int $user_id, int $tag_id, string $origem = 'manual', ?int $referencia_id = null): void {
    $pdo = getPDO();
    $tagNomeSistema = '';
    try {
        $stTag = $pdo->prepare("SELECT nome FROM tags WHERE id = :id LIMIT 1");
        $stTag->execute([':id' => $tag_id]);
        $tagNomeSistema = (string)($stTag->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    if (normalizar_tag_sistema($tagNomeSistema) === 'DESBLOQUEAR') {
        aplicar_tag_sistema_disparo($user_id, $tagNomeSistema);
    }

    // evita duplicar user_tag
    $stmt = $pdo->prepare("SELECT id FROM user_tags WHERE user_id = :u AND tag_id = :t");
    $stmt->execute([':u' => $user_id, ':t' => $tag_id]);
    if ($stmt->fetch()) {
        if (normalizar_tag_sistema($tagNomeSistema) === 'BLOQUEAR' || normalizar_tag_sistema($tagNomeSistema) === 'DESBLOQUEAR') {
            aplicar_tag_sistema_disparo($user_id, $tagNomeSistema);
        }
        return;
    }

    $ins = $pdo->prepare("
        INSERT INTO user_tags (user_id, tag_id, origem, referencia_id, created_at)
        VALUES (:u, :t, :o, :r, NOW())
    ");
    $ins->execute([
        ':u' => $user_id,
        ':t' => $tag_id,
        ':o' => $origem,
        ':r' => $referencia_id,
    ]);

    if (normalizar_tag_sistema($tagNomeSistema) === 'BLOQUEAR') {
        aplicar_tag_sistema_disparo($user_id, $tagNomeSistema);
    }
}


/**
 * Garante que uma tag exista (por NOME) e retorna o id.
 * - Se não existir, cria.
 */
function obter_ou_criar_tag_id(string $tag_nome): int {
    $tag_nome = trim($tag_nome);
    if ($tag_nome === '') return 0;

    $pdo = getPDO();

    // 1) tenta buscar
    $st = $pdo->prepare("SELECT id FROM tags WHERE nome = :n LIMIT 1");
    $st->execute([':n' => $tag_nome]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    // 2) tenta criar (duas tentativas: com/sem created_at)
    try {
        $ins = $pdo->prepare("INSERT INTO tags (nome) VALUES (:n)");
        $ins->execute([':n' => $tag_nome]);
    } catch (Throwable $e1) {
        try {
            $ins = $pdo->prepare("INSERT INTO tags (nome, created_at) VALUES (:n, NOW())");
            $ins->execute([':n' => $tag_nome]);
        } catch (Throwable $e2) {
            // se houve corrida (outro processo criou), tenta buscar novamente
        }
    }

    $st = $pdo->prepare("SELECT id FROM tags WHERE nome = :n LIMIT 1");
    $st->execute([':n' => $tag_nome]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Adiciona uma tag ao usuário por NOME (criando a tag se necessário).
 * Retorna true quando a operação foi bem-sucedida.
 */
function adicionar_tag(int $user_id, string $tag_nome, string $origem = 'manual', ?int $referencia_id = null): bool {
    if ($user_id <= 0) return false;

    try {
        $tagSistema = normalizar_tag_sistema($tag_nome);
        if ($tagSistema === 'DESBLOQUEAR') {
            aplicar_tag_sistema_disparo($user_id, $tag_nome);
        }

        $tag_id = obter_ou_criar_tag_id($tag_nome);
        if ($tag_id <= 0) return false;

        adicionar_tag_ao_usuario($user_id, $tag_id, $origem, $referencia_id);

        if ($tagSistema === 'BLOQUEAR') {
            aplicar_tag_sistema_disparo($user_id, $tag_nome);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}


/**
 * Dispara webhooks para um determinado evento.
 *
 * $evento  - código do evento (ex.: CERT_EMITIDO, ASSISTIU_ALGUMA_AULA, etc.)
 * $user_id - id do usuário (opcional)
 * $extra   - dados extras específicos do evento
 */
function disparar_webhooks(string $evento, ?int $user_id = null, array $extra = []): void {
    // Em requisições web, adia o envio para DEPOIS de entregar a resposta ao
    // usuário. Assim o aluno nunca espera a resposta de APIs externas
    // (webhooks / SuperFuncionário) durante login, conclusão de aula, etc.
    // Em CLI/cron executa na hora (o processo precisa terminar o trabalho).
    if (PHP_SAPI !== 'cli') {
        register_shutdown_function(static function () use ($evento, $user_id, $extra) {
            // Entrega a resposta ao navegador antes de iniciar o trabalho lento.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                _disparar_webhooks_sync($evento, $user_id, $extra);
            } catch (Throwable $e) {
                @error_log('disparar_webhooks (shutdown): ' . $e->getMessage());
            }
        });
        return;
    }

    _disparar_webhooks_sync($evento, $user_id, $extra);
}

/**
 * Execução síncrona real do disparo (usada no cron e no shutdown da web).
 */
function _disparar_webhooks_sync(string $evento, ?int $user_id = null, array $extra = []): bool {
    $pdo = getPDO();
    if (usuario_bloqueado_disparos($pdo, $user_id)) {
        return false;
    }

    // Monta dados básicos do usuário (se informado)
    $user = [];
    if ($user_id !== null) {
        $u = buscar_usuario_por_id($user_id);
        if ($u) {
            $user = [
                'id'       => $u['id'] ?? null,
                'nome'     => $u['nome'] ?? null,
                'email'    => $u['email'] ?? null,
                'telefone' => $u['telefone'] ?? null,
            ];
        }
    }

    // Usa o dispatcher central para enviar para todos os webhooks ativos
    disparar_evento_webhooks($pdo, $evento, $user, $extra);

    // Disparo opcional para SuperFuncionário (se houver regras ativas)
    $sfOk = sf_disparar_evento($pdo, $evento, $user, $extra);
    $mcOk = mc_disparar_evento($pdo, $evento, $user, $extra);
    return $sfOk || $mcOk;
}

function reagendamento_live_ensure_logs(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reagendamentos_live_process_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reagendamento_id INT NULL,
            user_id INT NULL,
            etapa VARCHAR(80) NOT NULL,
            status VARCHAR(30) NOT NULL,
            mensagem TEXT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rlpl_reag (reagendamento_id),
            KEY idx_rlpl_user (user_id),
            KEY idx_rlpl_etapa (etapa),
            KEY idx_rlpl_status (status),
            KEY idx_rlpl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function reagendamento_live_log(PDO $pdo, ?int $reagendamentoId, ?int $userId, string $etapa, string $status, string $mensagem = '', array $context = []): void {
    try {
        if (!$pdo->inTransaction()) {
            reagendamento_live_ensure_logs($pdo);
        }
        $st = $pdo->prepare("
            INSERT INTO reagendamentos_live_process_logs
                (reagendamento_id, user_id, etapa, status, mensagem, context_json, created_at)
            VALUES (:rid, :uid, :etapa, :status, :mensagem, :context_json, NOW())
        ");
        $st->execute([
            ':rid' => $reagendamentoId,
            ':uid' => $userId,
            ':etapa' => $etapa,
            ':status' => $status,
            ':mensagem' => $mensagem,
            ':context_json' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        @error_log('reagendamento_live_log: ' . $e->getMessage());
    }
}

/**
 * Log genérico de sistema.
 */
function log_sistema(string $nivel, string $origem, string $mensagem, array $contexto = [], ?string $stack = null): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        INSERT INTO system_logs (nivel, origem, mensagem, stack_trace, contexto_json, created_at)
        VALUES (:n, :o, :m, :s, :c, NOW())
    ");
    $stmt->execute([
        ':n' => $nivel,
        ':o' => $origem,
        ':m' => $mensagem,
        ':s' => $stack,
        ':c' => json_encode($contexto, JSON_UNESCAPED_UNICODE),
    ]);
}

function get_setting(string $chave, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT chave, valor FROM settings");
            foreach ($stmt as $row) {
                $cache[$row['chave']] = $row['valor'];
            }
        } catch (Throwable $e) {
            // banco ainda não criado etc.
            return $default;
        }
    }
    return $cache[$chave] ?? $default;
}

function set_setting(string $chave, string $valor): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        INSERT INTO settings (chave, valor) VALUES (:c, :v)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)
    ");
    $stmt->execute([':c' => $chave, ':v' => $valor]);
}

/**
/**
 * Gera um código UID de 36 caracteres com letras minúsculas, números e traços.
 */
function gerar_codigo_uid(): string {
    $data = bin2hex(random_bytes(16)); // 32 chars hex
    return substr($data, 0, 8) . '-' .
           substr($data, 8, 4) . '-' .
           substr($data, 12, 4) . '-' .
           substr($data, 16, 4) . '-' .
           substr($data, 20);
}

function theme_inline_css_vars(): string {
    $bg_main   = get_setting('theme_bg_main', '#0b1120');
    $bg_card   = get_setting('theme_bg_card', '#020617');
    $primary   = get_setting('theme_primary', '#facc15');
    $secondary = get_setting('theme_secondary', '#38bdf8');
    $text      = get_setting('theme_text', '#f9fafb');
    $fontScale = (int) get_setting('theme_font_scale', '100');

    return ":root{
        --bg-main: {$bg_main};
        --bg-card: {$bg_card};
        --primary: {$primary};
        --secondary: {$secondary};
        --text-main: {$text};
        --font-scale: {$fontScale};
    }";
}

/**
 * Magic-link de auto-login
 * - Cria tabela magic_links se não existir
 * - Gera um token único de 64 chars (hex)
 * - Retorna a URL completa pronta para enviar ao aluno
 *
 * @param int $userId       ID do usuário
 * @param int $ttlDays      Dias de validade (padrão: 30)
 * @param bool $oneShot     Se true, token expira ao primeiro uso (padrão: false)
 */
function gerar_magic_link(int $userId, int $ttlDays = 30, bool $oneShot = false): string {
    if ($userId <= 0) return '';
    try {
        $pdo = getPDO();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS magic_links (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                token       VARCHAR(64) NOT NULL,
                expires_at  DATETIME NOT NULL,
                one_shot    TINYINT(1) NOT NULL DEFAULT 0,
                used_at     DATETIME NULL,
                created_at  DATETIME NOT NULL DEFAULT NOW(),
                UNIQUE KEY uk_ml_token (token),
                INDEX idx_ml_user (user_id)
            )
        ");
        $token = bin2hex(random_bytes(32));
        $exp   = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * $ttlDays);
        $pdo->prepare("INSERT INTO magic_links (user_id, token, expires_at, one_shot) VALUES (:uid, :tok, :exp, :os)")
            ->execute([
                ':uid' => $userId,
                ':tok' => $token,
                ':exp' => $exp,
                ':os'  => $oneShot ? 1 : 0,
            ]);
        return rtrim(BASE_URL, '/') . '/login.php?am=' . $token;
    } catch (Throwable $e) {
        return '';
    }
}
