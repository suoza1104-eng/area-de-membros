<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/push_notifications.php';
proteger_aluno();

$appName = trim((string)(get_setting('push_app_name', 'Área de Membros') ?? 'Área de Membros')) ?: 'Área de Membros';
$image = trim((string)(get_setting('push_popup_image_url', 'pwa-install-phone.jpg') ?? 'pwa-install-phone.jpg')) ?: 'pwa-install-phone.jpg';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#080e1a">
    <title>Instalar <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <?php include __DIR__ . '/_pwa_head.php'; ?>
    <style>
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#030814;color:#f8fafc;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}body{min-height:100dvh;display:grid;place-items:center;padding:28px;background:radial-gradient(circle at 80% 10%,rgba(250,204,21,.1),transparent 30%),#030814}.install-shell{position:relative;width:min(1100px,100%);min-height:min(700px,calc(100dvh - 56px));display:grid;grid-template-columns:44% 56%;overflow:hidden;border:1px solid rgba(250,204,21,.55);border-radius:30px;background:linear-gradient(145deg,#0c1424,#050b16 70%);box-shadow:0 30px 100px rgba(0,0,0,.7)}.install-shell:before{content:"";position:absolute;z-index:5;top:0;left:0;width:84%;height:5px;background:linear-gradient(90deg,#facc15,#eab308)}.install-visual{min-height:700px;background:#060b15}.install-visual img{width:100%;height:100%;display:block;object-fit:cover;object-position:center}.install-content{position:relative;display:flex;flex-direction:column;justify-content:center;padding:90px 54px 58px}.classes-link{position:absolute;top:18px;right:18px;display:inline-flex;align-items:center;gap:8px;padding:13px 20px;border-radius:999px;background:#facc15;color:#181401;text-decoration:none;font-size:14px;font-weight:900;box-shadow:0 8px 24px rgba(250,204,21,.25)}.eyebrow{display:inline-flex;align-self:flex-start;padding:7px 12px;border:1px solid rgba(250,204,21,.35);border-radius:999px;background:rgba(250,204,21,.08);color:#fde047;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase}.install-content h1{max-width:570px;margin:20px 0 13px;font-size:clamp(34px,4vw,52px);line-height:1.02;letter-spacing:-.04em}.lead{max-width:590px;margin:0;color:#aeb9cc;font-size:17px;line-height:1.55}.benefits{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;margin:30px 0}.benefits span{display:flex;align-items:center;gap:10px;color:#e2e8f0;font-size:14px;font-weight:750}.benefits span:before{content:"✓";color:#facc15;font-size:21px;font-weight:950}.install-action{width:100%;border:0;border-radius:16px;padding:19px 22px;background:linear-gradient(135deg,#fde047,#eab308);color:#171301;font-size:18px;font-weight:950;cursor:pointer;box-shadow:0 8px 30px rgba(250,204,21,.18)}.install-action:hover{filter:brightness(1.05)}.install-action:disabled{cursor:wait;opacity:.7}.install-status{display:none;margin-top:14px;padding:12px 14px;border:1px solid rgba(96,165,250,.25);border-radius:12px;background:rgba(59,130,246,.08);color:#bfdbfe;font-size:13px;line-height:1.5}.install-status.show{display:block}.install-status.ok{border-color:rgba(74,222,128,.25);background:rgba(34,197,94,.09);color:#86efac}.install-status.err{border-color:rgba(248,113,113,.28);background:rgba(239,68,68,.09);color:#fca5a5}.ios-help{display:none;margin-top:16px;padding:15px;border:1px solid rgba(250,204,21,.25);border-radius:13px;background:rgba(250,204,21,.06);color:#e5e7eb;font-size:13px;line-height:1.6}.ios-help strong{color:#fde047}.fine-print{margin:14px 0 0;color:#64748b;font-size:11px;text-align:center}
        @media(max-width:760px){body{display:block;padding:0}.install-shell{width:100%;min-height:100dvh;border:0;border-radius:0;grid-template-columns:1fr;grid-template-rows:minmax(245px,38dvh) 1fr}.install-shell:before{width:78%;height:4px}.install-visual{min-height:0}.install-visual img{object-fit:contain}.install-content{justify-content:flex-start;padding:30px 22px calc(28px + env(safe-area-inset-bottom))}.classes-link{top:12px;right:12px;padding:10px 14px;font-size:12px}.eyebrow{font-size:10px;padding:6px 9px}.install-content h1{font-size:31px;margin:14px 0 9px}.lead{font-size:14px}.benefits{gap:10px 15px;margin:20px 0}.benefits span{font-size:12px}.benefits span:before{font-size:17px}.install-action{padding:16px;font-size:16px}}
        @media(max-width:390px){.benefits{grid-template-columns:1fr}.install-shell{grid-template-rows:minmax(210px,32dvh) 1fr}}
    </style>
</head>
<body>
<main class="install-shell">
    <div class="install-visual"><img src="<?= htmlspecialchars($image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Aplicativo da área de membros no celular"></div>
    <section class="install-content">
        <a class="classes-link" href="trilha.php">×&nbsp; Ir para as aulas</a>
        <span class="eyebrow">Aplicativo da área de membros</span>
        <h1>Assista às aulas com mais qualidade</h1>
        <p class="lead">Instale o aplicativo para ter reprodução mais estável, acesso rápido e avisos importantes no celular.</p>
        <div class="benefits">
            <span>Acesso em um toque</span><span>Avisos importantes</span>
            <span>Experiência de aplicativo</span><span>Instalação gratuita</span>
        </div>
        <button class="install-action" id="installAction" type="button">Instalar aplicativo agora</button>
        <div class="install-status" id="installStatus" role="status" aria-live="polite"></div>
        <div class="ios-help" id="iosHelp"><strong>Instalação no iPhone:</strong> toque no botão Compartilhar do Safari e escolha <strong>Adicionar à Tela de Início</strong>.</div>
        <p class="fine-print">Instalação segura diretamente pela sua área de membros.</p>
    </section>
</main>
<?php include __DIR__ . '/_pwa_runtime.php'; ?>
<script>
(function(){
    const action=document.getElementById('installAction');
    const status=document.getElementById('installStatus');
    const iosHelp=document.getElementById('iosHelp');
    const ua=navigator.userAgent||'';
    const android=/Android/i.test(ua);
    const apple=/iPhone|iPad|iPod/i.test(ua);
    const chrome=/Chrome\//i.test(ua)&&!/(?:wv\)|; wv|Version\/4\.0|EdgA|OPR|Opera|SamsungBrowser|FBAN|FBAV|Instagram|WhatsApp)/i.test(ua);
    const safari=/Safari/i.test(ua)&&!/CriOS|FxiOS|EdgiOS/i.test(ua);
    const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
    let installPrompt=null;

    function message(text,type){status.textContent=text;status.className='install-status show '+(type||'')}
    function installed(){action.disabled=false;action.textContent='Abrir minhas aulas';action.onclick=function(){window.location.href='trilha.php'};message('O aplicativo já está instalado neste aparelho.','ok')}
    function openChrome(){
        const fallback=window.location.href;
        const path=window.location.host+window.location.pathname+window.location.search;
        window.location.href='intent://'+path+'#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url='+encodeURIComponent(fallback)+';end';
    }
    function showIos(){iosHelp.style.display='block';action.disabled=false;action.textContent='Ver como instalar no iPhone';action.onclick=function(){iosHelp.scrollIntoView({behavior:'smooth',block:'center'})}}

    if(standalone){installed();return}
    if(apple){
        if(!safari)message('Abra este link no Safari para instalar o aplicativo.','err');
        showIos();return;
    }
    if(android&&!chrome){action.textContent='Abrir no Google Chrome';action.onclick=openChrome;message('Para instalar com segurança, continue no Google Chrome.');return}

    action.disabled=true;
    action.textContent='Preparando instalação...';
    window.addEventListener('beforeinstallprompt',function(event){
        event.preventDefault();installPrompt=event;action.disabled=false;action.textContent='Instalar aplicativo agora';
    });
    action.onclick=async function(){
        if(!installPrompt){message('No menu do navegador, escolha “Instalar app” ou “Adicionar à tela inicial”.','err');action.disabled=false;return}
        action.disabled=true;installPrompt.prompt();
        const choice=await installPrompt.userChoice;installPrompt=null;
        if(choice.outcome==='accepted'){action.textContent='Instalação confirmada';message('Pronto. O aplicativo está sendo adicionado à sua tela inicial.','ok')}
        else{action.disabled=false;action.textContent='Instalar aplicativo agora';message('A instalação foi cancelada. Você pode tentar novamente.')}
    };
    window.addEventListener('appinstalled',function(){localStorage.setItem('pwa_install_confirmed','1');installed()});
    window.setTimeout(function(){
        if(!installPrompt&&action.disabled){action.disabled=false;action.textContent='Instalar aplicativo agora'}
    },2500);
})();
</script>
</body>
</html>
