<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/voice_torpedo.php';
proteger_admin();

$pdo = getPDO();
voice_ensure_schema($pdo);
$menu = 'torpedo_voz';
$page_title = 'Torpedo de Voz';
$actor = (string)($_SESSION['equipe_nome'] ?? 'Administrador');
$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['torpedo_voz']['escrever']);
}
if (empty($_SESSION['voice_admin_csrf'])) $_SESSION['voice_admin_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['voice_admin_csrf'];
$diagnostic = null;

function vv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function vv_check(string $csrf): void { if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.'); }

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Sem permissao de escrita.');
        vv_check($csrf);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_provider') {
            voice_save_telnyx_config($pdo, $_POST, $actor);
            $message = 'Configuracao Telnyx salva.';
        } elseif ($action === 'test_connection') {
            $diagnostic = voice_telnyx_diagnostics($pdo);
            $message = !empty($diagnostic['ready']) ? 'Diagnostico concluido: conexao pronta.' : 'Diagnostico concluido com pendencias.';
        } elseif ($action === 'save_number') {
            $provider = voice_provider($pdo);
            $phone = voice_normalize_e164((string)($_POST['phone_e164'] ?? ''), (string)($provider['public']['default_country_code'] ?? '55'));
            if ($phone === '') throw new InvalidArgumentException('Informe um numero em E.164 valido.');
            if (!empty($_POST['is_default'])) $pdo->exec("UPDATE voice_phone_numbers SET is_default=0");
            $pdo->prepare("INSERT INTO voice_phone_numbers(provider_id,phone_e164,friendly_name,country,region,type,source_type,inbound_enabled,outbound_enabled,is_default,verification_status,connection_id,outbound_profile_id,status,metadata_json)
                VALUES(:p,:phone,:name,:country,:region,:type,:source,:inbound,:outbound,:def,:ver,:conn,:profile,'active',:meta)
                ON DUPLICATE KEY UPDATE friendly_name=VALUES(friendly_name),country=VALUES(country),region=VALUES(region),type=VALUES(type),source_type=VALUES(source_type),inbound_enabled=VALUES(inbound_enabled),outbound_enabled=VALUES(outbound_enabled),is_default=VALUES(is_default),verification_status=VALUES(verification_status),connection_id=VALUES(connection_id),outbound_profile_id=VALUES(outbound_profile_id),metadata_json=VALUES(metadata_json)")
                ->execute([
                    'p'=>(int)$provider['id'],
                    'phone'=>$phone,
                    'name'=>trim((string)($_POST['friendly_name'] ?? '')),
                    'country'=>trim((string)($_POST['country'] ?? 'BR')),
                    'region'=>trim((string)($_POST['region'] ?? '')),
                    'type'=>trim((string)($_POST['type'] ?? 'voice')),
                    'source'=>in_array(($_POST['source_type'] ?? 'manual'), ['telnyx_owned','verified_external','ported','sip','manual'], true) ? (string)$_POST['source_type'] : 'manual',
                    'inbound'=>!empty($_POST['inbound_enabled']) ? 1 : 0,
                    'outbound'=>!empty($_POST['outbound_enabled']) ? 1 : 0,
                    'def'=>!empty($_POST['is_default']) ? 1 : 0,
                    'ver'=>trim((string)($_POST['verification_status'] ?? 'manual')),
                    'conn'=>trim((string)($_POST['connection_id'] ?? '')),
                    'profile'=>trim((string)($_POST['outbound_profile_id'] ?? '')),
                    'meta'=>voice_json(['notes'=>trim((string)($_POST['notes'] ?? ''))]),
                ]);
            $message = 'Numero salvo.';
        } elseif ($action === 'add_suppression') {
            $provider = voice_provider($pdo);
            $phone = voice_normalize_e164((string)($_POST['phone_e164'] ?? ''), (string)($provider['public']['default_country_code'] ?? '55'));
            if ($phone === '') throw new InvalidArgumentException('Telefone invalido.');
            $pdo->prepare("INSERT INTO voice_suppression_list(phone_e164,reason,source,notes,created_by) VALUES(:p,:r,'admin',:n,:a) ON DUPLICATE KEY UPDATE reason=VALUES(reason),notes=VALUES(notes),permanent=1")
                ->execute(['p'=>$phone,'r'=>trim((string)($_POST['reason'] ?? 'manual')),'n'=>trim((string)($_POST['notes'] ?? '')),'a'=>$actor]);
            $message = 'Telefone bloqueado para chamadas.';
        } elseif ($action === 'upload_media') {
            if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) throw new RuntimeException('Envie um arquivo MP3 ou WAV.');
            $mime = (string)(mime_content_type($_FILES['audio']['tmp_name']) ?: '');
            $ext = strtolower(pathinfo((string)$_FILES['audio']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp3','wav'], true) || !in_array($mime, ['audio/mpeg','audio/mp3','audio/wav','audio/x-wav','audio/wave'], true)) throw new RuntimeException('Formato de audio nao aceito.');
            if ((int)$_FILES['audio']['size'] > 20 * 1024 * 1024) throw new RuntimeException('Audio acima de 20 MB.');
            $dir = realpath(__DIR__ . '/../public/uploads') ?: '';
            if ($dir === '') { mkdir(__DIR__ . '/../public/uploads', 0755, true); $dir = realpath(__DIR__ . '/../public/uploads') ?: ''; }
            $voiceDir = $dir . DIRECTORY_SEPARATOR . 'voice_media';
            if (!is_dir($voiceDir)) mkdir($voiceDir, 0755, true);
            $name = bin2hex(random_bytes(14)) . '.' . $ext;
            $dest = $voiceDir . DIRECTORY_SEPARATOR . $name;
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $dest)) throw new RuntimeException('Falha ao salvar audio.');
            $publicUrl = rtrim(BASE_URL, '/') . '/uploads/voice_media/' . rawurlencode($name);
            $pdo->prepare("INSERT INTO voice_media(name,media_type,source,local_path,public_url,mime_type,file_size,checksum,status,created_by) VALUES(:n,'uploaded_audio','local',:path,:url,:mime,:size,:checksum,'active',:actor)")
                ->execute(['n'=>trim((string)($_POST['name'] ?? 'Audio de voz')) ?: 'Audio de voz','path'=>$dest,'url'=>$publicUrl,'mime'=>$mime,'size'=>(int)$_FILES['audio']['size'],'checksum'=>hash_file('sha256',$dest),'actor'=>$actor]);
            $message = 'Audio salvo na biblioteca.';
        } elseif ($action === 'test_call') {
            if (empty($_POST['confirm_test_call'])) throw new RuntimeException('Confirme que entende que a chamada de teste pode gerar custo.');
            $r = voice_send_test_call($pdo, (string)$_POST['test_phone'], (string)($_POST['test_message'] ?? ''), (string)($_POST['test_audio_url'] ?? ''), $actor);
            $message = 'Chamada de teste criada. Attempt #' . (int)$r['attempt_id'];
        } elseif ($action === 'create_campaign') {
            $provider = voice_provider($pdo);
            $pdo->prepare("INSERT INTO voice_campaigns(name,description,provider_id,message_mode,message_template,machine_message_template,status,timezone,concurrency_limit,calls_per_minute,max_attempts,answering_machine_detection,record_calls,transcribe_calls,created_by)
                VALUES(:n,:d,:p,:mode,:msg,:machine,'draft',:tz,:conc,:rate,:attempts,:amd,:record,:transcribe,:actor)")
                ->execute([
                    'n'=>trim((string)($_POST['name'] ?? 'Nova campanha de voz')) ?: 'Nova campanha de voz',
                    'd'=>trim((string)($_POST['description'] ?? '')),
                    'p'=>(int)$provider['id'],
                    'mode'=>(string)($_POST['message_mode'] ?? 'text_to_speech'),
                    'msg'=>(string)($_POST['message_template'] ?? ''),
                    'machine'=>(string)($_POST['machine_message_template'] ?? ''),
                    'tz'=>(string)($provider['public']['timezone'] ?? 'America/Sao_Paulo'),
                    'conc'=>max(1,(int)($_POST['concurrency_limit'] ?? 1)),
                    'rate'=>max(1,(int)($_POST['calls_per_minute'] ?? 10)),
                    'attempts'=>max(1,min(5,(int)($_POST['max_attempts'] ?? 1))),
                    'amd'=>!empty($_POST['answering_machine_detection']) ? 1 : 0,
                    'record'=>!empty($_POST['record_calls']) ? 1 : 0,
                    'transcribe'=>!empty($_POST['transcribe_calls']) ? 1 : 0,
                    'actor'=>$actor,
                ]);
            $message = 'Campanha criada como rascunho.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$tab = (string)($_GET['tab'] ?? 'overview');
$provider = voice_provider($pdo);
$cfg = (array)$provider['public'];
$creds = (array)$provider['credentials'];
$stats = voice_dashboard_stats($pdo);
$campaigns = $pdo->query("SELECT * FROM voice_campaigns ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$numbers = $pdo->query("SELECT * FROM voice_phone_numbers ORDER BY is_default DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$media = $pdo->query("SELECT * FROM voice_media ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$calls = $pdo->query("SELECT * FROM voice_call_attempts ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$events = $pdo->query("SELECT * FROM voice_events ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$webhooks = $pdo->query("SELECT * FROM voice_webhook_logs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$suppression = $pdo->query("SELECT * FROM voice_suppression_list ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$daily = $pdo->query("SELECT DATE(created_at) d,COUNT(*) c,SUM(status='finished') done,SUM(answered_by='human') human FROM voice_call_attempts WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY d")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$statusRows = $pdo->query("SELECT status,COUNT(*) c FROM voice_call_attempts GROUP BY status ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$webhookUrl = rtrim(BASE_URL, '/') . '/telnyx_voice_webhook.php';
$failoverUrl = rtrim(BASE_URL, '/') . '/telnyx_voice_webhook_failover.php';

include __DIR__ . '/_header.php';
?>
<style>
.vv{display:grid;gap:14px}.vv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.vv-head h1{font-size:22px}.vv-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px}.vv-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.vv-nav a.active,.vv-nav a:hover{background:var(--primary-dim);color:var(--primary)}.vv-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:var(--shadow)}.vv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.vv-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.vv-kpi strong{display:block;font-size:25px}.vv-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.vv-field label{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;text-transform:uppercase}.vv-field input,.vv-field select,.vv-field textarea{width:100%;padding:9px 11px;border:1px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text)}.vv-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.vv-msg{padding:10px 12px;border-radius:9px;background:var(--success-dim);color:#86efac}.vv-error{padding:10px 12px;border-radius:9px;background:var(--danger-dim);color:#fca5a5}.vv-table{overflow:auto}.vv-table table{width:100%;border-collapse:collapse}.vv-table th,.vv-table td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:top}.vv-table th{font-size:10px;color:var(--muted);text-transform:uppercase}.vv-pill{display:inline-flex;padding:3px 8px;border-radius:999px;background:var(--bg-hover);font-size:10px}.vv-pill.ok{background:var(--success-dim);color:#86efac}.vv-pill.bad{background:var(--danger-dim);color:#fca5a5}.vv-pill.warn{background:var(--warning-dim);color:#facc15}.vv-code{display:block;padding:9px;border:1px solid var(--border);border-radius:8px;background:#071020;color:#bae6fd;word-break:break-all;font-size:12px}.vv-note{font-size:11px;color:var(--muted);line-height:1.45}.vv-split{display:grid;grid-template-columns:minmax(300px,1fr) minmax(300px,1fr);gap:14px}.vv-diag{display:grid;gap:8px;margin-top:12px}.vv-diag-row{display:grid;grid-template-columns:28px minmax(180px,1fr) minmax(180px,2fr);gap:10px;align-items:start;padding:10px;border:1px solid var(--border);border-radius:10px;background:#071020}.vv-diag-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.vv-diag-icon.ok{background:var(--success-dim);color:#86efac}.vv-diag-icon.error{background:var(--danger-dim);color:#fca5a5}.vv-diag-icon.pending{background:var(--bg-hover);color:#94a3b8}.vv-diag-icon.warning{background:var(--warning-dim);color:#facc15}.vv-diag-label{font-weight:700}.vv-diag-detail{color:var(--muted);font-size:11px}@media(max-width:900px){.vv-head,.vv-split{display:grid}.vv-actions{width:100%}.vv-diag-row{grid-template-columns:28px 1fr}.vv-diag-detail{grid-column:2}}
</style>
<div class="vv">
  <div class="vv-head">
    <div><h1>Torpedo de Voz</h1><p class="text-muted">Central para chamadas de voz, Telnyx, campanhas, audios, webhooks e logs auditaveis.</p></div>
    <div class="vv-actions"><span class="vv-pill <?=!empty($provider['enabled'])?'ok':'bad'?>"><?=!empty($provider['enabled'])?'Telnyx ativo':'Telnyx inativo'?></span><span class="vv-pill"><?=vv_h($provider['connection_status'] ?? 'pending')?></span></div>
  </div>
  <nav class="vv-nav">
    <?php foreach(['overview'=>'Visao geral','campaigns'=>'Campanhas','new'=>'Nova campanha','contacts'=>'Contatos e listas','media'=>'Audios e vozes','numbers'=>'Numeros','ai'=>'IA','calls'=>'Chamadas','reports'=>'Relatorios','settings'=>'Configuracoes','webhooks'=>'Webhooks e logs','suppression'=>'Bloqueio'] as $k=>$label): ?>
      <a class="<?=$tab===$k?'active':''?>" href="torpedo_voz.php?tab=<?=$k?>"><?=vv_h($label)?></a>
    <?php endforeach; ?>
  </nav>
  <?php if($message): ?><div class="vv-msg"><?=vv_h($message)?></div><?php endif; ?>
  <?php if($error): ?><div class="vv-error"><?=vv_h($error)?></div><?php endif; ?>

<?php if($tab === 'overview'): ?>
  <section class="vv-grid">
    <?php foreach(['campaigns'=>'Campanhas','running'=>'Em execucao','scheduled'=>'Programadas','initiated'=>'Iniciadas','ringing'=>'Chamando','answered'=>'Atendidas','human'=>'Humanos','machine'=>'Caixa postal','failed'=>'Falhas','completed'=>'Concluidas','audio_completed'=>'Audio completo','blocked'=>'Bloqueados'] as $k=>$label): ?>
      <div class="vv-card vv-kpi"><small><?=vv_h($label)?></small><strong><?=(int)$stats[$k]?></strong></div>
    <?php endforeach; ?>
  </section>
  <section class="vv-split">
    <div class="vv-card"><div class="panel-title">Chamadas por dia</div><canvas id="vvDaily"></canvas></div>
    <div class="vv-card"><div class="panel-title">Distribuicao por status</div><canvas id="vvStatus"></canvas></div>
  </section>
  <script>
  const vd=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>,vs=<?=json_encode($statusRows,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG)?>;
  if(window.Chart){new Chart(vvDaily,{type:'line',data:{labels:vd.map(x=>x.d),datasets:[{label:'Chamadas',data:vd.map(x=>+x.c),borderColor:'#38bdf8',backgroundColor:'rgba(56,189,248,.16)',fill:true,tension:.35},{label:'Concluidas',data:vd.map(x=>+x.done),borderColor:'#22c55e',tension:.35},{label:'Humanos',data:vd.map(x=>+x.human),borderColor:'#facc15',tension:.35}]},options:{plugins:{legend:{labels:{color:'#cbd5e1'}}},scales:{x:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}},y:{ticks:{color:'#64748b'},grid:{color:'rgba(255,255,255,.05)'}}}}});new Chart(vvStatus,{type:'doughnut',data:{labels:vs.map(x=>x.status),datasets:[{data:vs.map(x=>+x.c),backgroundColor:['#22c55e','#38bdf8','#facc15','#ef4444','#a78bfa','#f97316']}]},options:{plugins:{legend:{labels:{color:'#cbd5e1'}}}}})}
  </script>
<?php elseif($tab === 'settings'): ?>
  <section class="vv-card">
    <div class="card-header"><div><div class="card-header-title">Provedor de voz - Telnyx</div><p class="vv-note">A API key e criptografada no banco. A tela nunca exibe a chave completa.</p></div></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="save_provider">
      <div class="vv-form-grid">
        <label class="vv-field"><label>Status</label><select name="enabled"><option value="1" <?=!empty($provider['enabled'])?'selected':''?>>Ativo</option><option value="0" <?=empty($provider['enabled'])?'selected':''?>>Inativo</option></select></label>
        <label class="vv-field"><label>Ambiente</label><select name="environment"><option value="test" <?=$provider['environment']==='test'?'selected':''?>>Teste</option><option value="production" <?=$provider['environment']==='production'?'selected':''?>>Producao</option></select></label>
        <label class="vv-field"><label>API key <?=!empty($creds['api_key'])?'('.vv_h(voice_mask_secret(voice_decrypt_secret((string)$creds['api_key']))).')':''?></label><input type="password" name="api_key" autocomplete="new-password" placeholder="Cole somente se quiser substituir"></label>
        <label class="vv-field"><label>Public key do webhook</label><input name="public_key" value="<?=vv_h($cfg['public_key'] ?? '')?>"></label>
        <label class="vv-field"><label>Connection ID</label><input name="connection_id" value="<?=vv_h($cfg['connection_id'] ?? '')?>"></label>
        <label class="vv-field"><label>Outbound Voice Profile ID</label><input name="outbound_voice_profile_id" value="<?=vv_h($cfg['outbound_voice_profile_id'] ?? '')?>"></label>
        <label class="vv-field"><label>Organization ID</label><input name="organization_id" value="<?=vv_h($cfg['organization_id'] ?? '')?>"></label>
        <label class="vv-field"><label>Numero padrao de origem</label><input name="default_from_number" value="<?=vv_h($cfg['default_from_number'] ?? '')?>" placeholder="+5531999999999"></label>
        <label class="vv-field"><label>URL base publica</label><input name="webhook_base_url" value="<?=vv_h($cfg['webhook_base_url'] ?? rtrim(BASE_URL, '/'))?>"></label>
        <label class="vv-field"><label>Versao API</label><input name="api_version" value="<?=vv_h($cfg['api_version'] ?? 'v2')?>"></label>
        <label class="vv-field"><label>Timeout HTTP</label><input type="number" name="http_timeout" value="<?=vv_h($cfg['http_timeout'] ?? 15)?>"></label>
        <label class="vv-field"><label>Max. retries</label><input type="number" name="max_retries" value="<?=vv_h($cfg['max_retries'] ?? 1)?>"></label>
        <label class="vv-field"><label>Intervalo retry (seg.)</label><input type="number" name="retry_interval_seconds" value="<?=vv_h($cfg['retry_interval_seconds'] ?? 30)?>"></label>
        <label class="vv-field"><label>Concorrencia</label><input type="number" name="concurrency_limit" value="<?=vv_h($cfg['concurrency_limit'] ?? 1)?>"></label>
        <label class="vv-field"><label>Chamadas/minuto</label><input type="number" name="calls_per_minute" value="<?=vv_h($cfg['calls_per_minute'] ?? 10)?>"></label>
        <label class="vv-field"><label>Chamadas/hora</label><input type="number" name="calls_per_hour" value="<?=vv_h($cfg['calls_per_hour'] ?? 300)?>"></label>
        <label class="vv-field"><label>Chamadas/dia</label><input type="number" name="calls_per_day" value="<?=vv_h($cfg['calls_per_day'] ?? 1000)?>"></label>
        <label class="vv-field"><label>Limite diario de gasto</label><input type="number" step="0.01" name="daily_cost_limit" value="<?=vv_h($cfg['daily_cost_limit'] ?? 0)?>"></label>
        <label class="vv-field"><label>Duracao maxima padrao</label><input type="number" name="default_call_limit_secs" value="<?=vv_h($cfg['default_call_limit_secs'] ?? 120)?>"></label>
        <label class="vv-field"><label>Timeout atendimento</label><input type="number" name="default_timeout_secs" value="<?=vv_h($cfg['default_timeout_secs'] ?? 30)?>"></label>
        <label class="vv-field"><label>Codigo pais padrao</label><input name="default_country_code" value="<?=vv_h($cfg['default_country_code'] ?? '55')?>"></label>
        <label class="vv-field"><label>Fuso horario</label><input name="timezone" value="<?=vv_h($cfg['timezone'] ?? 'America/Sao_Paulo')?>"></label>
        <label class="vv-field"><label>Destinos permitidos</label><input name="allowed_destinations" value="<?=vv_h($cfg['allowed_destinations'] ?? '+55')?>"></label>
        <label class="vv-field"><label>Voz TTS padrao</label><input name="default_voice" value="<?=vv_h($cfg['default_voice'] ?? '')?>" placeholder="Conforme voz disponivel na Telnyx"></label>
        <label class="vv-field"><label>Idioma padrao</label><input name="default_language" value="<?=vv_h($cfg['default_language'] ?? 'pt-BR')?>"></label>
        <label class="vv-field"><label>Numeros autorizados para teste</label><textarea name="test_allowed_numbers" placeholder="+5531999999999, +5511999999999"><?=vv_h($cfg['test_allowed_numbers'] ?? '')?></textarea></label>
        <label class="vv-field"><label>Flags padrao</label><label><input type="checkbox" name="amd_default" <?=!empty($cfg['amd_default'])?'checked':''?>> AMD</label><label><input type="checkbox" name="record_calls_default" <?=!empty($cfg['record_calls_default'])?'checked':''?>> Gravar</label><label><input type="checkbox" name="transcribe_calls_default" <?=!empty($cfg['transcribe_calls_default'])?'checked':''?>> Transcrever</label><label><input type="checkbox" name="debug_mode" <?=!empty($cfg['debug_mode'])?'checked':''?>> Debug detalhado</label></label>
      </div>
      <div class="vv-actions mt-3"><button class="btn btn-primary" <?=$canWrite?'':'disabled'?>>Salvar configuracao</button></div>
    </form>
  </section>
  <section class="vv-split">
    <div class="vv-card"><div class="card-header-title">Webhooks para cadastrar na Telnyx</div><p class="vv-note">Principal</p><code class="vv-code"><?=vv_h($webhookUrl)?></code><p class="vv-note mt-3">Failover</p><code class="vv-code"><?=vv_h($failoverUrl)?></code></div>
    <div class="vv-card"><div class="card-header-title">Diagnostico e teste controlado</div><form method="post" class="vv-actions"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="test_connection"><button class="btn btn-ghost" <?=$canWrite?'':'disabled'?>>Testar conexao Telnyx</button></form><?php if($diagnostic): ?><div class="vv-diag"><div class="vv-actions"><span class="vv-pill <?=!empty($diagnostic['ready'])?'ok':'bad'?>"><?=!empty($diagnostic['ready'])?'Pronto para chamadas':'Com pendencias'?></span><span class="vv-pill ok">OK: <?=(int)($diagnostic['summary']['ok'] ?? 0)?></span><span class="vv-pill warn">Pendentes: <?=(int)($diagnostic['summary']['pending'] ?? 0)?></span><span class="vv-pill bad">Erros: <?=(int)($diagnostic['summary']['error'] ?? 0)?></span></div><?php foreach(($diagnostic['items'] ?? []) as $it): $st=(string)($it['status'] ?? 'pending'); ?><div class="vv-diag-row"><span class="vv-diag-icon <?=$st?>"><?=$st==='ok'?'✓':($st==='error'?'!':($st==='warning'?'?':'-'))?></span><div class="vv-diag-label"><?=vv_h($it['label'] ?? '')?></div><div class="vv-diag-detail"><?=vv_h($it['detail'] ?? '')?><?php if(!empty($it['meta']['url'])): ?><br><code><?=vv_h($it['meta']['url'])?></code><?php endif; ?></div></div><?php endforeach; ?></div><?php endif; ?><form method="post" class="mt-3" onsubmit="return confirm('Esta acao cria uma chamada real pela Telnyx e pode gerar custo. Continuar?')"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="test_call"><div class="vv-form-grid"><label class="vv-field"><label>Telefone autorizado</label><input name="test_phone" placeholder="+5531999999999"></label><label class="vv-field"><label>Audio URL opcional</label><input name="test_audio_url" placeholder="https://.../audio.mp3"></label><label class="vv-field"><label>Mensagem TTS de referencia</label><textarea name="test_message">Ola, esta e uma chamada de teste do Torpedo de Voz.</textarea></label><label class="vv-field"><label>Confirmacao</label><label><input type="checkbox" name="confirm_test_call" value="1"> Entendo que pode gerar custo</label></label></div><button class="btn btn-danger mt-3" <?=$canWrite?'':'disabled'?>>Fazer chamada de teste</button></form></div>
  </section>
<?php elseif($tab === 'numbers'): ?>
  <section class="vv-card"><div class="card-header-title">Numeros de telefone</div><form method="post" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="save_number"><label class="vv-field"><label>Nome</label><input name="friendly_name"></label><label class="vv-field"><label>Numero E.164</label><input name="phone_e164" placeholder="+5531999999999"></label><label class="vv-field"><label>Pais</label><input name="country" value="BR"></label><label class="vv-field"><label>Regiao</label><input name="region"></label><label class="vv-field"><label>Tipo</label><input name="type" value="voice"></label><label class="vv-field"><label>Origem</label><select name="source_type"><option value="telnyx_owned">Telnyx comprado</option><option value="verified_external">Externo verificado</option><option value="ported">Portado</option><option value="sip">SIP</option><option value="manual">Manual</option></select></label><label class="vv-field"><label>Connection ID</label><input name="connection_id"></label><label class="vv-field"><label>Outbound Profile</label><input name="outbound_profile_id"></label><label class="vv-field"><label>Flags</label><label><input type="checkbox" name="outbound_enabled" checked> Origina</label><label><input type="checkbox" name="inbound_enabled"> Recebe</label><label><input type="checkbox" name="is_default"> Padrao</label></label><label class="vv-field"><label>Observacoes</label><textarea name="notes"></textarea></label><div><button class="btn btn-primary mt-3" <?=$canWrite?'':'disabled'?>>Salvar numero</button></div></form></section>
  <section class="vv-card vv-table"><table><thead><tr><th>Numero</th><th>Nome</th><th>Origem</th><th>Status</th><th>Padrao</th><th>Outbound</th></tr></thead><tbody><?php foreach($numbers as $n): ?><tr><td><?=vv_h($n['phone_e164'])?></td><td><?=vv_h($n['friendly_name'])?></td><td><?=vv_h($n['source_type'])?></td><td><span class="vv-pill"><?=vv_h($n['status'])?></span></td><td><?=!empty($n['is_default'])?'Sim':'-'?></td><td><?=!empty($n['outbound_enabled'])?'Sim':'-'?></td></tr><?php endforeach; ?><?php if(!$numbers): ?><tr><td colspan="6">Nenhum numero cadastrado.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'media'): ?>
  <section class="vv-card"><div class="card-header-title">Biblioteca de audios</div><form method="post" enctype="multipart/form-data" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="upload_media"><label class="vv-field"><label>Nome</label><input name="name" placeholder="Aviso de live"></label><label class="vv-field"><label>Arquivo MP3/WAV</label><input type="file" name="audio" accept=".mp3,.wav,audio/mpeg,audio/wav"></label><div><button class="btn btn-primary mt-3" <?=$canWrite?'':'disabled'?>>Enviar audio</button></div></form></section>
  <section class="vv-card vv-table"><table><thead><tr><th>Audio</th><th>Tipo</th><th>Tamanho</th><th>URL publica</th></tr></thead><tbody><?php foreach($media as $m): ?><tr><td><?=vv_h($m['name'])?></td><td><?=vv_h($m['mime_type'])?></td><td><?=number_format(((int)$m['file_size'])/1024,1,',','.')?> KB</td><td><code class="vv-code"><?=vv_h($m['public_url'])?></code></td></tr><?php endforeach; ?><?php if(!$media): ?><tr><td colspan="4">Nenhum audio enviado.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'new'): ?>
  <section class="vv-card"><div class="card-header-title">Nova campanha</div><p class="vv-note">Nesta fase a campanha nasce como rascunho. A fila grande fica para o worker, nunca para a requisicao web.</p><form method="post" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="create_campaign"><label class="vv-field"><label>Nome</label><input name="name" required></label><label class="vv-field"><label>Descricao</label><input name="description"></label><label class="vv-field"><label>Modo</label><select name="message_mode"><option value="text_to_speech">Texto para voz</option><option value="uploaded_audio">Audio enviado</option><option value="ai_assistant">IA assistente</option></select></label><label class="vv-field"><label>Concorrencia</label><input type="number" name="concurrency_limit" value="1"></label><label class="vv-field"><label>Chamadas/minuto</label><input type="number" name="calls_per_minute" value="10"></label><label class="vv-field"><label>Tentativas</label><input type="number" name="max_attempts" value="1"></label><label class="vv-field"><label>Mensagem humano</label><textarea name="message_template" placeholder="Ola, {{primeiro_nome|aluno}}..."></textarea></label><label class="vv-field"><label>Mensagem caixa postal</label><textarea name="machine_message_template"></textarea></label><label class="vv-field"><label>Opcoes</label><label><input type="checkbox" name="answering_machine_detection"> AMD</label><label><input type="checkbox" name="record_calls"> Gravar</label><label><input type="checkbox" name="transcribe_calls"> Transcrever</label></label><div><button class="btn btn-primary mt-3" <?=$canWrite?'':'disabled'?>>Criar rascunho</button></div></form></section>
<?php elseif($tab === 'campaigns'): ?>
  <section class="vv-card vv-table"><table><thead><tr><th>Campanha</th><th>Status</th><th>Modo</th><th>Limites</th><th>Criada</th></tr></thead><tbody><?php foreach($campaigns as $c): ?><tr><td><strong><?=vv_h($c['name'])?></strong><div class="text-muted"><?=vv_h($c['description'])?></div></td><td><span class="vv-pill"><?=vv_h($c['status'])?></span></td><td><?=vv_h($c['message_mode'])?></td><td><?=vv_h($c['concurrency_limit'])?> simult. / <?=vv_h($c['calls_per_minute'])?> min</td><td><?=vv_h($c['created_at'])?></td></tr><?php endforeach; ?><?php if(!$campaigns): ?><tr><td colspan="5">Nenhuma campanha criada.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'calls' || $tab === 'reports'): ?>
  <section class="vv-card vv-table"><table><thead><tr><th>ID</th><th>Destino</th><th>Origem</th><th>Status</th><th>Humano/maquina</th><th>Dura.</th><th>Custo</th><th>Criada</th><th>Erro</th></tr></thead><tbody><?php foreach($calls as $c): ?><tr><td>#<?=(int)$c['id']?></td><td><?=vv_h(voice_mask_phone($c['to_number']))?></td><td><?=vv_h($c['from_number'])?></td><td><span class="vv-pill"><?=vv_h($c['status'])?></span></td><td><?=vv_h($c['answered_by'] ?: '-')?></td><td><?=vv_h($c['duration_seconds'] ?: '-')?></td><td><?=vv_h($c['cost'] ?: '-')?></td><td><?=vv_h($c['created_at'])?></td><td class="text-muted"><?=vv_h(mb_substr((string)$c['error_json'],0,180))?></td></tr><?php endforeach; ?><?php if(!$calls): ?><tr><td colspan="9">Nenhuma chamada registrada.</td></tr><?php endif; ?></tbody></table></section>
<?php elseif($tab === 'webhooks'): ?>
  <section class="vv-split"><div class="vv-card vv-table"><div class="card-header-title">Eventos normalizados</div><table><thead><tr><th>Evento</th><th>Normalizado</th><th>Attempt</th><th>Recebido</th></tr></thead><tbody><?php foreach($events as $e): ?><tr><td><?=vv_h($e['event_type'])?></td><td><?=vv_h($e['normalized_event'])?></td><td><?=vv_h($e['attempt_id'] ?: '-')?></td><td><?=vv_h($e['received_at'])?></td></tr><?php endforeach; ?><?php if(!$events): ?><tr><td colspan="4">Nenhum evento.</td></tr><?php endif; ?></tbody></table></div><div class="vv-card vv-table"><div class="card-header-title">Logs de webhook</div><table><thead><tr><th>ID evento</th><th>Ass.</th><th>HTTP</th><th>Status</th><th>Erro</th></tr></thead><tbody><?php foreach($webhooks as $w): ?><tr><td><?=vv_h($w['event_id'] ?: '-')?></td><td><?=!empty($w['signature_valid'])?'OK':'-'?></td><td><?=(int)$w['http_status']?></td><td><?=vv_h($w['processing_status'])?></td><td class="text-muted"><?=vv_h($w['error'])?></td></tr><?php endforeach; ?><?php if(!$webhooks): ?><tr><td colspan="5">Nenhum webhook recebido.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif($tab === 'suppression'): ?>
  <section class="vv-card"><div class="card-header-title">Lista de bloqueio</div><form method="post" class="vv-form-grid"><input type="hidden" name="csrf" value="<?=vv_h($csrf)?>"><input type="hidden" name="action" value="add_suppression"><label class="vv-field"><label>Telefone</label><input name="phone_e164" placeholder="+5531999999999"></label><label class="vv-field"><label>Motivo</label><input name="reason" value="manual"></label><label class="vv-field"><label>Notas</label><input name="notes"></label><div><button class="btn btn-danger mt-3" <?=$canWrite?'':'disabled'?>>Nao ligar novamente</button></div></form></section>
  <section class="vv-card vv-table"><table><thead><tr><th>Telefone</th><th>Motivo</th><th>Origem</th><th>Criado por</th><th>Data</th></tr></thead><tbody><?php foreach($suppression as $s): ?><tr><td><?=vv_h(voice_mask_phone($s['phone_e164']))?></td><td><?=vv_h($s['reason'])?></td><td><?=vv_h($s['source'])?></td><td><?=vv_h($s['created_by'])?></td><td><?=vv_h($s['created_at'])?></td></tr><?php endforeach; ?><?php if(!$suppression): ?><tr><td colspan="5">Nenhum telefone bloqueado.</td></tr><?php endif; ?></tbody></table></section>
<?php else: ?>
  <section class="vv-card"><div class="card-header-title"><?=vv_h(['contacts'=>'Contatos e listas','ai'=>'Inteligencia Artificial'][$tab] ?? 'Secao')?></div><p class="vv-note">Estrutura reservada para a proxima fase. Ela vai reutilizar as tabelas e logs reais criados agora, sem dados ficticios permanentes.</p></section>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
