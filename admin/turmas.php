<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}

function table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function parse_live_filter_cfg(?string $raw): array {
    $raw = trim((string)$raw);
    $cfg = ['include_any' => [], 'exclude_any' => [], 'exclude_cert' => 0, 'exclude_zero' => 0];
    if ($raw === '') return $cfg;
    if ($raw[0] === '{' || $raw[0] === '[') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (isset($j['include_any']) && is_array($j['include_any']))
                $cfg['include_any'] = array_values(array_filter(array_map('intval', $j['include_any']), fn($v)=>$v>0));
            if (isset($j['exclude_any']) && is_array($j['exclude_any']))
                $cfg['exclude_any'] = array_values(array_filter(array_map('intval', $j['exclude_any']), fn($v)=>$v>0));
            if (isset($j['exclude_cert'])) $cfg['exclude_cert'] = (int)(!!$j['exclude_cert']);
            if (isset($j['exclude_zero'])) $cfg['exclude_zero'] = (int)(!!$j['exclude_zero']);
            return $cfg;
        }
    }
    $ids = [];
    foreach (explode(',', $raw) as $p) { $v = (int)trim($p); if ($v > 0) $ids[] = $v; }
    $cfg['include_any'] = array_values(array_unique($ids));
    return $cfg;
}

function encode_live_filter_cfg(array $cfg): ?string {
    $out = [
        'include_any'  => array_values(array_filter(array_map('intval', $cfg['include_any'] ?? []), fn($v)=>$v>0)),
        'exclude_any'  => array_values(array_filter(array_map('intval', $cfg['exclude_any'] ?? []), fn($v)=>$v>0)),
        'exclude_cert' => (int)(!!($cfg['exclude_cert'] ?? 0)),
        'exclude_zero' => (int)(!!($cfg['exclude_zero'] ?? 0)),
    ];
    if (!$out['include_any'] && !$out['exclude_any'] && $out['exclude_cert']===0 && $out['exclude_zero']===0) return null;
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

function dt_local_value(?string $dbValue): string {
    if (!$dbValue) return '';
    $ts = strtotime($dbValue);
    if (!$ts) return '';
    return date('Y-m-d\TH:i', $ts);
}

// ===================== SAVE =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $codigo      = trim((string)($_POST['codigo'] ?? ''));
    $ji          = (string)($_POST['janela_inicio'] ?? '');
    $jf          = (string)($_POST['janela_fim'] ?? '');
    $dl          = (string)($_POST['data_live'] ?? '');
    $url         = trim((string)($_POST['webhook_live_url'] ?? ''));
    $delay       = (int)($_POST['delay_ms'] ?? 500);
    $codigoLive  = trim((string)($_POST['codigo_live'] ?? ''));
    $codigoLive  = ($codigoLive === '') ? null : $codigoLive;
    $liveEnabled = isset($_POST['live_webhook_enabled']) ? 1 : 0;
    $disparo     = (string)($_POST['live_disparo_data'] ?? '');
    $includeSel  = $_POST['live_include_tag_ids'] ?? ($_POST['live_filter_tag_ids'] ?? []);
    $excludeSel  = $_POST['live_exclude_tag_ids'] ?? [];
    $excludeCert = isset($_POST['live_exclude_cert']) ? 1 : 0;
    $excludeZero = isset($_POST['live_exclude_zero']) ? 1 : 0;

    $senhaCert = trim((string)($_POST['senha_certificado'] ?? ''));

    // SF config
    $sfEnabled   = isset($_POST['sf_enabled']) ? 1 : 0;
    $sfTagsText  = trim((string)($_POST['sf_tags_text'] ?? ''));
    $sfFlowsText = trim((string)($_POST['sf_flows_text'] ?? ''));
    $sfSources   = $_POST['sf_field_source'] ?? [];
    $sfDests     = $_POST['sf_field_dest'] ?? [];
    $sfPairs = [];
    if (is_array($sfSources) && is_array($sfDests)) {
        $n = min(count($sfSources), count($sfDests));
        for ($i = 0; $i < $n; $i++) {
            $src = trim((string)$sfSources[$i]);
            $dst = trim((string)$sfDests[$i]);
            if ($src !== '' && $dst !== '') $sfPairs[] = ['source' => $src, 'dest' => $dst];
        }
    }
    $sfFieldsJson = $sfPairs ? json_encode($sfPairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $jiDb   = $ji     ? date('Y-m-d H:i:s', strtotime($ji))     : null;
    $jfDb   = $jf     ? date('Y-m-d H:i:s', strtotime($jf))     : null;
    $dlDb   = $dl     ? date('Y-m-d H:i:s', strtotime($dl))     : null;
    $dispDb = $disparo ? date('Y-m-d H:i:s', strtotime($disparo)) : null;

    $cfg = [
        'include_any'  => is_array($includeSel) ? array_values(array_filter(array_map('intval', $includeSel), fn($v)=>$v>0)) : [],
        'exclude_any'  => is_array($excludeSel) ? array_values(array_filter(array_map('intval', $excludeSel), fn($v)=>$v>0)) : [],
        'exclude_cert' => $excludeCert,
        'exclude_zero' => $excludeZero,
    ];
    $tagsCsv = encode_live_filter_cfg($cfg);

    if ($codigo === '' || !$jiDb || !$jfDb) {
        header('Location: turmas.php'); exit;
    }

    $hasLiveEnabled  = col_exists($pdo, 'turmas', 'live_webhook_enabled');
    $hasDispDate     = col_exists($pdo, 'turmas', 'live_disparo_data');
    $hasTagIds       = col_exists($pdo, 'turmas', 'live_filter_tag_ids');
    $hasCreatedAt    = col_exists($pdo, 'turmas', 'created_at');
    $hasLiveDisp     = col_exists($pdo, 'turmas', 'live_disparada');
    $hasCodigoLive   = col_exists($pdo, 'turmas', 'codigo_live');
    $hasSfEnabled    = col_exists($pdo, 'turmas', 'sf_enabled');
    $hasSfTagsText   = col_exists($pdo, 'turmas', 'sf_tags_text');
    $hasSfFlowsText  = col_exists($pdo, 'turmas', 'sf_flows_text');
    $hasSfFieldsJson = col_exists($pdo, 'turmas', 'sf_fields_json');
    $hasSenhaCert    = col_exists($pdo, 'turmas', 'senha_certificado');

    // Migration: cria coluna senha_certificado se não existir
    if (!$hasSenhaCert) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN senha_certificado VARCHAR(255) NOT NULL DEFAULT ''"); $hasSenhaCert = true; } catch (Throwable $e) {}
    }

    // Migration: cria colunas SF se não existirem
    if (!$hasSfEnabled) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN sf_enabled TINYINT(1) NOT NULL DEFAULT 0"); $hasSfEnabled = true; } catch (Throwable $e) {}
    }
    if (!$hasSfTagsText) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN sf_tags_text TEXT NULL"); $hasSfTagsText = true; } catch (Throwable $e) {}
    }
    if (!$hasSfFlowsText) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN sf_flows_text TEXT NULL"); $hasSfFlowsText = true; } catch (Throwable $e) {}
    }
    if (!$hasSfFieldsJson) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN sf_fields_json LONGTEXT NULL"); $hasSfFieldsJson = true; } catch (Throwable $e) {}
    }

    if ($id > 0) {
        $old = null;
        if ($hasLiveDisp) {
            try {
                $stOld = $pdo->prepare("SELECT data_live, webhook_live_url, live_webhook_enabled, live_disparo_data, live_disparada FROM turmas WHERE id = :id");
                $stOld->execute([':id' => $id]);
                $old = $stOld->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { $old = null; }
        }

        $set = []; $params = [':id' => $id];
        $set[] = "codigo = :c";             $params[':c']  = $codigo;
        $set[] = "janela_inicio = :ji";     $params[':ji'] = $jiDb;
        $set[] = "janela_fim = :jf";        $params[':jf'] = $jfDb;
        $set[] = "data_live = :dl";         $params[':dl'] = $dlDb;
        $set[] = "webhook_live_url = :u";   $params[':u']  = ($url !== '' ? $url : null);
        $set[] = "delay_ms = :d";           $params[':d']  = $delay;
        if ($hasCodigoLive)   { $set[] = "codigo_live = :cl";        $params[':cl']   = $codigoLive; }
        if ($hasLiveEnabled)  { $set[] = "live_webhook_enabled = :en"; $params[':en']  = $liveEnabled; }
        if ($hasDispDate)     { $set[] = "live_disparo_data = :disp"; $params[':disp'] = $dispDb; }
        if ($hasTagIds)       { $set[] = "live_filter_tag_ids = :tags"; $params[':tags'] = $tagsCsv; }
        if ($hasSfEnabled)    { $set[] = "sf_enabled = :sfen";   $params[':sfen']  = $sfEnabled; }
        if ($hasSfTagsText)   { $set[] = "sf_tags_text = :sftt"; $params[':sftt']  = ($sfTagsText !== '' ? $sfTagsText : null); }
        if ($hasSfFlowsText)  { $set[] = "sf_flows_text = :sfft"; $params[':sfft'] = ($sfFlowsText !== '' ? $sfFlowsText : null); }
        if ($hasSfFieldsJson) { $set[] = "sf_fields_json = :sffj"; $params[':sffj'] = $sfFieldsJson; }
        if ($hasSenhaCert)    { $set[] = "senha_certificado = :sc"; $params[':sc']  = $senhaCert; }

        if ($hasLiveDisp) {
            $ld = 0;
            if ($old) {
                $changed = false;
                if (($old['data_live'] ?? null) !== ($dlDb ?? null)) $changed = true;
                if (($old['webhook_live_url'] ?? null) !== ($url !== '' ? $url : null)) $changed = true;
                if ($hasLiveEnabled && (int)($old['live_webhook_enabled'] ?? 0) !== (int)$liveEnabled) $changed = true;
                if ($hasDispDate && (($old['live_disparo_data'] ?? null) !== ($dispDb ?? null))) $changed = true;
                $ld = $changed ? 0 : (int)($old['live_disparada'] ?? 0);
            }
            $set[] = "live_disparada = :ld"; $params[':ld'] = $ld;
        }

        try {
            $pdo->prepare("UPDATE turmas SET " . implode(", ", $set) . " WHERE id = :id")->execute($params);
        } catch (Throwable $e) {
            $msg = strpos((string)$e->getMessage(), '1062') !== false ? 'Código da live já existe em outra turma.' : 'Erro ao salvar turma.';
            header('Location: turmas.php?err=' . urlencode($msg)); exit;
        }
    } else {
        $cols = ["codigo","janela_inicio","janela_fim","data_live","webhook_live_url","delay_ms"];
        $vals = [":c",":ji",":jf",":dl",":u",":d"];
        $params = [':c'=>$codigo,':ji'=>$jiDb,':jf'=>$jfDb,':dl'=>$dlDb,':u'=>($url!==''?$url:null),':d'=>$delay];
        if ($hasCodigoLive)   { $cols[] = "codigo_live";          $vals[] = ":cl";   $params[':cl']   = $codigoLive; }
        if ($hasLiveEnabled)  { $cols[] = "live_webhook_enabled"; $vals[] = ":en";   $params[':en']   = $liveEnabled; }
        if ($hasDispDate)     { $cols[] = "live_disparo_data";    $vals[] = ":disp"; $params[':disp'] = $dispDb; }
        if ($hasTagIds)       { $cols[] = "live_filter_tag_ids";  $vals[] = ":tags"; $params[':tags'] = $tagsCsv; }
        if ($hasSfEnabled)    { $cols[] = "sf_enabled";     $vals[] = ":sfen";  $params[':sfen']  = $sfEnabled; }
        if ($hasSfTagsText)   { $cols[] = "sf_tags_text";   $vals[] = ":sftt";  $params[':sftt']  = ($sfTagsText !== '' ? $sfTagsText : null); }
        if ($hasSfFlowsText)  { $cols[] = "sf_flows_text";  $vals[] = ":sfft";  $params[':sfft']  = ($sfFlowsText !== '' ? $sfFlowsText : null); }
        if ($hasSfFieldsJson) { $cols[] = "sf_fields_json"; $vals[] = ":sffj";  $params[':sffj']  = $sfFieldsJson; }
        if ($hasSenhaCert)    { $cols[] = "senha_certificado"; $vals[] = ":sc"; $params[':sc']    = $senhaCert; }
        if ($hasCreatedAt)    { $cols[] = "created_at";     $vals[] = "NOW()"; }
        if ($hasLiveDisp)     { $cols[] = "live_disparada"; $vals[] = "0"; }

        try {
            $pdo->prepare("INSERT INTO turmas (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")")->execute($params);
        } catch (Throwable $e) {
            $msg = strpos((string)$e->getMessage(), '1062') !== false ? 'Código da live já existe em outra turma.' : 'Erro ao salvar turma.';
            header('Location: turmas.php?err=' . urlencode($msg)); exit;
        }
    }

    // Propaga data_live para alunos da turma
    if ($codigo !== '') {
        try {
            if (col_exists($pdo, 'users', 'data_live'))     $pdo->prepare("UPDATE users SET data_live = :dl WHERE codigo_turma = :c")->execute([':dl'=>$dlDb,':c'=>$codigo]);
            if (col_exists($pdo, 'users', 'turma_live_at')) $pdo->prepare("UPDATE users SET turma_live_at = :dl WHERE codigo_turma = :c")->execute([':dl'=>$dlDb,':c'=>$codigo]);
        } catch (Throwable $e) {}
    }

    header('Location: turmas.php'); exit;
}

if (isset($_GET['del'])) {
    $pdo->prepare("DELETE FROM turmas WHERE id = :id")->execute([':id' => (int)$_GET['del']]);
    header('Location: turmas.php'); exit;
}

if (isset($_GET['reset_disparo'])) {
    $id = (int)$_GET['reset_disparo'];
    try { $pdo->prepare("UPDATE turmas SET live_disparada = 0 WHERE id = :id")->execute([':id' => $id]); } catch (Throwable $e) {}
    header('Location: turmas.php'); exit;
}

if (isset($_GET['update_live_date'], $_GET['codigo'], $_GET['nova_data'])) {
    $dlDb = date('Y-m-d H:i:s', strtotime((string)$_GET['nova_data']));
    $c    = (string)$_GET['codigo'];
    $pdo->prepare("UPDATE turmas SET data_live = :dl WHERE codigo = :c")->execute([':dl'=>$dlDb,':c'=>$c]);
    $pdo->prepare("UPDATE users SET data_live = :dl WHERE codigo_turma = :c")->execute([':dl'=>$dlDb,':c'=>$c]);
    if (col_exists($pdo, 'users', 'turma_live_at'))
        $pdo->prepare("UPDATE users SET turma_live_at = :dl WHERE codigo_turma = :c")->execute([':dl'=>$dlDb,':c'=>$c]);
    header('Location: turmas.php'); exit;
}

// ===================== LOAD =====================
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id");
    $st->execute([':id' => (int)$_GET['edit']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$allTags = [];
if (table_exists($pdo, 'tags') && col_exists($pdo, 'tags', 'ativo')) {
    $allTags = $pdo->query("SELECT id, nome FROM tags WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$turmas = $pdo->query("SELECT * FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Pares de campos SF para edição
$sfEditPairs = [];
if ($edit && !empty($edit['sf_fields_json'])) {
    $tmp = json_decode((string)$edit['sf_fields_json'], true);
    if (is_array($tmp)) $sfEditPairs = $tmp;
}
if (!$sfEditPairs) $sfEditPairs = [['source' => '', 'dest' => '']];

// Datalist para campos SF (contexto de turma)
$sfFieldOptions = [
    'user.id'       => 'ID do aluno',
    'user.nome'     => 'Nome do aluno',
    'user.email'    => 'Email do aluno',
    'user.telefone' => 'Telefone do aluno',
    'extra.codigo_turma'     => 'Código da turma',
    'extra.codigo_live'      => 'Código da live',
    'extra.data_live'        => 'Data da live (ISO)',
    'extra.data_live_br'     => 'Data da live (BR)',
    'extra.andamento'        => 'Andamento % do aluno',
    'extra.aulas_concluidas' => 'Aulas concluídas',
    'extra.aulas_totais'     => 'Total de aulas obrigatórias',
    'evento'                 => 'Nome do evento',
    'timestamp'              => 'Timestamp (ISO)',
];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($cols as $c) {
        $name = (string)($c['Field'] ?? '');
        if ($name !== '') $sfFieldOptions['users.' . $name] = 'users.' . $name;
    }
} catch (Throwable $e) {}

$filterCfg = parse_live_filter_cfg($edit['live_filter_tag_ids'] ?? null);
$selInc = []; foreach (($filterCfg['include_any'] ?? []) as $tid) $selInc[(int)$tid] = true;
$selExc = []; foreach (($filterCfg['exclude_any'] ?? []) as $tid) $selExc[(int)$tid] = true;
$excCert = (int)($filterCfg['exclude_cert'] ?? 0) === 1;
$excZero = (int)($filterCfg['exclude_zero'] ?? 0) === 1;

$menu = 'turmas';
include __DIR__ . '/_header.php';
?>
<style>
.page-turmas { max-width: 1100px; margin: 0 auto; }
.section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--muted); margin: 18px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
.grid2t { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.grid3t { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
@media (max-width: 780px) { .grid2t, .grid3t { grid-template-columns: 1fr; } }
.field-lbl { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; font-weight: 500; }
.toggle-panel { border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-top: 4px; }
.toggle-header { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: rgba(255,255,255,.03); cursor: pointer; }
.toggle-header:hover { background: rgba(255,255,255,.05); }
.toggle-body { padding: 16px; border-top: 1px solid var(--border); }
.toggle-body.hidden { display: none; }
.chk-label { display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; font-weight: 500; user-select: none; }
.chk-label input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
.sf-hint { font-size: 11px; color: var(--muted); background: rgba(255,255,255,.04); border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; line-height: 1.65; }
.sf-hint code { background: rgba(255,255,255,.09); border-radius: 4px; padding: 1px 5px; font-size: 10.5px; }
.row-sf { display: grid; grid-template-columns: 1fr 1fr 34px; gap: 8px; align-items: center; margin-bottom: 8px; }
.btnx { width: 34px; height: 34px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg-card); color: var(--muted); cursor: pointer; }
.btnx:hover { background: rgba(239,68,68,.15); color: #ef4444; border-color: rgba(239,68,68,.3); }
.btn-sm { font-size: 11px; padding: 4px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); cursor: pointer; text-decoration: none; display: inline-block; }
.btn-sm:hover { background: rgba(255,255,255,.08); }
.btn-danger-sm { border-color: rgba(239,68,68,.3); color: #ef4444; }
.btn-danger-sm:hover { background: rgba(239,68,68,.12); }
.tag-filter-box { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.tag-filter-box select { border: none; border-radius: 0; outline: none; padding: 8px; background: var(--bg); color: var(--text); }
.tag-filter-box input[type=text] { border: none; border-bottom: 1px solid var(--border); border-radius: 0; background: var(--bg); color: var(--text); padding: 8px; }
.badge-ok   { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(34,197,94,.12); color:#4ade80; border:1px solid rgba(34,197,94,.25); }
.badge-off  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(255,255,255,.06); color:var(--muted); border:1px solid var(--border); }
.badge-warn { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(251,191,36,.12); color:#fbbf24; border:1px solid rgba(251,191,36,.25); }
.table-turmas td, .table-turmas th { font-size: 12px; }
.table-turmas td { vertical-align: middle; }
</style>

<!-- datalist SF -->
<datalist id="sf-turma-source-list">
<?php foreach ($sfFieldOptions as $val => $lab): ?>
    <option value="<?= h($val) ?>"><?= h($lab) ?></option>
<?php endforeach; ?>
</datalist>

<div class="page-turmas">

<?php if (isset($_GET['err']) && $_GET['err'] !== ''): ?>
    <div style="margin-bottom:12px;padding:10px 14px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:13px;">
        <?= h((string)$_GET['err']) ?>
    </div>
<?php endif; ?>

<!-- ===== FORM ===== -->
<div class="card">
    <h4 style="margin:0 0 4px 0;"><?= $edit ? 'Editar turma' : 'Nova turma' ?></h4>
    <p style="margin:0 0 16px 0;font-size:12px;color:var(--muted);">Janelas de inscrição, configuração de live e integração com SuperFuncionário.</p>

    <form method="post" id="form-turma">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

        <!-- Identificação -->
        <p class="section-label">Identificação</p>
        <div class="grid2t">
            <label>
                <span class="field-lbl">Código da turma <span style="color:#ef4444">*</span></span>
                <input type="text" name="codigo" required value="<?= h($edit['codigo'] ?? '') ?>" placeholder="ex: TURMA_ABRIL_2025">
            </label>
            <label>
                <span class="field-lbl">Código da live <span style="color:var(--muted);font-weight:400">(slug opcional)</span></span>
                <input type="text" name="codigo_live" value="<?= h($edit['codigo_live'] ?? '') ?>" placeholder="ex: live-perfil-led-18dez">
            </label>
        </div>

        <!-- Certificado -->
        <p class="section-label">Certificado</p>
        <div style="max-width:480px;">
            <label>
                <span class="field-lbl">Senha do certificado desta turma</span>
                <input type="text" name="senha_certificado"
                    value="<?= h((string)($edit['senha_certificado'] ?? '')) ?>"
                    placeholder="Ex.: TURMA_ABRIL" autocomplete="off">
            </label>
            <div style="font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.6;">
                Usada quando o modo de senha está configurado como <strong>Variável</strong> em
                <a href="certificado_config.php" style="color:#facc15;">Configuração de Certificado</a>.
                No modo modular+variável, esta é a <strong>última parte</strong> da senha.
            </div>
        </div>

        <!-- Janelas e data -->
        <p class="section-label">Janela de Inscrição & Data da Live</p>
        <div class="grid3t">
            <label>
                <span class="field-lbl">Janela início <span style="color:#ef4444">*</span></span>
                <input type="datetime-local" name="janela_inicio" required value="<?= h(dt_local_value($edit['janela_inicio'] ?? null)) ?>">
            </label>
            <label>
                <span class="field-lbl">Janela fim <span style="color:#ef4444">*</span></span>
                <input type="datetime-local" name="janela_fim" required value="<?= h(dt_local_value($edit['janela_fim'] ?? null)) ?>">
            </label>
            <label>
                <span class="field-lbl">Data/hora da live</span>
                <input type="datetime-local" name="data_live" value="<?= h(dt_local_value($edit['data_live'] ?? null)) ?>">
            </label>
        </div>

        <!-- Webhook -->
        <p class="section-label">Disparo Automático — Webhook</p>
        <div class="toggle-panel">
            <div class="toggle-header" onclick="togglePanel('wh-body','wh-ico')">
                <label class="chk-label" onclick="event.stopPropagation()">
                    <input type="checkbox" name="live_webhook_enabled" id="wh-chk"
                        <?= (!isset($edit['live_webhook_enabled']) || (int)($edit['live_webhook_enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Habilitar webhook da live
                </label>
                <span style="margin-left:auto;font-size:11px;color:var(--muted);" id="wh-ico">▾</span>
            </div>
            <div class="toggle-body" id="wh-body">
                <label>
                    <span class="field-lbl">URL do Webhook</span>
                    <input type="text" name="webhook_live_url" value="<?= h($edit['webhook_live_url'] ?? '') ?>" placeholder="https://...">
                </label>
                <div class="grid2t" style="margin-top:10px;">
                    <label>
                        <span class="field-lbl">Delay entre envios (ms)</span>
                        <input type="number" name="delay_ms" value="<?= h((string)($edit['delay_ms'] ?? '500')) ?>" min="0" max="30000">
                    </label>
                    <label>
                        <span class="field-lbl">Disparar webhook em</span>
                        <input type="datetime-local" name="live_disparo_data" value="<?= h(dt_local_value($edit['live_disparo_data'] ?? null)) ?>">
                        <span style="font-size:11px;color:var(--muted);margin-top:3px;display:block">Se vazio, não dispara automaticamente.</span>
                    </label>
                </div>

                <!-- Filtros de tag -->
                <p class="section-label" style="margin-top:16px;">Filtros por tags e progresso</p>
                <div style="font-size:12px;color:var(--muted);margin-bottom:10px;">
                    Se nada for marcado, todos os alunos da turma serão enviados.
                </div>
                <div class="grid2t">
                    <div>
                        <div style="font-size:12px;font-weight:600;margin-bottom:6px;">ENVIAR se tiver pelo menos 1 dessas tags</div>
                        <div class="tag-filter-box">
                            <input type="text" class="tag-search" data-target="live_include_tag_ids" placeholder="Buscar tag..." style="width:100%;">
                            <select name="live_include_tag_ids[]" id="live_include_tag_ids" multiple size="8" style="width:100%;">
                                <?php foreach ($allTags as $tg): $tid=(int)$tg['id']; ?>
                                    <option value="<?= $tid ?>" <?= isset($selInc[$tid])?'selected':'' ?>><?= h($tg['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Vazio = não filtra por inclusão.</div>
                    </div>
                    <div>
                        <div style="font-size:12px;font-weight:600;margin-bottom:6px;">NÃO ENVIAR se tiver qualquer 1 dessas tags</div>
                        <div class="tag-filter-box">
                            <input type="text" class="tag-search" data-target="live_exclude_tag_ids" placeholder="Buscar tag..." style="width:100%;">
                            <select name="live_exclude_tag_ids[]" id="live_exclude_tag_ids" multiple size="8" style="width:100%;">
                                <?php foreach ($allTags as $tg): $tid=(int)$tg['id']; ?>
                                    <option value="<?= $tid ?>" <?= isset($selExc[$tid])?'selected':'' ?>><?= h($tg['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
                    <label class="chk-label" style="font-size:12px;font-weight:400;">
                        <input type="checkbox" name="live_exclude_cert" value="1" <?= $excCert?'checked':'' ?>>
                        Excluir alunos com certificado emitido
                    </label>
                    <label class="chk-label" style="font-size:12px;font-weight:400;">
                        <input type="checkbox" name="live_exclude_zero" value="1" <?= $excZero?'checked':'' ?>>
                        Excluir alunos com 0% de progresso
                    </label>
                </div>
            </div>
        </div>

        <!-- SuperFuncionário -->
        <p class="section-label">SuperFuncionário <span style="font-weight:400;text-transform:none;font-size:11px;letter-spacing:0"> — disparo por aluno ao chegar na data</span></p>
        <div class="toggle-panel">
            <div class="toggle-header" onclick="togglePanel('sf-body','sf-ico')">
                <label class="chk-label" onclick="event.stopPropagation()">
                    <input type="checkbox" name="sf_enabled" id="sf-chk"
                        <?= (!empty($edit['sf_enabled']) && (int)$edit['sf_enabled']===1) ? 'checked' : '' ?>>
                    Disparar alunos no SuperFuncionário ao chegar na data
                </label>
                <span style="margin-left:auto;font-size:11px;color:var(--muted);" id="sf-ico">▾</span>
            </div>
            <div class="toggle-body<?= (empty($edit['sf_enabled']) || (int)($edit['sf_enabled'] ?? 0) !== 1) ? ' hidden' : '' ?>" id="sf-body">
                <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">
                    Usa as <b>credenciais globais</b> configuradas em SuperFuncionário.
                    O endpoint e o token são os mesmos. Configure aqui as tags, fluxos e campos personalizados <b>específicos desta turma</b>.
                </div>

                <div class="grid2t">
                    <label>
                        <span class="field-lbl">Tags SF (uma por linha)</span>
                        <textarea name="sf_tags_text" placeholder="ex: LIVE_CONFIRMADO" style="min-height:80px;"><?= h((string)($edit['sf_tags_text'] ?? '')) ?></textarea>
                    </label>
                    <label>
                        <span class="field-lbl">Flow IDs (separados por vírgula)</span>
                        <input type="text" name="sf_flows_text" value="<?= h((string)($edit['sf_flows_text'] ?? '')) ?>" placeholder="ex: 123, 456">
                        <span style="font-size:11px;color:var(--muted);margin-top:4px;display:block;">IDs numéricos dos fluxos do SF.</span>
                    </label>
                </div>

                <p class="section-label" style="margin-top:16px;font-size:10.5px;">Campos personalizados</p>
                <div class="sf-hint">
                    <b>Origem</b> — selecione da lista ou digite livremente:<br>
                    • Aluno: <code>user.email</code>, <code>user.nome</code>, <code>user.telefone</code><br>
                    • Turma: <code>extra.codigo_turma</code>, <code>extra.data_live</code>, <code>extra.codigo_live</code><br>
                    • Progresso: <code>extra.andamento</code>, <code>extra.aulas_concluidas</code><br>
                    • Fixo: <code>literal:texto</code> &nbsp;|&nbsp; Template: <code>{{user.nome}} - {{extra.andamento}}%</code><br>
                    • Fallback: <code>extra.codigo_live|extra.codigo_turma|literal:GERAL</code>
                </div>

                <div id="sf-fields">
                    <?php foreach ($sfEditPairs as $p): ?>
                    <div class="row-sf">
                        <input type="text" name="sf_field_source[]" list="sf-turma-source-list"
                               value="<?= h((string)($p['source'] ?? '')) ?>"
                               placeholder="ex: user.email ou extra.data_live">
                        <input type="text" name="sf_field_dest[]" value="<?= h((string)($p['dest'] ?? '')) ?>"
                               placeholder="Campo destino no SF">
                        <button class="btnx" type="button" onclick="removeSfRow(this)">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-sm" onclick="addSfRow()" style="margin-top:4px;">+ Adicionar campo</button>
            </div>
        </div>

        <!-- Ações -->
        <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
            <button class="btn" type="submit"><?= $edit ? 'Salvar alterações' : 'Criar turma' ?></button>
            <?php if ($edit): ?>
                <a class="btn-secondary" href="turmas.php">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ===== TABELA ===== -->
<div class="card">
    <h4 style="margin:0 0 12px 0;">Turmas cadastradas</h4>
    <?php if (!$turmas): ?>
        <p style="color:var(--muted);font-size:13px;">Nenhuma turma cadastrada ainda.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table table-turmas" style="width:100%;min-width:800px;">
        <thead>
        <tr>
            <th>Código</th>
            <th>Janela</th>
            <th>Live</th>
            <th>Disparo em</th>
            <th>Webhook</th>
            <th>SF</th>
            <th>Disparado</th>
            <th>Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($turmas as $t): ?>
            <?php
            $cfg2    = parse_live_filter_cfg($t['live_filter_tag_ids'] ?? null);
            $tagMap  = array_column($allTags, 'nome', 'id');
            $filterParts = [];
            if (!empty($cfg2['include_any'])) {
                $filterParts[] = 'Inclui: ' . implode(', ', array_map(fn($id)=>$tagMap[$id]??'#'.$id, $cfg2['include_any']));
            }
            if (!empty($cfg2['exclude_any'])) {
                $filterParts[] = 'Exclui: ' . implode(', ', array_map(fn($id)=>$tagMap[$id]??'#'.$id, $cfg2['exclude_any']));
            }
            if (!empty($cfg2['exclude_cert'])) $filterParts[] = '−CERT';
            if (!empty($cfg2['exclude_zero'])) $filterParts[] = '−0%';
            $whEnabled = (int)($t['live_webhook_enabled'] ?? 1) === 1;
            $sfEnabled = (int)($t['sf_enabled'] ?? 0) === 1;
            $disparada = (int)($t['live_disparada'] ?? 0) === 1;
            ?>
            <tr>
                <td>
                    <strong><?= h((string)$t['codigo']) ?></strong>
                    <?php if (!empty($t['codigo_live'])): ?>
                        <br><span style="font-size:10.5px;color:var(--muted);"><?= h((string)$t['codigo_live']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;font-size:11px;">
                    <?= h(substr((string)($t['janela_inicio']??''),0,16)) ?><br>
                    <span style="color:var(--muted)">→ <?= h(substr((string)($t['janela_fim']??''),0,16)) ?></span>
                </td>
                <td style="white-space:nowrap;font-size:11px;"><?= h(substr((string)($t['data_live']??'—'),0,16)) ?></td>
                <td style="white-space:nowrap;font-size:11px;"><?= h(substr((string)($t['live_disparo_data']??'—'),0,16)) ?></td>
                <td>
                    <?php if ($whEnabled && !empty($t['webhook_live_url'])): ?>
                        <span class="badge-ok">ON</span>
                        <?php if ($filterParts): ?>
                            <br><span style="font-size:10px;color:var(--muted);" title="<?= h(implode(' | ', $filterParts)) ?>">Filtros</span>
                        <?php endif; ?>
                    <?php elseif ($whEnabled): ?>
                        <span class="badge-warn">Sem URL</span>
                    <?php else: ?>
                        <span class="badge-off">OFF</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sfEnabled): ?>
                        <span class="badge-ok">ON</span>
                    <?php else: ?>
                        <span class="badge-off">OFF</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($disparada): ?>
                        <span class="badge-ok">Sim</span>
                    <?php else: ?>
                        <span class="badge-off">Não</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <a href="?edit=<?= (int)$t['id'] ?>" class="btn-sm">Editar</a>
                    <?php if ($disparada): ?>
                        <a href="?reset_disparo=<?= (int)$t['id'] ?>" class="btn-sm btn-warn" onclick="return confirm('Resetar disparo? Webhook e SF serão enviados novamente!')" style="color:#fbbf24;border-color:rgba(251,191,36,.3)">Re-disparar</a>
                    <?php endif; ?>
                    <a href="?del=<?= (int)$t['id'] ?>" class="btn-sm btn-danger-sm" onclick="return confirm('Remover turma?')">Remover</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.page-turmas -->

<script>
// Toggle panel open/close
function togglePanel(bodyId, icoId) {
    var body = document.getElementById(bodyId);
    var ico  = document.getElementById(icoId);
    if (!body) return;
    var isHidden = body.classList.contains('hidden');
    body.classList.toggle('hidden', !isHidden);
    if (ico) ico.textContent = isHidden ? '▴' : '▾';
}

// Auto-open webhook panel if URL or disparo_data is set
(function(){
    var wh = document.getElementById('wh-body');
    var sf = document.getElementById('sf-body');
    // sf panel: already opened server-side if sf_enabled; just sync icon
    if (sf && !sf.classList.contains('hidden')) {
        var sfIco = document.getElementById('sf-ico');
        if (sfIco) sfIco.textContent = '▴';
    }
    if (wh && !wh.classList.contains('hidden')) {
        var whIco = document.getElementById('wh-ico');
        if (whIco) whIco.textContent = '▴';
    }
    // open webhook panel if checkbox is checked
    var whChk = document.getElementById('wh-chk');
    if (whChk && whChk.checked && wh && wh.classList.contains('hidden')) {
        togglePanel('wh-body','wh-ico');
    }
    // toggle SF panel when checkbox changes
    var sfChk = document.getElementById('sf-chk');
    if (sfChk) {
        sfChk.addEventListener('change', function(){
            var body = document.getElementById('sf-body');
            if (!body) return;
            if (sfChk.checked) {
                body.classList.remove('hidden');
                document.getElementById('sf-ico').textContent = '▴';
            } else {
                body.classList.add('hidden');
                document.getElementById('sf-ico').textContent = '▾';
            }
        });
    }
})();

// SF custom fields rows
function removeSfRow(btn) { btn.closest('.row-sf').remove(); }
function addSfRow() {
    var c = document.getElementById('sf-fields');
    var d = document.createElement('div');
    d.className = 'row-sf';
    d.innerHTML =
        '<input type="text" name="sf_field_source[]" list="sf-turma-source-list" placeholder="ex: user.email ou extra.data_live">' +
        '<input type="text" name="sf_field_dest[]" placeholder="Campo destino no SF">' +
        '<button class="btnx" type="button" onclick="removeSfRow(this)">×</button>';
    c.appendChild(d);
    d.querySelector('input[name="sf_field_source[]"]').focus();
}

// Tag search filter for multiselects
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.tag-search').forEach(function(inp){
        inp.addEventListener('input', function(){
            var q = (inp.value||'').toLowerCase();
            var sel = document.getElementById(inp.getAttribute('data-target'));
            if (!sel) return;
            Array.from(sel.options).forEach(function(opt){
                opt.style.display = (opt.textContent||'').toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    });
});

// Form validation
document.getElementById('form-turma').addEventListener('submit', function(e){
    var rows = document.querySelectorAll('#sf-fields .row-sf');
    var errors = [];
    rows.forEach(function(row, i){
        var src = row.querySelector('input[name="sf_field_source[]"]').value.trim();
        var dst = row.querySelector('input[name="sf_field_dest[]"]').value.trim();
        if (src !== '' && dst === '') errors.push('Campo SF linha ' + (i+1) + ': destino vazio');
        if (src === '' && dst !== '') errors.push('Campo SF linha ' + (i+1) + ': origem vazia');
    });
    if (errors.length > 0) {
        if (!confirm('Atenção:\n\n' + errors.join('\n') + '\n\nSalvar mesmo assim?')) e.preventDefault();
    }
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
