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

// Login/logout ja foram tratados acima. Daqui pra frente o painel so LE a
// sessao. Liberamos o lock para que outras abas/cliques do admin nao fiquem
// presos enquanto este dashboard (muitas queries) carrega.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = getPDO();
require_once __DIR__ . '/../app/push_notifications.php';
push_ensure_schema($pdo);

// ========================
// 3) FILTROS (datas / turma)
// ========================
// Default: últimos 30 dias (só aplica quando não vier filtro algum, evita prender ao clicar "Limpar")
$temFiltroNaUrl = isset($_GET['data_de']) || isset($_GET['data_ate']) || isset($_GET['turma_id']);
$dataDe  = trim($_GET['data_de']  ?? '');
$dataAte = trim($_GET['data_ate'] ?? '');
$turmaRaw = $_GET['turma_id'] ?? [];
if (!is_array($turmaRaw)) {
    $turmaRaw = [$turmaRaw];
}
$turmaIds = array_values(array_unique(array_filter(array_map('intval', $turmaRaw), fn($id) => $id > 0)));
$turmaId = (int)($turmaIds[0] ?? 0);
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
if ($turmaIds && $turmaColTop) {
    $turmaCodigoSel = '';
    if ($turmaColTop === 'codigo_turma') {
        try {
            $ph = [];
            $pr = [];
            foreach ($turmaIds as $i => $id) {
                $k = ':tid_lookup_' . $i;
                $ph[] = $k;
                $pr[$k] = $id;
            }
            $stT = $pdo->prepare("SELECT codigo FROM turmas WHERE id IN (" . implode(',', $ph) . ")");
            $stT->execute($pr);
            $codigos = array_values(array_filter(array_map('strval', $stT->fetchAll(PDO::FETCH_COLUMN) ?: []), fn($v) => $v !== ''));
        } catch (Throwable $e) {}
        if (!empty($codigos)) {
            $ph = [];
            foreach ($codigos as $i => $codigo) {
                $k = 'turma_codigo_' . $i;
                $ph[] = ':' . $k;
                $paramsUsers[$k] = $codigo;
            }
            $whereUsers[] = "u.`codigo_turma` IN (" . implode(',', $ph) . ")";
        }
    } else {
        $ph = [];
        foreach ($turmaIds as $i => $id) {
            $k = 'turma_id_' . $i;
            $ph[] = ':' . $k;
            $paramsUsers[$k] = $id;
        }
        $whereUsers[] = "u.`$turmaColTop` IN (" . implode(',', $ph) . ")";
    }
}

$whereUsersSql = $whereUsers ? ('WHERE ' . implode(' AND ', $whereUsers)) : '';

$turmas = [];
try {
    // Tenta com nome (instâncias antigas)
    $turmas = $pdo->query("SELECT id, codigo, nome FROM turmas ORDER BY codigo ASC")->fetchAll();
} catch (Throwable $e) {
    // Fallback sem nome
    try {
        $turmas = $pdo->query("SELECT id, codigo, '' AS nome FROM turmas ORDER BY codigo ASC")->fetchAll();
    } catch (Throwable $e2) { $turmas = []; }
}

// Codigo da turma selecionada (para filtros em inscricao_logs.codigo_turma)
$codigoTurmaSel = '';
$codigosTurmaFiltro = [];
if ($turmaIds) {
    foreach ($turmas as $t) {
        if (in_array((int)$t['id'], $turmaIds, true)) {
            $codigo = (string)($t['codigo'] ?? '');
            if ($codigo !== '') {
                $codigosTurmaFiltro[] = $codigo;
                if ($codigoTurmaSel === '') $codigoTurmaSel = $codigo;
            }
        }
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
    if ($codigosTurmaFiltro) {
        $ph = [];
        foreach ($codigosTurmaFiltro as $i => $codigo) {
            $k = 'il_ct_' . $i;
            $ph[] = ':' . $k;
            $prIL[$k] = $codigo;
        }
        $whIL[] = 'il.codigo_turma IN (' . implode(',', $ph) . ')';
    }
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
// 4) SERIES TEMPORAIS (graficos de linha)
// ========================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(250) NULL,
            INDEX idx_login_events_user (user_id),
            INDEX idx_login_events_logged (logged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lesson_view_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            lesson_id INT NOT NULL,
            viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(250) NULL,
            INDEX idx_lve_user (user_id),
            INDEX idx_lve_lesson (lesson_id),
            INDEX idx_lve_viewed (viewed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {}

function dash_bucket_expr(string $dateExpr, string $period): string {
    if ($period === 'monthly') return "DATE_FORMAT($dateExpr, '%Y-%m')";
    if ($period === 'yearly') return "DATE_FORMAT($dateExpr, '%Y')";
    return "DATE($dateExpr)";
}

function dash_bucket_label(string $bucket, string $period): string {
    if ($period === 'daily' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bucket, $m)) return $m[3] . '/' . $m[2] . '/' . $m[1];
    if ($period === 'monthly' && preg_match('/^(\d{4})-(\d{2})$/', $bucket, $m)) return $m[2] . '/' . $m[1];
    return $bucket;
}

function dash_engagement_bucket_expr(string $dateExpr, string $period): string {
    if ($period === 'weekly') return "DATE_FORMAT(DATE_SUB(DATE($dateExpr), INTERVAL WEEKDAY($dateExpr) DAY), '%Y-%m-%d')";
    if ($period === 'monthly') return "DATE_FORMAT($dateExpr, '%Y-%m')";
    if ($period === 'quarterly') return "CONCAT(YEAR($dateExpr), '-T', QUARTER($dateExpr))";
    if ($period === 'semester') return "CONCAT(YEAR($dateExpr), '-S', IF(MONTH($dateExpr) <= 6, 1, 2))";
    if ($period === 'yearly') return "DATE_FORMAT($dateExpr, '%Y')";
    return "DATE_FORMAT(DATE($dateExpr), '%Y-%m-%d')";
}

function dash_engagement_bucket_label(string $bucket, string $period): string {
    if ($period === 'daily' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bucket, $m)) return $m[3] . '/' . $m[2] . '/' . $m[1];
    if ($period === 'weekly' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bucket, $m)) return 'Sem. ' . $m[3] . '/' . $m[2] . '/' . $m[1];
    if ($period === 'monthly' && preg_match('/^(\d{4})-(\d{2})$/', $bucket, $m)) return $m[2] . '/' . $m[1];
    if ($period === 'quarterly' && preg_match('/^(\d{4})-T([1-4])$/', $bucket, $m)) return 'T' . $m[2] . '/' . $m[1];
    if ($period === 'semester' && preg_match('/^(\d{4})-S([12])$/', $bucket, $m)) return 'S' . $m[2] . '/' . $m[1];
    return $bucket;
}

function dash_turma_where(string $userAlias, ?string $turmaCol, array $turmaIds, array $codigosTurma, array &$params, string $prefix): array {
    if (!$turmaIds || !$turmaCol) return [];
    $where = [];
    if ($turmaCol === 'codigo_turma') {
        if (!$codigosTurma) return [];
        $ph = [];
        foreach ($codigosTurma as $i => $codigo) {
            $k = $prefix . '_tc_' . $i;
            $ph[] = ':' . $k;
            $params[$k] = $codigo;
        }
        $where[] = "$userAlias.`codigo_turma` IN (" . implode(',', $ph) . ")";
    } else {
        $ph = [];
        foreach ($turmaIds as $i => $id) {
            $k = $prefix . '_tid_' . $i;
            $ph[] = ':' . $k;
            $params[$k] = $id;
        }
        $where[] = "$userAlias.`$turmaCol` IN (" . implode(',', $ph) . ")";
    }
    return $where;
}

function dash_fetch_series(PDO $pdo, string $period, string $fromSql, string $dateExpr, string $valueExpr, array $baseWhere, array $baseParams): array {
    $bucketExpr = dash_bucket_expr($dateExpr, $period);
    $whereSql = $baseWhere ? ('WHERE ' . implode(' AND ', $baseWhere)) : '';
    $sql = "
        SELECT $bucketExpr AS bucket, $valueExpr AS total
        FROM $fromSql
        $whereSql
        GROUP BY bucket
        ORDER BY bucket ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($baseParams);
    $labels = [];
    $data = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $bucket = (string)($row['bucket'] ?? '');
        if ($bucket === '') continue;
        $labels[] = dash_bucket_label($bucket, $period);
        $data[] = round((float)($row['total'] ?? 0), 2);
    }
    return ['labels' => $labels, 'data' => $data];
}

function dash_event_base_where(string $dateExpr, string $prefix, string $dataDe, string $dataAte, ?string $turmaCol, array $turmaIds, array $codigosTurma, array &$params): array {
    $where = ["$dateExpr IS NOT NULL"];
    if ($dataDe !== '') {
        $where[] = "$dateExpr >= :" . $prefix . "_de";
        $params[$prefix . '_de'] = $dataDe . ' 00:00:00';
    }
    if ($dataAte !== '') {
        $where[] = "$dateExpr <= :" . $prefix . "_ate";
        $params[$prefix . '_ate'] = $dataAte . ' 23:59:59';
    }
    return array_merge($where, dash_turma_where('u', $turmaCol, $turmaIds, $codigosTurma, $params, $prefix));
}

function dash_all_period_series(PDO $pdo, string $fromSql, string $dateExpr, string $valueExpr, string $prefix, string $dataDe, string $dataAte, ?string $turmaCol, array $turmaIds, array $codigosTurma): array {
    $out = [];
    foreach (['daily', 'monthly', 'yearly'] as $period) {
        $params = [];
        $where = dash_event_base_where($dateExpr, $prefix . '_' . $period, $dataDe, $dataAte, $turmaCol, $turmaIds, $codigosTurma, $params);
        try {
            $out[$period] = dash_fetch_series($pdo, $period, $fromSql, $dateExpr, $valueExpr, $where, $params);
        } catch (Throwable $e) {
            $out[$period] = ['labels' => [], 'data' => []];
        }
    }
    return $out;
}

function dash_lead_engagement_series(PDO $pdo, string $dataDe, string $dataAte, ?string $turmaCol, array $turmaIds, array $codigosTurma): array {
    $empty = [
        'daily' => ['labels' => [], 'frio' => [], 'morno' => [], 'quente' => [], 'pelando' => []],
        'weekly' => ['labels' => [], 'frio' => [], 'morno' => [], 'quente' => [], 'pelando' => []],
        'monthly' => ['labels' => [], 'frio' => [], 'morno' => [], 'quente' => [], 'pelando' => []],
        'quarterly' => ['labels' => [], 'frio' => [], 'morno' => [], 'quente' => [], 'pelando' => []],
        'semester' => ['labels' => [], 'frio' => [], 'morno' => [], 'quente' => [], 'pelando' => []],
        'yearly' => ['labels' => [], 'frio' => [], 'morno' => [], 'quente' => [], 'pelando' => []],
    ];
    try {
        $expected16 = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND ordem BETWEEN 1 AND 6")->fetchColumn();
        $totalRequired = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE ativo = 1 AND conta_para_conclusao = 1")->fetchColumn();
    } catch (Throwable $e) {
        $expected16 = 0;
        $totalRequired = 0;
    }

    $lessonActivitySql = "SELECT NULL user_id,0 lessons_1_4,0 lessons_1_6,0 required_completed WHERE 1=0";
    if (dash_table_exists($pdo, 'lessons')) {
        $lessonParts = [];
        if (dash_table_exists($pdo, 'lesson_progress')) {
            $lessonParts[] = "SELECT lp.user_id,lp.lesson_id FROM lesson_progress lp WHERE lp.status='completed'";
        }
        if (dash_table_exists($pdo, 'lesson_view_events')) {
            $lessonParts[] = "SELECT lve.user_id,lve.lesson_id FROM lesson_view_events lve";
        }
        if ($lessonParts) {
            $lessonActivitySql = "
                SELECT la.user_id,
                       COUNT(DISTINCT CASE WHEN l.ativo=1 AND l.ordem BETWEEN 1 AND 4 THEN l.id END) lessons_1_4,
                       COUNT(DISTINCT CASE WHEN l.ativo=1 AND l.ordem BETWEEN 1 AND 6 THEN l.id END) lessons_1_6,
                       COUNT(DISTINCT CASE WHEN l.ativo=1 AND l.conta_para_conclusao=1 AND lp_done.lesson_id IS NOT NULL THEN l.id END) required_completed
                  FROM (" . implode(' UNION ALL ', $lessonParts) . ") la
                  JOIN lessons l ON l.id = la.lesson_id
                  LEFT JOIN lesson_progress lp_done ON lp_done.user_id=la.user_id AND lp_done.lesson_id=la.lesson_id AND lp_done.status='completed'
              GROUP BY la.user_id
            ";
        }
    }

    $loginSql = dash_table_exists($pdo, 'login_events')
        ? "SELECT user_id,1 has_login FROM login_events GROUP BY user_id"
        : "SELECT NULL user_id,0 has_login WHERE 1=0";
    $groupSql = (dash_table_exists($pdo, 'whatsapp_group_events') && dash_column_exists($pdo, 'whatsapp_group_events', 'interpreted_event'))
        ? "SELECT user_id,1 joined_group FROM whatsapp_group_events WHERE user_id IS NOT NULL AND user_id>0 AND interpreted_event='WHATSAPP_GRUPO_ENTROU' GROUP BY user_id"
        : "SELECT NULL user_id,0 joined_group WHERE 1=0";
    $liveAccessSql = (dash_table_exists($pdo, 'live_event_recebimentos') && dash_table_exists($pdo, 'live_events'))
        ? "SELECT ler.user_id,1 live_access FROM live_event_recebimentos ler JOIN live_events le ON le.id=ler.event_id WHERE ler.status='processado' AND le.tipo='acessou' AND ler.user_id IS NOT NULL GROUP BY ler.user_id"
        : "SELECT NULL user_id,0 live_access WHERE 1=0";
    $liveOfferSql = (dash_table_exists($pdo, 'live_event_recebimentos') && dash_table_exists($pdo, 'live_events'))
        ? "SELECT ler.user_id,1 live_offer FROM live_event_recebimentos ler JOIN live_events le ON le.id=ler.event_id WHERE ler.status='processado' AND le.tipo='oferta' AND ler.user_id IS NOT NULL GROUP BY ler.user_id"
        : "SELECT NULL user_id,0 live_offer WHERE 1=0";
    $offerClickParts = [];
    if (dash_table_exists($pdo, 'live_event_recebimentos') && dash_table_exists($pdo, 'live_events')) {
        $offerClickParts[] = "SELECT ler.user_id FROM live_event_recebimentos ler JOIN live_events le ON le.id=ler.event_id WHERE ler.status='processado' AND le.tipo='compra' AND ler.user_id IS NOT NULL";
    }
    if (dash_table_exists($pdo, 'automation_flow_events')) {
        $offerClickParts[] = "SELECT user_id FROM automation_flow_events WHERE event_code IN ('LIVE_COMPRA','BOTAO_OFERTA','BOTAO_COMPRA') AND user_id IS NOT NULL";
    }
    $offerClickSql = $offerClickParts
        ? "SELECT user_id,1 offer_click FROM (" . implode(' UNION ALL ', $offerClickParts) . ") oc GROUP BY user_id"
        : "SELECT NULL user_id,0 offer_click WHERE 1=0";
    $certificateSql = dash_table_exists($pdo, 'certificates')
        ? "SELECT user_id,1 has_certificate FROM certificates WHERE status='emitido' GROUP BY user_id"
        : "SELECT NULL user_id,0 has_certificate WHERE 1=0";

    $out = $empty;
    foreach (array_keys($empty) as $period) {
        $params = [];
        $where = ['u.created_at IS NOT NULL'];
        if ($dataDe !== '') {
            $where[] = 'u.created_at >= :de';
            $params['de'] = $dataDe . ' 00:00:00';
        }
        if ($dataAte !== '') {
            $where[] = 'u.created_at <= :ate';
            $params['ate'] = $dataAte . ' 23:59:59';
        }
        $where = array_merge($where, dash_turma_where('u', $turmaCol, $turmaIds, $codigosTurma, $params, 'eng_' . $period));
        $bucketExpr = dash_engagement_bucket_expr('u.created_at', $period);
        $progressExpr = $totalRequired > 0 ? "COALESCE(la.required_completed,0) / {$totalRequired}" : "0";
        $pelandoLessonExpr = $expected16 > 0 ? "COALESCE(la.lessons_1_6,0) >= {$expected16}" : "0";
        $sql = "
            SELECT bucket, perfil, COUNT(*) total
              FROM (
                SELECT {$bucketExpr} bucket,
                       CASE
                         WHEN {$pelandoLessonExpr}
                              AND COALESCE(lac.live_access,0)=1
                              AND COALESCE(lo.live_offer,0)=1
                              AND COALESCE(cert.has_certificate,0)=1 THEN 'pelando'
                         WHEN COALESCE(la.lessons_1_4,0) BETWEEN 1 AND 4
                              OR COALESCE(oc.offer_click,0)=1
                              OR {$progressExpr} >= 0.9 THEN 'quente'
                         WHEN COALESCE(login.has_login,0)=1
                              OR (COALESCE(grp.joined_group,0)=1 AND COALESCE(login.has_login,0)=1) THEN 'morno'
                         ELSE 'frio'
                       END perfil
                  FROM users u
             LEFT JOIN ({$loginSql}) login ON login.user_id=u.id
             LEFT JOIN ({$groupSql}) grp ON grp.user_id=u.id
             LEFT JOIN ({$lessonActivitySql}) la ON la.user_id=u.id
             LEFT JOIN ({$liveAccessSql}) lac ON lac.user_id=u.id
             LEFT JOIN ({$liveOfferSql}) lo ON lo.user_id=u.id
             LEFT JOIN ({$offerClickSql}) oc ON oc.user_id=u.id
             LEFT JOIN ({$certificateSql}) cert ON cert.user_id=u.id
                 WHERE " . implode(' AND ', $where) . "
              ) classified
          GROUP BY bucket, perfil
          ORDER BY bucket ASC
        ";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $byBucket = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $bucket = (string)($row['bucket'] ?? '');
                $perfil = (string)($row['perfil'] ?? '');
                if ($bucket === '' || !isset($out[$period][$perfil])) continue;
                if (!isset($byBucket[$bucket])) $byBucket[$bucket] = ['frio' => 0, 'morno' => 0, 'quente' => 0, 'pelando' => 0];
                $byBucket[$bucket][$perfil] = (int)($row['total'] ?? 0);
            }
            foreach ($byBucket as $bucket => $values) {
                $out[$period]['labels'][] = dash_engagement_bucket_label($bucket, $period);
                foreach (['frio','morno','quente','pelando'] as $perfil) $out[$period][$perfil][] = (int)$values[$perfil];
            }
        } catch (Throwable $e) {
            $out[$period] = $empty[$period];
        }
    }
    return $out;
}

function dash_device_family(?string $userAgent): string {
    $ua = strtolower(trim((string)$userAgent));
    if ($ua === '') return 'Não identificado';
    if (strpos($ua, 'android') !== false) return 'Android';
    if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipod') !== false) return 'iPhone';
    if (strpos($ua, 'ipad') !== false || (strpos($ua, 'macintosh') !== false && strpos($ua, 'mobile') !== false)) return 'iPad';
    if (strpos($ua, 'windows') !== false) return 'Windows';
    if (strpos($ua, 'macintosh') !== false || strpos($ua, 'mac os') !== false) return 'Mac';
    if (strpos($ua, 'linux') !== false) return 'Linux';
    return 'Outro';
}

// Cada aluno aparece uma vez, classificado pelo dispositivo de sua abertura de aula
// mais recente dentro do período selecionado.
$deviceOrder = ['Android', 'iPhone', 'iPad', 'Windows', 'Mac', 'Linux', 'Outro', 'Não identificado'];
$deviceCounts = array_fill_keys($deviceOrder, 0);
try {
    $deviceParams = [];
    $deviceEventWhere = [];
    if ($dataDe !== '') {
        $deviceEventWhere[] = 'viewed_at >= :device_de';
        $deviceParams['device_de'] = $dataDe . ' 00:00:00';
    }
    if ($dataAte !== '') {
        $deviceEventWhere[] = 'viewed_at <= :device_ate';
        $deviceParams['device_ate'] = $dataAte . ' 23:59:59';
    }
    $deviceEventSql = $deviceEventWhere ? ('WHERE ' . implode(' AND ', $deviceEventWhere)) : '';
    $deviceTurmaWhere = dash_turma_where('u', $turmaColTop, $turmaIds, $codigosTurmaFiltro, $deviceParams, 'device');
    $deviceTurmaSql = $deviceTurmaWhere ? ('WHERE ' . implode(' AND ', $deviceTurmaWhere)) : '';
    $deviceStmt = $pdo->prepare("
        SELECT lve.user_agent
          FROM lesson_view_events lve
          JOIN (
                SELECT user_id, MAX(id) AS latest_id
                  FROM lesson_view_events
                  {$deviceEventSql}
              GROUP BY user_id
          ) latest ON latest.latest_id = lve.id
          JOIN users u ON u.id = lve.user_id
          {$deviceTurmaSql}
    ");
    $deviceStmt->execute($deviceParams);
    foreach ($deviceStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $userAgent) {
        $family = dash_device_family(is_string($userAgent) ? $userAgent : null);
        $deviceCounts[$family] = ($deviceCounts[$family] ?? 0) + 1;
    }
} catch (Throwable $e) {}
$deviceCounts = array_filter($deviceCounts, static fn($count) => (int)$count > 0);
$deviceLabels = array_keys($deviceCounts);
$deviceData = array_values($deviceCounts);
$deviceTotalStudents = array_sum($deviceData);
$deviceMobileStudents = (int)($deviceCounts['Android'] ?? 0) + (int)($deviceCounts['iPhone'] ?? 0) + (int)($deviceCounts['iPad'] ?? 0);
$deviceMobilePct = $deviceTotalStudents > 0 ? round($deviceMobileStudents / $deviceTotalStudents * 100, 1) : 0.0;
$leadEngagementSeries = dash_lead_engagement_series($pdo, $dataDe, $dataAte, $turmaColTop, $turmaIds, $codigosTurmaFiltro);

$dashLineCharts = [];
$lineTurmaCol = $turmaColTop;

$dashLineCharts['logins'] = [
    'title' => 'Logaram no sistema',
    'suffix' => ' aluno(s)',
    'color' => '#14b8a6',
    'first_only_enabled' => true,
    'series' => dash_all_period_series(
        $pdo,
        "(SELECT user_id, logged_at AS event_at FROM login_events UNION ALL SELECT id AS user_id, last_login_at AS event_at FROM users WHERE last_login_at IS NOT NULL) ev JOIN users u ON u.id = ev.user_id",
        'ev.event_at',
        'COUNT(DISTINCT ev.user_id)',
        'logins',
        $dataDe,
        $dataAte,
        $lineTurmaCol,
        $turmaIds,
        $codigosTurmaFiltro
    ),
    'first_series' => dash_all_period_series(
        $pdo,
        "(SELECT user_id, MIN(event_at) AS first_event_at
          FROM (
              SELECT user_id, logged_at AS event_at FROM login_events
              UNION ALL
              SELECT id AS user_id, last_login_at AS event_at FROM users WHERE last_login_at IS NOT NULL
          ) login_history
          GROUP BY user_id) first_login
         JOIN users u ON u.id = first_login.user_id",
        'first_login.first_event_at',
        'COUNT(DISTINCT first_login.user_id)',
        'first_logins',
        $dataDe,
        $dataAte,
        $lineTurmaCol,
        $turmaIds,
        $codigosTurmaFiltro
    ),
];

$dashLineCharts['lesson_views'] = [
    'title' => 'Viram alguma aula',
    'suffix' => ' aluno(s)',
    'color' => '#38bdf8',
    'first_only_enabled' => true,
    'series' => dash_all_period_series(
        $pdo,
        "(SELECT user_id, viewed_at AS event_at FROM lesson_view_events UNION ALL SELECT user_id, COALESCE(completed_at, created_at) AS event_at FROM lesson_progress WHERE status = 'completed') ev JOIN users u ON u.id = ev.user_id",
        'ev.event_at',
        'COUNT(DISTINCT ev.user_id)',
        'lesson_views',
        $dataDe,
        $dataAte,
        $lineTurmaCol,
        $turmaIds,
        $codigosTurmaFiltro
    ),
    'first_series' => dash_all_period_series(
        $pdo,
        "(SELECT user_id, MIN(event_at) AS first_event_at
          FROM (
              SELECT user_id, viewed_at AS event_at FROM lesson_view_events
              UNION ALL
              SELECT user_id, COALESCE(completed_at, created_at) AS event_at
              FROM lesson_progress
              WHERE status = 'completed'
          ) lesson_history
          GROUP BY user_id) first_lesson
         JOIN users u ON u.id = first_lesson.user_id",
        'first_lesson.first_event_at',
        'COUNT(DISTINCT first_lesson.user_id)',
        'first_lesson_views',
        $dataDe,
        $dataAte,
        $lineTurmaCol,
        $turmaIds,
        $codigosTurmaFiltro
    ),
];

$dashLineCharts['app_installs'] = [
    'title' => 'Instalações do aplicativo',
    'suffix' => ' aluno(s)',
    'color' => '#facc15',
    'series' => dash_all_period_series(
        $pdo,
        'push_devices pd JOIN users u ON u.id = pd.user_id',
        'pd.installed_at',
        'COUNT(*)',
        'app_installs',
        $dataDe,
        $dataAte,
        $lineTurmaCol,
        $turmaIds,
        $codigosTurmaFiltro
    ),
];

// ========================
// 5) DADOS DO DASHBOARD
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
    $labelsDia[] = dash_bucket_label((string)$r['dia'], 'daily');
    $dataDia[]   = (int)$r['total'];
}

try { $pdo->exec("ALTER TABLE users ADD COLUMN bloquear TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN bloqueado_em DATETIME NULL"); } catch (Throwable $e) {}
$bloqueiosMesLabels = [];
$bloqueiosMesData = [];
$totalBloqueiosPeriodo = 0;
try {
    $bloqueiosPorMes = [];
    $whereBloqueiosUser = ['u.bloquear = 1'];
    $whereBloqueiosData = ['b.bloqueio_em IS NOT NULL'];
    $paramsBloqueios = [];
    if ($dataDe !== '') {
        $whereBloqueiosData[] = 'b.bloqueio_em >= :bloq_data_de';
        $paramsBloqueios['bloq_data_de'] = $dataDe . ' 00:00:00';
    }
    if ($dataAte !== '') {
        $whereBloqueiosData[] = 'b.bloqueio_em <= :bloq_data_ate';
        $paramsBloqueios['bloq_data_ate'] = $dataAte . ' 23:59:59';
    }
    if ($turmaIds && $turmaColTop) {
        if ($turmaColTop === 'codigo_turma') {
            $codigosBloq = [];
            try {
                $ph = [];
                $pr = [];
                foreach ($turmaIds as $i => $id) {
                    $k = ':bloq_tid_lookup_' . $i;
                    $ph[] = $k;
                    $pr[$k] = $id;
                }
                $stT = $pdo->prepare("SELECT codigo FROM turmas WHERE id IN (" . implode(',', $ph) . ")");
                $stT->execute($pr);
                $codigosBloq = array_values(array_filter(array_map('strval', $stT->fetchAll(PDO::FETCH_COLUMN) ?: []), fn($v) => $v !== ''));
            } catch (Throwable $e) {}
            if ($codigosBloq) {
                $ph = [];
                foreach ($codigosBloq as $i => $codigo) {
                    $k = 'bloq_turma_codigo_' . $i;
                    $ph[] = ':' . $k;
                    $paramsBloqueios[$k] = $codigo;
                }
                $whereBloqueiosUser[] = "u.`codigo_turma` IN (" . implode(',', $ph) . ")";
            }
        } else {
            $ph = [];
            foreach ($turmaIds as $i => $id) {
                $k = 'bloq_turma_id_' . $i;
                $ph[] = ':' . $k;
                $paramsBloqueios[$k] = $id;
            }
            $whereBloqueiosUser[] = "u.`$turmaColTop` IN (" . implode(',', $ph) . ")";
        }
    }
    $sqlBloqueiosMes = "
        SELECT DATE_FORMAT(b.bloqueio_em, '%Y-%m') AS ano_mes,
               COUNT(*) AS total
        FROM (
            SELECT u.id,
                   COALESCE(u.bloqueado_em, MIN(CASE WHEN t.id IS NOT NULL THEN ut.created_at END)) AS bloqueio_em
            FROM users u
            LEFT JOIN user_tags ut ON ut.user_id = u.id
            LEFT JOIN tags t ON t.id = ut.tag_id
                AND UPPER(REPLACE(REPLACE(t.nome, ' ', '_'), '-', '_')) IN ('BLOQUEAR', 'BLOQUEADO')
            WHERE " . implode(' AND ', $whereBloqueiosUser) . "
            GROUP BY u.id, u.bloqueado_em
        ) b
        WHERE " . implode(' AND ', $whereBloqueiosData) . "
        GROUP BY DATE_FORMAT(b.bloqueio_em, '%Y-%m')
        ORDER BY ano_mes ASC
    ";
    $stmt = $pdo->prepare($sqlBloqueiosMes);
    $stmt->execute($paramsBloqueios);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $anoMes = (string)($r['ano_mes'] ?? '');
        if ($anoMes === '') continue;
        $bloqueiosPorMes[$anoMes] = ($bloqueiosPorMes[$anoMes] ?? 0) + (int)($r['total'] ?? 0);
    }

    ksort($bloqueiosPorMes);
    foreach ($bloqueiosPorMes as $anoMes => $qtdBloqueios) {
        [$ano, $mes] = array_pad(explode('-', (string)$anoMes, 2), 2, '');
        $bloqueiosMesLabels[] = ($mes !== '' && $ano !== '') ? ($mes . '/' . $ano) : (string)$anoMes;
        $bloqueiosMesData[] = (int)$qtdBloqueios;
        $totalBloqueiosPeriodo += (int)$qtdBloqueios;
    }
} catch (Throwable $e) {}

$totalAulasConta = (int)$pdo->query("
    SELECT COUNT(*) FROM lessons WHERE conta_para_conclusao = 1 AND ativo = 1
")->fetchColumn();

if ($totalAulasConta > 0) {
    $completionFrom = "
        (
            SELECT
                u.id AS user_id,
                MAX(COALESCE(lp.completed_at, lp.created_at)) AS event_at,
                COUNT(DISTINCT l.id) AS qtd
            FROM users u
            JOIN lesson_progress lp ON lp.user_id = u.id AND lp.status = 'completed'
            JOIN lessons l ON l.id = lp.lesson_id AND l.ativo = 1 AND l.conta_para_conclusao = 1
            GROUP BY u.id
            HAVING qtd >= " . (int)$totalAulasConta . "
        ) done
        JOIN users u ON u.id = done.user_id
    ";
    $dashLineCharts['completed'] = [
        'title' => 'Concluiram o curso',
        'suffix' => ' aluno(s)',
        'color' => '#22c55e',
        'series' => dash_all_period_series($pdo, $completionFrom, 'done.event_at', 'COUNT(DISTINCT done.user_id)', 'completed', $dataDe, $dataAte, $lineTurmaCol, $turmaIds, $codigosTurmaFiltro),
    ];
    $dashLineCharts['progress_sum'] = [
        'title' => 'Avanco percentual somado',
        'suffix' => ' ponto(s) percentuais',
        'color' => '#f59e0b',
        'series' => dash_all_period_series(
            $pdo,
            "lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id AND l.ativo = 1 AND l.conta_para_conclusao = 1 JOIN users u ON u.id = lp.user_id",
            'COALESCE(lp.completed_at, lp.created_at)',
            'SUM(100 / ' . (int)$totalAulasConta . ')',
            'progress_sum',
            $dataDe,
            $dataAte,
            $lineTurmaCol,
            $turmaIds,
            $codigosTurmaFiltro
        ),
    ];
} else {
    $emptyPeriods = ['daily' => ['labels' => [], 'data' => []], 'monthly' => ['labels' => [], 'data' => []], 'yearly' => ['labels' => [], 'data' => []]];
    $dashLineCharts['completed'] = ['title' => 'Concluiram o curso', 'suffix' => ' aluno(s)', 'color' => '#22c55e', 'series' => $emptyPeriods];
    $dashLineCharts['progress_sum'] = ['title' => 'Avanco percentual somado', 'suffix' => ' ponto(s) percentuais', 'color' => '#f59e0b', 'series' => $emptyPeriods];
}

$dashLineCharts['certificates'] = [
    'title' => 'Geraram certificado',
    'suffix' => ' certificado(s)',
    'color' => '#a855f7',
    'series' => dash_all_period_series($pdo, "certificates c JOIN users u ON u.id = c.user_id", 'c.emitido_em', 'COUNT(*)', 'certificates', $dataDe, $dataAte, $lineTurmaCol, $turmaIds, $codigosTurmaFiltro),
];
foreach ([
    'live_access' => ['tipo' => 'acessou', 'title' => 'Acessaram live', 'color' => '#60a5fa'],
    'live_offer' => ['tipo' => 'oferta', 'title' => 'Chegaram na oferta', 'color' => '#fbbf24'],
    'live_buy_click' => ['tipo' => 'compra', 'title' => 'Clicaram no botao de compra', 'color' => '#34d399'],
] as $chartKey => $cfgLive) {
    $dashLineCharts[$chartKey] = [
        'title' => $cfgLive['title'],
        'suffix' => ' aluno(s)',
        'color' => $cfgLive['color'],
        'series' => ['daily' => ['labels' => [], 'data' => []], 'monthly' => ['labels' => [], 'data' => []], 'yearly' => ['labels' => [], 'data' => []]],
    ];
    foreach (['daily', 'monthly', 'yearly'] as $periodLive) {
        $paramsLive = ['tipo_live' => $cfgLive['tipo']];
        $dateExprLive = 'COALESCE(ler.processado_em, ler.recebido_em)';
        $whereLive = dash_event_base_where($dateExprLive, $chartKey . '_' . $periodLive, $dataDe, $dataAte, $lineTurmaCol, $turmaIds, $codigosTurmaFiltro, $paramsLive);
        $whereLive[] = "ler.status = 'processado'";
        $whereLive[] = "ler.user_id IS NOT NULL";
        $whereLive[] = "le.tipo = :tipo_live";
        try {
            $dashLineCharts[$chartKey]['series'][$periodLive] = dash_fetch_series(
                $pdo,
                $periodLive,
                "live_event_recebimentos ler JOIN live_events le ON le.id = ler.event_id JOIN users u ON u.id = ler.user_id",
                $dateExprLive,
                'COUNT(DISTINCT ler.user_id)',
                $whereLive,
                $paramsLive
            );
        } catch (Throwable $e) {
            $dashLineCharts[$chartKey]['series'][$periodLive] = ['labels' => [], 'data' => []];
        }
    }
}

foreach ([
    'group_joined' => [
        'events' => ['WHATSAPP_GRUPO_ENTROU'],
        'title' => 'Entraram no grupo',
        'color' => '#06b6d4',
    ],
    'group_left' => [
        'events' => ['WHATSAPP_GRUPO_SAIU', 'WHATSAPP_GRUPO_REMOVIDO_ADMIN'],
        'title' => 'Sairam do grupo',
        'color' => '#f97316',
    ],
] as $chartKey => $cfgGroup) {
    $dashLineCharts[$chartKey] = [
        'title' => $cfgGroup['title'],
        'suffix' => ' aluno(s)',
        'color' => $cfgGroup['color'],
        'series' => ['daily' => ['labels' => [], 'data' => []], 'monthly' => ['labels' => [], 'data' => []], 'yearly' => ['labels' => [], 'data' => []]],
    ];
    foreach (['daily', 'monthly', 'yearly'] as $periodGroup) {
        $paramsGroup = [];
        $dateExprGroup = 'ge.created_at';
        $whereGroup = dash_event_base_where($dateExprGroup, $chartKey . '_' . $periodGroup, $dataDe, $dataAte, $lineTurmaCol, $turmaIds, $codigosTurmaFiltro, $paramsGroup);
        $whereGroup[] = 'ge.user_id IS NOT NULL';
        $whereGroup[] = 'ge.user_id > 0';
        $whereGroup[] = 'COALESCE(wg.is_ignored, 0) = 0';
        $eventPh = [];
        foreach ($cfgGroup['events'] as $i => $eventName) {
            $k = 'group_event_' . $periodGroup . '_' . $chartKey . '_' . $i;
            $eventPh[] = ':' . $k;
            $paramsGroup[$k] = $eventName;
        }
        $whereGroup[] = 'ge.interpreted_event IN (' . implode(',', $eventPh) . ')';
        try {
            $dashLineCharts[$chartKey]['series'][$periodGroup] = dash_fetch_series(
                $pdo,
                $periodGroup,
                'whatsapp_group_events ge LEFT JOIN whatsapp_groups wg ON wg.group_id = ge.group_id JOIN users u ON u.id = ge.user_id',
                $dateExprGroup,
                'COUNT(DISTINCT ge.user_id)',
                $whereGroup,
                $paramsGroup
            );
        } catch (Throwable $e) {
            $dashLineCharts[$chartKey]['series'][$periodGroup] = ['labels' => [], 'data' => []];
        }
    }
}

$completionLabels = [];
$completionData = [];
for ($i = 1; $i < 60; $i++) {
    $completionLabels[] = (string)$i;
    $completionData[$i] = 0;
}
$completionLabels[] = '60+';
$completionData['60+'] = 0;
$completionTotal = 0;
if ($totalAulasConta > 0) {
    try {
        $sqlCompletion = "
            SELECT
                CASE
                    WHEN DATEDIFF(DATE(MAX(COALESCE(lp.completed_at, lp.created_at))), DATE(u.created_at)) + 1 >= 60 THEN '60+'
                    WHEN DATEDIFF(DATE(MAX(COALESCE(lp.completed_at, lp.created_at))), DATE(u.created_at)) + 1 < 1 THEN '1'
                    ELSE CAST(DATEDIFF(DATE(MAX(COALESCE(lp.completed_at, lp.created_at))), DATE(u.created_at)) + 1 AS CHAR)
                END AS bucket_dia,
                1 AS total
            FROM users u
            JOIN lesson_progress lp ON lp.user_id = u.id AND lp.status = 'completed'
            JOIN lessons l ON l.id = lp.lesson_id AND l.ativo = 1 AND l.conta_para_conclusao = 1
            $whereUsersSql
            GROUP BY u.id, u.created_at
            HAVING COUNT(DISTINCT l.id) >= :total_aulas_completion
            ORDER BY MIN(u.created_at) ASC
        ";
        $completionParams = $paramsUsers;
        $completionParams['total_aulas_completion'] = $totalAulasConta;
        $stmt = $pdo->prepare($sqlCompletion);
        $stmt->execute($completionParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $bucket = (string)($r['bucket_dia'] ?? '');
            if ($bucket === '') continue;
            if ($bucket !== '60+') {
                $n = (int)$bucket;
                if ($n < 1) $bucket = '1';
                elseif ($n >= 60) $bucket = '60+';
                else $bucket = (string)$n;
            }
            $completionData[$bucket] = (int)($completionData[$bucket] ?? 0) + (int)($r['total'] ?? 0);
            $completionTotal += (int)($r['total'] ?? 0);
        }
    } catch (Throwable $e) {}
}
$completionChartData = [];
foreach ($completionLabels as $label) {
    $completionChartData[] = (int)($completionData[$label] ?? 0);
}

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
$alunosComApp = 0;
$alunosLogaramComApp = 0;
try {
    $sqlApp = "SELECT COUNT(DISTINCT u.id) FROM users u JOIN push_devices pd ON pd.user_id=u.id AND pd.installed_at IS NOT NULL";
    if ($whereUsers) $sqlApp .= ' WHERE ' . implode(' AND ', $whereUsers);
    $stmt = $pdo->prepare($sqlApp); $stmt->execute($paramsUsers); $alunosComApp = (int)$stmt->fetchColumn();

    $sqlAppLogin = "SELECT COUNT(DISTINCT u.id) FROM users u JOIN push_devices pd ON pd.user_id=u.id AND pd.installed_at IS NOT NULL WHERE u.last_login_at IS NOT NULL";
    if ($whereUsers) $sqlAppLogin .= ' AND ' . implode(' AND ', $whereUsers);
    $stmt = $pdo->prepare($sqlAppLogin); $stmt->execute($paramsUsers); $alunosLogaramComApp = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
$pctAlunosComApp = $totalAlunos > 0 ? round($alunosComApp / $totalAlunos * 100, 1) : 0;
$pctLogaramComApp = $alunosLogaram > 0 ? round($alunosLogaramComApp / $alunosLogaram * 100, 1) : 0;

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

function dash_count_compras_reais(PDO $pdo, array $whereUsers, array $paramsUsers): int {
    try {
        $pdo->query("SELECT matched_user_id FROM hotmart_sales LIMIT 0");
        $sql = "SELECT COUNT(DISTINCT s.matched_user_id)
                FROM hotmart_sales s
                JOIN users u ON u.id = s.matched_user_id
                WHERE s.matched_user_id IS NOT NULL
                  AND s.status IN ('Aprovado','Completo')";
        if ($whereUsers) $sql .= ' AND ' . implode(' AND ', $whereUsers);
        $st = $pdo->prepare($sql);
        $st->execute($paramsUsers);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
$comprasReais = dash_count_compras_reais($pdo, $whereUsers, $paramsUsers);
$taxaConversaoVendas = $totalAlunos > 0 ? round($comprasReais / $totalAlunos * 100, 1) : 0;
$taxaShowup = $totalAlunos > 0 ? round($liveAcessou / $totalAlunos * 100, 1) : 0;

function dash_table_exists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function dash_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $st->execute([':column' => $column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function dash_vendas_relativas_live(PDO $pdo, array $whereUsers, array $paramsUsers): array {
    $empty = [
        'original' => ['labels' => ['0'], 'days' => [0], 'data' => [0], 'total' => 0],
        'reagendada' => ['labels' => ['0'], 'days' => [0], 'data' => [0], 'total' => 0],
    ];

    if (
        !dash_table_exists($pdo, 'hotmart_sales')
        || !dash_column_exists($pdo, 'hotmart_sales', 'matched_user_id')
        || !dash_column_exists($pdo, 'hotmart_sales', 'transaction_date')
    ) {
        return $empty;
    }

    $hasReagendamentos = dash_table_exists($pdo, 'reagendamentos_live')
        && dash_column_exists($pdo, 'reagendamentos_live', 'old_turma_live_at')
        && dash_column_exists($pdo, 'reagendamentos_live', 'new_turma_live_at');

    $originalCandidates = [];
    if ($hasReagendamentos) {
        $originalCandidates[] = "(SELECT rr.old_turma_live_at
            FROM reagendamentos_live rr
            WHERE rr.user_id = s.matched_user_id
              AND rr.old_turma_live_at IS NOT NULL
            ORDER BY rr.created_at ASC, rr.id ASC
            LIMIT 1)";
    }
    if (dash_column_exists($pdo, 'users', 'turma_live_at')) $originalCandidates[] = 'u.turma_live_at';
    if (dash_column_exists($pdo, 'users', 'data_live')) $originalCandidates[] = 'u.data_live';
    if (!$originalCandidates) return $empty;

    $originalExpr = count($originalCandidates) === 1
        ? $originalCandidates[0]
        : 'COALESCE(' . implode(', ', $originalCandidates) . ')';

    $reagendadaExpr = $hasReagendamentos
        ? "(SELECT rr.new_turma_live_at
            FROM reagendamentos_live rr
            WHERE rr.user_id = s.matched_user_id
              AND rr.new_turma_live_at IS NOT NULL
              AND rr.created_at <= s.transaction_date
            ORDER BY rr.created_at DESC, rr.id DESC
            LIMIT 1)"
        : 'NULL';

    $userWhereSql = $whereUsers ? (' AND ' . implode(' AND ', $whereUsers)) : '';
    $sql = "
        SELECT z.referencia, z.dia_relativo, COUNT(*) AS vendas
        FROM (
            SELECT 'original' AS referencia,
                   DATEDIFF(DATE(original_base.transaction_date), DATE(original_base.live_at)) AS dia_relativo
            FROM (
                SELECT s.transaction_date, $originalExpr AS live_at
                FROM hotmart_sales s
                JOIN users u ON u.id = s.matched_user_id
                WHERE s.matched_user_id IS NOT NULL
                  AND s.transaction_date IS NOT NULL
                  AND LOWER(COALESCE(s.status,'')) IN ('aprovado','completo','approved','complete','paid')
                  $userWhereSql
            ) original_base
            WHERE original_base.live_at IS NOT NULL
            UNION ALL
            SELECT 'reagendada' AS referencia,
                   DATEDIFF(DATE(reagendada_base.transaction_date), DATE(reagendada_base.live_at)) AS dia_relativo
            FROM (
                SELECT s.transaction_date, $reagendadaExpr AS live_at
                FROM hotmart_sales s
                JOIN users u ON u.id = s.matched_user_id
                WHERE s.matched_user_id IS NOT NULL
                  AND s.transaction_date IS NOT NULL
                  AND LOWER(COALESCE(s.status,'')) IN ('aprovado','completo','approved','complete','paid')
                  $userWhereSql
            ) reagendada_base
            WHERE reagendada_base.live_at IS NOT NULL
        ) z
        GROUP BY z.referencia, z.dia_relativo
        ORDER BY z.referencia, z.dia_relativo
    ";

    try {
        // Os mesmos placeholders aparecem nas duas metades do UNION.
        // Emulação permite reutilizá-los sem alterar os filtros do dashboard.
        $oldEmulate = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($paramsUsers);
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $oldEmulate);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $counts = ['original' => [], 'reagendada' => []];
    foreach ($rows as $row) {
        $ref = (string)($row['referencia'] ?? '');
        if (!isset($counts[$ref])) continue;
        $counts[$ref][(int)$row['dia_relativo']] = (int)$row['vendas'];
    }

    foreach ($counts as $ref => $byDay) {
        if (!$byDay) continue;
        $minDay = min(0, min(array_keys($byDay)));
        $maxDay = max(0, max(array_keys($byDay)));
        $days = range($minDay, $maxDay);
        $data = [];
        $labels = [];
        foreach ($days as $day) {
            $labels[] = $day > 0 ? '+' . $day : (string)$day;
            $data[] = (int)($byDay[$day] ?? 0);
        }
        $empty[$ref] = [
            'labels' => $labels,
            'days' => $days,
            'data' => $data,
            'total' => array_sum($data),
        ];
    }

    return $empty;
}

$vendasRelativasLive = dash_vendas_relativas_live($pdo, $whereUsers, $paramsUsers);

function dash_compras_por_certificados(PDO $pdo, array $whereUsers, array $paramsUsers): array {
    try {
        $pdo->query("SELECT matched_user_id FROM hotmart_sales LIMIT 0");
        $pdo->query("SELECT user_id FROM certificates LIMIT 0");
        $sql = "SELECT
                    COUNT(DISTINCT c.user_id) AS certificados,
                    COUNT(DISTINCT CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM hotmart_sales s
                            WHERE s.matched_user_id = c.user_id
                              AND s.status IN ('Aprovado','Completo')
                            LIMIT 1
                        )
                        THEN c.user_id
                    END) AS com_compra
                FROM certificates c
                JOIN users u ON u.id = c.user_id
                WHERE c.user_id IS NOT NULL";
        if ($whereUsers) $sql .= ' AND ' . implode(' AND ', $whereUsers);
        $st = $pdo->prepare($sql);
        $st->execute($paramsUsers);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $certificados = (int)($row['certificados'] ?? 0);
        $comCompra = (int)($row['com_compra'] ?? 0);
        return [
            'certificados' => $certificados,
            'com_compra' => $comCompra,
            'sem_compra' => max(0, $certificados - $comCompra),
        ];
    } catch (Throwable $e) {
        return ['certificados' => 0, 'com_compra' => 0, 'sem_compra' => 0];
    }
}
$certCompradores = dash_compras_por_certificados($pdo, $whereUsers, $paramsUsers);
$certificadosUnicos = (int)$certCompradores['certificados'];
$certificadosComCompra = (int)$certCompradores['com_compra'];
$certificadosSemCompra = (int)$certCompradores['sem_compra'];
$pctCertificadosComCompra = $certificadosUnicos > 0 ? round($certificadosComCompra / $certificadosUnicos * 100, 1) : 0;
$pctCertificadosSemCompra = $certificadosUnicos > 0 ? round($certificadosSemCompra / $certificadosUnicos * 100, 1) : 0;

function dash_compras_por_concluintes(PDO $pdo, int $totalAulasConta, string $whereUsersSql, array $paramsUsers): array {
    if ($totalAulasConta <= 0) {
        return ['concluintes' => 0, 'com_compra' => 0, 'sem_compra' => 0];
    }
    try {
        $pdo->query("SELECT matched_user_id FROM hotmart_sales LIMIT 0");
        $sql = "
            SELECT
                COUNT(*) AS concluintes,
                SUM(CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM hotmart_sales s
                        WHERE s.matched_user_id = done.user_id
                          AND s.status IN ('Aprovado','Completo')
                        LIMIT 1
                    )
                    THEN 1 ELSE 0
                END) AS com_compra
            FROM (
                SELECT u.id AS user_id
                FROM users u
                JOIN lesson_progress lp ON lp.user_id = u.id AND lp.status = 'completed'
                JOIN lessons l ON l.id = lp.lesson_id AND l.ativo = 1 AND l.conta_para_conclusao = 1
                $whereUsersSql
                GROUP BY u.id
                HAVING COUNT(DISTINCT l.id) >= :total_aulas_concluintes_compra
            ) done
        ";
        $params = $paramsUsers;
        $params['total_aulas_concluintes_compra'] = $totalAulasConta;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $concluintes = (int)($row['concluintes'] ?? 0);
        $comCompra = (int)($row['com_compra'] ?? 0);
        return [
            'concluintes' => $concluintes,
            'com_compra' => $comCompra,
            'sem_compra' => max(0, $concluintes - $comCompra),
        ];
    } catch (Throwable $e) {
        return ['concluintes' => 0, 'com_compra' => 0, 'sem_compra' => 0];
    }
}
$comprasConcluintes = dash_compras_por_concluintes($pdo, $totalAulasConta, $whereUsersSql, $paramsUsers);
$concluintesUnicos = (int)$comprasConcluintes['concluintes'];
$concluintesComCompra = (int)$comprasConcluintes['com_compra'];
$concluintesSemCompra = (int)$comprasConcluintes['sem_compra'];
$pctConcluintesComCompra = $concluintesUnicos > 0 ? round($concluintesComCompra / $concluintesUnicos * 100, 1) : 0;
$pctConcluintesSemCompra = $concluintesUnicos > 0 ? round($concluintesSemCompra / $concluintesUnicos * 100, 1) : 0;

function dash_status_por_compradores(PDO $pdo, int $totalAulasConta, array $whereUsers, array $paramsUsers): array {
    try {
        $pdo->query("SELECT matched_user_id FROM hotmart_sales LIMIT 0");
        $pdo->query("SELECT user_id FROM certificates LIMIT 0");
        $sql = "
            SELECT
                COUNT(*) AS compradores,
                SUM(CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM certificates c
                        WHERE c.user_id = buyers.user_id
                        LIMIT 1
                    )
                    THEN 1 ELSE 0
                END) AS com_certificado,
                " . ($totalAulasConta > 0 ? "
                SUM(CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM lesson_progress lp
                        JOIN lessons l ON l.id = lp.lesson_id AND l.ativo = 1 AND l.conta_para_conclusao = 1
                        WHERE lp.user_id = buyers.user_id
                          AND lp.status = 'completed'
                        GROUP BY lp.user_id
                        HAVING COUNT(DISTINCT l.id) >= :total_aulas_compradores
                    )
                    THEN 1 ELSE 0
                END)" : "0") . " AS concluintes
            FROM (
                SELECT DISTINCT s.matched_user_id AS user_id
                FROM hotmart_sales s
                JOIN users u ON u.id = s.matched_user_id
                WHERE s.matched_user_id IS NOT NULL
                  AND s.status IN ('Aprovado','Completo')
                  " . ($whereUsers ? ' AND ' . implode(' AND ', $whereUsers) : '') . "
            ) buyers
        ";
        $params = $paramsUsers;
        if ($totalAulasConta > 0) {
            $params['total_aulas_compradores'] = $totalAulasConta;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $compradores = (int)($row['compradores'] ?? 0);
        $concluintes = (int)($row['concluintes'] ?? 0);
        $comCertificado = (int)($row['com_certificado'] ?? 0);
        return [
            'compradores' => $compradores,
            'concluintes' => $concluintes,
            'nao_concluintes' => max(0, $compradores - $concluintes),
            'com_certificado' => $comCertificado,
            'sem_certificado' => max(0, $compradores - $comCertificado),
        ];
    } catch (Throwable $e) {
        return ['compradores' => 0, 'concluintes' => 0, 'nao_concluintes' => 0, 'com_certificado' => 0, 'sem_certificado' => 0];
    }
}
$statusCompradores = dash_status_por_compradores($pdo, $totalAulasConta, $whereUsers, $paramsUsers);
$compradoresBase = (int)$statusCompradores['compradores'];
$compradoresConcluintes = (int)$statusCompradores['concluintes'];
$compradoresNaoConcluintes = (int)$statusCompradores['nao_concluintes'];
$compradoresComCertificadoBase = (int)$statusCompradores['com_certificado'];
$compradoresSemCertificadoBase = (int)$statusCompradores['sem_certificado'];
$pctCompradoresConcluintes = $compradoresBase > 0 ? round($compradoresConcluintes / $compradoresBase * 100, 1) : 0;
$pctCompradoresNaoConcluintes = $compradoresBase > 0 ? round($compradoresNaoConcluintes / $compradoresBase * 100, 1) : 0;
$pctCompradoresComCertificadoBase = $compradoresBase > 0 ? round($compradoresComCertificadoBase / $compradoresBase * 100, 1) : 0;
$pctCompradoresSemCertificadoBase = $compradoresBase > 0 ? round($compradoresSemCertificadoBase / $compradoresBase * 100, 1) : 0;

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
    foreach (array_keys($paramsSemTurma) as $k) {
        if (strpos((string)$k, 'turma_id') === 0 || strpos((string)$k, 'turma_codigo') === 0) {
            unset($paramsSemTurma[$k]);
        }
    }
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

    // Compras reais importadas da Hotmart
    try {
        $sqlCompraReal = "SELECT u.`$turmaCol` AS tid, COUNT(DISTINCT s.matched_user_id) AS n
                          FROM hotmart_sales s
                          JOIN users u ON u.id = s.matched_user_id
                          WHERE s.matched_user_id IS NOT NULL
                            AND s.status IN ('Aprovado','Completo')$extraWhere
                          GROUP BY u.`$turmaCol`";
        $st = $pdo->prepare($sqlCompraReal); $st->execute($paramsSemTurma);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
            $barTurmaData[$tid]['compras_reais'] = (int)$r['n'];
        }
    } catch (Throwable $e) {}

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

    // WhatsApp: alunos identificados pelo monitor/tag, agrupados pela turma do aluno.
    try {
        $sqlWg = "SELECT u.`$turmaCol` AS tid, COUNT(DISTINCT x.user_id) AS n
                  FROM (
                        SELECT ge.user_id
                          FROM whatsapp_group_events ge
                          LEFT JOIN whatsapp_groups wg ON wg.group_id = ge.group_id
                         WHERE ge.interpreted_event = 'WHATSAPP_GRUPO_ENTROU'
                           AND ge.user_id IS NOT NULL
                           AND ge.user_id > 0
                           AND COALESCE(wg.is_ignored, 0) = 0
                        UNION
                        SELECT ut.user_id
                          FROM user_tags ut
                          JOIN tags t ON t.id = ut.tag_id
                         WHERE t.nome = 'WHATSAPP_GRUPO_ENTROU'
                           AND ut.user_id IS NOT NULL
                           AND ut.user_id > 0
                  ) x
                  JOIN users u ON u.id = x.user_id
                  WHERE 1=1$extraWhere
                  GROUP BY u.`$turmaCol`";
        $st = $pdo->prepare($sqlWg); $st->execute($paramsSemTurma);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (string)($r['tid'] ?? ''); if (!isset($barTurmaData[$tid])) $barTurmaData[$tid] = [];
            $barTurmaData[$tid]['grupo_entrou'] = (int)$r['n'];
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
$turmasSelecionadasChart = ($turmaCol === 'codigo_turma')
    ? array_values($codigosTurmaFiltro)
    : array_map('strval', $turmaIds);

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
if ($liveCompra  > 0) $funnelData[] = ['label' => 'Clicou CTA',           'count' => $liveCompra];
if ($comprasReais > 0) $funnelData[] = ['label' => 'Comprou',             'count' => $comprasReais];

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
$groupRankingRows = [];
$groupRankingHistorico = [];
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

// ========================
// 7) RANKING ENTRADAS EM GRUPOS WHATSAPP
// ========================
try {
    $pdo->query("SELECT 1 FROM whatsapp_group_events LIMIT 0");
    $groupRankingRows = $pdo->query("
        SELECT
            CASE
                WHEN ge.user_id IS NOT NULL AND ge.user_id > 0 THEN CONCAT('u:', ge.user_id)
                WHEN COALESCE(NULLIF(TRIM(ge.participant_phone), ''), '') <> '' THEN CONCAT('p:', TRIM(ge.participant_phone))
                WHEN COALESCE(NULLIF(TRIM(ge.participant_id), ''), '') <> '' THEN CONCAT('pid:', TRIM(ge.participant_id))
                ELSE CONCAT('e:', ge.id)
            END AS ranking_key,
            u.id,
            u.nome,
            u.email,
            COALESCE(NULLIF(TRIM(u.telefone), ''), MAX(NULLIF(TRIM(ge.participant_phone), ''))) AS telefone,
            MAX(ge.participant_phone) AS participant_phone,
            MAX(ge.participant_id) AS participant_id,
            COUNT(*) AS qtd_entradas,
            COUNT(DISTINCT ge.group_id) AS grupos_distintos,
            MIN(ge.created_at) AS primeira_entrada,
            MAX(ge.created_at) AS ultima_entrada
        FROM whatsapp_group_events ge
        LEFT JOIN users u ON u.id = ge.user_id
        LEFT JOIN whatsapp_groups wg ON wg.group_id = ge.group_id
        WHERE ge.interpreted_event = 'WHATSAPP_GRUPO_ENTROU'
          AND COALESCE(wg.is_ignored, 0) = 0
        GROUP BY ranking_key, u.id, u.nome, u.email, u.telefone
        ORDER BY qtd_entradas DESC, grupos_distintos DESC, ultima_entrada DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($groupRankingRows) {
        $keys = [];
        foreach ($groupRankingRows as $row) {
            $key = trim((string)($row['ranking_key'] ?? ''));
            if ($key !== '') $keys[] = $key;
        }
        $keys = array_values(array_unique($keys));
        if ($keys) {
            $in = implode(',', array_map([$pdo, 'quote'], $keys));
            $histRows = $pdo->query("
                SELECT
                    x.*
                FROM (
                    SELECT
                        CASE
                            WHEN ge.user_id IS NOT NULL AND ge.user_id > 0 THEN CONCAT('u:', ge.user_id)
                            WHEN COALESCE(NULLIF(TRIM(ge.participant_phone), ''), '') <> '' THEN CONCAT('p:', TRIM(ge.participant_phone))
                            WHEN COALESCE(NULLIF(TRIM(ge.participant_id), ''), '') <> '' THEN CONCAT('pid:', TRIM(ge.participant_id))
                            ELSE CONCAT('e:', ge.id)
                        END AS ranking_key,
                        ge.*,
                        wg.group_name,
                        wg.picture_url,
                        wg.is_ignored
                    FROM whatsapp_group_events ge
                    LEFT JOIN whatsapp_groups wg ON wg.group_id = ge.group_id
                    WHERE ge.interpreted_event IN ('WHATSAPP_GRUPO_ENTROU','WHATSAPP_GRUPO_SAIU','WHATSAPP_GRUPO_REMOVIDO_ADMIN')
                      AND COALESCE(wg.is_ignored, 0) = 0
                ) x
                WHERE x.ranking_key IN ($in)
                ORDER BY x.ranking_key ASC, x.created_at DESC, x.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($histRows as $h) {
                $groupRankingHistorico[(string)$h['ranking_key']][] = $h;
            }
        }
    }
} catch (Throwable $e) {
    $groupRankingRows = [];
    $groupRankingHistorico = [];
}

$emailDashboard = ['sent'=>0,'delivered'=>0,'opened'=>0,'clicked'=>0,'bounced'=>0,'complaints'=>0,'unsubscribed'=>0];
try {
    require_once __DIR__ . '/../app/email_marketing.php';
    email_marketing_ensure_schema($pdo);
    $emailDashboard = $pdo->query("SELECT COUNT(*) sent,SUM(delivered_at IS NOT NULL) delivered,SUM(first_opened_at IS NOT NULL) opened,SUM(first_clicked_at IS NOT NULL) clicked,SUM(status='bounced') bounced,SUM(status='complaint') complaints,SUM(status='unsubscribed') unsubscribed FROM email_messages")->fetch(PDO::FETCH_ASSOC) ?: $emailDashboard;
} catch (Throwable $e) {}

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
    <div class="filter-group" style="min-width:230px;position:relative">
        <label>Turma</label>
        <button type="button" id="dashTurmaBtn" onclick="dashTurmaToggle(event)"
                style="width:100%;height:34px;padding:6px 10px;border-radius:var(--r);border:1px solid var(--border-light);background:var(--bg);color:var(--text);font-size:12px;text-align:left;display:flex;align-items:center;justify-content:space-between;gap:8px">
            <span id="dashTurmaLabel" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Todas</span>
            <span id="dashTurmaArrow" style="color:var(--muted);font-size:10px">▼</span>
        </button>
        <div id="dashTurmaHidden"></div>
        <div id="dashTurmaPanel" style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r);box-shadow:0 10px 28px rgba(0,0,0,.45);padding:8px;z-index:80;max-height:320px;overflow:hidden;flex-direction:column">
            <input type="text" id="dashTurmaSearch" placeholder="Buscar turma..." oninput="dashTurmaRender()"
                   style="height:30px;padding:5px 8px;font-size:12px;margin-bottom:6px">
            <div style="display:flex;gap:6px;margin-bottom:6px">
                <button type="button" class="btn btn-ghost btn-xs" style="flex:1" onclick="dashTurmaAll(true)">Todas</button>
                <button type="button" class="btn btn-ghost btn-xs" style="flex:1" onclick="dashTurmaAll(false)">Limpar</button>
            </div>
            <div id="dashTurmaList" style="overflow-y:auto;max-height:230px"></div>
        </div>
    </div>
    <div class="filter-group" style="display:none">
        <label for="turma_id">Turma</label>
        <select id="turma_id" multiple size="4" disabled>
            <?php foreach ($turmas as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $turmaIds, true) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['codigo'] . (empty($t['nome']) ? '' : ' – ' . $t['nome'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div style="font-size:10px;color:var(--muted);margin-top:4px">Ctrl/Cmd + clique para selecionar mais de uma</div>
    </div>
    <?php endif; ?>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <a href="<?= htmlspecialchars(BASE_URL_ADMIN . '/index.php') ?>" class="reset-link">Limpar</a>
    </div>
</form>

<?php if ($turmas): ?>
<script>
const DASH_TURMAS = <?= json_encode(array_map(static function($t) {
    return [
        'id' => (int)($t['id'] ?? 0),
        'label' => (string)($t['codigo'] ?? '') . (empty($t['nome']) ? '' : ' - ' . (string)$t['nome']),
    ];
}, $turmas), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let dashTurmaSelected = <?= json_encode(array_values(array_map('intval', $turmaIds))) ?>;
function dashTurmaEsc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function dashTurmaToggle(ev) {
    if (ev) ev.stopPropagation();
    const panel = document.getElementById('dashTurmaPanel');
    const open = panel.style.display === 'flex';
    panel.style.display = open ? 'none' : 'flex';
    document.getElementById('dashTurmaArrow').textContent = open ? '▼' : '▲';
    if (!open) {
        document.getElementById('dashTurmaSearch').value = '';
        dashTurmaRender();
        setTimeout(() => document.getElementById('dashTurmaSearch').focus(), 30);
    }
}
function dashTurmaRender() {
    const q = (document.getElementById('dashTurmaSearch')?.value || '').trim().toLowerCase();
    const list = document.getElementById('dashTurmaList');
    const itens = DASH_TURMAS.filter(t => !q || t.label.toLowerCase().includes(q));
    if (!itens.length) {
        list.innerHTML = '<div style="padding:10px;color:var(--muted);font-size:12px;text-align:center">Nenhuma turma encontrada</div>';
        return;
    }
    list.innerHTML = itens.map(t => {
        const checked = dashTurmaSelected.includes(t.id);
        return `<label style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;cursor:pointer;font-size:12px;color:var(--text)">
            <input type="checkbox" ${checked ? 'checked' : ''} onchange="dashTurmaPick(${t.id})" style="accent-color:var(--primary)">
            <span style="flex:1">${dashTurmaEsc(t.label)}</span>
        </label>`;
    }).join('');
}
function dashTurmaPick(id) {
    const i = dashTurmaSelected.indexOf(id);
    if (i >= 0) dashTurmaSelected.splice(i, 1);
    else dashTurmaSelected.push(id);
    dashTurmaSync();
    dashTurmaRender();
}
function dashTurmaAll(all) {
    dashTurmaSelected = all ? DASH_TURMAS.map(t => t.id) : [];
    dashTurmaSync();
    dashTurmaRender();
}
function dashTurmaSync() {
    const hidden = document.getElementById('dashTurmaHidden');
    hidden.innerHTML = dashTurmaSelected.map(id => `<input type="hidden" name="turma_id[]" value="${id}">`).join('');
    const label = document.getElementById('dashTurmaLabel');
    if (!dashTurmaSelected.length) {
        label.textContent = 'Todas';
    } else if (dashTurmaSelected.length === 1) {
        const item = DASH_TURMAS.find(t => t.id === dashTurmaSelected[0]);
        label.textContent = item ? item.label : '1 turma selecionada';
    } else {
        label.textContent = dashTurmaSelected.length + ' turmas selecionadas';
    }
}
document.addEventListener('click', function(ev) {
    const panel = document.getElementById('dashTurmaPanel');
    const wrap = document.getElementById('dashTurmaBtn')?.parentElement;
    if (panel && wrap && !wrap.contains(ev.target)) {
        panel.style.display = 'none';
        document.getElementById('dashTurmaArrow').textContent = '▼';
    }
});
dashTurmaSync();
dashTurmaRender();
</script>
<?php endif; ?>

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

    <div class="kpi" style="border-color:rgba(250,204,21,.3)">
        <div class="kpi-icon" style="background:rgba(250,204,21,.15);color:#facc15">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 18h6"/></svg>
        </div>
        <div class="kpi-label">Alunos com aplicativo</div>
        <div class="kpi-value"><?= number_format($alunosComApp) ?></div>
        <div class="kpi-sub"><?= $pctAlunosComApp ?>% dos alunos inscritos</div>
    </div>

    <div class="kpi" style="border-color:rgba(34,197,94,.3)">
        <div class="kpi-icon" style="background:rgba(34,197,94,.15);color:#22c55e">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 12l2.5 2.5L16 9"/></svg>
        </div>
        <div class="kpi-label">Logaram e instalaram</div>
        <div class="kpi-value"><?= number_format($alunosLogaramComApp) ?></div>
        <div class="kpi-sub"><?= $pctLogaramComApp ?>% dos alunos que já logaram</div>
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
    <div class="kpi" style="border-color:rgba(52,211,153,.3)">
        <div class="kpi-icon" style="background:rgba(52,211,153,.15);color:#34d399">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3z"/><path d="M8 13l3 3 5-8"/></svg>
        </div>
        <div class="kpi-label">Conversão em vendas</div>
        <div class="kpi-value"><?= number_format($taxaConversaoVendas, 1, ',', '.') ?>%</div>
        <div class="kpi-sub"><?= number_format($comprasReais) ?> comprador(es)</div>
    </div>

    <div class="kpi" style="border-color:rgba(96,165,250,.3)">
        <div class="kpi-icon" style="background:rgba(96,165,250,.15);color:#60a5fa">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
        </div>
        <div class="kpi-label">Showup</div>
        <div class="kpi-value"><?= number_format($taxaShowup, 1, ',', '.') ?>%</div>
        <div class="kpi-sub">viram a live</div>
    </div>
</div>

<style>
.dash-period-switch {
    display: inline-flex;
    border: 1px solid var(--border);
    border-radius: 7px;
    overflow: hidden;
    background: rgba(255,255,255,.02);
}
.dash-period-switch button {
    border: 0;
    background: transparent;
    color: var(--muted);
    padding: 5px 9px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}
.dash-period-switch button.active {
    background: var(--primary);
    color: #111827;
}
.dash-first-only {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    user-select: none;
}
.dash-first-only input {
    margin: 0;
    accent-color: var(--primary);
}
.dash-line-panel {
    position: relative;
}
.dash-line-chart-wrap {
    height: 240px;
    cursor: zoom-in;
}
.dash-line-close {
    display: none;
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 10001;
    width: 34px;
    height: 34px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--bg-card);
    color: var(--text);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
}
.dash-line-panel.is-fullscreen {
    position: fixed;
    inset: 14px;
    z-index: 10000;
    margin: 0 !important;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 24px 90px rgba(0,0,0,.65);
}
.dash-line-panel.is-fullscreen .dash-line-chart-wrap {
    flex: 1;
    height: auto !important;
    min-height: 0;
    cursor: zoom-out;
}
.dash-line-panel.is-fullscreen .dash-line-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
body.dash-chart-fullscreen {
    overflow: hidden;
}
.live-relative-options {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.live-relative-range {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 6px 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: rgba(255,255,255,.02);
}
.live-relative-range label {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin: 0;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
}
.live-relative-range input {
    width: 68px;
    height: 30px;
    margin: 0;
    padding: 4px 7px;
    border: 1px solid var(--border-light);
    border-radius: 6px;
    background: var(--bg);
    color: var(--text);
    font-size: 12px;
}
.live-relative-range button {
    height: 30px;
    padding: 4px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: transparent;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}
.live-relative-range button:hover {
    color: var(--text);
    border-color: var(--border-light);
}
.live-relative-option {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    user-select: none;
}
.live-relative-option input {
    margin: 0;
    accent-color: var(--primary);
}
.live-relative-chart-wrap {
    height: 320px;
    min-height: 260px;
}
.live-relative-legend {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 12px;
    color: var(--muted);
    font-size: 11px;
}
.live-relative-legend strong {
    color: var(--text);
}
</style>

<div class="panel mb-4">
    <div class="panel-title" style="display:flex;align-items:center;gap:14px;justify-content:space-between;flex-wrap:wrap">
        <div>
            <span>Vendas por dia em relação à live</span>
            <div style="font-size:11px;color:var(--muted);font-weight:400;margin-top:4px">
                Dia 0 = dia da live; negativos = antes; positivos = depois
            </div>
        </div>
        <div class="live-relative-options" role="group" aria-label="Data de referência da live">
            <label class="live-relative-option">
                <input type="checkbox" data-live-reference="original" checked>
                Dia da live
            </label>
            <label class="live-relative-option">
                <input type="checkbox" data-live-reference="reagendada">
                Dia da live reagendada
            </label>
        </div>
    </div>
    <div class="live-relative-range" style="margin-bottom:14px">
        <label for="liveRelativeNegative">
            Máximo negativo
            <input type="number" id="liveRelativeNegative" min="0" step="1" value="30" inputmode="numeric">
        </label>
        <label for="liveRelativePositive">
            Máximo positivo
            <input type="number" id="liveRelativePositive" min="0" step="1" value="30" inputmode="numeric">
        </label>
        <button type="button" id="liveRelativeShowAll">Mostrar tudo</button>
        <span style="color:var(--muted);font-size:11px">Intervalo exibido: <strong id="liveRelativeRangeLabel" style="color:var(--text)">-30 a +30</strong></span>
    </div>
    <div class="live-relative-chart-wrap">
        <canvas id="chartVendasRelativasLive"></canvas>
    </div>
    <div class="live-relative-legend">
        <span>Referência: <strong id="liveRelativeReferenceLabel">live original</strong></span>
        <span>Vendas contabilizadas: <strong id="liveRelativeTotal">0</strong></span>
        <span id="liveRelativeEmpty" style="display:none">Sem vendas vinculadas a esta referência nos filtros atuais.</span>
    </div>
</div>

<!-- CHART: Indice de engajamento por cadastro -->
<div class="panel mb-4">
    <div class="panel-title" style="display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap">
        <div>
            <span>Indice de engajamento dos leads</span>
            <div style="font-size:11px;color:var(--muted);font-weight:400;margin-top:4px">
                Classificacao por aluno cadastrado no periodo: frio, morno, quente e pelando.
            </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap">
            <div class="dash-period-switch" data-engagement-mode>
                <button type="button" class="active" data-mode="count">Quantidade</button>
                <button type="button" data-mode="percent">%</button>
            </div>
            <div class="dash-period-switch" data-engagement-period>
                <button type="button" class="active" data-period="daily">Dia</button>
                <button type="button" data-period="weekly">Semana</button>
                <button type="button" data-period="monthly">Mes</button>
                <button type="button" data-period="quarterly">Trimestre</button>
                <button type="button" data-period="semester">Semestre</button>
                <button type="button" data-period="yearly">Ano</button>
            </div>
        </div>
    </div>
    <div style="height:360px;position:relative">
        <canvas id="chartLeadEngagement"></canvas>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;color:var(--muted);font-size:11px">
        <span><strong style="color:#94a3b8">Frio</strong>: cadastrou e nao teve outros sinais.</span>
        <span><strong style="color:#38bdf8">Morno</strong>: logou no sistema ou entrou/logou.</span>
        <span><strong style="color:#f59e0b">Quente</strong>: viu aulas 1-4, clicou oferta ou chegou a 90%.</span>
        <span><strong style="color:#ef4444">Pelando</strong>: viu aulas 1-6, entrou na live, ficou ate oferta e gerou certificado.</span>
    </div>
</div>

<!-- CHARTS: Atividade diaria/mensal/anual -->
<div class="grid-2 mb-4">
    <?php foreach ($dashLineCharts as $chartKey => $chartCfg): ?>
        <div class="panel dash-line-panel" data-chart-panel="<?= htmlspecialchars($chartKey) ?>">
            <button type="button" class="dash-line-close" aria-label="Fechar grafico em tela cheia">X</button>
            <div class="panel-title" style="display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap">
                <span><?= htmlspecialchars((string)$chartCfg['title']) ?></span>
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap">
                    <?php if (!empty($chartCfg['first_only_enabled'])): ?>
                        <label class="dash-first-only" title="Conta cada aluno somente na data do primeiro evento registrado">
                            <input type="checkbox" data-first-only="<?= htmlspecialchars($chartKey) ?>">
                            Somente primeiro evento
                        </label>
                    <?php endif; ?>
                    <div class="dash-period-switch" data-chart="<?= htmlspecialchars($chartKey) ?>">
                        <button type="button" class="active" data-period="daily">Diario</button>
                        <button type="button" data-period="monthly">Mensal</button>
                        <button type="button" data-period="yearly">Anual</button>
                    </div>
                </div>
            </div>
            <div class="dash-line-chart-wrap">
                <canvas id="dashLine_<?= htmlspecialchars($chartKey) ?>"></canvas>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- CHART: Dispositivos usados para assistir às aulas -->
<div class="panel mb-4">
    <div class="panel-title" style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap">
        <div>
            <span>Dispositivos usados nas aulas</span>
            <div style="font-size:11px;color:var(--muted);font-weight:400;margin-top:4px">
                Último dispositivo registrado por aluno no período selecionado
            </div>
        </div>
        <?php if ($deviceTotalStudents > 0): ?>
            <div style="font-size:12px;color:var(--muted);text-align:right">
                <strong style="display:block;color:var(--text);font-size:18px"><?= number_format($deviceMobilePct, 1, ',', '.') ?>%</strong>
                em Android, iPhone ou iPad
            </div>
        <?php endif; ?>
    </div>
    <?php if ($deviceTotalStudents > 0): ?>
        <div style="height:300px;position:relative">
            <canvas id="chartDevices"></canvas>
        </div>
        <div style="text-align:center;color:var(--muted);font-size:11px;margin-top:10px">
            <?= number_format($deviceTotalStudents) ?> aluno(s) com abertura de aula identificada
        </div>
    <?php else: ?>
        <p style="font-size:13px;color:var(--muted);text-align:center;padding:70px 0">Nenhuma abertura de aula registrada no período.</p>
    <?php endif; ?>
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

<!-- CHARTS: Certificados x compras -->
<div class="grid-2 mb-4">
    <div class="panel">
        <div class="panel-title">Certificados e compras</div>
        <?php if ($certificadosUnicos > 0): ?>
            <canvas id="chartCompradoresCertificado" style="max-height:220px"></canvas>
            <div style="display:flex;gap:18px;justify-content:center;margin-top:14px;font-size:12px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block"></span>
                    <span><strong><?= number_format($certificadosComCompra) ?></strong> geraram e compraram (<?= number_format($pctCertificadosComCompra, 1, ',', '.') ?>%)</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#f97316;display:inline-block"></span>
                    <span><strong><?= number_format($certificadosSemCompra) ?></strong> geraram sem compra (<?= number_format($pctCertificadosSemCompra, 1, ',', '.') ?>%)</span>
                </div>
            </div>
        <?php else: ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:60px 0">Nenhum certificado encontrado no periodo</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-title">Resumo de certificados</div>
        <div style="display:flex;flex-direction:column;gap:14px;padding:8px 4px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Alunos com certificado no filtro</span>
                <strong style="font-size:20px"><?= number_format($certificadosUnicos) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Geraram certificado e compraram</span>
                <strong style="font-size:20px;color:#22c55e"><?= number_format($pctCertificadosComCompra, 1, ',', '.') ?>%</strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline">
                <span style="font-size:13px;color:var(--muted)">Geraram certificado sem compra</span>
                <strong style="font-size:20px;color:#f97316"><?= number_format($pctCertificadosSemCompra, 1, ',', '.') ?>%</strong>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS: Concluintes x compras -->
<div class="grid-2 mb-4">
    <div class="panel">
        <div class="panel-title">Concluintes e compras</div>
        <?php if ($concluintesUnicos > 0): ?>
            <canvas id="chartConcluintesCompras" style="max-height:220px"></canvas>
            <div style="display:flex;gap:18px;justify-content:center;margin-top:14px;font-size:12px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block"></span>
                    <span><strong><?= number_format($concluintesComCompra) ?></strong> concluíram e compraram (<?= number_format($pctConcluintesComCompra, 1, ',', '.') ?>%)</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;display:inline-block"></span>
                    <span><strong><?= number_format($concluintesSemCompra) ?></strong> concluíram sem compra (<?= number_format($pctConcluintesSemCompra, 1, ',', '.') ?>%)</span>
                </div>
            </div>
        <?php else: ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:60px 0">Nenhum concluinte encontrado no periodo</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-title">Resumo de concluintes</div>
        <div style="display:flex;flex-direction:column;gap:14px;padding:8px 4px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Alunos concluintes no filtro</span>
                <strong style="font-size:20px"><?= number_format($concluintesUnicos) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding-bottom:12px;border-bottom:1px solid var(--border)">
                <span style="font-size:13px;color:var(--muted)">Concluíram e compraram</span>
                <strong style="font-size:20px;color:#10b981"><?= number_format($pctConcluintesComCompra, 1, ',', '.') ?>%</strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline">
                <span style="font-size:13px;color:var(--muted)">Concluíram sem compra</span>
                <strong style="font-size:20px;color:#f59e0b"><?= number_format($pctConcluintesSemCompra, 1, ',', '.') ?>%</strong>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS: Compradores x conclusao/certificado -->
<div class="grid-2 mb-4">
    <div class="panel">
        <div class="panel-title">Compradores e conclusão</div>
        <?php if ($compradoresBase > 0): ?>
            <canvas id="chartCompradoresConclusao" style="max-height:220px"></canvas>
            <div style="display:flex;gap:18px;justify-content:center;margin-top:14px;font-size:12px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#06b6d4;display:inline-block"></span>
                    <span><strong><?= number_format($compradoresConcluintes) ?></strong> compraram e concluíram (<?= number_format($pctCompradoresConcluintes, 1, ',', '.') ?>%)</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#64748b;display:inline-block"></span>
                    <span><strong><?= number_format($compradoresNaoConcluintes) ?></strong> compraram sem concluir (<?= number_format($pctCompradoresNaoConcluintes, 1, ',', '.') ?>%)</span>
                </div>
            </div>
        <?php else: ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:60px 0">Nenhuma compra encontrada no periodo</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-title">Compradores e certificado</div>
        <?php if ($compradoresBase > 0): ?>
            <canvas id="chartCompradoresCertificadoBase" style="max-height:220px"></canvas>
            <div style="display:flex;gap:18px;justify-content:center;margin-top:14px;font-size:12px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block"></span>
                    <span><strong><?= number_format($compradoresComCertificadoBase) ?></strong> compraram e emitiram (<?= number_format($pctCompradoresComCertificadoBase, 1, ',', '.') ?>%)</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block"></span>
                    <span><strong><?= number_format($compradoresSemCertificadoBase) ?></strong> compraram sem certificado (<?= number_format($pctCompradoresSemCertificadoBase, 1, ',', '.') ?>%)</span>
                </div>
            </div>
        <?php else: ?>
            <p style="font-size:13px;color:var(--muted);text-align:center;padding:60px 0">Nenhuma compra encontrada no periodo</p>
        <?php endif; ?>
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

<div class="panel mb-4">
    <div class="panel-title">
        Tempo ate concluir 100% das aulas
        <span style="font-size:11px;color:var(--muted);font-weight:400">dias apos inscricao - <?= number_format($completionTotal, 0, ',', '.') ?> aluno(s) concluintes no filtro</span>
    </div>
    <?php if ($completionTotal > 0): ?>
        <div style="height:300px">
            <canvas id="chartCompletionLag"></canvas>
        </div>
    <?php else: ?>
        <p style="font-size:13px;color:var(--muted);text-align:center;padding:42px 0">Nenhum aluno concluiu 100% das aulas no filtro atual.</p>
    <?php endif; ?>
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
    <div class="panel-title">Funil de Live<?= ($liveAcessou + $liveOferta + $liveCompra + $comprasReais) === 0 ? ' <span style="font-size:11px;color:var(--muted);font-weight:400">(sem dados — configure eventos em Integrações → Eventos Live)</span>' : '' ?></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px">
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
            <div class="kpi-label">Clicaram CTA</div>
            <div class="kpi-value"><?= number_format($liveCompra) ?></div>
            <div class="kpi-sub"><?= $liveOferta>0?round($liveCompra/$liveOferta*100,1):0 ?>% de quem viu oferta</div>
        </div>
        <div class="kpi" style="border-color:rgba(16,185,129,.3)">
            <div class="kpi-icon" style="background:rgba(16,185,129,.15);color:#10b981">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/><path d="M21 10v9a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            </div>
            <div class="kpi-label">Compraram</div>
            <div class="kpi-value"><?= number_format($comprasReais) ?></div>
            <div class="kpi-sub"><?= $totalAlunos>0?round($comprasReais/$totalAlunos*100,1):0 ?>% dos inscritos</div>
        </div>
    </div>
    <div style="display:flex;align-items:flex-end;gap:2px;height:140px;padding:0 6px">
        <?php
        $liveFunnel = [
            ['Acessou', $liveAcessou, '#60a5fa'],
            ['Oferta',  $liveOferta,  '#fbbf24'],
            ['Clicou CTA',  $liveCompra,  '#34d399'],
            ['Comprou',     $comprasReais, '#10b981'],
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
        <span>Entrada em grupos por turma</span>
        <span style="font-size:11px;color:var(--muted);font-weight:400">inscritos x alunos que entraram em algum grupo monitorado</span>
    </div>
    <details style="margin-bottom:12px">
        <summary style="cursor:pointer;color:var(--muted);font-size:12px;font-weight:700">Filtrar turmas do grafico</summary>
        <div style="margin-top:10px;border:1px solid var(--border);border-radius:8px;padding:10px;background:rgba(255,255,255,.02)">
            <input type="text" id="wgTurmaSearch" placeholder="Buscar turma..." oninput="wgRenderControls()"
                   style="width:100%;box-sizing:border-box;background:var(--input-bg,#1e1e2e);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:7px 10px;font-size:12px;margin-bottom:8px">
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
                <button type="button" onclick="wgSelectAll(true)" class="btn btn-sm">Mostrar todas</button>
                <button type="button" onclick="wgSelectAll(false)" class="btn btn-sm">Ocultar todas</button>
                <button type="button" onclick="wgSelectTop()" class="btn btn-sm">Top 12</button>
            </div>
            <div id="wgTurmaControls" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px;max-height:190px;overflow:auto"></div>
        </div>
    </details>
    <div style="height:420px;min-height:260px">
        <canvas id="chartWhatsappTurmas"></canvas>
    </div>
</div>

<div class="panel mb-4">
    <div class="panel-title">
        Bloqueios por mes
        <span style="font-size:11px;color:var(--muted);font-weight:400"><?= number_format($totalBloqueiosPeriodo, 0, ',', '.') ?> bloqueio(s) ativado(s) no filtro</span>
    </div>
    <?php if ($bloqueiosMesLabels): ?>
        <div style="height:280px">
            <canvas id="chartBloqueiosMes"></canvas>
        </div>
    <?php else: ?>
        <p style="font-size:13px;color:var(--muted);text-align:center;padding:42px 0">Nenhum bloqueio ativado no filtro atual.</p>
    <?php endif; ?>
</div>

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
    const LIVE_RELATIVE_DATA = <?= json_encode($vendasRelativasLive, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let liveRelativeChart = null;

    const liveDayZeroPlugin = {
        id: 'liveDayZero',
        afterDraw: function(chart) {
            const active = chart.$liveRelativePayload;
            if (!active || !Array.isArray(active.days)) return;
            const zeroIndex = active.days.indexOf(0);
            if (zeroIndex < 0) return;
            const xScale = chart.scales.x;
            const area = chart.chartArea;
            if (!xScale || !area) return;
            const x = xScale.getPixelForValue(zeroIndex);
            const ctx = chart.ctx;
            ctx.save();
            ctx.strokeStyle = 'rgba(250,204,21,.85)';
            ctx.lineWidth = 2;
            ctx.setLineDash([5, 5]);
            ctx.beginPath();
            ctx.moveTo(x, area.top);
            ctx.lineTo(x, area.bottom);
            ctx.stroke();
            ctx.restore();
        }
    };

    let liveRelativeReference = 'original';

    function liveRelativeLimit(inputId, fallback) {
        const input = document.getElementById(inputId);
        const parsed = input ? parseInt(input.value, 10) : fallback;
        return Number.isFinite(parsed) ? Math.max(0, parsed) : fallback;
    }

    function liveRelativeVisiblePayload(reference) {
        const full = LIVE_RELATIVE_DATA[reference] || {labels:['0'], days:[0], data:[0], total:0};
        const negative = liveRelativeLimit('liveRelativeNegative', 30);
        const positive = liveRelativeLimit('liveRelativePositive', 30);
        const labels = [];
        const days = [];
        const data = [];

        (full.days || []).forEach(function(day, index) {
            const numericDay = Number(day);
            if (numericDay < -negative || numericDay > positive) return;
            days.push(numericDay);
            labels.push((full.labels || [])[index] ?? (numericDay > 0 ? '+' + numericDay : String(numericDay)));
            data.push(Number((full.data || [])[index] || 0));
        });

        if (!days.length) {
            days.push(0);
            labels.push('0');
            data.push(0);
        }

        try {
            localStorage.setItem('dashboard_live_relative_negative', String(negative));
            localStorage.setItem('dashboard_live_relative_positive', String(positive));
        } catch (e) {}

        const rangeLabel = document.getElementById('liveRelativeRangeLabel');
        if (rangeLabel) rangeLabel.textContent = '-' + negative + ' a +' + positive;

        return {
            labels: labels,
            days: days,
            data: data,
            total: data.reduce(function(sum, value) { return sum + Number(value || 0); }, 0)
        };
    }

    function renderLiveRelativeChart(reference) {
        const canvas = document.getElementById('chartVendasRelativasLive');
        if (!canvas) return;
        liveRelativeReference = reference;
        const payload = liveRelativeVisiblePayload(reference);
        const isRescheduled = reference === 'reagendada';
        const color = isRescheduled ? '#a78bfa' : '#38bdf8';
        const total = Number(payload.total || 0);

        document.getElementById('liveRelativeReferenceLabel').textContent = isRescheduled ? 'live reagendada' : 'live original';
        document.getElementById('liveRelativeTotal').textContent = total.toLocaleString('pt-BR');
        document.getElementById('liveRelativeEmpty').style.display = total > 0 ? 'none' : 'inline';

        if (liveRelativeChart) liveRelativeChart.destroy();
        liveRelativeChart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: payload.labels || ['0'],
                datasets: [{
                    label: 'Vendas',
                    data: payload.data || [0],
                    borderColor: color,
                    backgroundColor: color + '22',
                    pointBackgroundColor: function(ctx) {
                        return Number((payload.days || [])[ctx.dataIndex]) === 0 ? '#facc15' : color;
                    },
                    pointBorderColor: function(ctx) {
                        return Number((payload.days || [])[ctx.dataIndex]) === 0 ? '#fef08a' : color;
                    },
                    pointRadius: function(ctx) {
                        const value = Number((payload.data || [])[ctx.dataIndex] || 0);
                        const day = Number((payload.days || [])[ctx.dataIndex]);
                        return day === 0 ? 6 : (value > 0 ? 4 : 2);
                    },
                    pointHoverRadius: 7,
                    borderWidth: 2.5,
                    tension: 0.28,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                if (!items.length) return '';
                                const day = Number((payload.days || [])[items[0].dataIndex] || 0);
                                if (day === 0) return 'Dia 0 — dia da live';
                                return day < 0
                                    ? Math.abs(day) + ' dia(s) antes da live'
                                    : day + ' dia(s) depois da live';
                            },
                            label: function(ctx) {
                                return Number(ctx.parsed.y || 0).toLocaleString('pt-BR') + ' venda(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: { display: true, text: 'Dias em relação à live', color: '#94a3b8' },
                        ticks: { color:'#94a3b8', autoSkip:true, maxTicksLimit:18, maxRotation:0 },
                        grid: { color:'rgba(26,37,64,.45)' }
                    },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Quantidade de vendas', color: '#94a3b8' },
                        ticks: { color:'#94a3b8', precision:0 },
                        grid: { color:'rgba(26,37,64,.6)' }
                    }
                }
            },
            plugins: [liveDayZeroPlugin]
        });
        liveRelativeChart.$liveRelativePayload = payload;
    }

    document.querySelectorAll('input[data-live-reference]').forEach(function(input) {
        input.addEventListener('change', function() {
            if (!input.checked) {
                input.checked = true;
                return;
            }
            document.querySelectorAll('input[data-live-reference]').forEach(function(other) {
                if (other !== input) other.checked = false;
            });
            renderLiveRelativeChart(input.getAttribute('data-live-reference') || 'original');
        });
    });

    ['liveRelativeNegative', 'liveRelativePositive'].forEach(function(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function() {
            renderLiveRelativeChart(liveRelativeReference);
        });
        input.addEventListener('change', function() {
            input.value = String(liveRelativeLimit(inputId, 30));
            renderLiveRelativeChart(liveRelativeReference);
        });
    });

    const liveRelativeShowAll = document.getElementById('liveRelativeShowAll');
    if (liveRelativeShowAll) {
        liveRelativeShowAll.addEventListener('click', function() {
            const full = LIVE_RELATIVE_DATA[liveRelativeReference] || {days:[0]};
            const days = (full.days || [0]).map(Number);
            const minDay = Math.min.apply(null, days);
            const maxDay = Math.max.apply(null, days);
            document.getElementById('liveRelativeNegative').value = String(Math.max(0, Math.abs(Math.min(0, minDay))));
            document.getElementById('liveRelativePositive').value = String(Math.max(0, maxDay));
            renderLiveRelativeChart(liveRelativeReference);
        });
    }

    try {
        const savedNegative = parseInt(localStorage.getItem('dashboard_live_relative_negative'), 10);
        const savedPositive = parseInt(localStorage.getItem('dashboard_live_relative_positive'), 10);
        if (Number.isFinite(savedNegative) && savedNegative >= 0) {
            document.getElementById('liveRelativeNegative').value = String(savedNegative);
        }
        if (Number.isFinite(savedPositive) && savedPositive >= 0) {
            document.getElementById('liveRelativePositive').value = String(savedPositive);
        }
    } catch (e) {}

    renderLiveRelativeChart('original');

    const LEAD_ENGAGEMENT_SERIES = <?= json_encode($leadEngagementSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let leadEngagementChart = null;
    const leadEngagementState = {period:'daily', mode:'count'};
    function leadEngagementTotals(data) {
        const labels = data.labels || [];
        return labels.map(function(_, i) {
            return ['frio','morno','quente','pelando'].reduce(function(sum, key) {
                return sum + Number((data[key] || [])[i] || 0);
            }, 0);
        });
    }
    function leadEngagementValues(data, key, totals, mode) {
        const raw = data[key] || [];
        if (mode !== 'percent') return raw;
        return raw.map(function(value, i) {
            const total = Number(totals[i] || 0);
            return total > 0 ? Math.round((Number(value || 0) / total * 100) * 10) / 10 : 0;
        });
    }
    function renderLeadEngagementChart(period) {
        const canvas = document.getElementById('chartLeadEngagement');
        if (!canvas) return;
        const data = LEAD_ENGAGEMENT_SERIES[period] || LEAD_ENGAGEMENT_SERIES.daily || {labels:[],frio:[],morno:[],quente:[],pelando:[]};
        const totals = leadEngagementTotals(data);
        const mode = leadEngagementState.mode;
        if (leadEngagementChart) leadEngagementChart.destroy();
        leadEngagementChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [
                    {label:'Frio', key:'frio', data:leadEngagementValues(data,'frio',totals,mode), backgroundColor:'#64748b', borderRadius:3, stack:'engagement'},
                    {label:'Morno', key:'morno', data:leadEngagementValues(data,'morno',totals,mode), backgroundColor:'#38bdf8', borderRadius:3, stack:'engagement'},
                    {label:'Quente', key:'quente', data:leadEngagementValues(data,'quente',totals,mode), backgroundColor:'#f59e0b', borderRadius:3, stack:'engagement'},
                    {label:'Pelando', key:'pelando', data:leadEngagementValues(data,'pelando',totals,mode), backgroundColor:'#ef4444', borderRadius:3, stack:'engagement'}
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {mode:'index', intersect:false},
                plugins: {
                    legend: {position:'bottom', labels:{color:'#94a3b8', boxWidth:10, font:{size:11}}},
                    tooltip: {
                        callbacks: {
                            footer: function(items) {
                                const idx = items[0] ? items[0].dataIndex : 0;
                                const total = Number(totals[idx] || 0);
                                return 'Total inscrito: ' + total.toLocaleString('pt-BR') + ' lead(s)';
                            },
                            label: function(ctx) {
                                const raw = Number((data[ctx.dataset.key] || [])[ctx.dataIndex] || 0);
                                const total = Number(totals[ctx.dataIndex] || 0);
                                const pct = total > 0 ? raw / total * 100 : 0;
                                if (mode === 'percent') return ctx.dataset.label + ': ' + Number(ctx.parsed.y || 0).toLocaleString('pt-BR') + '% (' + raw.toLocaleString('pt-BR') + ')';
                                return ctx.dataset.label + ': ' + raw.toLocaleString('pt-BR') + ' (' + pct.toLocaleString('pt-BR', {maximumFractionDigits:1}) + '%)';
                            }
                        }
                    }
                },
                scales: {
                    x: {stacked:true, ticks:{color:'#94a3b8', maxTicksLimit:10, font:{size:11}}, grid:{display:false}},
                    y: {stacked:true, beginAtZero:true, max:mode==='percent'?100:undefined, ticks:{color:'#94a3b8', precision:0, font:{size:11}, callback:function(value){return mode==='percent'?value+'%':value;}}, grid:{color:'rgba(26,37,64,.6)'}}
                }
            }
        });
    }
    const engagementSwitch = document.querySelector('[data-engagement-period]');
    if (engagementSwitch) {
        engagementSwitch.querySelectorAll('button[data-period]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                engagementSwitch.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                leadEngagementState.period = btn.getAttribute('data-period') || 'daily';
                renderLeadEngagementChart(leadEngagementState.period);
            });
        });
        renderLeadEngagementChart('daily');
    }
    const engagementModeSwitch = document.querySelector('[data-engagement-mode]');
    if (engagementModeSwitch) {
        engagementModeSwitch.querySelectorAll('button[data-mode]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                engagementModeSwitch.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                leadEngagementState.mode = btn.getAttribute('data-mode') || 'count';
                renderLeadEngagementChart(leadEngagementState.period);
            });
        });
    }

    const DASH_LINE_CHARTS = <?= json_encode($dashLineCharts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const dashLineInstances = {};

    function dashLineDataset(chartKey, period, firstOnly) {
        const cfg = DASH_LINE_CHARTS[chartKey] || {};
        const seriesGroup = firstOnly && cfg.first_series ? cfg.first_series : cfg.series;
        const series = (seriesGroup && seriesGroup[period]) ? seriesGroup[period] : {labels: [], data: []};
        return {cfg, labels: series.labels || [], data: series.data || []};
    }

    function dashRenderLine(chartKey, period) {
        const canvas = document.getElementById('dashLine_' + chartKey);
        if (!canvas) return;
        const firstOnlyInput = document.querySelector('input[data-first-only="' + chartKey + '"]');
        const firstOnly = Boolean(firstOnlyInput && firstOnlyInput.checked);
        const payload = dashLineDataset(chartKey, period, firstOnly);
        const color = payload.cfg.color || '#38bdf8';
        if (dashLineInstances[chartKey]) dashLineInstances[chartKey].destroy();
        dashLineInstances[chartKey] = new Chart(canvas, {
            type: 'line',
            data: {
                labels: payload.labels,
                datasets: [{
                    label: payload.cfg.title || '',
                    data: payload.data,
                    tension: 0.34,
                    borderWidth: 2.4,
                    borderColor: color,
                    backgroundColor: color + '22',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: color,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const suffix = payload.cfg.suffix || '';
                                return Number(ctx.parsed.y || 0).toLocaleString('pt-BR') + suffix;
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { maxTicksLimit: 8, color:'#94a3b8', font:{size:11} }, grid:{ color:'rgba(26,37,64,.45)' } },
                    y: { beginAtZero:true, ticks:{ color:'#94a3b8', font:{size:11} }, grid:{ color:'rgba(26,37,64,.6)' } }
                }
            }
        });
    }

    document.querySelectorAll('.dash-period-switch').forEach(function(group) {
        const chartKey = group.getAttribute('data-chart');
        group.querySelectorAll('button[data-period]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                group.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                dashRenderLine(chartKey, btn.getAttribute('data-period') || 'daily');
            });
        });
        dashRenderLine(chartKey, 'daily');
    });

    document.querySelectorAll('input[data-first-only]').forEach(function(input) {
        input.addEventListener('change', function() {
            const chartKey = input.getAttribute('data-first-only');
            const group = document.querySelector('.dash-period-switch[data-chart="' + chartKey + '"]');
            const active = group ? group.querySelector('button[data-period].active') : null;
            dashRenderLine(chartKey, active ? active.getAttribute('data-period') : 'daily');
        });
    });

    function dashResizeLine(chartKey) {
        setTimeout(function() {
            if (dashLineInstances[chartKey]) dashLineInstances[chartKey].resize();
        }, 60);
    }

    function dashCloseFullscreen() {
        const current = document.querySelector('.dash-line-panel.is-fullscreen');
        if (!current) return;
        const chartKey = current.getAttribute('data-chart-panel');
        current.classList.remove('is-fullscreen');
        document.body.classList.remove('dash-chart-fullscreen');
        dashResizeLine(chartKey);
    }

    document.querySelectorAll('.dash-line-panel[data-chart-panel]').forEach(function(panel) {
        const chartKey = panel.getAttribute('data-chart-panel');
        const wrap = panel.querySelector('.dash-line-chart-wrap');
        const closeBtn = panel.querySelector('.dash-line-close');
        if (wrap) {
            wrap.addEventListener('click', function() {
                if (panel.classList.contains('is-fullscreen')) return;
                dashCloseFullscreen();
                panel.classList.add('is-fullscreen');
                document.body.classList.add('dash-chart-fullscreen');
                dashResizeLine(chartKey);
            });
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', function(ev) {
                ev.stopPropagation();
                dashCloseFullscreen();
            });
        }
    });

    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape') dashCloseFullscreen();
    });

    var labelsDia = <?= json_encode($labelsDia, JSON_UNESCAPED_UNICODE) ?>;
    var dataDia   = <?= json_encode($dataDia) ?>;
    var deviceLabels = <?= json_encode($deviceLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var deviceData = <?= json_encode($deviceData) ?>;
    var deviceColors = {
        'Android': '#22c55e',
        'iPhone': '#a855f7',
        'iPad': '#c084fc',
        'Windows': '#38bdf8',
        'Mac': '#f59e0b',
        'Linux': '#fb7185',
        'Outro': '#64748b',
        'Não identificado': '#334155'
    };
    var cDevices = document.getElementById('chartDevices');
    if (cDevices && deviceLabels.length) {
        new Chart(cDevices, {
            type: 'doughnut',
            data: {
                labels: deviceLabels,
                datasets: [{
                    data: deviceData,
                    backgroundColor: deviceLabels.map(function(label) { return deviceColors[label] || '#64748b'; }),
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { color: '#94a3b8', padding: 16, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(sum, value) { return sum + Number(value || 0); }, 0);
                                var value = Number(ctx.parsed || 0);
                                var pct = total > 0 ? (value / total * 100).toFixed(1).replace('.', ',') : '0,0';
                                return ctx.label + ': ' + value.toLocaleString('pt-BR') + ' aluno(s) (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
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
    // Bloqueios por mes
    var bloqueiosMesLabels = <?= json_encode($bloqueiosMesLabels, JSON_UNESCAPED_UNICODE) ?>;
    var bloqueiosMesData = <?= json_encode($bloqueiosMesData) ?>;
    var cBloqueios = document.getElementById('chartBloqueiosMes');
    if (cBloqueios && bloqueiosMesLabels.length) {
        new Chart(cBloqueios, {
            type: 'bar',
            data: {
                labels: bloqueiosMesLabels,
                datasets: [{
                    label: 'Bloqueios ativados',
                    data: bloqueiosMesData,
                    backgroundColor: 'rgba(248,113,113,.72)',
                    borderColor: '#f87171',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.parsed.y.toLocaleString('pt-BR') + ' bloqueio(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color:'#94a3b8', font:{size:11} }, grid: { display:false } },
                    y: { beginAtZero:true, ticks: { precision:0, color:'#94a3b8', font:{size:11} }, grid: { color:'rgba(26,37,64,.6)' } }
                }
            }
        });
    }

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

    // Certificados com compra vs sem compra
    var cCompradoresCert = document.getElementById('chartCompradoresCertificado');
    if (cCompradoresCert) {
        new Chart(cCompradoresCert, {
            type: 'pie',
            data: {
                labels: ['Geraram certificado e compraram', 'Geraram certificado sem compra'],
                datasets: [{
                    data: [<?= $certificadosComCompra ?>, <?= $certificadosSemCompra ?>],
                    backgroundColor: ['rgba(34,197,94,.82)', 'rgba(249,115,22,.82)'],
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 12 }, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 1000) / 10 : 0;
                                return ctx.label + ': ' + ctx.parsed.toLocaleString('pt-BR') + ' (' + pct.toLocaleString('pt-BR') + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // Concluintes com compra vs sem compra
    var cConcluintesCompras = document.getElementById('chartConcluintesCompras');
    if (cConcluintesCompras) {
        new Chart(cConcluintesCompras, {
            type: 'pie',
            data: {
                labels: ['Concluíram e compraram', 'Concluíram sem compra'],
                datasets: [{
                    data: [<?= $concluintesComCompra ?>, <?= $concluintesSemCompra ?>],
                    backgroundColor: ['rgba(16,185,129,.82)', 'rgba(245,158,11,.82)'],
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 12 }, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 1000) / 10 : 0;
                                return ctx.label + ': ' + ctx.parsed.toLocaleString('pt-BR') + ' (' + pct.toLocaleString('pt-BR') + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ── Gráfico comparativo por turma ────────────────────────────────────
    // Tempo ate concluir 100% das aulas
    // Compradores que concluiram vs nao concluiram
    var cCompradoresConclusao = document.getElementById('chartCompradoresConclusao');
    if (cCompradoresConclusao) {
        new Chart(cCompradoresConclusao, {
            type: 'pie',
            data: {
                labels: ['Compraram e concluíram', 'Compraram sem concluir'],
                datasets: [{
                    data: [<?= $compradoresConcluintes ?>, <?= $compradoresNaoConcluintes ?>],
                    backgroundColor: ['rgba(6,182,212,.82)', 'rgba(100,116,139,.72)'],
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 12 }, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 1000) / 10 : 0;
                                return ctx.label + ': ' + ctx.parsed.toLocaleString('pt-BR') + ' (' + pct.toLocaleString('pt-BR') + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // Compradores com certificado vs sem certificado
    var cCompradoresCertBase = document.getElementById('chartCompradoresCertificadoBase');
    if (cCompradoresCertBase) {
        new Chart(cCompradoresCertBase, {
            type: 'pie',
            data: {
                labels: ['Compraram e emitiram certificado', 'Compraram sem certificado'],
                datasets: [{
                    data: [<?= $compradoresComCertificadoBase ?>, <?= $compradoresSemCertificadoBase ?>],
                    backgroundColor: ['rgba(34,197,94,.82)', 'rgba(239,68,68,.78)'],
                    borderColor: '#07101f',
                    borderWidth: 3,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#94a3b8', font: { size: 12 }, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce((a,b)=>a+b,0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 1000) / 10 : 0;
                                return ctx.label + ': ' + ctx.parsed.toLocaleString('pt-BR') + ' (' + pct.toLocaleString('pt-BR') + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    var completionLabels = <?= json_encode($completionLabels, JSON_UNESCAPED_UNICODE) ?>;
    var completionData = <?= json_encode($completionChartData) ?>;
    var cCompletion = document.getElementById('chartCompletionLag');
    if (cCompletion && completionData.some(function(v) { return v > 0; })) {
        new Chart(cCompletion, {
            type: 'bar',
            data: {
                labels: completionLabels,
                datasets: [{
                    label: 'Alunos que concluiram',
                    data: completionData,
                    backgroundColor: 'rgba(34,197,94,.72)',
                    borderColor: '#22c55e',
                    borderWidth: 1,
                    borderRadius: 5,
                    maxBarThickness: 18
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                var label = items && items[0] ? items[0].label : '';
                                return label === '60+' ? '60 dias ou mais' : 'Dia ' + label + ' apos inscricao';
                            },
                            label: function(ctx) {
                                return ctx.parsed.y.toLocaleString('pt-BR') + ' aluno(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 0,
                            font: { size: 10 },
                            callback: function(value) {
                                var label = this.getLabelForValue(value);
                                if (label === '60+') return label;
                                var n = parseInt(label, 10);
                                return n === 1 || n % 5 === 0 ? label : '';
                            }
                        },
                        grid: { display: false },
                        title: { display: true, text: 'Dias apos inscricao', color: '#64748b', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11 } },
                        grid: { color: 'rgba(26,37,64,.6)' },
                        title: { display: true, text: 'Alunos', color: '#64748b', font: { size: 11 } }
                    }
                }
            }
        });
    }

    // Grafico comparativo por turma
    const BAR_TURMA_DATA  = <?= json_encode($barTurmaData, JSON_UNESCAPED_UNICODE) ?>;
    const TURMAS_LABEL    = <?= json_encode($turmasLabel,  JSON_UNESCAPED_UNICODE) ?>;
    const TURMA_FILTRADA  = <?= json_encode((string)($turmasSelecionadasChart[0] ?? $turmaId)) ?>;
    const TURMAS_FILTRADAS = <?= json_encode($turmasSelecionadasChart, JSON_UNESCAPED_UNICODE) ?>;
    const BAR_STAGES = [
        {key:'inscritos', label:'Inscritos',  color:'#facc15'},
        {key:'logaram',   label:'Logaram',    color:'#a855f7'},
        {key:'aula',      label:'Viu aula',   color:'#0ea5e9'},
        {key:'live_acessou', label:'Live: Acessou', color:'#60a5fa'},
        {key:'live_oferta',  label:'Live: Oferta',  color:'#fbbf24'},
        {key:'live_compra',  label:'Live: Clicou CTA',  color:'#34d399'},
        {key:'compras_reais', label:'Compras', color:'#10b981'},
        {key:'cert',      label:'Certificado', color:'#22c55e'},
    ];
    let btMode = 'qtd';
    let btSelectedTurmas = [];
    let btChart = null;
    let wgSelectedTurmas = [];
    let wgChart = null;

    function wgTurmaIds() {
        return Object.keys(BAR_TURMA_DATA)
            .filter(tid => (BAR_TURMA_DATA[tid].inscritos || 0) > 0)
            .sort((a,b) => (BAR_TURMA_DATA[b].inscritos||0) - (BAR_TURMA_DATA[a].inscritos||0));
    }

    function wgInit() {
        if (!document.getElementById('chartWhatsappTurmas')) return;
        const ids = wgTurmaIds();
        wgSelectedTurmas = ids.slice(0, Math.min(12, ids.length));
        wgRenderControls();
        wgRenderChart();
    }

    function wgRenderControls() {
        const cont = document.getElementById('wgTurmaControls');
        if (!cont) return;
        const q = (document.getElementById('wgTurmaSearch')?.value || '').trim().toLowerCase();
        const ids = wgTurmaIds().filter(tid => {
            const label = (TURMAS_LABEL[tid] || tid).toLowerCase();
            return !q || label.includes(q);
        });
        if (!ids.length) {
            cont.innerHTML = '<div style="color:var(--muted);font-size:12px;padding:6px">Nenhuma turma encontrada</div>';
            return;
        }
        cont.innerHTML = ids.map(tid => {
            const d = BAR_TURMA_DATA[tid] || {};
            const inscritos = d.inscritos || 0;
            const entrou = d.grupo_entrou || 0;
            const taxa = inscritos > 0 ? Math.round(entrou / inscritos * 1000) / 10 : 0;
            const checked = wgSelectedTurmas.includes(tid) ? 'checked' : '';
            return `<label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text);padding:5px 6px;border-radius:6px;background:rgba(255,255,255,.025)">
                <input type="checkbox" ${checked} onchange="wgToggleTurma('${escAttr(tid)}', this.checked)" style="accent-color:var(--primary)">
                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(TURMAS_LABEL[tid] || tid)}</span>
                <span style="margin-left:auto;color:var(--muted);font-size:11px">${taxa}%</span>
            </label>`;
        }).join('');
    }

    function wgToggleTurma(tid, show) {
        const idx = wgSelectedTurmas.indexOf(tid);
        if (show && idx < 0) wgSelectedTurmas.push(tid);
        if (!show && idx >= 0) wgSelectedTurmas.splice(idx, 1);
        wgRenderChart();
    }

    function wgSelectAll(show) {
        wgSelectedTurmas = show ? wgTurmaIds() : [];
        wgRenderControls();
        wgRenderChart();
    }

    function wgSelectTop() {
        wgSelectedTurmas = wgTurmaIds().slice(0, 12);
        wgRenderControls();
        wgRenderChart();
    }

    function wgRenderChart() {
        const canvas = document.getElementById('chartWhatsappTurmas');
        if (!canvas) return;
        const ids = wgSelectedTurmas.slice().sort((a,b) => {
            const da = BAR_TURMA_DATA[a] || {}, db = BAR_TURMA_DATA[b] || {};
            const pa = (da.inscritos || 0) > 0 ? (da.grupo_entrou || 0) / da.inscritos : 0;
            const pb = (db.inscritos || 0) > 0 ? (db.grupo_entrou || 0) / db.inscritos : 0;
            return pb - pa;
        });
        const wrap = canvas.parentElement;
        if (wrap) wrap.style.height = Math.max(260, Math.min(900, ids.length * 34 + 80)) + 'px';
        const labels = ids.map(tid => TURMAS_LABEL[tid] || (tid === '' ? 'Sem turma' : ('Turma ' + tid)));
        const taxas = ids.map(tid => {
            const d = BAR_TURMA_DATA[tid] || {};
            return (d.inscritos || 0) > 0 ? Math.round((d.grupo_entrou || 0) / d.inscritos * 1000) / 10 : 0;
        });
        const maxTaxa = Math.max(100, ...taxas);
        const axisMax = Math.ceil(maxTaxa / 10) * 10;
        if (wgChart) wgChart.destroy();
        wgChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Taxa de entrada em grupos',
                    data: taxas,
                    backgroundColor: 'rgba(34,197,94,.72)',
                    borderColor: '#22c55e',
                    borderWidth: 1.5,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { beginAtZero:true, max:axisMax, ticks:{ callback:v => v + '%', color:'#94a3b8' }, grid:{ color:'rgba(26,37,64,.4)' } },
                    y: { ticks:{ color:'#cbd5e1', font:{size:11} }, grid:{ display:false } }
                },
                plugins: {
                    legend: { display:false },
                    tooltip: {
                        callbacks: {
                            label: c => {
                                const tid = ids[c.dataIndex];
                                const d = BAR_TURMA_DATA[tid] || {};
                                return `${d.grupo_entrou || 0} de ${d.inscritos || 0} alunos (${c.parsed.x}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

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
        const filtradas = TURMAS_FILTRADAS.filter(tid => BAR_TURMA_DATA[tid]);
        if (filtradas.length) {
            btSelectedTurmas = filtradas;
        } else if (TURMA_FILTRADA && BAR_TURMA_DATA[TURMA_FILTRADA]) {
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
    window.wgRenderControls = wgRenderControls;
    window.wgToggleTurma = wgToggleTurma;
    window.wgSelectAll = wgSelectAll;
    window.wgSelectTop = wgSelectTop;

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

    wgInit();
    btInit();

})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- RANKING DE MÚLTIPLAS INSCRIÇÕES                                    -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<?php if ($rankingRows || $groupRankingRows): ?>
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
.rk-panel:not(.expanded) tr.rk-extra-row { display:none; }
.rk-list-toggle {
    width:30px; height:30px; border-radius:var(--r-full);
    display:inline-flex; align-items:center; justify-content:center;
    border:1px solid var(--border); background:var(--bg-hover);
    color:var(--primary); cursor:pointer; transition:all var(--t);
}
.rk-list-toggle:hover { border-color:var(--primary); background:var(--primary-dim); }
.rk-list-toggle svg { transition:transform .2s; }
.rk-panel.expanded .rk-list-toggle svg { transform:rotate(180deg); }
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
.rk-group-card-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.rk-group-avatar { width:30px; height:30px; border-radius:var(--r-full); object-fit:cover; border:1px solid var(--border); background:var(--bg-hover); }
</style>
<?php endif; ?>

<?php if ($rankingRows): ?>
<div class="panel rk-panel" id="rkpanel-insc" style="margin-top:0">
    <div class="panel-title" style="margin-bottom:14px">
        <span style="display:flex;align-items:center;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            Ranking de inscrições
        </span>
        <span style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--muted);font-weight:400">
            <?php if (count($rankingRows) > 5): ?>
            <button type="button" class="rk-list-toggle" title="Mostrar todos" onclick="event.stopPropagation(); rkToggleList('insc')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <?php endif; ?>
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
            try { return (new DateTime($d))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $d; }
        }
        function rkDtHora(?string $d): string {
            if (!$d || trim($d) === '') return '—';
            try { return (new DateTime($d))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $d; }
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
            $extraRow = $ri >= 5 ? ' rk-extra-row' : '';
        ?>
        <tr class="main-row<?= $extraRow ?>" id="rkrow-<?= $ri ?>" onclick="rkToggle(<?= $ri ?>)">
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
        <tr class="<?= trim($extraRow) ?>">
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
                                    <?= rkDtHora((string)($h['hora']??'')) ?>
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

<?php if ($groupRankingRows): ?>
<?php
$grDt = static function (?string $d): string {
    if (!$d || trim($d) === '') return '—';
    try { return (new DateTime($d))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $d; }
};
$grEventLabel = static function (string $event): string {
    switch ($event) {
        case 'WHATSAPP_GRUPO_ENTROU':
            return 'Entrou no grupo';
        case 'WHATSAPP_GRUPO_SAIU':
            return 'Saiu por conta propria';
        case 'WHATSAPP_GRUPO_REMOVIDO_ADMIN':
            return 'Removido por admin';
        default:
            return $event;
    }
};
?>
<div class="panel rk-panel" id="rkpanel-grupos" style="margin-top:16px">
    <div class="panel-title" style="margin-bottom:14px">
        <span style="display:flex;align-items:center;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Ranking de entradas em grupos
        </span>
        <span style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--muted);font-weight:400">
            <?php if (count($groupRankingRows) > 5): ?>
            <button type="button" class="rk-list-toggle" title="Mostrar todos" onclick="event.stopPropagation(); rkToggleList('grupos')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <?php endif; ?>
            Top <?= min(5, count($groupRankingRows)) ?> de <?= count($groupRankingRows) ?> participantes — clique para ver entradas e saidas
        </span>
    </div>

    <div style="overflow-x:auto">
    <table class="rk-table" style="min-width:820px">
        <thead>
            <tr>
                <th style="width:30px"></th>
                <th style="width:36px">#</th>
                <th>Aluno / Identificacao</th>
                <th>Telefone</th>
                <th style="text-align:center">Entradas</th>
                <th style="text-align:center">Grupos diferentes</th>
                <th>Primeira entrada</th>
                <th>Ultima entrada</th>
                <th style="text-align:right">Acoes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groupRankingRows as $gi => $gr):
            $uid = (int)($gr['id'] ?? 0);
            $rankingKey = (string)($gr['ranking_key'] ?? ($uid > 0 ? 'u:' . $uid : ''));
            $rankN = $gi + 1;
            if ($rankN === 1)      $rankCls = 'rank-1';
            elseif ($rankN === 2)  $rankCls = 'rank-2';
            elseif ($rankN === 3)  $rankCls = 'rank-3';
            else                   $rankCls = 'rank-n';
            $entries = (int)($gr['qtd_entradas'] ?? 0);
            $distinctGroups = (int)($gr['grupos_distintos'] ?? 0);
            $hist = $groupRankingHistorico[$rankingKey] ?? [];
            $displayName = $uid > 0
                ? (string)($gr['nome'] ?? '-')
                : 'Numero nao identificado';
            $displaySub = $uid > 0
                ? (string)($gr['email'] ?? '-')
                : ((string)($gr['participant_id'] ?? '') ?: 'Sem aluno vinculado');
            $extraRow = $gi >= 5 ? ' rk-extra-row' : '';
            $entryColor = $entries >= 5
                ? 'color:#f87171;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25)'
                : 'color:#fcd34d;background:rgba(250,204,21,.1);border:1px solid rgba(250,204,21,.2)';
        ?>
        <tr class="main-row<?= $extraRow ?>" id="grrow-<?= $gi ?>" onclick="grToggle(<?= $gi ?>)">
            <td><span class="rk-expand-icon">▶</span></td>
            <td><span class="rank-badge <?= $rankCls ?>"><?= $rankN ?></span></td>
            <td>
                <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($displayName) ?></div>
                <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($displaySub) ?></div>
            </td>
            <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars(trim((string)($gr['telefone'] ?? ''))) ?: '—' ?></td>
            <td style="text-align:center"><span class="insc-count-badge" style="<?= $entryColor ?>"><?= $entries ?>x</span></td>
            <td style="text-align:center"><span class="insc-count-badge" style="color:#93c5fd;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25)"><?= $distinctGroups ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?= $grDt((string)($gr['primeira_entrada'] ?? '')) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= $grDt((string)($gr['ultima_entrada'] ?? '')) ?></td>
            <td style="text-align:right" onclick="event.stopPropagation()">
                <?php if ($uid > 0): ?>
                <a href="aluno_editar.php?id=<?= $uid ?>" class="btn btn-ghost btn-xs">Editar</a>
                <?php else: ?>
                <span style="font-size:11px;color:var(--muted)">Sem aluno</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr class="<?= trim($extraRow) ?>">
            <td colspan="9" style="padding:0;border-bottom:none">
                <div class="rk-detail" id="grdet-<?= $gi ?>">
                    <div class="rk-detail-inner">
                        <div class="rk-detail-title">
                            Historico de grupos (<?= count($hist) ?> <?= count($hist) === 1 ? 'evento' : 'eventos' ?>)
                        </div>
                        <?php if ($hist): ?>
                        <div class="rk-insc-grid">
                            <?php foreach ($hist as $hi => $h):
                                $groupName = trim((string)($h['group_name'] ?? ''));
                                $groupId = trim((string)($h['group_id'] ?? ''));
                                $picture = trim((string)($h['picture_url'] ?? ''));
                                $participantPhone = trim((string)($h['participant_phone'] ?? ''));
                                $participantId = trim((string)($h['participant_id'] ?? ''));
                                $authorId = trim((string)($h['author_id'] ?? ''));
                                $action = trim((string)($h['action'] ?? ''));
                                $ignored = (int)($h['is_ignored'] ?? 0) === 1;
                            ?>
                            <div class="rk-insc-card">
                                <div class="rk-group-card-head">
                                    <?php if ($picture !== ''): ?>
                                    <img class="rk-group-avatar" src="<?= htmlspecialchars($picture) ?>" alt="">
                                    <?php endif; ?>
                                    <div style="min-width:0">
                                        <div style="font-size:12px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($groupName !== '' ? $groupName : $groupId) ?></div>
                                        <div style="font-size:10.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($groupId) ?></div>
                                    </div>
                                </div>
                                <div class="rk-insc-date">
                                    <?= htmlspecialchars($grEventLabel((string)($h['interpreted_event'] ?? ''))) ?>
                                    <span style="margin-left:4px;font-size:10px;background:var(--primary-dim);color:var(--primary);padding:1px 6px;border-radius:999px">#<?= $hi + 1 ?></span>
                                </div>
                                <div class="rk-insc-row"><span class="rk-insc-k">Data</span><span class="rk-insc-v"><?= $grDt((string)($h['created_at'] ?? '')) ?></span></div>
                                <?php if ($participantPhone !== ''): ?>
                                <div class="rk-insc-row"><span class="rk-insc-k">Telefone</span><span class="rk-insc-v"><?= htmlspecialchars($participantPhone) ?></span></div>
                                <?php endif; ?>
                                <?php if ($action !== ''): ?>
                                <div class="rk-insc-row"><span class="rk-insc-k">Acao</span><span class="rk-insc-v"><?= htmlspecialchars($action) ?></span></div>
                                <?php endif; ?>
                                <?php if ($participantId !== ''): ?>
                                <div class="rk-insc-row"><span class="rk-insc-k">Participante</span><span class="rk-insc-v"><?= htmlspecialchars($participantId) ?></span></div>
                                <?php endif; ?>
                                <?php if ($authorId !== ''): ?>
                                <div class="rk-insc-row"><span class="rk-insc-k">Autor</span><span class="rk-insc-v"><?= htmlspecialchars($authorId) ?></span></div>
                                <?php endif; ?>
                                <?php if ($ignored): ?>
                                <div style="font-size:10px;color:var(--warning);margin-top:6px">Grupo marcado para ignorar</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="font-size:12px;color:var(--dim)">Sem historico detalhado disponivel.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="d-flex align-center justify-between mb-3">
        <div><div class="card-title">Indicadores de e-mail</div><div class="text-xs text-muted">Entregabilidade e engajamento registrados pelo Amazon SES.</div></div>
        <a class="btn btn-ghost btn-sm" href="email_dashboard.php">Abrir E-mail Marketing</a>
    </div>
    <div class="stats-grid">
        <?php foreach ([['sent','Aceitos pelo SES'],['delivered','Entregues'],['opened','Aberturas'],['clicked','Cliques'],['bounced','Bounces'],['complaints','Reclamações'],['unsubscribed','Descadastros']] as [$key,$label]): ?>
        <div class="stat-card"><div class="stat-label"><?= htmlspecialchars($label) ?></div><div class="stat-value"><?= number_format((int)($emailDashboard[$key] ?? 0),0,',','.') ?></div></div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function rkToggle(i) {
    var row = document.getElementById('rkrow-' + i);
    var det = document.getElementById('rkdet-' + i);
    if (!det) return;
    var open = det.classList.contains('open');
    det.classList.toggle('open', !open);
    row.classList.toggle('open', !open);
}
function grToggle(i) {
    var row = document.getElementById('grrow-' + i);
    var det = document.getElementById('grdet-' + i);
    if (!det) return;
    var open = det.classList.contains('open');
    det.classList.toggle('open', !open);
    row.classList.toggle('open', !open);
}
function rkToggleList(key) {
    var panel = document.getElementById(key === 'grupos' ? 'rkpanel-grupos' : 'rkpanel-insc');
    if (!panel) return;
    panel.classList.toggle('expanded');
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
