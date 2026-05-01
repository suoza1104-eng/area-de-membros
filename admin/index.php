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
                background: #07101f;
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
                background: #0d1b33;
                border: 1px solid #1a2540;
                border-radius: 18px;
                padding: 36px 32px;
                box-shadow: 0 20px 60px rgba(0,0,0,.5);
            }
            .login-logo {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 48px; height: 48px;
                background: rgba(250,204,21,.1);
                border-radius: 12px;
                margin: 0 auto 20px;
                color: #facc15;
            }
            .login-logo svg { width: 22px; height: 22px; }
            h2 {
                font-size: 20px; font-weight: 700;
                text-align: center; margin-bottom: 6px;
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
                border: 1px solid #1e3050;
                background: #07101f;
                color: #e2e8f0;
                font-size: 14px;
                font-family: inherit;
                outline: none;
                transition: border-color .15s, box-shadow .15s;
                margin-bottom: 14px;
            }
            input:focus {
                border-color: #facc15;
                box-shadow: 0 0 0 3px rgba(250,204,21,.12);
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
                background: rgba(239,68,68,.12);
                border: 1px solid rgba(239,68,68,.3);
                color: #fca5a5;
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

?>
<?php
$menu = 'dashboard';
include __DIR__ . '/_header.php';
?>

<!-- Page header row -->
<div class="d-flex align-center justify-between mb-4">
    <div>
        <div style="font-size:20px;font-weight:700;color:var(--text)">Dashboard</div>
        <div style="font-size:12px;color:var(--muted);margin-top:2px">Visão geral da plataforma</div>
    </div>
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

<!-- CHART ROW 2: Funil de aulas -->
<?php if ($funil): ?>
<div class="panel mb-4">
    <div class="panel-title">
        Funil de conclusão por aula
    </div>
    <canvas id="chartFunil" style="max-height:240px"></canvas>
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
                    backgroundColor: ['rgba(100,116,139,.5)', 'rgba(56,189,248,.8)', 'rgba(34,197,94,.8)'],
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

    // Funil de aulas
    var labelsFunil = <?= json_encode($labelsFunil, JSON_UNESCAPED_UNICODE) ?>;
    var dataFunil   = <?= json_encode($dataFunil) ?>;
    var cFunil = document.getElementById('chartFunil');
    if (cFunil && labelsFunil.length) {
        new Chart(cFunil, {
            type: 'bar',
            data: {
                labels: labelsFunil,
                datasets: [{
                    data: dataFunil,
                    backgroundColor: function(ctx) {
                        var gradient = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
                        gradient.addColorStop(0, 'rgba(250,204,21,.9)');
                        gradient.addColorStop(1, 'rgba(250,204,21,.35)');
                        return gradient;
                    },
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { font: { size: 11 } }, grid: { display: false } },
                    y: { ticks: { font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' }, beginAtZero: true }
                }
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
