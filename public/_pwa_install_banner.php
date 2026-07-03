<style>
.pwa-promo{display:none;position:relative;overflow:hidden;margin:0 0 18px;padding:18px;border:1px solid rgba(250,204,21,.34);border-radius:18px;background:radial-gradient(circle at 92% 8%,rgba(250,204,21,.2),transparent 34%),linear-gradient(135deg,#121827 0%,#0b1220 65%,#151407 100%);box-shadow:0 16px 40px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.04)}
.pwa-promo:before{content:'';position:absolute;width:150px;height:150px;border-radius:50%;right:-75px;bottom:-95px;background:rgba(250,204,21,.12);filter:blur(2px)}.pwa-promo-inner{position:relative;display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:15px}.pwa-promo-icon{width:58px;height:58px;border-radius:16px;box-shadow:0 8px 22px rgba(0,0,0,.3)}.pwa-promo-copy strong{display:block;color:#fff;font-size:17px;line-height:1.25;margin-bottom:4px}.pwa-promo-copy p{margin:0;color:#aeb9cc;font-size:12px;line-height:1.45}.pwa-promo-benefits{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.pwa-promo-benefits span{display:inline-flex;align-items:center;gap:4px;padding:4px 7px;border-radius:999px;background:rgba(255,255,255,.06);color:#d6deeb;font-size:9px;font-weight:700}.pwa-promo-benefits span:before{content:'✓';color:#facc15}.pwa-promo-action{min-width:170px;border:0;border-radius:11px;padding:12px 15px;background:linear-gradient(135deg,#fde047,#eab308);color:#171301;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 22px rgba(234,179,8,.2);text-align:center;text-decoration:none}.pwa-promo-action:hover{filter:brightness(1.05);text-decoration:none}.pwa-promo-action:disabled{opacity:.58;cursor:wait}.pwa-promo-close{position:absolute;top:7px;right:8px;width:26px;height:26px;border:0;border-radius:50%;background:rgba(255,255,255,.06);color:#94a3b8;font-size:17px;cursor:pointer}.pwa-promo-status{display:none;position:relative;margin-top:11px;padding-top:10px;border-top:1px solid rgba(255,255,255,.07);color:#93c5fd;font-size:11px}.pwa-promo-status.ok{color:#86efac}.pwa-promo-status.err{color:#fca5a5}@media(max-width:720px){.pwa-promo{padding:16px}.pwa-promo-inner{grid-template-columns:auto 1fr}.pwa-promo-icon{width:50px;height:50px}.pwa-promo-action{grid-column:1/-1;width:100%;min-width:0}.pwa-promo-copy strong{font-size:15px}.pwa-promo-close{top:5px;right:5px}}
</style>
<aside class="pwa-promo" id="pwaPromo" aria-label="Instalar aplicativo da área de membros">
    <button class="pwa-promo-close" id="pwaPromoClose" type="button" aria-label="Fechar">×</button>
    <div class="pwa-promo-inner">
        <img class="pwa-promo-icon" src="pwa-icon.svg" alt="">
        <div class="pwa-promo-copy">
            <strong id="pwaPromoTitle">Leve suas aulas com você</strong>
            <p id="pwaPromoText">Instale o aplicativo para acessar mais rápido e receber os avisos importantes.</p>
            <div class="pwa-promo-benefits"><span>Acesso rápido</span><span>Notificações</span><span>Sem Play Store</span></div>
        </div>
        <button class="pwa-promo-action" id="pwaPromoAction" type="button">Instalar aplicativo</button>
    </div>
    <div class="pwa-promo-status" id="pwaPromoStatus"></div>
</aside>
<script>
(function(){
    const promo=document.getElementById('pwaPromo');
    const action=document.getElementById('pwaPromoAction');
    const title=document.getElementById('pwaPromoTitle');
    const text=document.getElementById('pwaPromoText');
    const status=document.getElementById('pwaPromoStatus');
    const ua=navigator.userAgent||'';
    const android=/Android/i.test(ua);
    const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
    const chrome=/Chrome\//i.test(ua)&&!/(?:wv\)|; wv|Version\/4\.0|EdgA|OPR|Opera|SamsungBrowser|FBAN|FBAV|Instagram|WhatsApp)/i.test(ua);
    const pushReady=('Notification'in window)&&Notification.permission==='granted'&&!!localStorage.getItem('push_fcm_token');
    const dismissedUntil=Number(localStorage.getItem('pwa_promo_dismissed_until')||0);
    let deferredPrompt=null;

    function show(){if(Date.now()<dismissedUntil)return;promo.style.display='block'}
    function message(value,type){status.textContent=value;status.className='pwa-promo-status '+(type||'');status.style.display='block'}
    document.getElementById('pwaPromoClose').addEventListener('click',function(){localStorage.setItem('pwa_promo_dismissed_until',String(Date.now()+3*86400000));promo.style.display='none'});
    function activationMode(){
        title.textContent='Ative os avisos no seu celular';
        text.textContent='Receba lembretes de aulas, liberações e comunicados importantes.';
        action.textContent='Ativar notificações';
        action.disabled=false;
        action.onclick=async function(){
            action.disabled=true;action.textContent='Ativando...';
            try{
                if(typeof window.areaMembrosEnablePush!=='function')throw new Error('Serviço de notificações indisponível. Atualize a página.');
                await window.areaMembrosEnablePush();
                action.textContent='Notificações ativadas';message('Pronto! Este telefone já pode receber os avisos.','ok');
                setTimeout(function(){promo.style.display='none'},1800);
            }catch(error){action.disabled=false;action.textContent='Tentar novamente';message(error&&error.message?error.message:String(error),'err')}
        };
        show();
    }

    if(standalone){if(!pushReady)activationMode();return}
    if(!android)return;
    if(localStorage.getItem('pwa_install_confirmed')==='1')return;
    if(!chrome){
        title.textContent='Continue no Google Chrome';
        text.textContent='Abra esta mesma página no Chrome para instalar o aplicativo com segurança.';
        action.textContent='Abrir no Google Chrome';
        action.onclick=function(){
            const fallback=window.location.href;
            const path=window.location.host+window.location.pathname+window.location.search;
            window.location.href='intent://'+path+'#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url='+encodeURIComponent(fallback)+';end';
        };
        show();return;
    }

    show();action.disabled=true;action.textContent='Preparando instalação...';
    window.addEventListener('beforeinstallprompt',function(event){event.preventDefault();deferredPrompt=event;action.disabled=false;action.textContent='Instalar aplicativo'});
    action.onclick=async function(){
        if(!deferredPrompt){message('O Chrome ainda está preparando a instalação. Atualize a página e tente novamente.','err');return}
        action.disabled=true;deferredPrompt.prompt();
        const choice=await deferredPrompt.userChoice;deferredPrompt=null;
        if(choice.outcome==='accepted'){localStorage.setItem('pwa_install_confirmed','1');action.textContent='Aplicativo instalado';message('Abra o novo ícone na tela inicial para concluir.','ok')}
        else{action.disabled=false;action.textContent='Instalar aplicativo'}
    };
    window.addEventListener('appinstalled',function(){localStorage.setItem('pwa_install_confirmed','1');action.disabled=true;action.textContent='Aplicativo instalado';message('Abra o novo ícone na tela inicial para concluir.','ok')});
})();
</script>
