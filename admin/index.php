<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';

// ========================
// 1) LOGIN DO ADMIN
// ========================
if (empty($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    $erro = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = trim($_POST['usuario'] ?? '');
        $pass = trim($_POST['senha']   ?? '');

        if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
            $_SESSION['admin_logado'] = true;
            header('Location: ' . BASE_URL_ADMIN . '/index.php');
            exit;
        } else {
            $erro = 'Usuário ou senha inválidos.';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <title>Login Admin</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', -apple-system, sans-serif;
                background: #080e1a;
                color: #e2e8f0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .login-box {
                width: 100%;
                max-width: 380px;
                background: #0d1526;
                border: 1px solid rgba(255,255,255,.08);
                border-radius: 18px;
                padding: 36px 32px;
                box-shadow: 0 4px 24px rgba(0,0,0,.45);
            }
            .login-logo {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 48px; height: 48px;
                background: rgba(250,204,21,.15);
                border-radius: 12px;
                margin: 0 auto 20px;
                color: #ca8a04;
            }
            .login-logo svg { width: 22px; height: 22px; }
            h2 {
                font-size: 20px; font-weight: 700;
                text-align: center; margin-bottom: 6px;
                color: #e2e8f0;
            }
            .login-sub {
                font-size: 13px; color: #64748b;
                text-align: center; margin-bottom: 28px;
            }
            label {
                display: block;
                font-size: 11px; font-weight: 600;
                text-transform: uppercase; letter-spacing: .06em;
                color: #64748b;
                margin-bottom: 5px;
            }
            input[type="text"], input[type="password"] {
                width: 100%;
                padding: 9px 12px;
                border-radius: 9px;
                border: 1px solid rgba(255,255,255,.1);
                background: #080e1a;
                color: #e2e8f0;
                font-size: 14px;
                font-family: inherit;
                outline: none;
                transition: border-color .15s, box-shadow .15s;
                margin-bottom: 14px;
            }
            input:focus {
                border-color: #facc15;
                box-shadow: 0 0 0 3px rgba(250,204,21,.15);
            }
            button[type="submit"] {
                width: 100%;
                padding: 10px;
                border-radius: 999px;
                border: none;
                background: #facc15;
                color: #111827;
                font-weight: 700;
                font-size: 14px;
                font-family: inherit;
                cursor: pointer;
                margin-top: 4px;
                transition: filter .15s;
            }
            button[type="submit"]:hover { filter: brightness(1.07); }
            .erro {
                background: rgba(239,68,68,.08);
                border: 1px solid rgba(239,68,68,.2);
                color: #dc2626;
                border-radius: 8px;
                padding: 9px 12px;
                font-size: 13px;
                margin-bottom: 16px;
            }
        </style>
    </head>
    <body>
    <div class="login-box">
        <div class="login-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
        </div>
        <h2>Área Administrativa</h2>
        <p class="login-sub">Acesse com suas credenciais</p>
        <?php if ($erro): ?>
            <div class="erro"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="usuario">Usuário</label>
            <input type="text" id="usuario" name="usuario" required autofocus>

            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required>

            <button type="submit">Entrar</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ========================
// 2) LOGOUT
// ========================
if (isset($_GET['logout'])) {
    $_SESSION['admin_logado'] = false;
    session_destroy();
    header('Location: ' . BASE_URL_ADMIN . '/index.php');
    exit;
}

$pdo = getPDO();

// ========================
// 3) FILTROS (datas / turma)
// ========================
$dataDe  = trim($_GET['data_de']  ?? '');
$dataAte = trim($_GET['data_ate'] ?? '');
$turmaId = (int)($_GET['turma_id'] ?? 0);

$whereUsers  = [];
$paramsUsers = [];

if ($dataDe !== '') {
    $whereUsers[]           = 'u.created_at >= :data_de';
    $paramsUsers['data_de'] = $dataDe . ' 00:00:00';
}
if ($dataAte !== '') {
    $whereUsers[]            = 'u.created_at <= :data_ate';
    $paramsUsers['data_ate'] = $dataAte . ' 23:59:59';
}
if ($turmaId > 0) {
    $whereUsers[]            = 'u.turma_id = :turma_id';
    $paramsUsers['turma_id'] = $turmaId;
}

$whereUsersSql = $whereUsers ? ('WHERE ' . implode(' AND ', $whereUsers)) : '';

$turmas = [];
try {
    $turmas = $pdo->query("SELECT id, codigo, nome FROM turmas ORDER BY codigo ASC")->fetchAll();
} catch (Throwable $e) {
    $turmas = [];
}

// ========================
// 4) DADOS DO DASHBOARD
// ========================

$sqlTotalAlunos = "SELECT COUNT(*) FROM users u $whereUsersSql";
$stmt = $pdo->prepare($sqlTotalAlunos);
$stmt->execute($paramsUsers);
$totalAlunos = (int)$stmt->fetchColumn();

$sqlTotalCert = "
    SELECT COUNT(*)
    FROM certificates c
    JOIN users u ON u.id = c.user_id
    $whereUsersSql
";
$stmt = $pdo->prepare($sqlTotalCert);
$stmt->execute($paramsUsers);
$totalCert = (int)$stmt->fetchColumn();

$sqlFunil = "
    SELECT l.id, l.ordem, l.titulo,
           COUNT(lp.id) AS concluintes
    FROM lessons l
    LEFT JOIN lesson_progress lp
      ON lp.lesson_id = l.id
     AND lp.status = 'completed'
    LEFT JOIN users u
      ON u.id = lp.user_id
    " . ($whereUsers ? ' ' . $whereUsersSql : '') . "
    GROUP BY l.id, l.ordem, l.titulo
    ORDER BY l.ordem ASC
";
$stmt = $pdo->prepare($sqlFunil);
$stmt->execute($paramsUsers);
$funil = $stmt->fetchAll();

$sqlInsc = "
    SELECT DATE(u.created_at) AS dia, COUNT(*) AS total
    FROM users u
    $whereUsersSql
    GROUP BY DATE(u.created_at)
    ORDER BY dia ASC
";
$stmt = $pdo->prepare($sqlInsc);
$stmt->execute($paramsUsers);
$inscRows = $stmt->fetchAll();
$labelsDia = [];
$dataDia   = [];
foreach ($inscRows as $r) {
    $labelsDia[] = $r['dia'];
    $dataDia[]   = (int)$r['total'];
}

$totalAulasConta = (int)$pdo->query("
    SELECT COUNT(*) FROM lessons WHERE conta_para_conclusao = 1 AND ativo = 1
")->fetchColumn();

$sqlAlguma = "
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    JOIN lesson_progress lp ON lp.user_id = u.id
    WHERE lp.status = 'completed'
";
if ($whereUsers) {
    $sqlAlguma .= ' AND ' . implode(' AND ', $whereUsers);
}
$stmt = $pdo->prepare($sqlAlguma);
$stmt->execute($paramsUsers);
$alunosAlguma = (int)$stmt->fetchColumn();

$sqlFull = "
    SELECT u.id, COUNT(DISTINCT lp.lesson_id) AS qtd
    FROM users u
    JOIN lesson_progress lp ON lp.user_id = u.id
    JOIN lessons l         ON l.id = lp.lesson_id
    WHERE lp.status = 'completed'
      AND l.conta_para_conclusao = 1
      AND l.ativo = 1
";
if ($whereUsers) {
    $sqlFull .= ' AND ' . implode(' AND ', $whereUsers);
}
$sqlFull .= ' GROUP BY u.id';

$stmt = $pdo->prepare($sqlFull);
$stmt->execute($paramsUsers);
$fullCompleters = 0;
foreach ($stmt->fetchAll() as $r) {
    if ((int)$r['qtd'] >= $totalAulasConta && $totalAulasConta > 0) {
        $fullCompleters++;
    }
}

$onlyInscritos = max(0, $totalAlunos - $alunosAlguma);
$estagioFull   = $fullCompleters;
$estagioUma    = max(0, $alunosAlguma - $fullCompleters);

$labelsFunil = [];
$dataFunil   = [];
foreach ($funil as $f) {
    $labelsFunil[] = 'Aula ' . $f['ordem'];
    $dataFunil[]   = (int)$f['concluintes'];
}

$pctConclusao = ($totalAlunos > 0) ? round(($estagioFull / $totalAlunos) * 100, 1) : 0;

// ========================
// 5) DADOS DO FUNIL HTML/CSS
// ========================
$funnelData = [];
$funnelData[] = ['label' => 'Total Inscritos',       'count' => $totalAlunos];
$funnelData[] = ['label' => 'Assistiram alguma aula', 'count' => $alunosAlguma];
foreach ($funil as $f) {
    $funnelData[] = [
        'label' => 'Aula ' . $f['ordem'] . ' — ' . mb_strimwidth($f['titulo'], 0, 30, '…'),
        'count' => (int)$f['concluintes'],
    ];
}
$funnelData[] = ['label' => 'Certificado emitido', 'count' => $totalCert];

$funnelMax = max(1, (int)($funnelData[0]['count'] ?? 1));
foreach ($funnelData as $fi => &$fstep) {
    $fstep['pct_bar'] = max(6, (int)round(($fstep['count'] / $funnelMax) * 100));
    $prev = $fi > 0 ? (int)$funnelData[$fi - 1]['count'] : null;
    $fstep['drop'] = ($prev !== null && $prev > 0)
        ? round((($prev - $fstep['count']) / $prev) * 100, 1)
        : null;
}
unset($fstep);

// ========================
// 6) RANKING MÚLTIPLAS INSCRIÇÕES
// ========================
$rankingRows = [];
$rankHistorico = []; // [user_id => [ [data_dia, hora, payload] ]]
$hasWHL = false;
try { $pdo->query("SELECT 1 FROM webhook_logs LIMIT 0"); $hasWHL = true; } catch (Throwable $e) {}
$hasUtmCols = false;
try { $pdo->query("SELECT utm_source FROM users LIMIT 0"); $hasUtmCols = true; } catch (Throwable $e) {}

if ($hasWHL) {
    try {
        $utmSel = $hasUtmCols
            ? "u.utm_source, u.utm_medium, u.utm_campaign, u.utm_content,"
            : "NULL AS utm_source, NULL AS utm_medium, NULL AS utm_campaign, NULL AS utm_content,";

        $rankSql = "
            SELECT
                u.id, u.nome, u.email, u.telefone,
                $utmSel
                COUNT(DISTINCT DATE(wl.created_at)) AS qtd_inscricoes,
                MIN(wl.created_at) AS primeiro_cadastro,
                MAX(wl.created_at) AS ultimo_cadastro
            FROM users u
            JOIN webhook_logs wl ON wl.user_id = u.id AND wl.evento = 'INSCRITO'
            GROUP BY u.id
            ORDER BY qtd_inscricoes DESC, ultimo_cadastro DESC
            LIMIT 30
        ";
        $rankingRows = $pdo->query($rankSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rankingRows) {
            $uids = array_map('intval', array_column($rankingRows, 'id'));
            $in   = implode(',', $uids);
            $histSql = "
                SELECT wl.user_id,
                       DATE(wl.created_at) AS data_dia,
                       MIN(wl.created_at)  AS hora,
                       MIN(wl.payload_json) AS payload_raw
                FROM webhook_logs wl
                WHERE wl.user_id IN ($in) AND wl.evento = 'INSCRITO'
                GROUP BY wl.user_id, DATE(wl.created_at)
                ORDER BY wl.user_id ASC, data_dia ASC
            ";
            foreach ($pdo->query($histSql)->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $rankHistorico[(int)$h['user_id']][] = $h;
            }
        }
    } catch (Throwable $e) {
        $rankingRows = [];
    }
}

?>
<?php
$menu = 'dashboard';
include __DIR__ . '/_header.php';
?>

<div class="d-flex align-center justify-between mb-4">
    <div></div>
    <a href="alunos.php" class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        Ver alunos
    </a>
</div>

<!-- FILTROS -->
<form method="get" class="filter-bar mb-4">
    <div class="filter-group">
        <label for="data_de">Data inicial</label>
        <input type="date" id="data_de" name="data_de" value="<?= htmlspecialchars($dataDe) ?>">
    </div>
    <div class="filter-group">
        <label for="data_ate">Data final</label>
        <input type="date" id="data_ate" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>">
    </div>
    <?php if ($turmas): ?>
    <div class="filter-group">
        <label for="turma_id">Turma</label>
        <select id="turma_id" name="turma_id">
            <option value="0">Todas as turmas</option>
            <?php foreach ($turmas as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $turmaId === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['codigo'] . (empty($t['nome']) ? '' : ' – ' . $t['nome'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <a href="<?= htmlspecialchars(BASE_URL_ADMIN . '/index.php') ?>" class="reset-link">Limpar</a>
    </div>
</form>

<!-- KPI CARDS -->
<div class="kpi-grid mb-4">
    <div class="kpi kpi-y">
        <div class="kpi-icon y">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        </div>
        <div class="kpi-label">Alunos</div>
        <div class="kpi-value"><?= number_format($totalAlunos) ?></div>
        <div class="kpi-sub"><?= $dataDe || $dataAte ? 'filtrados' : 'total' ?></div>
    </div>

    <div class="kpi kpi-g">
        <div class="kpi-icon g">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
        </div>
        <div class="kpi-label">Certificados</div>
        <div class="kpi-value"><?= number_format($totalCert) ?></div>
        <div class="kpi-sub">emitidos</div>
    </div>

    <div class="kpi kpi-b">
        <div class="kpi-icon b">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="kpi-label">Acessaram</div>
        <div class="kpi-value"><?= number_format($alunosAlguma) ?></div>
        <div class="kpi-sub">viram pelo menos 1 aula</div>
    </div>

    <div class="kpi kpi-o">
        <div class="kpi-icon o">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
        </div>
        <div class="kpi-label">Concluíram</div>
        <div class="kpi-value"><?= number_format($estagioFull) ?></div>
        <div class="kpi-sub"><?= $pctConclusao ?>% do total</div>
    </div>
</div>

<!-- CHARTS ROW 1: Inscrições + Estágios -->
<div class="grid-2 mb-4">
    <div class="panel">
        <div class="panel-title">
            Inscrições por dia
        </div>
        <?php if ($labelsDia): ?>
            <canvas id="chartDia" style="max-height:220px"></canvas>
        <?php else: ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:30px 0">Nenhum dado no período</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-title">
            Distribuição de estágios
        </div>
        <canvas id="chartStage" style="max-height:220px"></canvas>
    </div>
</div>

<!-- FUNIL WEDGE SVG -->
<?php if (!empty($funnelData)):
    $fN    = count($funnelData);
    $segW  = 100;          // SVG units per segment
    $svgW  = $fN * $segW;  // total SVG viewBox width
    $svgH  = 160;          // viewBox height
    $maxFH = 138;          // funnel max height (leaves 11 top/bottom pad)
    $padV  = ($svgH - $maxFH) / 2;
    $fMax  = max(1, (int)$funnelData[0]['count']);

    $hs = [];
    foreach ($funnelData as $idx => $fstep) {
        $hs[$idx] = max(6.0, round(($fstep['count'] / $fMax) * $maxFH, 1));
    }
?>
<div class="panel mb-4" style="overflow:hidden">
    <div class="panel-title">Funil de conversão</div>
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">

      <!-- SVG: pure visual trapezoids, no text distortion risk -->
      <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>"
           preserveAspectRatio="none"
           xmlns="http://www.w3.org/2000/svg"
           style="display:block;width:100%;min-width:<?= max(320, $fN * 58) ?>px;height:150px">

        <defs>
          <linearGradient id="fgrad" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%"   stop-color="#facc15" stop-opacity=".92"/>
            <stop offset="100%" stop-color="#f59e0b" stop-opacity=".48"/>
          </linearGradient>
        </defs>

        <!-- Unified funnel path (single polygon connecting all steps) for gradient fill -->
        <?php
            $topPts = '';
            $botPts = '';
            for ($i = 0; $i < $fN; $i++) {
                $xl  = $i * $segW;
                $hL  = $hs[$i];
                $ytL = $padV + ($maxFH - $hL) / 2;
                $ybL = $ytL + $hL;
                $topPts .= "$xl,$ytL ";
                $botPts  = "$xl,$ybL " . $botPts;
            }
            // close right edge
            $xLast = $fN * $segW;
            $hLast = $hs[$fN - 1];
            $ytLast = $padV + ($maxFH - $hLast) / 2;
            $ybLast = $ytLast + $hLast;
            $allPts = trim($topPts) . " $xLast,$ytLast $xLast,$ybLast " . trim($botPts);
        ?>
        <polygon points="<?= $allPts ?>" fill="url(#fgrad)"/>

        <!-- Vertical dividers between segments -->
        <?php for ($i = 1; $i < $fN; $i++):
            $xDiv = $i * $segW;
            $hL   = $hs[$i];
            $ytL  = $padV + ($maxFH - $hL) / 2;
            $ybL  = $ytL + $hL;
        ?>
        <line x1="<?= $xDiv ?>" y1="<?= round($ytL, 1) ?>"
              x2="<?= $xDiv ?>" y2="<?= round($ybL, 1) ?>"
              stroke="rgba(8,14,26,.55)" stroke-width="1.5"/>
        <?php endfor; ?>
      </svg>

      <!-- Labels strip: always visible, always crisp -->
      <div style="display:flex;border-top:1px solid var(--border);background:var(--bg-card)">
        <?php foreach ($funnelData as $i => $fstep): ?>
        <div style="flex:1;min-width:0;text-align:center;padding:7px 3px 6px<?= ($i < $fN - 1) ? ';border-right:1px solid var(--border)' : '' ?>">
          <div style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 3px">
            <?= htmlspecialchars($fstep['label']) ?>
          </div>
          <div style="font-size:14px;font-weight:700;color:var(--text);margin-top:2px;line-height:1">
            <?= number_format($fstep['count']) ?>
          </div>
          <?php if ($fstep['drop'] !== null): ?>
          <div style="font-size:9.5px;font-weight:600;color:#fbbf24;margin-top:2px">↓ <?= $fstep['drop'] ?>%</div>
          <?php else: ?>
          <div style="font-size:9.5px;color:var(--muted);margin-top:2px">—</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
</div>
<?php endif; ?>

<!-- TABLE: Detalhamento das aulas -->
<?php if ($funil): ?>
<div class="panel">
    <div class="panel-title">Detalhamento por aula</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Aula</th>
                    <th>Concluintes</th>
                    <th>% do total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($funil as $f): ?>
                <?php $pct = $totalAlunos > 0 ? round(($f['concluintes'] / $totalAlunos) * 100, 1) : 0; ?>
                <tr>
                    <td style="color:var(--muted);font-size:12px"><?= (int)$f['ordem'] ?></td>
                    <td><?= htmlspecialchars($f['titulo']) ?></td>
                    <td><span class="fw-600"><?= number_format((int)$f['concluintes']) ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;background:var(--border);border-radius:999px;height:5px;min-width:60px">
                                <div style="width:<?= min(100, $pct) ?>%;background:var(--info);height:5px;border-radius:999px"></div>
                            </div>
                            <span style="font-size:11px;color:var(--muted);white-space:nowrap"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.color = '#64748b';
    Chart.defaults.borderColor = '#1a2540';
    Chart.defaults.font.family = "'Inter', sans-serif";

    // Inscrições por dia
    var labelsDia = <?= json_encode($labelsDia, JSON_UNESCAPED_UNICODE) ?>;
    var dataDia   = <?= json_encode($dataDia) ?>;
    var cDia = document.getElementById('chartDia');
    if (cDia && labelsDia.length) {
        new Chart(cDia, {
            type: 'line',
            data: {
                labels: labelsDia,
                datasets: [{
                    data: dataDia,
                    tension: 0.38,
                    borderWidth: 2.5,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56,189,248,.1)',
                    pointRadius: 3,
                    pointBackgroundColor: '#38bdf8',
                    fill: true
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { maxTicksLimit: 8, font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' } },
                    y: { ticks: { font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' }, beginAtZero: true }
                }
            }
        });
    }

    // Distribuição estágios
    var cStage = document.getElementById('chartStage');
    if (cStage) {
        new Chart(cStage, {
            type: 'doughnut',
            data: {
                labels: ['Só inscritos', 'Em progresso', 'Concluíram tudo'],
                datasets: [{
                    data: [<?= $onlyInscritos ?>, <?= $estagioUma ?>, <?= $estagioFull ?>],
                    backgroundColor: ['rgba(100,116,139,.25)', 'rgba(14,165,233,.7)', 'rgba(34,197,94,.75)'],
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 12 }, padding: 14 } }
                },
                cutout: '65%'
            }
        });
    }


})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- RANKING DE MÚLTIPLAS INSCRIÇÕES                                    -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<?php if ($rankingRows): ?>
<style>
.rk-table { width:100%; border-collapse:collapse; font-size:13px; }
.rk-table thead th {
    padding:9px 12px; text-align:left; font-size:10px; font-weight:700;
    text-transform:uppercase; letter-spacing:.08em; color:var(--muted);
    border-bottom:1px solid var(--border); white-space:nowrap;
    background:rgba(255,255,255,.025);
}
.rk-table tbody td { padding:10px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.rk-table .main-row { cursor:pointer; transition:background var(--t); }
.rk-table .main-row:hover td { background:var(--bg-hover); }
.rk-expand-icon { display:inline-block; font-size:11px; color:var(--muted); transition:transform .2s; }
.main-row.open .rk-expand-icon { transform:rotate(90deg); color:var(--primary); }

.rk-detail { display:none; padding:0; border-bottom:1px solid var(--border); }
.rk-detail.open { display:block; }
.rk-detail-inner {
    padding:14px 18px 16px;
    background:rgba(255,255,255,.018);
    border-top:1px solid var(--border);
}
.rk-detail-title {
    font-size:9.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.08em; color:var(--muted); margin-bottom:10px;
}
.rk-insc-grid { display:flex; flex-wrap:wrap; gap:10px; }
.rk-insc-card {
    background:var(--bg-card); border:1px solid var(--border);
    border-radius:var(--r-lg); padding:12px 14px; min-width:200px; flex:1;
    max-width:280px;
}
.rk-insc-date { font-size:13px; font-weight:700; color:var(--text); margin-bottom:6px; display:flex; align-items:center; gap:6px; }
.rk-insc-date span { font-size:11px; color:var(--muted); font-weight:400; }
.rk-insc-row { display:flex; justify-content:space-between; font-size:11.5px; margin-bottom:3px; gap:6px; }
.rk-insc-k { color:var(--muted); }
.rk-insc-v { color:var(--text); font-weight:500; text-align:right; word-break:break-all; }

.rank-badge {
    width:26px; height:26px; border-radius:var(--r-full);
    display:inline-flex; align-items:center; justify-content:center;
    font-size:11px; font-weight:700; flex-shrink:0;
}
.rank-1 { background:rgba(250,204,21,.18); color:var(--primary); border:1px solid rgba(250,204,21,.35); }
.rank-2 { background:rgba(148,163,184,.12); color:#94a3b8; border:1px solid rgba(148,163,184,.25); }
.rank-3 { background:rgba(180,90,40,.15); color:#fb923c; border:1px solid rgba(180,90,40,.3); }
.rank-n { background:rgba(100,116,139,.08); color:var(--muted); border:1px solid var(--border); }
.insc-count-badge {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:32px; height:24px; padding:0 8px;
    border-radius:var(--r-full); font-size:12px; font-weight:700;
}
</style>

<div class="panel" style="margin-top:0">
    <div class="panel-title" style="margin-bottom:14px">
        <span style="display:flex;align-items:center;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            Ranking de inscrições
        </span>
        <span style="font-size:11px;color:var(--muted);font-weight:400">
            Top <?= count($rankingRows) ?> alunos — clique para ver o histórico de cada inscrição
        </span>
    </div>

    <div style="overflow-x:auto">
    <table class="rk-table" style="min-width:700px">
        <thead>
            <tr>
                <th style="width:30px"></th>
                <th style="width:36px">#</th>
                <th>Nome / E-mail</th>
                <th>Telefone</th>
                <th style="text-align:center">Inscrições</th>
                <th>Primeiro cadastro</th>
                <th>Último cadastro</th>
                <th style="text-align:right">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php
        // funções fora do loop para não causar "Cannot redeclare"
        function rkDt(?string $d): string {
            if (!$d || trim($d) === '') return '—';
            try { return (new DateTime($d))->format('d/m/Y'); } catch (Throwable $e) { return $d; }
        }
        function rkDtHora(?string $d): string {
            if (!$d || trim($d) === '') return '—';
            try { return (new DateTime($d))->format('d/m/Y H:i'); } catch (Throwable $e) { return $d; }
        }
        foreach ($rankingRows as $ri => $rk):
            $uid   = (int)$rk['id'];
            $qtd   = (int)$rk['qtd_inscricoes'];
            $rankN = $ri + 1;
            if ($rankN === 1)      $rankCls = 'rank-1';
            elseif ($rankN === 2)  $rankCls = 'rank-2';
            elseif ($rankN === 3)  $rankCls = 'rank-3';
            else                   $rankCls = 'rank-n';
            if ($qtd >= 5)         $countColor = 'color:#f87171;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25)';
            elseif ($qtd >= 3)     $countColor = 'color:#fdba74;background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.25)';
            elseif ($qtd === 2)    $countColor = 'color:#fcd34d;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.2)';
            else                   $countColor = 'color:var(--muted);background:var(--bg-hover);border:1px solid var(--border)';
            $hist = $rankHistorico[$uid] ?? [];
        ?>
        <tr class="main-row" id="rkrow-<?= $ri ?>" onclick="rkToggle(<?= $ri ?>)">
            <td><span class="rk-expand-icon">▶</span></td>
            <td><span class="rank-badge <?= $rankCls ?>"><?= $rankN ?></span></td>
            <td>
                <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars((string)($rk['nome']??'-')) ?></div>
                <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars((string)($rk['email']??'-')) ?></div>
            </td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars(trim((string)($rk['telefone']??''))) ?: '—' ?></td>
            <td style="text-align:center">
                <span class="insc-count-badge" style="<?= $countColor ?>"><?= $qtd ?>x</span>
            </td>
            <td style="font-size:12px;color:var(--muted)"><?= rkDt((string)($rk['primeiro_cadastro']??'')) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= rkDt((string)($rk['ultimo_cadastro']??'')) ?></td>
            <td style="text-align:right" onclick="event.stopPropagation()">
                <a href="aluno_editar.php?id=<?= $uid ?>" class="btn btn-ghost btn-xs">Editar</a>
            </td>
        </tr>
        <tr>
            <td colspan="8" style="padding:0;border-bottom:none">
                <div class="rk-detail" id="rkdet-<?= $ri ?>">
                    <div class="rk-detail-inner">
                        <!-- Info fixa do aluno -->
                        <div style="display:flex;flex-wrap:wrap;gap:18px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border)">
                            <div>
                                <div class="rk-detail-title">Contato</div>
                                <div class="rk-insc-row"><span class="rk-insc-k">Nome</span>&nbsp;&nbsp;<span class="rk-insc-v"><?= htmlspecialchars((string)($rk['nome']??'')) ?></span></div>
                                <div class="rk-insc-row"><span class="rk-insc-k">E-mail</span>&nbsp;&nbsp;<span class="rk-insc-v"><?= htmlspecialchars((string)($rk['email']??'')) ?></span></div>
                                <div class="rk-insc-row"><span class="rk-insc-k">Telefone</span>&nbsp;&nbsp;<span class="rk-insc-v"><?= htmlspecialchars(trim((string)($rk['telefone']??''))) ?: '—' ?></span></div>
                            </div>
                            <?php
                            $hasAnyUtm = false;
                            foreach (['utm_source','utm_medium','utm_campaign','utm_content'] as $uk) {
                                if (trim((string)($rk[$uk]??'')) !== '') { $hasAnyUtm = true; break; }
                            }
                            if ($hasAnyUtm):
                            ?>
                            <div>
                                <div class="rk-detail-title">UTMs (última inscrição)</div>
                                <?php foreach (['utm_source'=>'Source','utm_medium'=>'Medium','utm_campaign'=>'Campaign','utm_content'=>'Content'] as $uk=>$ul):
                                    $uv = trim((string)($rk[$uk]??''));
                                    if ($uv==='') continue;
                                ?>
                                <div class="rk-insc-row"><span class="rk-insc-k"><?= htmlspecialchars($ul) ?></span>&nbsp;&nbsp;<span class="rk-insc-v"><?= htmlspecialchars($uv) ?></span></div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Histórico de inscrições -->
                        <div class="rk-detail-title">
                            Histórico de inscrições (<?= count($hist) ?> <?= count($hist)===1?'cadastro':'cadastros' ?>)
                        </div>
                        <?php if ($hist): ?>
                        <div class="rk-insc-grid">
                            <?php foreach ($hist as $hi => $h):
                                $payload = json_decode((string)($h['payload_raw']??'{}'), true) ?: [];
                                // tenta extrair dados do payload (estrutura pode variar)
                                $pa = $payload['aluno'] ?? $payload['user'] ?? $payload['data'] ?? [];
                                $pUtms = [
                                    'source'   => $pa['utm_source']   ?? $payload['utm_source']   ?? '',
                                    'medium'   => $pa['utm_medium']   ?? $payload['utm_medium']   ?? '',
                                    'campaign' => $pa['utm_campaign'] ?? $payload['utm_campaign'] ?? '',
                                    'content'  => $pa['utm_content']  ?? $payload['utm_content']  ?? '',
                                ];
                                $hasPayloadUtm = array_filter($pUtms, function($v) { return $v !== ''; });
                            ?>
                            <div class="rk-insc-card">
                                <div class="rk-insc-date">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?= rkDt((string)($h['data_dia']??'')) ?>
                                    <span><?= htmlspecialchars(substr((string)($h['hora']??''),11,5)) ?></span>
                                    <span style="margin-left:4px;font-size:10px;background:var(--primary-dim);color:var(--primary);padding:1px 6px;border-radius:999px">#<?= $hi+1 ?></span>
                                </div>
                                <?php if ($hasPayloadUtm): ?>
                                    <?php foreach (['source'=>'Source','medium'=>'Medium','campaign'=>'Campaign','content'=>'Content'] as $pk=>$pl):
                                        $pv = trim((string)($pUtms[$pk]??''));
                                        if ($pv==='') continue;
                                    ?>
                                    <div class="rk-insc-row">
                                        <span class="rk-insc-k">UTM <?= htmlspecialchars($pl) ?></span>
                                        <span class="rk-insc-v"><?= htmlspecialchars($pv) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="font-size:11px;color:var(--dim)">UTMs não registrados no payload</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="font-size:12px;color:var(--dim)">Sem histórico detalhado disponível.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if (!$hasWHL): ?>
    <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">
        Tabela <code class="code">webhook_logs</code> não encontrada — o ranking requer que webhooks estejam ativos.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function rkToggle(i) {
    var row = document.getElementById('rkrow-' + i);
    var det = document.getElementById('rkdet-' + i);
    if (!det) return;
    var open = det.classList.contains('open');
    det.classList.toggle('open', !open);
    row.classList.toggle('open', !open);
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
