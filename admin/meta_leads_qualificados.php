<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/meta_qualified_leads.php';
proteger_admin();

$pdo = getPDO();
mql_ensure_schema($pdo);
$menu = 'meta_leads';
$page_title = 'Meta Leads Qualificados';

function ml_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ml_badge(string $status): string {
    $class = ['sent'=>'success','pending'=>'warning','retry'=>'info','failed'=>'danger'][$status] ?? 'neutral';
    return '<span class="badge badge-' . $class . '">' . ml_h($status) . '</span>';
}
function ml_csv(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $value) ?: [])));
}

$message = $error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_dataset') {
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => trim((string)($_POST['name'] ?? '')),
                'dataset_id' => preg_replace('/\D+/', '', (string)($_POST['dataset_id'] ?? '')),
                'access_token' => trim((string)($_POST['access_token'] ?? '')),
                'api_version' => trim((string)($_POST['api_version'] ?? 'v25.0')) ?: 'v25.0',
                'event_name' => trim((string)($_POST['event_name'] ?? 'Lead')) ?: 'Lead',
                'lead_event_source' => trim((string)($_POST['lead_event_source'] ?? 'Area de Membros CRM')) ?: 'Area de Membros CRM',
                'test_event_code' => trim((string)($_POST['test_event_code'] ?? '')) ?: null,
                'mode' => (string)($_POST['mode'] ?? 'production') === 'test' ? 'test' : 'production',
                'active' => !empty($_POST['active']) ? 1 : 0,
            ];
            if ($data['name'] === '' || $data['dataset_id'] === '' || $data['access_token'] === '') throw new RuntimeException('Nome, dataset ID e token sao obrigatorios.');
            if ($id > 0) {
                $pdo->prepare("UPDATE meta_qualified_datasets SET name=:name,dataset_id=:dataset_id,access_token=:access_token,api_version=:api_version,event_name=:event_name,lead_event_source=:lead_event_source,test_event_code=:test_event_code,mode=:mode,active=:active WHERE id=:id")
                    ->execute($data + ['id' => $id]);
                $message = 'Conjunto atualizado.';
            } else {
                $pdo->prepare("INSERT INTO meta_qualified_datasets (name,dataset_id,access_token,api_version,event_name,lead_event_source,test_event_code,mode,active) VALUES (:name,:dataset_id,:access_token,:api_version,:event_name,:lead_event_source,:test_event_code,:mode,:active)")
                    ->execute($data);
                $message = 'Conjunto criado.';
            }
        } elseif ($action === 'toggle_dataset') {
            $pdo->prepare("UPDATE meta_qualified_datasets SET active=1-active WHERE id=:id")->execute(['id' => (int)$_POST['id']]);
            $message = 'Conjunto atualizado.';
        } elseif ($action === 'save_trigger') {
            $id = (int)($_POST['id'] ?? 0);
            $conditions = [
                'trigger_tag' => trim((string)($_POST['trigger_tag'] ?? '')),
                'include_tags' => ml_csv((string)($_POST['include_tags'] ?? '')),
                'exclude_tags' => ml_csv((string)($_POST['exclude_tags'] ?? '')),
                'turma' => trim((string)($_POST['turma'] ?? '')),
                'require_email' => !empty($_POST['require_email']),
                'require_phone' => !empty($_POST['require_phone']),
            ];
            $data = [
                'dataset_id' => (int)($_POST['dataset_id'] ?? 0),
                'name' => trim((string)($_POST['name'] ?? '')),
                'event_type' => (string)($_POST['event_type'] ?? 'tag_added') === 'manual_scan' ? 'manual_scan' : 'tag_added',
                'conditions_json' => json_encode($conditions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'active' => !empty($_POST['active']) ? 1 : 0,
            ];
            if ($data['dataset_id'] <= 0 || $data['name'] === '') throw new RuntimeException('Informe conjunto e nome do gatilho.');
            if ($id > 0) {
                $pdo->prepare("UPDATE meta_qualified_triggers SET dataset_id=:dataset_id,name=:name,event_type=:event_type,conditions_json=:conditions_json,active=:active WHERE id=:id")
                    ->execute($data + ['id' => $id]);
                $message = 'Gatilho atualizado.';
            } else {
                $pdo->prepare("INSERT INTO meta_qualified_triggers (dataset_id,name,event_type,conditions_json,active) VALUES (:dataset_id,:name,:event_type,:conditions_json,:active)")
                    ->execute($data);
                $message = 'Gatilho criado.';
            }
        } elseif ($action === 'toggle_trigger') {
            $pdo->prepare("UPDATE meta_qualified_triggers SET active=1-active WHERE id=:id")->execute(['id' => (int)$_POST['id']]);
            $message = 'Gatilho atualizado.';
        } elseif ($action === 'scan_trigger') {
            $res = mql_scan_trigger($pdo, (int)($_POST['id'] ?? 0), 3000);
            $message = 'Varredura concluida: ' . (int)$res['checked'] . ' leads avaliados, ' . (int)$res['queued'] . ' enfileirados.';
        } elseif ($action === 'process_now') {
            $res = mql_process_queue($pdo, 80);
            $message = 'Fila processada: ' . (int)$res['sent'] . ' enviados, ' . (int)$res['retry'] . ' para tentar novamente, ' . (int)$res['failed'] . ' falhas.';
        } elseif ($action === 'retry_queue') {
            $pdo->prepare("UPDATE meta_qualified_queue SET status='retry', next_attempt_at=NOW(), last_error=NULL WHERE id=:id AND status='failed'")->execute(['id' => (int)$_POST['id']]);
            $message = 'Item marcado para reenvio.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$datasets = mql_rows($pdo, "SELECT * FROM meta_qualified_datasets ORDER BY id DESC");
$triggers = mql_rows($pdo, "SELECT t.*, d.name dataset_name FROM meta_qualified_triggers t LEFT JOIN meta_qualified_datasets d ON d.id=t.dataset_id ORDER BY t.id DESC");
$queue = mql_rows($pdo, "SELECT q.*, d.name dataset_name, t.name trigger_name, u.nome, u.email FROM meta_qualified_queue q LEFT JOIN meta_qualified_datasets d ON d.id=q.dataset_id LEFT JOIN meta_qualified_triggers t ON t.id=q.trigger_id LEFT JOIN users u ON u.id=q.user_id ORDER BY q.id DESC LIMIT 120");
$logs = mql_rows($pdo, "SELECT l.*, d.name dataset_name, u.nome FROM meta_qualified_logs l LEFT JOIN meta_qualified_datasets d ON d.id=l.dataset_id LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 120");
$totals = mql_row($pdo, "SELECT COUNT(*) total, SUM(status='sent') sent, SUM(status IN ('pending','retry')) pending, SUM(status='failed') failed FROM meta_qualified_queue");
$daily = mql_rows($pdo, "SELECT DATE(created_at) day, COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed FROM meta_qualified_queue WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day");
$editDataset = [];
if (isset($_GET['dataset'])) $editDataset = mql_row($pdo, "SELECT * FROM meta_qualified_datasets WHERE id=:id", ['id' => (int)$_GET['dataset']]);
$editTrigger = [];
if (isset($_GET['trigger'])) $editTrigger = mql_row($pdo, "SELECT * FROM meta_qualified_triggers WHERE id=:id", ['id' => (int)$_GET['trigger']]);
$editConditions = mql_json($editTrigger['conditions_json'] ?? null);

include __DIR__ . '/_header.php';
?>
<style>
.ml{display:flex;flex-direction:column;gap:16px}.ml-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.ml-head h1{font-size:22px;margin:0}.ml-head p{margin:4px 0 0;color:var(--muted);font-size:12px}.ml-grid{display:grid;grid-template-columns:minmax(320px,.9fr) minmax(0,1.4fr);gap:14px}.ml-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-xl);padding:16px}.ml-card h2{font-size:15px;margin:0 0 12px}.ml-form{display:grid;gap:10px}.ml-row{display:grid;grid-template-columns:1fr 1fr;gap:9px}.ml-check{display:flex;gap:8px;align-items:center;font-size:12px;color:var(--muted)}.ml-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.ml-table{width:100%;border-collapse:collapse;min-width:900px}.ml-table th,.ml-table td{padding:9px;border-bottom:1px solid var(--border);font-size:11px;vertical-align:top}.ml-table th{color:var(--muted);text-transform:uppercase;font-size:9px}.ml-scroll{overflow:auto}.ml-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.ml-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px}.ml-kpi small{color:var(--muted);text-transform:uppercase;font-size:9px}.ml-kpi strong{display:block;font-size:24px}.ml-chart{height:250px}.ml-code{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted)}@media(max-width:1000px){.ml-grid{grid-template-columns:1fr}.ml-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.ml-row{grid-template-columns:1fr}.ml-kpis{grid-template-columns:1fr}}
</style>
<div class="ml">
  <div class="ml-head"><div><h1>Meta Leads Qualificados</h1><p>Configure eventos CRM para enviar leads qualificados para a API de Conversoes da Meta.</p></div><form method="post"><button class="btn btn-primary" name="action" value="process_now">Processar fila agora</button></form></div>
  <?php if($message):?><div class="alert alert-ok"><?=ml_h($message)?></div><?php endif; ?>
  <?php if($error):?><div class="alert alert-error"><?=ml_h($error)?></div><?php endif; ?>

  <div class="ml-kpis">
    <div class="ml-kpi"><small>Total na fila</small><strong><?=number_format((float)($totals['total']??0),0,',','.')?></strong></div>
    <div class="ml-kpi"><small>Enviados</small><strong><?=number_format((float)($totals['sent']??0),0,',','.')?></strong></div>
    <div class="ml-kpi"><small>Pendentes</small><strong><?=number_format((float)($totals['pending']??0),0,',','.')?></strong></div>
    <div class="ml-kpi"><small>Falhas</small><strong><?=number_format((float)($totals['failed']??0),0,',','.')?></strong></div>
  </div>
  <section class="ml-card"><h2>Indicadores dos ultimos 30 dias</h2><div class="ml-chart"><canvas id="mqlChart"></canvas></div></section>

  <div class="ml-grid">
    <section class="ml-card">
      <h2><?= $editDataset ? 'Editar conjunto de dados' : 'Novo conjunto de dados' ?></h2>
      <form method="post" class="ml-form">
        <input type="hidden" name="action" value="save_dataset"><input type="hidden" name="id" value="<?= (int)($editDataset['id'] ?? 0) ?>">
        <label>Nome interno<input name="name" required value="<?=ml_h($editDataset['name'] ?? '')?>" placeholder="Lead Qualificado - CRM"></label>
        <div class="ml-row"><label>Dataset ID<input name="dataset_id" required value="<?=ml_h($editDataset['dataset_id'] ?? '')?>" placeholder="1182793990703954"></label><label>API version<input name="api_version" value="<?=ml_h($editDataset['api_version'] ?? 'v25.0')?>"></label></div>
        <label>Access token<textarea name="access_token" required rows="3" placeholder="EAAB..."><?=ml_h($editDataset['access_token'] ?? '')?></textarea></label>
        <div class="ml-row"><label>Event name<input name="event_name" value="<?=ml_h($editDataset['event_name'] ?? 'Lead')?>"></label><label>Lead event source<input name="lead_event_source" value="<?=ml_h($editDataset['lead_event_source'] ?? 'Area de Membros CRM')?>"></label></div>
        <div class="ml-row"><label>Test event code<input name="test_event_code" value="<?=ml_h($editDataset['test_event_code'] ?? '')?>"></label><label>Modo<select name="mode"><option value="production"<?=($editDataset['mode']??'')==='production'?' selected':''?>>Producao</option><option value="test"<?=($editDataset['mode']??'')==='test'?' selected':''?>>Teste</option></select></label></div>
        <label class="ml-check"><input type="checkbox" name="active" value="1" <?=((int)($editDataset['active'] ?? 1)===1)?'checked':''?>> Ativo</label>
        <div class="ml-actions"><button class="btn btn-primary">Salvar conjunto</button><?php if($editDataset):?><a class="btn btn-ghost" href="meta_leads_qualificados.php">Cancelar</a><?php endif;?></div>
      </form>
    </section>
    <section class="ml-card">
      <h2>Conjuntos cadastrados</h2>
      <div class="ml-scroll"><table class="ml-table"><thead><tr><th>Nome</th><th>Dataset</th><th>Evento</th><th>Modo</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach($datasets as $d):?><tr><td><strong><?=ml_h($d['name'])?></strong></td><td><?=ml_h($d['dataset_id'])?></td><td><?=ml_h($d['event_name'])?></td><td><?=ml_h($d['mode'])?></td><td><?=((int)$d['active']===1)?'<span class="badge badge-success">ativo</span>':'<span class="badge badge-neutral">inativo</span>'?></td><td class="ml-actions"><a class="btn btn-xs btn-ghost" href="?dataset=<?=(int)$d['id']?>">Editar</a><form method="post"><input type="hidden" name="id" value="<?=(int)$d['id']?>"><button class="btn btn-xs btn-ghost" name="action" value="toggle_dataset">Ativar/inativar</button></form></td></tr><?php endforeach; ?>
        <?php if(!$datasets):?><tr><td colspan="6">Nenhum conjunto cadastrado.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </div>

  <div class="ml-grid">
    <section class="ml-card">
      <h2><?= $editTrigger ? 'Editar gatilho' : 'Novo gatilho de qualificacao' ?></h2>
      <form method="post" class="ml-form">
        <input type="hidden" name="action" value="save_trigger"><input type="hidden" name="id" value="<?= (int)($editTrigger['id'] ?? 0) ?>">
        <label>Nome do gatilho<input name="name" required value="<?=ml_h($editTrigger['name'] ?? '')?>" placeholder="Tag LEAD_QUALIFICADO"></label>
        <div class="ml-row"><label>Conjunto<select name="dataset_id" required><option value="">Selecione</option><?php foreach($datasets as $d):?><option value="<?=(int)$d['id']?>"<?=((int)($editTrigger['dataset_id']??0)===(int)$d['id'])?' selected':''?>><?=ml_h($d['name'])?></option><?php endforeach;?></select></label><label>Tipo<select name="event_type"><option value="tag_added"<?=($editTrigger['event_type']??'')==='tag_added'?' selected':''?>>Quando tag for aplicada</option><option value="manual_scan"<?=($editTrigger['event_type']??'')==='manual_scan'?' selected':''?>>Somente varredura manual</option></select></label></div>
        <label>Tag gatilho<input name="trigger_tag" value="<?=ml_h($editConditions['trigger_tag'] ?? '')?>" placeholder="LEAD_QUALIFICADO"></label>
        <div class="ml-row"><label>Tags obrigatorias<textarea name="include_tags" rows="2" placeholder="VIP, QUENTE"><?=ml_h(implode(', ', (array)($editConditions['include_tags'] ?? [])))?></textarea></label><label>Tags de exclusao<textarea name="exclude_tags" rows="2" placeholder="CLIENTE, DESCARTADO"><?=ml_h(implode(', ', (array)($editConditions['exclude_tags'] ?? [])))?></textarea></label></div>
        <label>Turma especifica<input name="turma" value="<?=ml_h($editConditions['turma'] ?? '')?>" placeholder="Opcional"></label>
        <div class="ml-row"><label class="ml-check"><input type="checkbox" name="require_email" value="1" <?=!empty($editConditions['require_email'])?'checked':''?>> Exigir e-mail</label><label class="ml-check"><input type="checkbox" name="require_phone" value="1" <?=!empty($editConditions['require_phone'])?'checked':''?>> Exigir telefone</label></div>
        <label class="ml-check"><input type="checkbox" name="active" value="1" <?=((int)($editTrigger['active'] ?? 1)===1)?'checked':''?>> Ativo</label>
        <div class="ml-actions"><button class="btn btn-primary">Salvar gatilho</button><?php if($editTrigger):?><a class="btn btn-ghost" href="meta_leads_qualificados.php">Cancelar</a><?php endif;?></div>
      </form>
    </section>
    <section class="ml-card">
      <h2>Gatilhos cadastrados</h2>
      <div class="ml-scroll"><table class="ml-table"><thead><tr><th>Gatilho</th><th>Conjunto</th><th>Tipo</th><th>Condicoes</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach($triggers as $t): $c=mql_json($t['conditions_json']??null); ?><tr><td><strong><?=ml_h($t['name'])?></strong></td><td><?=ml_h($t['dataset_name'] ?: '-')?></td><td><?=ml_h($t['event_type'])?></td><td class="ml-code">Tag: <?=ml_h($c['trigger_tag'] ?? '-')?> · inclui <?=ml_h(implode(', ', (array)($c['include_tags'] ?? [])))?></td><td><?=((int)$t['active']===1)?'<span class="badge badge-success">ativo</span>':'<span class="badge badge-neutral">inativo</span>'?></td><td class="ml-actions"><a class="btn btn-xs btn-ghost" href="?trigger=<?=(int)$t['id']?>">Editar</a><form method="post"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><button class="btn btn-xs btn-ghost" name="action" value="scan_trigger">Varrer</button></form><form method="post"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><button class="btn btn-xs btn-ghost" name="action" value="toggle_trigger">Ativar/inativar</button></form></td></tr><?php endforeach; ?>
        <?php if(!$triggers):?><tr><td colspan="6">Nenhum gatilho cadastrado.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </div>

  <section class="ml-card"><h2>Fila de envios</h2><div class="ml-scroll"><table class="ml-table"><thead><tr><th>Data</th><th>Lead</th><th>Conjunto</th><th>Gatilho</th><th>Status</th><th>HTTP</th><th>Erro/resposta</th><th></th></tr></thead><tbody>
    <?php foreach($queue as $q):?><tr><td><?=ml_h(date('d/m/Y H:i',strtotime((string)$q['created_at'])))?></td><td><strong><?=ml_h($q['nome'] ?: ('#'.$q['user_id']))?></strong><div class="text-muted text-xs"><?=ml_h($q['email'] ?? '')?></div></td><td><?=ml_h($q['dataset_name'] ?: '-')?></td><td><?=ml_h($q['trigger_name'] ?: '-')?></td><td><?=ml_badge((string)$q['status'])?></td><td><?=ml_h($q['last_http_status'] ?: '-')?></td><td class="ml-code"><?=ml_h($q['last_error'] ?: $q['last_response'])?></td><td><?php if($q['status']==='failed'):?><form method="post"><input type="hidden" name="id" value="<?=(int)$q['id']?>"><button class="btn btn-xs btn-ghost" name="action" value="retry_queue">Reenviar</button></form><?php endif;?></td></tr><?php endforeach; ?>
    <?php if(!$queue):?><tr><td colspan="8">Fila vazia.</td></tr><?php endif; ?>
  </tbody></table></div></section>

  <section class="ml-card"><h2>Logs</h2><div class="ml-scroll"><table class="ml-table"><thead><tr><th>Data</th><th>Nivel</th><th>Mensagem</th><th>Lead</th><th>Conjunto</th><th>HTTP</th><th>Detalhe</th></tr></thead><tbody>
    <?php foreach($logs as $l):?><tr><td><?=ml_h(date('d/m/Y H:i:s',strtotime((string)$l['created_at'])))?></td><td><?=ml_h($l['level'])?></td><td><?=ml_h($l['message'])?></td><td><?=ml_h($l['nome'] ?: ($l['user_id'] ?: '-'))?></td><td><?=ml_h($l['dataset_name'] ?: '-')?></td><td><?=ml_h($l['http_status'] ?: '-')?></td><td class="ml-code"><?=ml_h($l['error_message'] ?: $l['response_json'])?></td></tr><?php endforeach; ?>
    <?php if(!$logs):?><tr><td colspan="7">Nenhum log.</td></tr><?php endif; ?>
  </tbody></table></div></section>
</div>
<script>
(()=>{if(!window.Chart)return;const daily=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,ticks='#64748b';new Chart(document.getElementById('mqlChart'),{data:{labels:daily.map(x=>String(x.day).slice(5)),datasets:[{type:'bar',label:'Total',data:daily.map(x=>+x.total||0),backgroundColor:'rgba(56,189,248,.35)'},{type:'line',label:'Enviados',data:daily.map(x=>+x.sent||0),borderColor:'#22c55e',backgroundColor:'#22c55e',tension:.3},{type:'line',label:'Falhas',data:daily.map(x=>+x.failed||0),borderColor:'#ef4444',backgroundColor:'#ef4444',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:ticks,boxWidth:10}}},scales:{x:{ticks:{color:ticks},grid:{display:false}},y:{beginAtZero:true,ticks:{color:ticks,precision:0},grid:{color:'rgba(255,255,255,.06)'}}}}});})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
