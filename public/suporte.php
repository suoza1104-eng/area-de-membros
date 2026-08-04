<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/support_chat.php';

$pdo = getPDO();
support_chat_ensure_schema($pdo);

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

if (!empty($_GET['whatsapp'])) {
    support_chat_log_event($pdo, 'support_entry', 0, 0, 'visitor', '', 'Visitante', 'whatsapp', [
        'source' => 'public_suporte_bridge',
        'target_url' => $fallback,
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
    ]);
    header('Location: ' . $fallback);
    exit;
}

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

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$externalAttempt = !empty($_GET['external']);
$fromInAppBrowser = (bool)preg_match('~WhatsApp|FBAN|FBAV|Instagram|Line/|wv\)~i', $ua);
if (!$externalAttempt && $fromInAppBrowser) {
    $externalUrl = rtrim(BASE_URL, '/') . '/suporte.php?external=1';
    $whatsappUrl = rtrim(BASE_URL, '/') . '/suporte.php?whatsapp=1';
    $parts = parse_url($externalUrl);
    $intentPath = (string)($parts['host'] ?? '') . (string)($parts['path'] ?? '');
    if (!empty($parts['query'])) {
        $intentPath .= '?' . $parts['query'];
    }
    $intentUrl = 'intent://' . $intentPath . '#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=' . rawurlencode($externalUrl) . ';end';
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abrindo suporte</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#080e1a;color:#fff;font-family:Arial,sans-serif}
        main{width:min(92vw,430px);padding:26px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:#101827;text-align:center}
        h1{font-size:22px;margin:0 0 10px}p{color:#cbd5e1;line-height:1.5;margin:0 0 18px}
        a{display:block;margin-top:10px;padding:13px 16px;border-radius:10px;text-decoration:none;font-weight:800}
        .primary{background:#25d366;color:#052e16}.secondary{background:#1f2937;color:#fff}
    </style>
</head>
<body>
<main>
    <h1>Abrindo suporte...</h1>
    <p>Vamos tentar abrir sua área de membros fora do navegador do WhatsApp. Se não abrir, use o atendimento pelo WhatsApp.</p>
    <a class="primary" id="openApp" href="<?=htmlspecialchars($externalUrl, ENT_QUOTES, 'UTF-8')?>">Abrir minha área de membros</a>
    <a class="secondary" href="<?=htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8')?>">Continuar pelo WhatsApp</a>
</main>
<script>
(function(){
    var external = <?=json_encode($externalUrl, JSON_UNESCAPED_SLASHES)?>;
    var intent = <?=json_encode($intentUrl, JSON_UNESCAPED_SLASHES)?>;
    var isAndroid = /Android/i.test(navigator.userAgent);
    var openUrl = isAndroid ? intent : external;
    var button = document.getElementById('openApp');
    if (button) button.href = openUrl;
    setTimeout(function(){ location.href = isAndroid ? intent : external; }, 350);
})();
</script>
</body>
</html>
<?php
    exit;
}

support_chat_log_event($pdo, 'support_entry', 0, 0, 'visitor', '', 'Visitante', 'whatsapp', [
    'source' => 'public_suporte',
    'target_url' => $fallback,
    'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
]);

header('Location: ' . $fallback);
exit;
