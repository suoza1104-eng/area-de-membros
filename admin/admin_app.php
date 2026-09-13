<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/admin_app_notifications.php';
proteger_admin();

$menu = 'dashboard';
$page_title = 'App Administrativo';
$pdo = getPDO();
admin_app_ensure_schema($pdo);
$firebaseConfig = push_public_config();
$vapidKey = push_vapid_key();
$pushReady = $firebaseConfig['apiKey'] !== '' && $firebaseConfig['projectId'] !== '' && $vapidKey !== '';
$adminAppVersion = rawurlencode((string)(defined('APP_VERSION') ? APP_VERSION : 'v1'));
$admin_extra_head = '<link rel="manifest" href="admin_manifest.php?v=' . $adminAppVersion . '">' . "\n"
    . '<meta name="theme-color" content="#facc15">' . "\n"
    . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
    . '<meta name="apple-mobile-web-app-title" content="Vendas Admin">' . "\n"
    . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n"
    . '<link rel="apple-touch-icon" href="../public/pwa-icon-192.png?v=' . $adminAppVersion . '">' . "\n"
    . '<link rel="icon" href="../public/pwa-icon.svg?v=' . $adminAppVersion . '">';

include __DIR__ . '/_header.php';
?>
<?php if ($pushReady): ?>
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js"></script>
<?php endif; ?>
<style>
.admapp{display:grid;gap:14px}.admapp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.admapp-title h1{margin:0;color:var(--text);font-size:24px}.admapp-title p{margin:5px 0 0;color:var(--muted);font-size:12px}.admapp-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}.admapp-install{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;background:linear-gradient(135deg,rgba(250,204,21,.12),rgba(56,189,248,.06));border-color:rgba(250,204,21,.28)}.admapp-kicker{display:inline-flex;padding:4px 8px;border:1px solid rgba(250,204,21,.25);border-radius:999px;color:var(--primary);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.admapp-install h2{margin:10px 0 6px;font-size:19px;color:var(--text)}.admapp-install p{margin:0;color:var(--muted);font-size:12px;line-height:1.55}.admapp-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.admapp-btn{height:38px;border:1px solid var(--border);border-radius:9px;padding:0 13px;background:var(--bg);color:var(--text);font-size:12px;font-weight:750;cursor:pointer}.admapp-btn.primary{border-color:rgba(250,204,21,.45);background:var(--primary);color:#1b1601}.admapp-btn:disabled{opacity:.55;cursor:not-allowed}.admapp-toggle{min-width:154px;text-align:left}.admapp-toggle.on{border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.14);color:#86efac}.admapp-toggle.off{border-color:rgba(148,163,184,.24);background:rgba(15,23,42,.72);color:#cbd5e1}.admapp-toggle::before{content:'';display:inline-block;width:8px;height:8px;border-radius:50%;background:#94a3b8;margin-right:7px}.admapp-toggle.on::before{background:#22c55e}.admapp-status{display:none;margin-top:10px;padding:9px 11px;border-radius:9px;font-size:11px}.admapp-status.ok{display:block;background:rgba(34,197,94,.1);color:#86efac}.admapp-status.err{display:block;background:rgba(239,68,68,.1);color:#fca5a5}.admapp-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);gap:14px}.admapp-events{display:grid;gap:9px}.admapp-event{display:grid;grid-template-columns:minmax(150px,1fr) 120px 120px minmax(130px,.8fr) auto;gap:8px;align-items:end;padding:12px;border:1px solid var(--border);border-radius:10px;background:rgba(15,23,42,.42)}.admapp-event strong{display:block;color:var(--text);font-size:13px}.admapp-event span{display:block;margin-top:3px;color:var(--muted);font-size:10px}.admapp-event label{display:block;margin-bottom:4px;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em}.admapp-event input,.admapp-event select{width:100%;height:34px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);padding:0 9px;font-size:11px}.admapp-switch{display:flex;align-items:center;gap:8px;color:var(--text);font-size:11px}.admapp-switch input{width:auto;height:auto;accent-color:var(--primary)}.admapp-device{padding:10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);margin-top:8px}.admapp-device strong{font-size:12px;color:var(--text)}.admapp-device p{margin:3px 0 0;color:var(--muted);font-size:10px}.admapp-note{color:var(--muted);font-size:11px;line-height:1.55}.admapp-list-title{font-size:14px;font-weight:800;color:var(--text);margin-bottom:8px}@media(max-width:980px){.admapp-grid,.admapp-install{grid-template-columns:1fr}.admapp-actions{justify-content:flex-start}.admapp-event{grid-template-columns:1fr 1fr}.admapp-event .admapp-event-main{grid-column:1/-1}}@media(max-width:560px){.admapp-event{grid-template-columns:1fr}}
</style>

<div class="admapp">
  <div class="admapp-head">
    <div class="admapp-title">
      <h1>App administrativo</h1>
      <p>Instale o painel no celular ou computador e receba alertas de venda e eventos críticos.</p>
    </div>
  </div>

  <section class="admapp-card admapp-install">
    <div>
      <span class="admapp-kicker" id="admInstallBadge">Instalador</span>
      <h2>Gestão de Vendas no seu dispositivo</h2>
      <p>Use o botão de instalar do navegador e depois habilite as notificações. O app fica separado da área do aluno e abre direto no painel administrativo.</p>
      <div class="admapp-status" id="admAppStatus"></div>
    </div>
    <div class="admapp-actions">
      <button class="admapp-btn primary" id="admInstallBtn" type="button">Instalar app</button>
      <button class="admapp-btn admapp-toggle off" id="admEnablePushBtn" type="button">Notificações desligadas</button>
      <button class="admapp-btn" id="admTestPushBtn" type="button">Testar push</button>
      <button class="admapp-btn" id="admTestSoundBtn" type="button">Testar som</button>
    </div>
  </section>

  <div class="admapp-grid">
    <section class="admapp-card">
      <div class="admapp-list-title">Notificações por evento</div>
      <div class="admapp-events" id="admEvents"></div>
    </section>
    <aside class="admapp-card">
      <div class="admapp-list-title">Dispositivos conectados</div>
      <div id="admDevices"><p class="admapp-note">Carregando...</p></div>
      <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">
      <p class="admapp-note">Som personalizado é tocado quando o app está aberto ou em foco. Em segundo plano, Android/Windows/macOS usam o som padrão do sistema; iPhone tem regras mais restritas.</p>
      <?php if (!$pushReady): ?>
        <p class="admapp-status err" style="display:block">Firebase Push ainda não está configurado.</p>
      <?php endif; ?>
    </aside>
  </div>
</div>

<script>
(function(){
const PUSH_READY = <?= $pushReady ? 'true' : 'false' ?>;
const FIREBASE_CONFIG = <?= json_encode($firebaseConfig, JSON_UNESCAPED_SLASHES) ?>;
const VAPID_KEY = <?= json_encode($vapidKey) ?>;
const statusEl=document.getElementById('admAppStatus');
const installBadge=document.getElementById('admInstallBadge');
const installBtn=document.getElementById('admInstallBtn');
const pushBtn=document.getElementById('admEnablePushBtn');
const testPushBtn=document.getElementById('admTestPushBtn');
const testBtn=document.getElementById('admTestSoundBtn');
let deferredPrompt=null;
let swRegistration=null;

function msg(text,type){statusEl.textContent=text;statusEl.className='admapp-status '+(type||'ok');}
function pushDisabled(){return localStorage.getItem('admin_push_disabled')==='1';}
function pushEnabled(){return !pushDisabled()&&localStorage.getItem('admin_push_token')&&('Notification'in window)&&Notification.permission==='granted';}
function refreshPushToggle(){
  const on=pushEnabled();
  pushBtn.classList.toggle('on',!!on);
  pushBtn.classList.toggle('off',!on);
  pushBtn.textContent=on?'Notificações ligadas':'Notificações desligadas';
  pushBtn.title=on?'Clique para desligar notificações neste dispositivo':'Clique para ligar notificações neste dispositivo';
}
function isIOS(){return /iPhone|iPad|iPod/i.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);}
function isStandalone(){return window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;}
function installHelp(){
  if(isIOS())return 'No iPhone, abra no Safari, toque em Compartilhar e depois em Adicionar à Tela de Início. Depois abra pelo ícone criado e toque em Ativar notificações.';
  return 'Use o menu do navegador e escolha Instalar app ou Adicionar à tela inicial. Depois abra pelo ícone criado e ative as notificações.';
}
function notificationHelp(){
  if(isIOS()&&!isStandalone())return 'No iPhone, o Chrome/Safari em aba normal não libera notificações de PWA. Primeiro instale pela Tela de Início, de preferência pelo Safari, abra pelo ícone criado e então ative as notificações.';
  if(isIOS())return 'Este navegador no iPhone ainda não expôs notificações para este app. Tente instalar/abrir pelo ícone criado via Safari e confirme que o iOS está atualizado.';
  return 'Este navegador não expôs a API de notificações. Abra no Chrome/Edge atualizado ou instale o app e tente novamente.';
}
function refreshInstallState(){
  if(isStandalone()){
    installBadge.textContent='App instalado';
    installBtn.textContent='App instalado';
    installBtn.disabled=true;
    return;
  }
  installBadge.textContent='Instalador';
}
function clientId(){
  let id=localStorage.getItem('admin_push_client_id');
  if(!id){id=(crypto&&crypto.randomUUID)?crypto.randomUUID():(Date.now().toString(36)+'-'+Math.random().toString(36).slice(2));localStorage.setItem('admin_push_client_id',id);}
  return id;
}
function platform(){return /Android/i.test(navigator.userAgent)?'android':(/iPhone|iPad|iPod/i.test(navigator.userAgent)?'ios':'web');}
function playSound(key){
  if(key==='silent')return;
  const ctx=new (window.AudioContext||window.webkitAudioContext)(), now=ctx.currentTime;
  const seq={cash:[[880,.08],[1175,.09],[1568,.12]],alert:[[440,.16],[330,.16]],critical:[[220,.12],[220,.12],[220,.18]],soft:[[660,.12]]}[key]||[[700,.1]];
  let t=now;
  seq.forEach(([freq,dur])=>{const osc=ctx.createOscillator(),gain=ctx.createGain();osc.type='sine';osc.frequency.value=freq;gain.gain.setValueAtTime(.0001,t);gain.gain.exponentialRampToValueAtTime(.18,t+.015);gain.gain.exponentialRampToValueAtTime(.0001,t+dur);osc.connect(gain).connect(ctx.destination);osc.start(t);osc.stop(t+dur+.02);t+=dur+.045;});
}
window.adminAppPlaySound=playSound;

window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();deferredPrompt=e;installBtn.disabled=false;});
installBtn.onclick=async()=>{try{if(deferredPrompt){deferredPrompt.prompt();await deferredPrompt.userChoice;deferredPrompt=null;msg('Instalação solicitada. Se o app já estiver instalado, abra pelo ícone do sistema.','ok');}else{msg(installHelp(),'ok');}}catch(e){msg(e.message,'err');}};
refreshInstallState();
testBtn.onclick=()=>playSound('cash');

async function registerSW(){
  if(!('serviceWorker'in navigator)||!window.isSecureContext)throw new Error('Este navegador não permite instalação segura aqui.');
  swRegistration=await navigator.serviceWorker.register('admin-pwa-sw.php',{scope:'./'});
  await navigator.serviceWorker.ready;
  return swRegistration;
}
async function enablePush(forceRefresh = false){
  localStorage.removeItem('admin_push_disabled');
  if(!PUSH_READY)throw new Error('Firebase Push ainda não está configurado.');
  if(isIOS()&&!isStandalone())throw new Error(notificationHelp());
  if(!('Notification'in window))throw new Error(notificationHelp());
  const permission=await Notification.requestPermission();
  if(permission!=='granted')throw new Error('Permissão de notificações não concedida.');
  const registration=swRegistration||await registerSW();
  if(!firebase.apps.length)firebase.initializeApp(FIREBASE_CONFIG);
  const messaging=firebase.messaging();

  if(forceRefresh){
    try{await messaging.deleteToken();}catch(e){}
    localStorage.removeItem('admin_push_token');
  }

  let token=localStorage.getItem('admin_push_token')||'';
  if(!token||forceRefresh){
    try{
      token=await messaging.getToken({vapidKey:VAPID_KEY,serviceWorkerRegistration:registration});
    }catch(e){
      try{await messaging.deleteToken();}catch(err){}
      token=await messaging.getToken({vapidKey:VAPID_KEY,serviceWorkerRegistration:registration});
    }
  }
  if(!token)throw new Error('Não foi possível conectar este dispositivo.');
  const installed=isStandalone();
  const resp=await fetch('api_admin_push_device.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'register',client_id:clientId(),token,permission:'granted',installed,platform:platform()})});
  const json=await resp.json();
  if(!resp.ok||!json.ok)throw new Error(json.message||'Falha ao registrar dispositivo.');
  localStorage.setItem('admin_push_token',token);
  refreshPushToggle();
  messaging.onMessage(payload=>{
    const data=payload&&payload.data?payload.data:{};
    if(data.channel&&data.channel!=='admin')return;
    playSound(data.sound_key||'cash');
    registration.showNotification(data.title||'Gestão de Vendas',{body:data.body||'',icon:'../public/pwa-icon.svg',badge:'../public/pwa-icon.svg',data:{click_url:data.click_url||'vendas_analytics.php'}});
  });
  if(!forceRefresh) msg('Notificações administrativas ativadas neste dispositivo.','ok');
  await loadPrefs();
}
async function disablePush(){
  const token=localStorage.getItem('admin_push_token')||'';
  const permission=('Notification'in window)?Notification.permission:'default';
  localStorage.setItem('admin_push_disabled','1');
  refreshPushToggle();
  await fetch('api_admin_push_device.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'disable',client_id:clientId(),token,permission,installed:isStandalone(),platform:platform()})});
  msg('Notificações desligadas para este dispositivo.','ok');
  await loadPrefs();
}
pushBtn.onclick=async()=>{
  pushBtn.disabled=true;
  try{
    if(pushEnabled())await disablePush();
    else await enablePush();
  }catch(e){msg(e.message,'err');refreshPushToggle();}
  finally{pushBtn.disabled=false;}
};
testPushBtn.onclick=async()=>{
  testPushBtn.disabled=true;
  try{
    if(pushDisabled())throw new Error('Notificações desligadas neste dispositivo. Ligue o botão antes de testar.');
    await enablePush();
    let token=localStorage.getItem('admin_push_token')||'';
    let resp=await fetch('api_admin_push_test.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({client_id:clientId(),token})});
    let json=await resp.json();
    if(!resp.ok||!json.ok){
      const errText=(json.message||'')+' '+(json.error||'');
      if(errText.includes('NotRegistered')||errText.includes('device_not_registered')||errText.includes('UNREGISTERED')||errText.includes('NOT_FOUND')){
        msg('Renovando chave de notificação do Firebase... Aguarde...','ok');
        await enablePush(true);
        token=localStorage.getItem('admin_push_token')||'';
        resp=await fetch('api_admin_push_test.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({client_id:clientId(),token})});
        json=await resp.json();
      }
    }
    if(!resp.ok||!json.ok)throw new Error(json.message||'Falha ao enviar teste push.');
    msg('Teste push enviado para este dispositivo! Confira a notificação no seu sistema.','ok');
  }catch(e){msg(e.message,'err');}
  finally{testPushBtn.disabled=false;}
};

async function heartbeat(){
  try{
    const token=localStorage.getItem('admin_push_token')||'';
    const installed=isStandalone();
    if(pushDisabled()||!token||!('Notification'in window))return;
    await fetch('api_admin_push_device.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:installed?'installed':'heartbeat',client_id:clientId(),token,permission:Notification.permission,installed,platform:platform()})});
  }catch(e){}
}
async function loadPrefs(){
  const resp=await fetch('api_admin_push_preferences.php',{credentials:'same-origin',cache:'no-store'});
  const json=await resp.json();
  if(!json.ok)throw new Error(json.message||'Falha ao carregar preferências.');
  renderEvents(json.events,json.prefs);
  renderDevices(json.devices||[]);
}
function renderDevices(devices){
  const el=document.getElementById('admDevices');
  if(!devices.length){el.innerHTML='<p class="admapp-note">Nenhum dispositivo registrado ainda.</p>';return;}
  el.innerHTML=devices.map(d=>`<div class="admapp-device"><strong>${esc(d.platform||'web')} · ${esc(d.notification_permission||'')}</strong><p>Status: ${esc(d.status||'')}<br>Último acesso: ${esc(d.last_seen_at||'-')}</p>${d.last_error?`<p style="color:#fca5a5">${esc(d.last_error)}</p>`:''}</div>`).join('');
}
function renderEvents(events,prefs){
  const el=document.getElementById('admEvents');
  el.innerHTML=Object.keys(events).map(code=>{
    const p=prefs[code]||{};
    return `<div class="admapp-event" data-event="${esc(code)}">
      <div class="admapp-event-main"><strong>${esc(events[code].label||code)}</strong><span>${esc(code)}</span></div>
      <label class="admapp-switch"><input type="checkbox" data-field="enabled" ${p.enabled?'checked':''}> Ativar</label>
      <div><label>Som</label><select data-field="sound_key">${['cash','alert','critical','soft','silent'].map(s=>`<option value="${s}" ${p.sound_key===s?'selected':''}>${{cash:'Caixa',alert:'Alerta',critical:'Crítico',soft:'Leve',silent:'Silencioso'}[s]}</option>`).join('')}</select></div>
      <div><label>Valor mínimo</label><input data-field="min_value" inputmode="decimal" value="${Number((p.min_value_cents||0)/100).toFixed(2)}"></div>
      <div><label>Produto contém</label><input data-field="product_filter" value="${esc(p.product_filter||'')}"></div>
      <button class="admapp-btn" type="button" data-save>Salvar</button>
    </div>`;
  }).join('');
}
document.addEventListener('click',async e=>{
  const btn=e.target.closest('[data-save]'); if(!btn)return;
  const row=btn.closest('.admapp-event'),data={event_code:row.dataset.event};
  row.querySelectorAll('[data-field]').forEach(field=>{data[field.dataset.field]=field.type==='checkbox'?field.checked:field.value;});
  btn.disabled=true;
  try{const resp=await fetch('api_admin_push_preferences.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data)});const json=await resp.json();if(!resp.ok||!json.ok)throw new Error(json.message||'Falha ao salvar.');msg('Preferência salva.','ok');if(data.sound_key)playSound(data.sound_key);}catch(err){msg(err.message,'err');}finally{btn.disabled=false;}
});
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

registerSW().then(()=>heartbeat()).catch(e=>msg(e.message,'err'));
refreshPushToggle();
loadPrefs().catch(e=>msg(e.message,'err'));
setInterval(heartbeat,60000);
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
