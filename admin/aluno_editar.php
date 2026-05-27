<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';
require_once __DIR__ . '/../app/certificado_pdf.php';
proteger_admin();
$pdo = getPDO();
$menu = 'alunos';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function table_columns_ae(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => strtolower((string)($r['Field'] ?? '')), $rows);
    } catch (Throwable $e) { return []; }
}
function parse_dt(?string $raw): ?string {
    $s = trim((string)$raw);
    if ($s === '') return null;
    $s = preg_replace('/\s+/', ' ', str_replace(['T','às','ÀS'], [' ',' ',' '], $s)) ?? $s;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s(\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[1],(int)$m[2],(int)$m[3],(int)$m[4],(int)$m[5],(int)($m[6]??0));
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s(\d{2}):(\d{2})$/', $s, $m)) {
        $dt = DateTime::createFromFormat('d/m/Y H:i', $s);
        return $dt ? $dt->format('Y-m-d H:i:00') : null;
    }
    return null;
}
function sql_br(?string $sql): string {
    $sql = trim((string)$sql);
    if ($sql === '') return '';
    try { return (new DateTime($sql))->format('d/m/Y H:i'); } catch (Throwable $e) { return ''; }
}
function sql_iso(?string $sql): string {
    $sql = trim((string)$sql);
    if ($sql === '') return '';
    try { return (new DateTime($sql))->format('Y-m-d\TH:i'); } catch (Throwable $e) { return ''; }
}

function ae_gerar_codigo_certificado(): string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $result = '';
    for ($i = 0; $i < 36; $i++) {
        if ($i > 0 && $i % 9 === 0) $result .= '-';
        else $result .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $result;
}

function ae_obter_configs_certificado(PDO $pdo): array {
    $appCfg = [];
    $certCfg = [];
    try {
        $st = $pdo->query("SELECT * FROM app_config WHERE id = 1 LIMIT 1");
        $appCfg = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("SELECT * FROM certificate_config WHERE id = 1 LIMIT 1");
        $certCfg = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {}
    return [$appCfg, $certCfg];
}

function ae_certificado_atual(PDO $pdo, int $userId): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND status = 'emitido' ORDER BY id DESC LIMIT 1");
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function ae_gerar_ou_atualizar_certificado(PDO $pdo, array $aluno): array {
    [$appCfg, $certCfg] = ae_obter_configs_certificado($pdo);
    $courseTitle = trim((string)($appCfg['course_title'] ?? 'Trilha de Aulas'));
    if ($courseTitle === '') $courseTitle = 'Trilha de Aulas';

    $userId = (int)($aluno['id'] ?? 0);
    if ($userId <= 0) throw new RuntimeException('Aluno inválido.');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM certificates WHERE user_id = :uid AND course = :course ORDER BY id DESC LIMIT 1");
        $st->execute([':uid' => $userId, ':course' => $courseTitle]);
        $cert = $st->fetch(PDO::FETCH_ASSOC);

        if (!$cert || (string)($cert['status'] ?? '') !== 'emitido') {
            $codigo = ae_gerar_codigo_certificado();
            $emitidoEm = date('Y-m-d H:i:s');
            $ins = $pdo->prepare("
                INSERT INTO certificates (user_id, course, codigo_uid, emitido_em, status)
                VALUES (:uid, :course, :codigo, :emitido, 'emitido')
            ");
            $ins->execute([
                ':uid' => $userId,
                ':course' => $courseTitle,
                ':codigo' => $codigo,
                ':emitido' => $emitidoEm,
            ]);
            $cert = [
                'id' => (int)$pdo->lastInsertId(),
                'user_id' => $userId,
                'course' => $courseTitle,
                'codigo_uid' => $codigo,
                'emitido_em' => $emitidoEm,
                'status' => 'emitido',
                'pdf_url' => null,
            ];
        }

        $pdfUrl = gerar_pdf_certificado($aluno, $cert, $certCfg);
        $upd = $pdo->prepare("UPDATE certificates SET pdf_url = :pdf_url WHERE id = :id");
        $upd->execute([':pdf_url' => $pdfUrl, ':id' => (int)$cert['id']]);
        $cert['pdf_url'] = $pdfUrl;

        try { adicionar_tag($userId, 'CERT_EMITIDO', 'admin'); } catch (Throwable $e) {}
        $pdo->commit();
        return $cert;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ae_reenviar_certificado_sf(PDO $pdo, array $aluno, array $cert): void {
    $user = [
        'id' => $aluno['id'] ?? null,
        'nome' => $aluno['nome'] ?? null,
        'email' => $aluno['email'] ?? null,
        'telefone' => $aluno['telefone'] ?? null,
    ];
    $extra = [
        'codigo_certificado' => $cert['codigo_uid'] ?? '',
        'curso' => $cert['course'] ?? '',
        'emitido_em' => $cert['emitido_em'] ?? '',
        'pdf_url' => $cert['pdf_url'] ?? '',
        'origem' => 'admin_reenvio_sf',
    ];
    sf_disparar_evento($pdo, 'CERT_EMITIDO', $user, $extra);
}

// ── Carrega aluno ──────────────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: alunos.php'); exit; }

$st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$aluno = $st->fetch(PDO::FETCH_ASSOC);
if (!$aluno) { header('Location: alunos.php'); exit; }

$userCols = table_columns_ae($pdo, 'users');

// Detecta coluna de senha
$senhaCol = '';
foreach (['senha','password','senha_hash','pass'] as $c) {
    if (in_array($c, $userCols, true)) { $senhaCol = $c; break; }
}

// ── Carrega turmas ─────────────────────────────────────────────────────
$turmas      = [];
$turmaMap    = [];
$liveSugest  = [];
$turmaCols   = table_columns_ae($pdo, 'turmas');
$colTurmaCod = null;
foreach (['codigo','codigo_turma','turma_codigo'] as $c) {
    if (in_array($c,$turmaCols,true)) { $colTurmaCod = $c; break; }
}
$colTurmaLive = null;
foreach (['data_live','turma_live_at','live_at','data'] as $c) {
    if (in_array($c,$turmaCols,true)) { $colTurmaLive = $c; break; }
}
try {
    if ($colTurmaCod) {
        $order = $colTurmaLive ? "$colTurmaLive ASC, $colTurmaCod ASC" : "$colTurmaCod ASC";
        $stT = $pdo->query("SELECT $colTurmaCod AS codigo" . ($colTurmaLive ? ", $colTurmaLive AS data_live" : '') . " FROM turmas ORDER BY $order");
        foreach ($stT->fetchAll() as $r) {
            $c = trim((string)($r['codigo']??''));
            if ($c === '') continue;
            $dl = trim((string)($r['data_live']??''));
            $br = $dl !== '' ? sql_br($dl) : '';
            $iso = $dl !== '' ? sql_iso($dl) : '';
            $turmas[] = ['codigo'=>$c,'data_br'=>$br,'data_iso'=>$iso];
            $turmaMap[$c] = ['br'=>$br,'iso'=>$iso];
            if ($br !== '') $liveSugest[$br] = true;
        }
    }
} catch (Throwable $e) {}
$liveSugest = array_keys($liveSugest);

// Valor atual turma
$valorTurma = '';
if (!empty($aluno['codigo_turma']))  $valorTurma = (string)$aluno['codigo_turma'];
elseif (!empty($aluno['turma_codigo'])) $valorTurma = (string)$aluno['turma_codigo'];

$valorLiveBr = '';
if (!empty($aluno['turma_live_at'])) $valorLiveBr = sql_br((string)$aluno['turma_live_at']);
elseif (!empty($aluno['data_live'])) $valorLiveBr = sql_br((string)$aluno['data_live']);

// ── POST handlers ──────────────────────────────────────────────────────
$msgOk = ''; $msgErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? 'salvar');

    if ($acao === 'gerar_certificado') {
        try {
            ae_gerar_ou_atualizar_certificado($pdo, $aluno);
            $msgOk = 'Certificado gerado/atualizado com sucesso.';
            $aluno = buscar_usuario_por_id($id) ?: $aluno;
        } catch (Throwable $e) {
            $msgErro = 'Erro ao gerar certificado: ' . $e->getMessage();
        }
    }

    if ($acao === 'reenviar_certificado_sf') {
        try {
            $certAtual = ae_certificado_atual($pdo, $id);
            if (!$certAtual) {
                throw new RuntimeException('Este aluno ainda não tem certificado emitido.');
            }
            if (trim((string)($certAtual['pdf_url'] ?? '')) === '') {
                $certAtual = ae_gerar_ou_atualizar_certificado($pdo, $aluno);
            }
            ae_reenviar_certificado_sf($pdo, $aluno, $certAtual);
            $msgOk = 'Certificado reenviado para o SuperFuncionário.';
        } catch (Throwable $e) {
            $msgErro = 'Erro ao reenviar certificado: ' . $e->getMessage();
        }
    }

    // ─── Trocar senha ─────────────────────────────────────────────────
    if ($acao === 'trocar_senha' && $senhaCol !== '') {
        $ns  = trim((string)($_POST['nova_senha'] ?? ''));
        $ns2 = trim((string)($_POST['conf_senha']  ?? ''));
        if (strlen($ns) < 6)          $msgErro = 'Senha deve ter mínimo 6 caracteres.';
        elseif ($ns !== $ns2)          $msgErro = 'As senhas não conferem.';
        else {
            $hash = password_hash($ns, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET `$senhaCol` = :h WHERE id = :id")->execute([':h'=>$hash,':id'=>$id]);
            $msgOk = 'Senha alterada com sucesso.';
        }
    }

    // ─── Salvar dados ─────────────────────────────────────────────────
    if ($acao === 'salvar') {
        $nome   = trim((string)($_POST['nome']    ?? ''));
        $email  = trim((string)($_POST['email']   ?? ''));
        $tel    = trim((string)($_POST['telefone']?? ''));
        $turma  = trim((string)($_POST['turma_codigo'] ?? $_POST['codigo_turma'] ?? ''));
        $liveBr = trim((string)($_POST['turma_live_at'] ?? ''));
        $liveSql = parse_dt($liveBr);

        $utm_source   = trim((string)($_POST['utm_source']   ?? ''));
        $utm_medium   = trim((string)($_POST['utm_medium']   ?? ''));
        $utm_campaign = trim((string)($_POST['utm_campaign'] ?? ''));
        $utm_content  = trim((string)($_POST['utm_content']  ?? ''));

        if ($nome === '' || $email === '') {
            $msgErro = 'Nome e e-mail são obrigatórios.';
        } elseif ($liveBr !== '' && $liveSql === null) {
            $msgErro = 'Data/hora inválida. Use dd/mm/aaaa hh:mm.';
        } else {
            try {
                $set = []; $p = ['id' => $id];
                if (in_array('nome',$userCols,true))  { $set[]='nome=:nome'; $p['nome']=$nome; }
                if (in_array('email',$userCols,true)) { $set[]='email=:email'; $p['email']=$email; }
                if (in_array('telefone',$userCols,true)) { $set[]='telefone=:tel'; $p['tel']=$tel; }
                $tv = ($turma !== '') ? $turma : null;
                if (in_array('codigo_turma',$userCols,true)) { $set[]='codigo_turma=:ct'; $p['ct']=$tv; }
                if (in_array('turma_codigo',$userCols,true)) { $set[]='turma_codigo=:tc'; $p['tc']=$tv; }
                $lv = ($liveSql !== null) ? $liveSql : null;
                if (in_array('turma_live_at',$userCols,true)) { $set[]='turma_live_at=:lv'; $p['lv']=$lv; }
                if (in_array('data_live',$userCols,true))     { $set[]='data_live=:lv2'; $p['lv2']=$lv; }
                if (in_array('utm_source',$userCols,true))   { $set[]='utm_source=:us'; $p['us']=$utm_source?:null; }
                if (in_array('utm_medium',$userCols,true))   { $set[]='utm_medium=:um'; $p['um']=$utm_medium?:null; }
                if (in_array('utm_campaign',$userCols,true)) { $set[]='utm_campaign=:uc'; $p['uc']=$utm_campaign?:null; }
                if (in_array('utm_content',$userCols,true))  { $set[]='utm_content=:ux'; $p['ux']=$utm_content?:null; }
                if (empty($set)) throw new RuntimeException('Nenhuma coluna compatível.');
                $pdo->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($p);
                $msgOk = 'Dados salvos com sucesso.';
                // Recarrega
                $aluno = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1')->execute(['id'=>$id]) ? null : null;
                $st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id'=>$id]); $aluno = $st->fetch(PDO::FETCH_ASSOC);
                $valorTurma = '';
                if (!empty($aluno['codigo_turma'])) $valorTurma = (string)$aluno['codigo_turma'];
                elseif (!empty($aluno['turma_codigo'])) $valorTurma = (string)$aluno['turma_codigo'];
                $valorLiveBr = '';
                if (!empty($aluno['turma_live_at'])) $valorLiveBr = sql_br((string)$aluno['turma_live_at']);
                elseif (!empty($aluno['data_live'])) $valorLiveBr = sql_br((string)$aluno['data_live']);
            } catch (Throwable $e) {
                $msgErro = 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    }
}

// Carrega tags
$stTags = $pdo->prepare("SELECT t.nome FROM user_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.user_id = :uid ORDER BY t.nome");
$stTags->execute(['uid' => $id]);
$tagsAluno = $stTags->fetchAll(PDO::FETCH_ASSOC) ?: [];
$certAluno = ae_certificado_atual($pdo, $id);
$certPdfUrl = trim((string)($certAluno['pdf_url'] ?? ''));
$certVerifyUrl = !empty($certAluno['codigo_uid'])
    ? BASE_URL . '/verificar_certificado.php?c=' . urlencode((string)$certAluno['codigo_uid'])
    : '';

$page_title = 'Editar Aluno #' . $id;
require __DIR__ . '/_header.php';
?>
<style>
.ae-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:640px) { .ae-grid { grid-template-columns:1fr; } }
.ae-tag { display:inline-flex; align-items:center; padding:3px 10px; border-radius:var(--r-full); font-size:11px; font-weight:500; border:1px solid var(--border-light); background:rgba(255,255,255,.04); color:var(--text); margin:2px 3px 0 0; }
.ae-tag.cert  { background:rgba(34,197,94,.1);  border-color:rgba(34,197,94,.3);  color:#86efac; }
.ae-tag.live  { background:rgba(249,115,22,.1); border-color:rgba(249,115,22,.3); color:#fdba74; }
.ae-tag.insc  { background:var(--info-dim); border-color:rgba(56,189,248,.3); color:var(--info); }
.section-divider { border:none; border-top:1px solid var(--border); margin:20px 0 18px; }
.input-icon-wrap { position:relative; display:flex; gap:8px; }
.input-icon-wrap input { flex:1; }
.cal-btn {
    width:38px; flex-shrink:0; height:38px; border-radius:var(--r);
    border:1px solid var(--border-light); background:var(--bg);
    color:var(--muted); cursor:pointer; font-size:15px; display:flex;
    align-items:center; justify-content:center; transition:all var(--t);
}
.cal-btn:hover { border-color:var(--primary); color:var(--primary); }
.back-link { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); text-decoration:none; margin-bottom:16px; }
.back-link:hover { color:var(--text); }
.cert-info-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:14px; }
@media(max-width:640px) { .cert-info-grid { grid-template-columns:1fr; } }
.cert-info-box { border:1px solid var(--border); background:rgba(255,255,255,.025); border-radius:var(--r); padding:10px 12px; }
.cert-info-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:4px; }
.cert-info-value { font-size:13px; font-weight:600; color:var(--text); word-break:break-word; }
.cert-link-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:8px 0 14px; }
.cert-link-input { flex:1; min-width:240px; font-size:12px; color:var(--muted); }
</style>

<a href="alunos.php" class="back-link">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    Voltar para alunos
</a>

<?php if ($msgOk): ?>
<div class="alert alert-ok" style="margin-bottom:14px"><?= h($msgOk) ?></div>
<?php endif; ?>
<?php if ($msgErro): ?>
<div class="alert alert-error" style="margin-bottom:14px"><?= h($msgErro) ?></div>
<?php endif; ?>

<!-- ─── Card: Dados pessoais ───────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header">
        <div class="card-header-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Aluno #<?= $id ?> — <?= h((string)($aluno['nome']??'')) ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($tagsAluno as $t):
                $tn = (string)$t['nome'];
                $tu = strtoupper($tn);
                $cls = '';
                if ($tu === 'CERT_EMITIDO') $cls = 'cert';
                elseif (strpos($tu,'LIVE')!==false) $cls = 'live';
                elseif ($tu === 'INSCRITO') $cls = 'insc';
            ?>
            <span class="ae-tag <?= $cls ?>"><?= h(mb_strtolower($tn)) ?></span>
            <?php endforeach; ?>
            <?php if (!$tagsAluno): ?>
                <span style="font-size:11px;color:var(--dim)">Sem tags</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="acao" value="salvar">

        <div class="form-group">
            <label class="form-label">Nome completo *</label>
            <input type="text" name="nome" required value="<?= h((string)($aluno['nome']??'')) ?>" placeholder="Nome completo">
        </div>

        <div class="ae-grid" style="margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label class="form-label">E-mail *</label>
                <input type="email" name="email" required value="<?= h((string)($aluno['email']??'')) ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Telefone</label>
                <input type="text" name="telefone" id="ae-tel" value="<?= h((string)($aluno['telefone']??'')) ?>" placeholder="(31) 99999-9999">
            </div>
        </div>

        <div class="ae-grid" style="margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label class="form-label">Código da turma</label>
                <select name="turma_codigo" id="ae-turma">
                    <option value="">Selecione…</option>
                    <?php foreach ($turmas as $t):
                        $c  = (string)($t['codigo']  ?? '');
                        $br = (string)($t['data_br'] ?? '');
                        $lbl = $br !== '' ? "$c — $br" : $c;
                    ?>
                    <option value="<?= h($c) ?>" <?= ($c===$valorTurma)?'selected':'' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Data/hora da live (turma)</label>
                <div class="input-icon-wrap">
                    <input type="text" name="turma_live_at" id="ae-live" list="ae-lista-lives"
                        value="<?= h($valorLiveBr) ?>" placeholder="dd/mm/aaaa hh:mm">
                    <div style="position:relative">
                        <button type="button" class="cal-btn" title="Escolher no calendário">📅</button>
                        <input type="datetime-local" id="ae-live-picker" aria-label="calendário"
                            style="position:absolute;inset:0;opacity:0;cursor:pointer;border:none">
                    </div>
                </div>
                <datalist id="ae-lista-lives">
                    <?php foreach ($liveSugest as $d): ?>
                    <option value="<?= h((string)$d) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <hr class="section-divider">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:12px">UTMs</div>

        <div class="ae-grid" style="margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label class="form-label">UTM Source</label>
                <input type="text" name="utm_source" value="<?= h((string)($aluno['utm_source']??'')) ?>" placeholder="google, facebook…">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">UTM Medium</label>
                <input type="text" name="utm_medium" value="<?= h((string)($aluno['utm_medium']??'')) ?>" placeholder="cpc, email…">
            </div>
        </div>
        <div class="ae-grid" style="margin-bottom:20px">
            <div class="form-group" style="margin:0">
                <label class="form-label">UTM Campaign</label>
                <input type="text" name="utm_campaign" value="<?= h((string)($aluno['utm_campaign']??'')) ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">UTM Content</label>
                <input type="text" name="utm_content" value="<?= h((string)($aluno['utm_content']??'')) ?>">
            </div>
        </div>

        <div style="display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Salvar alterações
            </button>
            <a href="alunos.php" class="btn btn-ghost">Cancelar</a>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="margin-bottom:14px">
        <div class="card-header-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
            Certificado
        </div>
    </div>

    <?php if ($certAluno): ?>
        <div class="cert-info-grid">
            <div class="cert-info-box">
                <div class="cert-info-label">Data de emissão</div>
                <div class="cert-info-value"><?= h(sql_br((string)($certAluno['emitido_em'] ?? ''))) ?: '-' ?></div>
            </div>
            <div class="cert-info-box">
                <div class="cert-info-label">Curso</div>
                <div class="cert-info-value"><?= h((string)($certAluno['course'] ?? '-')) ?></div>
            </div>
            <div class="cert-info-box">
                <div class="cert-info-label">Código</div>
                <div class="cert-info-value"><?= h((string)($certAluno['codigo_uid'] ?? '-')) ?></div>
            </div>
            <div class="cert-info-box">
                <div class="cert-info-label">Status</div>
                <div class="cert-info-value"><?= h((string)($certAluno['status'] ?? '-')) ?></div>
            </div>
        </div>

        <?php if ($certPdfUrl !== ''): ?>
            <div class="cert-info-label">Link do PDF</div>
            <div class="cert-link-row">
                <input type="text" class="cert-link-input" id="cert-pdf-link" readonly value="<?= h($certPdfUrl) ?>">
                <button type="button" class="btn btn-ghost" onclick="copyCertLink('cert-pdf-link')">Copiar link</button>
                <a href="<?= h($certPdfUrl) ?>" target="_blank" class="btn btn-ghost">Abrir PDF</a>
            </div>
        <?php endif; ?>

        <?php if ($certVerifyUrl !== ''): ?>
            <div class="cert-info-label">Link de verificação</div>
            <div class="cert-link-row">
                <input type="text" class="cert-link-input" id="cert-verify-link" readonly value="<?= h($certVerifyUrl) ?>">
                <button type="button" class="btn btn-ghost" onclick="copyCertLink('cert-verify-link')">Copiar link</button>
                <a href="<?= h($certVerifyUrl) ?>" target="_blank" class="btn btn-ghost">Verificar</a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
            Este aluno ainda não tem certificado emitido.
        </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <form method="post" style="margin:0" onsubmit="return confirm('Gerar ou atualizar o PDF do certificado deste aluno?')">
            <input type="hidden" name="acao" value="gerar_certificado">
            <button type="submit" class="btn btn-ghost" style="border-color:rgba(34,197,94,.35);color:var(--success)">
                Gerar certificado
            </button>
        </form>
        <?php if ($certAluno): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Reenviar este certificado para o SuperFuncionário?')">
                <input type="hidden" name="acao" value="reenviar_certificado_sf">
                <button type="submit" class="btn btn-ghost" style="border-color:rgba(56,189,248,.35);color:var(--info)">
                    Reenviar certificado
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($senhaCol !== ''): ?>
<!-- ─── Card: Senha de acesso ─────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="margin-bottom:14px">
        <div class="card-header-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Senha de acesso
        </div>
    </div>
    <form method="post">
        <input type="hidden" name="acao" value="trocar_senha">
        <div class="ae-grid" style="margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label class="form-label">Nova senha (mín. 6 caracteres)</label>
                <input type="password" name="nova_senha" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Confirmar nova senha</label>
                <input type="password" name="conf_senha" required minlength="6" autocomplete="new-password">
            </div>
        </div>
        <button type="submit" class="btn btn-ghost" style="border-color:rgba(245,158,11,.4);color:var(--warning)"
            onclick="return confirm('Confirmar troca de senha?')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            Salvar nova senha
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ─── Card: Tags atuais ─────────────────────────────────────────── -->
<div class="card">
    <div class="card-header-title" style="margin-bottom:10px">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--info)" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        Tags atuais
    </div>
    <div>
        <?php if ($tagsAluno): ?>
            <?php foreach ($tagsAluno as $t):
                $tn = (string)$t['nome'];
                $tu = strtoupper($tn);
                $cls = '';
                if ($tu === 'CERT_EMITIDO') $cls = 'cert';
                elseif (strpos($tu,'LIVE')!==false) $cls = 'live';
                elseif ($tu === 'INSCRITO') $cls = 'insc';
            ?>
            <span class="ae-tag <?= $cls ?>"><?= h($tn) ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span style="font-size:12px;color:var(--muted)">Nenhuma tag atribuída.</span>
        <?php endif; ?>
    </div>
</div>

<script>
function copyCertLink(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.select();
    el.setSelectionRange(0, 99999);
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(el.value);
    } else {
        document.execCommand('copy');
    }
}
(function(){
    // Telefone
    var tel = document.getElementById('ae-tel');
    function maskPhone(){
        var d = (tel.value||'').replace(/\D+/g,'');
        if(d.length>11) d=d.slice(-11);
        var o='';
        if(d.length>=1) o+='('+d.slice(0,2);
        if(d.length>=3) o+=') '+d.slice(2,7);
        if(d.length>=8) o+='-'+d.slice(7,11);
        tel.value=o;
    }
    if(tel){ if((tel.value||'').replace(/\D+/g,'').length>=10) maskPhone(); tel.addEventListener('input',maskPhone); }

    // Data live mask
    var lv = document.getElementById('ae-live');
    var pk = document.getElementById('ae-live-picker');
    function maskDt(){
        var d=(lv.value||'').replace(/\D+/g,'').slice(0,12);
        var o='';
        if(d.length>0)o+=d.slice(0,2);
        if(d.length>2)o+='/'+d.slice(2,4);
        if(d.length>4)o+='/'+d.slice(4,8);
        if(d.length>8)o+=' '+d.slice(8,10);
        if(d.length>10)o+=':'+d.slice(10,12);
        lv.value=o;
    }
    function brToIso(br){ var m=(br||'').match(/^(\d{2})\/(\d{2})\/(\d{4})\s(\d{2}):(\d{2})$/); return m?m[3]+'-'+m[2]+'-'+m[1]+'T'+m[4]+':'+m[5]:''; }
    function isoToBr(iso){ var m=(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/); return m?m[3]+'/'+m[2]+'/'+m[1]+' '+m[4]+':'+m[5]:''; }
    if(lv){ lv.addEventListener('input',maskDt); }
    if(pk&&lv){
        pk.addEventListener('focus',function(){ var iso=brToIso(lv.value); if(iso) pk.value=iso; });
        pk.addEventListener('change',function(){ var br=isoToBr(pk.value); if(br) lv.value=br; });
    }

    // Turma -> preenche live
    var TMAP = <?php echo json_encode($turmaMap, JSON_UNESCAPED_UNICODE); ?>;
    var selT = document.getElementById('ae-turma');
    function applyTurma(){
        var cod=(selT.value||'').trim(); if(!cod) return;
        var info=TMAP[cod]; if(!info||!info.br) return;
        if(lv) lv.value=info.br;
        if(pk&&info.iso) pk.value=info.iso;
    }
    if(selT){ selT.addEventListener('change',applyTurma); if(!(lv&&lv.value.trim())) applyTurma(); }
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
