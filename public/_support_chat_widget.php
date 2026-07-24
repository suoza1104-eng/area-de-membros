<?php
if (!function_exists('support_widget_h')) {
    function support_widget_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
$__supportWidgetOpen = isset($_GET['abrir_suporte']) && (string)$_GET['abrir_suporte'] === '1';
?>
<style>
.scw-panel{--support-font-scale:1.08;position:fixed;right:18px;bottom:86px;z-index:20010;width:min(430px,calc(100vw - 24px));height:min(720px,calc(100dvh - 112px));display:none;flex-direction:column;overflow:hidden;border:1px solid #34434b;border-radius:18px;background:#0b141a;color:#e9edef;box-shadow:0 25px 90px #000b;font-family:Inter,system-ui,sans-serif;font-size:calc(13px * var(--support-font-scale))}.scw-panel.open{display:flex}.scw-head{display:flex;align-items:center;gap:11px;padding:12px 14px;background:#075e54}.scw-avatar{width:42px;height:42px;min-width:42px;border-radius:50%;display:grid;place-items:center;overflow:hidden;background:#00a884;font-weight:900}.scw-avatar img{width:100%;height:100%;object-fit:cover;display:block}.scw-title{min-width:0;flex:1}.scw-title b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.scw-title small{display:block;font-size:calc(11px * var(--support-font-scale))}.scw-close{width:36px;height:36px;border:0;background:transparent;color:#fff;font-size:26px;line-height:1;cursor:pointer}.scw-messages{flex:1;min-height:0;overflow-y:auto;display:flex;flex-direction:column;gap:7px;padding:12px;background-color:#0b141a;background-image:radial-gradient(#253139 1px,transparent 1px);background-size:18px 18px}.scw-empty{margin:auto;text-align:center;color:#9fb0bc;font-size:calc(12px * var(--support-font-scale))}.scw-msg{max-width:84%;align-self:flex-start;padding:7px 9px 4px;border-radius:8px;background:#202c33;color:#fff;font-size:calc(12px * var(--support-font-scale));line-height:1.4;box-shadow:0 1px 2px #0005;overflow-wrap:anywhere;word-break:break-word}.scw-msg.own{align-self:flex-end;background:#005c4b}.scw-sign{font-size:calc(8px * var(--support-font-scale));color:#67e8f9;font-weight:800}.scw-time{display:block;text-align:right;color:#9ca3af;font-size:calc(7px * var(--support-font-scale));margin-left:15px}.scw-buttons{display:grid;gap:6px;margin-top:8px}.scw-buttons a{display:block;text-align:center;padding:8px 10px;border-radius:8px;background:#e9edef;color:#075e54!important;font-weight:800;text-decoration:none}.scw-typing{min-height:22px;padding:0 13px;background:#0b141a;color:#9fb0bc;font-size:calc(11px * var(--support-font-scale))}.scw-compose{display:flex;align-items:center;gap:8px;padding:8px;background:#202c33}.scw-compose input{min-width:0;flex:1;height:40px;padding:9px 13px;border:0;border-radius:20px;background:#2a3942;color:#fff;outline:0;font-size:calc(12px * var(--support-font-scale))}.scw-send{width:40px;height:40px;border:0;border-radius:50%;display:grid;place-items:center;background:#00a884;color:#fff;font-size:18px;cursor:pointer}.scw-send:disabled{opacity:.55;cursor:wait}@media(max-width:520px){.scw-panel{inset:0;width:100%;height:100dvh;border:0;border-radius:0}.scw-messages{padding:12px 8px}.scw-msg{max-width:88%}}
</style>
<section class="scw-panel<?= $__supportWidgetOpen ? ' open' : '' ?>" id="supportChatWidget" aria-live="polite">
  <header class="scw-head">
    <div class="scw-avatar" id="scwAvatar">S</div>
    <div class="scw-title"><b id="scwName">Suporte FERA</b><small>Normalmente responde rapido</small></div>
    <button type="button" class="scw-close" id="scwClose" aria-label="Fechar">&times;</button>
  </header>
  <div class="scw-messages" id="scwMessages"><div class="scw-empty">Carregando atendimento...</div></div>
  <div class="scw-typing" id="scwTyping"></div>
  <form class="scw-compose" id="scwCompose">
    <input type="text" id="scwText" placeholder="Mensagem" autocomplete="off">
    <button class="scw-send" id="scwSend" aria-label="Enviar">&gt;</button>
  </form>
</section>
<script>
(function(){
  const panel=document.getElementById('supportChatWidget'),messages=document.getElementById('scwMessages'),typing=document.getElementById('scwTyping'),form=document.getElementById('scwCompose'),input=document.getElementById('scwText'),send=document.getElementById('scwSend'),close=document.getElementById('scwClose'),nameEl=document.getElementById('scwName'),avatar=document.getElementById('scwAvatar');
  if(!panel||!messages||!form)return;
  let csrf='',conversation=0,last=0,loading=false,sendLock=false,loaded=false;
  const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  async function api(url,opt={}){const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...opt}),j=await r.json();if(!j.ok)throw new Error(j.error||'Falha no suporte.');return j}
  function fmt(v){return v?new Date(String(v).replace(' ','T')).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}):''}
  function html(m){const own=m.sender_type==='student';let body=esc(m.body||'').replace(/\n/g,'<br>'),meta={};try{meta=JSON.parse(m.metadata_json||'{}')}catch(e){}if(Array.isArray(meta.buttons)&&meta.buttons.length)body+=`<div class="scw-buttons">${meta.buttons.map(b=>`<a target="_blank" rel="noopener" href="${esc(b.url||'#')}">${esc(b.label||'Abrir')}</a>`).join('')}</div>`;return `<div class="scw-msg ${own?'own':''}" data-message-id="${esc(m.id)}">${!own?`<div class="scw-sign">${esc(m.sender_name||'Suporte')}</div>`:''}${body}<span class="scw-time">${fmt(m.created_at)} ${own?'OK':''}</span></div>`}
  function applyIdentity(j){conversation=+j.conversation_id||conversation;csrf=j.csrf||csrf;if(j.display_name)nameEl.textContent=j.display_name;if(j.font_scale)panel.style.setProperty('--support-font-scale',Math.max(.85,Math.min(1.35,parseFloat(j.font_scale)||1.08)).toFixed(2));if(j.avatar_src){avatar.innerHTML='';const img=document.createElement('img');img.src=j.avatar_src;img.alt='';avatar.appendChild(img)}}
  async function init(){if(loaded)return;loaded=true;const j=await api('api_support_chat.php?api=init');applyIdentity(j);await refresh(true)}
  async function refresh(full=false){if(loading)return;loading=true;try{const j=await api('api_support_chat.php?api=messages&after='+(full?0:last));if(full)messages.innerHTML='';applyIdentity(j);j.messages.forEach(m=>{last=Math.max(last,+m.id);if(m.id&&messages.querySelector(`[data-message-id="${m.id}"]`))return;messages.insertAdjacentHTML('beforeend',html(m))});if(!messages.children.length)messages.innerHTML='<div class="scw-empty">Envie uma mensagem para iniciar.</div>';typing.textContent=(j.typing&&j.typing.length)?'Suporte digitando...':'';if(j.messages.length||full)messages.scrollTop=messages.scrollHeight}catch(e){messages.innerHTML='<div class="scw-empty">'+esc(e.message)+'</div>'}finally{loading=false}}
  async function openPanel(){panel.classList.add('open');try{await init()}catch(e){messages.innerHTML='<div class="scw-empty">'+esc(e.message)+'</div>'}input.focus()}
  close.onclick=()=>panel.classList.remove('open');
  document.addEventListener('click',function(e){const a=e.target.closest('a[data-support-agent="1"]');if(!a)return;e.preventDefault();openPanel();const url=new URL(location.href);url.searchParams.delete('abrir_suporte');history.replaceState(null,'',url.pathname+url.search+url.hash)},true);
  form.onsubmit=async e=>{e.preventDefault();const body=input.value.trim();if(!body||sendLock)return;sendLock=true;send.disabled=true;input.value='';messages.insertAdjacentHTML('beforeend',html({id:'local-'+Date.now(),sender_type:'student',body,created_at:new Date().toISOString()}));messages.scrollTop=messages.scrollHeight;try{const f=new FormData();f.append('csrf',csrf);f.append('action','send');f.append('body',body);await api('api_support_chat.php',{method:'POST',body:f});last=0;await refresh(true)}catch(err){alert(err.message)}finally{sendLock=false;send.disabled=false}};
  let typingTimer;input.addEventListener('input',()=>{clearTimeout(typingTimer);typingTimer=setTimeout(()=>{if(!csrf)return;const f=new FormData();f.append('csrf',csrf);f.append('action','typing');api('api_support_chat.php',{method:'POST',body:f}).catch(()=>{})},450)});
  if(panel.classList.contains('open'))openPanel();
  setInterval(()=>{if(panel.classList.contains('open')&&!sendLock)refresh()},3000);
})();
</script>
