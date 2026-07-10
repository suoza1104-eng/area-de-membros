<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/meta_qualified_leads.php';
proteger_admin();

$pdo = getPDO();
mql_ensure_schema($pdo);
$menu = 'meta_leads';
$page_title = 'Meta Leads Qualificados';
$view = (string)($_GET['view'] ?? (isset($_GET['dataset']) ? 'settings' : (isset($_GET['trigger']) ? 'triggers' : 'overview')));
if (!in_array($view, ['overview','settings','triggers','test','queue','logs'], true)) $view = 'overview';

function ml_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ml_badge(string $status): string {
    $class = ['sent'=>'success','pending'=>'warning','retry'=>'info','failed'=>'danger'][$status] ?? 'neutral';
    return '<span class="badge badge-' . $class . '">' . ml_h($status) . '</span>';
}
function ml_csv(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $value) ?: [])));
}
function ml_meta_error_summary(?string $response): array {
    $json = json_decode((string)$response, true);
    $error = is_array($json) && isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
    $fields = ['message','type','code','error_subcode','error_user_title','error_user_msg','fbtrace_id'];
    $out = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $error) && $error[$field] !== null && $error[$field] !== '') {
            $out[$field] = is_scalar($error[$field]) ? (string)$error[$field] : json_encode($error[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    return $out;
}

$message = $error = '';
$testResult = null;
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
            if ($data['name'] === '' || $data['dataset_id'] === '') throw new RuntimeException('Nome e dataset ID sao obrigatorios.');
            if ($id === 0 && $data['access_token'] === '') throw new RuntimeException('Token e obrigatorio para criar um conjunto.');
            if ($id > 0) {
                $sql = "UPDATE meta_qualified_datasets SET name=:name,dataset_id=:dataset_id,api_version=:api_version,event_name=:event_name,lead_event_source=:lead_event_source,test_event_code=:test_event_code,mode=:mode,active=:active";
                $params = $data + ['id' => $id];
                if ($data['access_token'] !== '') $sql .= ",access_token=:access_token"; else unset($params['access_token']);
                $sql .= " WHERE id=:id";
                $pdo->prepare($sql)->execute($params);
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
        } elseif ($action === 'send_test_lead') {
            $view = 'test';
            $datasetId = (int)($_POST['dataset_id'] ?? 0);
            $leadId = preg_replace('/\D+/', '', (string)($_POST['meta_lead_id'] ?? '')) ?: '';
            $name = trim((string)($_POST['test_name'] ?? ''));
            $email = mb_strtolower(trim((string)($_POST['test_email'] ?? '')));
            $phone = preg_replace('/\D+/', '', (string)($_POST['test_phone'] ?? '')) ?: '';
            if ($datasetId <= 0) throw new RuntimeException('Selecione um dataset configurado.');
            if ($leadId === '') throw new RuntimeException('Informe o Lead ID da Meta.');
            if ($name === '') throw new RuntimeException('Informe o nome do lead de teste.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail valido.');
            if (strlen($phone) < 8) throw new RuntimeException('Informe um telefone valido.');
            $dataset = mql_row($pdo, "SELECT * FROM meta_qualified_datasets WHERE id=:id AND active=1", ['id' => $datasetId]);
            if (!$dataset) throw new RuntimeException('Dataset nao encontrado ou inativo.');
            $emailHash = mql_hash_email($email);
            $phoneHash = mql_hash_phone($phone);
            if (!$emailHash || !$phoneHash) throw new RuntimeException('Nao foi possivel gerar hash de e-mail ou telefone.');
            $payload = [
                'data' => [[
                    'action_source' => 'system_generated',
                    'event_name' => (string)($dataset['event_name'] ?: 'Lead'),
                    'event_time' => time(),
                    'custom_data' => [
                        'event_source' => 'crm',
                        'lead_event_source' => (string)($dataset['lead_event_source'] ?: 'Area de Membros CRM'),
                    ],
                    'user_data' => [
                        'lead_id' => (int)$leadId,
                        'em' => [$emailHash],
                        'ph' => [$phoneHash],
                    ],
                ]],
            ];
            if (trim((string)($dataset['test_event_code'] ?? '')) !== '') $payload['test_event_code'] = trim((string)$dataset['test_event_code']);
            $url = 'https://graph.facebook.com/' . rawurlencode((string)$dataset['api_version']) . '/' . rawurlencode((string)$dataset['dataset_id']) . '/events?access_token=' . rawurlencode((string)$dataset['access_token']);
            $res = mql_http_post_json($url, $payload);
            $ok = $res['status'] >= 200 && $res['status'] < 300 && !$res['error'];
            mql_log($pdo, null, (int)$dataset['id'], null, null, $ok ? 'info' : 'error', $ok ? 'Teste manual enviado para a Meta' : 'Falha no teste manual da Meta', $res['status'] ?: null, $payload, $res['body'], $res['error']);
            $testResult = ['ok' => $ok, 'payload' => $payload, 'status' => $res['status'], 'response' => $res['body'], 'error' => $res['error']];
            $message = $ok ? 'Lead de teste enviado com sucesso.' : 'A Meta retornou erro no envio de teste.';
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
$allTags = [];
try {
    $allTags = $pdo->query("SELECT nome FROM tags WHERE ativo=1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    try {
        $allTags = $pdo->query("SELECT nome FROM tags ORDER BY nome ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $ignored) {
        $allTags = [];
    }
}

include __DIR__ . '/_header.php';
?>
<style>
.ml{display:flex;flex-direction:column;gap:16px}.ml-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.ml-head h1{font-size:22px;margin:0}.ml-head p{margin:4px 0 0;color:var(--muted);font-size:12px}.ml-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px}.ml-nav a{padding:8px 11px;border-radius:9px;color:var(--muted);font-size:12px;text-decoration:none}.ml-nav a.active,.ml-nav a:hover{background:var(--primary-dim);color:var(--primary)}.ml-section{display:none}.ml-section.active{display:block}.ml-grid{display:grid;grid-template-columns:minmax(330px,.9fr) minmax(0,1.4fr);gap:14px}.ml-card{background:#0b1120;border:1px solid rgba(96,165,250,.18);border-radius:14px;padding:18px 20px;box-shadow:var(--shadow)}.ml-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:16px}.ml-card h2{font-size:16px;margin:0}.ml-card p{font-size:12px;color:var(--muted);margin:4px 0 0}.ml-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(168,85,247,.15);color:#facc15}.ml-form{display:grid;gap:12px}.ml-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ml label{display:block;font-size:10px;color:#7c8eb4;text-transform:uppercase;letter-spacing:.07em}.ml input:not([type]),.ml input[type=text],.ml input[type=number],.ml input[type=url],.ml input[type=email],.ml textarea,.ml select{display:block;margin-top:6px;width:100%;background:#081120!important;border:1px solid #1d3355!important;color:#e2e8f0!important;border-radius:10px;padding:10px 12px;font-size:13px;box-shadow:none!important}.ml textarea{min-height:82px}.ml input::placeholder,.ml textarea::placeholder{color:#475569}.ml-check{display:flex;gap:8px;align-items:center;font-size:12px;color:var(--muted);text-transform:none;letter-spacing:0}.ml-check input{accent-color:var(--primary)}.ml-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.ml-help-btn{width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:#081120;color:#93c5fd;font-weight:800}.ml-help{display:none;margin:0 0 12px;padding:12px;border-left:3px solid rgba(250,204,21,.7);border-radius:9px;background:rgba(250,204,21,.07);color:#bfdbfe;font-size:12px;line-height:1.55}.ml-help.open{display:block}.ml-help code{background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;color:#e2e8f0}.ml-tag-picker{position:relative;margin-top:6px}.ml-tag-box{min-height:44px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;background:#081120;border:1px solid #1d3355;border-radius:10px;padding:7px}.ml-tag-chip{display:inline-flex;align-items:center;gap:7px;max-width:100%;background:#16223a;border:1px solid #294467;border-radius:999px;color:#f8fafc;font-size:12px;font-weight:700;letter-spacing:0;padding:5px 9px}.ml-tag-chip button{border:0;background:transparent;color:#94a3b8;font-weight:900;cursor:pointer;padding:0}.ml-tag-add{border:1px dashed #facc15;background:transparent;color:#facc15;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer}.ml-tag-empty{color:#475569;font-size:12px;padding:0 4px;text-transform:none;letter-spacing:0}.ml-tag-dropdown{position:absolute;z-index:40;top:calc(100% + 6px);left:0;right:0;background:#0b0f14;border:1px solid #26364f;border-radius:10px;box-shadow:0 20px 40px rgba(0,0,0,.45);padding:8px;display:none}.ml-tag-dropdown.open{display:block}.ml-tag-dropdown input{margin:0 0 7px!important;border-color:#4a3d12!important}.ml-tag-list{max-height:220px;overflow:auto}.ml-tag-option{padding:9px 10px;border-radius:7px;color:#f8fafc;font-size:12px;font-weight:700;cursor:pointer}.ml-tag-option:hover,.ml-tag-option.is-active{background:#111827}.ml-tag-none{padding:10px;color:#64748b;font-size:12px}.ml-result{margin-top:14px;display:grid;gap:10px}.ml-result pre{margin:0;max-height:360px;overflow:auto;border:1px solid #1d3355;border-radius:10px;background:#081120;color:#bfdbfe;padding:12px;font-size:11px;white-space:pre-wrap}.ml-table{width:100%;border-collapse:collapse;min-width:900px}.ml-table th,.ml-table td{padding:9px;border-bottom:1px solid var(--border);font-size:11px;vertical-align:top}.ml-table th{color:var(--muted);text-transform:uppercase;font-size:9px}.ml-scroll{overflow:auto}.ml-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.ml-kpi{background:#0b1120;border:1px solid rgba(96,165,250,.18);border-radius:12px;padding:14px}.ml-kpi small{color:var(--muted);text-transform:uppercase;font-size:9px}.ml-kpi strong{display:block;font-size:24px}.ml-chart{height:250px}.ml-code{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted)}@media(max-width:1000px){.ml-grid{grid-template-columns:1fr}.ml-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.ml-row{grid-template-columns:1fr}.ml-kpis{grid-template-columns:1fr}}
</style>
<div class="ml">
  <div class="ml-head"><div><h1>Meta Leads Qualificados</h1><p>Configure eventos CRM para enviar leads qualificados para a API de Conversoes da Meta.</p></div><form method="post"><button class="btn btn-primary" name="action" value="process_now">Processar fila agora</button></form></div>
  <nav class="ml-nav">
    <a class="<?=$view==='overview'?'active':''?>" href="?view=overview">Visao geral</a>
    <a class="<?=$view==='settings'?'active':''?>" href="?view=settings">Configuracoes</a>
    <a class="<?=$view==='triggers'?'active':''?>" href="?view=triggers">Gatilhos</a>
    <a class="<?=$view==='test'?'active':''?>" href="?view=test">Teste manual</a>
    <a class="<?=$view==='queue'?'active':''?>" href="?view=queue">Fila</a>
    <a class="<?=$view==='logs'?'active':''?>" href="?view=logs">Logs</a>
  </nav>
  <?php if($message):?><div class="alert alert-ok"><?=ml_h($message)?></div><?php endif; ?>
  <?php if($error):?><div class="alert alert-error"><?=ml_h($error)?></div><?php endif; ?>

  <section class="ml-section <?=$view==='overview'?'active':''?>">
    <div class="ml-kpis">
      <div class="ml-kpi"><small>Total na fila</small><strong><?=number_format((float)($totals['total']??0),0,',','.')?></strong></div>
      <div class="ml-kpi"><small>Enviados</small><strong><?=number_format((float)($totals['sent']??0),0,',','.')?></strong></div>
      <div class="ml-kpi"><small>Pendentes</small><strong><?=number_format((float)($totals['pending']??0),0,',','.')?></strong></div>
      <div class="ml-kpi"><small>Falhas</small><strong><?=number_format((float)($totals['failed']??0),0,',','.')?></strong></div>
    </div>
    <section class="ml-card" style="margin-top:14px"><div class="ml-card-head"><div><h2>Indicadores dos ultimos 30 dias</h2><p>Eventos criados, enviados e falhas de integracao.</p></div></div><div class="ml-chart"><canvas id="mqlChart"></canvas></div></section>
  </section>

  <section class="ml-section <?=$view==='settings'?'active':''?>"><div class="ml-grid">
    <section class="ml-card">
      <div class="ml-card-head"><div class="ml-icon">⚡</div><div><h2><?= $editDataset ? 'Editar conjunto de dados' : 'Novo conjunto de dados' ?></h2><p>Credenciais e parametros do endpoint da Meta.</p></div><button type="button" class="ml-help-btn" data-help="dataset">?</button></div>
      <div class="ml-help" id="ml-help-dataset">Use o <b>Dataset ID</b> exibido pela Meta, cole o <b>Access Token</b> gerado no Gerenciador de Eventos e mantenha <code>event_name=Lead</code> para o evento padrao de lead qualificado. Em modo teste, informe o <code>test_event_code</code> para aparecer na aba Eventos de teste da Meta.</div>
      <form method="post" class="ml-form">
        <input type="hidden" name="action" value="save_dataset"><input type="hidden" name="id" value="<?= (int)($editDataset['id'] ?? 0) ?>">
        <label>Nome interno<input type="text" name="name" required value="<?=ml_h($editDataset['name'] ?? '')?>" placeholder="Lead Qualificado - CRM"></label>
        <div class="ml-row"><label>Dataset ID<input type="text" name="dataset_id" required value="<?=ml_h($editDataset['dataset_id'] ?? '')?>" placeholder="1182793990703954"></label><label>API version<input type="text" name="api_version" value="<?=ml_h($editDataset['api_version'] ?? 'v25.0')?>"></label></div>
        <label>Access token<textarea name="access_token" <?= $editDataset ? '' : 'required' ?> rows="3" placeholder="<?= $editDataset ? 'Deixe vazio para manter o token atual' : 'EAAB...' ?>"></textarea></label>
        <div class="ml-row"><label>Event name<input type="text" name="event_name" value="<?=ml_h($editDataset['event_name'] ?? 'Lead')?>"></label><label>Lead event source<input type="text" name="lead_event_source" value="<?=ml_h($editDataset['lead_event_source'] ?? 'Area de Membros CRM')?>"></label></div>
        <div class="ml-row"><label>Test event code<input type="text" name="test_event_code" value="<?=ml_h($editDataset['test_event_code'] ?? '')?>"></label><label>Modo<select name="mode"><option value="production"<?=($editDataset['mode']??'')==='production'?' selected':''?>>Producao</option><option value="test"<?=($editDataset['mode']??'')==='test'?' selected':''?>>Teste</option></select></label></div>
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
  </div></section>

  <section class="ml-section <?=$view==='triggers'?'active':''?>"><div class="ml-grid">
    <section class="ml-card">
      <div class="ml-card-head"><div class="ml-icon">✓</div><div><h2><?= $editTrigger ? 'Editar gatilho' : 'Novo gatilho de qualificacao' ?></h2><p>Define quando um lead deve entrar na fila de envio.</p></div><button type="button" class="ml-help-btn" data-help="trigger">?</button></div>
      <div class="ml-help" id="ml-help-trigger">Para automacao, escolha <b>Quando tag for aplicada</b> e preencha a <b>Tag gatilho</b>, por exemplo <code>LEAD_QUALIFICADO</code>. Tags obrigatorias funcionam como filtros extras; tags de exclusao impedem envio. A varredura manual avalia leads ja existentes e enfileira quem cumprir a regra.</div>
      <form method="post" class="ml-form">
        <input type="hidden" name="action" value="save_trigger"><input type="hidden" name="id" value="<?= (int)($editTrigger['id'] ?? 0) ?>">
        <label>Nome do gatilho<input type="text" name="name" required value="<?=ml_h($editTrigger['name'] ?? '')?>" placeholder="Tag LEAD_QUALIFICADO"></label>
        <div class="ml-row"><label>Conjunto<select name="dataset_id" required><option value="">Selecione</option><?php foreach($datasets as $d):?><option value="<?=(int)$d['id']?>"<?=((int)($editTrigger['dataset_id']??0)===(int)$d['id'])?' selected':''?>><?=ml_h($d['name'])?></option><?php endforeach;?></select></label><label>Tipo<select name="event_type"><option value="tag_added"<?=($editTrigger['event_type']??'')==='tag_added'?' selected':''?>>Quando tag for aplicada</option><option value="manual_scan"<?=($editTrigger['event_type']??'')==='manual_scan'?' selected':''?>>Somente varredura manual</option></select></label></div>
        <label>Tag gatilho
          <input type="hidden" name="trigger_tag" id="mlTriggerTagValue" value="<?=ml_h($editConditions['trigger_tag'] ?? '')?>">
          <div class="ml-tag-picker" data-picker="trigger" data-mode="single" data-target="mlTriggerTagValue"></div>
        </label>
        <div class="ml-row">
          <label>Tags obrigatorias
            <input type="hidden" name="include_tags" id="mlIncludeTagsValue" value="<?=ml_h(implode(', ', (array)($editConditions['include_tags'] ?? [])))?>">
            <div class="ml-tag-picker" data-picker="include" data-mode="multi" data-target="mlIncludeTagsValue"></div>
          </label>
          <label>Tags de exclusao
            <input type="hidden" name="exclude_tags" id="mlExcludeTagsValue" value="<?=ml_h(implode(', ', (array)($editConditions['exclude_tags'] ?? [])))?>">
            <div class="ml-tag-picker" data-picker="exclude" data-mode="multi" data-target="mlExcludeTagsValue"></div>
          </label>
        </div>
        <label>Turma especifica<input type="text" name="turma" value="<?=ml_h($editConditions['turma'] ?? '')?>" placeholder="Opcional"></label>
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
  </div></section>

  <section class="ml-section <?=$view==='test'?'active':''?>"><div class="ml-grid">
    <section class="ml-card">
      <div class="ml-card-head"><div class="ml-icon">T</div><div><h2>Enviar lead de teste</h2><p>Envia um evento direto para o dataset selecionado sem salvar lead local.</p></div><button type="button" class="ml-help-btn" data-help="test">?</button></div>
      <div class="ml-help" id="ml-help-test">Informe o Lead ID exibido pela Meta e os dados de contato. O sistema normaliza e converte e-mail/telefone em SHA-256, usa o token salvo apenas no servidor e mostra payload, HTTP e resposta completa.</div>
      <form method="post" class="ml-form">
        <input type="hidden" name="action" value="send_test_lead">
        <label>Dataset configurado<select name="dataset_id" required><option value="">Selecione</option><?php foreach($datasets as $d): if((int)$d['active']!==1) continue; ?><option value="<?=(int)$d['id']?>"<?=((int)($_POST['dataset_id']??0)===(int)$d['id'])?' selected':''?>><?=ml_h($d['name'])?> · <?=ml_h($d['dataset_id'])?></option><?php endforeach;?></select></label>
        <label>Lead ID da Meta<input type="text" name="meta_lead_id" required value="<?=ml_h($_POST['meta_lead_id'] ?? '')?>" placeholder="1793287691659425"></label>
        <label>Nome<input type="text" name="test_name" required value="<?=ml_h($_POST['test_name'] ?? '')?>" placeholder="Nome do lead"></label>
        <div class="ml-row"><label>E-mail<input type="email" name="test_email" required value="<?=ml_h($_POST['test_email'] ?? '')?>" placeholder="lead@email.com"></label><label>Telefone<input type="text" name="test_phone" required value="<?=ml_h($_POST['test_phone'] ?? '')?>" placeholder="5522999999999"></label></div>
        <div class="ml-actions"><button class="btn btn-primary">Enviar lead de teste</button></div>
      </form>
    </section>
    <section class="ml-card">
      <div class="ml-card-head"><div><h2>Resultado do teste</h2><p>Payload enviado e retorno integral da Meta.</p></div></div>
      <?php if($testResult): ?>
        <div class="alert <?=$testResult['ok']?'alert-ok':'alert-error'?>"><?=$testResult['ok']?'Envio aceito pela Meta.':'Envio retornou erro.'?></div>
        <?php $metaError = ml_meta_error_summary((string)$testResult['response']); ?>
        <div class="ml-result">
          <div><strong>Status HTTP:</strong> <?=ml_h($testResult['status'] ?: '-')?></div>
          <?php if(!empty($testResult['error'])):?><div><strong>Erro local:</strong> <?=ml_h($testResult['error'])?></div><?php endif; ?>
          <?php if($metaError): ?>
            <div><strong>Erro da Meta</strong><pre><?php foreach($metaError as $k=>$v): ?><?=ml_h($k)?>: <?=ml_h($v) . "\n"?><?php endforeach; ?></pre></div>
          <?php endif; ?>
          <div><strong>Payload enviado</strong><pre><?=ml_h(json_encode($testResult['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?></pre></div>
          <div><strong>Resposta da Meta</strong><pre><?=ml_h((string)$testResult['response'])?></pre></div>
        </div>
      <?php else: ?>
        <div class="text-muted text-sm">Nenhum teste enviado nesta sessao.</div>
      <?php endif; ?>
    </section>
  </div></section>

  <section class="ml-section <?=$view==='queue'?'active':''?>"><section class="ml-card"><div class="ml-card-head"><div><h2>Fila de envios</h2><p>Eventos pendentes, enviados, retentativas e falhas.</p></div><button type="button" class="ml-help-btn" data-help="queue">?</button></div><div class="ml-help" id="ml-help-queue">A fila evita lentidao no cadastro do lead. O cron <code>meta_leads_qualificados</code> envia em lote para a Meta, registra HTTP/status e tenta novamente falhas temporarias.</div><div class="ml-scroll"><table class="ml-table"><thead><tr><th>Data</th><th>Lead</th><th>Conjunto</th><th>Gatilho</th><th>Status</th><th>HTTP</th><th>Erro/resposta</th><th></th></tr></thead><tbody>
    <?php foreach($queue as $q):?><tr><td><?=ml_h(date('d/m/Y H:i',strtotime((string)$q['created_at'])))?></td><td><strong><?=ml_h($q['nome'] ?: ('#'.$q['user_id']))?></strong><div class="text-muted text-xs"><?=ml_h($q['email'] ?? '')?></div></td><td><?=ml_h($q['dataset_name'] ?: '-')?></td><td><?=ml_h($q['trigger_name'] ?: '-')?></td><td><?=ml_badge((string)$q['status'])?></td><td><?=ml_h($q['last_http_status'] ?: '-')?></td><td class="ml-code"><?=ml_h($q['last_error'] ?: $q['last_response'])?></td><td><?php if($q['status']==='failed'):?><form method="post"><input type="hidden" name="id" value="<?=(int)$q['id']?>"><button class="btn btn-xs btn-ghost" name="action" value="retry_queue">Reenviar</button></form><?php endif;?></td></tr><?php endforeach; ?>
    <?php if(!$queue):?><tr><td colspan="8">Fila vazia.</td></tr><?php endif; ?>
  </tbody></table></div></section></section>

  <section class="ml-section <?=$view==='logs'?'active':''?>"><section class="ml-card"><div class="ml-card-head"><div><h2>Logs</h2><p>Historico tecnico dos envios para diagnostico.</p></div><button type="button" class="ml-help-btn" data-help="logs">?</button></div><div class="ml-help" id="ml-help-logs">Os logs mostram payload/resposta e erros da Meta. Tokens nao ficam expostos aqui; o payload usa e-mail e telefone convertidos em SHA-256.</div><div class="ml-scroll"><table class="ml-table"><thead><tr><th>Data</th><th>Nivel</th><th>Mensagem</th><th>Lead</th><th>Conjunto</th><th>HTTP</th><th>Detalhe</th></tr></thead><tbody>
    <?php foreach($logs as $l):?><tr><td><?=ml_h(date('d/m/Y H:i:s',strtotime((string)$l['created_at'])))?></td><td><?=ml_h($l['level'])?></td><td><?=ml_h($l['message'])?></td><td><?=ml_h($l['nome'] ?: ($l['user_id'] ?: '-'))?></td><td><?=ml_h($l['dataset_name'] ?: '-')?></td><td><?=ml_h($l['http_status'] ?: '-')?></td><td class="ml-code"><?=ml_h($l['error_message'] ?: $l['response_json'])?></td></tr><?php endforeach; ?>
    <?php if(!$logs):?><tr><td colspan="7">Nenhum log.</td></tr><?php endif; ?>
  </tbody></table></div></section></section>
</div>
<script>
const ML_ALL_TAGS = <?=json_encode(array_values($allTags), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
function mlTagEsc(value){return String(value||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function mlTagSplit(value){return String(value||'').split(/[,;\r\n]+/).map(v=>v.trim()).filter(Boolean);}
function mlTagInitPicker(root){
  const target = document.getElementById(root.dataset.target || '');
  if(!target) return;
  const mode = root.dataset.mode === 'single' ? 'single' : 'multi';
  let selected = mode === 'single' ? mlTagSplit(target.value).slice(0,1) : mlTagSplit(target.value);
  const uid = 'mltp_' + Math.random().toString(36).slice(2);
  root.innerHTML = `<div class="ml-tag-box" id="${uid}_box"></div><div class="ml-tag-dropdown" id="${uid}_drop"><input type="text" id="${uid}_search" placeholder="Buscar tag..."><div class="ml-tag-list" id="${uid}_list"></div></div>`;
  const box = document.getElementById(uid + '_box');
  const drop = document.getElementById(uid + '_drop');
  const search = document.getElementById(uid + '_search');
  const list = document.getElementById(uid + '_list');
  const sync = () => { target.value = selected.join(', '); };
  const closeOthers = () => document.querySelectorAll('.ml-tag-dropdown.open').forEach(el => { if(el !== drop) el.classList.remove('open'); });
  const renderBox = () => {
    const chips = selected.map((tag, index) => `<span class="ml-tag-chip"><span>${mlTagEsc(tag)}</span><button type="button" data-remove="${index}" title="Remover">x</button></span>`).join('');
    const empty = selected.length ? '' : `<span class="ml-tag-empty">${mode === 'single' ? 'Selecione uma tag' : 'Nenhuma tag selecionada'}</span>`;
    box.innerHTML = chips + empty + `<button type="button" class="ml-tag-add">${selected.length ? '+ Adicionar tag' : '+ Selecionar tag'}</button>`;
    box.querySelectorAll('[data-remove]').forEach(btn => btn.addEventListener('click', ev => {
      ev.stopPropagation();
      selected.splice(Number(btn.dataset.remove), 1);
      sync();
      renderBox();
      renderList();
    }));
    box.querySelector('.ml-tag-add').addEventListener('click', ev => {
      ev.stopPropagation();
      closeOthers();
      drop.classList.toggle('open');
      if(drop.classList.contains('open')){
        search.value = '';
        renderList();
        setTimeout(() => search.focus(), 20);
      }
    });
  };
  const addTag = tag => {
    if(mode === 'single') selected = [tag];
    else if(!selected.map(v => v.toLowerCase()).includes(tag.toLowerCase())) selected.push(tag);
    sync();
    renderBox();
    renderList();
    if(mode === 'single') drop.classList.remove('open');
    else setTimeout(() => search.focus(), 20);
  };
  const renderList = () => {
    const q = search.value.trim().toLowerCase();
    const taken = selected.map(v => v.toLowerCase());
    const options = ML_ALL_TAGS.filter(tag => (!q || tag.toLowerCase().includes(q)) && (mode === 'single' || !taken.includes(tag.toLowerCase())));
    if(!options.length){
      list.innerHTML = `<div class="ml-tag-none">${q ? 'Nenhuma tag encontrada' : 'Todas as tags ja foram selecionadas'}</div>`;
      return;
    }
    list.innerHTML = options.map(tag => `<div class="ml-tag-option">${mlTagEsc(tag)}</div>`).join('');
  };
  list.addEventListener('pointerdown', ev => {
    const item = ev.target.closest('.ml-tag-option');
    if(!item) return;
    ev.preventDefault();
    ev.stopPropagation();
    addTag(item.textContent.trim());
  });
  search.addEventListener('input', renderList);
  search.addEventListener('keydown', ev => {
    if(ev.key === 'Escape') drop.classList.remove('open');
    if(ev.key === 'Enter'){
      ev.preventDefault();
      const first = list.querySelector('.ml-tag-option');
      if(first) addTag(first.textContent.trim());
    }
  });
  renderBox();
  sync();
}
(()=>{document.querySelectorAll('.ml-help-btn').forEach(btn=>btn.addEventListener('click',()=>{const box=document.getElementById('ml-help-'+btn.dataset.help);if(box)box.classList.toggle('open')}));document.querySelectorAll('.ml-tag-picker').forEach(mlTagInitPicker);document.addEventListener('click',ev=>{if(!ev.target.closest('.ml-tag-picker'))document.querySelectorAll('.ml-tag-dropdown.open').forEach(el=>el.classList.remove('open'));});if(!window.Chart)return;const canvas=document.getElementById('mqlChart');if(!canvas)return;const daily=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,ticks='#64748b';new Chart(canvas,{data:{labels:daily.map(x=>String(x.day).slice(5)),datasets:[{type:'bar',label:'Total',data:daily.map(x=>+x.total||0),backgroundColor:'rgba(56,189,248,.35)'},{type:'line',label:'Enviados',data:daily.map(x=>+x.sent||0),borderColor:'#22c55e',backgroundColor:'#22c55e',tension:.3},{type:'line',label:'Falhas',data:daily.map(x=>+x.failed||0),borderColor:'#ef4444',backgroundColor:'#ef4444',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:ticks,boxWidth:10}}},scales:{x:{ticks:{color:ticks},grid:{display:false}},y:{beginAtZero:true,ticks:{color:ticks,precision:0},grid:{color:'rgba(255,255,255,.06)'}}}}});})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
