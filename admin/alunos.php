<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();
$menu       = 'alunos';
$page_title = 'Alunos';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function table_ok(PDO $pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}
function col_ok(PDO $pdo, string $t, string $c): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c");
        $st->execute([':c' => $c]); return (bool)$st->fetch();
    } catch (Throwable $e) { return false; }
}
// fora do foreach para não "Cannot redeclare"
function fmtDt(?string $d): string {
    if (!$d || trim($d) === '') return '-';
    try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return $d; }
}

// ── Detecta colunas e tabelas ─────────────────────────────────────────────
$colTurma   = col_ok($pdo,'users','codigo_turma') ? 'codigo_turma' : (col_ok($pdo,'users','turma') ? 'turma' : '');
$colCreated = col_ok($pdo,'users','created_at')   ? 'created_at'   : (col_ok($pdo,'users','criado_em') ? 'criado_em' : '');
$hasWHL     = table_ok($pdo, 'webhook_logs');
$hasIL      = table_ok($pdo, 'inscricao_logs');
$hasCerts   = table_ok($pdo, 'certificates');
$hasSenha   = col_ok($pdo,'users','senha');
$hasPassword = col_ok($pdo,'users','password');
$senhaCol   = $hasSenha ? 'senha' : ($hasPassword ? 'password' : '');
$hasUtm     = col_ok($pdo,'users','utm_source');

// ── Detecta turmas disponíveis ────────────────────────────────────────────
$turmas = [];
if (table_ok($pdo,'turmas') && $colTurma !== '') {
    $turmas = $pdo->query("SELECT codigo FROM turmas ORDER BY codigo ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// ── POST: ações inline ────────────────────────────────────────────────────
$msgPost = ''; $msgPostTipo = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'trocar_senha' && $senhaCol !== '') {
        $uid = (int)($_POST['uid'] ?? 0);
        $ns  = trim((string)($_POST['nova_senha'] ?? ''));
        $ns2 = trim((string)($_POST['conf_senha']  ?? ''));
        if ($uid <= 0) {
            $msgPost = 'Aluno inválido.'; $msgPostTipo = 'erro';
        } elseif (strlen($ns) < 6) {
            $msgPost = 'Senha deve ter mínimo 6 caracteres.'; $msgPostTipo = 'erro';
        } elseif ($ns !== $ns2) {
            $msgPost = 'As senhas não conferem.'; $msgPostTipo = 'erro';
        } else {
            $hash = password_hash($ns, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET `$senhaCol` = :h WHERE id = :id")->execute([':h' => $hash, ':id' => $uid]);
            $msgPost = 'Senha alterada com sucesso.';
        }
    } elseif ($acao === 'trocar_login') {
        $uid    = (int)($_POST['uid'] ?? 0);
        $nemail = trim((string)($_POST['novo_email'] ?? ''));
        if ($uid <= 0 || !filter_var($nemail, FILTER_VALIDATE_EMAIL)) {
            $msgPost = 'E-mail inválido.'; $msgPostTipo = 'erro';
        } else {
            try {
                $pdo->prepare("UPDATE users SET email = :e WHERE id = :id")->execute([':e' => $nemail, ':id' => $uid]);
                $msgPost = 'E-mail/login atualizado.';
            } catch (Throwable $e) {
                $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
            }
        }
    } elseif ($acao === 'reenviar_cert') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid > 0) {
            try {
                disparar_webhooks('CERT_EMITIDO', $uid, ['origem' => 'admin_reenvio']);
                $msgPost = 'Evento CERT_EMITIDO disparado.';
            } catch (Throwable $e) {
                $msgPost = 'Erro: ' . $e->getMessage(); $msgPostTipo = 'erro';
            }
        }
    }
}

// ── Filtros ────────────────────────────────────────────────────────────────
$q         = trim((string)($_GET['q']           ?? ''));
$fTurma    = trim((string)($_GET['turma']       ?? ''));
$fTag      = trim((string)($_GET['tag']         ?? ''));
$fUtmSrc   = trim((string)($_GET['utm_source']  ?? ''));
$fUtmMed   = trim((string)($_GET['utm_medium']  ?? ''));
$fUtmCamp  = trim((string)($_GET['utm_campaign']?? ''));
$fDateFrom = trim((string)($_GET['date_from']   ?? ''));
$fDateTo   = trim((string)($_GET['date_to']     ?? ''));
$limit     = (int)($_GET['limit'] ?? 100);
$limitsOk  = [10, 100, 200, 500, 1000, 5000, 10000];
if (!in_array($limit, $limitsOk, true)) $limit = 100;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]      = "(u.nome LIKE :q OR u.email LIKE :q OR u.telefone LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($fTurma !== '' && $colTurma !== '') {
    $where[]          = "u.`$colTurma` = :turma";
    $params[':turma'] = $fTurma;
}
if ($fTag !== '') {
    $where[]       = "EXISTS (SELECT 1 FROM user_tags ut2 JOIN tags t2 ON t2.id = ut2.tag_id WHERE ut2.user_id = u.id AND t2.nome LIKE :tag)";
    $params[':tag']= '%' . $fTag . '%';
}
if ($fDateFrom !== '' && $colCreated !== '') {
    $where[]           = "u.`$colCreated` >= :dfrom";
    $params[':dfrom']  = $fDateFrom . ' 00:00:00';
}
if ($fDateTo !== '' && $colCreated !== '') {
    $where[]          = "u.`$colCreated` <= :dto";
    $params[':dto']   = $fDateTo . ' 23:59:59';
}
if ($fUtmSrc !== '' && $hasUtm) {
    $where[]       = "u.utm_source LIKE :us";
    $params[':us'] = '%' . $fUtmSrc . '%';
}
if ($fUtmMed !== '' && $hasUtm) {
    $where[]       = "u.utm_medium LIKE :um";
    $params[':um'] = '%' . $fUtmMed . '%';
}
if ($fUtmCamp !== '' && $hasUtm) {
    $where[]        = "u.utm_campaign LIKE :uc";
    $params[':uc']  = '%' . $fUtmCamp . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$selTurma   = $colTurma   !== '' ? "u.`$colTurma` AS turma_codigo," : "NULL AS turma_codigo,";
$selCreated = $colCreated !== '' ? "u.`$colCreated` AS primeiro_cadastro," : "NULL AS primeiro_cadastro,";
$selUtm     = $hasUtm
    ? "u.utm_source, u.utm_medium, u.utm_campaign, u.utm_content,"
    : "NULL AS utm_source, NULL AS utm_medium, NULL AS utm_campaign, NULL AS utm_content,";

if ($hasIL) {
    $selWhl = "(SELECT COUNT(*) FROM inscricao_logs il WHERE il.user_id = u.id) AS qtd_cadastros,
               (SELECT MAX(il2.created_at) FROM inscricao_logs il2 WHERE il2.user_id = u.id) AS ultimo_cadastro,";
} elseif ($hasWHL) {
    $selWhl = "(SELECT COUNT(DISTINCT DATE(wl.created_at)) FROM webhook_logs wl WHERE wl.user_id = u.id AND wl.evento = 'INSCRITO') AS qtd_cadastros,
               (SELECT MAX(wl2.created_at) FROM webhook_logs wl2 WHERE wl2.user_id = u.id AND wl2.evento = 'INSCRITO') AS ultimo_cadastro,";
} else {
    $selWhl = "1 AS qtd_cadastros, NULL AS ultimo_cadastro,";
}

$selCert = $hasCerts
    ? "(SELECT codigo_uid FROM certificates WHERE user_id = u.id ORDER BY id DESC LIMIT 1) AS cert_codigo,"
    : "NULL AS cert_codigo,";

// Count total (sem limit)
$sqlCount = "SELECT COUNT(*) FROM users u $whereSql";
$stCount  = $pdo->prepare($sqlCount);
$stCount->execute($params);
$totalGeral = (int)$stCount->fetchColumn();

$sql = "
SELECT
  u.id, u.nome, u.email, u.telefone,
  $selTurma $selCreated $selUtm $selWhl $selCert
  (SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR '|')
   FROM user_tags ut JOIN tags t ON t.id = ut.tag_id
   WHERE ut.user_id = u.id) AS tags_lista
FROM users u
$whereSql
ORDER BY u.id DESC
LIMIT $limit
";

$st     = $pdo->prepare($sql);
$st->execute($params);
$alunos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$temFiltroUtm  = ($fUtmSrc !== '' || $fUtmMed !== '' || $fUtmCamp !== '');
$temFiltroData = ($fDateFrom !== '' || $fDateTo !== '');

require __DIR__ . '/_header.php';
?>
<style>
/* ── Filtros ──────────────────────────────────────────────── */
.adv-panel { display:none; padding-top:10px; }
.adv-panel.open { display:flex; flex-wrap:wrap; gap:10px; }
.filter-toggle-btn {
    font-size:11px; color:var(--muted); background:none; border:1px solid var(--border);
    border-radius:var(--r-full); padding:4px 10px; cursor:pointer; font-family:var(--font);
    display:inline-flex; align-items:center; gap:4px; transition:all var(--t);
}
.filter-toggle-btn:hover { border-color:var(--border-light); color:var(--text); background:var(--bg-hover); }
.filter-toggle-btn.ativo { border-color:rgba(250,204,21,.4); color:var(--primary); background:var(--primary-dim); }

/* ── Tabela ───────────────────────────────────────────────── */
.al-table { width:100%; border-collapse:collapse; font-size:13px; }
.al-table thead th {
    padding:9px 12px; text-align:left; font-size:10.5px; font-weight:700;
    text-transform:uppercase; letter-spacing:.07em; color:var(--muted);
    border-bottom:1px solid var(--border); white-space:nowrap;
    background:rgba(255,255,255,.025);
}
.al-table tbody td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.al-table tbody tr.main-row { cursor:pointer; }
.al-table tbody tr.main-row:hover td { background:var(--bg-hover); }
.expand-icon { transition:transform .2s ease; display:inline-block; color:var(--muted); font-size:11px; }
.main-row.open .expand-icon { transform:rotate(90deg); color:var(--primary); }

/* ── Expanded detail ──────────────────────────────────────── */
.expand-detail {
    display:none; padding:16px 18px 18px;
    background:rgba(255,255,255,.02);
    border-top:1px solid var(--border);
    border-bottom:1px solid var(--border);
}
.expand-detail.open { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
@media(max-width:900px) { .expand-detail.open { grid-template-columns:1fr 1fr; } }
@media(max-width:600px) { .expand-detail.open { grid-template-columns:1fr; } }
.det-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:8px; }
.det-row { display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; gap:8px; }
.det-key { color:var(--muted); flex-shrink:0; }
.det-val { color:var(--text); font-weight:500; text-align:right; word-break:break-all; }
.tag-chip {
    display:inline-block; padding:2px 8px; border-radius:var(--r-full); font-size:11px;
    margin:2px 2px 0 0; border:1px solid var(--border-light); color:var(--text);
    background:rgba(255,255,255,.05);
}
.tag-chip.cert { background:rgba(34,197,94,.1); border-color:rgba(34,197,94,.3); color:#86efac; }
.tag-chip.inscrito { background:var(--info-dim); border-color:rgba(56,189,248,.3); color:var(--info); }
.tag-chip.live { background:rgba(249,115,22,.1); border-color:rgba(249,115,22,.3); color:#fdba74; }
.det-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; grid-column:1/-1; }
.cert-link { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--success); }
.cert-link:hover { text-decoration:underline; }

/* ── KPI bar ──────────────────────────────────────────────── */
.al-kpi-bar { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.al-kpi { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg); padding:10px 16px; min-width:120px; }
.al-kpi-v { font-size:22px; font-weight:700; line-height:1.1; }
.al-kpi-l { font-size:10.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-top:2px; }

/* ── Paginação / limit ────────────────────────────────────── */
.pg-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:10px 14px; border-top:1px solid var(--border); font-size:12px; color:var(--muted); }
.limit-select { display:inline-flex; align-items:center; gap:6px; }
.limit-select select { padding:4px 8px; font-size:12px; border-radius:var(--r); background:var(--bg); border:1px solid var(--border-light); color:var(--text); cursor:pointer; }

/* ── Modal ────────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:900; backdrop-filter:blur(4px); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border-light); border-radius:var(--r-xl); padding:24px; width:100%; max-width:400px; box-shadow:var(--shadow-lg); }
.modal-title { font-size:14px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.modal-footer { display:flex; gap:8px; margin-top:16px; }
</style>

<?php if ($msgPost): ?>
<div class="alert <?= $msgPostTipo==='ok'?'alert-ok':'alert-error' ?>" style="margin-bottom:14px"><?= h($msgPost) ?></div>
<?php endif; ?>

<!-- ─── Filtros ──────────────────────────────────────────────────────── -->
<div class="filter-bar" style="margin-bottom:14px">
    <form method="get" id="fform" style="width:100%">
        <!-- Linha 1: filtros principais -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <div class="filter-group" style="flex:2;min-width:200px">
                <label>Nome / E-mail / Telefone</label>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Busca livre…">
            </div>
            <div class="filter-group" style="min-width:150px">
                <label>Turma</label>
                <select name="turma">
                    <option value="">Todas</option>
                    <?php foreach ($turmas as $t): ?>
                    <option value="<?= h((string)$t) ?>" <?= $fTurma===(string)$t?'selected':'' ?>><?= h((string)$t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" style="min-width:140px">
                <label>Tag</label>
                <input type="text" name="tag" value="<?= h($fTag) ?>" placeholder="Ex: inscrito">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <?php if ($q||$fTurma||$fTag||$temFiltroUtm||$temFiltroData): ?>
                <a href="alunos.php" class="reset-link">Limpar</a>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;padding-top:14px;flex-wrap:wrap">
                <button type="button" class="filter-toggle-btn <?= $temFiltroData?'ativo':'' ?>" onclick="togglePanel('panel-data',this)">
                    📅 Datas<?= $temFiltroData?' (ativo)':'' ?>
                </button>
                <?php if ($hasUtm): ?>
                <button type="button" class="filter-toggle-btn <?= $temFiltroUtm?'ativo':'' ?>" onclick="togglePanel('panel-utm',this)">
                    ⚙ UTMs<?= $temFiltroUtm?' (ativo)':'' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Painel datas -->
        <div class="adv-panel <?= $temFiltroData?'open':'' ?>" id="panel-data">
            <div class="filter-group" style="min-width:150px">
                <label>Inscrição de</label>
                <input type="date" name="date_from" value="<?= h($fDateFrom) ?>">
            </div>
            <div class="filter-group" style="min-width:150px">
                <label>Inscrição até</label>
                <input type="date" name="date_to" value="<?= h($fDateTo) ?>">
            </div>
        </div>

        <!-- Painel UTMs -->
        <?php if ($hasUtm): ?>
        <div class="adv-panel <?= $temFiltroUtm?'open':'' ?>" id="panel-utm">
            <div class="filter-group" style="min-width:140px">
                <label>UTM Source</label>
                <input type="text" name="utm_source" value="<?= h($fUtmSrc) ?>" placeholder="google, facebook…">
            </div>
            <div class="filter-group" style="min-width:140px">
                <label>UTM Medium</label>
                <input type="text" name="utm_medium" value="<?= h($fUtmMed) ?>" placeholder="cpc, email…">
            </div>
            <div class="filter-group" style="min-width:140px">
                <label>UTM Campaign</label>
                <input type="text" name="utm_campaign" value="<?= h($fUtmCamp) ?>" placeholder="nome_campanha">
            </div>
        </div>
        <?php endif; ?>

        <!-- Limit selector (preservado no submit) -->
        <input type="hidden" name="limit" id="limit-hidden" value="<?= $limit ?>">
    </form>
</div>

<!-- ─── KPI Bar ──────────────────────────────────────────────────────── -->
<div class="al-kpi-bar">
    <div class="al-kpi">
        <div class="al-kpi-v"><?= number_format($totalGeral) ?></div>
        <div class="al-kpi-l">Total encontrado</div>
    </div>
    <div class="al-kpi">
        <div class="al-kpi-v"><?= count($alunos) ?></div>
        <div class="al-kpi-l">Exibindo</div>
    </div>
    <?php
    $comCert  = count(array_filter($alunos, function($a){ return strpos((string)($a['tags_lista']??''),'CERT_EMITIDO')!==false; }));
    $comTurma = count(array_filter($alunos, function($a){ return trim((string)($a['turma_codigo']??''))!==''; }));
    ?>
    <div class="al-kpi">
        <div class="al-kpi-v" style="color:var(--success)"><?= $comCert ?></div>
        <div class="al-kpi-l">Com certificado</div>
    </div>
    <div class="al-kpi">
        <div class="al-kpi-v" style="color:var(--info)"><?= $comTurma ?></div>
        <div class="al-kpi-l">Com turma</div>
    </div>
</div>

<!-- ─── Tabela ──────────────────────────────────────────────────────── -->
<div class="card" style="padding:0;overflow:hidden">
    <div style="overflow-x:auto">
        <table class="al-table" style="min-width:900px">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th>Nome / E-mail</th>
                    <th>Telefone</th>
                    <th>Turma</th>
                    <th>Tags</th>
                    <th style="text-align:center">Cadastros</th>
                    <th>1° Cadastro</th>
                    <th>Último</th>
                    <th style="text-align:right">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$alunos): ?>
                <tr><td colspan="9" style="padding:28px;text-align:center;color:var(--muted)">Nenhum aluno encontrado para os filtros aplicados.</td></tr>
            <?php else: ?>
            <?php foreach ($alunos as $i => $a):
                $tags    = array_filter(array_map('trim', explode('|', (string)($a['tags_lista']??''))));
                $turma   = trim((string)($a['turma_codigo']??''));
                $primCad = (string)($a['primeiro_cadastro']??'');
                $ultCad  = (string)($a['ultimo_cadastro']??'');
                $qtd     = (int)($a['qtd_cadastros']??1);
                $certCod = (string)($a['cert_codigo']??'');
                $certUrl = $certCod !== '' ? BASE_URL . '/verificar_certificado.php?c=' . urlencode($certCod) : '';
                $temCert = in_array('CERT_EMITIDO', array_map('strtoupper', $tags));
            ?>
            <tr class="main-row" id="row-<?= $i ?>" onclick="toggleExpand(<?= $i ?>)">
                <td><span class="expand-icon">▶</span></td>
                <td>
                    <div style="font-weight:600"><?= h((string)($a['nome']??'-')) ?></div>
                    <div style="font-size:11px;color:var(--muted)"><?= h((string)($a['email']??'-')) ?></div>
                </td>
                <td style="color:var(--muted);font-size:12px"><?= h(trim((string)($a['telefone']??''))) ?: '-' ?></td>
                <td>
                    <?php if ($turma !== ''): ?>
                    <span class="badge badge-info" style="font-size:11px"><?= h($turma) ?></span>
                    <?php else: ?><span style="color:var(--dim);font-size:12px">—</span><?php endif; ?>
                </td>
                <td style="max-width:180px">
                    <?php $shown=0; foreach ($tags as $tag):
                        if ($shown>=3) break;
                        $tu=strtoupper($tag);
                        $cls = $tu==='CERT_EMITIDO' ? 'cert' : ($tu==='INSCRITO' ? 'inscrito' : (strpos($tu,'LIVE')!==false?'live':''));
                    ?>
                    <span class="tag-chip <?= $cls ?>"><?= h(mb_strtolower($tag)) ?></span>
                    <?php $shown++; endforeach; ?>
                    <?php if (count($tags)>3): ?><span style="font-size:11px;color:var(--muted)">+<?= count($tags)-3 ?></span><?php endif; ?>
                    <?php if (!$tags): ?><span style="font-size:11px;color:var(--dim)">—</span><?php endif; ?>
                </td>
                <td style="text-align:center">
                    <span style="font-weight:600;<?= $qtd>1?'color:var(--warning)':'' ?>"><?= $qtd ?></span>
                </td>
                <td style="font-size:12px;color:var(--muted)"><?= fmtDt($primCad) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= fmtDt($ultCad) ?></td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                    <a href="aluno_editar.php?id=<?= (int)$a['id'] ?>" class="btn btn-ghost btn-xs">Editar</a>
                </td>
            </tr>
            <tr id="exp-<?= $i ?>">
                <td colspan="9" style="padding:0;border-bottom:none">
                    <div class="expand-detail" id="det-<?= $i ?>">
                        <!-- UTMs + cadastros -->
                        <div>
                            <div class="det-title">UTM / Origem</div>
                            <?php
                            $utmF=['utm_source'=>'Source','utm_medium'=>'Medium','utm_campaign'=>'Campaign','utm_content'=>'Content'];
                            $anyUtm=false;
                            foreach($utmF as $uk=>$ul):
                                $uv=trim((string)($a[$uk]??''));
                                if($uv==='') continue; $anyUtm=true;
                            ?><div class="det-row"><span class="det-key"><?=h($ul)?></span><span class="det-val"><?=h($uv)?></span></div>
                            <?php endforeach; ?>
                            <?php if(!$anyUtm): ?><div style="font-size:12px;color:var(--dim)">Sem dados UTM</div><?php endif; ?>
                            <div style="margin-top:10px">
                                <div class="det-title">Inscrições</div>
                                <div class="det-row"><span class="det-key">Qtd.</span><span class="det-val"><?=$qtd?></span></div>
                                <div class="det-row"><span class="det-key">Primeiro</span><span class="det-val"><?=fmtDt($primCad)?></span></div>
                                <?php if($ultCad): ?><div class="det-row"><span class="det-key">Último</span><span class="det-val"><?=fmtDt($ultCad)?></span></div><?php endif; ?>
                            </div>
                        </div>
                        <!-- Tags -->
                        <div>
                            <div class="det-title">Tags / Etapas do funil</div>
                            <?php if($tags): foreach($tags as $tag):
                                $tu=strtoupper($tag);
                                $cls=$tu==='CERT_EMITIDO'?'cert':($tu==='INSCRITO'?'inscrito':(strpos($tu,'LIVE')!==false?'live':''));
                            ?><span class="tag-chip <?=$cls?>"><?=h(mb_strtolower($tag))?></span><?php endforeach;
                            else: ?><div style="font-size:12px;color:var(--dim)">Sem tags</div><?php endif; ?>
                        </div>
                        <!-- Certificado + ações -->
                        <div>
                            <div class="det-title">Certificado</div>
                            <?php if($certUrl): ?>
                            <a href="<?=h($certUrl)?>" target="_blank" class="cert-link">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                                Ver certificado
                            </a>
                            <?php elseif($temCert): ?>
                            <span style="font-size:12px;color:var(--success)">✓ Emitido</span>
                            <?php else: ?>
                            <span style="font-size:12px;color:var(--dim)">Não emitido</span>
                            <?php endif; ?>

                            <div class="det-actions">
                                <a href="aluno_editar.php?id=<?=(int)$a['id']?>" class="btn btn-ghost btn-sm">✏ Editar dados</a>
                                <?php if($senhaCol!==''): ?>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abrirSenha(<?=(int)$a['id']?>,'<?=h((string)($a['nome']??''))?>')">🔑 Trocar senha</button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abrirLogin(<?=(int)$a['id']?>,'<?=h((string)($a['email']??''))?>')">📧 Trocar e-mail</button>
                                <?php endif; ?>
                                <?php if($temCert): ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Reenviar CERT_EMITIDO para este aluno?')">
                                    <input type="hidden" name="acao" value="reenviar_cert">
                                    <input type="hidden" name="uid"  value="<?=(int)$a['id']?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--success)">↻ Reenviar certificado</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Barra de paginação / limit -->
    <div class="pg-bar">
        <span>Exibindo <strong><?= count($alunos) ?></strong> de <strong><?= number_format($totalGeral) ?></strong> alunos</span>
        <div class="limit-select">
            <span>Mostrar:</span>
            <select id="limit-sel" onchange="changeLimit(this.value)">
                <?php foreach ([10,100,200,500,1000,5000,10000] as $lo): ?>
                <option value="<?= $lo ?>" <?= $limit===$lo?'selected':'' ?>><?= number_format($lo) ?></option>
                <?php endforeach; ?>
            </select>
            <span>por página</span>
        </div>
    </div>
</div>

<!-- ─── Modal Senha ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-senha">
    <div class="modal-box">
        <div class="modal-title">🔑 Trocar senha de acesso</div>
        <div id="m-senha-nome" style="font-size:12px;color:var(--muted);margin-bottom:14px"></div>
        <form method="post">
            <input type="hidden" name="acao" value="trocar_senha">
            <input type="hidden" name="uid"  id="m-senha-uid">
            <div class="form-group">
                <label class="form-label">Nova senha (mín. 6 caracteres)</label>
                <input type="password" name="nova_senha" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label">Confirmar senha</label>
                <input type="password" name="conf_senha" required minlength="6">
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-senha')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal Login ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-login">
    <div class="modal-box">
        <div class="modal-title">📧 Trocar e-mail / login</div>
        <form method="post">
            <input type="hidden" name="acao" value="trocar_login">
            <input type="hidden" name="uid"  id="m-login-uid">
            <div class="form-group">
                <label class="form-label">Novo e-mail de login</label>
                <input type="email" name="novo_email" id="m-login-email" required>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-login')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleExpand(i) {
    var row = document.getElementById('row-' + i);
    var det = document.getElementById('det-' + i);
    if (!det) return;
    var open = det.classList.contains('open');
    det.classList.toggle('open', !open);
    row.classList.toggle('open', !open);
}
function togglePanel(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('open');
    btn.classList.toggle('ativo', el.classList.contains('open'));
}
function changeLimit(val) {
    document.getElementById('limit-hidden').value = val;
    document.getElementById('fform').submit();
}
function abrirSenha(uid, nome) {
    document.getElementById('m-senha-uid').value = uid;
    document.getElementById('m-senha-nome').textContent = 'Aluno: ' + nome;
    document.getElementById('modal-senha').classList.add('open');
}
function abrirLogin(uid, email) {
    document.getElementById('m-login-uid').value = uid;
    document.getElementById('m-login-email').value = email;
    document.getElementById('modal-login').classList.add('open');
}
function fecharModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('open'); });
});
</script>
<?php require __DIR__ . '/_footer.php'; ?>
