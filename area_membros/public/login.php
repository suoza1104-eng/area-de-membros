<?php
// FILE: public/login.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

$pdo = getPDO();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Se já estiver logado como aluno, manda pra trilha
if (!empty($_SESSION['aluno_id'])) {
    header('Location: trilha.php');
    exit;
}

// Carrega configurações visuais básicas
$stCfg = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
$appCfg = $stCfg->fetch() ?: [
    'course_title'    => 'Trilha de Aulas',
    'primary_color'   => '#facc15',
    'secondary_color' => '#22c55e',
    'background_color'=> '#020617',
    'logo_url'        => '',
];

$courseTitle = $appCfg['course_title'] ?? 'Trilha de Aulas';
$primary     = $appCfg['primary_color'] ?? '#facc15';
$bgColor     = $appCfg['background_color'] ?? '#020617';
$logoUrl     = $appCfg['logo_url'] ?? '';

$loginHelpUrl = (string)get_setting('login_help_url', '');
$whatsHelpUrl = (string)get_setting('whatsapp_help_url', '');
$helpUrl      = $loginHelpUrl !== '' ? $loginHelpUrl : $whatsHelpUrl;
$helpUrl      = trim($helpUrl);
$mailtoHelp   = 'mailto:suporte@professoremersonleite.com?subject=' . rawurlencode('Não consigo acessar a área de membros');

$mensagemErro = '';

// Lê cookie de e-mail salvo (se existir)
$cookieEmail = $_COOKIE['am_email'] ?? '';
$emailForm   = $cookieEmail;
$lembrarMarcado = $cookieEmail !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email  = trim((string)($_POST['email'] ?? ''));
    $senha  = trim((string)($_POST['senha'] ?? ''));
    $lembrar = !empty($_POST['lembrar_email']);

    $emailForm = $email; // mantém preenchido no POST

    if ($email === '' || $senha === '') {
        $mensagemErro = 'Informe seu e-mail e senha para acessar.';
    } else {
        $st = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $st->execute(['email' => $email]);
        $user = $st->fetch();

        if (!$user || empty($user['senha_hash']) || !password_verify($senha, $user['senha_hash'])) {
            $mensagemErro = 'E-mail ou senha inválidos. Confira os dados e tente novamente.';
        } else {
            // Login OK
            $_SESSION['aluno_id'] = (int)$user['id'];

            // Atualiza last_login
            try {
                $stUp = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
                $stUp->execute(['id' => $user['id']]);
            } catch (Throwable $e) {
                // não é crítico
            }

            // Lembrar só o e-mail em cookie (60 dias)
            if ($lembrar) {
                setcookie('am_email', $email, [
                    'expires'  => time() + 60*60*24*60,
                    'path'     => '/',
                    'secure'   => isset($_SERVER['HTTPS']),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            } else {
                setcookie('am_email', '', time() - 3600, '/');
            }

            header('Location: trilha.php');
            exit;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Login - <?= h($courseTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg: <?= h($bgColor) ?>;
            --card:#020617;
            --border:#1f2937;
            --primary: <?= h($primary) ?>;
            --text:#e5e7eb;
            --muted:#9ca3af;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            font-family:Arial, sans-serif;
            background:radial-gradient(circle at top, rgba(250,204,21,.12), transparent 60%), var(--bg);
            color:var(--text);
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:16px 12px;
        }
        a{text-decoration:none;color:inherit;}

        .shell{
            width:100%;
            max-width:960px;
            display:grid;
            grid-template-columns: minmax(0,1.2fr) minmax(0,1fr);
            gap:28px;
            align-items:center;
        }
        @media (max-width:820px){
            .shell{
                grid-template-columns:1fr;
                gap:16px;
            }
            /* No celular, logo + nome do curso em cima e a caixa de login abaixo */
            .side-info{
                order:1;
                text-align:center;
            }
            .card{
                order:2;
            }
        }

        .side-info{
            padding:8px 4px;
        }
        .logo-circle{
            width:52px;
            height:52px;
            border-radius:999px;
            border:1px solid rgba(148,163,184,.3);
            background:#020617;
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            color:var(--primary);
            font-size:22px;
            margin-bottom:10px;
        }
        .logo-circle img{
            max-width:100%;
            max-height:100%;
            object-fit:contain;
        }
        .big-title{
            font-size:22px;
            font-weight:bold;
            margin-bottom:8px;
        }
        .big-sub{
            font-size:13px;
            color:var(--muted);
            max-width:380px;
        }

        .card{
            background:rgba(15,23,42,.98);
            border-radius:18px;
            border:1px solid rgba(31,41,55,.9);
            padding:20px 16px 18px;
            box-shadow:0 22px 60px rgba(0,0,0,.9);
        }
        .card-title{
            font-size:16px;
            font-weight:bold;
            margin-bottom:4px;
        }
        .card-sub{
            font-size:12px;
            color:var(--muted);
            margin-bottom:12px;
        }
        .field{
            margin-bottom:10px;
        }
        label{
            display:block;
            font-size:12px;
            color:var(--muted);
            margin-bottom:4px;
        }
        input[type="email"],
        input[type="password"]{
            width:100%;
            padding:8px 10px;
            border-radius:10px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text);
            font-size:13px;
        }
        input::placeholder{
            color:#6b7280;
        }
        .remember-line{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-top:4px;
            margin-bottom:6px;
        }
        .remember-left{
            display:flex;
            align-items:center;
            gap:6px;
            font-size:11px;
            color:var(--muted);
        }
        .remember-left input[type="checkbox"]{
            width:14px;
            height:14px;
        }
        .forgot-link{
            font-size:11px;
            color:#fca5a5;
            cursor:pointer;
        }
        .forgot-link:hover{
            text-decoration:underline;
        }
        .btn-primary{
            width:100%;
            margin-top:4px;
            padding:9px 16px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:13px;
            cursor:pointer;
        }
        .btn-primary:hover{filter:brightness(1.05);}

        .msg-erro{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(239,68,68,.16);
            border:1px solid #ef4444;
            color:#fecaca;
            font-size:12px;
        }

        .help-block{
            margin-top:10px;
            font-size:11px;
            color:var(--muted);
            text-align:center;
        }
        .help-block a{
            color:#facc15;
            font-weight:bold;
        }
    
    .password-hint{
        margin-top:8px;
        font-size:12px;
        color: rgba(226,232,240,.78);
        line-height:1.25;
    }
    .password-hint b{ color: #e5e7eb; }
</style>
</head>
<body>
<div class="shell">

    <div class="side-info">
        <div class="logo-circle">
            <?php if ($logoUrl): ?>
                <img src="<?= h($logoUrl) ?>" alt="Logo">
            <?php else: ?>
                EL
            <?php endif; ?>
        </div>
        <div class="big-title"><?= h($courseTitle) ?></div>
        <div class="big-sub">
            Acesse a sua área de aulas com o e-mail cadastrado e a senha enviada pela equipe.
            Se tiver qualquer dificuldade, use o botão <strong>"Não consigo acessar"</strong>.
        </div>
    </div>

    <div class="card">
        <div class="card-title">Entrar na área de membros</div>
        <div class="card-sub">Digite seus dados de acesso para continuar.</div>

        <?php if ($mensagemErro): ?>
            <div class="msg-erro"><?= h($mensagemErro) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field">
                <label for="email">E-mail</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= h($emailForm) ?>"
                    placeholder="seuemail@exemplo.com"
                    required
                >
            </div>
            <div class="field">
                <label for="senha">Senha</label>
                <input
                    type="password"
                    id="senha"
                    name="senha"
                    placeholder="Digite sua senha"
                    required
                >
            </div>
            <div class="password-hint">Dica: sua senha é seu <b>telefone com DDD</b>, só números (sem espaços). Ex: <b>31985278215</b>.</div>

            <div class="remember-line">
                <label class="remember-left">
                    <input
                        type="checkbox"
                        name="lembrar_email"
                        value="1"
                        <?= $lembrarMarcado ? 'checked' : '' ?>
                    >
                    <span>Manter meus dados salvos neste dispositivo</span>
                </label>

                <?php
                    $finalHelpUrl = $helpUrl !== '' ? $helpUrl : $mailtoHelp;
                ?>
                <a class="forgot-link" href="<?= h($finalHelpUrl) ?>" target="_blank" rel="noopener">
                    Não consigo acessar
                </a>
            </div>

            <button type="submit" class="btn-primary">Entrar</button>
        </form>

        <div class="help-block">
            Caso não tenha recebido os dados de acesso ou esteja com dificuldades,
            clique em <a href="mailto:suporte@professoremersonleite.com?subject=N%C3%A3o%20consigo%20acessar%20a%20%C3%A1rea%20de%20membros" target="_blank">Não consigo acessar</a>
            para falar com a equipe.
        </div>
    </div>

</div>
</body>
</html>
