<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/manychat_dispatcher.php';

proteger_admin();
$pdo = getPDO();
$menu = 'manychat';
$page_title = 'Manychat';
$page_subtitle = 'Integracao de eventos com Manychat';
$view=(string)($_GET['view']??(isset($_GET['edit'])?'rules':'overview'));if(!in_array($view,['overview','rules','reference','logs','settings'],true))$view='overview';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post_str(string $key): string { return trim((string)($_POST[$key] ?? '')); }
function post_int(string $key): int { return (int)($_POST[$key] ?? 0); }

mc_ensure_tables($pdo);

$eventOptions = [
    'INSCRITO',
    'INSCRICAO_GRATUITA',
    'INSCRICAO_VITALICIA',
    'ACESSO_VITALICIO_LIBERADO',
    'REINSCRITO',
    'PRIMEIRO_LOGIN',
    'ASSISTIU_ALGUMA_AULA',
    'CONCLUIU_TRILHA',
    'APP_INSTALADO',
    'APP_NOTIFICACOES_AUTORIZADAS',
    'APP_DESINSTALADO_DETECTADO',
    'CERT_EMITIDO',
    'REENVIO_CERTIFICADO',
    'LIVE_TURMA',
    'LIVE_REAGENDADA',
    'LIVE_REAGENDAMENTO_LEMBRETE',
    'LIVE_REAGENDAMENTO_EXPIRADO',
    'WHATSAPP_GRUPO_ENTROU',
    'WHATSAPP_GRUPO_SAIU',
    'WHATSAPP_GRUPO_REMOVIDO_ADMIN',
    'WHATSAPP_BLACKLIST_DETECTADO',
];

$fieldOptions = [
    'user.id' => 'ID do aluno',
    'user.nome' => 'Nome',
    'user.email' => 'Email',
    'user.telefone' => 'Telefone',
    'user.magic_link' => 'Magic link',
    'extra.codigo_turma' => 'Turma',
    'extra.codigo_live' => 'Codigo da live',
    'extra.data_live' => 'Data da live',
    'extra.data_live_iso' => 'Data da live ISO',
    'extra.andamento' => 'Andamento',
    'extra.aulas_concluidas' => 'Aulas concluidas',
    'extra.aulas_totais' => 'Aulas totais',
    'extra.pdf_url' => 'PDF certificado',
    'extra.codigo_certificado' => 'Codigo certificado',
    'literal:valor_fixo' => 'Valor fixo',
];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') $fieldOptions['users.' . $name] = 'users.' . $name;
    }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $enabled = isset($_POST['is_enabled']) ? 1 : 0;
    $baseUrl = post_str('base_url') ?: 'https://api.manychat.com';
    $token = post_str('token');
    $lookupCustomFieldId = post_int('lookup_custom_field_id');
    $timeout = max(1, post_int('timeout_seconds'));

    $existing = $pdo->query("SELECT id FROM manychat_config ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($existing) {
        $st = $pdo->prepare("UPDATE manychat_config SET is_enabled=:en, base_url=:bu, token=:tk, lookup_custom_field_id=:lf, timeout_seconds=:to WHERE id=:id LIMIT 1");
        $st->execute([':en'=>$enabled, ':bu'=>$baseUrl, ':tk'=>$token, ':lf'=>$lookupCustomFieldId > 0 ? $lookupCustomFieldId : null, ':to'=>$timeout, ':id'=>$existing]);
    } else {
        $st = $pdo->prepare("INSERT INTO manychat_config (is_enabled, base_url, token, lookup_custom_field_id, timeout_seconds) VALUES (:en,:bu,:tk,:lf,:to)");
        $st->execute([':en'=>$enabled, ':bu'=>$baseUrl, ':tk'=>$token, ':lf'=>$lookupCustomFieldId > 0 ? $lookupCustomFieldId : null, ':to'=>$timeout]);
    }
    header('Location: manychat.php?saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rule') {
    $id = post_int('id');
    $nome = post_str('nome');
    $evento = post_str('evento');
    $active = isset($_POST['is_active']) ? 1 : 0;
    $tagsText = post_str('tags_text');
    $flowsText = post_str('flows_text');

    $sources = $_POST['field_source'] ?? [];
    $dests = $_POST['field_dest'] ?? [];
    $pairs = [];
    if (is_array($sources) && is_array($dests)) {
        $n = min(count($sources), count($dests));
        for ($i = 0; $i < $n; $i++) {
            $src = trim((string)$sources[$i]);
            $dst = trim((string)$dests[$i]);
            if ($src !== '' && $dst !== '') $pairs[] = ['source' => $src, 'dest' => $dst];
        }
    }
    $fieldsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE manychat_rules
               SET nome=:n, evento=:e, is_active=:a, tags_text=:t, flows_text=:f, fields_json=:fj
             WHERE id=:id LIMIT 1
        ");
        $st->execute([':n'=>$nome, ':e'=>$evento, ':a'=>$active, ':t'=>$tagsText, ':f'=>$flowsText, ':fj'=>$fieldsJson, ':id'=>$id]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO manychat_rules (nome, evento, is_active, tags_text, flows_text, fields_json)
            VALUES (:n,:e,:a,:t,:f,:fj)
        ");
        $st->execute([':n'=>$nome, ':e'=>$evento, ':a'=>$active, ':t'=>$tagsText, ':f'=>$flowsText, ':fj'=>$fieldsJson]);
    }
    header('Location: manychat.php?saved=1');
    exit;
}

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE manychat_rules SET is_active = IF(is_active=1,0,1) WHERE id=:id")->execute([':id'=>(int)$_GET['toggle']]);
    header('Location: manychat.php');
    exit;
}

if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM manychat_rules WHERE id=:id")->execute([':id'=>(int)$_GET['del']]);
    header('Location: manychat.php');
    exit;
}

$cfg = mc_get_config($pdo);
$rules = $pdo->query("SELECT * FROM manychat_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM manychat_rules WHERE id=:id LIMIT 1");
    $st->execute([':id'=>(int)$_GET['edit']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editPairs = [];
if ($edit && trim((string)($edit['fields_json'] ?? '')) !== '') {
    $tmp = json_decode((string)$edit['fields_json'], true);
    if (is_array($tmp)) $editPairs = $tmp;
}
if (!$editPairs) $editPairs = [['source'=>'user.email', 'dest'=>'email_area_membros']];

$recentLogs = $pdo->query("
    SELECT ml.*, mr.nome AS rule_nome
      FROM manychat_logs ml
      LEFT JOIN manychat_rules mr ON mr.id = ml.rule_id
  ORDER BY ml.created_at DESC, ml.id DESC
     LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/_header.php';
?>

<style>
.int-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:16px}.int-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.int-nav a.active,.int-nav a:hover{background:var(--primary-dim);color:var(--primary)}.int-overview{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}.int-kpi{padding:16px;border:1px solid var(--border);border-radius:14px;background:var(--bg-card)}.int-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.int-kpi strong{display:block;font-size:24px;margin-top:5px}@media(max-width:750px){.int-overview{grid-template-columns:repeat(2,1fr)}}
    :root {
        --bg: #020617;
        --bg-card: #0b1120;
        --border: #1e2d45;
        --text: #e2e8f0;
        --muted: #64748b;
        --primary: #facc15;
        --green: #22c55e;
        --red: #ef4444;
        --blue: #3b82f6;
        --pink: #ec4899;
    }
    .sf-wrap { width:100%; max-width:none; margin:24px 0 0; padding:0 16px 60px; overflow-x:hidden; }
    .page-header { margin-bottom:28px; }
    .page-header h1 { font-size:26px; font-weight:700; margin:0 0 4px; }
    .page-header p { font-size:13px; color:var(--muted); margin:0; }
    .mc-grid { display:grid; grid-template-columns:minmax(320px,430px) minmax(0,1fr); gap:16px; align-items:start; }
    .card {
        background:var(--bg-card);
        border-radius:16px;
        border:1px solid var(--border);
        box-shadow:0 8px 32px rgba(0,0,0,.4);
        padding:22px 26px;
        margin-bottom:22px;
        min-width:0;
        overflow:hidden;
    }
    .card-header {
        display:flex;
        align-items:center;
        gap:10px;
        margin-bottom:18px;
        padding-bottom:14px;
        border-bottom:1px solid var(--border);
    }
    .card-icon {
        width:36px;
        height:36px;
        border-radius:10px;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:13px;
        font-weight:800;
        flex-shrink:0;
        color:#fce7f3;
    }
    .card-icon.yellow { background:rgba(250,204,21,.12); color:#fef3c7; }
    .card-icon.pink { background:rgba(236,72,153,.12); color:#f9a8d4; }
    .card-icon.blue { background:rgba(59,130,246,.12); color:#93c5fd; }
    .card-icon.green { background:rgba(34,197,94,.12); color:#86efac; }
    .card-header-text h2 { font-size:16px; font-weight:600; margin:0 0 2px; }
    .card-header-text p { font-size:12px; color:var(--muted); margin:0; }
    label.lbl { font-size:12px; font-weight:500; color:var(--muted); display:block; margin-bottom:5px; text-transform:uppercase; letter-spacing:.04em; }
    input[type="text"], input[type="password"], input[type="number"], textarea, select {
        width:100%;
        padding:9px 12px;
        border-radius:10px;
        border:1px solid var(--border);
        background:#07101f;
        color:var(--text);
        font-size:13px;
        outline:none;
        transition:border-color .15s;
    }
    input:focus, textarea:focus, select:focus { border-color:var(--blue); }
    textarea { min-height:70px; resize:vertical; }
    .checkbox-row { display:flex; align-items:center; gap:8px; }
    .checkbox-row input[type="checkbox"] { width:16px; height:16px; accent-color:var(--primary); flex-shrink:0; }
    .checkbox-row label, .checkbox-row span { font-size:13px; margin:0; }
    .btn {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        border:none;
        background:var(--primary);
        color:#111;
        font-weight:700;
        font-size:13px;
        padding:10px 20px;
        border-radius:999px;
        cursor:pointer;
        text-decoration:none;
    }
    .btn:hover { filter:brightness(1.06); text-decoration:none; }
    .btn.ghost, .btn-ghost { background:rgba(255,255,255,.06); color:var(--text); border:1px solid var(--border); }
    .btn.sm, .btn-sm { padding:6px 14px; font-size:12px; }
    .btn.danger, .btn-danger { background:rgba(239,68,68,.12); color:#fca5a5; border:1px solid rgba(239,68,68,.3); }
    .note, .mc-muted { font-size:11.5px; color:var(--muted); margin-top:5px; line-height:1.5; }
    .note code, .mc-muted code { background:rgba(255,255,255,.06); border-radius:4px; padding:1px 5px; font-size:11px; }
    .mc-field-grid { display:grid; grid-template-columns:1fr 1fr 34px; gap:8px; align-items:center; margin-bottom:8px; }
    .btnx { width:34px; height:34px; border-radius:10px; border:1px solid var(--border); background:var(--bg-card); color:var(--muted); cursor:pointer; font-size:16px; }
    .btnx:hover { background:rgba(239,68,68,.12); color:#fca5a5; border-color:rgba(239,68,68,.3); }
    .sf-list { display:flex; flex-direction:column; gap:12px; }
    .sf-card { background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:12px; padding:14px 16px; }
    .sf-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:8px; }
    .sf-card-name { font-size:14px; font-weight:600; }
    .sf-card-meta { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .sf-actions { display:flex; gap:6px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
    .badge-on { background:rgba(34,197,94,.1); color:#86efac; border:1px solid rgba(34,197,94,.3); }
    .badge-off { background:rgba(100,116,139,.1); color:#94a3b8; border:1px solid rgba(100,116,139,.3); }
    .badge-evt { background:rgba(236,72,153,.08); border:1px solid rgba(236,72,153,.25); color:#fbcfe8; border-radius:999px; padding:2px 10px; font-size:11px; font-weight:600; }
    .badge-live { background:rgba(249,115,22,.1); color:#fdba74; border:1px solid rgba(249,115,22,.3); }
    .empty-state { text-align:center; padding:32px; color:var(--muted); border:1px dashed var(--border); border-radius:12px; font-size:13px; }
    .log-table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .log-table th, .log-table td { padding:9px 8px; border-bottom:1px solid var(--border); font-size:12px; text-align:left; vertical-align:top; color:var(--text); overflow:hidden; text-overflow:ellipsis; }
    .log-table th { color:var(--muted); font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
    .log-ok { color:#4ade80; }
    .log-fail { color:#f87171; }
    .mc-ref-grid { display:grid; gap:10px; }
    .mc-ref-item { border:1px solid var(--border); border-radius:10px; background:rgba(255,255,255,.03); padding:10px 12px; }
    .mc-ref-item b { display:block; font-size:12px; margin-bottom:6px; }
    .mc-ref-item code { display:inline-block; margin:2px 4px 2px 0; color:#93c5fd; background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.16); border-radius:6px; padding:2px 6px; font-size:11px; }
    @media(max-width:1000px){.mc-grid{grid-template-columns:1fr;}.mc-field-grid{grid-template-columns:1fr;}.sf-card-top{flex-direction:column;}.sf-actions{justify-content:flex-start;}}
</style>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-ok">Configuracao salva.</div>
<?php endif; ?>

<div class="sf-wrap">
    <div class="page-header">
        <h1>Manychat</h1>
        <p>Configure as credenciais globais e crie regras de disparo por evento: tags, flows e campos personalizados.</p>
    </div>
    <nav class="int-nav"><?php foreach(['overview'=>'Visão geral','rules'=>'Regras','reference'=>'Referências','logs'=>'Logs','settings'=>'Configurações'] as $k=>$label):?><a class="<?=$view===$k?'active':''?>" href="manychat.php?view=<?=$k?>"><?=h($label)?></a><?php endforeach;?></nav>
    <?php if($view==='overview'):?><?php $mcOk=count(array_filter($recentLogs,fn($l)=>(int)$l['ok']===1));$mcFail=count($recentLogs)-$mcOk;?><div class="int-overview"><div class="int-kpi"><small>Status da integração</small><strong class="<?=!empty($cfg['is_enabled'])?'log-ok':'text-muted'?>"><?=!empty($cfg['is_enabled'])?'Ativa':'Pausada'?></strong></div><div class="int-kpi"><small>Regras ativas</small><strong><?=count(array_filter($rules,fn($r)=>(int)$r['is_active']===1))?></strong></div><div class="int-kpi"><small>Sucessos recentes</small><strong class="log-ok"><?=$mcOk?></strong></div><div class="int-kpi"><small>Falhas recentes</small><strong class="<?=$mcFail?'log-fail':''?>"><?=$mcFail?></strong></div></div><?php endif;?>

<div class="mc-grid">
    <div>
        <div class="card">
            <div class="card-header">
                <div class="card-icon yellow">API</div>
                <div class="card-header-text">
                    <h2>Credenciais globais</h2>
                    <p>Token e endpoint padrao para todos os disparos.</p>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="save_config">
                <div class="checkbox-row" style="margin-bottom:14px;">
                    <input type="checkbox" id="mc-enabled" name="is_enabled" <?= (int)$cfg['is_enabled'] === 1 ? 'checked' : '' ?>>
                    <label for="mc-enabled">Ativar integracao Manychat</label>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="lbl">Base URL</label>
                    <input type="text" name="base_url" value="<?= h($cfg['base_url']) ?>" placeholder="https://api.manychat.com">
                    <div class="note">Opcional se usar o endpoint oficial.</div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="lbl">Token</label>
                    <input type="password" name="token" value="<?= h($cfg['token']) ?>" placeholder="Bearer token do Manychat">
                    <div class="note">No Manychat, gere o token em Settings > API. Os flows, tags e campos precisam existir no Manychat.</div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="lbl">Campo espelho WhatsApp (field_id)</label>
                    <input type="number" name="lookup_custom_field_id" min="0" value="<?= (int)($cfg['lookup_custom_field_id'] ?? 0) ?>" placeholder="Ex.: 123456">
                    <div class="note">Opcional. Use o ID de um campo customizado do Manychat que guarda o WhatsApp do contato. Isso permite localizar contatos WhatsApp ja existentes e aplicar tags.</div>
                </div>
                <div style="margin-bottom:16px;">
                    <label class="lbl">Timeout (segundos)</label>
                    <input type="number" name="timeout_seconds" min="1" max="60" value="<?= (int)$cfg['timeout_seconds'] ?>">
                </div>
                <button class="btn" type="submit">Salvar credenciais</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-icon blue">REF</div>
                <div class="card-header-text">
                    <h2>Referencias de campos</h2>
                    <p>Use estes valores como origem no mapeamento.</p>
                </div>
            </div>
            <div class="mc-ref-grid">
                <div class="mc-ref-item">
                    <b>Aluno</b>
                    <code>user.nome</code><code>user.email</code><code>user.telefone</code><code>user.magic_link</code>
                </div>
                <div class="mc-ref-item">
                    <b>Live</b>
                    <code>extra.codigo_turma</code><code>extra.codigo_live</code><code>extra.data_live</code><code>extra.data_live_iso</code>
                </div>
                <div class="mc-ref-item">
                    <b>Progresso e certificado</b>
                    <code>extra.andamento</code><code>extra.aulas_concluidas</code><code>extra.pdf_url</code><code>extra.codigo_certificado</code>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-icon pink">EVT</div>
                <div class="card-header-text">
                    <h2><?= $edit ? 'Editar regra' : 'Nova regra' ?></h2>
                    <p>Defina o evento e as acoes executadas no Manychat.</p>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div style="margin-bottom:12px;">
                    <label class="lbl">Nome</label>
                    <input type="text" name="nome" value="<?= h($edit['nome'] ?? '') ?>" placeholder="Ex.: Tag de inscrito no Manychat">
                </div>
                <div style="margin-bottom:12px;">
                    <label class="lbl">Evento</label>
                    <input list="mc-events" name="evento" value="<?= h($edit['evento'] ?? '') ?>" placeholder="INSCRITO ou LIVE_TURMA_300526" required>
                    <datalist id="mc-events">
                        <?php foreach ($eventOptions as $event): ?><option value="<?= h($event) ?>"></option><?php endforeach; ?>
                    </datalist>
                    <div class="note">Para turma especifica, use o evento completo, como <code>LIVE_TURMA_300526</code>.</div>
                </div>
                <div class="checkbox-row" style="margin-bottom:14px;">
                    <input type="checkbox" id="mc-rule-active" name="is_active" <?= !isset($edit['is_active']) || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>>
                    <label for="mc-rule-active">Regra ativa</label>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="lbl">Tags por nome (uma por linha)</label>
                    <textarea name="tags_text" placeholder="INSCRITO_AREA_MEMBROS"><?= h($edit['tags_text'] ?? '') ?></textarea>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="lbl">Flows flow_ns</label>
                    <textarea name="flows_text" placeholder="content20240101abc123"><?= h($edit['flows_text'] ?? '') ?></textarea>
                    <div class="note">Use o identificador <code>flow_ns</code> do Manychat. Pode separar por linha, espaco ou virgula.</div>
                </div>
                <div style="margin-bottom:16px;">
                    <label class="lbl">Campos personalizados</label>
                    <datalist id="mc-field-options">
                        <?php foreach ($fieldOptions as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
                    </datalist>
                    <div id="mc-fields">
                        <?php foreach ($editPairs as $pair): ?>
                            <div class="mc-field-grid">
                                <input list="mc-field-options" name="field_source[]" value="<?= h($pair['source'] ?? '') ?>" placeholder="Origem. Ex.: user.email">
                                <input name="field_dest[]" value="<?= h($pair['dest'] ?? '') ?>" placeholder="Campo no Manychat">
                                <button class="btnx" type="button" onclick="this.closest('.mc-field-grid').remove()">x</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn ghost sm" type="button" onclick="mcAddField()">Adicionar campo</button>
                </div>
                <button class="btn" type="submit"><?= $edit ? 'Salvar regra' : 'Adicionar regra' ?></button>
                <?php if ($edit): ?><a class="btn ghost" href="manychat.php">Cancelar</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header">
                <div class="card-icon green">RUN</div>
                <div class="card-header-text">
                    <h2>Regras configuradas</h2>
                    <p><?= count($rules) ?> regra<?= count($rules) !== 1 ? 's' : '' ?> no total.</p>
                </div>
            </div>
            <?php if (!$rules): ?>
                <div class="empty-state">Nenhuma regra Manychat cadastrada ainda.</div>
            <?php else: ?>
                <div class="sf-list">
                <?php foreach ($rules as $rule): ?>
                    <?php
                    $tagCount = count(array_filter(array_map('trim', preg_split('/\R+/', (string)($rule['tags_text'] ?? '')) ?: [])));
                    $flowCount = count(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($rule['flows_text'] ?? '')) ?: [])));
                    $fieldCount = 0;
                    $tmp = json_decode((string)($rule['fields_json'] ?? ''), true);
                    if (is_array($tmp)) $fieldCount = count($tmp);
                    ?>
                    <div class="sf-card">
                        <div class="sf-card-top">
                            <div>
                                <div class="sf-card-name"><?= h($rule['nome'] ?: ('Regra #' . (int)$rule['id'])) ?></div>
                                <div class="sf-card-meta" style="margin-top:7px;">
                                    <span class="badge-evt"><?= h($rule['evento']) ?></span>
                                    <?= (int)$rule['is_active'] === 1 ? '<span class="badge badge-on">Ativa</span>' : '<span class="badge badge-off">Pausada</span>' ?>
                                </div>
                                <div class="note"><?= $tagCount ?> tag(s), <?= $flowCount ?> flow(s), <?= $fieldCount ?> campo(s)</div>
                            </div>
                            <div class="sf-actions">
                                <a class="btn ghost sm" href="manychat.php?edit=<?= (int)$rule['id'] ?>">Editar</a>
                                <a class="btn ghost sm" href="manychat.php?toggle=<?= (int)$rule['id'] ?>"><?= (int)$rule['is_active'] === 1 ? 'Pausar' : 'Ativar' ?></a>
                                <a class="btn danger sm" href="manychat.php?del=<?= (int)$rule['id'] ?>" onclick="return confirm('Excluir esta regra?')">Excluir</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-icon blue">LOG</div>
                <div class="card-header-text">
                    <h2>Ultimos logs Manychat</h2>
                    <p>Auditoria recente das chamadas feitas para a API.</p>
                </div>
                <div style="margin-left:auto"><a class="btn ghost sm" href="logs.php?source=manychat">Ver todos</a></div>
            </div>
            <div class="table-wrap">
                <table class="log-table">
                    <thead><tr><th>Quando</th><th>Evento</th><th>Acao</th><th>Status</th><th>Resumo</th></tr></thead>
                    <tbody>
                    <?php if (!$recentLogs): ?>
                        <tr><td colspan="5" class="text-muted" style="text-align:center;padding:22px">Sem logs ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= h($log['created_at']) ?></td>
                            <td><?= h($log['evento']) ?></td>
                            <td><?= h($log['action']) ?></td>
                            <td><?= (int)$log['ok'] === 1 ? '<span class="log-ok">OK</span>' : '<span class="log-fail">Erro</span>' ?> <span class="text-muted"><?= h($log['http_status'] ?? '-') ?></span></td>
                            <td><?= h($log['error_text'] ?: substr((string)($log['response_text'] ?? ''), 0, 120)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<script>
const mcView=<?=json_encode($view)?>;document.querySelectorAll('.card h2').forEach(h=>{const t=h.textContent.trim(),card=h.closest('.card'),show=mcView==='overview'?false:(mcView==='settings'?t==='Credenciais globais':mcView==='reference'?t==='Referencias de campos':mcView==='logs'?t==='Ultimos logs Manychat':mcView==='rules'?(t==='Nova regra'||t==='Editar regra'||t==='Regras configuradas'):true);if(!show)card.style.display='none';});
function mcAddField() {
    var wrap = document.getElementById('mc-fields');
    var div = document.createElement('div');
    div.className = 'mc-field-grid';
    div.innerHTML = '<input list="mc-field-options" name="field_source[]" placeholder="Origem. Ex.: user.email">' +
        '<input name="field_dest[]" placeholder="Campo no Manychat">' +
        "<button class=\"btnx\" type=\"button\" onclick=\"this.closest('.mc-field-grid').remove()\">x</button>";
    wrap.appendChild(div);
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>
