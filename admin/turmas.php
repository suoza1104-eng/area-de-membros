<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
$pdo = getPDO();

/** Verifica se uma coluna existe em uma tabela. */
function col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute([':c' => $col]);
        return (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
/**
 * Parseia o campo de filtro de tags da turma.
 * - Compatível com legado CSV (inclui_any)
 * - Novo formato: JSON (include_any, exclude_any, exclude_cert, exclude_zero)
 */
function parse_live_filter_cfg(?string $raw): array {
    $raw = trim((string)$raw);
    $cfg = [
        'include_any'  => [],
        'exclude_any'  => [],
        'exclude_cert' => 0,
        'exclude_zero' => 0,
    ];
    if ($raw === '') return $cfg;

    // JSON?
    if ($raw[0] === '{' || $raw[0] === '[') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if (isset($j['include_any']) && is_array($j['include_any'])) {
                $cfg['include_any'] = array_values(array_filter(array_map('intval', $j['include_any']), fn($v)=>$v>0));
            }
            if (isset($j['exclude_any']) && is_array($j['exclude_any'])) {
                $cfg['exclude_any'] = array_values(array_filter(array_map('intval', $j['exclude_any']), fn($v)=>$v>0));
            }
            if (isset($j['exclude_cert'])) $cfg['exclude_cert'] = (int)(!!$j['exclude_cert']);
            if (isset($j['exclude_zero'])) $cfg['exclude_zero'] = (int)(!!$j['exclude_zero']);
            return $cfg;
        }
    }

    // Legado CSV = include_any
    $ids = [];
    foreach (explode(',', $raw) as $p) {
        $v = (int)trim($p);
        if ($v > 0) $ids[] = $v;
    }
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
    // Se tudo vazio/zero, mantém NULL pra não poluir
    if (!$out['include_any'] && !$out['exclude_any'] && $out['exclude_cert']===0 && $out['exclude_zero']===0) {
        return null;
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** Verifica se uma tabela existe. */
function table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE :t");
        $st->execute([':t' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dt_local_value(?string $dbValue): string {
    if (!$dbValue) return '';
    $ts = strtotime($dbValue);
    if (!$ts) return '';
    return date('Y-m-d\TH:i', $ts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $codigo = trim((string)($_POST['codigo'] ?? ''));
    $ji     = (string)($_POST['janela_inicio'] ?? '');
    $jf     = (string)($_POST['janela_fim'] ?? '');
    $dl     = (string)($_POST['data_live'] ?? '');
    $url    = trim((string)($_POST['webhook_live_url'] ?? ''));
    $delay  = (int)($_POST['delay_ms'] ?? 500);
    $codigoLive = trim((string)($_POST['codigo_live'] ?? ''));
    $codigoLive = ($codigoLive === '') ? null : $codigoLive;

    $liveEnabled = isset($_POST['live_webhook_enabled']) ? 1 : 0;
    $disparo     = (string)($_POST['live_disparo_data'] ?? '');
    $includeSel = $_POST['live_include_tag_ids'] ?? ($_POST['live_filter_tag_ids'] ?? []);
    $excludeSel = $_POST['live_exclude_tag_ids'] ?? [];
    $excludeCert = isset($_POST['live_exclude_cert']) ? 1 : 0;
    $excludeZero = isset($_POST['live_exclude_zero']) ? 1 : 0;

    $jiDb   = $ji ? date('Y-m-d H:i:s', strtotime($ji)) : null;
    $jfDb   = $jf ? date('Y-m-d H:i:s', strtotime($jf)) : null;
    $dlDb   = $dl ? date('Y-m-d H:i:s', strtotime($dl)) : null;
    $dispDb = $disparo ? date('Y-m-d H:i:s', strtotime($disparo)) : null;

    // tags CSV
    $tagsCsv = null;
    // monta config avançada (JSON) e mantém compatível com legado
    $cfg = [
        'include_any'  => is_array($includeSel) ? array_values(array_filter(array_map('intval', $includeSel), fn($v)=>$v>0)) : [],
        'exclude_any'  => is_array($excludeSel) ? array_values(array_filter(array_map('intval', $excludeSel), fn($v)=>$v>0)) : [],
        'exclude_cert' => $excludeCert,
        'exclude_zero' => $excludeZero,
    ];
    $tagsCsv = encode_live_filter_cfg($cfg);


    if ($codigo === '' || !$jiDb || !$jfDb) {
        // validação mínima (mantém simples)
        header('Location: turmas.php');
        exit;
    }

    // Detecta colunas opcionais da tabela turmas (blinda contra banco divergente)
    $hasLiveEnabled = col_exists($pdo, 'turmas', 'live_webhook_enabled');
    $hasDispDate    = col_exists($pdo, 'turmas', 'live_disparo_data');
    $hasTagIds      = col_exists($pdo, 'turmas', 'live_filter_tag_ids');
    $hasCreatedAt   = col_exists($pdo, 'turmas', 'created_at');
    $hasLiveDisp    = col_exists($pdo, 'turmas', 'live_disparada');
    $hasCodigoLive  = col_exists($pdo, 'turmas', 'codigo_live');

    if ($id > 0) {
        // lê antigo para decidir se precisa resetar live_disparada
        $old = null;
        if ($hasLiveDisp) {
            try {
                $stOld = $pdo->prepare("SELECT data_live, webhook_live_url, live_webhook_enabled, live_disparo_data, live_disparada FROM turmas WHERE id = :id");
                $stOld->execute([':id' => $id]);
                $old = $stOld->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) { $old = null; }
        }

        $set = [];
        $params = [':id' => $id];

        // colunas base (existem no seu schema)
        $set[] = "codigo = :c";        $params[':c']  = $codigo;
        $set[] = "janela_inicio = :ji";$params[':ji'] = $jiDb;
        $set[] = "janela_fim = :jf";   $params[':jf'] = $jfDb;
        $set[] = "data_live = :dl";    $params[':dl'] = $dlDb;
        $set[] = "webhook_live_url = :u"; $params[':u'] = ($url !== '' ? $url : null);
        $set[] = "delay_ms = :d";      $params[':d']  = $delay;

        if ($hasCodigoLive) { $set[] = "codigo_live = :cl"; $params[':cl'] = $codigoLive; }

        if ($hasLiveEnabled) { $set[] = "live_webhook_enabled = :en"; $params[':en'] = $liveEnabled; }
        if ($hasDispDate)    { $set[] = "live_disparo_data = :disp";  $params[':disp'] = $dispDb; }
        if ($hasTagIds)      { $set[] = "live_filter_tag_ids = :tags";$params[':tags'] = $tagsCsv; }

        // reset live_disparada se mudou algo relevante
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
            $set[] = "live_disparada = :ld";
            $params[':ld'] = $ld;
        }

        $sql = "UPDATE turmas SET " . implode(",\n    ", $set) . " WHERE id = :id";
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            $msg = 'Erro ao salvar turma.';
            if (strpos((string)$e->getMessage(), '1062') !== false) {
                $msg = 'Código da live já existe em outra turma.';
            }
            header('Location: turmas.php?err=' . urlencode($msg));
            exit;
        }
    } else {
        $cols = ["codigo","janela_inicio","janela_fim","data_live","webhook_live_url","delay_ms"];
        $vals = [":c",":ji",":jf",":dl",":u",":d"];
        $params = [
            ':c'  => $codigo,
            ':ji' => $jiDb,
            ':jf' => $jfDb,
            ':dl' => $dlDb,
            ':u'  => ($url !== '' ? $url : null),
            ':d'  => $delay,
        ];

        if ($hasCodigoLive) { $cols[] = "codigo_live"; $vals[] = ":cl"; $params[':cl'] = $codigoLive; }
        if ($hasLiveEnabled) { $cols[] = "live_webhook_enabled"; $vals[] = ":en"; $params[':en'] = $liveEnabled; }
        if ($hasDispDate)    { $cols[] = "live_disparo_data";    $vals[] = ":disp"; $params[':disp'] = $dispDb; }
        if ($hasTagIds)      { $cols[] = "live_filter_tag_ids";  $vals[] = ":tags"; $params[':tags'] = $tagsCsv; }
        if ($hasCreatedAt)   { $cols[] = "created_at";           $vals[] = "NOW()"; }
        if ($hasLiveDisp)    { $cols[] = "live_disparada";        $vals[] = "0"; }

        $sql = "INSERT INTO turmas (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")";
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            $msg = 'Erro ao salvar turma.';
            if (strpos((string)$e->getMessage(), '1062') !== false) {
                $msg = 'Código da live já existe em outra turma.';
            }
            header('Location: turmas.php?err=' . urlencode($msg));
            exit;
        }
    }

    // Atualiza também os alunos já inscritos nesta turma, para o contador (turma_live_at) ficar consistente
    if ($codigo !== '') {
        try {
            if (col_exists($pdo, 'users', 'data_live')) {
                $pdo->prepare("UPDATE users SET data_live = :dl WHERE codigo_turma = :c")->execute([':dl' => $dlDb, ':c' => $codigo]);
            }
            if (col_exists($pdo, 'users', 'turma_live_at')) {
                $pdo->prepare("UPDATE users SET turma_live_at = :dl WHERE codigo_turma = :c")->execute([':dl' => $dlDb, ':c' => $codigo]);
            }
        } catch (Throwable $e) {}
    }

    header('Location: turmas.php');
    exit;
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $pdo->prepare("DELETE FROM turmas WHERE id = :id")->execute([':id' => $id]);
    header('Location: turmas.php');
    exit;
}

// atualizar data da live (turma + alunos)
if (isset($_GET['update_live_date']) && isset($_GET['codigo']) && isset($_GET['nova_data'])) {
    $codigo = (string)$_GET['codigo'];
    $nova   = (string)$_GET['nova_data'];
    $dlDb   = date('Y-m-d H:i:s', strtotime($nova));

    $pdo->prepare("UPDATE turmas SET data_live = :dl WHERE codigo = :c")->execute([':dl' => $dlDb, ':c' => $codigo]);
    $pdo->prepare("UPDATE users SET data_live = :dl WHERE codigo_turma = :c")->execute([':dl' => $dlDb, ':c' => $codigo]);
    if (col_exists($pdo, 'users', 'turma_live_at')) {
        $pdo->prepare("UPDATE users SET turma_live_at = :dl WHERE codigo_turma = :c")->execute([':dl' => $dlDb, ':c' => $codigo]);
    }
    header('Location: turmas.php');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM turmas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// carrega tags para filtro (se existir)
$allTags = [];
if (table_exists($pdo, 'tags') && col_exists($pdo, 'tags', 'ativo')) {
    $allTagsStmt = $pdo->query("SELECT id, nome FROM tags WHERE ativo = 1 ORDER BY nome ASC");
    $allTags = $allTagsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$turmas = $pdo->query("SELECT * FROM turmas ORDER BY janela_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);

// menu ativo
$menu = 'turmas';

include __DIR__ . '/_header.php';
?>
<?php if (isset($_GET['err']) && $_GET['err'] !== ''): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:10px;background:rgba(255,0,0,.12);border:1px solid rgba(255,0,0,.25);color:#ffd7d7;">
        <?= htmlspecialchars((string)$_GET['err']) ?>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Turmas (janelas de inscrição)</h3>
    <form method="post">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
            <div>
                <label>Código da turma<br>
                    <input type="text" name="codigo" required value="<?= htmlspecialchars($edit['codigo'] ?? '') ?>">
                </label>
            </div>


            <div>
                <label>Código da live<br>
                    <input type="text" name="codigo_live" value="<?= htmlspecialchars($edit['codigo_live'] ?? '') ?>" placeholder="ex: live-perfil-led-18dez">
                </label>
                <div style="font-size:11px;opacity:.75;margin-top:4px;">
                    Slug do link da live (opcional).
                </div>
            </div>

            <div>
                <label>Janela início<br>
                    <input type="datetime-local" name="janela_inicio" required value="<?= htmlspecialchars(dt_local_value($edit['janela_inicio'] ?? null)) ?>">
                </label>
            </div>

            <div>
                <label>Janela fim<br>
                    <input type="datetime-local" name="janela_fim" required value="<?= htmlspecialchars(dt_local_value($edit['janela_fim'] ?? null)) ?>">
                </label>
            </div>

            <div>
                <label>Data/hora da live<br>
                    <input type="datetime-local" name="data_live" value="<?= htmlspecialchars(dt_local_value($edit['data_live'] ?? null)) ?>">
                </label>
            </div>
        </div>

        <div style="margin-top:10px;">
            <label>Webhook da live (URL que receberá a fila de alunos)</label><br>
            <input type="text" name="webhook_live_url" style="width:100%;" value="<?= htmlspecialchars($edit['webhook_live_url'] ?? '') ?>">
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:8px;">
            <div>
                <label>Delay entre envios (ms)<br>
                    <input type="number" name="delay_ms" value="<?= htmlspecialchars((string)($edit['delay_ms'] ?? '500')) ?>">
                </label>
            </div>

            <div style="padding-top:18px;">
                <label style="font-size:12px;">
                    <input type="checkbox" name="live_webhook_enabled" <?= (!isset($edit['live_webhook_enabled']) || (int)($edit['live_webhook_enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Habilitar disparo do webhook da live
                </label>
            </div>

            <div>
                <label>Data/hora para disparar webhook<br>
                    <input type="datetime-local" name="live_disparo_data" value="<?= htmlspecialchars(dt_local_value($edit['live_disparo_data'] ?? null)) ?>">
                </label>
                <div style="font-size:12px;opacity:.75;margin-top:4px">
                    Se vazio, o disparo fica desativado (não envia automaticamente).
                </div>
            </div>
        </div>

                <div style="margin-top:10px;">
            <label>Regras de envio por tags e progresso (opcional)</label>
            <div style="font-size:12px;opacity:.78;margin-top:4px;line-height:1.3">
                Você pode criar condições para enviar apenas para alguns alunos. Se nada for marcado, serão enviados todos os alunos da turma.
            </div>

            <?php
            $filterCfg = parse_live_filter_cfg($edit['live_filter_tag_ids'] ?? null);
            $selInc = [];
            foreach (($filterCfg['include_any'] ?? []) as $tid) { $selInc[(int)$tid] = true; }
            $selExc = [];
            foreach (($filterCfg['exclude_any'] ?? []) as $tid) { $selExc[(int)$tid] = true; }
            $excCert = (int)($filterCfg['exclude_cert'] ?? 0) === 1;
            $excZero = (int)($filterCfg['exclude_zero'] ?? 0) === 1;
            ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
                <div>
                    <div style="font-size:12px;margin-bottom:6px;">
                        <strong>ENVIAR se tiver pelo menos 1 dessas tags (OU)</strong>
                    </div>
                    <input type="text" class="tag-search" data-target="live_include_tag_ids" placeholder="Buscar tags..." style="width:100%;padding:8px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:#0b1220;color:#fff;margin-bottom:6px;">
                    <select name="live_include_tag_ids[]" id="live_include_tag_ids" multiple size="10" style="width:100%;padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:#0b1220;color:#fff;">
                        <?php foreach ($allTags as $tg): $tid=(int)$tg['id']; ?>
                            <option value="<?= $tid ?>" <?= isset($selInc[$tid]) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tg['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;opacity:.7;margin-top:4px;">
                        Se deixar vazio, não exige tag (não filtra por inclusão).
                    </div>
                </div>

                <div>
                    <div style="font-size:12px;margin-bottom:6px;">
                        <strong>NÃO ENVIAR se tiver qualquer 1 dessas tags</strong>
                    </div>
                    <input type="text" class="tag-search" data-target="live_exclude_tag_ids" placeholder="Buscar tags..." style="width:100%;padding:8px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:#0b1220;color:#fff;margin-bottom:6px;">
                    <select name="live_exclude_tag_ids[]" id="live_exclude_tag_ids" multiple size="10" style="width:100%;padding:10px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:#0b1220;color:#fff;">
                        <?php foreach ($allTags as $tg): $tid=(int)$tg['id']; ?>
                            <option value="<?= $tid ?>" <?= isset($selExc[$tid]) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tg['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px;opacity:.7;margin-top:4px;">
                        Ex.: selecione <code>CERT_EMITIDO</code> para não enviar para quem já gerou certificado.
                    </div>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:12px;align-items:center;">
                <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="live_exclude_cert" value="1" <?= $excCert ? 'checked' : '' ?>>
                    <span>Não enviar para quem já emitiu certificado (tag <code>CERT_EMITIDO</code>)</span>
                </label>

                <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="live_exclude_zero" value="1" <?= $excZero ? 'checked' : '' ?>>
                    <span>Não enviar para quem está com <strong>0%</strong> de progresso (nenhuma aula obrigatória concluída)</span>
                </label>
            </div>

            <div style="font-size:11px;opacity:.75;margin-top:6px;line-height:1.35">
                Dica: você pode combinar as regras. Ex.: ENVIAR se tiver (INSCRITO OU VIU_AULA_1), e NÃO ENVIAR se tiver CERT_EMITIDO.
            </div>
        </div>

        <script>
        // Filtro de busca nas selects de tags (não altera estética geral)
        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.tag-search').forEach(function(inp){
                inp.addEventListener('input', function(){
                    var q = (inp.value || '').toLowerCase();
                    var targetId = inp.getAttribute('data-target');
                    var sel = document.getElementById(targetId);
                    if(!sel) return;
                    Array.from(sel.options).forEach(function(opt){
                        var txt = (opt.textContent || '').toLowerCase();
                        opt.style.display = txt.indexOf(q) !== -1 ? '' : 'none';
                    });
                });
            });
        });
        </script>

<div style="margin-top:10px;">
            <button type="submit" class="btn"><?= $edit ? 'Salvar turma' : 'Criar turma' ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h3>Lista de turmas</h3>
    <table class="table" style="width:100%;">
        <thead>
        <tr>
            <th>ID</th>
            <th>Código</th>
            <th>Janela início</th>
            <th>Janela fim</th>
            <th>Data Live</th>
            <th>Webhook</th>
            <th>Delay</th>
            <th>Habilitado</th>
            <th>Disparo em</th>
            <th>Tags</th>
            <th>Live disparada</th>
            <th>Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($turmas as $t): ?>
            <tr>
                <td><?= (int)$t['id'] ?></td>
                <td><?= htmlspecialchars((string)($t['codigo'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($t['janela_inicio'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($t['janela_fim'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($t['data_live'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($t['webhook_live_url'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($t['delay_ms'] ?? '')) ?></td>
                <td><?= ((int)($t['live_webhook_enabled'] ?? 1) === 1) ? 'Sim' : 'Não' ?></td>
                <td><?= htmlspecialchars((string)($t['live_disparo_data'] ?? '')) ?></td>
                <td>
                    <?php
                    $cfg = parse_live_filter_cfg($t['live_filter_tag_ids'] ?? null);
                    $tagName = function(int $id) use ($allTags) {
                        static $map = null;
                        if ($map === null) {
                            $map = [];
                            foreach ($allTags as $tg) $map[(int)$tg['id']] = (string)$tg['nome'];
                        }
                        return $map[$id] ?? ('#' . $id);
                    };
                    $parts = [];
                    if (!empty($cfg['include_any'])) {
                        $names = array_map(fn($id)=>$tagName((int)$id), $cfg['include_any']);
                        $parts[] = 'Inclui: ' . implode(', ', $names);
                    }
                    if (!empty($cfg['exclude_any'])) {
                        $names = array_map(fn($id)=>$tagName((int)$id), $cfg['exclude_any']);
                        $parts[] = 'Exclui: ' . implode(', ', $names);
                    }
                    if (!empty($cfg['exclude_cert'])) $parts[] = '− CERT_EMITIDO';
                    if (!empty($cfg['exclude_zero'])) $parts[] = '− 0%';
                    echo $parts ? htmlspecialchars(implode(' | ', $parts)) : '—';
                    ?>
                </td>
                <td><?= !empty($t['live_disparada']) ? 'Sim' : 'Não' ?></td>
                <td>
                    <a href="?edit=<?= (int)$t['id'] ?>" class="btn-sm">editar</a>
                    <a href="?del=<?= (int)$t['id'] ?>" onclick="return confirm('Remover turma?')" class="btn-sm">remover</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/_footer.php'; ?>