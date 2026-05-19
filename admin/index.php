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
            $_SESSION['admin_tipo']   = 'principal';
            $_SESSION['equipe_id']    = null;
            $_SESSION['equipe_nome']  = 'Administrador';
            $_SESSION['equipe_email'] = $user;
            $_SESSION['equipe_perms'] = null;
            header('Location: ' . BASE_URL_ADMIN . '/index.php');
            exit;
        }

        // Verificar membros da equipe (email = usuário)
        try {
            $pdo_l = getPDO();
            $st = $pdo_l->prepare("SELECT * FROM admin_equipe WHERE email = :e AND ativo = 1 LIMIT 1");
            $st->execute([':e' => $user]);
            $membro = $st->fetch(PDO::FETCH_ASSOC);
            if ($membro && password_verify($pass, (string)($membro['senha_hash'] ?? ''))) {
                $_SESSION['admin_logado'] = true;
                $_SESSION['admin_tipo']   = 'equipe';
                $_SESSION['equipe_id']    = (int)$membro['id'];
                $_SESSION['equipe_nome']  = $membro['nome'];
                $_SESSION['equipe_email'] = $membro['email'];
                $_SESSION['equipe_perms'] = $membro['permissoes'];
                header('Location: ' . BASE_URL_ADMIN . '/index.php');
                exit;
            }
        } catch (Throwable $e) {}

        $erro = 'Usuário ou senha inválidos.';
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
// Default: últimos 30 dias (só aplica quando não vier filtro algum, evita prender ao clicar "Limpar")
$temFiltroNaUrl = isset($_GET['data_de']) || isset($_GET['data_ate']) || isset($_GET['turma_id']);
$dataDe  = trim($_GET['data_de']  ?? '');
$dataAte = trim($_GET['data_ate'] ?? '');
$turmaId = (int)($_GET['turma_id'] ?? 0);
if (!$temFiltroNaUrl) {
    $dataDe  = date('Y-m-d', strtotime('-30 days'));
    $dataAte = date('Y-m-d');
}

// Detecta coluna de turma no users (varia entre instâncias)
$turmaColTop = null;
try {
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'codigo_turma'")->fetch()) $turmaColTop = 'codigo_turma';
    elseif ($pdo->query("SHOW COLUMNS FROM users LIKE 'turma_id'")->fetch()) $turmaColTop = 'turma_id';
} catch (Throwable $e) {}

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
// Filtro por turma — se a coluna é codigo_turma, faz lookup do codigo
if ($turmaId > 0 && $turmaColTop) {
    $turmaCodigoSel = '';
    if ($turmaColTop === 'codigo_turma') {
        try {
            $stT = $pdo->prepare("SELECT codigo FROM turmas WHERE id = :id LIMIT 1");
            $stT->execute([':id' => $turmaId]);
            $turmaCodigoSel = (string)($stT->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        if ($turmaCodigoSel !== '') {
            $whereUsers[]               = "u.`codigo_turma` = :turma_codigo";
            $paramsUsers['turma_codigo'] = $turmaCodigoSel;
        }
    } else {
        $whereUsers[]            = "u.`$turmaColTop` = :turma_id";
        $paramsUsers['turma_id'] = $turmaId;
    }
}

$whereUsersSql = $whereUsers ? ('WHERE ' . implode(' AND ', $whereUsers)) : '';

$turmas = [];
try {
    // Tenta com nome (instâncias antigas)
    $turmas = $pdo->query("SELECT id, codigo, nome FROM turmas ORDER BY codigo DESC")->fetchAll();
} catch (Throwable $e) {
    // Fallback sem nome
    try {
        $turmas = $pdo->query("SELECT id, codigo, '' AS nome FROM turmas ORDER BY codigo DESC")->fetchAll();
    } catch (Throwable $e2) { $turmas = []; }
}

// Codigo da turma selecionada (para filtros em inscricao_logs.codigo_turma)
$codigoTurmaSel = '';
if ($turmaId > 0) {
    foreach ($turmas as $t) {
        if ((int)$t['id'] === $turmaId) { $codigoTurmaSel = (string)$t['codigo']; break; }
    }
}

// ── Métricas de inscrições x reinscrições (respeita filtros) ──
$novosInsc      = 0;
$reinscritos    = 0;
$totalInscEvts  = 0;
$uniqUsersInsc  = 0;
$freqMedia      = 0.0;
try {
    $whIL  = [];
    $prIL  = [];
    if ($dataDe  !== '') { $whIL[] = 'il.created_at >= :il_de';  $prIL['il_de']  = $dataDe . ' 00:00:00'; }
    if ($dataAte !== '') { $whIL[] = 'il.created_at <= :il_ate'; $prIL['il_ate'] = $dataAte . ' 23:59:59'; }
    if ($codigoTurmaSel !== '') { $whIL[] = 'il.codigo_turma = :il_ct'; $prIL['il_ct'] = $codigoTurmaSel; }
    $whIlSql = $whIL ? ('WHERE ' . implode(' AND ', $whIL)) : '';

    $stIL = $pdo->prepare("
        SELECT
            SUM(CASE WHEN il.is_novo = 1 THEN 1 ELSE 0 END) AS novos,
            SUM(CASE WHEN il.is_novo = 0 THEN 1 ELSE 0 END) AS reins,
            COUNT(*) AS total_evts,
            COUNT(DISTINCT il.user_id) AS uniq_users
        FROM inscricao_logs il
        $whIlSql
    ");
    $stIL->execute($prIL);
    $rowIL = $stIL->fetch(PDO::FETCH_ASSOC) ?: [];
    $novosInsc     = (int)($rowIL['novos']      ?? 0);
    $reinscritos   = (int)($rowIL['reins']      ?? 0);
    $totalInscEvts = (int)($rowIL['total_evts'] ?? 0);
    $uniqUsersInsc = (int)($rowIL['uniq_users'] ?? 0);
    $freqMedia     = $uniqUsersInsc > 0 ? round($totalInscEvts / $uniqUsersInsc, 2) : 0.0;
} catch (Throwable $e) { /* tabela inexistente: deixa zero */ }
$pctReinsc = $totalInscEvts > 0 ? round($reinscritos / $totalInscEvts * 100, 1) : 0;

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

// Alunos que pelo menos logaram (last_login_at IS NOT NULL)
$alunosLogaram = 0;
try {
    $sqlLog = "SELECT COUNT(*) FROM users u WHERE u.last_login_at IS NOT NULL";
    if ($whereUsers) $sqlLog .= ' AND ' . implode(' AND ', $whereUsers);
    $stmt = $pdo->prepare($sqlLog);
    $stmt->execute($paramsUsers);
    $alunosLogaram = (int)$stmt->fetchColumn();
} catch (Throwable $e) { /* coluna pode não existir em instâncias antigas */ }
$pctLogaram = $totalAlunos > 0 ? round($alunosLogaram / $totalAlunos * 100, 1) : 0;

// ── Métricas de LIVE (acessou / oferta / compra) ──
// Conta usuários únicos com recebimentos processados em live_events do tipo
function dash_count_live(PDO $pdo, string $tipo, array $whereUsers, array $paramsUsers): int {
    try {
        $sql = "SELECT COUNT(DISTINCT ler.user_id)
                FROM live_event_recebimentos ler
                JOIN live_events le ON le.id = ler.event_id
                JOIN users u ON u.id = ler.user_id
                WHERE ler.status = 'processado' AND ler.user_id IS NOT NULL AND le.tipo = :tipo";
        if ($whereUsers) $sql .= ' AND ' . implode(' AND ', $whereUsers);
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([':tipo' => $tipo], $paramsUsers));
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
$liveAcessou = dash_count_live($pdo, 'acessou', $whereUsers, $paramsUsers);
$liveOferta  = dash_count_live($pdo, 'oferta',  $whereUsers, $paramsUsers);
$liveCompra  = dash_count_live($pdo, 'compra',  $whereUsers, $paramsUsers);

// ── Dados para o gráfico comparativo POR TURMA ──
// Detecta qual coluna em users referencia a turma
$turmaCol = null;
try {
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'codigo_turma'")->fetch()) $turmaCol = 'codigo_turma';
    elseif ($pdo->query("SHOW COLUMNS FROM users LIKE 'turma_id'")->fetch()) $turmaCol = 'turma_id';
} catch (Throwable $e) {}

$barTurmaData = [];
if ($turmaCol) {
    $whereSemTurma = array_values(array_filter($whereUsers, function($w) {
        return strpos($w, 'turma_id') === false && strpos($w, 'codigo_turma') === false;
    }));
    $paramsSemTurma = $paramsUsers;
    unset($paramsSemTurma['turma_id'], $paramsSemTurma['turma_codigo']);
    $whereSemTurmaSql = $whereSemTurma ? (' WHERE ' . implode(' AND ', $whereSemTurma)) : '';
    $extraWhere      = $whereSemTurma ? (' AND ' . implode(' AND ', $whereSemTurma)) : '';

    // Inscritos por turma
    try {
        $sqlT = "SELECT u.`$turmaCol` AS tid, COUNT(*) AS n FROM users u $whereSemTurmaSql GROUP BY u.`$turmaCol`";
        $st = $pdo->prepare($sqlT); $st->execute($paramsSemTurma);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
            $barTurmaData[$tid]['inscritos'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}

    // Logaram
    try {
        $sqlL = "SELECT u.`$turmaCol` AS tid, COUNT(*) AS n FROM users u WHERE u.last_login_at IS NOT NULL$extraWhere GROUP BY u.`$turmaCol`";
        $st = $pdo->prepare($sqlL); $st->execute($paramsSemTurma);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
            $barTurmaData[$tid]['logaram'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}

    // Viram alguma aula
    try {
        $sqlA = "SELECT u.`$turmaCol` AS tid, COUNT(DISTINCT u.id) AS n
                 FROM users u JOIN lesson_progress lp ON lp.user_id = u.id
                 WHERE lp.status = 'completed'$extraWhere GROUP BY u.`$turmaCol`";
        $st = $pdo->prepare($sqlA); $st->execute($paramsSemTurma);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
            $barTurmaData[$tid]['aula'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}

    // LIVE: acessou / oferta / compra
    foreach (['acessou','oferta','compra'] as $tp) {
        try {
            $sqlV = "SELECT u.`$turmaCol` AS tid, COUNT(DISTINCT ler.user_id) AS n
                     FROM live_event_recebimentos ler
                     JOIN live_events le ON le.id = ler.event_id
                     JOIN users u ON u.id = ler.user_id
                     WHERE ler.status='processado' AND ler.user_id IS NOT NULL AND le.tipo = :tipo$extraWhere
                     GROUP BY u.`$turmaCol`";
            $st = $pdo->prepare($sqlV); $st->execute(array_merge([':tipo' => $tp], $paramsSemTurma));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
                $barTurmaData[$tid]['live_'.$tp] = (int)$r['n'];
            }
        } catch (Throwable $e) {}
    }

    // Certificados
    try {
        $sqlC = "SELECT u.`$turmaCol` AS tid, COUNT(*) AS n
                 FROM certificates c JOIN users u ON u.id = c.user_id $whereSemTurmaSql GROUP BY u.`$turmaCol`";
        $st = $pdo->prepare($sqlC); $st->execute($paramsSemTurma);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
            $barTurmaData[$tid]['cert'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}
}

// Mapa de label das turmas — chaves: id (int) E codigo (string)
$turmasLabel = [];
foreach ($turmas as $t) {
    $label = (string)($t['codigo'] ?? $t['nome'] ?? ('Turma ' . $t['id']));
    $turmasLabel[(string)$t['id']]     = $label;
    if (!empty($t['codigo'])) $turmasLabel[(string)$t['codigo']] = $label;
}
$turmasLabel[''] = 'Sem turma';

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
$funnelData[] = ['label' => 'Total Inscritos',        'count' => $totalAlunos];
$funnelData[] = ['label' => 'Logaram na plataforma',  'count' => $alunosLogaram];
$funnelData[] = ['label' => 'Assistiram alguma aula', 'count' => $alunosAlguma];
foreach ($funil as $f) {
    $funnelData[] = [
        'label' => 'Aula ' . $f['ordem'] . ' — ' . mb_strimwidth($f['titulo'], 0, 30, '…'),
        'count' => (int)$f['concluintes'],
    ];
}
if ($liveAcessou > 0) $funnelData[] = ['label' => 'Acessaram a live',     'count' => $liveAcessou];
$funnelData[] = ['label' => 'Certificado emitido', 'count' => $totalCert];
if ($liveCompra  > 0) $funnelData[] = ['label' => 'Clicou na oferta',     'count' => $liveCompra];

$funnelMax = max(1, (int)($funnelData[0]['count'] ?? 1));
foreach ($funnelData as $fi => &$fstep) {
    $fstep['pct_bar']   = max(6, (int)round(($fstep['count'] / $funnelMax) * 100));
    $fstep['pct_total'] = round(($fstep['count'] / $funnelMax) * 100, 1);
    $prev = $fi > 0 ? (int)$funnelData[$fi - 1]['count'] : null;
    $fstep['drop'] = ($prev !== null && $prev > 0)
        ? round((($prev - $fstep['count']) / $prev) * 100, 1)
        : null;
}
unset($fstep);

// ========================
// 6) RANKING MÚLTIPLAS INSCRIÇÕES
// ========================
$rankingRows   = [];
$rankHistorico = [];
$hasIL         = false;
$hasWHL        = false;
$hasUtmCols    = false;
try { $pdo->query("SELECT utm_source FROM users LIMIT 0"); $hasUtmCols = true; } catch (Throwable $e) {}
try { $pdo->query("SELECT 1 FROM webhook_logs LIMIT 0"); $hasWHL = true; } catch (Throwable $e) {}

// Garante tabela inscricao_logs; na primeira vez, migra dados históricos do webhook_logs
try {
    try {
        $pdo->query("SELECT 1 FROM inscricao_logs LIMIT 0");
        $hasIL = true;
    } catch (Throwable $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inscricao_logs (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NOT NULL,
                codigo_turma VARCHAR(100) NULL,
                utm_source   VARCHAR(255) NULL,
                utm_medium   VARCHAR(255) NULL,
                utm_campaign VARCHAR(255) NULL,
                utm_term     VARCHAR(255) NULL,
                utm_content  VARCHAR(255) NULL,
                is_novo      TINYINT(1)   NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT NOW(),
                INDEX idx_il_user (user_id),
                INDEX idx_il_date (created_at)
            )
        ");
        if ($hasWHL) {
            // Migração única: importa um registro por inscrição distinta (user + dia)
            $pdo->exec("
                INSERT INTO inscricao_logs (user_id, is_novo, created_at)
                SELECT user_id, 1, MIN(created_at)
                FROM webhook_logs
                WHERE evento = 'INSCRITO'
                GROUP BY user_id, DATE(created_at)
            ");
        }
        $hasIL = true;
    }
} catch (Throwable $e) { /* ignorado */ }

if ($hasIL) {
    try {
        $utmSel = $hasUtmCols
            ? "MAX(u.utm_source) AS utm_source, MAX(u.utm_medium) AS utm_medium, MAX(u.utm_campaign) AS utm_campaign, MAX(u.utm_content) AS utm_content,"
            : "NULL AS utm_source, NULL AS utm_medium, NULL AS utm_campaign, NULL AS utm_content,";

        $rankSql = "
            SELECT
                COALESCE(NULLIF(TRIM(u.email),''), NULLIF(TRIM(u.telefone),''), CAST(u.id AS CHAR)) AS chave,
                GROUP_CONCAT(DISTINCT u.id ORDER BY u.id SEPARATOR ',') AS user_ids_str,
                MIN(u.id)       AS id,
                MIN(u.nome)     AS nome,
                MIN(u.email)    AS email,
                MIN(u.telefone) AS telefone,
                $utmSel
                COUNT(il.id) AS qtd_inscricoes,
                MIN(il.created_at) AS primeiro_cadastro,
                MAX(il.created_at) AS ultimo_cadastro
            FROM users u
            JOIN inscricao_logs il ON il.user_id = u.id
            GROUP BY chave
            ORDER BY qtd_inscricoes DESC, ultimo_cadastro DESC
            LIMIT 30
        ";
        $rankingRows = $pdo->query($rankSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rankingRows) {
            $allRkUids = [];
            foreach ($rankingRows as $row) {
                foreach (explode(',', (string)($row['user_ids_str'] ?? '')) as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) $allRkUids[] = $uid;
                }
            }
            $in = implode(',', array_unique($allRkUids));

            $histSql = "
                SELECT
                    COALESCE(NULLIF(TRIM(u.email),''), NULLIF(TRIM(u.telefone),''), CAST(u.id AS CHAR)) AS chave,
                    DATE(il.created_at) AS data_dia,
                    il.created_at       AS hora,
                    il.utm_source,
                    il.utm_medium,
                    il.utm_campaign,
                    il.utm_content,
                    il.codigo_turma,
                    il.is_novo
                FROM inscricao_logs il
                JOIN users u ON u.id = il.user_id
                WHERE il.user_id IN ($in)
                ORDER BY chave ASC, il.created_at ASC
            ";
            foreach ($pdo->query($histSql)->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $rankHistorico[$h['chave']][] = $h;
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

<?php if (!empty($_GET['sem_acesso'])): ?>
<div class="alert alert-error" style="margin-bottom:18px">
    🚫 Você não tem permissão para acessar essa página.
</div>
<?php endif; ?>

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

    <div class="kpi" style="border-color:rgba(20,184,166,.3)">
        <div class="kpi-icon" style="background:rgba(20,184,166,.15);color:#14b8a6">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        </div>
        <div class="kpi-label">Logaram</div>
        <div class="kpi-value"><?= number_format($alunosLogaram) ?></div>
        <div class="kpi-sub"><?= $pctLogaram ?>% acessaram a plataforma</div>
    </div>

    <div class="kpi kpi-b">
        <div class="kpi-icon b">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="kpi-label">Viram aula</div>
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

    <div class="kpi" style="border-color:rgba(168,85,247,.3)">
        <div class="kpi-icon" style="background:rgba(168,85,247,.15);color:#a855f7">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
        </div>
        <div class="kpi-label">Frequência média</div>
        <div class="kpi-value"><?= number_format($freqMedia, 2, ',', '.') ?>x</div>
        <div class="kpi-sub">inscrições por aluno</div>
    </div>
</div>

<!-- CHARTS: Novos vs Reinscritos -->
<div class="grid-2 mb-4">
    <div class="panel">
        <div class="panel-title">Novos vs Reinscritos</div>
        <?php if ($totalInscEvts > 0): ?>
            <canvas id="chartReinsc" style="max-height:220px"></canvas>
            <div style="display:flex;gap:18px;justify-content:center;margin-top:14px;font-size:12px">
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#38bdf8;display:inline-block"></span>
                    <span><strong><?= number_format($novosInsc) ?></strong> novos (<?= 100 - $pctReinsc ?>%)</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#a855f7;display:inline-block"></span>
                    <span><strong><?= number_format($reinscritos) ?></strong> reinscritos (<?= $pctReinsc ?>%)</span>
                </div>
            </div>
        <?php else: ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:60px 0">Nenhuma inscrição no período</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-title">Resumo de inscrições</div>
        <div style="display:flex;flex-direction:column;gap:14px;padding:8px 4px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Total de eventos de inscrição</span>
                <strong style="font-size:20px"><?= number_format($totalInscEvts) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Alunos únicos que se inscreveram</span>
                <strong style="font-size:20px"><?= number_format($uniqUsersInsc) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Frequência média</span>
                <strong style="font-size:20px;color:#a855f7"><?= number_format($freqMedia, 2, ',', '.') ?>x</strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline">
                <span style="font-size:13px;color:var(--muted)">% de reinscrições</span>
                <strong style="font-size:20px;color:#a855f7"><?= $pctReinsc ?>%</strong>
            </div>
        </div>
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
          <div style="font-size:9.5px;font-weight:600;color:#fbbf24;margin-top:2px" title="Queda em relação à etapa anterior">↓ <?= $fstep['drop'] ?>%</div>
          <?php else: ?>
          <div style="font-size:9.5px;color:var(--muted);margin-top:2px">—</div>
          <?php endif; ?>
          <div style="font-size:9.5px;font-weight:600;color:#60a5fa;margin-top:1px" title="% do total de inscritos"><?= $fstep['pct_total'] ?>% do total</div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
</div>
<?php endif; ?>

<!-- ═══ LIVE: Funil exclusivo + cards ═══ -->
<div class="panel mb-4">
    <div class="panel-title">Funil de Live<?= ($liveAcessou + $liveOferta + $liveCompra) === 0 ? ' <span style="font-size:11px;color:var(--muted);font-weight:400">(sem dados — configure eventos em Integrações → Eventos Live)</span>' : '' ?></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px">
        <div class="kpi" style="border-color:rgba(96,165,250,.3)">
            <div class="kpi-icon" style="background:rgba(96,165,250,.15);color:#60a5fa">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
            <div class="kpi-label">Acessaram a live</div>
            <div class="kpi-value"><?= number_format($liveAcessou) ?></div>
            <div class="kpi-sub"><?= $totalAlunos>0?round($liveAcessou/$totalAlunos*100,1):0 ?>% dos inscritos</div>
        </div>
        <div class="kpi" style="border-color:rgba(251,191,36,.3)">
            <div class="kpi-icon" style="background:rgba(251,191,36,.15);color:#fbbf24">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </div>
            <div class="kpi-label">Ficaram até a oferta</div>
            <div class="kpi-value"><?= number_format($liveOferta) ?></div>
            <div class="kpi-sub"><?= $liveAcessou>0?round($liveOferta/$liveAcessou*100,1):0 ?>% de quem acessou</div>
        </div>
        <div class="kpi" style="border-color:rgba(52,211,153,.3)">
            <div class="kpi-icon" style="background:rgba(52,211,153,.15);color:#34d399">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            </div>
            <div class="kpi-label">Clicaram na compra</div>
            <div class="kpi-value"><?= number_format($liveCompra) ?></div>
            <div class="kpi-sub"><?= $liveOferta>0?round($liveCompra/$liveOferta*100,1):0 ?>% de quem viu oferta</div>
        </div>
    </div>
    <div style="display:flex;align-items:flex-end;gap:2px;height:140px;padding:0 6px">
        <?php
        $liveFunnel = [
            ['Acessou', $liveAcessou, '#60a5fa'],
            ['Oferta',  $liveOferta,  '#fbbf24'],
            ['Compra',  $liveCompra,  '#34d399'],
        ];
        $lfMax = max(1, $liveAcessou);
        foreach ($liveFunnel as $lf):
            $h = max(8, round($lf[1]/$lfMax*100));
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%">
            <div style="font-size:12px;font-weight:700;color:var(--text)"><?= number_format($lf[1]) ?></div>
            <div style="flex:1;display:flex;align-items:flex-end;width:60%;max-width:120px">
                <div style="width:100%;background:<?= $lf[2] ?>;border-radius:6px 6px 0 0;height:<?= $h ?>%;min-height:8px;opacity:.85"></div>
            </div>
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em"><?= $lf[0] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ Comparativo POR TURMA (barras) ═══ -->
<div class="panel mb-4">
    <div class="panel-title" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span>Comparativo por turma<?= empty($barTurmaData) ? ' <span style="font-size:11px;color:var(--muted);font-weight:400">(sem dados — verifique se há turmas com inscritos no período)</span>' : '' ?></span>
        <span style="font-size:11px;color:var(--muted);font-weight:400">selecione turmas e modo (qtd ou %)</span>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <div style="display:flex;border:1px solid var(--border);border-radius:6px;overflow:hidden">
                <button type="button" id="btnModeQtd" onclick="btSetMode('qtd')" class="bt-mode-btn active" style="background:var(--primary);color:#000;border:none;padding:5px 12px;cursor:pointer;font-size:11px;font-weight:700">Qtd</button>
                <button type="button" id="btnModePct" onclick="btSetMode('pct')" class="bt-mode-btn" style="background:transparent;color:var(--muted);border:none;padding:5px 12px;cursor:pointer;font-size:11px;font-weight:700">%</button>
            </div>
        </div>
    </div>
    <div style="margin-bottom:16px;position:relative" id="turmasPickerWrap">
        <button type="button" id="btDropBtn" onclick="btDropToggle(event)"
                style="width:100%;text-align:left;background:#14142a;border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:space-between;font-family:var(--font, inherit)">
            <span><strong style="color:var(--muted);text-transform:uppercase;font-size:11px;letter-spacing:.05em;margin-right:8px">Turmas a comparar:</strong><span id="btDropLabel">carregando…</span></span>
            <span id="btDropArrow" style="color:var(--muted);font-size:11px">▼</span>
        </button>
        <div id="btDropPanel" style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.4);padding:8px;z-index:50;max-height:320px;overflow:hidden;flex-direction:column">
            <input type="text" id="btDropSearch" placeholder="Buscar turma..." oninput="btDropFilter()"
                   style="background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 10px;font-size:12px;margin-bottom:6px;width:100%;box-sizing:border-box">
            <div style="display:flex;gap:6px;margin-bottom:6px">
                <button type="button" onclick="btDropAll(true)" style="flex:1;background:none;border:1px solid var(--border);color:var(--text);padding:4px;border-radius:6px;cursor:pointer;font-size:11px">Selecionar todas</button>
                <button type="button" onclick="btDropAll(false)" style="flex:1;background:none;border:1px solid var(--border);color:var(--text);padding:4px;border-radius:6px;cursor:pointer;font-size:11px">Limpar</button>
            </div>
            <div id="btDropList" style="overflow-y:auto;max-height:220px"></div>
        </div>
        <div id="btSelectedPills" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px"></div>
    </div>
    <canvas id="chartBarTurmas" style="max-height:380px"></canvas>
</div>

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

    // Novos vs Reinscritos
    var cReinsc = document.getElementById('chartReinsc');
    if (cReinsc) {
        new Chart(cReinsc, {
            type: 'doughnut',
            data: {
                labels: ['Novos', 'Reinscritos'],
                datasets: [{
                    data: [<?= $novosInsc ?>, <?= $reinscritos ?>],
                    backgroundColor: ['rgba(56,189,248,.8)', 'rgba(168,85,247,.8)'],
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed.toLocaleString('pt-BR') + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // ── Gráfico comparativo por turma ────────────────────────────────────
    const BAR_TURMA_DATA  = <?= json_encode($barTurmaData, JSON_UNESCAPED_UNICODE) ?>;
    const TURMAS_LABEL    = <?= json_encode($turmasLabel,  JSON_UNESCAPED_UNICODE) ?>;
    const TURMA_FILTRADA  = <?= json_encode((string)$turmaId) ?>;
    const BAR_STAGES = [
        {key:'inscritos', label:'Inscritos',  color:'#facc15'},
        {key:'logaram',   label:'Logaram',    color:'#a855f7'},
        {key:'aula',      label:'Viu aula',   color:'#0ea5e9'},
        {key:'live_acessou', label:'Live: Acessou', color:'#60a5fa'},
        {key:'live_oferta',  label:'Live: Oferta',  color:'#fbbf24'},
        {key:'live_compra',  label:'Live: Compra',  color:'#34d399'},
        {key:'cert',      label:'Certificado', color:'#22c55e'},
    ];
    let btMode = 'qtd';
    let btSelectedTurmas = [];
    let btChart = null;

    function btInit() {
        const wrap = document.getElementById('turmasPickerWrap');
        if (!wrap) return;
        const turmaIds = Object.keys(BAR_TURMA_DATA).sort((a,b) => {
            return (BAR_TURMA_DATA[b].inscritos||0) - (BAR_TURMA_DATA[a].inscritos||0);
        });
        if (!turmaIds.length) {
            document.getElementById('btDropLabel').textContent = 'nenhuma turma no período';
            return;
        }
        if (TURMA_FILTRADA && BAR_TURMA_DATA[TURMA_FILTRADA]) {
            btSelectedTurmas = [TURMA_FILTRADA];
        } else {
            btSelectedTurmas = turmaIds.slice(0, Math.min(3, turmaIds.length));
        }
        btRenderDropList();
        btRenderSelectedPills();
        btRender();

        document.addEventListener('click', function(ev) {
            const wrap = document.getElementById('turmasPickerWrap');
            if (wrap && !wrap.contains(ev.target)) {
                const p = document.getElementById('btDropPanel');
                if (p && p.style.display === 'flex') { p.style.display = 'none'; document.getElementById('btDropArrow').textContent = '▼'; }
            }
        });
    }

    function btDropToggle(ev) {
        if (ev) ev.stopPropagation();
        const p = document.getElementById('btDropPanel');
        const open = p.style.display === 'flex';
        p.style.display = open ? 'none' : 'flex';
        document.getElementById('btDropArrow').textContent = open ? '▼' : '▲';
        if (!open) {
            document.getElementById('btDropSearch').value = '';
            btDropFilter();
            setTimeout(() => document.getElementById('btDropSearch').focus(), 30);
        }
    }

    function btRenderDropList() {
        const turmaIds = Object.keys(BAR_TURMA_DATA).sort((a,b) =>
            (BAR_TURMA_DATA[b].inscritos||0) - (BAR_TURMA_DATA[a].inscritos||0));
        const q = (document.getElementById('btDropSearch')?.value || '').trim().toLowerCase();
        const list = document.getElementById('btDropList');
        const itens = turmaIds.filter(tid => {
            const lbl = (TURMAS_LABEL[tid] || tid).toLowerCase();
            return !q || lbl.includes(q);
        });
        if (!itens.length) {
            list.innerHTML = '<div style="padding:10px;color:var(--muted);font-size:12px;text-align:center">Nenhuma turma encontrada</div>';
            return;
        }
        list.innerHTML = itens.map(tid => {
            const label = TURMAS_LABEL[tid] || (tid === '' ? 'Sem turma' : ('Turma ' + tid));
            const sel = btSelectedTurmas.includes(tid);
            return `<div class="bt-drop-item" data-tid="${escAttr(tid)}" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:12px;color:var(--text)">
                <input type="checkbox" ${sel ? 'checked' : ''} style="cursor:pointer;accent-color:var(--primary)">
                <span style="flex:1">${escHtml(label)}</span>
                <span style="color:var(--muted);font-size:11px">${BAR_TURMA_DATA[tid].inscritos||0}</span>
            </div>`;
        }).join('');
        list.querySelectorAll('.bt-drop-item').forEach(el => {
            el.addEventListener('mouseenter', () => el.style.background = 'var(--bg-hover, rgba(255,255,255,.05))');
            el.addEventListener('mouseleave', () => el.style.background = '');
            el.addEventListener('click', () => btDropPick(el.dataset.tid));
        });
    }

    function btDropFilter() { btRenderDropList(); }

    function btDropPick(tid) {
        const i = btSelectedTurmas.indexOf(tid);
        if (i >= 0) btSelectedTurmas.splice(i, 1);
        else btSelectedTurmas.push(tid);
        btRenderDropList();
        btRenderSelectedPills();
        btRender();
    }

    function btDropAll(checkAll) {
        const turmaIds = Object.keys(BAR_TURMA_DATA);
        btSelectedTurmas = checkAll ? [...turmaIds] : [];
        btRenderDropList();
        btRenderSelectedPills();
        btRender();
    }

    function btRenderSelectedPills() {
        const cont = document.getElementById('btSelectedPills');
        const lbl  = document.getElementById('btDropLabel');
        if (!btSelectedTurmas.length) {
            cont.innerHTML = '';
            lbl.textContent = 'nenhuma selecionada';
            return;
        }
        lbl.textContent = btSelectedTurmas.length + ' turma' + (btSelectedTurmas.length > 1 ? 's' : '') + ' selecionada' + (btSelectedTurmas.length > 1 ? 's' : '');
        cont.innerHTML = btSelectedTurmas.map(tid => {
            const label = TURMAS_LABEL[tid] || (tid === '' ? 'Sem turma' : ('Turma ' + tid));
            return `<span style="display:inline-flex;align-items:center;gap:4px;background:rgba(250,204,21,.15);border:1px solid var(--primary);color:var(--primary);padding:2px 4px 2px 10px;border-radius:999px;font-size:11px;font-weight:600">
                ${escHtml(label)}
                <button type="button" onclick="btDropPick('${escAttr(tid)}')" style="background:none;border:none;color:inherit;cursor:pointer;padding:0 6px;font-size:13px;line-height:1">×</button>
            </span>`;
        }).join('');
    }

    function btSetMode(m) {
        btMode = m;
        document.getElementById('btnModeQtd').style.background = m==='qtd' ? 'var(--primary)' : 'transparent';
        document.getElementById('btnModeQtd').style.color      = m==='qtd' ? '#000' : 'var(--muted)';
        document.getElementById('btnModePct').style.background = m==='pct' ? 'var(--primary)' : 'transparent';
        document.getElementById('btnModePct').style.color      = m==='pct' ? '#000' : 'var(--muted)';
        btRender();
    }

    function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function escAttr(s) { return String(s||'').replace(/'/g,"\\'").replace(/"/g,'&quot;'); }

    // Expor globalmente (onclick inline precisa)
    window.btSetMode = btSetMode;
    window.btDropToggle = btDropToggle;
    window.btDropFilter = btDropFilter;
    window.btDropPick = btDropPick;
    window.btDropAll = btDropAll;

    function btRender() {
        const labels = BAR_STAGES.map(s => s.label);
        const datasets = btSelectedTurmas.map((tid, idx) => {
            const data = BAR_STAGES.map(s => {
                const raw = (BAR_TURMA_DATA[tid] && BAR_TURMA_DATA[tid][s.key]) || 0;
                if (btMode === 'pct') {
                    const base = (BAR_TURMA_DATA[tid] && BAR_TURMA_DATA[tid].inscritos) || 0;
                    return base > 0 ? Math.round(raw / base * 1000) / 10 : 0;
                }
                return raw;
            });
            const hue = (idx * 47 + 200) % 360;
            return {
                label: TURMAS_LABEL[tid] || (tid === '' ? 'Sem turma' : ('Turma ' + tid)),
                data,
                backgroundColor: `hsla(${hue},75%,60%,.7)`,
                borderColor:     `hsla(${hue},75%,55%,1)`,
                borderWidth: 1.5,
            };
        });

        const ctx = document.getElementById('chartBarTurmas');
        if (!ctx) return;
        if (btChart) btChart.destroy();

        // Plugin inline: rótulo do valor em cima de cada barra
        const dataLabelsPlugin = {
            id: 'btDataLabels',
            afterDatasetsDraw(chart) {
                const c = chart.ctx;
                chart.data.datasets.forEach((ds, di) => {
                    const meta = chart.getDatasetMeta(di);
                    meta.data.forEach((bar, idx) => {
                        const v = ds.data[idx];
                        if (v === null || v === undefined) return;
                        const txt = btMode === 'pct'
                            ? (v % 1 === 0 ? v + '%' : v.toFixed(1) + '%')
                            : v.toLocaleString('pt-BR');
                        c.save();
                        c.fillStyle = '#e2e8f0';
                        c.font = 'bold 10px system-ui, sans-serif';
                        c.textAlign = 'center';
                        c.textBaseline = 'bottom';
                        c.fillText(txt, bar.x, bar.y - 4);
                        c.restore();
                    });
                });
            }
        };

        btChart = new Chart(ctx, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                layout: { padding: { top: 22 } }, // espaço para os rótulos
                plugins: {
                    legend: { position:'top', labels:{ color:'#cbd5e1', font:{size:11}, padding:10 } },
                    tooltip: {
                        callbacks: {
                            label: c => c.dataset.label + ': ' + c.parsed.y + (btMode==='pct'?'%':'')
                        }
                    }
                },
                scales: {
                    x: { ticks:{ color:'#94a3b8', font:{size:11} }, grid:{ color:'rgba(26,37,64,.4)' } },
                    y: {
                        beginAtZero:true,
                        ticks:{ color:'#94a3b8', font:{size:11},
                                callback: v => btMode==='pct' ? v+'%' : v },
                        grid:{ color:'rgba(26,37,64,.4)' }
                    }
                }
            },
            plugins: [dataLabelsPlugin]
        });
    }

    btInit();

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
            $uid       = (int)$rk['id'];
            $chave     = (string)($rk['chave'] ?? '');
            $qtd       = (int)$rk['qtd_inscricoes'];
            $userCount = count(array_filter(explode(',', (string)($rk['user_ids_str'] ?? ''))));
            $rankN = $ri + 1;
            if ($rankN === 1)      $rankCls = 'rank-1';
            elseif ($rankN === 2)  $rankCls = 'rank-2';
            elseif ($rankN === 3)  $rankCls = 'rank-3';
            else                   $rankCls = 'rank-n';
            if ($qtd >= 5)         $countColor = 'color:#f87171;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25)';
            elseif ($qtd >= 3)     $countColor = 'color:#fdba74;background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.25)';
            elseif ($qtd === 2)    $countColor = 'color:#fcd34d;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.2)';
            else                   $countColor = 'color:var(--muted);background:var(--bg-hover);border:1px solid var(--border)';
            $hist = $rankHistorico[$chave] ?? [];
        ?>
        <tr class="main-row" id="rkrow-<?= $ri ?>" onclick="rkToggle(<?= $ri ?>)">
            <td><span class="rk-expand-icon">▶</span></td>
            <td><span class="rank-badge <?= $rankCls ?>"><?= $rankN ?></span></td>
            <td>
                <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars((string)($rk['nome']??'-')) ?></div>
                <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars((string)($rk['email']??'-')) ?></div>
                <?php if ($userCount > 1): ?>
                <div style="font-size:10px;color:var(--warning);margin-top:2px">⚠ <?= $userCount ?> cadastros mesclados</div>
                <?php endif; ?>
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
                                $pUtms = [
                                    'source'   => trim((string)($h['utm_source']   ?? '')),
                                    'medium'   => trim((string)($h['utm_medium']   ?? '')),
                                    'campaign' => trim((string)($h['utm_campaign'] ?? '')),
                                    'content'  => trim((string)($h['utm_content']  ?? '')),
                                ];
                                $hasPayloadUtm = array_filter($pUtms, fn($v) => $v !== '');
                                $isNovo = (int)($h['is_novo'] ?? 1);
                                $turmaH = trim((string)($h['codigo_turma'] ?? ''));
                            ?>
                            <div class="rk-insc-card">
                                <div class="rk-insc-date">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?= rkDt((string)($h['data_dia']??'')) ?>
                                    <span><?= htmlspecialchars(substr((string)($h['hora']??''),11,5)) ?></span>
                                    <span style="margin-left:4px;font-size:10px;background:var(--primary-dim);color:var(--primary);padding:1px 6px;border-radius:999px">#<?= $hi+1 ?></span>
                                    <?php if (!$isNovo): ?>
                                    <span style="margin-left:4px;font-size:10px;background:rgba(99,102,241,.12);color:#818cf8;padding:1px 6px;border-radius:999px">re-inscrição</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($turmaH !== ''): ?>
                                <div class="rk-insc-row">
                                    <span class="rk-insc-k">Turma</span>
                                    <span class="rk-insc-v"><?= htmlspecialchars($turmaH) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($hasPayloadUtm): ?>
                                    <?php foreach (['source'=>'Source','medium'=>'Medium','campaign'=>'Campaign','content'=>'Content'] as $pk=>$pl):
                                        $pv = $pUtms[$pk];
                                        if ($pv==='') continue;
                                    ?>
                                    <div class="rk-insc-row">
                                        <span class="rk-insc-k">UTM <?= htmlspecialchars($pl) ?></span>
                                        <span class="rk-insc-v"><?= htmlspecialchars($pv) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="font-size:11px;color:var(--dim)">UTMs não disponíveis</div>
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

    <?php if (!$hasIL): ?>
    <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">
        Sem dados de inscrições registrados ainda.
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
