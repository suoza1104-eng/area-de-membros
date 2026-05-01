<?php
// FILE: admin/aluno_editar.php
// Baseado no arquivo antigo (layout preservado) + melhorias solicitadas.

declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/funcoes.php';

proteger_admin();
$pdo = getPDO();

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Retorna lista de colunas de uma tabela (lowercase).
 */
function table_columns(PDO $pdo, string $table): array {
    try {
        $st = $pdo->query("DESCRIBE {$table}");
        $cols = [];
        foreach ($st->fetchAll() as $row) {
            if (!empty($row['Field'])) {
                $cols[] = strtolower((string)$row['Field']);
            }
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Converte entradas comuns para datetime SQL (Y-m-d H:i:s).
 * Aceita:
 * - dd/mm/aaaa hh:mm
 * - yyyy-mm-ddThh:mm (datetime-local)
 * - yyyy-mm-dd hh:mm
 */
function parse_datetime_to_sql(?string $raw): ?string {
    $s = trim((string)$raw);
    if ($s === '') return null;

    // normalizações
    $s = str_replace(['às', 'ÁS', 'As', 'às'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = str_replace('T', ' ', $s);

    // yyyy-mm-dd hh:mm
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s(\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
        $sec = isset($m[6]) && $m[6] !== '' ? $m[6] : '00';
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], (int)$sec);
    }

    // dd/mm/aaaa hh:mm
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s(\d{2}):(\d{2})$/', $s, $m)) {
        $dt = DateTime::createFromFormat('d/m/Y H:i', $s);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:00');
        }
    }

    return null;
}

function format_sql_to_br(?string $sql): string {
    $sql = trim((string)$sql);
    if ($sql === '') return '';
    try {
        $dt = new DateTime($sql);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        // tentativa simples
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s(\d{2}):(\d{2})/', $sql, $m)) {
            return $m[3].'/'.$m[2].'/'.$m[1].' '.$m[4].':'.$m[5];
        }
        return '';
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: alunos.php');
    exit;
}

$mensagemOk   = '';
$mensagemErro = '';

// Carrega aluno
$st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$aluno = $st->fetch();

if (!$aluno) {
    header('Location: alunos.php');
    exit;
}

// Carrega turmas (para as opções e vínculo turma -> data da live)
$turmas = [];
$turmaMap = []; // codigo => ['br' => 'dd/mm/aaaa hh:mm', 'iso' => 'yyyy-mm-ddThh:mm']
$liveSugestoes = [];

$turmasCols = table_columns($pdo, 'turmas');
$colTurmaCodigo = null;
foreach (['codigo', 'codigo_turma', 'turma_codigo'] as $cand) {
    if (in_array($cand, $turmasCols, true)) { $colTurmaCodigo = $cand; break; }
}
$colTurmaLive = null;
foreach (['data_live', 'turma_live_at', 'live_at', 'data'] as $cand) {
    if (in_array($cand, $turmasCols, true)) { $colTurmaLive = $cand; break; }
}

function sql_to_iso_local(?string $sql): string {
    $sql = trim((string)$sql);
    if ($sql === '') return '';
    try {
        $dt = new DateTime($sql);
        return $dt->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

try {
    if ($colTurmaCodigo) {
        $orderParts = [];
        if ($colTurmaLive) $orderParts[] = $colTurmaLive . ' ASC';
        if (in_array('id', $turmasCols, true)) $orderParts[] = 'id ASC';
        if (!$orderParts) $orderParts[] = $colTurmaCodigo . ' ASC';
        $sqlTurmas = 'SELECT ' . $colTurmaCodigo . ' AS codigo' . ($colTurmaLive ? (', ' . $colTurmaLive . ' AS data_live') : '') . ' FROM turmas ORDER BY ' . implode(', ', $orderParts);
        $stTurmas = $pdo->query($sqlTurmas);
        $rowsTurmas = $stTurmas->fetchAll();
        foreach ($rowsTurmas as $r) {
            $c = trim((string)($r['codigo'] ?? ''));
            if ($c === '') continue;
            $dl = trim((string)($r['data_live'] ?? ''));
            $br = $dl !== '' ? format_sql_to_br($dl) : '';
            $iso = $dl !== '' ? sql_to_iso_local($dl) : '';
            $turmas[] = ['codigo' => $c, 'data_sql' => $dl, 'data_br' => $br, 'data_iso' => $iso];
            if ($br !== '') {
                $turmaMap[$c] = ['br' => $br, 'iso' => $iso];
                $liveSugestoes[$br] = true;
            } else {
                $turmaMap[$c] = ['br' => '', 'iso' => ''];
            }
        }
    }
} catch (Throwable $e) {
    // se der erro, apenas não mostra a lista (não deve quebrar a tela)
}

$liveSugestoes = array_keys($liveSugestoes);

$userCols = table_columns($pdo, 'users');

// Valores atuais (compatibilidade)
$valorTurmaAtual = '';
if (!empty($aluno['codigo_turma'])) {
    $valorTurmaAtual = (string)$aluno['codigo_turma'];
} elseif (!empty($aluno['turma_codigo'])) {
    $valorTurmaAtual = (string)$aluno['turma_codigo'];
}

$valorLiveAtualBr = '';
if (!empty($aluno['turma_live_at'])) {
    $valorLiveAtualBr = format_sql_to_br((string)$aluno['turma_live_at']);
} elseif (!empty($aluno['data_live'])) {
    $valorLiveAtualBr = format_sql_to_br((string)$aluno['data_live']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome   = trim((string)($_POST['nome'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $tel    = trim((string)($_POST['telefone'] ?? ''));

    // Campo "Código da turma" (mantendo name antigo para não quebrar)
    $turma  = trim((string)($_POST['turma_codigo'] ?? $_POST['codigo_turma'] ?? ''));

    // Campo de data em BR
    $liveBr = trim((string)($_POST['turma_live_at'] ?? ''));
    $liveAtSql = parse_datetime_to_sql($liveBr);

    $utm_source   = trim((string)($_POST['utm_source'] ?? ''));
    $utm_medium   = trim((string)($_POST['utm_medium'] ?? ''));
    $utm_campaign = trim((string)($_POST['utm_campaign'] ?? ''));
    $utm_content  = trim((string)($_POST['utm_content'] ?? ''));

    if ($nome === '' || $email === '') {
        $mensagemErro = 'Nome e e-mail são obrigatórios.';
    } elseif ($liveBr !== '' && $liveAtSql === null) {
        $mensagemErro = 'Data/hora inválida. Use o formato dd/mm/aaaa hh:mm (ex.: 18/12/2025 19:30).';
    } else {
        try {
            // Monta UPDATE apenas com colunas existentes (evita HTTP 500 por coluna ausente)
            $set = [];
            $params = ['id' => $id];

            if (in_array('nome', $userCols, true)) { $set[] = 'nome = :nome'; $params['nome'] = $nome; }
            if (in_array('email', $userCols, true)) { $set[] = 'email = :email'; $params['email'] = $email; }
            if (in_array('telefone', $userCols, true)) { $set[] = 'telefone = :telefone'; $params['telefone'] = $tel; }

            // ✅ Turma: prioridade para codigo_turma
            $turmaVal = ($turma !== '') ? $turma : null;
            if (in_array('codigo_turma', $userCols, true)) {
                $set[] = 'codigo_turma = :codigo_turma';
                $params['codigo_turma'] = $turmaVal;
            }
            // mantém compatibilidade, se existir
            if (in_array('turma_codigo', $userCols, true)) {
                $set[] = 'turma_codigo = :turma_codigo';
                $params['turma_codigo'] = $turmaVal;
            }

            // ✅ Live
            $liveVal = ($liveAtSql !== null) ? $liveAtSql : null;
            if (in_array('turma_live_at', $userCols, true)) {
                $set[] = 'turma_live_at = :turma_live_at';
                $params['turma_live_at'] = $liveVal;
            }
            // compatibilidade
            if (in_array('data_live', $userCols, true)) {
                $set[] = 'data_live = :data_live';
                $params['data_live'] = $liveVal;
            }

            if (in_array('utm_source', $userCols, true)) { $set[] = 'utm_source = :utm_source'; $params['utm_source'] = ($utm_source !== '' ? $utm_source : null); }
            if (in_array('utm_medium', $userCols, true)) { $set[] = 'utm_medium = :utm_medium'; $params['utm_medium'] = ($utm_medium !== '' ? $utm_medium : null); }
            if (in_array('utm_campaign', $userCols, true)) { $set[] = 'utm_campaign = :utm_campaign'; $params['utm_campaign'] = ($utm_campaign !== '' ? $utm_campaign : null); }
            if (in_array('utm_content', $userCols, true)) { $set[] = 'utm_content = :utm_content'; $params['utm_content'] = ($utm_content !== '' ? $utm_content : null); }

            if (empty($set)) {
                throw new RuntimeException('Nenhuma coluna compatível encontrada para salvar este aluno.');
            }

            $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $mensagemOk = 'Dados do aluno atualizados com sucesso.';

            // recarrega aluno
            $st = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            $aluno = $st->fetch();

            // atualiza valores atuais
            $valorTurmaAtual = '';
            if (!empty($aluno['codigo_turma'])) {
                $valorTurmaAtual = (string)$aluno['codigo_turma'];
            } elseif (!empty($aluno['turma_codigo'])) {
                $valorTurmaAtual = (string)$aluno['turma_codigo'];
            }
            $valorLiveAtualBr = '';
            if (!empty($aluno['turma_live_at'])) {
                $valorLiveAtualBr = format_sql_to_br((string)$aluno['turma_live_at']);
            } elseif (!empty($aluno['data_live'])) {
                $valorLiveAtualBr = format_sql_to_br((string)$aluno['data_live']);
            }

        } catch (Throwable $e) {
            $mensagemErro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

// Carrega tags do aluno
$stTagsAluno = $pdo->prepare("
    SELECT t.nome
    FROM user_tags ut
    JOIN tags t ON t.id = ut.tag_id
    WHERE ut.user_id = :uid
    ORDER BY t.nome ASC
");
$stTagsAluno->execute(['uid' => $id]);
$tagsAluno = $stTagsAluno->fetchAll();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Editar aluno #<?= (int)$id ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg:#020617;
            --card:#020617;
            --border:#1f2937;
            --primary:#facc15;
            --text:#e5e7eb;
            --muted:#9ca3af;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            font-family:Arial, sans-serif;
            background:#020617;
            color:var(--text);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit;}
        .page{
            max-width:720px;
            margin:0 auto;
            padding:16px 12px 32px;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-bottom:12px;
        }
        .top-title{
            font-size:18px;
            font-weight:bold;
        }
        .top-sub{
            font-size:12px;
            color:var(--muted);
        }
        .top-actions a{
            font-size:12px;
            color:var(--muted);
        }
        .card{
            background:var(--card);
            border-radius:16px;
            border:1px solid var(--border);
            padding:14px 12px 16px;
            box-shadow:0 14px 32px rgba(0,0,0,.45);
            margin-bottom:14px;
        }
        .card-title{
            font-size:14px;
            font-weight:bold;
            margin-bottom:6px;
        }
        .field{ margin-bottom:9px; }
        label{
            display:block;
            font-size:12px;
            color:var(--muted);
            margin-bottom:3px;
        }
        input[type="text"],
        input[type="email"]{
            width:100%;
            padding:7px 9px;
            border-radius:10px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text);
            font-size:13px;
        }
        .row{ display:flex; gap:10px; }
        .col-2{ flex:1; }
        @media (max-width:720px){ .row{flex-direction:column;} }

        .btn{
            margin-top:8px;
            padding:9px 14px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:13px;
            cursor:pointer;
        }
        .btn:hover{filter:brightness(1.05);}
        .msg-ok{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(34,197,94,.12);
            border:1px solid #22c55e;
            color:#bbf7d0;
            font-size:12px;
        }
        .msg-erro{
            margin-bottom:8px;
            padding:8px 10px;
            border-radius:10px;
            background:rgba(239,68,68,.12);
            border:1px solid #ef4444;
            color:#fecaca;
            font-size:12px;
        }

        .tags-box{
            margin-top:4px;
            font-size:11px;
            color:var(--muted);
        }
        .tag-label{
            display:inline-block;
            padding:2px 6px;
            border-radius:999px;
            border:1px solid #1f2937;
            margin:2px 3px 0 0;
            font-size:10px;
        }
        .tag-label.cert{ border-color:#22c55e; color:#bbf7d0; }

        /* input com botão de calendário */
        .input-wrap{ display:flex; gap:8px; align-items:center; }
        .icon-btn{
            width:40px;
            min-width:40px;
            height:34px;
            border-radius:10px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text);
            cursor:pointer;
            font-size:16px;
        }
        .help{
            font-size:11px;
            color:var(--muted);
            margin-top:3px;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <div class="top-title">Editar aluno</div>
            <div class="top-sub">Ajuste nome, e-mail, telefone, turma e UTMs. Útil para corrigir dados antes de emitir certificado.</div>
        </div>
        <div class="top-actions">
            <a href="alunos.php">← Voltar para lista</a>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            Aluno #<?= (int)$aluno['id'] ?> — <?= h((string)($aluno['nome'] ?? '')) ?>
        </div>

        <?php if ($mensagemOk): ?>
            <div class="msg-ok"><?= h($mensagemOk) ?></div>
        <?php endif; ?>
        <?php if ($mensagemErro): ?>
            <div class="msg-erro"><?= h($mensagemErro) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="field">
                <label for="nome">Nome completo</label>
                <input type="text" id="nome" name="nome" value="<?= h((string)($aluno['nome'] ?? '')) ?>">
            </div>

            <div class="row">
                <div class="field col-2">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" value="<?= h((string)($aluno['email'] ?? '')) ?>">
                </div>
                <div class="field col-2">
                    <label for="telefone">Telefone</label>
                    <input type="text" id="telefone" name="telefone" value="<?= h((string)($aluno['telefone'] ?? '')) ?>" placeholder="(31) 99999-9999">
                </div>
            </div>

            <div class="row">
                <div class="field col-2">
                    <label for="turma_codigo">Código da turma</label>
                    <select id="turma_codigo" name="turma_codigo" style="width:100%;padding:7px 9px;border-radius:10px;border:1px solid var(--border);background:#020617;color:var(--text);font-size:13px;">
                        <option value="">Selecione uma turma...</option>
                        <?php foreach ($turmas as $t): ?>
                            <?php
                                $c = (string)($t['codigo'] ?? '');
                                $br = (string)($t['data_br'] ?? '');
                                $label = $br !== '' ? ($c . ' — ' . $br) : $c;
                            ?>
                            <option value="<?= h($c) ?>" <?= ($c === $valorTurmaAtual ? 'selected' : '') ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Use a setinha para escolher uma turma já cadastrada.</div>
                </div>

                <div class="field col-2">
                    <label for="turma_live_at">Data/hora da live (turma)</label>
                    <div class="input-wrap">
                        <input type="text" id="turma_live_at" name="turma_live_at" list="lista_lives"
                               placeholder="dd/mm/aaaa hh:mm"
                               value="<?= h($valorLiveAtualBr) ?>">
                        <div class="picker-wrap" style="position:relative;">
                            <button type="button" class="icon-btn" id="btnPick" title="Escolher no calendário">📅</button>
                            <input type="datetime-local" id="turma_live_picker" aria-label="Escolher data e hora" style="position:absolute;inset:0;opacity:0;cursor:pointer;border:none;background:transparent;">
                        </div>
                    </div>
                    <datalist id="lista_lives">
                        <?php foreach ($liveSugestoes as $d): ?>
                            <option value="<?= h((string)$d) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="help">Pode digitar ou escolher no calendário. Formato: dd/mm/aaaa hh:mm</div>
                </div>
            </div>

            <div class="row">
                <div class="field col-2">
                    <label for="utm_source">UTM Source</label>
                    <input type="text" id="utm_source" name="utm_source" value="<?= h((string)($aluno['utm_source'] ?? '')) ?>">
                </div>
                <div class="field col-2">
                    <label for="utm_medium">UTM Medium</label>
                    <input type="text" id="utm_medium" name="utm_medium" value="<?= h((string)($aluno['utm_medium'] ?? '')) ?>">
                </div>
            </div>
            <div class="row">
                <div class="field col-2">
                    <label for="utm_campaign">UTM Campaign</label>
                    <input type="text" id="utm_campaign" name="utm_campaign" value="<?= h((string)($aluno['utm_campaign'] ?? '')) ?>">
                </div>
                <div class="field col-2">
                    <label for="utm_content">UTM Content</label>
                    <input type="text" id="utm_content" name="utm_content" value="<?= h((string)($aluno['utm_content'] ?? '')) ?>">
                </div>
            </div>

            <button type="submit" class="btn">Salvar alterações</button>
        </form>

        <div class="tags-box">
            <div style="margin-bottom:4px;">Tags atuais do aluno:</div>
            <?php if ($tagsAluno): ?>
                <?php foreach ($tagsAluno as $t): ?>
                    <?php
                        $tn = (string)$t['nome'];
                        $cls = (strtoupper($tn) === 'CERT_EMITIDO') ? 'tag-label cert' : 'tag-label';
                    ?>
                    <span class="<?= $cls ?>"><?= h($tn) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="font-size:11px;color:var(--muted);">Nenhuma tag atribuída ainda.</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function(){
  function onlyDigits(v){ return (v||'').replace(/\D+/g,''); }

  // Telefone: (DD) 9xxxx-xxxx
  var tel = document.getElementById('telefone');
  function maskPhone(){
    var d = onlyDigits(tel.value);
    if (d.length > 11) d = d.slice(-11);
    var out = '';
    if (d.length >= 1) out += '(' + d.slice(0,2);
    if (d.length >= 3) out += ') ' + d.slice(2,7);
    if (d.length >= 8) out += '-' + d.slice(7,11);
    tel.value = out;
  }
  if (tel){
    // Se veio número cru do banco, mascara ao carregar
    if (onlyDigits(tel.value).length >= 10) maskPhone();
    tel.addEventListener('input', maskPhone);
  }

  // Data/hora: dd/mm/aaaa hh:mm (máscara simples)
  var dt = document.getElementById('turma_live_at');
  function maskDt(){
    var v = dt.value;
    var d = onlyDigits(v);
    // ddmmyyyyhhmm
    if (d.length > 12) d = d.slice(0,12);

    var dd = d.slice(0,2);
    var mm = d.slice(2,4);
    var yy = d.slice(4,8);
    var hh = d.slice(8,10);
    var mi = d.slice(10,12);

    var out = '';
    if (dd) out += dd;
    if (mm) out += '/' + mm;
    if (yy) out += '/' + yy;
    if (hh) out += ' ' + hh;
    if (mi) out += ':' + mi;

    dt.value = out;
  }
  if (dt){
    dt.addEventListener('input', maskDt);
  }

  // Calendário: usa datetime-local oculto para escolher e converte para BR
  var picker = document.getElementById('turma_live_picker');

  // Mapa turma -> data (vindo do PHP)
  var TURMA_MAP = <?php echo json_encode($turmaMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var turmaSel = document.getElementById('turma_codigo');

  function brToIso(br){
    // dd/mm/aaaa hh:mm -> yyyy-mm-ddThh:mm
    var m = (br||'').trim().match(/^(\d{2})\/(\d{2})\/(\d{4})\s(\d{2}):(\d{2})$/);
    if(!m) return '';
    return m[3]+'-'+m[2]+'-'+m[1]+'T'+m[4]+':'+m[5];
  }
  function isoToBr(iso){
    // yyyy-mm-ddThh:mm -> dd/mm/aaaa hh:mm
    var m = (iso||'').trim().match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if(!m) return '';
    return m[3]+'/'+m[2]+'/'+m[1]+' '+m[4]+':'+m[5];
  }

  // Ao focar/clicar no picker (invisível sobre o ícone), sincroniza o valor atual do campo BR.
  if (picker && dt){
    function syncPickerFromBr(){
      var iso = brToIso(dt.value);
      if (iso) picker.value = iso;
    }
    picker.addEventListener('focus', syncPickerFromBr);
    picker.addEventListener('click', syncPickerFromBr);
    picker.addEventListener('change', function(){
      var br = isoToBr(picker.value);
      if (br) dt.value = br;
    });
  }

  // Vincula: ao escolher turma, preencher data/hora da live daquela turma
  if (turmaSel && dt){
    function applyTurmaLive(){
      var cod = (turmaSel.value || '').trim();
      if (!cod) return;
      var info = TURMA_MAP && TURMA_MAP[cod] ? TURMA_MAP[cod] : null;
      if (!info) return;
      if (info.br) dt.value = info.br;
      if (picker && info.iso) picker.value = info.iso;
    }
    turmaSel.addEventListener('change', applyTurmaLive);
    // Se a tela abriu com turma já selecionada, garante preenchimento
    if ((dt.value || '').trim() === '') applyTurmaLive();
  }
})();
</script>
</body>
</html>
