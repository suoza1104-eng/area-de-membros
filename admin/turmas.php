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

function dt_local_value(?string $dbValue): string {
    if (!$dbValue) return '';
    $ts = strtotime($dbValue);
    if (!$ts) return '';
    return date('Y-m-d\TH:i', $ts);
}

// ===================== CLONE =====================
if (isset($_GET['clone'])) {
    $srcId = (int)$_GET['clone'];
    try {
        $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id LIMIT 1");
        $st->execute([':id' => $srcId]);
        $src = $st->fetch(PDO::FETCH_ASSOC);
        if ($src) {
            // Determine new codigo
            $baseCode = (string)$src['codigo'];
            // Strip existing _COPIA or _COPIA_N suffix
            $baseCodigo = preg_replace('/_COPIA(_\d+)?$/', '', $baseCode);
            // Find a unique codigo
            $newCodigo = $baseCodigo . '_COPIA';
            $suffix = 1;
            while (true) {
                $check = $pdo->prepare("SELECT id FROM turmas WHERE codigo = :c LIMIT 1");
                $check->execute([':c' => $newCodigo]);
                if (!$check->fetchColumn()) break;
                $suffix++;
                $newCodigo = $baseCodigo . '_COPIA_' . $suffix;
            }

            // Build INSERT from all columns except id, created_at, live_disparada, live_disparo_data
            $allCols = $pdo->query("SHOW COLUMNS FROM turmas")->fetchAll(PDO::FETCH_ASSOC);
            $skipCols = ['id', 'created_at', 'live_disparada', 'live_disparo_data'];
            $insertCols = [];
            $insertVals = [];
            $insertParams = [];
            foreach ($allCols as $col) {
                $name = (string)$col['Field'];
                if (in_array($name, $skipCols, true)) continue;
                $insertCols[] = "`$name`";
                if ($name === 'codigo') {
                    $insertVals[] = ':_codigo';
                    $insertParams[':_codigo'] = $newCodigo;
                } else {
                    $key = ':_' . $name;
                    $insertVals[] = $key;
                    $insertParams[$key] = $src[$name] ?? null;
                }
            }
            // Add back the skipped cols with new values
            if (col_exists($pdo, 'turmas', 'created_at')) {
                $insertCols[] = '`created_at`';
                $insertVals[] = 'NOW()';
            }
            if (col_exists($pdo, 'turmas', 'live_disparada')) {
                $insertCols[] = '`live_disparada`';
                $insertVals[] = '0';
            }
            if (col_exists($pdo, 'turmas', 'live_disparo_data')) {
                $insertCols[] = '`live_disparo_data`';
                $insertVals[] = 'NULL';
            }

            $sql = "INSERT INTO turmas (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
            $pdo->prepare($sql)->execute($insertParams);
        }
    } catch (Throwable $e) {}
    header('Location: turmas.php');
    exit;
}

// ===================== SAVE =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int)($_POST['id'] ?? 0);
    $codigo      = trim((string)($_POST['codigo'] ?? ''));
    $codigoLive  = trim((string)($_POST['codigo_live'] ?? ''));
    $codigoLive  = ($codigoLive === '') ? null : $codigoLive;
    $ji          = (string)($_POST['janela_inicio'] ?? '');
    $jf          = (string)($_POST['janela_fim'] ?? '');
    $dl          = (string)($_POST['data_live'] ?? '');
    $senhaCert   = trim((string)($_POST['senha_certificado'] ?? ''));

    $jiDb = $ji ? date('Y-m-d H:i:s', strtotime($ji)) : null;
    $jfDb = $jf ? date('Y-m-d H:i:s', strtotime($jf)) : null;
    $dlDb = $dl ? date('Y-m-d H:i:s', strtotime($dl)) : null;

    if ($codigo === '' || !$jiDb || !$jfDb) {
        header('Location: turmas.php'); exit;
    }

    $hasCodigoLive   = col_exists($pdo, 'turmas', 'codigo_live');
    $hasCreatedAt    = col_exists($pdo, 'turmas', 'created_at');
    $hasLiveDisp     = col_exists($pdo, 'turmas', 'live_disparada');
    $hasSenhaCert    = col_exists($pdo, 'turmas', 'senha_certificado');

    // Migration: cria coluna senha_certificado se não existir
    if (!$hasSenhaCert) {
        try { $pdo->exec("ALTER TABLE turmas ADD COLUMN senha_certificado VARCHAR(255) NOT NULL DEFAULT ''"); $hasSenhaCert = true; } catch (Throwable $e) {}
    }

    if ($id > 0) {
        $set = []; $params = [':id' => $id];
        $set[] = "codigo = :c";         $params[':c']  = $codigo;
        $set[] = "janela_inicio = :ji"; $params[':ji'] = $jiDb;
        $set[] = "janela_fim = :jf";    $params[':jf'] = $jfDb;
        $set[] = "data_live = :dl";     $params[':dl'] = $dlDb;
        if ($hasCodigoLive) { $set[] = "codigo_live = :cl"; $params[':cl'] = $codigoLive; }
        if ($hasSenhaCert)  { $set[] = "senha_certificado = :sc"; $params[':sc'] = $senhaCert; }

        try {
            $pdo->prepare("UPDATE turmas SET " . implode(", ", $set) . " WHERE id = :id")->execute($params);
        } catch (Throwable $e) {
            $msg = strpos((string)$e->getMessage(), '1062') !== false ? 'Código já existe em outra turma.' : 'Erro ao salvar turma.';
            header('Location: turmas.php?err=' . urlencode($msg)); exit;
        }
    } else {
        $cols = ["codigo", "janela_inicio", "janela_fim", "data_live"];
        $vals = [":c", ":ji", ":jf", ":dl"];
        $params = [':c'=>$codigo, ':ji'=>$jiDb, ':jf'=>$jfDb, ':dl'=>$dlDb];
        if ($hasCodigoLive) { $cols[] = "codigo_live"; $vals[] = ":cl"; $params[':cl'] = $codigoLive; }
        if ($hasSenhaCert)  { $cols[] = "senha_certificado"; $vals[] = ":sc"; $params[':sc'] = $senhaCert; }
        if ($hasCreatedAt)  { $cols[] = "created_at"; $vals[] = "NOW()"; }
        if ($hasLiveDisp)   { $cols[] = "live_disparada"; $vals[] = "0"; }

        try {
            $pdo->prepare("INSERT INTO turmas (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")")->execute($params);
        } catch (Throwable $e) {
            $msg = strpos((string)$e->getMessage(), '1062') !== false ? 'Código já existe em outra turma.' : 'Erro ao salvar turma.';
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

// ===================== LOAD =====================
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare("SELECT * FROM turmas WHERE id = :id");
    $st->execute([':id' => (int)$_GET['edit']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$turmas = $pdo->query("SELECT * FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
.btn-sm { font-size: 11px; padding: 4px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card); color: var(--text); cursor: pointer; text-decoration: none; display: inline-block; }
.btn-sm:hover { background: rgba(255,255,255,.08); }
.btn-danger-sm { border-color: rgba(239,68,68,.3); color: #ef4444; }
.btn-danger-sm:hover { background: rgba(239,68,68,.12); }
.badge-ok   { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(34,197,94,.12); color:#4ade80; border:1px solid rgba(34,197,94,.25); }
.badge-off  { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(255,255,255,.06); color:var(--muted); border:1px solid var(--border); }
.badge-warn { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10.5px; background:rgba(251,191,36,.12); color:#fbbf24; border:1px solid rgba(251,191,36,.25); }
.table-turmas td, .table-turmas th { font-size: 12px; }
.table-turmas td { vertical-align: middle; }
</style>

<div class="page-turmas">

<?php if (isset($_GET['err']) && $_GET['err'] !== ''): ?>
    <div style="margin-bottom:12px;padding:10px 14px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:13px;">
        <?= h((string)$_GET['err']) ?>
    </div>
<?php endif; ?>

<!-- ===== FORM ===== -->
<div class="card">
    <h4 style="margin:0 0 4px 0;"><?= $edit ? 'Editar turma' : 'Nova turma' ?></h4>
    <p style="margin:0 0 16px 0;font-size:12px;color:var(--muted);">Campos básicos da turma. Webhook e SF configuram-se nas páginas dedicadas.</p>

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
            </div>
        </div>

        <!-- Janelas e data -->
        <p class="section-label">Janela de Inscrição &amp; Data da Live</p>
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

        <!-- Ações -->
        <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
            <button class="btn" type="submit"><?= $edit ? 'Salvar alterações' : 'Criar turma' ?></button>
            <?php if ($edit): ?>
                <a class="btn-secondary" href="turmas.php">Cancelar</a>
                <a class="btn-secondary" href="webhooks.php?live_edit=<?= (int)$edit['id'] ?>" style="margin-left:4px;">⚙️ Webhook</a>
                <a class="btn-secondary" href="superfuncionario.php?sf_edit=<?= (int)$edit['id'] ?>" style="margin-left:4px;">⚙️ SF</a>
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
    <table class="table table-turmas" style="width:100%;min-width:900px;">
        <thead>
        <tr>
            <th>Código</th>
            <th>Janela</th>
            <th>Live</th>
            <th>Senha</th>
            <th>Webhook</th>
            <th>SF</th>
            <th>Disparado</th>
            <th>Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($turmas as $t): ?>
            <?php
            $whEnabled = (int)($t['live_webhook_enabled'] ?? 0) === 1 && !empty($t['webhook_live_url']);
            $sfEnabled2 = (int)($t['sf_enabled'] ?? 0) === 1;
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
                <td style="font-size:11px;color:var(--muted);"><?= h((string)($t['senha_certificado']??'—')) ?></td>
                <td>
                    <?php if ($whEnabled): ?>
                        <span class="badge-ok">ON</span>
                    <?php elseif (!empty($t['webhook_live_url'])): ?>
                        <span class="badge-warn">OFF</span>
                    <?php else: ?>
                        <span class="badge-off">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sfEnabled2): ?>
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
                    <a href="?clone=<?= (int)$t['id'] ?>" class="btn-sm" onclick="return confirm('Clonar esta turma?')">Clonar</a>
                    <a href="webhooks.php?live_edit=<?= (int)$t['id'] ?>" class="btn-sm">⚙️ Webhook</a>
                    <a href="superfuncionario.php?sf_edit=<?= (int)$t['id'] ?>" class="btn-sm">⚙️ SF</a>
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

<?php include __DIR__ . '/_footer.php'; ?>
