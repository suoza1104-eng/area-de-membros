<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
session_start();
proteger_admin();

$menu = 'vendas_analytics';
$page_title = 'Analise de Vendas';

$pdo = getPDO();

/**
 * Helpers compatíveis com PHP 7.4
 */
function getArrayParam(string $key): array {
    if (!isset($_GET[$key])) return [];
    $v = $_GET[$key];

    if (is_array($v)) {
        $v = array_map('trim', $v);
        $v = array_values(array_filter($v, function($x){ return $x !== ''; }));
        return $v;
    }

    $v = trim((string)$v);
    return $v === '' ? [] : [$v];
}

function safeDim(string $dim): string {
    $allowed = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content'];
    return in_array($dim, $allowed, true) ? $dim : 'utm_source';
}

function detectDateColumn(PDO $pdo, string $table, array $candidates): ?string {
    $cols = [];
    try {
        $stmt = $pdo->query("DESCRIBE `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $cols[] = (string)$r['Field'];
    } catch (\Throwable $e) {
        return null;
    }
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

function tableExists(PDO $pdo, string $table): bool {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 0");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function detectColumn(PDO $pdo, string $table, array $candidates): ?string {
    $cols = [];
    try {
        $stmt = $pdo->query("DESCRIBE `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $cols[] = (string)$r['Field'];
    } catch (\Throwable $e) {
        return null;
    }
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

/**
 * Monta WHERE+PARAMS (suporta filtro por curso/produto em vendas)
 */
function buildFilters(
    string $tableAlias,
    string $status,
    string $dtIni,
    string $dtFim,
    bool $includeOrganic,

    array $f_source,
    array $f_medium,
    array $f_campaign,
    array $f_term,
    array $f_content,

    array $f_products,
    bool $applyProducts,

    ?string $skipCol = null,
    bool $applyIncludeOrganic = true,
    ?string $dateCol = null,
    bool $applyStatus = true
): array {
    $where = [];
    $params = [];

    if ($applyStatus) {
        if ($status === 'aprovadas') {
            $where[] = "$tableAlias.status IN ('Aprovado','Completo')";
        } elseif ($status === 'todas') {
            // nada
        } else {
            $where[] = "$tableAlias.status = :st";
            $params[':st'] = $status;
        }
    }

    if ($dateCol) {
        if ($dtIni !== '') {
            $where[] = "$tableAlias.`$dateCol` >= :ini";
            $params[':ini'] = $dtIni . " 00:00:00";
        }
        if ($dtFim !== '') {
            $where[] = "$tableAlias.`$dateCol` <= :fim";
            $params[':fim'] = $dtFim . " 23:59:59";
        }
    }

    if ($applyIncludeOrganic && !$includeOrganic) {
        $where[] = "COALESCE(NULLIF($tableAlias.utm_source,''),'organico') <> 'organico'";
    }

    $applyIn = function(string $col, array $vals, string $prefix) use (&$where, &$params, $skipCol, $tableAlias) {
        if ($skipCol === $col) return;
        if (!$vals) return;

        $ph = [];
        foreach ($vals as $i => $v) {
            $k = ':' . $prefix . $i;
            $ph[] = $k;
            $params[$k] = $v;
        }
        $where[] = "COALESCE(NULLIF($tableAlias.$col,''),'(vazio)') IN (" . implode(',', $ph) . ")";
    };

    // UTMs
    $applyIn('utm_source',   $f_source,   'src');
    $applyIn('utm_medium',   $f_medium,   'med');
    $applyIn('utm_campaign', $f_campaign, 'cam');
    $applyIn('utm_term',     $f_term,     'ter');
    $applyIn('utm_content',  $f_content,  'con');

    // Produtos (somente vendas)
    if ($applyProducts) {
        $applyIn('product_name', $f_products, 'prd');
    }

    return [$where, $params];
}

function fetchOptions(PDO $pdo, string $table, string $tableAlias, string $column, array $where, array $params, string $joinSql = ''): array {
    $sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";
    $stmt = $pdo->prepare("
        SELECT DISTINCT COALESCE(NULLIF($tableAlias.$column,''),'(vazio)') AS v
        FROM `$table` $tableAlias
        $joinSql
        $sqlWhere
        ORDER BY v ASC
        LIMIT 900
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){ return (string)$r['v']; }, $rows);
}

function appendInFilter(array &$where, array &$params, string $expr, array $vals, string $prefix): void {
    if (!$vals) return;
    $ph = [];
    foreach ($vals as $i => $v) {
        $k = ':' . $prefix . $i;
        $ph[] = $k;
        $params[$k] = $v;
    }
    $where[] = "$expr IN (" . implode(',', $ph) . ")";
}

/**
 * Inputs
 */
$dim = safeDim($_GET['dim'] ?? 'utm_source');
$dtIni = trim((string)($_GET['ini'] ?? ''));
$dtFim = trim((string)($_GET['fim'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'aprovadas'));
$includeOrganic = ((string)($_GET['include_organic'] ?? '1') === '1');

$f_source   = getArrayParam('f_source');
$f_medium   = getArrayParam('f_medium');
$f_campaign = getArrayParam('f_campaign');
$f_term     = getArrayParam('f_term');
$f_content  = getArrayParam('f_content');
$f_products = getArrayParam('f_products');
$f_turmas   = getArrayParam('f_turmas');

$limit = 25;

// Sorting tabela
$sort = (string)($_GET['sort'] ?? 'vendas');
$dir  = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$allowedSort = ['label','leads','vendas','lucro'];
if (!in_array($sort, $allowedSort, true)) $sort = 'vendas';

// Datas
$salesDateCol = 'transaction_date';
$leadDateCol = detectDateColumn($pdo, 'users', [
    'created_at','data_cadastro','dt_cadastro','created','data','date_created','registered_at','cadastro_em'
]);
$hasInscricaoLogs = tableExists($pdo, 'inscricao_logs');
$userTurmaCol = detectColumn($pdo, 'users', ['codigo_turma','turma_codigo','turma','turma_id']);

$approvedStatuses = ["'Aprovado'", "'Completo'"];
$refundStatuses = ["'Reembolsado'", "'Chargeback'"];
$approvedStatusSql = implode(',', $approvedStatuses);
$refundStatusSql = implode(',', $refundStatuses);

$turmaHistExprSales = $hasInscricaoLogs
    ? "(SELECT il.codigo_turma
          FROM inscricao_logs il
         WHERE il.user_id = s.matched_user_id
           AND il.codigo_turma IS NOT NULL
           AND il.codigo_turma <> ''
           AND (s.transaction_date IS NULL OR il.created_at <= s.transaction_date)
         ORDER BY il.created_at DESC
         LIMIT 1)"
    : "NULL";
$salesJoinUserForTurma = $userTurmaCol ? "LEFT JOIN users us ON us.id = s.matched_user_id" : "";
$turmaCurrentExprSales = $userTurmaCol ? "us.`$userTurmaCol`" : "NULL";
$salesTurmaExpr = "COALESCE(NULLIF($turmaHistExprSales,''), NULLIF($turmaCurrentExprSales,''), 'Sem turma')";

$turmaCurrentExprLeads = $userTurmaCol ? "u.`$userTurmaCol`" : "NULL";
$leadTurmaExpr = "COALESCE(NULLIF($turmaCurrentExprLeads,''), 'Sem turma')";

/**
 * ===== VENDAS filtros =====
 */
list($whereSales, $paramsSales) = buildFilters(
    's', $status, $dtIni, $dtFim, $includeOrganic,
    $f_source, $f_medium, $f_campaign, $f_term, $f_content,
    $f_products, true,
    null, true, $salesDateCol, true
);
appendInFilter($whereSales, $paramsSales, $salesTurmaExpr, $f_turmas, 'turma');
$sqlWhereSales = $whereSales ? ("WHERE " . implode(" AND ", $whereSales)) : "";
$sqlWhereSalesLag = $sqlWhereSales ? $sqlWhereSales : "WHERE 1=1";

/**
 * ===== LEADS filtros =====
 */
list($whereLeads, $paramsLeads) = buildFilters(
    'u', 'todas', $dtIni, $dtFim, $includeOrganic,
    $f_source, $f_medium, $f_campaign, $f_term, $f_content,
    [], false,
    null, true, $leadDateCol, false
);
if ($userTurmaCol) {
    appendInFilter($whereLeads, $paramsLeads, $leadTurmaExpr, $f_turmas, 'lturma');
}
$sqlWhereLeads = $whereLeads ? ("WHERE " . implode(" AND ", $whereLeads)) : "";

/**
 * ===== Gráficos vendas/lucro =====
 */
$stmtSalesAgg = $pdo->prepare("
  SELECT
    COALESCE(NULLIF(s.$dim,''), 'organico') AS label,
    COUNT(*) AS vendas,
    COALESCE(SUM(s.producer_net),0) AS lucro
  FROM hotmart_sales s
  $salesJoinUserForTurma
  $sqlWhereSales
  GROUP BY label
  ORDER BY vendas DESC
  LIMIT $limit
");
$stmtSalesAgg->execute($paramsSales);
$dataSales = $stmtSalesAgg->fetchAll(PDO::FETCH_ASSOC);

$labelsSales = array_map(function($r){ return (string)$r['label']; }, $dataSales);
$vendasArr   = array_map(function($r){ return (int)$r['vendas']; }, $dataSales);
$lucroArr    = array_map(function($r){ return (float)$r['lucro']; }, $dataSales);

/**
 * ===== Gráfico leads =====
 */
$stmtLeadsAgg = $pdo->prepare("
  SELECT
    COALESCE(NULLIF(u.$dim,''), 'organico') AS label,
    COUNT(*) AS leads
  FROM users u
  $sqlWhereLeads
  GROUP BY label
  ORDER BY leads DESC
  LIMIT $limit
");
$stmtLeadsAgg->execute($paramsLeads);
$dataLeads = $stmtLeadsAgg->fetchAll(PDO::FETCH_ASSOC);

$labelsLeads = array_map(function($r){ return (string)$r['label']; }, $dataLeads);
$leadsArr    = array_map(function($r){ return (int)$r['leads']; }, $dataLeads);

/**
 * ===== Cards =====
 */
$stmtSalesTotals = $pdo->prepare("
  SELECT COUNT(*) AS vendas_total, COALESCE(SUM(s.producer_net),0) AS lucro_total
  FROM hotmart_sales s
  $salesJoinUserForTurma
  $sqlWhereSales
");
$stmtSalesTotals->execute($paramsSales);
$salesTotals = $stmtSalesTotals->fetch(PDO::FETCH_ASSOC) ?: ['vendas_total'=>0,'lucro_total'=>0];

$stmtLeadsTotals = $pdo->prepare("
  SELECT COUNT(*) AS leads_total
  FROM users u
  $sqlWhereLeads
");
$stmtLeadsTotals->execute($paramsLeads);
$leadsTotals = $stmtLeadsTotals->fetch(PDO::FETCH_ASSOC) ?: ['leads_total'=>0];

/**
 * ===== Tabela UTM (Leads + Vendas + Lucro) =====
 */
$orderByMap = [
    'label' => 't.label',
    'leads' => 't.leads',
    'vendas'=> 't.vendas',
    'lucro' => 't.lucro',
];
$orderBy = $orderByMap[$sort] . " " . $dir;

// Observação: executamos usando paramsSales (vendas) e paramsLeads (leads).
// Se seu PDO reclamar de placeholders duplicados, me avise que eu te mando a versão com placeholders prefixados.
$stmtTable = $pdo->prepare("
  SELECT
    t.label,
    COALESCE(l.leads, 0) AS leads,
    COALESCE(t.vendas, 0) AS vendas,
    COALESCE(t.lucro, 0) AS lucro
  FROM (
      SELECT
        COALESCE(NULLIF(s.$dim,''), 'organico') AS label,
        COUNT(*) AS vendas,
        COALESCE(SUM(s.producer_net),0) AS lucro
      FROM hotmart_sales s
      $salesJoinUserForTurma
      $sqlWhereSales
      GROUP BY label
  ) t
  LEFT JOIN (
      SELECT
        COALESCE(NULLIF(u.$dim,''), 'organico') AS label,
        COUNT(*) AS leads
      FROM users u
      $sqlWhereLeads
      GROUP BY label
  ) l ON l.label = t.label
  ORDER BY $orderBy
  LIMIT $limit
");
$stmtTable->execute(array_merge($paramsSales, $paramsLeads));
$tableRows = $stmtTable->fetchAll(PDO::FETCH_ASSOC);

/**
 * ===== Turmas (receita + conversao)
 *
 * Atribuicao da venda:
 * 1) turma historica em inscricao_logs antes da compra;
 * 2) turma atual do aluno em users;
 * 3) Sem turma.
 */
$salesTurmaRows = [];
$leadsByTurma = [];
$turmaRows = [];

try {
    $stmtTurmaSales = $pdo->prepare("
        SELECT
          x.turma,
          COUNT(*) AS vendas,
          COUNT(DISTINCT x.matched_user_id) AS compradores,
          COALESCE(SUM(x.producer_net),0) AS receita,
          SUM(CASE WHEN NULLIF(x.turma_hist,'') IS NOT NULL THEN 1 ELSE 0 END) AS vendas_historico,
          SUM(CASE WHEN NULLIF(x.turma_hist,'') IS NULL AND NULLIF(x.turma_atual,'') IS NOT NULL THEN 1 ELSE 0 END) AS vendas_turma_atual,
          SUM(CASE WHEN NULLIF(x.turma_hist,'') IS NULL AND NULLIF(x.turma_atual,'') IS NULL THEN 1 ELSE 0 END) AS vendas_sem_turma
        FROM (
          SELECT
            s.matched_user_id,
            s.producer_net,
            $turmaHistExprSales AS turma_hist,
            $turmaCurrentExprSales AS turma_atual,
            $salesTurmaExpr AS turma
          FROM hotmart_sales s
          $salesJoinUserForTurma
          $sqlWhereSales
        ) x
        GROUP BY turma
        ORDER BY receita DESC, vendas DESC
        LIMIT 100
    ");
    $stmtTurmaSales->execute($paramsSales);
    $salesTurmaRows = $stmtTurmaSales->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $salesTurmaRows = [];
}

if ($userTurmaCol) {
    try {
        $stmtTurmaLeads = $pdo->prepare("
            SELECT
              $leadTurmaExpr AS turma,
              COUNT(*) AS leads
            FROM users u
            $sqlWhereLeads
            GROUP BY turma
        ");
        $stmtTurmaLeads->execute($paramsLeads);
        foreach (($stmtTurmaLeads->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $leadsByTurma[(string)$r['turma']] = (int)$r['leads'];
        }
    } catch (\Throwable $e) {
        $leadsByTurma = [];
    }
}

foreach ($salesTurmaRows as $r) {
    $turma = (string)$r['turma'];
    $leads = $leadsByTurma[$turma] ?? 0;
    $compradores = (int)($r['compradores'] ?? 0);
    $turmaRows[] = [
        'turma' => $turma,
        'leads' => $leads,
        'vendas' => (int)($r['vendas'] ?? 0),
        'compradores' => $compradores,
        'receita' => (float)($r['receita'] ?? 0),
        'conversao' => $leads > 0 ? round(($compradores / $leads) * 100, 1) : null,
        'vendas_historico' => (int)($r['vendas_historico'] ?? 0),
        'vendas_turma_atual' => (int)($r['vendas_turma_atual'] ?? 0),
        'vendas_sem_turma' => (int)($r['vendas_sem_turma'] ?? 0),
    ];
}


/**
 * ===== NOVO: Lag lead -> compra (0..44 + bucket +45) =====
 */
$lagLabels = [];
$lagData = [];
$lagInfo = null;

// labels fixos
for ($d = 0; $d <= 44; $d++) $lagLabels[] = (string)$d;
$lagLabels[] = '+45';

if (!$leadDateCol) {
    $lagInfo = "Não foi possível gerar este gráfico: coluna de data não encontrada em users (ex: created_at).";
} else {
    // garante WHERE
    $sqlWhereSalesLag = $sqlWhereSales ? $sqlWhereSales : "WHERE 1=1";

    $stmtLag = $pdo->prepare("
        SELECT
          LEAST(365, DATEDIFF(DATE(s.transaction_date), DATE(u.`$leadDateCol`))) AS days_diff,
          COUNT(*) AS vendas
        FROM hotmart_sales s
        $salesJoinUserForTurma
        INNER JOIN users u ON u.id = s.matched_user_id
        $sqlWhereSalesLag
          AND s.matched_user_id IS NOT NULL
          AND s.transaction_date IS NOT NULL
          AND u.`$leadDateCol` IS NOT NULL
          AND DATEDIFF(DATE(s.transaction_date), DATE(u.`$leadDateCol`)) BETWEEN 0 AND 365
        GROUP BY days_diff
        ORDER BY days_diff ASC
    ");
    $stmtLag->execute($paramsSales);
    $rowsLag = $stmtLag->fetchAll(PDO::FETCH_ASSOC);

    // prepara array 0..44 + bucket
    $counts = array_fill(0, 46, 0); // 0..44 + index 45 = bucket +45

    foreach ($rowsLag as $r) {
        $d = (int)$r['days_diff'];
        $c = (int)$r['vendas'];

        if ($d <= 44) $counts[$d] += $c;
        else $counts[45] += $c;
    }

    $lagData = $counts;

    $sumAll = array_sum($lagData);
    if ($sumAll === 0) {
        $lagInfo = "Sem dados suficientes para cruzar lead x compra (precisa de matched_user_id e datas válidas).";
    }
}

/**
 * ===== Financeiro: reembolsos, faturamento semanal e comparativo mensal
 * Usa status internos para nao esconder reembolsos quando o filtro de status estiver em aprovadas.
 */
list($whereFinance, $paramsFinance) = buildFilters(
    's', 'todas', $dtIni, $dtFim, $includeOrganic,
    $f_source, $f_medium, $f_campaign, $f_term, $f_content,
    $f_products, true,
    null, true, $salesDateCol, false
);
appendInFilter($whereFinance, $paramsFinance, $salesTurmaExpr, $f_turmas, 'fin_turma');
$sqlWhereFinance = $whereFinance ? ("WHERE " . implode(" AND ", $whereFinance)) : "";
$financeDateGuard = $sqlWhereFinance ? "AND s.transaction_date IS NOT NULL" : "WHERE s.transaction_date IS NOT NULL";

$refundRows = [];
$weeklyRows = [];
$monthlyRows = [];
try {
    $stmtRefund = $pdo->prepare("
        SELECT
          $salesTurmaExpr AS turma,
          SUM(CASE WHEN s.status IN ($approvedStatusSql) THEN 1 ELSE 0 END) AS vendas_validas,
          SUM(CASE WHEN s.status IN ($refundStatusSql) THEN 1 ELSE 0 END) AS reembolsos_qtd,
          COALESCE(SUM(CASE WHEN s.status IN ($refundStatusSql) THEN s.gross_revenue ELSE 0 END),0) AS reembolsos_valor
        FROM hotmart_sales s
        $salesJoinUserForTurma
        $sqlWhereFinance
        GROUP BY turma
        ORDER BY reembolsos_valor DESC, reembolsos_qtd DESC, turma ASC
        LIMIT 30
    ");
    $stmtRefund->execute($paramsFinance);
    $refundRows = $stmtRefund->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtWeekly = $pdo->prepare("
        SELECT
          DATE_FORMAT(s.transaction_date, '%Y-%m') AS ym,
          LEAST(4, CEIL(DAYOFMONTH(s.transaction_date) / 7)) AS semana,
          COALESCE(SUM(CASE WHEN s.status IN ($approvedStatusSql) THEN s.gross_revenue ELSE 0 END),0) AS bruto,
          COALESCE(SUM(CASE WHEN s.status IN ($refundStatusSql) THEN s.gross_revenue ELSE 0 END),0) AS reembolso,
          COALESCE(SUM(CASE WHEN s.status IN ($approvedStatusSql) THEN s.gross_revenue ELSE 0 END),0)
            - COALESCE(SUM(CASE WHEN s.status IN ($refundStatusSql) THEN s.gross_revenue ELSE 0 END),0) AS liquido_pos_reembolso
        FROM hotmart_sales s
        $salesJoinUserForTurma
        $sqlWhereFinance
        $financeDateGuard
        GROUP BY ym, semana
        ORDER BY ym ASC, semana ASC
        LIMIT 80
    ");
    $stmtWeekly->execute($paramsFinance);
    $weeklyRows = $stmtWeekly->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtMonthly = $pdo->prepare("
        SELECT
          DATE_FORMAT(s.transaction_date, '%Y-%m') AS ym,
          COALESCE(SUM(CASE WHEN s.status IN ($approvedStatusSql) THEN s.gross_revenue ELSE 0 END),0) AS bruto,
          COALESCE(SUM(CASE WHEN s.status IN ($approvedStatusSql) THEN s.producer_net ELSE 0 END),0) AS comissao_liquida
        FROM hotmart_sales s
        $salesJoinUserForTurma
        $sqlWhereFinance
        $financeDateGuard
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 48
    ");
    $stmtMonthly->execute($paramsFinance);
    $monthlyRows = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $refundRows = [];
    $weeklyRows = [];
    $monthlyRows = [];
}

$refundLabels = [];
$refundQtdArr = [];
$refundValorArr = [];
$refundRateArr = [];
foreach ($refundRows as $r) {
    $validas = (int)($r['vendas_validas'] ?? 0);
    $refundQtd = (int)($r['reembolsos_qtd'] ?? 0);
    $refundLabels[] = (string)($r['turma'] ?? 'Sem turma');
    $refundQtdArr[] = $refundQtd;
    $refundValorArr[] = (float)($r['reembolsos_valor'] ?? 0);
    $refundRateArr[] = ($validas + $refundQtd) > 0 ? round(($refundQtd / ($validas + $refundQtd)) * 100, 1) : 0;
}

$weeklyLabels = [];
$weeklyNetArr = [];
foreach ($weeklyRows as $r) {
    $weeklyLabels[] = substr((string)$r['ym'], 5, 2) . '/' . substr((string)$r['ym'], 0, 4) . ' S' . (int)$r['semana'];
    $weeklyNetArr[] = (float)$r['liquido_pos_reembolso'];
}

$monthlyLabels = [];
$monthlyGrossArr = [];
$monthlyCommissionArr = [];
foreach ($monthlyRows as $r) {
    $monthlyLabels[] = substr((string)$r['ym'], 5, 2) . '/' . substr((string)$r['ym'], 0, 4);
    $monthlyGrossArr[] = (float)$r['bruto'];
    $monthlyCommissionArr[] = (float)$r['comissao_liquida'];
}

/**
 * ===== Dropdown options (base vendas, já respeitando filtro de produto) =====
 */
list($w_src, $p_src) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_source', true, $salesDateCol, true);
list($w_med, $p_med) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_medium', true, $salesDateCol, true);
list($w_cam, $p_cam) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_campaign', true, $salesDateCol, true);
list($w_ter, $p_ter) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_term', true, $salesDateCol, true);
list($w_con, $p_con) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_content', true, $salesDateCol, true);
list($w_prd, $p_prd) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'product_name', true, $salesDateCol, true);

appendInFilter($w_src, $p_src, $salesTurmaExpr, $f_turmas, 'osrc_turma');
appendInFilter($w_med, $p_med, $salesTurmaExpr, $f_turmas, 'omed_turma');
appendInFilter($w_cam, $p_cam, $salesTurmaExpr, $f_turmas, 'ocam_turma');
appendInFilter($w_ter, $p_ter, $salesTurmaExpr, $f_turmas, 'oter_turma');
appendInFilter($w_con, $p_con, $salesTurmaExpr, $f_turmas, 'ocon_turma');
appendInFilter($w_prd, $p_prd, $salesTurmaExpr, $f_turmas, 'oprd_turma');

$opt_source   = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_source',   $w_src, $p_src, $salesJoinUserForTurma);
$opt_medium   = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_medium',   $w_med, $p_med, $salesJoinUserForTurma);
$opt_campaign = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_campaign', $w_cam, $p_cam, $salesJoinUserForTurma);
$opt_term     = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_term',     $w_ter, $p_ter, $salesJoinUserForTurma);
$opt_content  = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_content',  $w_con, $p_con, $salesJoinUserForTurma);
$opt_products = fetchOptions($pdo, 'hotmart_sales', 's', 'product_name', $w_prd, $p_prd, $salesJoinUserForTurma);

list($w_turma_opt, $p_turma_opt) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, null, true, $salesDateCol, true);
$sqlWhereTurmaOpt = $w_turma_opt ? ("WHERE " . implode(" AND ", $w_turma_opt)) : "";
$opt_turmas = [];
try {
    $stmtTurmaOpt = $pdo->prepare("
        SELECT $salesTurmaExpr AS v, COUNT(*) AS vendas, COALESCE(SUM(s.producer_net),0) AS receita
        FROM hotmart_sales s
        $salesJoinUserForTurma
        $sqlWhereTurmaOpt
        GROUP BY v
        HAVING v IS NOT NULL AND v <> ''
        ORDER BY vendas DESC, receita DESC, v ASC
        LIMIT 900
    ");
    $stmtTurmaOpt->execute($p_turma_opt);
    $opt_turmas = array_map(function($r){ return (string)$r['v']; }, $stmtTurmaOpt->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (\Throwable $e) {
    $opt_turmas = [];
}

/**
 * ===== Orgânicos não encontrados =====
 */
list($whereOrg, $paramsOrg) = buildFilters(
    's', $status, $dtIni, $dtFim, true,
    $f_source, $f_medium, $f_campaign, $f_term, $f_content,
    $f_products, true,
    null, false, $salesDateCol, true
);
$whereOrg[] = "s.match_method = 'none'";
$whereOrg[] = "COALESCE(NULLIF(s.utm_source,''),'organico') = 'organico'";
appendInFilter($whereOrg, $paramsOrg, $salesTurmaExpr, $f_turmas, 'org_turma');
$sqlWhereOrg = $whereOrg ? ("WHERE " . implode(" AND ", $whereOrg)) : "";

$stmtOrg = $pdo->prepare("
  SELECT
    s.transaction_date,
    s.status,
    s.product_name,
    s.producer_net,
    s.buyer_name,
    s.buyer_email,
    s.buyer_phone_raw,
    s.buyer_phone_norm,
    s.transaction_code
  FROM hotmart_sales s
  $salesJoinUserForTurma
  $sqlWhereOrg
  ORDER BY s.transaction_date DESC
  LIMIT 500
");
$stmtOrg->execute($paramsOrg);
$organicRows = $stmtOrg->fetchAll(PDO::FETCH_ASSOC);

$stmtOrgTotal = $pdo->prepare("
  SELECT COUNT(*) AS qtd, COALESCE(SUM(s.producer_net),0) AS lucro
  FROM hotmart_sales s
  $salesJoinUserForTurma
  $sqlWhereOrg
");
$stmtOrgTotal->execute($paramsOrg);
$orgTotals = $stmtOrgTotal->fetch(PDO::FETCH_ASSOC) ?: ['qtd'=>0,'lucro'=>0];

require_once __DIR__ . '/_header.php';

/**
 * Sort links preservando filtros
 */
function buildSortLink(string $col, string $currentSort, string $currentDir): string {
    $params = $_GET;
    $params['sort'] = $col;
    if ($currentSort === $col) $params['dir'] = ($currentDir === 'asc') ? 'desc' : 'asc';
    else $params['dir'] = 'desc';
    return "vendas_analytics.php?" . http_build_query($params);
}
function sortArrow(string $col, string $currentSort, string $currentDir): string {
    if ($currentSort !== $col) return '↕';
    return ($currentDir === 'asc') ? '↑' : '↓';
}
?>

<style>
  .sales-page{ width:100%; max-width:none; margin:0; }
  .sales-page .muted{ color:var(--muted)!important; }
  .sales-page h2{ font-size:16px; font-weight:700; margin:0 0 6px; color:var(--text); }
  .sales-page strong{ color:var(--text); }

  .sales-page .filter-box{
    display:flex!important;
    flex-wrap:wrap;
    gap:10px;
    align-items:flex-end;
    margin-bottom:16px;
    padding:14px 16px;
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--r-xl);
    box-shadow:var(--shadow);
  }
  .sales-page .filter-box > div{ grid-column:auto!important; min-width:150px; flex:1 1 170px; }
  .sales-page .filter-box > div[style*="border-top"]{ flex-basis:100%; min-width:100%; margin:2px 0 0!important; }
  .sales-page .filter-box > div:last-child{ flex:0 0 auto; margin-left:auto; justify-content:flex-end!important; }
  .sales-page .filter-box label{
    display:block;
    font-size:10.5px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:var(--muted);
    margin-bottom:4px;
  }
  .sales-page .filter-box label strong{ color:var(--muted)!important; font-weight:600; }
  .sales-page .filter-box input,
  .sales-page .filter-box select,
  .sales-page .ms-btn,
  .sales-page .ms-search input{
    background:var(--bg)!important;
    color:var(--text)!important;
    border:1px solid var(--border-light)!important;
    border-radius:var(--r)!important;
    min-height:34px;
    padding:7px 10px!important;
    font-size:12px;
    font-family:var(--font);
  }
  .sales-page .card-dark{
    background:var(--bg-card);
    border:1px solid var(--border);
    border-radius:var(--r-xl);
    padding:16px 18px;
    margin-bottom:16px;
    box-shadow:var(--shadow);
  }
  .sales-page .card-dark h3{
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--muted)!important;
    margin:0!important;
  }
  .sales-page .badge-dark{
    display:inline-flex;
    align-items:center;
    padding:5px 10px;
    border:1px solid var(--border);
    border-radius:var(--r-full);
    background:var(--bg-hover);
    color:var(--text);
    font-size:11px;
    font-weight:600;
  }
  .sales-page .kpi-grid{ margin-top:0; }
  .sales-page .kpi .title{
    font-size:11px;
    font-weight:500;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:2px;
  }
  .sales-page .kpi .value{ font-size:26px; font-weight:700; color:var(--text); line-height:1.1; letter-spacing:0; }
  .sales-page .kpi .sub{ font-size:11px; color:var(--muted); margin-top:3px; }
  .sales-grid{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:0!important; }
  .sales-grid .table-wide{ grid-column:1 / -1; }
  .sales-chart{ height:260px!important; margin-top:10px; }
  .sales-chart canvas{ max-height:250px; }

  /* Multi-select dropdown */
  .ms-wrap{ position:relative; width:100%; }
  .ms-btn{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    border-radius:10px;
    cursor:pointer;
  }
  .ms-btn-text{
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
    text-align:left;
    flex:1;
  }
  .ms-caret{ opacity:.8; }
  .ms-menu{
    position:absolute;
    top:calc(100% + 8px);
    left:0;
    width: min(600px, 92vw);
    min-width: 100%;
    max-width: 92vw;
    z-index:50;
    background:var(--bg-card);
    border:1px solid var(--border-light);
    border-radius:var(--r-lg);
    box-shadow:var(--shadow-lg);
    padding:10px;
    display:none;
  }
  .ms-wrap.open .ms-menu{ display:block; }
  .ms-search input{
    width:100%;
    padding:10px 12px;
    border-radius:10px;
    background:#0b1430!important;
    color:#e5e7eb!important;
    border:1px solid rgba(255,255,255,.12)!important;
    outline:none;
  }
  .ms-list{
    margin-top:10px;
    max-height:260px;
    overflow:auto;
    padding-right:4px;
  }
  .ms-item{
    display:flex;
    align-items:center;
    gap:10px;
    padding:8px 8px;
    border-radius:10px;
    cursor:pointer;
  }
  .ms-item:hover{ background:rgba(255,255,255,.06); }
  .ms-item input{ transform:scale(1.05); }
  .ms-label{
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
    color:var(--text);
    font-size:14px;
    max-width: 560px;
  }
  .ms-list::-webkit-scrollbar{ width:8px; }
  .ms-list::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.15); border-radius:999px; }

  .th-sort{ display:flex; align-items:center; gap:8px; }
  .th-sort .arrow{ font-size:12px; opacity:.85; }
  .sales-page .table-striped{ min-width:100%; }
  .sales-page .table-striped a{ color:var(--primary); }
  @media (max-width: 900px){
    .sales-grid{ grid-template-columns:1fr; }
    .sales-page .filter-box > div{ flex-basis:100%; }
    .sales-page .filter-box > div:last-child{ width:100%; margin-left:0; }
  }
</style>

<div class="sales-page">
  <h2 style="margin-bottom:10px;">Análise por UTM (Vendas + Leads)</h2>
  <div class="muted" style="margin-bottom:14px;">
    Dimensão atual: <strong><?= htmlspecialchars($dim) ?></strong>
    <?php if (!$leadDateCol): ?>
      <span class="badge-dark" style="margin-left:8px;">Leads: sem filtro de data (coluna de data não encontrada em users)</span>
    <?php endif; ?>
  </div>

  <form method="GET" class="filter-box" style="display:grid; grid-template-columns: repeat(12, 1fr); gap:12px; align-items:end;">
    <div style="grid-column: span 3;">
      <label><strong>Dimensão do gráfico:</strong></label><br>
      <select name="dim">
        <?php foreach (['utm_source'=>'UTM Source','utm_medium'=>'UTM Medium','utm_campaign'=>'UTM Campaign','utm_term'=>'UTM Term','utm_content'=>'UTM Content'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $dim===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column: span 2;">
      <label><strong>Início:</strong></label><br>
      <input type="date" name="ini" value="<?= htmlspecialchars($dtIni) ?>">
    </div>

    <div style="grid-column: span 2;">
      <label><strong>Fim:</strong></label><br>
      <input type="date" name="fim" value="<?= htmlspecialchars($dtFim) ?>">
    </div>

    <div style="grid-column: span 2;">
      <label><strong>Status (vendas):</strong></label><br>
      <select name="status">
        <option value="aprovadas" <?= $status==='aprovadas'?'selected':'' ?>>Aprovado + Completo</option>
        <option value="todas" <?= $status==='todas'?'selected':'' ?>>Todas</option>
        <option value="Aprovado" <?= $status==='Aprovado'?'selected':'' ?>>Aprovado</option>
        <option value="Completo" <?= $status==='Completo'?'selected':'' ?>>Completo</option>
        <option value="Reembolsado" <?= $status==='Reembolsado'?'selected':'' ?>>Reembolsado</option>
        <option value="Chargeback" <?= $status==='Chargeback'?'selected':'' ?>>Chargeback</option>
        <option value="Cancelado" <?= $status==='Cancelado'?'selected':'' ?>>Cancelado</option>
      </select>
    </div>

    <div style="grid-column: span 3;">
      <label><strong>Orgânicos nos gráficos?</strong></label><br>
      <select name="include_organic">
        <option value="1" <?= $includeOrganic?'selected':'' ?>>Sim (incluir)</option>
        <option value="0" <?= !$includeOrganic?'selected':'' ?>>Não (excluir)</option>
      </select>
    </div>

    <div style="grid-column: span 12; border-top:1px solid rgba(255,255,255,.10); margin-top:6px;"></div>

    <?php
      $renderMS = function(string $label, string $nameAttr, array $options, array $selected, int $span){
        ?>
        <div style="grid-column: span <?= (int)$span ?>;">
          <label><strong><?= htmlspecialchars($label) ?></strong></label><br>
          <div class="ms-wrap" data-name="<?= htmlspecialchars($nameAttr) ?>">
            <button type="button" class="ms-btn" data-ms-btn>
              <span class="ms-btn-text" data-ms-text></span>
              <span class="ms-caret">▾</span>
            </button>
            <div class="ms-menu" data-ms-menu>
              <div class="ms-search"><input type="text" placeholder="Buscar..." data-ms-search></div>
              <div class="ms-list" data-ms-list>
                <?php foreach ($options as $v):
                  $sel = in_array($v, $selected, true);
                ?>
                  <label class="ms-item" data-ms-item>
                    <input type="checkbox" value="<?= htmlspecialchars($v) ?>" <?= $sel?'checked':'' ?> data-ms-check>
                    <span class="ms-label" title="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php
      };

      // UTMs
      $renderMS('Filtrar UTM Source',   'f_source[]',   $opt_source,   $f_source,   3);
      $renderMS('UTM Medium',          'f_medium[]',   $opt_medium,   $f_medium,   2);
      $renderMS('UTM Campaign',        'f_campaign[]', $opt_campaign, $f_campaign, 3);
      $renderMS('UTM Term',            'f_term[]',     $opt_term,     $f_term,     2);
      $renderMS('UTM Content',         'f_content[]',  $opt_content,  $f_content,  2);

      // Cursos
      echo '<div style="grid-column: span 12;"></div>';
      $renderMS('Cursos / Produtos (Hotmart)', 'f_products[]', $opt_products, $f_products, 6);
      $renderMS('Turmas', 'f_turmas[]', $opt_turmas, $f_turmas, 6);
    ?>

    <div style="grid-column: span 6; display:flex; gap:10px; justify-content:flex-end;">
      <button type="submit" class="btn btn-primary">Aplicar filtros</button>
      <a class="btn btn-ghost" href="vendas_analytics.php">Limpar</a>
    </div>
  </form>

  <!-- KPI -->
  <div class="kpi-grid">
    <div class="kpi kpi-b">
      <div class="kpi-icon b">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/></svg>
      </div>
      <div class="title">LEADS (no período/filtros)</div>
      <div class="value"><?= number_format((int)$leadsTotals['leads_total'], 0, ',', '.') ?></div>
      <div class="sub">Fonte: <strong>users</strong></div>
    </div>
    <div class="kpi kpi-y">
      <div class="kpi-icon y">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><path d="M6 6L5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
      </div>
      <div class="title">VENDAS (no período/filtros)</div>
      <div class="value"><?= number_format((int)$salesTotals['vendas_total'], 0, ',', '.') ?></div>
      <div class="sub">Cursos filtrados: <strong><?= $f_products ? count($f_products) : 0 ?></strong></div>
    </div>
    <div class="kpi kpi-g">
      <div class="kpi-icon g">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7H14a3.5 3.5 0 010 7H6"/></svg>
      </div>
      <div class="title">LUCRO TOTAL (R$)</div>
      <div class="value">R$ <?= number_format((float)$salesTotals['lucro_total'], 2, ',', '.') ?></div>
      <div class="sub">Soma de <strong>producer_net</strong></div>
    </div>
  </div>

  <!-- Charts -->
  <div class="sales-grid">
    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Quantidade de vendas por <?= htmlspecialchars($dim) ?></h3>
        <span class="badge-dark">Top <?= (int)$limit ?> • Orgânicos: <?= $includeOrganic ? 'SIM' : 'NÃO' ?></span>
      </div>
      <div class="sales-chart"><canvas id="chartVendas"></canvas></div>
    </div>

    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Lucro líquido (R$) por <?= htmlspecialchars($dim) ?></h3>
        <span class="badge-dark">producer_net</span>
      </div>
      <div class="sales-chart"><canvas id="chartLucro"></canvas></div>
    </div>

    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Quantidade de leads por <?= htmlspecialchars($dim) ?></h3>
        <span class="badge-dark">Fonte: users</span>
      </div>
      <div class="sales-chart"><canvas id="chartLeads"></canvas></div>
    </div>

    <!-- NOVO: Lag lead->compra (0..44 +45) -->
<div class="card-dark">
  <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
    <h3 style="margin:0;">Dias entre inscrição (lead) e compra</h3>
    <span class="badge-dark">0 a 44 e bucket +45 (até 365)</span>
  </div>

  <?php if (!empty($lagInfo)): ?>
    <div class="muted" style="margin-top:10px;"><?= htmlspecialchars($lagInfo) ?></div>
  <?php else: ?>
    <div class="sales-chart">
      <canvas id="chartLag"></canvas>
    </div>
  <?php endif; ?>
</div>

    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Taxa de reembolso por turma</h3>
        <span class="badge-dark">Qtd + valor em R$</span>
      </div>
      <?php if (!$refundLabels): ?>
        <div class="muted" style="margin-top:10px;">Sem reembolsos para os filtros atuais.</div>
      <?php else: ?>
        <div class="sales-chart"><canvas id="chartRefund"></canvas></div>
      <?php endif; ?>
    </div>

    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Faturamento liquido por semana</h3>
        <span class="badge-dark">bruto aprovado - reembolsos</span>
      </div>
      <?php if (!$weeklyLabels): ?>
        <div class="muted" style="margin-top:10px;">Sem dados semanais para os filtros atuais.</div>
      <?php else: ?>
        <div class="sales-chart"><canvas id="chartWeeklyNet"></canvas></div>
      <?php endif; ?>
    </div>

    <div class="card-dark table-wide">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Faturamento bruto x comissao liquida por mes</h3>
        <span class="badge-dark">comparativo mensal</span>
      </div>
      <?php if (!$monthlyLabels): ?>
        <div class="muted" style="margin-top:10px;">Sem dados mensais para os filtros atuais.</div>
      <?php else: ?>
        <div class="sales-chart"><canvas id="chartMonthlyGrossNet"></canvas></div>
      <?php endif; ?>
    </div>

  <!-- Tabela UTM -->
  <div class="card-dark table-wide">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
      <h3 style="margin:0;">Tabela por <?= htmlspecialchars($dim) ?> (Leads + Vendas + Lucro)</h3>
      <span class="badge-dark">Clique no cabeçalho para ordenar</span>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table class="table table-striped" style="width:100%; min-width:820px;">
        <thead>
          <tr>
            <th><a href="<?= htmlspecialchars(buildSortLink('label', $sort, $dir)) ?>"><span class="th-sort">UTM <span class="arrow"><?= sortArrow('label', $sort, $dir) ?></span></span></a></th>
            <th><a href="<?= htmlspecialchars(buildSortLink('leads', $sort, $dir)) ?>"><span class="th-sort">Leads <span class="arrow"><?= sortArrow('leads', $sort, $dir) ?></span></span></a></th>
            <th><a href="<?= htmlspecialchars(buildSortLink('vendas', $sort, $dir)) ?>"><span class="th-sort">Vendas <span class="arrow"><?= sortArrow('vendas', $sort, $dir) ?></span></span></a></th>
            <th><a href="<?= htmlspecialchars(buildSortLink('lucro', $sort, $dir)) ?>"><span class="th-sort">Lucro (R$) <span class="arrow"><?= sortArrow('lucro', $sort, $dir) ?></span></span></a></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tableRows as $r): ?>
            <tr>
              <td title="<?= htmlspecialchars((string)$r['label']) ?>"><?= htmlspecialchars((string)$r['label']) ?></td>
              <td><?= number_format((int)$r['leads'], 0, ',', '.') ?></td>
              <td><?= number_format((int)$r['vendas'], 0, ',', '.') ?></td>
              <td><?= number_format((float)$r['lucro'], 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tableRows): ?>
            <tr><td colspan="4">Sem dados para os filtros selecionados.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tabela Turmas -->
  <div class="card-dark table-wide">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
      <h3 style="margin:0;">Receita por turma</h3>
      <span class="badge-dark">Historico da inscricao; fallback: turma atual do aluno</span>
    </div>
    <div class="muted" style="margin-top:8px;">
      Quando a venda nao encontra turma em <strong>inscricao_logs</strong> antes da compra, o sistema atribui pela turma atual em <strong>users</strong>.
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table class="table table-striped" style="width:100%; min-width:1100px;">
        <thead>
          <tr>
            <th>Turma</th>
            <th>Leads</th>
            <th>Vendas</th>
            <th>Compradores</th>
            <th>Conversao</th>
            <th>Receita (R$)</th>
            <th>Atribuicao</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($turmaRows as $r): ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['turma']) ?></td>
              <td><?= number_format((int)$r['leads'], 0, ',', '.') ?></td>
              <td><?= number_format((int)$r['vendas'], 0, ',', '.') ?></td>
              <td><?= number_format((int)$r['compradores'], 0, ',', '.') ?></td>
              <td><?= $r['conversao'] === null ? '-' : number_format((float)$r['conversao'], 1, ',', '.') . '%' ?></td>
              <td><?= number_format((float)$r['receita'], 2, ',', '.') ?></td>
              <td>
                historico: <?= number_format((int)$r['vendas_historico'], 0, ',', '.') ?> |
                turma atual: <?= number_format((int)$r['vendas_turma_atual'], 0, ',', '.') ?> |
                sem turma: <?= number_format((int)$r['vendas_sem_turma'], 0, ',', '.') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$turmaRows): ?>
            <tr><td colspan="7">Sem vendas com turma atribuida para os filtros selecionados.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Orgânicos não encontrados -->
  <div class="card-dark table-wide">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
      <h3 style="margin:0;">Orgânicos não encontrados (sem match por telefone/email)</h3>
      <span class="badge-dark">Qtd: <?= (int)$orgTotals['qtd'] ?> • Lucro: R$ <?= number_format((float)$orgTotals['lucro'], 2, ',', '.') ?></span>
    </div>
    <div class="muted" style="margin-top:8px;">Limite: 500 registros (mais recentes).</div>

    <div style="overflow:auto; margin-top:10px;">
      <table class="table table-striped" style="width:100%; min-width:1100px;">
        <thead>
          <tr>
            <th>Data</th><th>Status</th><th>Produto</th><th>Lucro (R$)</th>
            <th>Comprador</th><th>Email</th><th>Telefone (raw)</th><th>Telefone (norm)</th><th>Transação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($organicRows as $r): ?>
            <tr>
              <td><?= htmlspecialchars((string)$r['transaction_date']) ?></td>
              <td><?= htmlspecialchars((string)$r['status']) ?></td>
              <td><?= htmlspecialchars((string)$r['product_name']) ?></td>
              <td><?= number_format((float)$r['producer_net'], 2, ',', '.') ?></td>
              <td><?= htmlspecialchars((string)$r['buyer_name']) ?></td>
              <td><?= htmlspecialchars((string)$r['buyer_email']) ?></td>
              <td><?= htmlspecialchars((string)$r['buyer_phone_raw']) ?></td>
              <td><?= htmlspecialchars((string)$r['buyer_phone_norm']) ?></td>
              <td><?= htmlspecialchars((string)$r['transaction_code']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$organicRows): ?>
            <tr><td colspan="9">Nenhum orgânico não encontrado para os filtros atuais.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Chart.js + DataLabels -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>

<script>
/**
 * MultiSelect Dropdown
 */
(function(){
  function closeAll(except){
    document.querySelectorAll('.ms-wrap.open').forEach(function(w){
      if (except && w === except) return;
      w.classList.remove('open');
    });
  }

  document.addEventListener('click', function(e){
    var wrap = e.target.closest('.ms-wrap');
    if (!wrap){
      closeAll();
      return;
    }
    var btn = e.target.closest('[data-ms-btn]');
    if (btn){
      var isOpen = wrap.classList.contains('open');
      closeAll(wrap);
      if (!isOpen) wrap.classList.add('open'); else wrap.classList.remove('open');
    }
  });

  function syncWrap(wrap){
    var name = wrap.getAttribute('data-name');
    var checks = Array.prototype.slice.call(wrap.querySelectorAll('[data-ms-check]'));
    var selected = checks.filter(function(c){ return c.checked; }).map(function(c){ return c.value; });

    var textEl = wrap.querySelector('[data-ms-text]');
    if (selected.length === 0){
      textEl.textContent = 'Selecionar...';
    } else if (selected.length === 1){
      textEl.textContent = selected[0];
    } else {
      textEl.textContent = selected[0] + ' +' + (selected.length - 1);
    }

    Array.prototype.slice.call(wrap.querySelectorAll('input[type="hidden"][data-ms-hidden]'))
      .forEach(function(n){ n.remove(); });

    selected.forEach(function(v){
      var h = document.createElement('input');
      h.type = 'hidden';
      h.name = name;
      h.value = v;
      h.setAttribute('data-ms-hidden', '1');
      wrap.appendChild(h);
    });
  }

  document.querySelectorAll('.ms-wrap').forEach(function(wrap){
    var search = wrap.querySelector('[data-ms-search]');
    var items  = Array.prototype.slice.call(wrap.querySelectorAll('[data-ms-item]'));

    syncWrap(wrap);

    wrap.addEventListener('change', function(e){
      if (e.target && e.target.matches('[data-ms-check]')){
        syncWrap(wrap);
      }
    });

    if (search){
      search.addEventListener('input', function(){
        var q = search.value.trim().toLowerCase();
        items.forEach(function(it){
          var txt = it.textContent.trim().toLowerCase();
          it.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
        });
      });
    }
  });
})();
</script>

<script>
/**
 * Charts
 */
Chart.register(ChartDataLabels);

const fmtBRL = (n) => new Intl.NumberFormat('pt-BR', { style:'currency', currency:'BRL' }).format(n);

function makeBarChart(canvasId, labels, datasetLabel, data, isMoney=false){
  const el = document.getElementById(canvasId);
  if (!el) return null;

  return new Chart(el, {
    type: 'bar',
    data: { labels, datasets: [{ label: datasetLabel, data, borderWidth: 0, backgroundColor: 'rgba(56,189,248,.62)', borderRadius: 4, maxBarThickness: 46 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#94a3b8', boxWidth: 18, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: (context) => {
              const v = context.parsed.y ?? 0;
              return isMoney ? `${datasetLabel}: ${fmtBRL(v)}` : `${datasetLabel}: ${v}`;
            }
          }
        },
        datalabels: {
          color: '#e2e8f0',
          anchor: 'end',
          align: 'end',
          clamp: true,
          formatter: (value) => isMoney ? fmtBRL(value) : value,
          font: { weight: '700' }
        }
      },
      scales: {
        x: {
          ticks: { color: '#64748b', autoSkip: false, maxRotation: 45, minRotation: 0, font: { size: 11 } },
          grid: { color: 'rgba(26,37,64,.6)' }
        },
        y: {
          beginAtZero: true,
          ticks: { color: '#64748b', font: { size: 11 } },
          grid: { color: 'rgba(26,37,64,.6)' }
        }
      }
    }
  });
}

function makeGroupedBarChart(canvasId, labels, datasets, isMoney=false){
  const el = document.getElementById(canvasId);
  if (!el) return null;

  return new Chart(el, {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#94a3b8', boxWidth: 18, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: (context) => {
              const v = context.parsed.y ?? 0;
              return `${context.dataset.label}: ${isMoney ? fmtBRL(v) : v}`;
            }
          }
        },
        datalabels: {
          color: '#e2e8f0',
          anchor: 'end',
          align: 'end',
          clamp: true,
          formatter: (value) => isMoney ? fmtBRL(value) : value,
          font: { weight: '700', size: 10 }
        }
      },
      scales: {
        x: { ticks: { color: '#64748b', maxRotation: 45, minRotation: 0, font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' } },
        y: { beginAtZero: true, ticks: { color: '#64748b', font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' } }
      }
    }
  });
}

function makeRefundChart(canvasId, labels, valueData, countData, rateData){
  const el = document.getElementById(canvasId);
  if (!el) return null;

  return new Chart(el, {
    data: {
      labels,
      datasets: [
        { type: 'bar', label: 'Valor reembolsado', data: valueData, yAxisID: 'money', backgroundColor: 'rgba(239,68,68,.62)', borderRadius: 4, maxBarThickness: 44 },
        { type: 'line', label: 'Reembolsos (qtd)', data: countData, yAxisID: 'count', borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .35, pointRadius: 3 },
        { type: 'line', label: 'Taxa (%)', data: rateData, yAxisID: 'count', borderColor: '#a855f7', backgroundColor: '#a855f7', tension: .35, pointRadius: 3 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#94a3b8', boxWidth: 18, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: (context) => {
              const v = context.parsed.y ?? 0;
              if (context.dataset.yAxisID === 'money') return `${context.dataset.label}: ${fmtBRL(v)}`;
              return `${context.dataset.label}: ${v}`;
            }
          }
        },
        datalabels: { display: false }
      },
      scales: {
        x: { ticks: { color: '#64748b', maxRotation: 45, minRotation: 0, font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' } },
        money: { beginAtZero: true, position: 'left', ticks: { color: '#64748b', callback: (v) => fmtBRL(v), font: { size: 11 } }, grid: { color: 'rgba(26,37,64,.6)' } },
        count: { beginAtZero: true, position: 'right', ticks: { color: '#64748b', font: { size: 11 } }, grid: { drawOnChartArea: false } }
      }
    }
  });
}

// Vendas/Lucro
const labelsSales = <?= json_encode($labelsSales, JSON_UNESCAPED_UNICODE) ?>;
const vendasArr   = <?= json_encode($vendasArr) ?>;
const lucroArr    = <?= json_encode($lucroArr) ?>;
makeBarChart('chartVendas', labelsSales, 'Vendas', vendasArr, false);
makeBarChart('chartLucro',  labelsSales, 'Lucro (R$)', lucroArr, true);

// Leads
const labelsLeads = <?= json_encode($labelsLeads, JSON_UNESCAPED_UNICODE) ?>;
const leadsArr    = <?= json_encode($leadsArr) ?>;
makeBarChart('chartLeads', labelsLeads, 'Leads', leadsArr, false);

// Lag (lead->compra)
const lagLabels = <?= json_encode($lagLabels, JSON_UNESCAPED_UNICODE) ?>;
const lagData   = <?= json_encode($lagData) ?>;
if (document.getElementById('chartLag')) {
  makeBarChart('chartLag', lagLabels, 'Vendas', lagData, false);
}

const refundLabels = <?= json_encode($refundLabels, JSON_UNESCAPED_UNICODE) ?>;
const refundQtdArr = <?= json_encode($refundQtdArr) ?>;
const refundValorArr = <?= json_encode($refundValorArr) ?>;
const refundRateArr = <?= json_encode($refundRateArr) ?>;
if (document.getElementById('chartRefund')) {
  makeRefundChart('chartRefund', refundLabels, refundValorArr, refundQtdArr, refundRateArr);
}

const weeklyLabels = <?= json_encode($weeklyLabels, JSON_UNESCAPED_UNICODE) ?>;
const weeklyNetArr = <?= json_encode($weeklyNetArr) ?>;
makeBarChart('chartWeeklyNet', weeklyLabels, 'Liquido pos-reembolso (R$)', weeklyNetArr, true);

const monthlyLabels = <?= json_encode($monthlyLabels, JSON_UNESCAPED_UNICODE) ?>;
const monthlyGrossArr = <?= json_encode($monthlyGrossArr) ?>;
const monthlyCommissionArr = <?= json_encode($monthlyCommissionArr) ?>;
if (document.getElementById('chartMonthlyGrossNet')) {
  makeGroupedBarChart('chartMonthlyGrossNet', monthlyLabels, [
    { label: 'Faturamento bruto', data: monthlyGrossArr, backgroundColor: 'rgba(56,189,248,.62)', borderRadius: 4, maxBarThickness: 44 },
    { label: 'Comissao liquida', data: monthlyCommissionArr, backgroundColor: 'rgba(34,197,94,.62)', borderRadius: 4, maxBarThickness: 44 }
  ], true);
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
