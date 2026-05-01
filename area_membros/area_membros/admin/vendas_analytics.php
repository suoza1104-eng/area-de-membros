<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
session_start();
proteger_admin();

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

function fetchOptions(PDO $pdo, string $table, string $tableAlias, string $column, array $where, array $params): array {
    $sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";
    $stmt = $pdo->prepare("
        SELECT DISTINCT COALESCE(NULLIF($tableAlias.$column,''),'(vazio)') AS v
        FROM `$table` $tableAlias
        $sqlWhere
        ORDER BY v ASC
        LIMIT 900
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){ return (string)$r['v']; }, $rows);
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

/**
 * ===== VENDAS filtros =====
 */
list($whereSales, $paramsSales) = buildFilters(
    's', $status, $dtIni, $dtFim, $includeOrganic,
    $f_source, $f_medium, $f_campaign, $f_term, $f_content,
    $f_products, true,
    null, true, $salesDateCol, true
);
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
 * ===== Dropdown options (base vendas, já respeitando filtro de produto) =====
 */
list($w_src, $p_src) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_source', true, $salesDateCol, true);
list($w_med, $p_med) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_medium', true, $salesDateCol, true);
list($w_cam, $p_cam) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_campaign', true, $salesDateCol, true);
list($w_ter, $p_ter) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_term', true, $salesDateCol, true);
list($w_con, $p_con) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'utm_content', true, $salesDateCol, true);
list($w_prd, $p_prd) = buildFilters('s', $status, $dtIni, $dtFim, $includeOrganic, $f_source, $f_medium, $f_campaign, $f_term, $f_content, $f_products, true, 'product_name', true, $salesDateCol, true);

$opt_source   = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_source',   $w_src, $p_src);
$opt_medium   = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_medium',   $w_med, $p_med);
$opt_campaign = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_campaign', $w_cam, $p_cam);
$opt_term     = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_term',     $w_ter, $p_ter);
$opt_content  = fetchOptions($pdo, 'hotmart_sales', 's', 'utm_content',  $w_con, $p_con);
$opt_products = fetchOptions($pdo, 'hotmart_sales', 's', 'product_name', $w_prd, $p_prd);

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
  $sqlWhereOrg
  ORDER BY s.transaction_date DESC
  LIMIT 500
");
$stmtOrg->execute($paramsOrg);
$organicRows = $stmtOrg->fetchAll(PDO::FETCH_ASSOC);

$stmtOrgTotal = $pdo->prepare("
  SELECT COUNT(*) AS qtd, COALESCE(SUM(s.producer_net),0) AS lucro
  FROM hotmart_sales s
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
  :root{
    --bg:#0b1220;
    --card:#101a2e;
    --card2:#0f172a;
    --border:rgba(255,255,255,.10);
    --text:#e5e7eb;
    --muted:rgba(229,231,235,.70);
    --accent:#60a5fa;
  }
  body{ background:var(--bg)!important; color:var(--text)!important; }
  h2,h3,label,strong,th,td{ color:var(--text)!important; }
  a{ color:var(--accent)!important; text-decoration:none; }
  a:hover{ text-decoration:underline; }
  .muted{ color:var(--muted)!important; }

  .filter-box{
    background:linear-gradient(180deg, rgba(16,26,46,.95), rgba(15,23,42,.95));
    border:1px solid var(--border);
    border-radius:14px;
    padding:14px;
  }
  .card-dark{
    background:linear-gradient(180deg, var(--card), var(--card2));
    border:1px solid var(--border);
    border-radius:14px;
    padding:14px;
  }
  input, select{
    background:#0b1430!important;
    color:var(--text)!important;
    border:1px solid var(--border)!important;
    border-radius:10px!important;
    padding:8px 10px!important;
  }
  .badge-dark{
    display:inline-block;
    padding:6px 10px;
    border:1px solid var(--border);
    border-radius:999px;
    background:rgba(255,255,255,.05);
    color:var(--text);
    font-size:12px;
  }

  /* KPI */
  .kpi-grid{
    display:grid;
    grid-template-columns: repeat(12, 1fr);
    gap:12px;
    margin-top:12px;
  }
  .kpi{
    grid-column: span 4;
    padding:14px;
    border-radius:14px;
    border:1px solid var(--border);
    background:linear-gradient(180deg, rgba(16,26,46,.95), rgba(15,23,42,.95));
  }
  .kpi .title{ font-size:13px; color:var(--muted); margin-bottom:6px; }
  .kpi .value{ font-size:28px; font-weight:800; letter-spacing:.3px; }
  .kpi .sub{ font-size:12px; color:var(--muted); margin-top:6px; }
  @media (max-width: 900px){ .kpi{ grid-column: span 12; } }

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
    background:#0b1430;
    color:#e5e7eb;
    border:1px solid rgba(255,255,255,.12);
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
    background:linear-gradient(180deg, #101a2e, #0f172a);
    border:1px solid rgba(255,255,255,.12);
    border-radius:12px;
    box-shadow:0 18px 50px rgba(0,0,0,.45);
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
    color:#e5e7eb;
    font-size:14px;
    max-width: 560px;
  }
  .ms-list::-webkit-scrollbar{ width:8px; }
  .ms-list::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.15); border-radius:999px; }

  .th-sort{ display:flex; align-items:center; gap:8px; }
  .th-sort .arrow{ font-size:12px; opacity:.85; }
</style>

<div class="container" style="max-width: 1200px; margin: 18px auto;">
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
    ?>

    <div style="grid-column: span 6; display:flex; gap:10px; justify-content:flex-end;">
      <button type="submit" class="btn btn-primary">Aplicar filtros</button>
      <a class="btn btn-outline-light" href="vendas_analytics.php" style="border-color:rgba(255,255,255,.15); color:#fff;">Limpar</a>
    </div>
  </form>

  <!-- KPI -->
  <div class="kpi-grid">
    <div class="kpi">
      <div class="title">LEADS (no período/filtros)</div>
      <div class="value"><?= number_format((int)$leadsTotals['leads_total'], 0, ',', '.') ?></div>
      <div class="sub">Fonte: <strong>users</strong></div>
    </div>
    <div class="kpi">
      <div class="title">VENDAS (no período/filtros)</div>
      <div class="value"><?= number_format((int)$salesTotals['vendas_total'], 0, ',', '.') ?></div>
      <div class="sub">Cursos filtrados: <strong><?= $f_products ? count($f_products) : 0 ?></strong></div>
    </div>
    <div class="kpi">
      <div class="title">LUCRO TOTAL (R$)</div>
      <div class="value">R$ <?= number_format((float)$salesTotals['lucro_total'], 2, ',', '.') ?></div>
      <div class="sub">Soma de <strong>producer_net</strong></div>
    </div>
  </div>

  <!-- Charts -->
  <div style="display:grid; grid-template-columns:1fr; gap:14px; margin-top:14px;">
    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Quantidade de vendas por <?= htmlspecialchars($dim) ?></h3>
        <span class="badge-dark">Top <?= (int)$limit ?> • Orgânicos: <?= $includeOrganic ? 'SIM' : 'NÃO' ?></span>
      </div>
      <div style="height:340px;"><canvas id="chartVendas"></canvas></div>
    </div>

    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Lucro líquido (R$) por <?= htmlspecialchars($dim) ?></h3>
        <span class="badge-dark">producer_net</span>
      </div>
      <div style="height:340px;"><canvas id="chartLucro"></canvas></div>
    </div>

    <div class="card-dark">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
        <h3 style="margin:0;">Quantidade de leads por <?= htmlspecialchars($dim) ?></h3>
        <span class="badge-dark">Fonte: users</span>
      </div>
      <div style="height:340px;"><canvas id="chartLeads"></canvas></div>
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
    <div style="height:340px; margin-top:10px;">
      <canvas id="chartLag"></canvas>
    </div>
  <?php endif; ?>
</div>

  <!-- Tabela UTM -->
  <div class="card-dark" style="margin-top:14px;">
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

  <!-- Orgânicos não encontrados -->
  <div class="card-dark" style="margin-top:14px;">
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
    data: { labels, datasets: [{ label: datasetLabel, data, borderWidth: 0 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#e5e7eb' } },
        tooltip: {
          callbacks: {
            label: (context) => {
              const v = context.parsed.y ?? 0;
              return isMoney ? `${datasetLabel}: ${fmtBRL(v)}` : `${datasetLabel}: ${v}`;
            }
          }
        },
        datalabels: {
          color: '#ffffff',
          anchor: 'end',
          align: 'end',
          clamp: true,
          formatter: (value) => isMoney ? fmtBRL(value) : value,
          font: { weight: '700' }
        }
      },
      scales: {
        x: {
          ticks: { color: '#e5e7eb', autoSkip: false, maxRotation: 45, minRotation: 0 },
          grid: { color: 'rgba(255,255,255,.08)' }
        },
        y: {
          beginAtZero: true,
          ticks: { color: '#e5e7eb' },
          grid: { color: 'rgba(255,255,255,.08)' }
        }
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
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>