<?php
if (!function_exists('support_widget_h')) {
    function support_widget_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
$__supportWidgetOpen = isset($_GET['abrir_suporte']) && (string)$_GET['abrir_suporte'] === '1';
$__supportWidgetName = trim((string)get_setting('support_chat_display_name', 'Suporte FERA')) ?: 'Suporte FERA';
$__supportWidgetFontScale = max(0.85, min(1.35, (float)get_setting('support_chat_font_scale', '1.08')));
$__supportWidgetAvatarUrl = trim((string)get_setting('support_chat_avatar_url', ''));
$__supportWidgetAvatarSrc = '';
if ($__supportWidgetAvatarUrl !== '') {
    $__supportWidgetAvatarSrc = preg_match('~^(?:https?:)?//|^data:|^/~i', $__supportWidgetAvatarUrl) ? $__supportWidgetAvatarUrl : $__supportWidgetAvatarUrl;
}
?>
<style>
.scw-panel{--support-font-scale:<?= support_widget_h(number_format($__supportWidgetFontScale, 2, '.', '')) ?>;position:fixed;right:18px;bottom:86px;z-index:20010;width:min(430px,calc(100vw - 24px));height:min(720px,calc(100dvh - 112px));display:none;flex-direction:column;overflow:hidden;border:1px solid #34434b;border-radius:18px;background:#0b141a;color:#e9edef;box-shadow:0 25px 90px #000b;font-family:Inter,system-ui,sans-serif;font-size:calc(13px * var(--support-font-scale));touch-action:manipulation}.scw-panel.open{display:flex}.scw-head{position:relative;z-index:2;flex:0 0 auto;display:flex;align-items:center;gap:11px;padding:12px 14px;background:#075e54}.scw-avatar{width:42px;height:42px;min-width:42px;border-radius:50%;display:grid;place-items:center;overflow:hidden;background:#00a884;font-weight:900}.scw-avatar img{width:100%;height:100%;object-fit:cover;display:block}.scw-title{min-width:0;flex:1}.scw-title b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.scw-title small{display:block;font-size:calc(11px * var(--support-font-scale))}.scw-close{width:36px;height:36px;border:0;background:transparent;color:#fff;font-size:26px;line-height:1;cursor:pointer}.scw-messages{flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;display:flex;flex-direction:column;gap:7px;padding:12px;background-color:#0b141a;background-image:radial-gradient(#253139 1px,transparent 1px);background-size:18px 18px;overscroll-behavior:contain}.scw-empty{margin:auto;text-align:center;color:#9fb0bc;font-size:calc(12px * var(--support-font-scale))}.scw-loading:after{content:"";display:block;width:34px;height:4px;margin:12px auto 0;border-radius:999px;background:linear-gradient(90deg,#00a884,#67e8f9,#00a884);background-size:200% 100%;animation:scwload 1s linear infinite}@keyframes scwload{to{background-position:-200% 0}}.scw-msg{max-width:84%;align-self:flex-start;padding:7px 9px 4px;border-radius:8px;background:#202c33;color:#fff;font-size:calc(12px * var(--support-font-scale));line-height:1.4;box-shadow:0 1px 2px #0005;overflow-wrap:anywhere;word-break:break-word}.scw-msg.own{align-self:flex-end;background:#005c4b}.scw-sign{font-size:calc(8px * var(--support-font-scale));color:#67e8f9;font-weight:800}.scw-time{display:block;text-align:right;color:#9ca3af;font-size:calc(7px * var(--support-font-scale));margin-left:15px}.scw-buttons{display:grid;gap:6px;margin-top:8px}.scw-buttons a{display:block;text-align:center;padding:8px 10px;border-radius:8px;background:#e9edef;color:#075e54!important;font-weight:800;text-decoration:none}.scw-typing{flex:0 0 auto;min-height:20px;padding:0 13px;background:#0b141a;color:#9fb0bc;font-size:calc(11px * var(--support-font-scale))}.scw-compose{flex:0 0 auto;display:flex;align-items:center;gap:8px;min-height:62px;padding:8px 10px calc(10px + env(safe-area-inset-bottom));background:#202c33}.scw-plus,.scw-tool,.scw-send{flex:0 0 auto;border:0;background:transparent;color:#e9edef;display:grid;place-items:center;cursor:pointer}.scw-plus{width:28px;height:42px;font-size:30px;font-weight:300}.scw-input-shell{min-width:0;flex:1;height:42px;display:flex;align-items:center;gap:8px;padding:0 8px 0 14px;border-radius:22px;background:#1f2c33}.scw-input-shell input{min-width:0;flex:1;height:100%;padding:0;border:0;background:transparent;color:#fff;outline:0;font-size:max(16px,calc(12px * var(--support-font-scale)));line-height:20px}.scw-tool{width:28px;height:36px}.scw-tool svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}.scw-send{width:34px;height:42px;font-size:23px}.scw-send svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.scw-send:disabled{opacity:.55;cursor:wait}body.scw-lock{overflow:hidden!important;position:fixed;width:100%}@media(max-width:520px){.scw-panel{inset:0;width:100%;height:100dvh;max-height:100dvh;border:0;border-radius:0}.scw-messages{padding:12px 14px 10px}.scw-msg{max-width:88%}.scw-typing{padding:0 18px}.scw-compose{gap:7px;min-height:70px;padding:8px 9px calc(18px + env(safe-area-inset-bottom));background:#111b21}.scw-input-shell{height:39px;border-radius:20px}.scw-plus{width:24px;height:39px}.scw-send{width:28px;height:39px}.scw-send svg{width:22px;height:22px}}
</style>
<section class="scw-panel<?= $__supportWidgetOpen ? ' open' : '' ?>" id="supportChatWidget" aria-live="polite">
  <header class="scw-head">
    <div class="scw-avatar" id="scwAvatar"><?= $__supportWidgetAvatarSrc !== '' ? '<img src="' . support_widget_h($__supportWidgetAvatarSrc) . '" alt="">' : 'S' ?></div>
    <div class="scw-title"><b id="scwName"><?= support_widget_h($__supportWidgetName) ?></b><small>Normalmente responde rapido</small></div>
    <button type="button" class="scw-close" id="scwClose" aria-label="Fechar">&times;</button>
  </header>
  <div class="scw-messages" id="scwMessages"><div class="scw-empty scw-loading">Abrindo suporte...</div></div>
  <div class="scw-typing" id="scwTyping"></div>
  <form class="scw-compose" id="scwCompose">
    <button type="button" class="scw-plus" aria-label="Adicionar">+</button>
    <div class="scw-input-shell">
      <input type="text" id="scwText" placeholder="Mensagem" autocomplete="off">
      <button type="button" class="scw-tool" aria-label="Figurinha">
        <svg viewBox="0 0 24 24"><path d="M6 3h12a3 3 0 0 1 3 3v9l-6 6H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3z"/><path d="M15 21v-4a2 2 0 0 1 2-2h4"/><path d="M8 9h.01M15 9h.01M8.5 13.5c1.8 1.4 5.2 1.4 7 0"/></svg>
      </button>
      <button type="button" class="scw-tool" aria-label="Camera">
        <svg viewBox="0 0 24 24"><path d="M4 8h3l1.6-2h6.8L17 8h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2z"/><circle cx="12" cy="14" r="4"/></svg>
      </button>
    </div>
    <button class="scw-send" id="scwSend" aria-label="Enviar">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"/><path d="M8 9l4-4 4 4"/></svg>
    </button>
  </form>
</section>
<script>
(function(){
  const panel=document.getElementById('supportChatWidget'),messages=document.getElementById('scwMessages'),typing=document.getElementById('scwTyping'),form=document.getElementById('scwCompose'),input=document.getElementById('scwText'),send=document.getElementById('scwSend'),close=document.getElementById('scwClose'),nameEl=document.getElementById('scwName'),avatar=document.getElementById('scwAvatar');
  if(!panel||!messages||!form)return;
  let csrf='',conversation=0,last=0,loading=false,sendLock=false,loaded=false,lockedY=0;
  const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  async function api(url,opt={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...opt}),j=await r.json();if(!j.ok)throw new Error(j.error||'Falha no suporte.');return j}
  function fmt(v){return v?new Date(String(v).replace(' ','T')).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}):''}
  function updateSendIcon(){send.innerHTML=input.value.trim()?'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"/><path d="M8 9l4-4 4 4"/></svg>':'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3z"/><path d="M5 10v1a7 7 0 0 0 14 0v-1"/><path d="M12 18v3"/></svg>'}
  function html(m){const own=m.sender_type==='student';let body=esc(m.body||'').replace(/\n/g,'<br>'),meta={};try{meta=JSON.parse(m.metadata_json||'{}')}catch(e){}if(Array.isArray(meta.buttons)&&meta.buttons.length)body+=`<div class="scw-buttons">${meta.buttons.map(b=>`<a target="_blank" rel="noopener" href="${esc(b.url||'#')}">${esc(b.label||'Abrir')}</a>`).join('')}</div>`;return `<div class="scw-msg ${own?'own':''}" data-message-id="${esc(m.id)}">${!own?`<div class="scw-sign">${esc(m.sender_name||'Suporte')}</div>`:''}${body}<span class="scw-time">${fmt(m.created_at)} ${own?'OK':''}</span></div>`}
  function applyIdentity(j){conversation=+j.conversation_id||conversation;csrf=j.csrf||csrf;if(j.display_name)nameEl.textContent=j.display_name;if(j.font_scale)panel.style.setProperty('--support-font-scale',Math.max(.85,Math.min(1.35,parseFloat(j.font_scale)||1.08)).toFixed(2));if(j.avatar_src){avatar.innerHTML='';const img=document.createElement('img');img.src=j.avatar_src;img.alt='';avatar.appendChild(img)}}
  function scrollToEnd(){requestAnimationFrame(()=>{messages.scrollTop=messages.scrollHeight})}
  function lockBody(){if(document.body.classList.contains('scw-lock'))return;lockedY=window.scrollY||document.documentElement.scrollTop||0;document.body.style.top='-'+lockedY+'px';document.body.classList.add('scw-lock')}
  function unlockBody(){if(!document.body.classList.contains('scw-lock'))return;document.body.classList.remove('scw-lock');document.body.style.top='';window.scrollTo(0,lockedY)}
  function renderBatch(j,full=false){if(full)messages.innerHTML='';applyIdentity(j);let added=0;(j.messages||[]).forEach(m=>{last=Math.max(last,+m.id);if(m.id&&messages.querySelector(`[data-message-id="${m.id}"]`))return;messages.insertAdjacentHTML('beforeend',html(m));added++});if(!messages.children.length)messages.innerHTML='<div class="scw-empty">Envie uma mensagem para iniciar.</div>';typing.textContent=(j.typing&&j.typing.length)?'Suporte digitando...':'';if(added||full)scrollToEnd()}
  async function init(){if(loaded)return;loaded=true;messages.innerHTML='<div class="scw-empty scw-loading">Abrindo suporte...</div>';const j=await api('api_support_chat.php?api=init');renderBatch(j,true)}
  async function refresh(full=false){if(loading)return;loading=true;try{const j=await api('api_support_chat.php?api=messages&after='+(full?0:last));renderBatch(j,full)}catch(e){messages.innerHTML='<div class="scw-empty">'+esc(e.message)+'</div>'}finally{loading=false}}
  async function openPanel(){lockBody();panel.classList.add('open');try{await init();scrollToEnd()}catch(e){messages.innerHTML='<div class="scw-empty">'+esc(e.message)+'</div>'}}
  close.onclick=()=>{panel.classList.remove('open');unlockBody()};
  document.addEventListener('click',function(e){const a=e.target.closest('a[data-support-agent="1"]');if(!a)return;e.preventDefault();openPanel();const url=new URL(location.href);url.searchParams.delete('abrir_suporte');history.replaceState(null,'',url.pathname+url.search+url.hash)},true);
  form.onsubmit=async e=>{e.preventDefault();const body=input.value.trim();if(!body||sendLock)return;sendLock=true;send.disabled=true;input.value='';updateSendIcon();messages.insertAdjacentHTML('beforeend',html({id:'local-'+Date.now(),sender_type:'student',body,created_at:new Date().toISOString()}));messages.scrollTop=messages.scrollHeight;try{const f=new FormData();f.append('csrf',csrf);f.append('action','send');f.append('body',body);await api('api_support_chat.php',{method:'POST',body:f});last=0;await refresh(true)}catch(err){alert(err.message)}finally{sendLock=false;send.disabled=false;updateSendIcon()}};
  let typingTimer;input.addEventListener('input',()=>{updateSendIcon();clearTimeout(typingTimer);typingTimer=setTimeout(()=>{if(!csrf)return;const f=new FormData();f.append('csrf',csrf);f.append('action','typing');api('api_support_chat.php',{method:'POST',body:f}).catch(()=>{})},450)});
  updateSendIcon();
  if(panel.classList.contains('open'))openPanel();
  window.addEventListener('pagehide',unlockBody);
  setInterval(()=>{if(panel.classList.contains('open')&&!sendLock)refresh()},3000);
})();
</script>
