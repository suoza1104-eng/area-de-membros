<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/integration_hub.php';
proteger_admin();
$pdo=getPDO();hub_ensure_schema($pdo);

$action=(string)($_POST['action']??$_GET['action']??'');
if($action!==''){
    header('Content-Type: application/json; charset=utf-8');
    if($action==='save_route'){
        $slug=trim((string)($_POST['destination']??''));$enabled=isset($_POST['enabled'])?1:0;
        if(!in_array($slug,['superfuncionario','manychat','webhook'],true)){echo json_encode(['ok'=>false,'message'=>'Destino invalido']);exit;}
        $config=json_decode((string)($_POST['config_json']??''),true);
        if(!is_array($config)){echo json_encode(['ok'=>false,'message'=>'Configuracao JSON invalida']);exit;}
        if($slug==='superfuncionario'){
            if(trim((string)($config['fixed_tag']??''))===''||!ctype_digit((string)($config['flow_id']??''))){echo json_encode(['ok'=>false,'message'=>'Informe tag e fluxo numerico']);exit;}
            if(!is_array($config['fields']??null)){echo json_encode(['ok'=>false,'message'=>'Campos personalizados invalidos']);exit;}
            set_setting('hotmart_sf_shadow_capture_enabled',(string)$enabled);set_setting('hotmart_sf_shadow_fixed_tag',(string)$config['fixed_tag']);
            set_setting('hotmart_sf_shadow_flow_id',(string)$config['flow_id']);set_setting('hotmart_sf_shadow_event_prefix',(string)($config['event_tag_prefix']??'RV_'));
            set_setting('hotmart_sf_shadow_order_bump_prefix',(string)($config['order_bump_prefix']??'RV_ORDER_BUMP_'));
            set_setting('hotmart_sf_shadow_fields_json',json_encode($config['fields'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        $stmt=$pdo->prepare("UPDATE integration_routes r JOIN integration_destinations d ON d.id=r.destination_id SET r.is_active=:active,r.mode='shadow',r.config_json=:config WHERE d.slug=:slug");
        $stmt->execute(['active'=>$enabled,'config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'slug'=>$slug]);
        echo json_encode(['ok'=>true,'message'=>'Rota salva em modo sombra. Nenhum envio foi realizado.']);exit;
    }
    if($action==='logs'){
        $event=mb_strtoupper(trim((string)($_GET['event']??'')),'UTF-8');$params=[];$where='WHERE 1=1';
        if($event!==''){$where.=' AND e.event_name=:event';$params['event']=$event;}
        $sql="SELECT e.id,e.external_event_id,e.event_name,e.transaction_code,e.contact_email,e.contact_phone,e.raw_payload_json,e.received_at,
            d.name destination_name,d.adapter,l.status,l.prepared_payload_json,l.updated_at delivery_updated_at
            FROM integration_events e LEFT JOIN integration_deliveries l ON l.event_id=e.id LEFT JOIN integration_destinations d ON d.id=l.destination_id
            {$where} ORDER BY e.received_at DESC,e.id DESC,l.id ASC LIMIT 200";
        $stmt=$pdo->prepare($sql);$stmt->execute($params);echo json_encode(['ok'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['ok'=>false,'message'=>'Acao desconhecida']);exit;
}

$routes=[];foreach(hub_routes($pdo) as $route){$route['config']=json_decode((string)$route['config_json'],true)?:[];$routes[$route['destination_slug']]=$route;}
$stats=$pdo->query("SELECT COUNT(*) events,COUNT(DISTINCT event_name) event_types,MAX(received_at) last_event FROM integration_events")->fetch(PDO::FETCH_ASSOC)?:[];
$deliveryStats=$pdo->query("SELECT COUNT(*) deliveries FROM integration_deliveries WHERE status='shadow'")->fetch(PDO::FETCH_ASSOC)?:[];
$menu='integration_hub';$page_title='Hub de Integrações';include __DIR__.'/_header.php';
function hub_h(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function hub_json($value):string{return hub_h(json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
?>
<style>
.hub-grid{display:grid;grid-template-columns:repeat(3,minmax(260px,1fr));gap:14px}.hub-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:18px}.hub-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.hub-pill{font-size:10px;font-weight:800;padding:3px 9px;border-radius:999px;border:1px solid rgba(250,204,21,.35);color:var(--primary);background:var(--primary-dim)}.hub-stat{padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg-card);font-size:12px}.hub-form{display:none;margin-top:14px;border-top:1px solid var(--border);padding-top:14px}.hub-form.open{display:block}.hub-form label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin:10px 0 4px}.hub-form input,.hub-form textarea,.hub-form select{width:100%;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:9px}.hub-form textarea{min-height:105px;font-family:monospace;font-size:11px}.hub-url{display:flex;gap:8px;align-items:center;background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:9px 11px;margin-top:10px}.hub-url code{flex:1;overflow:auto;color:#60a5fa}.hub-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:1000;align-items:center;justify-content:center}.hub-modal.open{display:flex}.hub-modal-box{width:1100px;max-width:96vw;max-height:88vh;overflow:auto;background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:20px}.hub-log{border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:9px}.hub-log pre{white-space:pre-wrap;max-height:300px;overflow:auto;background:#050914;padding:10px;border-radius:7px;font-size:10px}@media(max-width:1050px){.hub-grid{grid-template-columns:1fr}}
</style>
<div class="main-content">
 <div class="page-header"><div><h1 class="page-title">Hub de Integrações</h1><p class="page-subtitle">Recebe, normaliza, armazena e prepara entregas para sistemas externos.</p></div><button class="btn" onclick="hubOpenLogs()">Ver logs</button></div>
 <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px"><span class="hub-stat"><strong><?= (int)($stats['events']??0) ?></strong> eventos</span><span class="hub-stat"><strong><?= (int)($stats['event_types']??0) ?></strong> tipos</span><span class="hub-stat"><strong><?= (int)($deliveryStats['deliveries']??0) ?></strong> entregas preparadas</span><span class="hub-stat">Último: <strong><?= hub_h((string)($stats['last_event']??'ainda não recebido')) ?></strong></span></div>
 <div class="hub-card" style="margin-bottom:16px;border-color:rgba(56,189,248,.3)"><div class="hub-head"><div><h3>Fonte: Hotmart</h3><p style="font-size:12px;color:var(--muted);margin-top:4px">O payload original é salvo antes de qualquer transformação.</p></div><span class="hub-pill" style="color:#7dd3fc;border-color:rgba(56,189,248,.35)">ATIVA</span></div><div class="hub-url"><code id="hubUrl"><?= hub_h(rtrim(BASE_URL,'/').'/hotmart_metrics_webhook.php') ?></code><button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('hubUrl').textContent)">Copiar</button></div></div>
 <h2 style="font-size:15px;margin:0 0 10px">Rotas da fonte Hotmart</h2>
 <div class="hub-grid">
 <?php foreach(['superfuncionario','manychat','webhook'] as $slug): $route=$routes[$slug];$cfg=$route['config']; ?>
  <div class="hub-card"><div class="hub-head"><div><h3><?= hub_h((string)$route['destination_name']) ?></h3><p style="font-size:11px;color:var(--muted);margin-top:4px">Hotmart → <?= hub_h((string)$route['destination_name']) ?></p></div><span class="hub-pill"><?= (int)$route['is_active']===1?'SHADOW ATIVO':'PAUSADA' ?></span></div>
   <p style="font-size:11px;color:#fbbf24;margin-top:10px">Nenhuma entrega externa é executada enquanto a rota estiver em modo sombra.</p>
   <button class="btn btn-sm" style="margin-top:12px" onclick="hubToggleForm('<?= $slug ?>')">Configurar</button>
   <div class="hub-form" id="form-<?= $slug ?>"><label style="display:flex;align-items:center;gap:8px"><input style="width:auto" type="checkbox" id="enabled-<?= $slug ?>" <?= (int)$route['is_active']===1?'checked':'' ?>>Preparar entregas em modo sombra</label>
   <?php if($slug==='superfuncionario'): ?>
    <label>Tag fixa</label><input id="sf-tag" value="<?= hub_h((string)($cfg['fixed_tag']??'')) ?>"><label>ID do fluxo</label><input id="sf-flow" value="<?= hub_h((string)($cfg['flow_id']??'')) ?>"><label>Prefixo da tag do evento</label><input id="sf-prefix" value="<?= hub_h((string)($cfg['event_tag_prefix']??'RV_')) ?>"><label>Prefixo order bump</label><input id="sf-order" value="<?= hub_h((string)($cfg['order_bump_prefix']??'RV_ORDER_BUMP_')) ?>"><label>Campos personalizados</label><textarea id="sf-fields" style="min-height:280px"><?= hub_json($cfg['fields']??[]) ?></textarea>
   <?php elseif($slug==='manychat'): ?>
    <label>Tags (JSON; aceita {{event}})</label><textarea id="mc-tags"><?= hub_json($cfg['tags']??[]) ?></textarea><label>Flows (JSON)</label><textarea id="mc-flows"><?= hub_json($cfg['flows']??[]) ?></textarea><label>Campos personalizados (JSON)</label><textarea id="mc-fields"><?= hub_json($cfg['fields']??[]) ?></textarea>
   <?php else: ?>
    <label>URL de destino</label><input id="wh-url" value="<?= hub_h((string)($cfg['url']??'')) ?>"><label>Método</label><select id="wh-method"><option <?= ($cfg['method']??'POST')==='POST'?'selected':'' ?>>POST</option><option <?= ($cfg['method']??'')==='PUT'?'selected':'' ?>>PUT</option><option <?= ($cfg['method']??'')==='PATCH'?'selected':'' ?>>PATCH</option></select><label>Headers (JSON)</label><textarea id="wh-headers"><?= hub_json($cfg['headers']??[]) ?></textarea><label>Template do payload (JSON; aceita {{event}} e {{data}})</label><textarea id="wh-payload"><?= hub_json($cfg['payload_template']??[]) ?></textarea>
   <?php endif; ?><div id="msg-<?= $slug ?>" style="font-size:11px;margin:9px 0"></div><button class="btn btn-primary btn-sm" onclick="hubSave('<?= $slug ?>')">Salvar rota</button></div>
  </div>
 <?php endforeach; ?>
 </div>
</div>
<div class="hub-modal" id="hubLogs"><div class="hub-modal-box"><div style="display:flex;justify-content:space-between;align-items:center"><div><h2>Logs do Hub</h2><p style="font-size:11px;color:var(--muted)">Payload original e entregas preparadas, sem envio.</p></div><button class="btn" onclick="hubCloseLogs()">Fechar</button></div><div style="display:flex;gap:8px;margin:14px 0"><input id="hubEventFilter" placeholder="PURCHASE_APPROVED" style="flex:1;background:var(--bg-card);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:9px"><button class="btn" onclick="hubLoadLogs()">Filtrar</button></div><div id="hubLogList">Carregando…</div></div></div>
<script>
function hubToggleForm(slug){document.getElementById('form-'+slug).classList.toggle('open')}
function hubParse(id){return JSON.parse(document.getElementById(id).value||'[]')}
async function hubSave(slug){let config;try{if(slug==='superfuncionario')config={fixed_tag:document.getElementById('sf-tag').value,flow_id:document.getElementById('sf-flow').value,event_tag_prefix:document.getElementById('sf-prefix').value,order_bump_prefix:document.getElementById('sf-order').value,fields:hubParse('sf-fields')};else if(slug==='manychat')config={tags:hubParse('mc-tags'),flows:hubParse('mc-flows'),fields:hubParse('mc-fields')};else config={url:document.getElementById('wh-url').value,method:document.getElementById('wh-method').value,headers:hubParse('wh-headers'),payload_template:hubParse('wh-payload')};}catch(e){document.getElementById('msg-'+slug).textContent='JSON inválido: '+e.message;return}const fd=new FormData();fd.append('action','save_route');fd.append('destination',slug);if(document.getElementById('enabled-'+slug).checked)fd.append('enabled','1');fd.append('config_json',JSON.stringify(config));const data=await(await fetch('integration_hub.php',{method:'POST',body:fd})).json();const msg=document.getElementById('msg-'+slug);msg.style.color=data.ok?'#4ade80':'#f87171';msg.textContent=data.message||'Erro';}
function hubOpenLogs(){document.getElementById('hubLogs').classList.add('open');hubLoadLogs()}function hubCloseLogs(){document.getElementById('hubLogs').classList.remove('open')}function hubEsc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML}function hubPretty(v){try{return JSON.stringify(JSON.parse(v||'{}'),null,2)}catch(e){return v||''}}
async function hubLoadLogs(){const list=document.getElementById('hubLogList');list.textContent='Carregando…';const event=document.getElementById('hubEventFilter').value;const data=await(await fetch('integration_hub.php?action=logs&event='+encodeURIComponent(event))).json();if(!data.data.length){list.innerHTML='<div style="color:var(--muted)">Nenhum evento novo recebido pelo Hub.</div>';return}list.innerHTML=data.data.map(r=>`<div class="hub-log"><div style="display:flex;justify-content:space-between;gap:10px;font-size:11px"><strong style="color:#c4b5fd">${hubEsc(r.event_name)} · ${hubEsc(r.transaction_code||'sem transação')}</strong><span>${hubEsc(r.received_at)}</span></div><div style="font-size:11px;color:var(--muted);margin:5px 0">Destino: ${hubEsc(r.destination_name||'nenhuma rota preparada')} · ${hubEsc(r.status||'somente recebido')}</div><details><summary>Payload original</summary><pre>${hubEsc(hubPretty(r.raw_payload_json))}</pre></details>${r.prepared_payload_json?`<details><summary>Payload preparado para ${hubEsc(r.destination_name)} — não enviado</summary><pre>${hubEsc(hubPretty(r.prepared_payload_json))}</pre></details>`:''}</div>`).join('')}
</script>
<?php include __DIR__.'/_footer.php'; ?>
