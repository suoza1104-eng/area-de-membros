<?php
// FILE: public/login.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

define('AM_TOKEN_DAYS', 400); // máximo suportado pelos browsers modernos

// Garante a coluna last_login_at — usada pelo KPI "Logaram" do dashboard
try { $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL"); } catch (Throwable $e) {}

// Helper: destino após login (?next=path) — só aceita paths relativos seguros
function am_resolve_next(): string {
    $n = (string)($_GET['next'] ?? $_POST['next'] ?? '');
    if ($n === '') return 'trilha.php';
    // Bloqueia: URLs absolutas, // protocol-relative, .. (subir diretórios)
    if (strpos($n, '://') !== false) return 'trilha.php';
    if (strpos($n, '//') === 0)      return 'trilha.php';
    if (strpos($n, '..') !== false)  return 'trilha.php';
    // Retorna como path absoluto (com / inicial) — Location: relativa quebra
    return '/' . ltrim($n, '/');
}

// Log simples para diagnosticar login (gravado em /tmp ou logs PHP)
function login_dbg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    $f = __DIR__ . '/../uploads/login_debug.log';
    try { @file_put_contents($f, $line, FILE_APPEND | LOCK_EX); } catch (Throwable $e) {}
}

function am_login_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?: '';
}

function am_normalize_login_email(string $email): string {
    $email = trim($email);
    $email = (string)preg_replace('/\s+/', '', $email);
    return strtolower($email);
}

function am_login_password_candidates(string $senha, string $telefone): array {
    $raw = trim($senha);
    $digits = am_login_digits($raw);
    $phoneDigits = am_login_digits($telefone);
    $candidates = [];
    foreach ([$raw, $digits] as $value) {
        if ($value !== '') $candidates[$value] = true;
    }
    if ($digits !== '') {
        if ((strlen($digits) === 10 || strlen($digits) === 11) && !str_starts_with($digits, '55')) {
            $candidates['55' . $digits] = true;
        }
        if (str_starts_with($digits, '55') && (strlen($digits) === 12 || strlen($digits) === 13)) {
            $candidates[substr($digits, 2)] = true;
        }
    }
    if ($phoneDigits !== '') {
        $phoneLocal = (str_starts_with($phoneDigits, '55') && (strlen($phoneDigits) === 12 || strlen($phoneDigits) === 13))
            ? substr($phoneDigits, 2)
            : $phoneDigits;
        if ($digits !== '' && ($digits === $phoneLocal || $digits === $phoneDigits)) {
            $candidates[$phoneDigits] = true;
            if ($phoneLocal !== '') $candidates[$phoneLocal] = true;
        }
    }
    return array_map('strval', array_keys($candidates));
}

function am_verify_login_password(string $senha, string $hash, string $telefone): bool {
    foreach (am_login_password_candidates($senha, $telefone) as $candidate) {
        if (password_verify($candidate, $hash)) return true;
    }
    return false;
}

// Endpoint para ler o log: /login.php?dbg_log=1
if (!empty($_GET['dbg_log'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $f = __DIR__ . '/../uploads/login_debug.log';
    if (is_file($f)) {
        $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail  = array_slice($lines, -50);
        echo implode("\n", $tail);
    } else {
        echo '(log file ainda não existe — clique em um magic link primeiro)';
    }
    exit;
}

// Endpoint de diagnóstico: /login.php?dbg_login=email@dominio.com
if (!empty($_GET['dbg_login'])) {
    header('Content-Type: application/json; charset=utf-8');
    $email = (string)$_GET['dbg_login'];
    $info  = ['email' => $email];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetchAll(PDO::FETCH_ASSOC);
        $info['column_last_login_at_existe'] = !empty($cols);
    } catch (Throwable $e) { $info['column_err'] = $e->getMessage(); }
    try {
        $st = $pdo->prepare("SELECT id, nome, email, last_login_at, created_at FROM users WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $info['user'] = $row ?: null;
        if ($row) {
            $tgs = $pdo->prepare("SELECT t.nome FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = :u");
            $tgs->execute([':u' => (int)$row['id']]);
            $info['tags'] = array_column($tgs->fetchAll(PDO::FETCH_ASSOC), 'nome');
            $ml = $pdo->prepare("SELECT id, token, expires_at, used_at, one_shot, created_at FROM magic_links WHERE user_id = :u ORDER BY id DESC LIMIT 5");
            $ml->execute([':u' => (int)$row['id']]);
            $info['magic_links_recentes'] = $ml->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { $info['err'] = $e->getMessage(); }
    $info['debug_log_path'] = realpath(__DIR__ . '/../uploads/login_debug.log') ?: '(arquivo não existe ainda)';
    echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function am_ensure_login_events_schema(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip VARCHAR(64) NULL,
                user_agent VARCHAR(250) NULL,
                method VARCHAR(30) NOT NULL DEFAULT 'unknown',
                success TINYINT(1) NOT NULL DEFAULT 1,
                failure_reason VARCHAR(80) NULL,
                email VARCHAR(190) NULL,
                INDEX idx_login_events_user (user_id),
                INDEX idx_login_events_logged (logged_at),
                INDEX idx_login_events_method_success (method, success, logged_at),
                INDEX idx_login_events_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        foreach ([
            "ALTER TABLE login_events MODIFY COLUMN user_id INT NULL",
            "ALTER TABLE login_events ADD COLUMN method VARCHAR(30) NOT NULL DEFAULT 'unknown'",
            "ALTER TABLE login_events ADD COLUMN success TINYINT(1) NOT NULL DEFAULT 1",
            "ALTER TABLE login_events ADD COLUMN failure_reason VARCHAR(80) NULL",
            "ALTER TABLE login_events ADD COLUMN email VARCHAR(190) NULL",
            "ALTER TABLE login_events ADD INDEX idx_login_events_method_success (method, success, logged_at)",
            "ALTER TABLE login_events ADD INDEX idx_login_events_email (email)",
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        login_dbg('login_events schema fail: ' . $e->getMessage());
    }
}

function am_log_login_attempt(PDO $pdo, ?int $userId, string $email, string $method, bool $success, string $failureReason = ''): void {
    try {
        am_ensure_login_events_schema($pdo);
        $pdo->prepare("
            INSERT INTO login_events (user_id, logged_at, ip, user_agent, method, success, failure_reason, email)
            VALUES (:uid, NOW(), :ip, :ua, :method, :success, :reason, :email)
        ")->execute([
            ':uid' => ($userId && $userId > 0) ? $userId : null,
            ':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64) ?: null,
            ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250) ?: null,
            ':method' => substr($method, 0, 30),
            ':success' => $success ? 1 : 0,
            ':reason' => $failureReason !== '' ? substr($failureReason, 0, 80) : null,
            ':email' => $email !== '' ? substr(strtolower($email), 0, 190) : null,
        ]);
    } catch (Throwable $e) {
        login_dbg('login_events insert fail: ' . $e->getMessage());
    }
}

// Helper centralizado: registra evento de login (qualquer caminho)
// Em primeiro login: marca tag PRIMEIRO_LOGIN + dispara webhook
function am_touch_login(PDO $pdo, int $userId, string $method = 'password', string $email = ''): void {
    if ($userId <= 0) { login_dbg('touch_login uid=0, skip'); return; }
    try {
        am_log_login_attempt($pdo, $userId, $email, $method, true);

        $st = $pdo->prepare("SELECT last_login_at FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch();
        $primeiroLogin = $row && empty($row['last_login_at']);
        login_dbg('touch_login uid=' . $userId . ' primeiro=' . ($primeiroLogin ? 'sim' : 'nao') . ' last_atual=' . ($row['last_login_at'] ?? 'null'));

        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
            ->execute([':id' => $userId]);
        login_dbg('touch_login UPDATE feito uid=' . $userId);

        if ($primeiroLogin) {
            try {
                if (function_exists('adicionar_tag')) {
                    $ok = adicionar_tag($userId, 'PRIMEIRO_LOGIN', 'login', null);
                    login_dbg('add_tag PRIMEIRO_LOGIN uid=' . $userId . ' result=' . ($ok ? 'ok' : 'falhou'));
                } else {
                    login_dbg('adicionar_tag NAO existe');
                }
            } catch (Throwable $e) { login_dbg('add_tag erro: ' . $e->getMessage()); }
            try {
                if (function_exists('disparar_webhooks')) {
                    disparar_webhooks('PRIMEIRO_LOGIN', $userId, []);
                    login_dbg('webhook PRIMEIRO_LOGIN disparado uid=' . $userId);
                }
            } catch (Throwable $e) { login_dbg('webhook erro: ' . $e->getMessage()); }
        }
    } catch (Throwable $e) { login_dbg('touch_login ERRO: ' . $e->getMessage()); }
}

function am_token_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remember_tokens (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            token      VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT NOW(),
            UNIQUE KEY uk_token (token),
            INDEX idx_rt_user (user_id)
        )
    ");
    // Migração defensiva — caso tabela existente esteja com schema antigo
    foreach ([
        "ALTER TABLE remember_tokens ADD COLUMN user_id INT NOT NULL",
        "ALTER TABLE remember_tokens ADD COLUMN token VARCHAR(64) NOT NULL",
        "ALTER TABLE remember_tokens ADD COLUMN expires_at DATETIME NOT NULL",
        "ALTER TABLE remember_tokens ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE remember_tokens ADD UNIQUE KEY uk_token (token)",
        "ALTER TABLE remember_tokens ADD INDEX idx_rt_user (user_id)",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (Throwable $e) { /* já existe */ }
    }
}

function am_set_token(PDO $pdo, int $userId): void {
    am_token_table($pdo);
    $existingToken = strtolower(trim((string)($_COOKIE['am_token'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/', $existingToken)) {
        $pdo->prepare("DELETE FROM remember_tokens WHERE token = :tok")->execute([':tok' => $existingToken]);
    }
    try { $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()"); } catch (Throwable $e) {}
    $token = bin2hex(random_bytes(32));
    $exp   = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * AM_TOKEN_DAYS);
    $pdo->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (:uid, :tok, :exp)")
        ->execute([':uid' => $userId, ':tok' => $token, ':exp' => $exp]);
    setcookie('am_token', '', am_host_cookie_options(time() - 3600));
    setcookie('am_token', $token, am_cookie_options(time() + 60 * 60 * 24 * AM_TOKEN_DAYS));
}

// ── Magic-link via URL (?am=<token>) ─────────────────────────────────────────
// Aceita link direto do tipo /login.php?am=<token> — loga e redireciona.
// Processa sempre que ?am= vier na URL, mesmo se já tiver sessão ativa,
// para garantir que o login fique registrado (last_login_at).
if (!empty($_GET['am'])) {
    $tok = preg_replace('/[^a-f0-9]/i', '', (string)$_GET['am']);
    login_dbg('magic link recebido, token_len=' . strlen($tok));
    if (strlen($tok) === 64) {
        try {
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
            $stML = $pdo->prepare("
                SELECT id, user_id, one_shot, used_at, expires_at
                FROM magic_links
                WHERE token = :t AND expires_at > NOW()
                LIMIT 1
            ");
            $stML->execute([':t' => $tok]);
            $ml = $stML->fetch();
            login_dbg('magic link lookup: ' . ($ml ? 'achou uid=' . $ml['user_id'] : 'nao achou'));
            if ($ml && !((int)$ml['one_shot'] === 1 && !empty($ml['used_at']))) {
                $uid = (int)$ml['user_id'];
                $_SESSION['aluno_id'] = $uid;
                // Cada passo isolado para que uma falha não bloqueie os outros
                try { $pdo->prepare("UPDATE magic_links SET used_at = NOW() WHERE id = :id")->execute([':id' => (int)$ml['id']]); }
                catch (Throwable $e) { login_dbg('used_at fail: ' . $e->getMessage()); }
                // touch_login PRIMEIRO — garante last_login_at + tag mesmo se set_token falhar
                try { am_touch_login($pdo, $uid, 'magic_link'); }
                catch (Throwable $e) { login_dbg('touch_login fail: ' . $e->getMessage()); }
                try { am_set_token($pdo, $uid); }
                catch (Throwable $e) { login_dbg('set_token fail: ' . $e->getMessage()); }
                login_dbg('magic link OK, redirect uid=' . $uid);
                header('Location: ' . am_resolve_next());
                exit;
            }
        } catch (Throwable $e) {
            login_dbg('magic link ERRO: ' . $e->getMessage());
        }
    }
}

// Auto-login via token salvo no cookie
if (empty($_SESSION['aluno_id']) && !empty($_COOKIE['am_token'])) {
    try {
        am_token_table($pdo);
        // limpeza ocasional de tokens expirados (1% das requisições)
        if (rand(1, 100) === 1) {
            $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
        }
        $stTok = $pdo->prepare("
            SELECT user_id FROM remember_tokens
            WHERE token = :tok AND expires_at > NOW()
            LIMIT 1
        ");
        $stTok->execute([':tok' => $_COOKIE['am_token']]);
        $tokRow = $stTok->fetch();
        if ($tokRow) {
            $_SESSION['aluno_id'] = (int)$tokRow['user_id'];
            am_set_token($pdo, (int)$tokRow['user_id']); // renova
            am_touch_login($pdo, (int)$tokRow['user_id'], 'remember_token');
            header('Location: ' . am_resolve_next());
            exit;
        } else {
            setcookie('am_token', '', am_host_cookie_options(time() - 3600));
            setcookie('am_token', '', am_cookie_options(time() - 3600));
        }
    } catch (Throwable $e) { /* não impede o fluxo normal */ }
}

if (!empty($_SESSION['aluno_id'])) {
    header('Location: ' . am_resolve_next());
    exit;
}

$stCfg  = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
$appCfg = $stCfg->fetch() ?: [];

$courseTitle = $appCfg['course_title']     ?? 'Trilha de Aulas';
$primary     = $appCfg['primary_color']    ?? '#facc15';
$bgColor     = $appCfg['background_color'] ?? '#07101f';
$logoUrl     = $appCfg['logo_url']         ?? '';

$loginHelpUrl = (string)get_setting('login_help_url', '');
$whatsHelpUrl = (string)get_setting('whatsapp_help_url', '');
$helpUrl      = trim($whatsHelpUrl !== '' ? $whatsHelpUrl : $loginHelpUrl);
$mailtoHelp   = 'mailto:suporte@professoremersonleite.com?subject=' . rawurlencode('Não consigo acessar a área de membros');

$loginIgnorePassword = (int)(get_setting('login_ignore_password', '0') ?: '0') === 1;
$mensagemErro = '';
$cookieEmail     = $_COOKIE['am_email'] ?? '';
$emailForm       = $cookieEmail;
$lembrarMarcado  = $cookieEmail !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email   = am_normalize_login_email((string)($_POST['email']  ?? ''));
    $senha   = trim((string)($_POST['senha']  ?? ''));
    $lembrar = !empty($_POST['lembrar_email']);

    $emailForm = $email;

    if ($email === '' || (!$loginIgnorePassword && $senha === '')) {
        $mensagemErro = $loginIgnorePassword ? 'Informe seu e-mail para acessar.' : 'Informe seu e-mail e senha para acessar.';
    } else {
        try {
        $st = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $st->execute(['email' => $email]);
        $user = $st->fetch();

            $blocked = $user && (int)($user['bloquear'] ?? 0) === 1;
            $passwordOk = $user
                && !$blocked
                && (
                    $loginIgnorePassword
                    || (!empty($user['senha_hash']) && am_verify_login_password($senha, (string)$user['senha_hash'], (string)($user['telefone'] ?? '')))
                );

        if (!$passwordOk) {
            $failureReason = $blocked ? 'blocked_user' : ($user ? 'invalid_password' : 'user_not_found');
            am_log_login_attempt($pdo, $user ? (int)$user['id'] : null, $email, $loginIgnorePassword ? 'password_bypass' : 'password', false, $failureReason);
            $mensagemErro = $blocked ? 'Seu acesso está bloqueado. Fale com a equipe de suporte.' : 'E-mail ou senha inválidos. Confira os dados e tente novamente.';
            if ($loginIgnorePassword && $failureReason === 'user_not_found') {
                $mensagemErro = 'Não encontrei esse e-mail cadastrado. A senha não bloqueia o acesso; confira se digitou o mesmo e-mail da inscrição ou fale com o suporte.';
            }
        } else {
            $_SESSION['aluno_id'] = (int)$user['id'];

            am_touch_login($pdo, (int)$user['id'], $loginIgnorePassword ? 'password_bypass' : 'password', $email);

            // Salva token de auto-login (renova a cada login)
            try {
                am_set_token($pdo, (int)$user['id']);
            } catch (Throwable $e) { /* não crítico */ }

            // Cookie de e-mail para pré-preenchimento
            setcookie('am_email', '', am_host_cookie_options(time() - 3600, false));
            setcookie('am_email', $email, am_cookie_options(time() + 60 * 60 * 24 * AM_TOKEN_DAYS, false));

            header('Location: ' . am_resolve_next());
            exit;
        }
        } catch (Throwable $e) {
            login_dbg('password login ERRO: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            $mensagemErro = 'Nao foi possivel concluir o login agora. Tente novamente em instantes.';
        }
    }
}

$finalHelpUrl = $helpUrl !== '' ? $helpUrl : $mailtoHelp;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Acesso — <?= h($courseTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:      <?= h($bgColor) ?>;
            --primary: <?= h($primary) ?>;
            --card:    #0d1b33;
            --border:  #1a2540;
            --text:    #e2e8f0;
            --muted:   #64748b;
            --dim:     #475569;
            --danger:  #ef4444;
            --r:       10px;
            --font:    'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { height: 100%; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
            background-image: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(250,204,21,.08), transparent);
        }
        a { color: var(--primary); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ===== SHELL ===== */
        .shell {
            width: 100%;
            max-width: 900px;
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 32px;
            align-items: center;
        }
        @media (max-width: 780px) {
            .shell { grid-template-columns: 1fr; gap: 24px; }
            .side-info { text-align: center; }
            .side-info .bullet-list { text-align: left; max-width: 340px; margin: 0 auto; }
        }

        /* ===== LEFT SIDE ===== */
        .side-info { padding: 8px 0; }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        @media (max-width: 780px) { .brand { justify-content: center; } }
        .brand-logo {
            width: 46px; height: 46px;
            border-radius: 12px;
            background: rgba(250,204,21,.1);
            border: 1px solid rgba(250,204,21,.2);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            color: var(--primary);
        }
        .brand-logo img { width: 100%; height: 100%; object-fit: contain; }
        .brand-logo svg { width: 22px; height: 22px; }
        .brand-name { font-size: 15px; font-weight: 700; color: var(--text); }
        .brand-tag  { font-size: 11px; color: var(--muted); margin-top: 1px; }

        .side-headline {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 12px;
            letter-spacing: -.02em;
        }
        .side-sub {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .bullet-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .bullet-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--dim);
        }
        .bullet-dot {
            width: 22px; height: 22px;
            border-radius: var(--r);
            background: rgba(250,204,21,.1);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            color: var(--primary);
        }
        .bullet-dot svg { width: 12px; height: 12px; }

        /* ===== CARD ===== */
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px 28px;
            box-shadow: 0 30px 80px rgba(0,0,0,.5);
        }
        .card-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .card-sub   { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

        .form-group { margin-bottom: 14px; }
        .form-label {
            display: block;
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .06em;
            color: var(--muted); margin-bottom: 5px;
        }
        input[type="email"], input[type="password"] {
            width: 100%; padding: 10px 13px;
            border-radius: var(--r);
            border: 1px solid var(--border);
            background: #07101f;
            color: var(--text);
            font-size: 14px; font-family: var(--font);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(250,204,21,.12);
        }
        input::placeholder { color: var(--dim); }

        .hint-box {
            background: rgba(56,189,248,.07);
            border: 1px solid rgba(56,189,248,.15);
            border-radius: var(--r);
            padding: 9px 12px;
            font-size: 12px;
            color: #7dd3fc;
            margin-bottom: 16px;
        }

        .actions-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 16px;
        }
        .remember-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--muted);
            cursor: pointer;
        }
        .remember-label input[type="checkbox"] {
            width: 14px; height: 14px;
            accent-color: var(--primary);
        }
        .help-link {
            font-size: 12px;
            color: #fca5a5;
        }
        .help-link:hover { text-decoration: underline; }

        .btn-submit {
            width: 100%;
            padding: 11px;
            border-radius: 999px;
            border: none;
            background: var(--primary);
            color: #111827;
            font-weight: 700; font-size: 14px;
            font-family: var(--font);
            cursor: pointer;
            transition: filter .15s;
        }
        .btn-submit:hover { filter: brightness(1.07); }
        .btn-submit:active { filter: brightness(.94); }

        .error-box {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5;
            border-radius: var(--r);
            padding: 10px 13px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .card-footer {
            margin-top: 18px;
            font-size: 12px;
            color: var(--muted);
            text-align: center;
            line-height: 1.6;
        }
    </style>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<div class="shell">

    <!-- LEFT: BRANDING -->
    <div class="side-info">
        <div class="brand">
            <div class="brand-logo">
                <?php if ($logoUrl): ?>
                    <img src="<?= h($logoUrl) ?>" alt="Logo">
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                    </svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="brand-name"><?= h($courseTitle) ?></div>
                <div class="brand-tag">Área de Membros</div>
            </div>
        </div>

        <div class="side-headline">Bem-vindo<br>de volta!</div>
        <div class="side-sub">Acesse sua área de aulas e continue de onde parou. Todo o conteúdo exclusivo em um só lugar.</div>

        <div class="bullet-list">
            <div class="bullet-item">
                <div class="bullet-dot">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                Aulas liberadas no seu ritmo
            </div>
            <div class="bullet-item">
                <div class="bullet-dot">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                Certificado de conclusão
            </div>
            <div class="bullet-item">
                <div class="bullet-dot">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                Acesso em qualquer dispositivo
            </div>
        </div>
    </div>

    <!-- RIGHT: FORM -->
    <div class="card">
        <div class="card-title">Entrar na área de membros</div>
        <div class="card-sub"><?= $loginIgnorePassword ? 'Use o e-mail cadastrado para acessar.' : 'Use o e-mail e a senha que recebeu ao se cadastrar.' ?></div>

        <?php if ($mensagemErro): ?>
            <div class="error-box"><?= h($mensagemErro) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php if (!empty($_GET['next'])): ?>
            <input type="hidden" name="next" value="<?= h((string)$_GET['next']) ?>">
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label" for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       value="<?= h($emailForm) ?>"
                       placeholder="seuemail@exemplo.com" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label" for="senha">Senha</label>
                <input type="password" id="senha" name="senha"
                       placeholder="<?= $loginIgnorePassword ? 'Senha dispensada' : 'Digite sua senha' ?>" <?= $loginIgnorePassword ? '' : 'required' ?>>
            </div>

            <?php if ($loginIgnorePassword): ?>
            <div class="hint-box">
                A senha esta temporariamente dispensada. Informe o e-mail cadastrado para entrar.
            </div>
            <?php else: ?>
            <div class="hint-box">
                Dica: sua senha é seu <strong>telefone com DDD</strong>, só números. Ex: <strong>11987654321</strong>. Se não entrar, tente com <strong>55</strong> antes do DDD.
            </div>
            <?php endif; ?>

            <div class="actions-row">
                <label class="remember-label">
                    <input type="checkbox" name="lembrar_email" value="1" <?= $lembrarMarcado ? 'checked' : '' ?>>
                    Lembrar meu e-mail
                </label>
                <a class="help-link" href="<?= h($finalHelpUrl) ?>" target="_blank" rel="noopener">
                    Não consigo acessar
                </a>
            </div>

            <button type="submit" class="btn-submit">Entrar</button>
        </form>

        <div class="card-footer">
            Dificuldades para acessar?
            <a href="<?= h($finalHelpUrl) ?>" target="_blank" rel="noopener">Fale com a equipe de suporte</a>
        </div>
    </div>

</div>
<?php include __DIR__ . '/_pwa_runtime.php'; ?>
</body>
</html>
