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
}

function am_set_token(PDO $pdo, int $userId): void {
    am_token_table($pdo);
    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :uid")->execute([':uid' => $userId]);
    $token = bin2hex(random_bytes(32));
    $exp   = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * AM_TOKEN_DAYS);
    $pdo->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (:uid, :tok, :exp)")
        ->execute([':uid' => $userId, ':tok' => $token, ':exp' => $exp]);
    setcookie('am_token', $token, [
        'expires'  => time() + 60 * 60 * 24 * AM_TOKEN_DAYS,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
            try {
                $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
                    ->execute(['id' => (int)$tokRow['user_id']]);
            } catch (Throwable $e) {}
            header('Location: trilha.php');
            exit;
        } else {
            setcookie('am_token', '', time() - 3600, '/');
        }
    } catch (Throwable $e) { /* não impede o fluxo normal */ }
}

if (!empty($_SESSION['aluno_id'])) {
    header('Location: trilha.php');
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
$helpUrl      = trim($loginHelpUrl !== '' ? $loginHelpUrl : $whatsHelpUrl);
$mailtoHelp   = 'mailto:suporte@professoremersonleite.com?subject=' . rawurlencode('Não consigo acessar a área de membros');

$mensagemErro = '';
$cookieEmail     = $_COOKIE['am_email'] ?? '';
$emailForm       = $cookieEmail;
$lembrarMarcado  = $cookieEmail !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email   = trim((string)($_POST['email']  ?? ''));
    $senha   = trim((string)($_POST['senha']  ?? ''));
    $lembrar = !empty($_POST['lembrar_email']);

    $emailForm = $email;

    if ($email === '' || $senha === '') {
        $mensagemErro = 'Informe seu e-mail e senha para acessar.';
    } else {
        $st = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $st->execute(['email' => $email]);
        $user = $st->fetch();

        if (!$user || empty($user['senha_hash']) || !password_verify($senha, $user['senha_hash'])) {
            $mensagemErro = 'E-mail ou senha inválidos. Confira os dados e tente novamente.';
        } else {
            $_SESSION['aluno_id'] = (int)$user['id'];

            try {
                $stUp = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
                $stUp->execute(['id' => $user['id']]);
            } catch (Throwable $e) { /* não é crítico */ }

            // Salva token de auto-login (renova a cada login)
            try {
                am_set_token($pdo, (int)$user['id']);
            } catch (Throwable $e) { /* não crítico */ }

            // Cookie de e-mail para pré-preenchimento
            setcookie('am_email', $email, [
                'expires'  => time() + 60 * 60 * 24 * AM_TOKEN_DAYS,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);

            header('Location: trilha.php');
            exit;
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
        <div class="card-sub">Use o e-mail e a senha que recebeu ao se cadastrar.</div>

        <?php if ($mensagemErro): ?>
            <div class="error-box"><?= h($mensagemErro) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label class="form-label" for="email">E-mail</label>
                <input type="email" id="email" name="email"
                       value="<?= h($emailForm) ?>"
                       placeholder="seuemail@exemplo.com" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label" for="senha">Senha</label>
                <input type="password" id="senha" name="senha"
                       placeholder="Digite sua senha" required>
            </div>

            <div class="hint-box">
                Dica: sua senha é seu <strong>telefone com DDD</strong>, só números. Ex: <strong>31985278215</strong>
            </div>

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
</body>
</html>
