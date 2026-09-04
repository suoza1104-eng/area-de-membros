<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$menu = 'alunos';
$page_title = 'Erros de Login';

$pdo = getPDO();

if (!function_exists('table_ok')) {
    function table_ok(PDO $pdo, string $t): bool {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('col_ok')) {
    function col_ok(PDO $pdo, string $t, string $c): bool {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c");
            $st->execute([':c' => $c]); return (bool)$st->fetch();
        } catch (Throwable $e) { return false; }
    }
}

// Garantir schema da tabela login_events (auto-migração)
if (function_exists('am_ensure_login_events_schema')) {
    am_ensure_login_events_schema($pdo);
}

// --- FILTROS E PARÂMETROS ---
$q           = trim($_GET['q'] ?? '');
$preset      = trim($_GET['period'] ?? '30');
$leadFilter  = trim($_GET['lead_filter'] ?? 'all');
$salesFilter = trim($_GET['sales_filter'] ?? 'all');
$reason      = trim($_GET['reason'] ?? 'all');
$page        = max(1, (int)($_GET['page'] ?? 1));
$export      = trim($_GET['export'] ?? '');
$perPage     = 50;

// Calcular datas do período
$today = date('Y-m-d');
$startDate = '';
$endDate = '';

switch ($preset) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        break;
    case '7':
        $startDate = date('Y-m-d', strtotime('-6 days'));
        $endDate = $today;
        break;
    case '30':
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $endDate = $today;
        break;
    case '90':
        $startDate = date('Y-m-d', strtotime('-89 days'));
        $endDate = $today;
        break;
    case '365':
        $startDate = date('Y-m-d', strtotime('-364 days'));
        $endDate = $today;
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        break;
    case 'custom':
        $startDate = trim($_GET['from'] ?? date('Y-m-01'));
        $endDate   = trim($_GET['to'] ?? $today);
        break;
    default:
        $preset = 'all';
        $startDate = '';
        $endDate = '';
        break;
}

// 1. Carregar conjunto de leads (attribution_leads) em memória para cruzamento ultra-rápido
$leadMap = [];
try {
    if (table_ok($pdo, 'attribution_leads')) {
        $leadRows = $pdo->query("SELECT LOWER(TRIM(lead_email)) AS email, turma_codigo, created_at FROM attribution_leads WHERE lead_email IS NOT NULL AND lead_email <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($leadRows as $lr) {
            $e = strtolower(trim((string)$lr['email']));
            if ($e !== '' && !isset($leadMap[$e])) {
                $leadMap[$e] = [
                    'turma' => $lr['turma_codigo'] ?: 'Sem Turma',
                    'created_at' => $lr['created_at']
                ];
            }
        }
    }
} catch (Throwable $e) {}

// 2. Carregar conjunto de vendas (v_sales_master) em memória
$salesMap = [];
try {
    $salesRows = $pdo->query("SELECT DISTINCT LOWER(TRIM(buyer_email)) AS email, provider FROM v_sales_master WHERE buyer_email IS NOT NULL AND buyer_email <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($salesRows as $sr) {
        $e = strtolower(trim((string)$sr['email']));
        if ($e !== '') {
            $salesMap[$e] = $sr['provider'] ?: 'hotmart';
        }
    }
} catch (Throwable $e) {}

// 3. Montar Cláusulas SQL de login_events
$whereClauses = ["(le.success = 0 OR le.user_id IS NULL OR le.user_id = 0)"];
$params = [];

if ($startDate !== '' && $endDate !== '') {
    $whereClauses[] = "le.logged_at BETWEEN :start_date AND :end_date";
    $params['start_date'] = $startDate . ' 00:00:00';
    $params['end_date']   = $endDate . ' 23:59:59';
}

if ($reason !== '' && $reason !== 'all') {
    $whereClauses[] = "le.failure_reason = :reason";
    $params['reason'] = $reason;
}

$hasPasswordCol = col_ok($pdo, 'login_events', 'password_typed');
$selPassword = $hasPasswordCol ? "le.password_typed" : "NULL AS password_typed";

if ($q !== '') {
    if ($hasPasswordCol) {
        $whereClauses[] = "(le.email LIKE :q OR le.ip LIKE :q OR le.failure_reason LIKE :q OR le.user_agent LIKE :q OR le.password_typed LIKE :q)";
    } else {
        $whereClauses[] = "(le.email LIKE :q OR le.ip LIKE :q OR le.failure_reason LIKE :q OR le.user_agent LIKE :q)";
    }
    $params['q'] = '%' . $q . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

$rawEvents = [];
try {
    $allSql = "SELECT le.id, le.user_id, le.logged_at, le.ip, le.user_agent, le.method, le.success, le.failure_reason, $selPassword, LOWER(TRIM(le.email)) AS email
               FROM login_events le
               {$whereSql}
               ORDER BY le.logged_at DESC, le.id DESC";

    $stmtAll = $pdo->prepare($allSql);
    $stmtAll->execute($params);
    $rawEvents = $stmtAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

// Cruzamento e Filtragem
$filteredEvents = [];
$uniqueEmails = [];
$inLeadCount = 0;
$inSalesCount = 0;
$noLeadCount = 0;

foreach ($rawEvents as $ev) {
    $email = strtolower(trim((string)($ev['email'] ?? '')));
    $hasLead  = isset($leadMap[$email]);
    $hasSale  = isset($salesMap[$email]);
    
    // Aplicar Filtros de Lead e Vendas
    if ($leadFilter === 'has_lead' && !$hasLead) continue;
    if ($leadFilter === 'no_lead' && $hasLead) continue;
    if ($salesFilter === 'has_sale' && !$hasSale) continue;
    if ($salesFilter === 'no_sale' && $hasSale) continue;

    $ev['lead_info']  = $hasLead ? $leadMap[$email] : null;
    $ev['sales_info'] = $hasSale ? $salesMap[$email] : null;

    $filteredEvents[] = $ev;

    if ($email !== '') {
        $uniqueEmails[$email] = true;
        if ($hasLead) $inLeadCount++;
        else $noLeadCount++;
        if ($hasSale) $inSalesCount++;
    }
}

// Exportação CSV
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="logins_nao_identificados_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID Evento', 'Data e Hora', 'E-mail Digitado', 'Senha Digitada', 'Motivo da Falha',
        'Status em Leads', 'Turma Lead', 'Status em Vendas', 'IP Origem', 'Método', 'User Agent'
    ]);

    foreach ($filteredEvents as $r) {
        $leadStr = $r['lead_info'] ? 'SIM (Em Leads)' : 'NÃO (Fora de Leads)';
        $turmaStr = $r['lead_info'] ? ($r['lead_info']['turma'] ?? '-') : '-';
        $saleStr = $r['sales_info'] ? 'SIM (' . strtoupper((string)$r['sales_info']) . ')' : 'NÃO';

        fputcsv($output, [
            $r['id'],
            $r['logged_at'],
            $r['email'],
            $r['password_typed'] ?: '-',
            $r['failure_reason'] ?: 'user_not_found',
            $leadStr,
            $turmaStr,
            $saleStr,
            $r['ip'],
            $r['method'],
            $r['user_agent']
        ]);
    }
    fclose($output);
    exit;
}

// Padrão de Paginação
$totalRecords = count($filteredEvents);
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$offset       = ($page - 1) * $perPage;
$pagedEvents  = array_slice($filteredEvents, $offset, $perPage);

function lni_reason_badge(?string $reason): string {
    $r = strtolower(trim((string)$reason));
    switch ($r) {
        case 'user_not_found':
            return '<span class="reason-badge r-not-found"><i class="ph ph-user-minus"></i> Usuário Não Cadastrado</span>';
        case 'invalid_password':
            return '<span class="reason-badge r-password"><i class="ph ph-key-return"></i> Senha Incorreta</span>';
        case 'blocked_user':
            return '<span class="reason-badge r-blocked"><i class="ph ph-prohibit"></i> Usuário Bloqueado</span>';
        default:
            return '<span class="reason-badge r-default">' . htmlspecialchars($reason ?: 'Não identificado') . '</span>';
    }
}

// ─── Dataset de Erros de Login (Alunos Únicos e Tentativas) ───
$failedLoginDataSets = [
    'dia' => ['labels' => [], 'unicos' => [], 'tentativas' => []],
    'semana' => ['labels' => [], 'unicos' => [], 'tentativas' => []],
    'mes' => ['labels' => [], 'unicos' => [], 'tentativas' => []],
];

if (table_ok($pdo, 'login_events')) {
    try {
        $sqlDia = "
            SELECT 
                DATE_FORMAT(logged_at, '%d/%m') AS display_label,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(email), ''), CAST(user_id AS CHAR))) AS alunos_unicos,
                COUNT(*) AS total_tentativas
            FROM login_events
            WHERE success = 0
              AND logged_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(logged_at), DATE_FORMAT(logged_at, '%d/%m')
            ORDER BY DATE(logged_at) ASC
        ";
        foreach ($pdo->query($sqlDia)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $failedLoginDataSets['dia']['labels'][] = $r['display_label'];
            $failedLoginDataSets['dia']['unicos'][] = (int)$r['alunos_unicos'];
            $failedLoginDataSets['dia']['tentativas'][] = (int)$r['total_tentativas'];
        }

        $sqlSemana = "
            SELECT 
                CONCAT('Sem ', DATE_FORMAT(logged_at, '%v/%Y')) AS display_label,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(email), ''), CAST(user_id AS CHAR))) AS alunos_unicos,
                COUNT(*) AS total_tentativas
            FROM login_events
            WHERE success = 0
              AND logged_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
            GROUP BY DATE_FORMAT(logged_at, '%x-W%v'), CONCAT('Sem ', DATE_FORMAT(logged_at, '%v/%Y'))
            ORDER BY DATE_FORMAT(logged_at, '%x-W%v') ASC
        ";
        foreach ($pdo->query($sqlSemana)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $failedLoginDataSets['semana']['labels'][] = $r['display_label'];
            $failedLoginDataSets['semana']['unicos'][] = (int)$r['alunos_unicos'];
            $failedLoginDataSets['semana']['tentativas'][] = (int)$r['total_tentativas'];
        }

        $sqlMes = "
            SELECT 
                DATE_FORMAT(logged_at, '%m/%Y') AS display_label,
                COUNT(DISTINCT COALESCE(NULLIF(TRIM(email), ''), CAST(user_id AS CHAR))) AS alunos_unicos,
                COUNT(*) AS total_tentativas
            FROM login_events
            WHERE success = 0
              AND logged_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(logged_at, '%Y-%m'), DATE_FORMAT(logged_at, '%m/%Y')
            ORDER BY DATE_FORMAT(logged_at, '%Y-%m') ASC
        ";
        foreach ($pdo->query($sqlMes)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $failedLoginDataSets['mes']['labels'][] = $r['display_label'];
            $failedLoginDataSets['mes']['unicos'][] = (int)$r['alunos_unicos'];
            $failedLoginDataSets['mes']['tentativas'][] = (int)$r['total_tentativas'];
        }
    } catch (Throwable $e) {}
}

$recTypoCount = 0;
$recAutoRegCount = 0;
try {
    if (table_ok($pdo, 'login_recovery_events')) {
        $stRec = $pdo->query("
            SELECT event_type, COUNT(*) AS total
            FROM login_recovery_events
            GROUP BY event_type
        ");
        while ($rRec = $stRec->fetch(PDO::FETCH_ASSOC)) {
            if ($rRec['event_type'] === 'typo_corrected') $recTypoCount = (int)$rRec['total'];
            if ($rRec['event_type'] === 'auto_registered') $recAutoRegCount = (int)$rRec['total'];
        }
    }
} catch (Throwable $e) {}

require_once __DIR__ . '/_header.php';
?>
<script src="https://unpkg.com/@phosphor-icons/web"></script>

<style>
.lni-container { display: flex; flex-direction: column; gap: 18px; font-family: system-ui, -apple-system, sans-serif; color: var(--text); }
.lni-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; background: var(--bg-card); border: 1px solid var(--border); padding: 18px 20px; border-radius: 12px; }
.lni-title h1 { font-size: 22px; margin: 0; font-weight: 750; color: #f8fafc; }
.lni-title p { margin: 4px 0 0; font-size: 13px; color: var(--muted); }
.lni-nav-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); border-radius: 10px; color: #60a5fa; font-weight: 650; font-size: 13px; text-decoration: none; transition: all .15s ease; }
.lni-nav-btn:hover { background: rgba(59,130,246,0.25); border-color: rgba(59,130,246,0.5); color: #93c5fd; }

.lni-cards { display: grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap: 12px; }
.lni-card { background: var(--bg-card); border: 1px solid var(--border); padding: 14px 16px; border-radius: 10px; position: relative; overflow: hidden; }
.lni-card small { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); display: block; }
.lni-card strong { font-size: 22px; font-weight: 800; margin-top: 6px; display: block; color: #f1f5f9; }
.lni-card.lead-ok strong { color: #4ade80; }
.lni-card.alert-card strong { color: #fb923c; }

.lni-filter-box { background: var(--bg-card); border: 1px solid var(--border); padding: 16px; border-radius: 12px; }
.lni-presets { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.lni-presets a { padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; color: var(--muted); font-size: 12px; font-weight: 600; text-decoration: none; }
.lni-presets a.active, .lni-presets a:hover { background: rgba(250,204,21,0.15); border-color: rgba(250,204,21,0.4); color: #facc15; }

.lni-grid { display: grid; grid-template-columns: 2fr 1.2fr 1fr 1fr auto; gap: 10px; align-items: end; }
@media (max-width: 1100px) { .lni-grid { grid-template-columns: 1fr 1fr 1fr; } }
@media (max-width: 700px) { .lni-grid { grid-template-columns: 1fr; } .lni-cards { grid-template-columns: 1fr 1fr; } }

.lni-fg label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 5px; }
.lni-fg input, .lni-fg select { width: 100%; height: 38px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: #f1f5f9; padding: 0 10px; font-size: 12px; }
.lni-actions { display: flex; gap: 8px; }
.lni-btn { height: 38px; padding: 0 16px; border-radius: 8px; border: 0; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
.lni-btn-primary { background: #3b82f6; color: #fff; }
.lni-btn-primary:hover { background: #2563eb; }
.lni-btn-csv { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
.lni-btn-csv:hover { background: rgba(34,197,94,0.3); }

.lni-table-wrap { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; overflow: auto; }
.lni-table { width: 100%; border-collapse: collapse; min-width: 1050px; font-size: 12px; }
.lni-table th { background: #0f172a; color: var(--muted); text-transform: uppercase; font-size: 10px; letter-spacing: .06em; padding: 12px; text-align: left; border-bottom: 1px solid var(--border); position: sticky; top: 0; }
.lni-table td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
.lni-table tr:hover td { background: rgba(255,255,255,0.02); }

.reason-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 750; }
.r-not-found { background: rgba(249,115,22,0.18); color: #fb923c; border: 1px solid rgba(249,115,22,0.3); }
.r-password { background: rgba(234,179,8,0.18); color: #facc15; border: 1px solid rgba(234,179,8,0.3); }
.r-blocked { background: rgba(239,68,68,0.18); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
.r-default { background: var(--bg-hover); color: var(--text); }

.lead-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; font-size: 11px; font-weight: 650; }
.lb-yes { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
.lb-no { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }

.sales-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; font-size: 11px; font-weight: 650; }
.sb-yes { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.sb-no { background: rgba(148,163,184,0.12); color: #94a3b8; border: 1px solid rgba(148,163,184,0.25); }

.lni-paging { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-top: 1px solid var(--border); color: var(--muted); font-size: 12px; }
.lni-page-links { display: flex; gap: 6px; }
.lni-page-links a, .lni-page-links span { padding: 6px 12px; border: 1px solid var(--border); border-radius: 7px; text-decoration: none; color: var(--text); }
.lni-page-links .active { background: #3b82f6; color: #fff; border-color: #3b82f6; }

/* ── Granularity buttons (Erros de Login) ─────────────────── */
.btn-gran {
    background: transparent;
    border: none;
    color: var(--muted);
    padding: 5px 12px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all .15s ease;
}
.btn-gran:hover { color: var(--text); }
.btn-gran.active {
    background: rgba(250,204,21,0.15);
    color: #facc15;
    font-weight: 700;
}
</style>

<div class="lni-container">
  
  <!-- CABEÇALHO COM NAVEGAÇÃO -->
  <div class="lni-head">
    <div class="lni-title">
      <h1>🚨 Tentativas de Login Não Identificadas</h1>
      <p>Lista de e-mails que tentaram acessar a área de membros sem cadastro de usuário atrelado.</p>
    </div>
    <a href="alunos.php" class="lni-nav-btn">
      <i class="ph ph-arrow-left"></i> ⬅️ Voltar para a Lista de Alunos
    </a>
  </div>

  <!-- 1. FORMULÁRIO DE FILTROS (NO TOPO) -->
  <div class="lni-filter-box">
    <div class="lni-presets">
      <?php 
      $presetOptions = [
          'all' => 'Todas as Datas',
          'today' => 'Hoje',
          '7' => '7 Dias',
          '30' => '30 Dias',
          '90' => '90 Dias',
          '365' => '365 Dias',
          'month' => 'Mês Atual',
          'custom' => 'Personalizado'
      ];
      foreach ($presetOptions as $k => $lbl): 
      ?>
        <a class="<?= $preset === $k ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['period' => $k, 'page' => 1]))) ?>">
          <?= htmlspecialchars($lbl) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" class="lni-grid">
      <input type="hidden" name="period" value="<?= htmlspecialchars($preset) ?>">
      
      <div class="lni-fg">
        <label>Buscar E-mail / IP / Dispositivo</label>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="E-mail digitado ou IP...">
      </div>

      <div class="lni-fg">
        <label>Motivo do Erro</label>
        <select name="reason">
          <option value="all" <?= $reason === 'all' ? 'selected' : '' ?>>Todos os Motivos</option>
          <option value="user_not_found" <?= $reason === 'user_not_found' ? 'selected' : '' ?>>Usuário Não Cadastrado</option>
          <option value="invalid_password" <?= $reason === 'invalid_password' ? 'selected' : '' ?>>Senha Incorreta</option>
          <option value="blocked_user" <?= $reason === 'blocked_user' ? 'selected' : '' ?>>Usuário Bloqueado</option>
        </select>
      </div>

      <div class="lni-fg">
        <label>Status em Leads</label>
        <select name="lead_filter">
          <option value="all" <?= $leadFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="has_lead" <?= $leadFilter === 'has_lead' ? 'selected' : '' ?>>Presente em Leads</option>
          <option value="no_lead" <?= $leadFilter === 'no_lead' ? 'selected' : '' ?>>Fora da Lista de Leads</option>
        </select>
      </div>

      <div class="lni-fg">
        <label>Status em Vendas</label>
        <select name="sales_filter">
          <option value="all" <?= $salesFilter === 'all' ? 'selected' : '' ?>>Todos</option>
          <option value="has_sale" <?= $salesFilter === 'has_sale' ? 'selected' : '' ?>>Possui Compra Registrada</option>
          <option value="no_sale" <?= $salesFilter === 'no_sale' ? 'selected' : '' ?>>Sem Compra Registrada</option>
        </select>
      </div>

      <?php if ($preset === 'custom'): ?>
        <div class="lni-fg">
          <label>Data Início</label>
          <input type="date" name="from" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div class="lni-fg">
          <label>Data Fim</label>
          <input type="date" name="to" value="<?= htmlspecialchars($endDate) ?>">
        </div>
      <?php endif; ?>

      <div class="lni-actions">
        <button type="submit" class="lni-btn lni-btn-primary">
          <i class="ph ph-magnifying-glass"></i> Filtrar
        </button>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="lni-btn lni-btn-csv">
          <i class="ph ph-download-simple"></i> Exportar CSV
        </a>
      </div>
    </form>
  </div>

  <!-- 2. CARDS DE RESUMO (ABAIXO DOS FILTROS) -->
  <div class="lni-cards">
    <div class="lni-card">
      <small>Tentativas Auditadas</small>
      <strong><?= number_format($totalRecords, 0, ',', '.') ?></strong>
    </div>
    <div class="lni-card">
      <small>E-mails Únicos Tentando Acesso</small>
      <strong><?= number_format(count($uniqueEmails), 0, ',', '.') ?></strong>
    </div>
    <div class="lni-card lead-ok">
      <small>Presentes na Captura de Leads</small>
      <strong><?= number_format($inLeadCount, 0, ',', '.') ?></strong>
    </div>
    <div class="lni-card alert-card">
      <small>Fora da Captura de Leads</small>
      <strong><?= number_format($noLeadCount, 0, ',', '.') ?></strong>
    </div>
  </div>

  <!-- 2.3 FUNIL DE RECUPERAÇÃO DE LOGIN -->
  <div style="background: linear-gradient(135deg, rgba(250, 204, 21, 0.08), rgba(15, 23, 42, 0.6)); border: 1px solid rgba(250, 204, 21, 0.3); border-radius: 12px; padding: 16px 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
      <h3 style="font-size: 15px; font-weight: 750; margin: 0; color: #facc15; display: flex; align-items: center; gap: 8px;">
        <span>🛡️ Funil de Recuperação de Login Inteligente</span>
      </h3>
      <a href="config_app.php" style="font-size: 12px; color: #fde047; text-decoration: underline; font-weight: 600;">Configurar Modal &amp; Simulador →</a>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
      <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px;">
        <div style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;">Typo Corrigido (Email Errado)</div>
        <div style="font-size: 22px; font-weight: 800; color: #fde047; margin-top: 2px;"><?= number_format($recTypoCount, 0, ',', '.') ?></div>
      </div>
      <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px;">
        <div style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;">Auto-Cadastros / Acesso Liberado</div>
        <div style="font-size: 22px; font-weight: 800; color: #4ade80; margin-top: 2px;"><?= number_format($recAutoRegCount, 0, ',', '.') ?></div>
      </div>
      <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px;">
        <div style="font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: 0.05em;">Total Alunos Salvos</div>
        <div style="font-size: 22px; font-weight: 800; color: #60a5fa; margin-top: 2px;"><?= number_format($recTypoCount + $recAutoRegCount, 0, ',', '.') ?></div>
      </div>
    </div>
  </div>

  <!-- 2.5 GRÁFICO DE BARRAS DE ALUNOS ÚNICOS COM ERRO -->
  <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
      <div>
        <h3 style="font-size:15px; font-weight:750; margin:0; color:#f8fafc; display:flex; align-items:center; gap:8px;">
          <span>📊 Evolução de Alunos Únicos com Erro de Login</span>
        </h3>
        <p style="font-size:12px; color:var(--muted); margin:4px 0 0;">
          Gráfico comparativo de alunos únicos e tentativas totais com erro agrupados por dia, semana ou mês.
        </p>
      </div>
      <div style="display:flex; align-items:center; gap:10px;">
        <div style="display:inline-flex; background:var(--bg,#090d16); border:1px solid var(--border); border-radius:8px; padding:2px;">
          <button type="button" class="btn-gran active" data-gran="dia" onclick="setFailedLoginGranularity('dia')">Por Dia</button>
          <button type="button" class="btn-gran" data-gran="semana" onclick="setFailedLoginGranularity('semana')">Por Semana</button>
          <button type="button" class="btn-gran" data-gran="mes" onclick="setFailedLoginGranularity('mes')">Por Mês</button>
        </div>
      </div>
    </div>

    <!-- Mini KPIs do Gráfico -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:16px;">
      <div style="background:rgba(239,68,68,.06); border:1px solid rgba(239,68,68,.2); border-radius:10px; padding:10px 14px;">
        <div style="font-size:10px; text-transform:uppercase; color:var(--muted); letter-spacing:.05em;">Alunos Únicos c/ Erro</div>
        <div id="fl-kpi-unicos" style="font-size:20px; font-weight:700; color:#f87171; margin-top:2px;">-</div>
      </div>
      <div style="background:rgba(249,115,22,.06); border:1px solid rgba(249,115,22,.2); border-radius:10px; padding:10px 14px;">
        <div style="font-size:10px; text-transform:uppercase; color:var(--muted); letter-spacing:.05em;">Total Tentativas c/ Erro</div>
        <div id="fl-kpi-tentativas" style="font-size:20px; font-weight:700; color:#fb923c; margin-top:2px;">-</div>
      </div>
      <div style="background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:10px; padding:10px 14px;">
        <div style="font-size:10px; text-transform:uppercase; color:var(--muted); letter-spacing:.05em;">Média no Período</div>
        <div id="fl-kpi-media" style="font-size:20px; font-weight:700; color:var(--text); margin-top:2px;">-</div>
      </div>
    </div>

    <div style="position:relative; height:240px; width:100%;">
      <canvas id="chartFailedLogins"></canvas>
    </div>
  </div>

  <!-- 3. TABELA DE TENTATIVAS DE LOGIN -->
  <div class="lni-table-wrap">
    <table class="lni-table">
      <thead>
        <tr>
          <th>Data / Hora</th>
          <th>E-mail Digitado</th>
          <th>Senha Digitada</th>
          <th>Motivo do Erro</th>
          <th>Status em Leads</th>
          <th>Status em Vendas</th>
          <th>IP & Método</th>
          <th>Navegador / Dispositivo</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pagedEvents)): ?>
          <tr>
            <td colspan="8" style="text-align:center; padding: 40px; color: var(--muted);">
              Nenhuma tentativa de login não identificada encontrada para os filtros aplicados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($pagedEvents as $r): ?>
            <tr>
              <td>
                <strong style="color: #f1f5f9; font-size: 12px; display:block;">
                  <?= date('d/m/Y H:i:s', strtotime((string)$r['logged_at'])) ?>
                </strong>
              </td>
              <td>
                <strong style="color: #93c5fd; font-size: 13px;">
                  <?= htmlspecialchars((string)($r['email'] ?: 'Não informado')) ?>
                </strong>
              </td>
              <td>
                <code style="color: #f87171; font-size: 12px; font-weight: 600; background: rgba(239,68,68,0.1); padding: 3px 8px; border-radius: 6px; border: 1px solid rgba(239,68,68,0.25); display: inline-block;">
                  <?= htmlspecialchars((string)($r['password_typed'] ?: '-')) ?>
                </code>
              </td>
              <td>
                <?= lni_reason_badge((string)$r['failure_reason']) ?>
              </td>
              <td>
                <?php if ($r['lead_info']): ?>
                  <span class="lead-badge lb-yes">
                    <i class="ph ph-check"></i> Lead (Turma <?= htmlspecialchars((string)$r['lead_info']['turma']) ?>)
                  </span>
                <?php else: ?>
                  <span class="lead-badge lb-no">
                    <i class="ph ph-x"></i> Fora da Lista
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['sales_info']): ?>
                  <span class="sales-badge sb-yes">
                    <i class="ph ph-shopping-cart-simple"></i> Comprador (<?= htmlspecialchars(strtoupper((string)$r['sales_info'])) ?>)
                  </span>
                <?php else: ?>
                  <span class="sales-badge sb-no">
                    <i class="ph ph-minus"></i> Sem Compra
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <div style="font-family: monospace; font-size: 11px; color: #cbd5e1;">
                  <?= htmlspecialchars((string)$r['ip']) ?>
                </div>
                <div style="font-size: 10px; color: var(--muted); margin-top: 2px;">
                  <?= htmlspecialchars((string)($r['method'] ?: 'normal')) ?>
                </div>
              </td>
              <td>
                <div style="font-size: 10px; color: var(--muted); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars((string)$r['user_agent']) ?>">
                  <?= htmlspecialchars((string)$r['user_agent']) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPages > 1): ?>
      <div class="lni-paging">
        <div>
          Exibindo página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (Total: <?= number_format($totalRecords, 0, ',', '.') ?> tentativas)
        </div>
        <div class="lni-page-links">
          <?php if ($page > 1): ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Anterior</a>
          <?php endif; ?>
          
          <?php
          $startP = max(1, $page - 2);
          $endP   = min($totalPages, $page + 2);
          for ($i = $startP; $i <= $endP; $i++):
          ?>
            <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))) ?>">
              <?= $i ?>
            </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Próxima</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
const failedLoginData = <?= json_encode($failedLoginDataSets) ?>;
let flChartInstance = null;

function renderFailedLoginsChart(granularity) {
    granularity = granularity || 'dia';
    const dataObj = failedLoginData[granularity] || { labels: [], unicos: [], tentativas: [] };
    
    const totalUnicos = dataObj.unicos.reduce(function(a, b){ return a + b; }, 0);
    const totalTentativas = dataObj.tentativas.reduce(function(a, b){ return a + b; }, 0);
    const countItems = dataObj.unicos.length;
    const mediaUnicos = countItems > 0 ? (totalUnicos / countItems).toFixed(1) : '0';
    const sufixo = granularity === 'dia' ? '/dia' : granularity === 'semana' ? '/semana' : '/mês';

    const elemUnicos = document.getElementById('fl-kpi-unicos');
    const elemTentativas = document.getElementById('fl-kpi-tentativas');
    const elemMedia = document.getElementById('fl-kpi-media');

    if (elemUnicos) elemUnicos.textContent = totalUnicos.toLocaleString('pt-BR');
    if (elemTentativas) elemTentativas.textContent = totalTentativas.toLocaleString('pt-BR');
    if (elemMedia) elemMedia.textContent = mediaUnicos.replace('.', ',') + ' ' + sufixo;

    const canvasElem = document.getElementById('chartFailedLogins');
    if (!canvasElem) return;
    const ctx = canvasElem.getContext('2d');
    if (flChartInstance) {
        flChartInstance.destroy();
    }

    flChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dataObj.labels,
            datasets: [
                {
                    label: 'Alunos Únicos com Erro',
                    data: dataObj.unicos,
                    backgroundColor: 'rgba(239, 68, 68, 0.75)',
                    borderColor: '#ef4444',
                    borderWidth: 1,
                    borderRadius: 4,
                    hoverBackgroundColor: 'rgba(239, 68, 68, 0.95)'
                },
                {
                    label: 'Total Tentativas com Erro',
                    data: dataObj.tentativas,
                    backgroundColor: 'rgba(249, 115, 22, 0.35)',
                    borderColor: '#f97316',
                    borderWidth: 1,
                    borderRadius: 4,
                    hoverBackgroundColor: 'rgba(249, 115, 22, 0.65)'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#94a3b8',
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    borderColor: '#334155',
                    borderWidth: 1,
                    titleColor: '#f8fafc',
                    bodyColor: '#cbd5e1',
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            return ' ' + context.dataset.label + ': ' + context.parsed.y.toLocaleString('pt-BR');
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#94a3b8', font: { size: 10 } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 10 },
                        precision: 0
                    }
                }
            }
        }
    });
}

function setFailedLoginGranularity(gran) {
    document.querySelectorAll('.btn-gran').forEach(function(btn) {
        if (btn.dataset.gran === gran) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    renderFailedLoginsChart(gran);
}

document.addEventListener('DOMContentLoaded', function() {
    renderFailedLoginsChart('dia');
});
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
