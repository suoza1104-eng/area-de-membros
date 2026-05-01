<?php
declare(strict_types=1);

// carrega config e inicia sessão
require_once __DIR__ . '/../app/config.php';

$pdo = getPDO();

// ========================
// 1) LOGIN DO ADMIN
// ========================
if (empty($_SESSION['admin_logado']) || $_SESSION['admin_logado'] !== true) {
    $erro = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = trim($_POST['usuario'] ?? '');
        $pass = trim($_POST['senha'] ?? '');

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
        <style>
            *{box-sizing:border-box;margin:0;padding:0;}
            body{
                font-family:Arial, sans-serif;
                background:#020617;
                color:#e5e7eb;
                display:flex;
                align-items:center;
                justify-content:center;
                min-height:100vh;
            }
            .box{
                background:#020617;
                padding:24px;
                border-radius:14px;
                width:100%;
                max-width:360px;
                border:1px solid #1f2937;
                box-shadow:0 20px 40px rgba(0,0,0,.5);
            }
            h2{
                margin-bottom:16px;
                font-size:20px;
                text-align:center;
            }
            label{
                display:block;
                margin-bottom:4px;
                font-size:13px;
            }
            input{
                width:100%;
                padding:8px 10px;
                border-radius:8px;
                border:1px solid #4b5563;
                background:#020617;
                color:#e5e7eb;
                margin-bottom:10px;
            }
            button{
                width:100%;
                padding:9px;
                border-radius:999px;
                border:none;
                background:#facc15;
                color:#111827;
                font-weight:bold;
                cursor:pointer;
                margin-top:6px;
            }
            button:hover{opacity:.9;}
            .erro{
                color:#f97316;
                font-size:13px;
                margin-bottom:8px;
            }
        </style>
    </head>
    <body>
    <div class="box">
        <h2>Login Admin</h2>
        <?php if ($erro): ?>
            <div class="erro"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="usuario">Usuário</label>
            <input type="text" id="usuario" name="usuario" required>

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
// 2) FILTROS (datas / turma)
// ========================
$dataDe  = trim($_GET['data_de']  ?? '');
$dataAte = trim($_GET['data_ate'] ?? '');
$turmaId = (int)($_GET['turma_id'] ?? 0);

// condição para usuários (aplicada em tudo)
$whereUsers  = [];
$paramsUsers = [];

if ($dataDe !== '') {
    $whereUsers[]          = 'u.created_at >= :data_de';
    $paramsUsers['data_de'] = $dataDe . ' 00:00:00';
}
if ($dataAte !== '') {
    $whereUsers[]          = 'u.created_at <= :data_ate';
    $paramsUsers['data_ate'] = $dataAte . ' 23:59:59';
}
if ($turmaId > 0) {
    $whereUsers[]             = 'u.turma_id = :turma_id';
    $paramsUsers['turma_id']  = $turmaId;
}

$whereUsersSql = $whereUsers ? ('WHERE ' . implode(' AND ', $whereUsers)) : '';

// carrega turmas para o select
$turmas = [];
try {
    $turmas = $pdo->query("SELECT id, codigo, nome FROM turmas ORDER BY codigo ASC")->fetchAll();
} catch (Throwable $e) {
    // se não existir tabela turmas, só ignora
    $turmas = [];
}

// ========================
// 3) DADOS DO DASHBOARD
// ========================

// Total de alunos (com filtro)
$sqlTotalAlunos = "SELECT COUNT(*) FROM users u $whereUsersSql";
$stmt = $pdo->prepare($sqlTotalAlunos);
$stmt->execute($paramsUsers);
$totalAlunos = (int)$stmt->fetchColumn();

// Certificados emitidos (com filtro por usuário)
$sqlTotalCert = "
    SELECT COUNT(*)
    FROM certificates c
    JOIN users u ON u.id = c.user_id
    $whereUsersSql
";
$stmt = $pdo->prepare($sqlTotalCert);
$stmt->execute($paramsUsers);
$totalCert = (int)$stmt->fetchColumn();

// Funil por aula (considerando apenas alunos filtrados)
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

// Inscrições (por dia) considerando filtros
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

// Distribuição de estágios
// total de aulas que contam para conclusão (sem filtro de usuário)
$totalAulasConta = (int)$pdo->query("
    SELECT COUNT(*) FROM lessons WHERE conta_para_conclusao = 1 AND ativo = 1
")->fetchColumn();

// alunos que concluíram pelo menos 1 aula (com filtro)
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

// alunos que concluíram todas as aulas que contam (com filtro)
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

// Dados para gráfico de funil
$labelsFunil = [];
$dataFunil   = [];
foreach ($funil as $f) {
    $labelsFunil[] = 'Aula ' . $f['ordem'];
    $dataFunil[]   = (int)$f['concluintes'];
}

?>
<?php
$menu = 'dashboard';
include __DIR__ . '/_header.php';
?>
<style>
:root{
            --bg-main:#020617;
            --bg-card:#020617;
            --bg-sidebar:#020617;
            --border:#1f2937;
            --primary:#facc15;
            --primary-soft:rgba(250,204,21,.15);
            --text-main:#e5e7eb;
            --text-muted:#9ca3af;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{
            font-family:Arial, sans-serif;
            background:var(--bg-main);
            color:var(--text-main);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit;}
        .layout{
            display:flex;
            min-height:100vh;
        }
        .sidebar{
            width:220px;
            background:var(--bg-sidebar);
            border-right:1px solid var(--border);
            padding:20px 16px;
        }
        .logo{
            font-weight:bold;
            font-size:18px;
            margin-bottom:12px;
        }
        .logo span{
            color:var(--primary);
        }
        .menu-title{
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:var(--text-muted);
            margin:18px 0 6px;
        }
        .menu a{
            display:block;
            padding:8px 10px;
            border-radius:8px;
            font-size:14px;
            color:var(--text-muted);
        }
        .menu a:hover{
            background:var(--primary-soft);
            color:var(--primary);
        }
        .menu a.active{
            background:var(--primary);
            color:#111827;
            font-weight:bold;
        }
        .main{
            flex:1;
            padding:20px 24px 40px;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin-bottom:12px;
        }
        .topbar-title{
            font-size:20px;
            font-weight:bold;
        }
        .topbar-right{
            font-size:13px;
            color:var(--text-muted);
        }
        .logout-link{
            color:var(--primary);
            font-size:13px;
        }
        .filters{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            align-items:flex-end;
            margin-bottom:18px;
        }
        .filter-group{
            display:flex;
            flex-direction:column;
            gap:4px;
            min-width:140px;
        }
        .filter-group label{
            font-size:12px;
            color:var(--text-muted);
        }
        .filter-group input,
        .filter-group select{
            padding:6px 8px;
            border-radius:8px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text-main);
            font-size:13px;
        }
        .filters button{
            padding:8px 14px;
            border-radius:999px;
            border:none;
            background:var(--primary);
            color:#111827;
            font-weight:bold;
            font-size:13px;
            cursor:pointer;
        }
        .filters a.reset{
            font-size:12px;
            color:var(--text-muted);
            text-decoration:underline;
        }
        .cards-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:16px;
            margin-bottom:18px;
        }
        .card{
            background:var(--bg-card);
            border-radius:14px;
            padding:14px 16px;
            border:1px solid var(--border);
        }
        .card-label{
            font-size:13px;
            color:var(--text-muted);
            margin-bottom:4px;
        }
        .card-value{
            font-size:26px;
            font-weight:bold;
        }
        .panel{
            background:var(--bg-card);
            border-radius:14px;
            border:1px solid var(--border);
            padding:16px;
            margin-bottom:18px;
        }
        .panel-title{
            font-size:14px;
            margin-bottom:8px;
        }
        .grid-2{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
            gap:16px;
            margin-bottom:18px;
        }
        .table-wrapper{
            overflow-x:auto;
        }
        table{
            width:100%;
            border-collapse:collapse;
            font-size:13px;
        }
        th,td{
            padding:8px 6px;
            border-bottom:1px solid var(--border);
        }
        th{
            text-align:left;
            color:var(--text-muted);
            font-weight:600;
        }
        tr:hover td{
            background:#020818;
        }
        .panel canvas{
            width:100% !important;
            height:100% !important;
            max-height:260px;
        }
        @media (max-width:768px){
            .layout{
                flex-direction:column;
            }
            .sidebar{
                width:100%;
                border-right:none;
                border-bottom:1px solid var(--border);
                display:flex;
                align-items:center;
                justify-content:space-between;
            }
            .menu{
                display:flex;
                gap:6px;
            }
            .menu a{
                font-size:12px;
                padding:6px 8px;
            }
        }
</style>
<div class="topbar">
            <div class="topbar-title">Dashboard</div>
            <div class="topbar-right">
                Admin logado ·
                <a class="logout-link" href="<?= htmlspecialchars(BASE_URL_ADMIN . '/index.php?logout=1') ?>">Sair</a>
            </div>
        </div>

        <!-- FILTROS -->
        <form method="get" class="filters">
            <div class="filter-group">
                <label for="data_de">Data inicial (inscrição)</label>
                <input type="date" id="data_de" name="data_de" value="<?= htmlspecialchars($dataDe) ?>">
            </div>
            <div class="filter-group">
                <label for="data_ate">Data final (inscrição)</label>
                <input type="date" id="data_ate" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>">
            </div>
            <div class="filter-group">
                <label for="turma_id">Turma</label>
                <select id="turma_id" name="turma_id">
                    <option value="0">Todas</option>
                    <?php foreach ($turmas as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $turmaId === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['codigo'] . (empty($t['nome']) ? '' : ' - ' . $t['nome'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" style="min-width:auto;">
                <button type="submit">Aplicar filtros</button>
                <a href="<?= htmlspecialchars(BASE_URL_ADMIN . '/index.php') ?>" class="reset">Limpar filtros</a>
            </div>
        </form>

        <div class="cards-grid">
            <div class="card">
                <div class="card-label">Total de alunos (filtrados)</div>
                <div class="card-value"><?= $totalAlunos ?></div>
            </div>
            <div class="card">
                <div class="card-label">Certificados emitidos</div>
                <div class="card-value"><?= $totalCert ?></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Funil de aulas (alunos que concluíram cada etapa)</div>
            <canvas id="chartFunil"></canvas>
        </div>

        <div class="grid-2">
            <div class="panel">
                <div class="panel-title">Inscrições - por dia</div>
                <canvas id="chartDia"></canvas>
            </div>
            <div class="panel">
                <div class="panel-title">Distribuição de estágios</div>
                <canvas id="chartStage"></canvas>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Tabela detalhada</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>ORDEM</th>
                        <th>AULA</th>
                        <th>ALUNOS QUE CONCLUÍRAM</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($funil as $f): ?>
                        <tr>
                            <td><?= (int)$f['ordem'] ?></td>
                            <td><?= htmlspecialchars($f['titulo']) ?></td>
                            <td><?= (int)$f['concluintes'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-chart-funnel@4"></script>
<script>
(function() {
    function debug(msg) {
        console.log('[Dashboard]', msg);
    }

    if (typeof Chart === 'undefined') {
        debug('Chart.js não carregou.');
        return;
    }

    // ===== FUNIL (gráfico de funil mesmo) =====
    const labelsFunil = <?= json_encode($labelsFunil, JSON_UNESCAPED_UNICODE) ?>;
    const dataFunil   = <?= json_encode($dataFunil) ?>;

    const canvasFunil = document.getElementById('chartFunil');
    if (canvasFunil && labelsFunil.length) {
        const ctxF = canvasFunil.getContext('2d');
        new Chart(ctxF, {
            type: 'funnel',
            data: {
                labels: labelsFunil,
                datasets: [{
                    data: dataFunil,
                    backgroundColor: 'rgba(250,204,21,0.85)',
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display:false }
                }
            }
        });
    }

    // ===== INSCRIÇÕES DIA A DIA =====
    const labelsDia = <?= json_encode($labelsDia, JSON_UNESCAPED_UNICODE) ?>;
    const dataDia   = <?= json_encode($dataDia) ?>;

    const canvasDia = document.getElementById('chartDia');
    if (canvasDia && labelsDia.length) {
        const ctxD = canvasDia.getContext('2d');
        new Chart(ctxD, {
            type: 'line',
            data: {
                labels: labelsDia,
                datasets: [{
                    label: 'Inscrições',
                    data: dataDia,
                    tension: 0.35,
                    borderWidth: 2,
                    borderColor: '#38bdf8',
                    backgroundColor: 'rgba(56,189,248,0.18)',
                    pointRadius: 3,
                    pointBackgroundColor: '#38bdf8'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: '#e5e7eb' },
                        grid: { color: 'rgba(31,41,55,0.7)' }
                    },
                    y: {
                        ticks: { color: '#e5e7eb' },
                        grid: { color: 'rgba(31,41,55,0.7)' }
                    }
                }
            }
        });
    }

    // ===== ESTÁGIOS (pizza) =====
    const canvasStage = document.getElementById('chartStage');
    if (canvasStage) {
        const ctxS = canvasStage.getContext('2d');
        new Chart(ctxS, {
            type: 'doughnut',
            data: {
                labels: ['Só inscritos', 'Viram alguma aula', 'Concluíram tudo'],
                datasets: [{
                    data: [<?= $onlyInscritos ?>, <?= $estagioUma ?>, <?= $estagioFull ?>],
                    backgroundColor: [
                        'rgba(148,163,184,0.7)',
                        'rgba(56,189,248,0.8)',
                        'rgba(34,197,94,0.8)'
                    ],
                    borderColor: '#020617',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#e5e7eb' }
                    }
                }
            }
        });
    }
})();
</script>

<?php
// logout simples
if (isset($_GET['logout'])) {
    $_SESSION['admin_logado'] = false;
    session_destroy();
    header('Location: ' . BASE_URL_ADMIN . '/index.php');
    exit;
}
?>

<?php include __DIR__ . '/_footer.php'; ?>
