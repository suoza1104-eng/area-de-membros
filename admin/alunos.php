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

// ── Detecta colunas e tabelas ─────────────────────────────────────────────
$colTurma   = col_ok($pdo,'users','codigo_turma') ? 'codigo_turma' : (col_ok($pdo,'users','turma') ? 'turma' : '');
$colCreated = col_ok($pdo,'users','created_at')   ? 'created_at'   : (col_ok($pdo,'users','criado_em') ? 'criado_em' : '');
$hasWHL     = table_ok($pdo, 'webhook_logs');
$hasCerts   = table_ok($pdo, 'certificados');
$hasSenha   = col_ok($pdo,'users','senha');
$hasPassword = col_ok($pdo,'users','password');
$senhaCol   = $hasSenha ? 'senha' : ($hasPassword ? 'password' : '');

$hasUtm = col_ok($pdo,'users','utm_source');

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
        $uid  = (int)($_POST['uid'] ?? 0);
        $ns   = trim((string)($_POST['nova_senha'] ?? ''));
        $ns2  = trim((string)($_POST['conf_senha']  ?? ''));
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
        $uid   = (int)($_POST['uid'] ?? 0);
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
                $msgPost = 'Evento CERT_EMITIDO disparado para o aluno.';
            } catch (Throwable $e) {
                $msgPost = 'Erro ao reenviar: ' . $e->getMessage(); $msgPostTipo = 'erro';
            }
        }
    }
}

// ── Filtros ────────────────────────────────────────────────────────────────
$q          = trim((string)($_GET['q']           ?? ''));
$fTurma     = trim((string)($_GET['turma']       ?? ''));
$fUtmSrc    = trim((string)($_GET['utm_source']  ?? ''));
$fUtmMed    = trim((string)($_GET['utm_medium']  ?? ''));
$fUtmCamp   = trim((string)($_GET['utm_campaign']?? ''));

$where  = [];
$params = [];

if ($q !== '') {
    $where[]       = "(u.nome LIKE :q OR u.email LIKE :q OR u.telefone LIKE :q)";
    $params[':q']  = '%' . $q . '%';
}
if ($fTurma !== '' && $colTurma !== '') {
    $where[]          = "u.`$colTurma` = :turma";
    $params[':turma'] = $fTurma;
}
if ($fUtmSrc !== '' && $hasUtm) {
    $where[]             = "u.utm_source LIKE :us";
    $params[':us']       = '%' . $fUtmSrc . '%';
}
if ($fUtmMed !== '' && $hasUtm) {
    $where[]             = "u.utm_medium LIKE :um";
    $params[':um']       = '%' . $fUtmMed . '%';
}
if ($fUtmCamp !== '' && $hasUtm) {
    $where[]              = "u.utm_campaign LIKE :uc";
    $params[':uc']        = '%' . $fUtmCamp . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$selTurma   = $colTurma   !== '' ? "u.`$colTurma` AS turma_codigo," : "NULL AS turma_codigo,";
$selCreated = $colCreated !== '' ? "u.`$colCreated` AS primeiro_cadastro," : "NULL AS primeiro_cadastro,";
$selUtm     = $hasUtm ? "u.utm_source, u.utm_medium, u.utm_campaign, u.utm_content," : "NULL AS utm_source, NULL AS utm_medium, NULL AS utm_campaign, NULL AS utm_content,";

// Subquery para webhook_logs (cadastros e último)
$selWhl = $hasWHL
    ? "(SELECT COUNT(*) FROM webhook_logs wl WHERE wl.user_id = u.id AND wl.evento = 'INSCRITO') AS qtd_cadastros,
       (SELECT MAX(wl2.created_at) FROM webhook_logs wl2 WHERE wl2.user_id = u.id AND wl2.evento = 'INSCRITO') AS ultimo_cadastro,"
    : "1 AS qtd_cadastros, NULL AS ultimo_cadastro,";

// Subquery para certificado
$selCert = $hasCerts
    ? "(SELECT codigo_uid FROM certificados WHERE user_id = u.id ORDER BY id DESC LIMIT 1) AS cert_codigo,"
    : "NULL AS cert_codigo,";

$sql = "
SELECT
  u.id, u.nome, u.email, u.telefone,
  $selTurma $selCreated $selUtm $selWhl $selCert
  (SELECT GROUP_CONCAT(t.nome ORDER BY t.nome SEPARATOR '|') FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = u.id) AS tags_lista
FROM users u
$whereSql
ORDER BY u.id DESC
LIMIT 500
";

$st = $pdo->prepare($sql);
$st->execute($params);
$alunos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$temFiltroUtm = ($fUtmSrc !== '' || $fUtmMed !== '' || $fUtmCamp !== '');

require __DIR__ . '/_header.php';
?>
<style>
/* ── Filtros avançados ─────────────────────────────────── */
.adv-filters { display:none; }
.adv-filters.open { display:flex; }
.filter-toggle-btn {
    font-size:11px; color:var(--muted); background:none; border:1px solid var(--border);
    border-radius:var(--r-full); padding:4px 10px; cursor:pointer; font-family:var(--font);
    display:inline-flex; align-items:center; gap:4px; transition:all var(--t);
}
.filter-toggle-btn:hover { border-color:var(--border-light); color:var(--text); background:var(--bg-hover); }
.filter-toggle-btn.ativo { border-color:rgba(250,204,21,.4); color:var(--primary); background:var(--primary-dim); }

/* ── Tabela de alunos ──────────────────────────────────── */
.al-table { width:100%; border-collapse:collapse; font-size:13px; }
.al-table thead th {
    padding:9px 12px; text-align:left; font-size:10.5px; font-weight:700;
    text-transform:uppercase; letter-spacing:.07em; color:var(--muted);
    border-bottom:1px solid var(--border); white-space:nowrap;
    background:rgba(255,255,255,.025);
}
.al-table tbody td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.al-table tbody tr.main-row:hover td { background:var(--bg-hover); }
.al-table tbody tr.main-row { cursor:pointer; }
.al-table tbody tr.expand-row td { padding:0; border-bottom:none; }
.al-table tbody tr.main-row:last-child td { border-bottom:none; }
.expand-icon { transition:transform .2s ease; display:inline-block; color:var(--muted); font-size:12px; }
.main-row.open .expand-icon { transform:rotate(90deg); color:var(--primary); }

/* ── Expanded detail ───────────────────────────────────── */
.expand-detail {
    display:none; padding:16px 18px 18px;
    background:rgba(255,255,255,.02);
    border-top:1px solid var(--border);
    border-bottom:1px solid var(--border);
}
.expand-detail.open { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
.detail-section { min-width:0; }
.detail-title {
    font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
    color:var(--muted); margin-bottom:8px;
}
.detail-row { display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; gap:8px; }
.detail-key { color:var(--muted); flex-shrink:0; }
.detail-val { color:var(--text); font-weight:500; text-align:right; word-break:break-word; }
.tag-chip {
    display:inline-block; padding:2px 8px; border-radius:var(--r-full); font-size:11px;
    margin:2px 2px 0 0; border:1px solid var(--border-light); color:var(--text);
    background:rgba(255,255,255,.05);
}
.tag-chip.cert { background:rgba(34,197,94,.1); border-color:rgba(34,197,94,.3); color:#86efac; }
.tag-chip.inscrito { background:var(--info-dim); border-color:rgba(56,189,248,.3); color:var(--info); }
.tag-chip.live { background:rgba(249,115,22,.1); border-color:rgba(249,115,22,.3); color:#fdba74; }
.detail-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; grid-column:1/-1; }
.cert-link { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--success); text-decoration:none; }
.cert-link:hover { text-decoration:underline; }

/* ── KPI bar ───────────────────────────────────────────── */
.al-kpi-bar { display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
.al-kpi { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--r-lg); padding:10px 16px; }
.al-kpi-v { font-size:20px; font-weight:700; }
.al-kpi-l { font-size:10.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }

/* ── Modal ─────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:900; backdrop-filter:blur(4px); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border-light); border-radius:var(--r-xl); padding:24px; width:100%; max-width:400px; box-shadow:var(--shadow-lg); }
.modal-title { font-size:15px; font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.modal-title svg { width:16px; height:16px; }
.modal-footer { display:flex; gap:8px; margin-top:16px; }
</style>

<?php if ($msgPost): ?>
<div class="alert <?= $msgPostTipo === 'ok' ? 'alert-ok' : 'alert-error' ?>" style="margin-bottom:14px"><?= h($msgPost) ?></div>
<?php endif; ?>

<!-- ─── Filtros ──────────────────────────────────────────────────────── -->
<div class="filter-bar" style="margin-bottom:16px">
    <form method="get" id="filter-form" style="width:100%">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <div class="filter-group" style="flex:2;min-width:200px">
                <label>Busca</label>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Nome, e-mail ou telefone">
            </div>
            <div class="filter-group" style="min-width:150px">
                <label>Turma</label>
                <select name="turma">
                    <option value="">Todas</option>
                    <?php foreach ($turmas as $t): ?>
                        <option value="<?= h((string)$t) ?>" <?= ($fTurma===(string)$t)?'selected':'' ?>><?= h((string)$t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                <?php if ($q||$fTurma||$temFiltroUtm): ?>
                    <a href="alunos.php" class="reset-link">Limpar</a>
                <?php endif; ?>
                <?php if ($hasUtm): ?>
                    <button type="button" class="filter-toggle-btn <?= $temFiltroUtm ? 'ativo' : '' ?>" id="btnUtm" onclick="toggleUtmFilters()">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>
                        UTMs <?= $temFiltroUtm ? '(ativo)' : '' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($hasUtm): ?>
        <div class="adv-filters <?= $temFiltroUtm ? 'open' : '' ?>" id="utm-filters" style="flex-wrap:wrap;gap:10px;margin-top:10px">
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
                <input type="text" name="utm_campaign" value="<?= h($fUtmCamp) ?>" placeholder="nome_da_campanha">
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- ─── KPI Bar ──────────────────────────────────────────────────────── -->
<div class="al-kpi-bar">
    <div class="al-kpi">
        <div class="al-kpi-v"><?= count($alunos) ?></div>
        <div class="al-kpi-l">Alunos encontrados</div>
    </div>
    <?php
    $comCert = count(array_filter($alunos, fn($a) => strpos((string)($a['tags_lista']??''), 'CERT_EMITIDO') !== false));
    $comTurma = count(array_filter($alunos, fn($a) => trim((string)($a['turma_codigo']??'')) !== ''));
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
    <div class="table-wrap" style="overflow-x:auto">
        <table class="al-table">
            <thead>
                <tr>
                    <th style="width:32px"></th>
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
                <tr><td colspan="9" style="padding:24px;text-align:center;color:var(--muted)">Nenhum aluno encontrado.</td></tr>
            <?php else: ?>
            <?php foreach ($alunos as $i => $a):
                $tags    = array_filter(explode('|', (string)($a['tags_lista']??'')));
                $turma   = trim((string)($a['turma_codigo']??''));
                $primCad = (string)($a['primeiro_cadastro']??'');
                $ultCad  = (string)($a['ultimo_cadastro']??'');
                $qtd     = (int)($a['qtd_cadastros']??1);
                $certCod = (string)($a['cert_codigo']??'');
                $certUrl = $certCod !== '' ? BASE_URL . '/verificar_certificado.php?c=' . urlencode($certCod) : '';

                $temCert = in_array('CERT_EMITIDO', array_map('strtoupper', $tags));

                function fmtDt(?string $d): string {
                    if (!$d) return '-';
                    try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return $d; }
                }
            ?>
            <tr class="main-row" id="row-<?= $i ?>" onclick="toggleExpand(<?= $i ?>)">
                <td><span class="expand-icon">▶</span></td>
                <td>
                    <div style="font-weight:600;color:var(--text)"><?= h((string)($a['nome']??'-')) ?></div>
                    <div style="font-size:11px;color:var(--muted)"><?= h((string)($a['email']??'-')) ?></div>
                </td>
                <td style="color:var(--muted);font-size:12px"><?= h((string)($a['telefone']??'-')) ?: '-' ?></td>
                <td>
                    <?php if ($turma !== ''): ?>
                        <span class="badge badge-info" style="font-size:11px"><?= h($turma) ?></span>
                    <?php else: ?>
                        <span style="color:var(--dim);font-size:12px">—</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:180px">
                    <?php
                    $shown = 0;
                    foreach ($tags as $tag):
                        if ($shown >= 3) break;
                        $cls = '';
                        $tu = strtoupper($tag);
                        if ($tu === 'CERT_EMITIDO') $cls = 'cert';
                        elseif ($tu === 'INSCRITO') $cls = 'inscrito';
                        elseif (strpos($tu,'LIVE')!==false) $cls = 'live';
                    ?>
                    <span class="tag-chip <?= $cls ?>"><?= h(mb_strtolower($tag)) ?></span>
                    <?php $shown++; endforeach; ?>
                    <?php if (count($tags) > 3): ?>
                        <span style="font-size:11px;color:var(--muted)">+<?= count($tags)-3 ?></span>
                    <?php endif; ?>
                    <?php if (!$tags): ?>
                        <span style="font-size:11px;color:var(--dim)">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center">
                    <span style="font-weight:600;<?= $qtd > 1 ? 'color:var(--warning)' : '' ?>"><?= $qtd ?></span>
                </td>
                <td style="font-size:12px;color:var(--muted)"><?= fmtDt($primCad) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= fmtDt($ultCad) ?></td>
                <td style="text-align:right" onclick="event.stopPropagation()">
                    <a href="aluno_editar.php?id=<?= (int)$a['id'] ?>" class="btn btn-ghost btn-xs">Editar</a>
                </td>
            </tr>
            <tr class="expand-row" id="exp-<?= $i ?>">
                <td colspan="9">
                    <div class="expand-detail" id="det-<?= $i ?>">
                        <!-- UTMs -->
                        <div class="detail-section">
                            <div class="detail-title">UTM / Origem</div>
                            <?php
                            $utmFields = ['utm_source' => 'Source', 'utm_medium' => 'Medium', 'utm_campaign' => 'Campaign', 'utm_content' => 'Content'];
                            $hasAnyUtm = false;
                            foreach ($utmFields as $utmK => $utmL):
                                $uv = trim((string)($a[$utmK]??''));
                                if ($uv === '') continue;
                                $hasAnyUtm = true;
                            ?>
                            <div class="detail-row">
                                <span class="detail-key"><?= h($utmL) ?></span>
                                <span class="detail-val"><?= h($uv) ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (!$hasAnyUtm): ?>
                                <div style="font-size:12px;color:var(--dim)">Sem dados UTM</div>
                            <?php endif; ?>

                            <div style="margin-top:10px">
                                <div class="detail-title">Cadastros</div>
                                <div class="detail-row">
                                    <span class="detail-key">Quantidade</span>
                                    <span class="detail-val"><?= $qtd ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-key">Primeiro</span>
                                    <span class="detail-val"><?= fmtDt($primCad) ?></span>
                                </div>
                                <?php if ($ultCad): ?>
                                <div class="detail-row">
                                    <span class="detail-key">Último</span>
                                    <span class="detail-val"><?= fmtDt($ultCad) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tags / Funil -->
                        <div class="detail-section">
                            <div class="detail-title">Tags / Etapas do funil</div>
                            <?php if ($tags): ?>
                                <?php foreach ($tags as $tag):
                                    $tu = strtoupper($tag);
                                    $cls = '';
                                    if ($tu==='CERT_EMITIDO') $cls='cert';
                                    elseif ($tu==='INSCRITO') $cls='inscrito';
                                    elseif (strpos($tu,'LIVE')!==false) $cls='live';
                                ?>
                                <span class="tag-chip <?= $cls ?>"><?= h(mb_strtolower($tag)) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:12px;color:var(--dim)">Sem tags</div>
                            <?php endif; ?>
                        </div>

                        <!-- Certificado + Ações -->
                        <div class="detail-section">
                            <div class="detail-title">Certificado</div>
                            <?php if ($certUrl): ?>
                                <a href="<?= h($certUrl) ?>" target="_blank" class="cert-link">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                                    Ver certificado
                                </a>
                            <?php elseif ($temCert): ?>
                                <span style="font-size:12px;color:var(--success)">✓ Certificado emitido</span>
                            <?php else: ?>
                                <span style="font-size:12px;color:var(--dim)">Não emitido</span>
                            <?php endif; ?>

                            <div class="detail-actions">
                                <a href="aluno_editar.php?id=<?= (int)$a['id'] ?>" class="btn btn-ghost btn-sm">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Editar dados
                                </a>
                                <?php if ($senhaCol !== ''): ?>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abrirModalSenha(<?= (int)$a['id'] ?>, '<?= h((string)($a['nome']??'')) ?>')">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                    Trocar senha
                                </button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="abrirModalLogin(<?= (int)$a['id'] ?>, '<?= h((string)($a['email']??'')) ?>')">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    Trocar e-mail/login
                                </button>
                                <?php endif; ?>
                                <?php if ($temCert): ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Reenviar evento CERT_EMITIDO para este aluno?')">
                                    <input type="hidden" name="acao" value="reenviar_cert">
                                    <input type="hidden" name="uid"  value="<?= (int)$a['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--success)">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                                        Reenviar certificado
                                    </button>
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
    <?php if (count($alunos) >= 500): ?>
    <div style="padding:10px 14px;font-size:12px;color:var(--muted);border-top:1px solid var(--border)">
        Exibindo os primeiros 500 resultados. Use os filtros para refinar.
    </div>
    <?php endif; ?>
</div>

<!-- ─── Modal: Trocar Senha ──────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-senha">
    <div class="modal-box">
        <div class="modal-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Trocar senha de acesso
        </div>
        <div id="modal-senha-nome" style="font-size:12px;color:var(--muted);margin-bottom:14px"></div>
        <form method="post">
            <input type="hidden" name="acao" value="trocar_senha">
            <input type="hidden" name="uid"  id="senha-uid">
            <div class="form-group">
                <label class="form-label">Nova senha (mín. 6 caracteres)</label>
                <input type="password" name="nova_senha" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label">Confirmar senha</label>
                <input type="password" name="conf_senha" required minlength="6" autocomplete="new-password">
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar nova senha</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="fecharModal('modal-senha')">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Modal: Trocar Login (email) ─────────────────────────────────── -->
<div class="modal-overlay" id="modal-login">
    <div class="modal-box">
        <div class="modal-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Trocar e-mail / login
        </div>
        <form method="post">
            <input type="hidden" name="acao" value="trocar_login">
            <input type="hidden" name="uid"  id="login-uid">
            <div class="form-group">
                <label class="form-label">Novo e-mail de login</label>
                <input type="email" name="novo_email" id="login-email" required>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-sm">Salvar novo e-mail</button>
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
function toggleUtmFilters() {
    var el = document.getElementById('utm-filters');
    var btn = document.getElementById('btnUtm');
    el.classList.toggle('open');
    btn.classList.toggle('ativo', el.classList.contains('open'));
}
function abrirModalSenha(uid, nome) {
    document.getElementById('senha-uid').value = uid;
    document.getElementById('modal-senha-nome').textContent = 'Aluno: ' + nome;
    document.getElementById('modal-senha').classList.add('open');
}
function abrirModalLogin(uid, emailAtual) {
    document.getElementById('login-uid').value = uid;
    document.getElementById('login-email').value = emailAtual;
    document.getElementById('modal-login').classList.add('open');
}
function fecharModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === m) m.classList.remove('open');
    });
});
<?php if ($temFiltroUtm): ?>
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('utm-filters');
    if (el) el.classList.add('open');
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/_footer.php'; ?>
