<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();

$menu       = 'alunos';
$page_title = 'Lista de Compradores';
$pdo        = getPDO();
$isEquipe   = (($_SESSION['admin_tipo'] ?? 'principal') === 'equipe');

if (!function_exists('am_h')) {
    function am_h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function comp_status_label(?string $status): string {
    $status = strtoupper(trim((string)$status));
    $labels = [
        'APPROVED' => 'Aprovada',
        'COMPLETE' => 'Completa',
        'COMPLETED' => 'Completa',
        'PAID' => 'Paga',
        'PENDING' => 'Pendente',
        'PROCESSING' => 'Processando',
        'CAPTURE' => 'Em captura',
        'REVISION_PAID' => 'Em revisao',
        'CANCELED' => 'Cancelada',
        'CANCELLED' => 'Cancelada',
        'REFUNDED' => 'Reembolsada',
        'REFUNDED_REQUEST' => 'Pedido de reembolso',
        'REFUND_REQUESTED' => 'Pedido de reembolso',
        'PENDING_REFUND' => 'Reembolso pendente',
        'CHARGEBACK' => 'Chargeback',
        'DISPUTE' => 'Disputa',
        'DISPUTE_PENDING' => 'Disputa pendente',
        'IN_MEDIATION' => 'Em mediacao',
        'FAILED' => 'Falhou',
        'EXPIRED' => 'Expirada',
    ];
    if ($status === '') return 'Pendente';
    return $labels[$status] ?? mb_convert_case(str_replace('_', ' ', strtolower($status)), MB_CASE_TITLE, 'UTF-8');
}

function comp_payment_method_label(?string $method): string {
    $method = trim((string)$method);
    $key = strtolower(str_replace(['-', ' '], '_', $method));
    $labels = [
        'pix' => 'PIX',
        'credit_card' => 'Cartao de credito',
        'creditcard' => 'Cartao de credito',
        'card' => 'Cartao',
        'cartao' => 'Cartao',
        'cartao_credito' => 'Cartao de credito',
        'boleto' => 'Boleto',
        'bank_slip' => 'Boleto',
        'debit_card' => 'Cartao de debito',
        'debitcard' => 'Cartao de debito',
        'paypal' => 'PayPal',
    ];
    if ($method === '') return 'Pagamento nao informado';
    return $labels[$key] ?? mb_convert_case(str_replace('_', ' ', $method), MB_CASE_TITLE, 'UTF-8');
}

function comp_date_label($value): string {
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function comp_first_non_empty(array $values): string {
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') return $value;
    }
    return '';
}

function comp_sales_columns(PDO $pdo): array {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM v_sales_master")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('strval', $cols);
    } catch (Throwable $e) {
        return [];
    }
}

function comp_optional_sales_select(array $availableCols): string {
    $optionalCols = [
        'product_code',
        'external_product_id',
        'price_name',
        'payment_gateway',
        'provider_account_id',
        'sale_origin',
        'sales_channel',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'refund_or_chargeback_at',
        'currency',
    ];
    $select = [];
    foreach ($optionalCols as $col) {
        $select[] = in_array($col, $availableCols, true) ? "s.`{$col}`" : "NULL AS `{$col}`";
    }
    return implode(",\n                      ", $select);
}

// --- FILTROS E PARÂMETROS ---
$q        = trim($_GET['q'] ?? '');
$preset   = trim($_GET['period'] ?? 'all');
$provider = trim($_GET['provider'] ?? 'all');
$status   = trim($_GET['status'] ?? 'all');
$product  = trim($_GET['product'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$export   = trim($_GET['export'] ?? '');
$perPage  = 50;

// Datas do filtro de período
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
    case 'quarter':
        $startDate = date('Y-m-d', strtotime('-90 days'));
        $endDate = $today;
        break;
    case 'year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
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

// Cláusulas SQL de filtragem
$whereClauses = [];
$params = [];

if ($startDate !== '' && $endDate !== '') {
    $whereClauses[] = "s.sale_date BETWEEN :start_date AND :end_date";
    $params['start_date'] = $startDate . ' 00:00:00';
    $params['end_date']   = $endDate . ' 23:59:59';
}

if ($provider !== '' && $provider !== 'all') {
    $whereClauses[] = "s.provider = :provider";
    $params['provider'] = strtolower($provider);
}

if ($status !== '' && $status !== 'all') {
    if ($status === 'REFUND_ALL') {
        $whereClauses[] = "(s.status IN ('REFUNDED', 'REFUNDED_REQUEST', 'REFUND_REQUESTED') OR COALESCE(s.refunded_value, 0) > 0)";
    } else {
        $whereClauses[] = "s.status = :status";
        $params['status'] = strtoupper($status);
    }
}

if ($product !== '') {
    $whereClauses[] = "s.product_name = :product";
    $params['product'] = $product;
}

if ($q !== '') {
    $whereClauses[] = "(s.buyer_name LIKE :q OR s.buyer_email LIKE :q OR s.buyer_phone LIKE :q OR s.buyer_document LIKE :q OR s.transaction_code LIKE :q OR s.product_name LIKE :q)";
    $params['q'] = '%' . $q . '%';
}

$whereSql = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';
$salesAvailableCols = comp_sales_columns($pdo);
$optionalSalesSelect = comp_optional_sales_select($salesAvailableCols);

// Helper para formatar fone para WhatsApp
function comp_clean_phone(?string $phone): string {
    if (!$phone) return '';
    $clean = preg_replace('/\D+/', '', $phone);
    if ($clean === '') return '';
    if (strlen($clean) <= 11 && !str_starts_with($clean, '55')) {
        $clean = '55' . $clean;
    }
    return $clean;
}

// EXPORTAÇÃO CSV PARA EQUIPE DE SUPORTE (APENAS ADMINISTRADORES)
if ($export === 'csv') {
    if ($isEquipe) {
        header('Location: compradores.php');
        exit;
    }
    $csvSql = "SELECT s.id, s.provider, s.transaction_code, s.status, s.sale_date, s.payment_confirmed_at,
                      s.product_name, s.payment_method, s.installments,
                      s.buyer_name, s.buyer_email, s.buyer_phone, s.buyer_document,
                      {$optionalSalesSelect},
                      u.id AS user_id, u.created_at AS user_created_at
               FROM v_sales_master s
               LEFT JOIN users u ON (u.email = s.buyer_email AND s.buyer_email IS NOT NULL AND s.buyer_email != '')
               {$whereSql}
               ORDER BY s.sale_date DESC, s.id DESC";
    $stmtCsv = $pdo->prepare($csvSql);
    $stmtCsv->execute($params);
    $rows = $stmtCsv->fetchAll(PDO::FETCH_ASSOC);

    $csvEmails = array_filter(array_unique(array_map('trim', array_column($rows, 'buyer_email'))));
    $csvUserMap = [];
    if (!empty($csvEmails)) {
        $chunks = array_chunk(array_values($csvEmails), 500);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stU = $pdo->prepare("SELECT id, email FROM users WHERE email IN ($ph)");
            $stU->execute($chunk);
            while ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
                $csvUserMap[mb_strtolower(trim((string)$u['email']))] = $u['id'];
            }
        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="compradores_suporte_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID Venda', 'Plataforma', 'Codigo Transacao', 'Status Compra', 'Data Venda',
        'Data Confirmacao', 'Produto', 'Codigo Produto', 'Oferta', 'Metodo Pagamento', 'Parcelas',
        'Gateway', 'Conta Gateway', 'Canal', 'Origem Venda', 'UTM Source', 'UTM Medium',
        'UTM Campaign', 'UTM Term', 'UTM Content', 'Data Reembolso/Disputa',
        'Nome Comprador', 'Email Comprador', 'Telefone', 'CPF/Documento', 'Status Area Membros', 'ID Aluno'
    ]);

    foreach ($rows as $r) {
        $userStatus = !empty($r['user_id']) ? 'Inscrito' : 'Nao Inscrito';
        $em = mb_strtolower(trim((string)$r['buyer_email']));
        $uId = $csvUserMap[$em] ?? null;
        $userStatus = !empty($uId) ? 'Inscrito' : 'Nao Inscrito';
        fputcsv($output, [
            $r['id'],
            strtoupper((string)$r['provider']),
            $r['transaction_code'],
            comp_status_label((string)$r['status']),
            $r['sale_date'],
            $r['payment_confirmed_at'],
            $r['product_name'],
            comp_first_non_empty([$r['product_code'] ?? '', $r['external_product_id'] ?? '']),
            $r['price_name'] ?? '',
            comp_payment_method_label((string)($r['payment_method'] ?? '')),
            $r['installments'] ?: 1,
            $r['payment_gateway'] ?? '',
            $r['provider_account_id'] ?? '',
            $r['sales_channel'] ?? '',
            $r['sale_origin'] ?? '',
            $r['utm_source'] ?? '',
            $r['utm_medium'] ?? '',
            $r['utm_campaign'] ?? '',
            $r['utm_term'] ?? '',
            $r['utm_content'] ?? '',
            $r['refund_or_chargeback_at'] ?? '',
            $r['buyer_name'],
            $r['buyer_email'],
            $r['buyer_phone'],
            $r['buyer_document'],
            $userStatus,
            $uId ?: ''
        ]);
    }
    fclose($output);
    exit;
}

// TOTALIZADORES E PAGINAÇÃO
$countSql = "SELECT COUNT(*) FROM v_sales_master s {$whereSql}";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$offset     = ($page - 1) * $perPage;

// KPI STATS DA CONSULTA ATUAL (SEM VALORES FINANCEIROS)
$kpiSql = "SELECT 
              COUNT(*) AS total_sales,
              COUNT(DISTINCT NULLIF(TRIM(s.buyer_email), '')) AS unique_buyers,
              SUM(CASE WHEN s.status IN ('REFUNDED', 'REFUNDED_REQUEST', 'REFUND_REQUESTED') THEN 1 ELSE 0 END) AS total_refunded
           FROM v_sales_master s
           {$whereSql}";
$stmtKpi = $pdo->prepare($kpiSql);
$stmtKpi->execute($params);
$kpiData = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [
    'total_sales' => 0, 'unique_buyers' => 0, 'total_refunded' => 0
];

$enrolledCount = 0;
try {
    $stEmails = $pdo->prepare("SELECT DISTINCT NULLIF(TRIM(s.buyer_email), '') AS email FROM v_sales_master s {$whereSql}");
    $stEmails->execute($params);
    $distinctEmails = array_filter(array_unique(array_map('trim', $stEmails->fetchAll(PDO::FETCH_COLUMN))));
    if (!empty($distinctEmails)) {
        $chunks = array_chunk(array_values($distinctEmails), 500);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stU = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email IN ($ph)");
            $stU->execute($chunk);
            $enrolledCount += (int)$stU->fetchColumn();
        }
    }
} catch (Throwable $e) {}
$kpiData['enrolled_students'] = $enrolledCount;

// PRODUTOS E PROVIDERS DISPONÍVEIS PARA DROPDOWNS
$productsList = [];
try {
    $productsList = $pdo->query("SELECT DISTINCT product_name FROM v_sales_master WHERE product_name IS NOT NULL AND product_name != '' ORDER BY product_name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$providersList = [];
try {
    $providersList = $pdo->query("SELECT DISTINCT provider FROM v_sales_master WHERE provider IS NOT NULL AND provider != '' ORDER BY provider ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// CONSULTA PRINCIPAL DAS VENDAS (RÁPIDA)
$salesSql = "SELECT s.id, s.provider, s.transaction_code, s.status, s.sale_date, s.payment_confirmed_at,
                    s.product_name, s.payment_method, s.installments,
                    s.buyer_name, s.buyer_email, s.buyer_phone, s.buyer_document,
                    {$optionalSalesSelect}
             FROM v_sales_master s
             {$whereSql}
             ORDER BY s.sale_date DESC, s.id DESC
             LIMIT {$perPage} OFFSET {$offset}";
$stmtSales = $pdo->prepare($salesSql);
$stmtSales->execute($params);
$sales = $stmtSales->fetchAll(PDO::FETCH_ASSOC) ?: [];

// MAPEAMENTO RÁPIDO DE ALUNOS EM MEMÓRIA (50 VENDAS DA PÁGINA ATUAL)
$pageEmails = array_filter(array_unique(array_map('trim', array_column($sales, 'buyer_email'))));
$userMap = [];
if (!empty($pageEmails)) {
    $ph = implode(',', array_fill(0, count($pageEmails), '?'));
    $stU = $pdo->prepare("SELECT id, email, created_at, turma_codigo FROM users WHERE email IN ($ph)");
    $stU->execute(array_values($pageEmails));
    while ($u = $stU->fetch(PDO::FETCH_ASSOC)) {
        $userMap[mb_strtolower(trim((string)$u['email']))] = $u;
    }
}
foreach ($sales as &$s) {
    $em = mb_strtolower(trim((string)$s['buyer_email']));
    $s['user_id'] = $userMap[$em]['id'] ?? null;
    $s['user_created_at'] = $userMap[$em]['created_at'] ?? null;
    $s['turma_codigo'] = $userMap[$em]['turma_codigo'] ?? null;
}
unset($s);

require_once __DIR__ . '/_header.php';
?>

<style>
.comp-container { display: flex; flex-direction: column; gap: 16px; color: var(--text); }

/* Header & Titulo */
.comp-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }
.comp-title h1 { font-size: 20px; font-weight: 750; margin: 0; color: #f8fafc; display: flex; align-items: center; gap: 8px; }
.comp-title p { margin: 4px 0 0; font-size: 13px; color: var(--muted); }

/* KPIs */
.comp-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.comp-kpi-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
.comp-kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin-bottom: 4px; }
.comp-kpi-val { font-size: 24px; font-weight: 800; color: #f8fafc; }

/* Filtros */
.comp-filter-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.comp-presets { display: flex; flex-wrap: wrap; gap: 6px; }
.comp-preset-btn { background: var(--bg); border: 1px solid var(--border); color: var(--muted); padding: 5px 12px; font-size: 12px; font-weight: 600; border-radius: 7px; text-decoration: none; transition: all .15s ease; }
.comp-preset-btn:hover { background: var(--bg-hover); color: var(--text); }
.comp-preset-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }

.comp-filter-grid { display: grid; grid-template-columns: 2fr 1.2fr 1fr 1fr 1.5fr; gap: 10px; }
@media (max-width: 1024px) { .comp-filter-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px) { .comp-filter-grid { grid-template-columns: 1fr; } }

.comp-fg label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 5px; }
.comp-fg input, .comp-fg select { width: 100%; height: 38px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: #f1f5f9; padding: 0 10px; font-size: 12px; }
.comp-actions { display: flex; gap: 8px; align-items: flex-end; }
.comp-btn { height: 38px; padding: 0 16px; border-radius: 8px; border: 0; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
.comp-btn-primary { background: #3b82f6; color: #fff; }
.comp-btn-primary:hover { background: #2563eb; }
.comp-btn-csv { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
.comp-btn-csv:hover { background: rgba(34,197,94,0.3); }
.comp-btn-disabled { background: #1e293b !important; border: 1px solid #334155 !important; color: #64748b !important; cursor: not-allowed !important; opacity: 0.65; pointer-events: none; }
.comp-btn-clear { background: transparent; border: 1px solid var(--border); color: var(--muted); }
.comp-btn-clear:hover { color: var(--text); background: var(--bg-hover); }

/* Tabela */
.comp-table-wrap { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.comp-table { width: 100%; border-collapse: collapse; min-width: 1050px; font-size: 12px; }
.comp-table th { background: #0f172a; color: var(--muted); text-transform: uppercase; font-size: 10px; letter-spacing: .06em; padding: 12px; text-align: left; border-bottom: 1px solid var(--border); position: sticky; top: 0; }
.comp-table td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.comp-table tr:hover td { background: rgba(255,255,255,0.02); }

/* Badges */
.badge-status { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.bs-approved { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
.bs-refunded { background: rgba(239,68,68,0.18); color: #f87171; border: 1px solid rgba(239,68,68,0.35); }
.bs-refund-req { background: rgba(234,179,8,0.18); color: #facc15; border: 1px solid rgba(234,179,8,0.35); }
.bs-dispute { background: rgba(249,115,22,0.18); color: #fb923c; border: 1px solid rgba(249,115,22,0.35); }
.bs-pending { background: rgba(148,163,184,0.15); color: #cbd5e1; border: 1px solid rgba(148,163,184,0.3); }

.badge-user { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 650; text-decoration: none; }
.bu-yes { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.bu-yes:hover { background: rgba(59,130,246,0.3); }
.bu-no { background: rgba(148,163,184,0.1); color: #94a3b8; border: 1px solid rgba(148,163,184,0.2); }

.wa-btn { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px; background: rgba(34,197,94,0.12); color: #4ade80; border: 1px solid rgba(34,197,94,0.25); border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 11px; margin-left: 4px; }
.wa-btn:hover { background: rgba(34,197,94,0.28); color: #86efac; }

/* Paginação */
.comp-paging { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-top: 1px solid var(--border); color: var(--muted); font-size: 12px; }
.comp-page-links { display: flex; gap: 6px; }
.comp-page-links a, .comp-page-links span { padding: 6px 12px; border: 1px solid var(--border); border-radius: 7px; text-decoration: none; color: var(--text); }
.comp-page-links .active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
.comp-mini-list { display: grid; gap: 3px; margin-top: 4px; color: var(--muted); font-size: 10px; line-height: 1.35; }
.comp-mini-list div { display: flex; gap: 4px; align-items: baseline; min-width: 0; }
.comp-mini-list b { color: #94a3b8; font-weight: 700; white-space: nowrap; }
.comp-mini-list span { color: #cbd5e1; overflow-wrap: anywhere; }
.comp-mini-list .muted span { color: var(--muted); }
.comp-tag { display: inline-flex; max-width: 100%; padding: 2px 6px; border: 1px solid var(--border); border-radius: 6px; background: rgba(255,255,255,0.03); color: #cbd5e1; font-size: 10px; font-weight: 650; overflow-wrap: anywhere; }
</style>

<div class="comp-container">
  
  <!-- CABEÇALHO DA TELA -->
  <div class="comp-header">
    <div class="comp-title">
      <h1>🛍️ Lista de Compradores</h1>
      <p>Consulta completa de histórico de vendas e dados de contato dos compradores (Painel de Suporte).</p>
    </div>
  </div>

  <!-- KPIS SUMÁRIO DA SELEÇÃO -->
  <div class="comp-kpi-grid">
    <div class="comp-kpi-card">
      <div class="comp-kpi-label">Vendas Encontradas</div>
      <div class="comp-kpi-val"><?= number_format((int)$kpiData['total_sales'], 0, ',', '.') ?></div>
    </div>
    <div class="comp-kpi-card">
      <div class="comp-kpi-label">Compradores Únicos</div>
      <div class="comp-kpi-val"><?= number_format((int)$kpiData['unique_buyers'], 0, ',', '.') ?></div>
    </div>
    <div class="comp-kpi-card">
      <div class="comp-kpi-label">Inscritos na Área de Membros</div>
      <div class="comp-kpi-val" style="color:#60a5fa"><?= number_format((int)$kpiData['enrolled_students'], 0, ',', '.') ?></div>
    </div>
    <div class="comp-kpi-card">
      <div class="comp-kpi-label">Reembolsadas / Solicitações</div>
      <div class="comp-kpi-val" style="color:<?= (int)$kpiData['total_refunded'] > 0 ? '#f87171' : '#f8fafc' ?>"><?= number_format((int)$kpiData['total_refunded'], 0, ',', '.') ?></div>
    </div>
  </div>

  <!-- FILTROS DE CONSULTA -->
  <div class="comp-filter-card">
    <!-- Atalhos de Período -->
    <div class="comp-presets">
      <?php 
      $presetsList = [
          'all' => 'Histórico Completo (Tudo)',
          'today' => 'Hoje',
          '7' => 'Últimos 7d',
          '30' => 'Últimos 30d',
          '90' => 'Últimos 90d',
          '365' => 'Último Ano',
          'month' => 'Mês Atual',
          'custom' => 'Personalizado'
      ];
      foreach ($presetsList as $pk => $plbl):
          $pUrl = '?' . http_build_query(array_merge($_GET, ['period' => $pk, 'page' => 1]));
      ?>
        <a href="<?= am_h($pUrl) ?>" class="comp-preset-btn <?= $preset === $pk ? 'active' : '' ?>"><?= am_h($plbl) ?></a>
      <?php endforeach; ?>
    </div>

    <!-- Formulário Principal de Filtros -->
    <form method="get" action="compradores.php">
      <input type="hidden" name="period" value="<?= am_h($preset) ?>">
      
      <div class="comp-filter-grid">
        <!-- Campo Busca Textual -->
        <div class="comp-fg">
          <label>Buscar Comprador / Transação</label>
          <input type="text" name="q" value="<?= am_h($q) ?>" placeholder="Nome, e-mail, telefone, CPF ou transação...">
        </div>

        <!-- Filtro de Produto -->
        <div class="comp-fg">
          <label>Produto / Curso</label>
          <select name="product">
            <option value="">Todos os Produtos</option>
            <?php foreach ($productsList as $prod): ?>
              <option value="<?= am_h($prod) ?>" <?= $product === $prod ? 'selected' : '' ?>><?= am_h($prod) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Filtro de Status da Compra -->
        <div class="comp-fg">
          <label>Status da Compra</label>
          <select name="status">
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todos os Status</option>
            <option value="APPROVED" <?= $status === 'APPROVED' ? 'selected' : '' ?>>Aprovadas / Concluídas</option>
            <option value="REFUND_ALL" <?= $status === 'REFUND_ALL' ? 'selected' : '' ?>>⚠️ Reembolsadas & Solicitações</option>
            <option value="REFUNDED" <?= $status === 'REFUNDED' ? 'selected' : '' ?>>Reembolsadas</option>
            <option value="REFUNDED_REQUEST" <?= $status === 'REFUNDED_REQUEST' ? 'selected' : '' ?>>Solicitação de Reembolso</option>
            <option value="CHARGEBACK" <?= $status === 'CHARGEBACK' ? 'selected' : '' ?>>Chargeback / Disputa</option>
            <option value="PENDING" <?= $status === 'PENDING' ? 'selected' : '' ?>>Pendentes / Canceladas</option>
          </select>
        </div>

        <!-- Filtro de Gateway/Provider -->
        <div class="comp-fg">
          <label>Plataforma</label>
          <select name="provider">
            <option value="all" <?= $provider === 'all' ? 'selected' : '' ?>>Todas as Plataformas</option>
            <?php foreach ($providersList as $prov): ?>
              <option value="<?= am_h($prov) ?>" <?= strtolower($provider) === strtolower($prov) ? 'selected' : '' ?>><?= am_h(strtoupper($prov)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Botões de Ação -->
        <div class="comp-actions">
          <button type="submit" class="comp-btn comp-btn-primary">Filtrar</button>
          <?php if ($q !== '' || $product !== '' || $status !== 'all' || $provider !== 'all' || $preset !== 'all'): ?>
            <a href="compradores.php" class="comp-btn comp-btn-clear">Limpar</a>
          <?php endif; ?>
          <?php if ($isEquipe): ?>
            <button type="button" class="comp-btn comp-btn-disabled" disabled title="Apenas administradores podem exportar o relatório CSV">🔒 Exportar CSV (Apenas Admin)</button>
          <?php else: ?>
            <a href="?<?= am_h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="comp-btn comp-btn-csv">⬇️ Exportar CSV (Suporte)</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Datas Personalizadas se selecionado 'custom' -->
      <?php if ($preset === 'custom'): ?>
        <div style="display:flex; gap:10px; margin-top:10px; align-items:center;">
          <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:10px; text-transform:uppercase; color:var(--muted)">Data Inicial</label>
            <input type="date" name="from" value="<?= am_h($startDate) ?>" style="height:36px; background:var(--bg); border:1px solid var(--border); border-radius:6px; color:#fff; padding:0 8px;">
          </div>
          <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:10px; text-transform:uppercase; color:var(--muted)">Data Final</label>
            <input type="date" name="to" value="<?= am_h($endDate) ?>" style="height:36px; background:var(--bg); border:1px solid var(--border); border-radius:6px; color:#fff; padding:0 8px;">
          </div>
          <button type="submit" class="comp-btn comp-btn-primary" style="margin-top:auto; height:36px;">Aplicar Datas</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- TABELA DE COMPRADORES -->
  <div class="comp-table-wrap">
    <table class="comp-table">
      <thead>
        <tr>
          <th style="width: 40px;">#</th>
          <th>Comprador / Contato</th>
          <th>CPF / Documento</th>
          <th>Área de Membros</th>
          <th>Produto / Curso</th>
          <th>Plataforma / Transação</th>
          <th>Data Compra</th>
          <th>Dados da Compra</th>
          <th>Status da Compra</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sales)): ?>
          <tr>
            <td colspan="9" style="text-align: center; padding: 30px; color: var(--muted);">
              Nenhuma compra encontrada para os filtros selecionados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($sales as $idx => $s): 
              $stUpper = strtoupper((string)$s['status']);
              $waPhone = comp_clean_phone($s['buyer_phone']);
              $installments = (int)($s['installments'] ?? 0);
              $installments = $installments > 0 ? $installments : 1;
              $productCode = comp_first_non_empty([$s['product_code'] ?? '', $s['external_product_id'] ?? '']);
              $origin = comp_first_non_empty([$s['sales_channel'] ?? '', $s['sale_origin'] ?? '']);
              $utmParts = array_filter([
                  trim((string)($s['utm_source'] ?? '')),
                  trim((string)($s['utm_medium'] ?? '')),
                  trim((string)($s['utm_campaign'] ?? '')),
                  trim((string)($s['utm_term'] ?? '')),
                  trim((string)($s['utm_content'] ?? '')),
              ], static fn($v) => $v !== '');
          ?>
            <tr>
              <td style="color: var(--muted); font-weight: 600;">
                <?= $offset + $idx + 1 ?>
              </td>
              <td>
                <strong style="color: #f8fafc; font-size: 13px; display: block;"><?= am_h($s['buyer_name'] ?: 'Não Informado') ?></strong>
                <span style="color: var(--muted); font-size: 11px; display: block;"><?= am_h($s['buyer_email'] ?: '-') ?></span>
                <?php if ($s['buyer_phone']): ?>
                  <span style="color: var(--muted); font-size: 11px; display: inline-flex; align-items: center; margin-top: 2px;">
                    📞 <?= am_h($s['buyer_phone']) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-family: monospace; font-size: 11px; color: #cbd5e1;"><?= am_h($s['buyer_document'] ?: '-') ?></span>
              </td>
              <td>
                <?php if (!empty($s['user_id'])): ?>
                  <a href="alunos.php?q=<?= urlencode((string)$s['buyer_email']) ?>" class="badge-user bu-yes" title="Aluno cadastrado na Área de Membros (ID #<?= (int)$s['user_id'] ?>)">
                    🟢 Inscrito (#<?= (int)$s['user_id'] ?>)
                  </a>
                <?php else: ?>
                  <span class="badge-user bu-no">⚪ Não Inscrito</span>
                <?php endif; ?>
              </td>
              <td>
                <strong style="color: #e2e8f0; font-size: 12px;"><?= am_h($s['product_name'] ?: 'Curso/Produto') ?></strong>
                <div class="comp-mini-list">
                  <?php if ($productCode !== ''): ?>
                    <div><b>Codigo:</b><span><?= am_h($productCode) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($s['price_name'])): ?>
                    <div><b>Oferta:</b><span><?= am_h($s['price_name']) ?></span></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <span style="display: block; font-weight: 700; font-size: 11px; color: #60a5fa; text-transform: uppercase;">
                  <?= am_h(strtoupper((string)$s['provider'])) ?>
                </span>
                <span style="font-family: monospace; font-size: 10px; color: var(--muted); display: block;">
                  <?= am_h($s['transaction_code']) ?>
                </span>
                <div class="comp-mini-list">
                  <div><b>ID venda:</b><span><?= (int)$s['id'] ?></span></div>
                  <?php if (!empty($s['payment_gateway'])): ?>
                    <div><b>Gateway:</b><span><?= am_h($s['payment_gateway']) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($s['provider_account_id'])): ?>
                    <div><b>Conta:</b><span><?= am_h($s['provider_account_id']) ?></span></div>
                  <?php endif; ?>
                  <?php if ($origin !== ''): ?>
                    <div><b>Origem:</b><span><?= am_h($origin) ?></span></div>
                  <?php endif; ?>
                  <?php if (!empty($utmParts)): ?>
                    <div><b>UTM:</b><span><?= am_h(implode(' / ', $utmParts)) ?></span></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <span style="white-space: nowrap; color: #cbd5e1;">
                  <?= comp_date_label($s['sale_date'] ?? '') ?>
                </span>
                <div class="comp-mini-list">
                  <div class="<?= empty($s['payment_confirmed_at']) ? 'muted' : '' ?>"><b>Confirmacao:</b><span><?= !empty($s['payment_confirmed_at']) ? am_h(comp_date_label($s['payment_confirmed_at'])) : 'Aguardando' ?></span></div>
                  <?php if (!empty($s['refund_or_chargeback_at'])): ?>
                    <div><b>Reembolso/disputa:</b><span><?= am_h(comp_date_label($s['refund_or_chargeback_at'])) ?></span></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <span style="color: #e2e8f0; font-size: 11px; font-weight: 600;">
                  <?= am_h(comp_payment_method_label($s['payment_method'] ?? '')) ?>
                </span>
                <div class="comp-mini-list">
                  <div><b>Parcelas:</b><span><?= $installments ?>x</span></div>
                  <?php if (!empty($s['currency'])): ?>
                    <div><b>Moeda:</b><span><?= am_h($s['currency']) ?></span></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($stUpper === 'APPROVED' || $stUpper === 'COMPLETE'): ?>
                  <span class="badge-status bs-approved">🟢 <?= am_h(comp_status_label($stUpper)) ?></span>
                <?php elseif ($stUpper === 'REFUNDED'): ?>
                  <span class="badge-status bs-refunded">🔴 <?= am_h(comp_status_label($stUpper)) ?></span>
                <?php elseif (in_array($stUpper, ['REFUNDED_REQUEST', 'REFUND_REQUESTED'], true)): ?>
                  <span class="badge-status bs-refund-req">⚠️ <?= am_h(comp_status_label($stUpper)) ?></span>
                <?php elseif ($stUpper === 'CHARGEBACK' || $stUpper === 'DISPUTE'): ?>
                  <span class="badge-status bs-dispute">⛔ <?= am_h(comp_status_label($stUpper)) ?></span>
                <?php else: ?>
                  <span class="badge-status bs-pending">🟡 <?= am_h(comp_status_label($stUpper)) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPages > 1): ?>
      <div class="comp-paging">
        <div>Mostrando página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (<?= number_format($totalRecords, 0, ',', '.') ?> vendas no total)</div>
        <div class="comp-page-links">
          <?php if ($page > 1): ?>
            <a href="?<?= am_h(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">&laquo; Anterior</a>
          <?php endif; ?>
          
          <?php
          $startP = max(1, $page - 2);
          $endP   = min($totalPages, $page + 2);
          for ($p = $startP; $p <= $endP; $p++):
          ?>
            <a href="?<?= am_h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= am_h(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>">Próxima &raquo;</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
