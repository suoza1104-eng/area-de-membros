<style>
body.pwa-modal-open{overflow:hidden}.pwa-promo{display:none;position:fixed;inset:0;z-index:9999;padding:18px;background:rgba(2,6,15,.88);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);align-items:center;justify-content:center}.pwa-promo-card{position:relative;width:min(920px,100%);max-height:calc(100vh - 36px);display:grid;grid-template-columns:minmax(300px,44%) minmax(0,1fr);overflow:hidden;border:1px solid rgba(250,204,21,.34);border-radius:26px;background:radial-gradient(circle at 92% 8%,rgba(250,204,21,.17),transparent 35%),linear-gradient(145deg,#101827,#080e1a 68%);box-shadow:0 28px 100px rgba(0,0,0,.72),0 0 60px rgba(250,204,21,.08)}.pwa-promo-visual{min-height:540px;background:#060b15}.pwa-promo-visual img{width:100%;height:100%;display:block;object-fit:contain;object-position:center}.pwa-promo-content{display:flex;flex-direction:column;justify-content:center;padding:54px 46px 42px}.pwa-promo-eyebrow{display:inline-flex;align-self:flex-start;padding:5px 9px;border:1px solid rgba(250,204,21,.25);border-radius:999px;background:rgba(250,204,21,.08);color:#fde047;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.pwa-promo-content h2{margin:15px 0 10px;color:#fff;font-size:34px;line-height:1.08;letter-spacing:-.035em}.pwa-promo-content>p{margin:0;color:#aeb9cc;font-size:14px;line-height:1.6}.pwa-promo-benefits{display:grid;gap:9px;margin:22px 0}.pwa-promo-benefits span{display:flex;align-items:center;gap:9px;color:#e2e8f0;font-size:12px;font-weight:650}.pwa-promo-benefits span:before{content:'✓';display:grid;place-items:center;width:20px;height:20px;border-radius:50%;background:rgba(250,204,21,.14);color:#facc15;font-size:11px;font-weight:900}.pwa-promo-action{width:100%;border:1px solid rgba(255,255,255,.32);border-radius:14px;padding:15px 18px;background:linear-gradient(135deg,#fde047,#eab308);color:#171301;font-size:16px;font-weight:950;cursor:pointer;text-align:center;box-shadow:0 0 0 0 rgba(250,204,21,.55);animation:pwaCtaPulse 1.8s infinite}.pwa-promo-action:hover{filter:brightness(1.08)}.pwa-promo-action:disabled{opacity:.62;cursor:wait;animation:none}.pwa-promo-close{position:absolute;top:14px;right:14px;z-index:2;width:38px;height:38px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.13);border-radius:50%;background:rgba(2,6,15,.72);color:#e2e8f0;font-size:24px;line-height:1;cursor:pointer;backdrop-filter:blur(5px)}.pwa-promo-status{display:none;margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(56,189,248,.08);color:#93c5fd;font-size:11px;line-height:1.45}.pwa-promo-status.ok{display:block;background:rgba(34,197,94,.1);color:#86efac}.pwa-promo-status.err{display:block;background:rgba(239,68,68,.1);color:#fca5a5}@keyframes pwaCtaPulse{0%{transform:scale(1);box-shadow:0 0 0 0 rgba(250,204,21,.55)}65%{transform:scale(1.018);box-shadow:0 0 0 13px rgba(250,204,21,0)}100%{transform:scale(1);box-shadow:0 0 0 0 rgba(250,204,21,0)}}@media(prefers-reduced-motion:reduce){.pwa-promo-action{animation:none}}@media(max-width:720px){.pwa-promo{padding:0;align-items:stretch}.pwa-promo-card{width:100%;max-height:none;min-height:100dvh;border:0;border-radius:0;grid-template-columns:1fr;grid-template-rows:minmax(250px,39dvh) 1fr;overflow-y:auto}.pwa-promo-visual{min-height:0}.pwa-promo-visual img{object-fit:contain;object-position:center}.pwa-promo-content{justify-content:flex-start;padding:25px 22px calc(25px + env(safe-area-inset-bottom))}.pwa-promo-content h2{font-size:27px;margin-top:12px}.pwa-promo-benefits{grid-template-columns:1fr 1fr;gap:8px;margin:17px 0}.pwa-promo-benefits span{font-size:11px}.pwa-promo-close{top:12px;right:12px}}
</style>
<div class="pwa-promo" id="pwaPromo" role="dialog" aria-modal="true" aria-labelledby="pwaPromoTitle">
    <div class="pwa-promo-card">
        <button class="pwa-promo-close" id="pwaPromoClose" type="button" aria-label="Fechar">×</button>
        <div class="pwa-promo-visual"><img src="pwa-install-phone.jpg" alt="Aplicativo da área de membros exibindo uma aula no celular"></div>
        <div class="pwa-promo-content">
            <span class="pwa-promo-eyebrow">Aplicativo da área de membros</span>
            <h2 id="pwaPromoTitle">Assista às aulas com mais qualidade</h2>
            <p id="pwaPromoText">Instale o aplicativo para ter uma reprodução mais estável, acesso mais rápido e uma experiência melhor no seu celular.</p>
            <div class="pwa-promo-benefits"><span>Acesso em um toque</span><span>Avisos importantes</span><span>Experiência de aplicativo</span><span>Instalação gratuita</span></div>
            <button class="pwa-promo-action" id="pwaPromoAction" type="button">Instalar aplicativo agora</button>
            <div class="pwa-promo-status" id="pwaPromoStatus"></div>
        </div>
    </div>
</div>
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
    let deferredPrompt=null;

    function show(){promo.style.display='flex';document.body.classList.add('pwa-modal-open')}
    function hide(){promo.style.display='none';document.body.classList.remove('pwa-modal-open')}
    function message(value,type){status.textContent=value;status.className='pwa-promo-status '+(type||'');status.style.display='block'}
    document.getElementById('pwaPromoClose').addEventListener('click',hide);

    function activationMode(){
        title.textContent='Não perca nenhum aviso importante';
        text.textContent='Ative as notificações para receber lembretes de aulas, liberações e comunicados no seu celular.';
        action.textContent='Ativar notificações agora';action.disabled=false;
        action.onclick=async function(){
            action.disabled=true;action.textContent='Ativando...';
            try{
                if(typeof window.areaMembrosEnablePush!=='function')throw new Error('Serviço indisponível. Atualize a página e tente novamente.');
                await window.areaMembrosEnablePush();
                action.textContent='Notificações ativadas';message('Pronto! Este telefone já pode receber os avisos.','ok');setTimeout(hide,1800);
            }catch(error){action.disabled=false;action.textContent='Tentar novamente';message(error&&error.message?error.message:String(error),'err')}
        };show();
    }

    if(standalone)return;
    if(!android)return;
    if(localStorage.getItem('pwa_install_confirmed')==='1')return;
    if(!chrome){
        title.textContent='Abra suas aulas no Google Chrome';
        text.textContent='Este aplicativo foi preparado para reproduzir suas aulas pelo Google Chrome, oferecendo mais estabilidade, velocidade e qualidade. Toque no botão abaixo para abrir no Chrome e instalar.';
        action.textContent='Abrir no Google Chrome';
        action.onclick=function(){const fallback=window.location.href;const path=window.location.host+window.location.pathname+window.location.search;window.location.href='intent://'+path+'#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url='+encodeURIComponent(fallback)+';end'};
        show();return;
    }

    show();action.disabled=true;action.textContent='Preparando instalação...';
    window.addEventListener('beforeinstallprompt',function(event){event.preventDefault();deferredPrompt=event;action.disabled=false;action.textContent='Instalar aplicativo agora'});
    action.onclick=async function(){
        if(!deferredPrompt){message('O Chrome ainda está preparando a instalação. Atualize a página e tente novamente.','err');return}
        action.disabled=true;deferredPrompt.prompt();const choice=await deferredPrompt.userChoice;deferredPrompt=null;
        if(choice.outcome==='accepted'){localStorage.setItem('pwa_install_confirmed','1');activationMode();message('Aplicativo instalado. Ative os avisos para concluir.','ok')}
        else{action.disabled=false;action.textContent='Instalar aplicativo agora'}
    };
    window.addEventListener('appinstalled',function(){localStorage.setItem('pwa_install_confirmed','1');activationMode();message('Aplicativo instalado. Ative os avisos para concluir.','ok')});
})();
</script>
