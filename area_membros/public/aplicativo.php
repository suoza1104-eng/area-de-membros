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
    <script>
    window.__areaMembrosInstallPrompt=null;
    window.addEventListener('beforeinstallprompt',function(event){
        event.preventDefault();
        window.__areaMembrosInstallPrompt=event;
        window.dispatchEvent(new Event('area-install-ready'));
    });
    </script>
    <style>
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#030814;color:#f8fafc;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}body{min-height:100dvh;display:grid;place-items:center;padding:28px;background:radial-gradient(circle at 80% 10%,rgba(250,204,21,.1),transparent 30%),#030814}.install-shell{position:relative;width:min(1100px,100%);min-height:min(700px,calc(100dvh - 56px));display:grid;grid-template-columns:44% 56%;overflow:hidden;border:1px solid rgba(250,204,21,.55);border-radius:30px;background:linear-gradient(145deg,#0c1424,#050b16 70%);box-shadow:0 30px 100px rgba(0,0,0,.7)}.install-visual{min-height:700px;background:#060b15}.install-visual img{width:100%;height:100%;display:block;object-fit:cover;object-position:center}.install-content{position:relative;display:flex;flex-direction:column;justify-content:center;padding:58px 54px}.eyebrow{display:inline-flex;align-self:flex-start;padding:7px 12px;border:1px solid rgba(250,204,21,.35);border-radius:999px;background:rgba(250,204,21,.08);color:#fde047;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase}.install-content h1{max-width:570px;margin:20px 0 13px;font-size:clamp(34px,4vw,52px);line-height:1.02;letter-spacing:-.04em}.lead{max-width:590px;margin:0;color:#aeb9cc;font-size:17px;line-height:1.55}.benefits{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;margin:30px 0}.benefits span{display:flex;align-items:center;gap:10px;color:#e2e8f0;font-size:14px;font-weight:750}.benefits span:before{content:"✓";color:#facc15;font-size:21px;font-weight:950}.install-action{width:100%;border:0;border-radius:16px;padding:19px 22px;background:linear-gradient(135deg,#fde047,#eab308);color:#171301;font-size:18px;font-weight:950;cursor:pointer;box-shadow:0 8px 30px rgba(250,204,21,.18)}.install-action:hover{filter:brightness(1.05)}.install-action:disabled{cursor:wait;opacity:.7}.install-status{display:none;margin-top:14px;padding:12px 14px;border:1px solid rgba(96,165,250,.25);border-radius:12px;background:rgba(59,130,246,.08);color:#bfdbfe;font-size:13px;line-height:1.5}.install-status.show{display:block}.install-status.ok{border-color:rgba(74,222,128,.25);background:rgba(34,197,94,.09);color:#86efac}.install-status.err{border-color:rgba(248,113,113,.28);background:rgba(239,68,68,.09);color:#fca5a5}.ios-help{display:none;margin-top:16px;padding:15px;border:1px solid rgba(250,204,21,.25);border-radius:13px;background:rgba(250,204,21,.06);color:#e5e7eb;font-size:13px;line-height:1.6}.ios-help strong{color:#fde047}.fine-print{margin:14px 0 0;color:#64748b;font-size:11px;text-align:center}
        @media(max-width:760px){body{display:block;padding:0}.install-shell{width:100%;min-height:100dvh;border:0;border-radius:0;grid-template-columns:1fr;grid-template-rows:minmax(245px,38dvh) 1fr}.install-visual{min-height:0}.install-visual img{object-fit:contain}.install-content{justify-content:flex-start;padding:30px 22px calc(28px + env(safe-area-inset-bottom))}.eyebrow{font-size:10px;padding:6px 9px}.install-content h1{font-size:31px;margin:14px 0 9px}.lead{font-size:14px}.benefits{gap:10px 15px;margin:20px 0}.benefits span{font-size:12px}.benefits span:before{font-size:17px}.install-action{padding:16px;font-size:16px}}
        @media(max-width:390px){.benefits{grid-template-columns:1fr}.install-shell{grid-template-rows:minmax(210px,32dvh) 1fr}}
        .ios-help{padding:18px}.ios-help-title{margin:0 0 5px;color:#fff;font-size:18px}.ios-help-intro{margin:0 0 15px;color:#cbd5e1}.ios-safari-warning{display:none;margin:0 0 14px;padding:12px;border-radius:10px;background:rgba(239,68,68,.1);color:#fecaca}.ios-steps{display:grid;gap:12px}.ios-step{display:grid;grid-template-columns:34px 86px 1fr;gap:11px;align-items:center;padding:11px;border-radius:12px;background:#080e1a;border:1px solid rgba(255,255,255,.09)}.ios-step-number{display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:#facc15;color:#171301;font-weight:950}.ios-step-visual{height:68px;display:grid;place-items:center;border-radius:10px;background:linear-gradient(145deg,#edf2f7,#cbd5e1);color:#0f172a;overflow:hidden}.ios-step-text{color:#cbd5e1;line-height:1.45}.ios-step-text strong{display:block;margin-bottom:3px;color:#fff}.ios-toolbar{width:70px;padding:8px 7px;border-radius:8px;background:#fff;box-shadow:0 5px 14px rgba(0,0,0,.2)}.ios-address{height:7px;margin-bottom:10px;border-radius:5px;background:#dbe3ed}.ios-toolbar-icons{display:flex;justify-content:space-between;align-items:center;color:#1677ff}.ios-menu{width:76px;padding:7px;border-radius:8px;background:#fff;box-shadow:0 5px 14px rgba(0,0,0,.2);font-size:7px;font-weight:800}.ios-menu-row{display:flex;align-items:center;gap:5px;padding:5px 2px;border-top:1px solid #e2e8f0}.ios-plus{display:grid;place-items:center;width:17px;height:17px;border:1.5px solid #1677ff;border-radius:4px;color:#1677ff;font-size:14px}.ios-home{display:grid;grid-template-columns:repeat(3,17px);gap:7px}.ios-app-icon{width:19px;height:19px;border-radius:5px;background:#94a3b8}.ios-app-icon.main{display:grid;place-items:center;background:#0f172a;color:#facc15;font-size:11px;border:1px solid #facc15}@media(max-width:480px){.ios-step{grid-template-columns:31px 72px 1fr;padding:9px;gap:8px}.ios-step-visual{height:62px}.ios-step-text{font-size:12px}}
    </style>
</head>
<body>
<main class="install-shell">
    <div class="install-visual"><img src="<?= htmlspecialchars($image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Aplicativo da área de membros no celular"></div>
    <section class="install-content">
        <span class="eyebrow">Aplicativo da área de membros</span>
        <h1>Assista às aulas com mais qualidade</h1>
        <p class="lead">Instale o aplicativo para ter reprodução mais estável, acesso rápido e avisos importantes no celular.</p>
        <div class="benefits">
            <span>Acesso em um toque</span><span>Avisos importantes</span>
            <span>Experiência de aplicativo</span><span>Instalação gratuita</span>
        </div>
        <button class="install-action" id="installAction" type="button">Instalar aplicativo agora</button>
        <div class="install-status" id="installStatus" role="status" aria-live="polite"></div>
        <div class="ios-help" id="iosHelp">
            <h2 class="ios-help-title">Como instalar no iPhone</h2>
            <p class="ios-help-intro">Não é necessário procurar na App Store. Siga estas três etapas no Safari:</p>
            <div class="ios-safari-warning" id="iosSafariWarning"><strong>Primeiro, abra esta página no Safari.</strong><br>No WhatsApp ou Instagram, toque em <strong>⋯</strong> no alto da tela e depois em <strong>Abrir no Safari</strong>.</div>
            <div class="ios-steps">
                <div class="ios-step">
                    <span class="ios-step-number">1</span>
                    <div class="ios-step-visual">
                        <div class="ios-toolbar"><div class="ios-address"></div><div class="ios-toolbar-icons"><span>‹</span><svg width="22" height="25" viewBox="0 0 24 27" fill="none" aria-hidden="true"><path d="M12 17V2M12 2L7 7M12 2l5 5M5 11H3.8A1.8 1.8 0 002 12.8v10.4A1.8 1.8 0 003.8 25h16.4a1.8 1.8 0 001.8-1.8V12.8a1.8 1.8 0 00-1.8-1.8H19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>□</span></div></div>
                    </div>
                    <div class="ios-step-text"><strong>Toque em Compartilhar</strong>Procure o símbolo de um <b>quadrado com uma seta para cima</b>. Ele fica na barra inferior ou superior do Safari.</div>
                </div>
                <div class="ios-step">
                    <span class="ios-step-number">2</span>
                    <div class="ios-step-visual"><div class="ios-menu"><div>Opções</div><div class="ios-menu-row"><span class="ios-plus">+</span><span>Adicionar à Tela de Início</span></div></div></div>
                    <div class="ios-step-text"><strong>Escolha “Adicionar à Tela de Início”</strong>Na lista que abrir, deslize para baixo até encontrar essa opção.</div>
                </div>
                <div class="ios-step">
                    <span class="ios-step-number">3</span>
                    <div class="ios-step-visual"><div class="ios-home"><span class="ios-app-icon"></span><span class="ios-app-icon main">▣</span><span class="ios-app-icon"></span><span class="ios-app-icon"></span><span class="ios-app-icon"></span><span class="ios-app-icon"></span></div></div>
                    <div class="ios-step-text"><strong>Toque em “Adicionar”</strong>Confirme no canto superior direito. O aplicativo aparecerá na tela inicial do iPhone.</div>
                </div>
            </div>
        </div>
        <p class="fine-print">Instalação segura diretamente pela sua área de membros.</p>
    </section>
</main>
<?php include __DIR__ . '/_pwa_runtime.php'; ?>
<script>
(function(){
    const action=document.getElementById('installAction');
    const status=document.getElementById('installStatus');
    const iosHelp=document.getElementById('iosHelp');
    const iosSafariWarning=document.getElementById('iosSafariWarning');
    const ua=navigator.userAgent||'';
    const android=/Android/i.test(ua);
    const apple=/iPhone|iPad|iPod/i.test(ua);
    const chrome=/Chrome\//i.test(ua)&&!/(?:wv\)|; wv|Version\/4\.0|EdgA|OPR|Opera|SamsungBrowser|FBAN|FBAV|Instagram|WhatsApp)/i.test(ua);
    const safari=/Safari/i.test(ua)&&!/CriOS|FxiOS|EdgiOS/i.test(ua);
    const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
    let installPrompt=window.__areaMembrosInstallPrompt;

    function message(text,type){status.textContent=text;status.className='install-status show '+(type||'')}
    function installed(){action.disabled=true;action.textContent='Aplicativo já instalado';message('O aplicativo já está instalado neste aparelho.','ok')}
    function installerReady(){installPrompt=window.__areaMembrosInstallPrompt;action.disabled=false;action.textContent='Instalar aplicativo agora'}
    function openChrome(){
        const fallback=window.location.href;
        const path=window.location.host+window.location.pathname+window.location.search;
        window.location.href='intent://'+path+'#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url='+encodeURIComponent(fallback)+';end';
    }
    function showIos(){iosHelp.style.display='block';action.disabled=false;action.textContent='Ver passo a passo para instalar';action.onclick=function(){iosHelp.scrollIntoView({behavior:'smooth',block:'start'})}}

    // A desinstalacao da PWA nao apaga o localStorage do site. Por isso,
    // somente o modo standalone real pode confirmar que o app esta aberto.
    if(standalone){installed();return}
    if(apple){
        if(!safari){iosSafariWarning.style.display='block';message('Este link não está aberto no Safari. Veja abaixo como continuar.','err')}
        showIos();return;
    }
    if(android&&!chrome){action.textContent='Abrir no Google Chrome';action.onclick=openChrome;message('Para instalar com segurança, continue no Google Chrome.');return}

    action.disabled=true;
    action.textContent='Preparando instalação...';
    if(installPrompt)installerReady();
    window.addEventListener('area-install-ready',installerReady);
    action.onclick=async function(){
        if(!installPrompt)return;
        action.disabled=true;installPrompt.prompt();
        const choice=await installPrompt.userChoice;installPrompt=null;
        if(choice.outcome==='accepted'){action.textContent='Instalação confirmada';message('Pronto. O aplicativo está sendo adicionado à sua tela inicial.','ok')}
        else{action.disabled=false;action.textContent='Instalar aplicativo agora';message('A instalação foi cancelada. Você pode tentar novamente.')}
    };
    window.addEventListener('appinstalled',installed);
})();
</script>
</body>
</html>
