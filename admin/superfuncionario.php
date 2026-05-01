<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/superfuncionario_dispatcher.php';

proteger_admin();
$pdo = getPDO();

// menu ativo
$menu = 'superfuncionario';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function post_str(string $k): string { return trim((string)($_POST[$k] ?? '')); }
function post_int(string $k): int { return (int)($_POST[$k] ?? 0); }

// garante tabelas
sf_ensure_tables($pdo);

// ===== eventos (mesmos do Webhooks) =====
$eventOptions = [
    'INSCRITO'              => 'Aluno se cadastrou na área de membros',
    'ASSISTIU_ALGUMA_AULA'  => 'Aluno assistiu pelo menos 10 segundos de qualquer aula',
    'CONCLUIU_TRILHA'       => 'Concluiu todas as aulas obrigatórias',
    'CERT_EMITIDO'          => 'Certificado emitido com sucesso',
    'CERT_SENHA_ERRADA'     => 'Tentativa de senha de certificado incorreta',
];

// dinâmico por aula
try {
    $stLessons = $pdo->query("SELECT id, titulo FROM lessons ORDER BY ordem ASC, id ASC");
    while ($ls = $stLessons->fetch(PDO::FETCH_ASSOC)) {
        $lessonId   = (int)($ls['id'] ?? 0);
        $lessonName = trim((string)($ls['titulo'] ?? 'Aula sem título'));
        if ($lessonId > 0) {
            $code = 'VIU_AULA_' . $lessonId;
            $eventOptions[$code] = $code . ' – ' . $lessonName;
        }
    }
} catch (Throwable $e) { /* ignora */ }

// ===== opções de campos (origem) =====
$fieldOptions = [
    'Payload (fixo)' => [
        'evento'        => 'Evento (código)',
        'timestamp'     => 'Timestamp (ISO)',
        'user.id'       => 'User ID',
        'user.nome'     => 'Nome',
        'user.email'    => 'Email',
        'user.telefone' => 'Telefone',
    ],
    'Extra — INSCRITO / LIVE' => [
        'extra.codigo_live'    => 'codigo_live',
        'extra.data_live'      => 'data_live',
        'extra.andamento'      => 'andamento (% conclusão)',
        'extra.aulas_concluidas' => 'aulas_concluidas',
        'extra.aulas_totais'   => 'aulas_totais',
    ],
    'Extra — CERT_EMITIDO' => [
        'extra.pdf_url'           => 'pdf_url (link do certificado)',
        'extra.codigo_certificado' => 'codigo_certificado',
        'extra.curso'             => 'curso',
        'extra.emitido_em'        => 'emitido_em',
    ],
    'Extra — CERT_SENHA_ERRADA' => [
        'extra.motivo' => 'motivo',
    ],
    'Users (tabela users)' => [],
];

// hints por evento — exibidos dinamicamente no formulário
$eventHints = [
    'INSCRITO' => 'Extras disponíveis: <code>extra.codigo_live</code>, <code>extra.data_live</code>',
    'CONCLUIU_TRILHA' => 'Extras disponíveis: <code>extra.andamento</code>, <code>extra.aulas_concluidas</code>, <code>extra.aulas_totais</code>',
    'CERT_EMITIDO' => 'Extras disponíveis: <code>extra.pdf_url</code> (link do PDF), <code>extra.codigo_certificado</code>, <code>extra.curso</code>, <code>extra.emitido_em</code>',
    'CERT_SENHA_ERRADA' => 'Extras disponíveis: <code>extra.motivo</code> (valor: <code>senha_incorreta</code>)',
];

// pega colunas reais da tabela users (para você mapear qualquer dado salvo)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($cols as $c) {
        $name = (string)($c['Field'] ?? '');
        if ($name === '') continue;
        $fieldOptions['Users (tabela users)']['users.' . $name] = 'users.' . $name;
    }
} catch (Throwable $e) {
    // se não conseguir, segue sem a lista
}

// ===== salvar config =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
    $baseUrl = post_str('base_url');
    $token = post_str('token');
    $defaultEndpoint = post_str('default_endpoint');
    $headerMode = post_str('header_mode');
    $timeoutSeconds = max(1, post_int('timeout_seconds'));

    if (!in_array($headerMode, ['x-access-token','bearer'], true)) $headerMode = 'x-access-token';
    if ($defaultEndpoint === '') $defaultEndpoint = '/api/contacts';

    // upsert: atualiza linha existente ou cria se não houver
    $existing = $pdo->query("SELECT id FROM superfuncionario_config ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($existing) {
        $st = $pdo->prepare("
            UPDATE superfuncionario_config
               SET is_enabled=:en, base_url=:bu, token=:tk,
                   default_endpoint=:ep, header_mode=:hm, timeout_seconds=:to
             WHERE id=:id LIMIT 1
        ");
        $st->execute([':en'=>$isEnabled,':bu'=>$baseUrl,':tk'=>$token,':ep'=>$defaultEndpoint,':hm'=>$headerMode,':to'=>$timeoutSeconds,':id'=>$existing]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO superfuncionario_config (is_enabled, base_url, token, default_endpoint, header_mode, timeout_seconds)
            VALUES (:en,:bu,:tk,:ep,:hm,:to)
        ");
        $st->execute([':en'=>$isEnabled,':bu'=>$baseUrl,':tk'=>$token,':ep'=>$defaultEndpoint,':hm'=>$headerMode,':to'=>$timeoutSeconds]);
    }

    header('Location: superfuncionario.php');
    exit;
}

// ===== CRUD regras =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rule') {
    $id = post_int('id');
    $nome = post_str('nome');
    $evento = post_str('evento');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $tagsText = post_str('tags_text');
    $flowsText = post_str('flows_text');
    $endpointOverride = post_str('endpoint_override');

    // mapping (arrays paralelos)
    $sources = $_POST['field_source'] ?? [];
    $dests   = $_POST['field_dest'] ?? [];

    $pairs = [];
    if (is_array($sources) && is_array($dests)) {
        $n = min(count($sources), count($dests));
        for ($i=0; $i<$n; $i++) {
            $src = trim((string)$sources[$i]);
            $dst = trim((string)$dests[$i]);
            if ($src === '' || $dst === '') continue;
            $pairs[] = ['source' => $src, 'dest' => $dst];
        }
    }

    $fieldsJson = json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($id > 0) {
        $st = $pdo->prepare("
            UPDATE superfuncionario_rules
               SET nome=:n, evento=:e, is_active=:a,
                   tags_text=:t, flows_text=:f, endpoint_override=:ep, fields_json=:fj
             WHERE id=:id
             LIMIT 1
        ");
        $st->execute([
            ':n' => $nome,
            ':e' => $evento,
            ':a' => $isActive,
            ':t' => $tagsText,
            ':f' => $flowsText,
            ':ep'=> $endpointOverride !== '' ? $endpointOverride : null,
            ':fj'=> $fieldsJson,
            ':id'=> $id,
        ]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO superfuncionario_rules (nome, evento, is_active, tags_text, flows_text, endpoint_override, fields_json)
            VALUES (:n,:e,:a,:t,:f,:ep,:fj)
        ");
        $st->execute([
            ':n' => $nome,
            ':e' => $evento,
            ':a' => $isActive,
            ':t' => $tagsText,
            ':f' => $flowsText,
            ':ep'=> $endpointOverride !== '' ? $endpointOverride : null,
            ':fj'=> $fieldsJson,
        ]);
    }

    header('Location: superfuncionario.php');
    exit;
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $pdo->prepare("DELETE FROM superfuncionario_rules WHERE id=:id")->execute([':id'=>$id]);
    header('Location: superfuncionario.php');
    exit;
}

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE superfuncionario_rules SET is_active = IF(is_active=1,0,1) WHERE id=:id")->execute([':id'=>$id]);
    header('Location: superfuncionario.php');
    exit;
}

// === POST: salvar config SF por turma ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sf_turma_save') {
    $tid        = (int)($_POST['turma_id'] ?? 0);
    $sfEnabled  = isset($_POST['sf_enabled']) ? 1 : 0;
    $sfTags     = trim($_POST['sf_tags_text'] ?? '');
    $sfFlows    = trim($_POST['sf_flows_text'] ?? '');
    $sfSources  = $_POST['sf_field_source'] ?? [];
    $sfDests    = $_POST['sf_field_dest'] ?? [];
    $sfPairs    = [];
    if (is_array($sfSources) && is_array($sfDests)) {
        $n = min(count($sfSources), count($sfDests));
        for ($i = 0; $i < $n; $i++) {
            $src = trim((string)$sfSources[$i]);
            $dst = trim((string)$sfDests[$i]);
            if ($src !== '' && $dst !== '') $sfPairs[] = ['source'=>$src,'dest'=>$dst];
        }
    }
    $sfFieldsJson = $sfPairs ? json_encode($sfPairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    if ($tid > 0) {
        try {
            $pdo->prepare("UPDATE turmas SET sf_enabled=:sfen,sf_tags_text=:sftt,sf_flows_text=:sfft,sf_fields_json=:sffj,live_disparada=0 WHERE id=:id")
                ->execute([':sfen'=>$sfEnabled,':sftt'=>$sfTags?:null,':sfft'=>$sfFlows?:null,':sffj'=>$sfFieldsJson,':id'=>$tid]);
        } catch (Throwable $e) {}
    }
    header('Location: superfuncionario.php?sf_edit=' . $tid . '&saved=1');
    exit;
}

// ===== carregar config e regras =====
$cfg = sf_get_config($pdo);

$rules = $pdo->query("SELECT * FROM superfuncionario_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $st = $pdo->prepare("SELECT * FROM superfuncionario_rules WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$editPairs = [];
if ($edit) {
    // Prefere custom_fields_json (novo), cai em fields_json (legado)
    $cfRaw = trim((string)($edit['custom_fields_json'] ?? ''));
    $fjRaw = trim((string)($edit['fields_json'] ?? ''));
    $rawJson = $cfRaw !== '' ? $cfRaw : $fjRaw;
    if ($rawJson !== '') {
        $tmp = json_decode($rawJson, true);
        if (is_array($tmp)) $editPairs = $tmp;
    }
}

// Datalist plano para o input de origem
$fieldDatalist = [];
foreach ($fieldOptions as $opts) {
    foreach ($opts as $val => $lab) {
        $fieldDatalist[$val] = $lab;
    }
}

$sfEditTurma = null;
if (isset($_GET['sf_edit'])) {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
    $st->execute([':id' => (int)$_GET['sf_edit']]);
    $sfEditTurma = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$sfTurmasList = $pdo->query("SELECT * FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/_header.php';
?>

<style>
.page-sf { max-width: 1200px; margin: 0 auto; }
.grid2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 980px){ .grid2{ grid-template-columns: 1fr; } }
.small { font-size:12px; color:var(--muted); }
input[type="text"], input[type="number"], select, textarea { width:100%; }
textarea{ min-height:78px; }
.sf-badge { display:inline-block; padding:3px 8px; border-radius:999px; border:1px solid var(--border); font-size:11px; color:var(--text); background:var(--bg); }
.sf-badge.off { opacity:.55; }
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:10px 8px; border-bottom:1px solid var(--border); font-size:12px; text-align:left; vertical-align:top; color:var(--text); }
.table th { color:var(--muted); font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
.row { display:grid; grid-template-columns: 1fr 1fr 34px; gap:8px; align-items:center; margin-bottom:8px; }
.btnx { width:34px; height:34px; border-radius:10px; border:1px solid var(--border); background:var(--bg-card); color:var(--muted); cursor:pointer; transition:background var(--t); }
.btnx:hover { background:var(--danger-dim); color:var(--danger); border-color:rgba(239,68,68,.25); }
.sf-logs-table td { font-size:11px; }
.sf-ok    { color:#15803d; }
.sf-fail  { color:#dc2626; }
.sf-hint  { font-size:11px; color:var(--muted); background:rgba(255,255,255,.04); border-radius:8px; padding:8px 10px; margin-bottom:8px; line-height:1.6; }
.sf-hint code { background:rgba(255,255,255,.08); border-radius:4px; padding:1px 5px; font-size:10.5px; }
</style>

<!-- datalist para o campo de origem -->
<datalist id="sf-source-list">
<?php foreach ($fieldDatalist as $val => $lab): ?>
    <option value="<?= h($val) ?>"><?= h($lab) ?></option>
<?php endforeach; ?>
</datalist>

<div class="page-sf">
    <div class="card">
        <h3 style="margin:0 0 6px 0;">Integrações (Saída) — SuperFuncionário</h3>
        <div class="small">
            Configure as credenciais globais e crie regras por <b>evento</b> (os mesmos eventos da tela de Webhooks).
            Cada regra pode: criar/atualizar contato, aplicar tags, enviar campos personalizados e disparar fluxos.
        </div>
    </div>

    <div class="grid2">
        <div class="card">
            <h4 style="margin:0 0 10px 0;">SuperFuncionário (credenciais fixas)</h4>
            <form method="post">
                <input type="hidden" name="action" value="save_config">
                <label class="small">
                    <input type="checkbox" name="is_enabled" <?= ((int)$cfg['is_enabled']===1?'checked':'') ?>> Ativar integração
                </label>
                <div style="height:8px"></div>

                <label class="small">Base URL (opcional se usar o default)<br>
                    <input type="text" name="base_url" placeholder="https://app.superfuncionario.com.br" value="<?= h((string)$cfg['base_url']) ?>">
                </label>
                <div style="height:8px"></div>

                <label class="small">Token<br>
                    <input type="text" name="token" value="<?= h((string)$cfg['token']) ?>" placeholder="cole o token aqui">
                </label>
                <div style="height:8px"></div>

                <label class="small">Modo do Header<br>
                    <select name="header_mode">
                        <option value="x-access-token" <?= $cfg['header_mode']==='x-access-token'?'selected':'' ?>>X-ACCESS-TOKEN</option>
                        <option value="bearer" <?= $cfg['header_mode']==='bearer'?'selected':'' ?>>Authorization: Bearer</option>
                    </select>
                </label>

                <div style="height:8px"></div>

                <label class="small">Default Endpoint (path ou URL)<br>
                    <input type="text" name="default_endpoint" value="<?= h((string)$cfg['default_endpoint']) ?>" placeholder="/api/contacts">
                </label>

                <div style="height:8px"></div>

                <label class="small">Timeout (segundos)<br>
                    <input type="number" name="timeout_seconds" value="<?= (int)$cfg['timeout_seconds'] ?>" min="1" max="60">
                </label>

                <div style="height:10px"></div>
                <button class="btn" type="submit">Salvar credenciais</button>
            </form>

            <div class="small" style="margin-top:10px;">
                Essas credenciais são globais. As regras abaixo definem <b>quando</b> e <b>o que</b> enviar.
            </div>
        </div>

        <div class="card">
            <h4 style="margin:0 0 10px 0;"><?= $edit ? 'Editar regra' : 'Nova integração' ?></h4>

            <form method="post" id="form-rule">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <label class="small">Nome<br>
                        <input type="text" name="nome" value="<?= h((string)($edit['nome'] ?? '')) ?>" placeholder="Ex.: SF - CTA Click">
                    </label>

                    <label class="small">Gatilho (evento)<br>
                        <select name="evento">
                            <?php foreach ($eventOptions as $code => $label): ?>
                                <option value="<?= h($code) ?>" <?= ($edit && (string)$edit['evento']===(string)$code)?'selected':'' ?>>
                                    <?= h($code) ?> — <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="height:8px"></div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <label class="small">Tag(s) (uma por linha)<br>
                        <textarea name="tags_text" placeholder="Ex.: TAG_CTA"><?= h((string)($edit['tags_text'] ?? '')) ?></textarea>
                    </label>

                    <label class="small">Fluxo / Flow ID(s) (separados por vírgula)<br>
                        <input type="text" name="flows_text" value="<?= h((string)($edit['flows_text'] ?? '')) ?>" placeholder="Ex.: 123,456">
                        <div class="small" style="margin-top:6px;">O SF também permite disparar fluxo dentro do POST /contacts.</div>
                    </label>
                </div>

                <div style="height:8px"></div>

                <label class="small">Endpoint (opcional - sobrescreve o default)<br>
                    <input type="text" name="endpoint_override" value="<?= h((string)($edit['endpoint_override'] ?? '')) ?>" placeholder="/api/contacts">
                </label>

                <div style="height:10px"></div>

                <h4 style="margin:10px 0 6px 0;">Campos personalizados</h4>
                <div class="sf-hint" id="sf-event-hint" style="display:none;margin-bottom:6px;border-left:3px solid rgba(250,204,21,.5);padding-left:10px;"></div>
                <div class="sf-hint">
                    <b>Origem</b> — selecione da lista ou digite livremente:<br>
                    • Caminho simples: <code>user.email</code>, <code>extra.codigo_live</code><br>
                    • Caminho profundo: <code>extra.data.purchase.transaction</code><br>
                    • Valor fixo: <code>literal:texto aqui</code><br>
                    • Template: <code>{{user.nome}} - {{evento}}</code><br>
                    • Fallback: <code>user.telefone|extra.phone|literal:sem_telefone</code>
                </div>

                <div id="fields">
                    <?php
                    $initialPairs = $editPairs ?: [['source' => '', 'dest' => '']];
                    foreach ($initialPairs as $p):
                    ?>
                        <div class="row">
                            <input type="text" name="field_source[]" list="sf-source-list"
                                   value="<?= h((string)($p['source'] ?? '')) ?>"
                                   placeholder="ex.: user.email ou extra.data.id">
                            <input type="text" name="field_dest[]" value="<?= h((string)($p['dest'] ?? '')) ?>"
                                   placeholder="Campo destino no SF (ex.: idade)">
                            <button class="btnx" type="button" onclick="removeRow(this)">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="btn-secondary" type="button" onclick="addRow()">Adicionar campo</button>

                <div style="height:10px"></div>
                <label class="small">
                    <input type="checkbox" name="is_active" <?= (!$edit || (int)($edit['is_active'] ?? 1)===1)?'checked':'' ?>> Regra ativa
                </label>

                <div style="height:10px"></div>
                <button class="btn" type="submit"><?= $edit ? 'Salvar regra' : 'Criar integração' ?></button>
                <?php if ($edit): ?>
                    <a class="btn-secondary" href="superfuncionario.php" style="margin-left:8px;">Cancelar</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <h4 style="margin:0 0 10px 0;">Integrações cadastradas (regras)</h4>
        <table class="table">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Evento</th>
                <th>Campos</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rules): ?>
                <tr><td colspan="5" class="small">Nenhuma regra cadastrada ainda.</td></tr>
            <?php else: ?>
                <?php foreach ($rules as $r): ?>
                    <?php
                    $rCfRaw = trim((string)($r['custom_fields_json'] ?? ''));
                    $rFjRaw = trim((string)($r['fields_json'] ?? ''));
                    $rFieldsRaw = $rCfRaw !== '' ? $rCfRaw : $rFjRaw;
                    $rFields = [];
                    if ($rFieldsRaw !== '') {
                        $rTmp = json_decode($rFieldsRaw, true);
                        if (is_array($rTmp)) {
                            foreach ($rTmp as $fp) {
                                if (trim((string)($fp['source'] ?? '')) !== '' && trim((string)($fp['dest'] ?? '')) !== '') {
                                    $rFields[] = $fp;
                                }
                            }
                        }
                    }
                    ?>
                    <tr>
                        <td><?= h((string)$r['nome']) ?></td>
                        <td><span class="sf-badge"><?= h((string)$r['evento']) ?></span></td>
                        <td style="color:var(--muted)">
                            <?php if ($rFields): ?>
                                <span title="<?= h(implode(', ', array_column($rFields, 'dest'))) ?>"><?= count($rFields) ?> campo<?= count($rFields) !== 1 ? 's' : '' ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$r['is_active']===1): ?>
                                <span class="sf-badge" style="color:#15803d;background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25)">Ativa</span>
                            <?php else: ?>
                                <span class="sf-badge off">Inativa</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="superfuncionario.php?edit=<?= (int)$r['id'] ?>">Editar</a>
                            &nbsp;|&nbsp;
                            <a href="superfuncionario.php?toggle=<?= (int)$r['id'] ?>"><?= ((int)$r['is_active']===1)?'Desativar':'Ativar' ?></a>
                            &nbsp;|&nbsp;
                            <a href="superfuncionario.php?del=<?= (int)$r['id'] ?>" onclick="return confirm('Remover esta regra?')">Remover</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="small" style="margin-top:8px;">
            Dica: para validar o disparo, ative a regra e provoque o evento (ex.: assistir aula, concluir trilha etc.).
        </div>
    </div>

    <!-- LOGS RECENTES -->
    <?php
    $logs = [];
    try {
        $logs = $pdo->query("
            SELECT sl.id, sl.evento, sl.rule_id, sl.ok, sl.http_status,
                   sl.error_text, sl.response_text, sl.created_at,
                   sr.nome AS rule_nome
            FROM superfuncionario_logs sl
            LEFT JOIN superfuncionario_rules sr ON sr.id = sl.rule_id
            ORDER BY sl.id DESC LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    ?>
    <div class="card">
        <h4 style="margin:0 0 10px 0;">Logs recentes (últimos 30)</h4>
        <?php if (!$logs): ?>
            <p class="small">Nenhum log registrado ainda. Os disparos aparecem aqui automaticamente.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table sf-logs-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Evento</th>
                        <th>Regra</th>
                        <th>Status</th>
                        <th>HTTP</th>
                        <th>Campos</th>
                        <th>Detalhe</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <?php
                    $logDebug = null;
                    $reqRaw = trim((string)($l['request_json'] ?? ''));
                    if ($reqRaw !== '') {
                        $reqArr = json_decode($reqRaw, true);
                        if (is_array($reqArr) && isset($reqArr['_debug'])) {
                            $logDebug = $reqArr['_debug'];
                        }
                    }
                    ?>
                    <tr>
                        <td style="color:var(--muted)"><?= (int)$l['id'] ?></td>
                        <td style="white-space:nowrap"><?= h((string)$l['created_at']) ?></td>
                        <td><span class="sf-badge"><?= h((string)$l['evento']) ?></span></td>
                        <td><?= h((string)($l['rule_nome'] ?? '—')) ?></td>
                        <td>
                            <?php if ((int)$l['ok']): ?>
                                <span class="sf-ok">✓ OK</span>
                            <?php else: ?>
                                <span class="sf-fail">✗ Falha</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $l['http_status'] ? (int)$l['http_status'] : '—' ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($logDebug !== null): ?>
                                <?php
                                $cnt     = (int)($logDebug['custom_fields_count'] ?? 0);
                                $skiped  = (array)($logDebug['skipped_keys'] ?? []);
                                $tooltip = '';
                                if ($cnt > 0) $tooltip .= 'OK: ' . implode(', ', (array)($logDebug['custom_fields_keys'] ?? []));
                                if ($skiped) $tooltip .= ($tooltip ? ' | ' : '') . 'Skip: ' . implode(', ', $skiped);
                                ?>
                                <span title="<?= h($tooltip) ?>" style="<?= $skiped ? 'color:#f59e0b' : 'color:var(--muted)' ?>">
                                    <?= $cnt ?>✓<?= count($skiped) ? ' ' . count($skiped) . '✗' : '' ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="max-width:240px;word-break:break-all">
                            <?php
                            $detail = trim((string)($l['error_text'] ?? ''));
                            if ($detail === '') $detail = trim(substr((string)($l['response_text'] ?? ''), 0, 100));
                            echo h($detail !== '' ? $detail : '—');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== SF POR TURMA ===== -->
    <div class="card">
        <h4 style="margin:0 0 10px 0;">🚀 Disparo de Live por Turma — SuperFuncionário</h4>
        <p class="small" style="margin:0 0 14px 0;">Configure as tags, fluxos e campos personalizados de SF específicos para cada turma, disparados automaticamente na data da live.</p>

        <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
            <div style="margin-bottom:14px;padding:10px 14px;border-radius:10px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.25);color:#4ade80;font-size:13px;">
                Configuração de SF da turma salva com sucesso!
            </div>
        <?php endif; ?>

        <!-- datalist para campos SF da turma -->
        <datalist id="sf-turma-source-list">
            <?php foreach ($fieldDatalist as $val => $lab): ?>
                <option value="<?= h($val) ?>"><?= h($lab) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="grid2" style="align-items:start;">
            <!-- FORM (left) -->
            <div>
                <?php if ($sfEditTurma): ?>
                    <?php
                    $sfTEditPairs = [];
                    $sfTFieldsRaw = trim((string)($sfEditTurma['sf_fields_json'] ?? ''));
                    if ($sfTFieldsRaw !== '') {
                        $tmp = json_decode($sfTFieldsRaw, true);
                        if (is_array($tmp)) $sfTEditPairs = $tmp;
                    }
                    if (!$sfTEditPairs) $sfTEditPairs = [['source'=>'','dest'=>'']];
                    ?>
                    <form method="post" id="form-sf-turma" style="background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;padding:18px;">
                        <input type="hidden" name="action" value="sf_turma_save">
                        <input type="hidden" name="turma_id" value="<?= (int)$sfEditTurma['id'] ?>">

                        <div style="margin-bottom:14px;">
                            <span class="small" style="display:block;font-weight:700;margin-bottom:2px;">Turma</span>
                            <div style="font-size:14px;font-weight:600;color:var(--text);"><?= h((string)$sfEditTurma['codigo']) ?></div>
                            <?php if (!empty($sfEditTurma['data_live'])): ?>
                                <div style="font-size:11px;color:var(--muted);margin-top:2px;">Live: <?= h(substr((string)$sfEditTurma['data_live'],0,16)) ?></div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="small">
                                <input type="checkbox" name="sf_enabled" <?= (int)($sfEditTurma['sf_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                                Disparar alunos no SuperFuncionário ao chegar na data
                            </label>
                        </div>

                        <div class="grid2" style="margin-bottom:12px;">
                            <label class="small">Tags SF (uma por linha)<br>
                                <textarea name="sf_tags_text" placeholder="ex: LIVE_CONFIRMADO" style="min-height:80px;"><?= h((string)($sfEditTurma['sf_tags_text'] ?? '')) ?></textarea>
                            </label>
                            <label class="small">Flow IDs (separados por vírgula)<br>
                                <input type="text" name="sf_flows_text" value="<?= h((string)($sfEditTurma['sf_flows_text'] ?? '')) ?>" placeholder="ex: 123, 456">
                                <div class="small" style="margin-top:4px;">IDs numéricos dos fluxos do SF.</div>
                            </label>
                        </div>

                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:8px;border-bottom:1px solid var(--border);padding-bottom:4px;">Campos personalizados</div>
                        <div class="sf-hint">
                            <b>Origem</b> — selecione da lista ou digite livremente:<br>
                            • Aluno: <code>user.email</code>, <code>user.nome</code>, <code>user.telefone</code><br>
                            • Turma: <code>extra.codigo_turma</code>, <code>extra.data_live</code>, <code>extra.codigo_live</code><br>
                            • Progresso: <code>extra.andamento</code>, <code>extra.aulas_concluidas</code><br>
                            • Fixo: <code>literal:texto</code> | Template: <code>{{user.nome}} - {{extra.andamento}}%</code>
                        </div>

                        <div id="sf-turma-fields">
                            <?php foreach ($sfTEditPairs as $p): ?>
                            <div class="row">
                                <input type="text" name="sf_field_source[]" list="sf-turma-source-list"
                                       value="<?= h((string)($p['source'] ?? '')) ?>"
                                       placeholder="ex: user.email ou extra.data_live">
                                <input type="text" name="sf_field_dest[]" value="<?= h((string)($p['dest'] ?? '')) ?>"
                                       placeholder="Campo destino no SF">
                                <button class="btnx" type="button" onclick="removeSfTurmaRow(this)">×</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-secondary" onclick="addSfTurmaRow()" style="margin-top:4px;">+ Adicionar campo</button>

                        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                            <button class="btn" type="submit">💾 Salvar configuração</button>
                            <a class="btn-secondary" href="superfuncionario.php">Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="text-align:center;padding:24px;color:var(--muted);border:1px dashed var(--border);border-radius:12px;font-size:13px;">
                        Selecione uma turma na tabela ao lado para configurar o SF.
                    </div>
                <?php endif; ?>
            </div>

            <!-- TABLE (right) -->
            <div>
                <?php if (empty($sfTurmasList)): ?>
                    <p class="small">Nenhuma turma cadastrada ainda. <a href="turmas.php">Cadastrar turma</a>.</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Turma</th>
                        <th>Data Live</th>
                        <th>SF</th>
                        <th>Tags</th>
                        <th>Flows</th>
                        <th>Disparado</th>
                        <th>Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sfTurmasList as $stl): ?>
                        <?php
                        $stlSfOn  = (int)($stl['sf_enabled'] ?? 0) === 1;
                        $stlDisp  = (int)($stl['live_disparada'] ?? 0) === 1;
                        $stlTags  = trim((string)($stl['sf_tags_text'] ?? ''));
                        $stlFlows = trim((string)($stl['sf_flows_text'] ?? ''));
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?= h((string)$stl['codigo']) ?></td>
                            <td style="white-space:nowrap;color:var(--muted);"><?= h(substr((string)($stl['data_live']??'—'),0,16)) ?></td>
                            <td>
                                <?php if ($stlSfOn): ?>
                                    <span class="sf-badge" style="color:#15803d;background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25)">ON</span>
                                <?php else: ?>
                                    <span class="sf-badge off">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($stlTags) ?>"><?= h($stlTags ?: '—') ?></td>
                            <td style="color:var(--muted);"><?= h($stlFlows ?: '—') ?></td>
                            <td>
                                <?php if ($stlDisp): ?>
                                    <span class="sf-badge" style="color:#15803d;background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25)">Sim</span>
                                <?php else: ?>
                                    <span class="sf-badge off">Não</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <a href="?sf_edit=<?= (int)$stl['id'] ?>">⚙️ Configurar</a>
                                &nbsp;|&nbsp;
                                <a href="turmas.php?reset_disparo=<?= (int)$stl['id'] ?>" onclick="return confirm('Resetar disparo desta turma?')">↺ Resetar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div><!-- /.page-sf -->

<script>
function removeRow(btn){
    var row = btn.closest('.row');
    if(row) row.remove();
}
function addRow(){
    var container = document.getElementById('fields');
    var tpl = document.createElement('div');
    tpl.className = 'row';
    tpl.innerHTML =
        '<input type="text" name="field_source[]" list="sf-source-list" placeholder="ex.: user.email ou extra.data.id">' +
        '<input type="text" name="field_dest[]" placeholder="Campo destino no SF (ex.: idade)">' +
        '<button class="btnx" type="button" onclick="removeRow(this)">×</button>';
    container.appendChild(tpl);
    container.lastElementChild.querySelector('input[name="field_source[]"]').focus();
}

// Hint dinâmico por evento
var eventHints = <?= json_encode($eventHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function updateEventHint() {
    var sel = document.querySelector('select[name="evento"]');
    var hintDiv = document.getElementById('sf-event-hint');
    if (!sel || !hintDiv) return;
    var hint = eventHints[sel.value];
    if (hint) {
        hintDiv.innerHTML = hint;
        hintDiv.style.display = 'block';
    } else {
        hintDiv.style.display = 'none';
    }
}
(function() {
    var sel = document.querySelector('select[name="evento"]');
    if (sel) {
        sel.addEventListener('change', updateEventHint);
        updateEventHint();
    }
})();

// Validação campos da regra SF
document.getElementById('form-rule').addEventListener('submit', function(e){
    var rows = document.querySelectorAll('#fields .row');
    var errors = [];
    rows.forEach(function(row, i){
        var src = row.querySelector('input[name="field_source[]"]').value.trim();
        var dst = row.querySelector('input[name="field_dest[]"]').value.trim();
        if (src !== '' && dst === '') errors.push('Linha ' + (i+1) + ': destino vazio (origem: ' + src + ')');
        if (src === '' && dst !== '') errors.push('Linha ' + (i+1) + ': origem vazia (destino: ' + dst + ')');
    });
    if (errors.length > 0) {
        if (!confirm('Atenção nos campos personalizados:\n\n' + errors.join('\n') + '\n\nSalvar mesmo assim?')) {
            e.preventDefault();
        }
    }
});

function removeSfTurmaRow(btn) { btn.closest('.row').remove(); }
function addSfTurmaRow() {
    var c = document.getElementById('sf-turma-fields');
    if (!c) return;
    var d = document.createElement('div');
    d.className = 'row';
    d.innerHTML =
        '<input type="text" name="sf_field_source[]" list="sf-turma-source-list" placeholder="ex: user.email ou extra.data_live">' +
        '<input type="text" name="sf_field_dest[]" placeholder="Campo destino no SF">' +
        '<button class="btnx" type="button" onclick="removeSfTurmaRow(this)">×</button>';
    c.appendChild(d);
    d.querySelector('input[name="sf_field_source[]"]').focus();
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
