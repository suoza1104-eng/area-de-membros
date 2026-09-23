<?php
declare(strict_types=1);
$__auditoriaJsonAction = (($_GET['action'] ?? '') === 'sale_detail');
if ($__auditoriaJsonAction) {
    ob_start();
}
$menu = 'vendas_analytics';
require_once __DIR__ . '/_header.php';

$pdo = getPDO();

// --- FILTROS E PARÂMETROS ---
$q        = trim($_GET['q'] ?? '');
$preset   = trim($_GET['period'] ?? '30');
$provider = trim($_GET['provider'] ?? 'all');
$status   = trim($_GET['status'] ?? 'all');
$product  = trim($_GET['product'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$export   = trim($_GET['export'] ?? '');
$action   = trim($_GET['action'] ?? '');
$perPage  = 50;

function va_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function va_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute(['table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function va_decode_json_payload(?string $json) {
    $json = trim((string)$json);
    if ($json === '') return null;
    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $json;
}

function va_rows(PDO $pdo, string $sql, array $params): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function va_first(PDO $pdo, string $sql, array $params): ?array {
    $rows = va_rows($pdo, $sql, $params);
    return $rows[0] ?? null;
}

function va_normalized_tx_variants(string $transaction): array {
    $transaction = trim($transaction);
    $variants = [$transaction];
    foreach (['hotmart:', 'pagarme:', 'dom:', 'firepay:'] as $prefix) {
        if (str_starts_with(strtolower($transaction), $prefix)) {
            $variants[] = substr($transaction, strlen($prefix));
        }
    }
    $variants = array_values(array_unique(array_filter($variants, static fn($v) => trim((string)$v) !== '')));
    return $variants ?: [$transaction];
}

function va_in_clause(array $values, string $prefix, array &$params): string {
    $keys = [];
    foreach (array_values($values) as $i => $value) {
        $key = ':' . $prefix . $i;
        $keys[] = $key;
        $params[$key] = $value;
    }
    return implode(',', $keys);
}

if ($action === 'sale_detail') {
    if (ob_get_length() !== false) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    $detailProvider = strtolower(trim((string)($_GET['provider'] ?? '')));
    $detailTx = trim((string)($_GET['transaction'] ?? ''));

    if ($detailTx === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Transacao nao informada.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $variants = va_normalized_tx_variants($detailTx);
    $variantParams = [];
    $variantSql = va_in_clause($variants, 'tx', $variantParams);
    $masterParams = $variantParams;
    $masterWhere = "s.transaction_code IN ({$variantSql})";
    if ($detailProvider !== '' && $detailProvider !== 'all') {
        $masterWhere .= ' AND s.provider = :provider';
        $masterParams[':provider'] = $detailProvider;
    }

    $master = va_first($pdo, "SELECT s.* FROM v_sales_master s WHERE {$masterWhere} ORDER BY s.sale_date DESC, s.id DESC LIMIT 1", $masterParams);
    $providerForLookup = strtolower((string)($master['provider'] ?? $detailProvider));
    $txForLookup = (string)($master['transaction_code'] ?? $detailTx);
    $lookupVariants = va_normalized_tx_variants($txForLookup);
    $lookupParams = [];
    $lookupSql = va_in_clause($lookupVariants, 'lk', $lookupParams);

    $payload = [
        'resumo_normalizado' => $master ?: ['transaction_code' => $detailTx, 'provider' => $detailProvider],
        'origens' => [],
    ];

    if (va_table_exists($pdo, 'hotmart_sales_live')) {
        $ledgerParams = $lookupParams;
        $ledgerWhere = "transaction_code IN ({$lookupSql})";
        if ($providerForLookup !== '') {
            $ledgerWhere .= " AND COALESCE(NULLIF(sales_channel,''),'hotmart') = :ledger_provider";
            $ledgerParams[':ledger_provider'] = $providerForLookup;
        }
        $ledger = va_first($pdo, "SELECT * FROM hotmart_sales_live WHERE {$ledgerWhere} ORDER BY updated_at DESC, id DESC LIMIT 1", $ledgerParams)
            ?: va_first($pdo, "SELECT * FROM hotmart_sales_live WHERE transaction_code IN ({$lookupSql}) ORDER BY updated_at DESC, id DESC LIMIT 1", $lookupParams);
        if ($ledger) {
            $payload['origens']['ledger_vendas'] = $ledger;
            if (array_key_exists('raw_payload_json', $ledger)) {
                $payload['origens']['payload_ledger'] = va_decode_json_payload((string)$ledger['raw_payload_json']);
            }
        }
    }

    if (va_table_exists($pdo, 'payment_sales')) {
        $payParams = $lookupParams;
        $payWhere = "external_transaction_id IN ({$lookupSql})";
        if ($providerForLookup !== '') {
            $payWhere .= ' AND provider = :pay_provider';
            $payParams[':pay_provider'] = $providerForLookup;
        }
        $paymentSale = va_first($pdo, "SELECT * FROM payment_sales WHERE {$payWhere} ORDER BY last_received_at DESC, id DESC LIMIT 1", $payParams)
            ?: va_first($pdo, "SELECT * FROM payment_sales WHERE external_transaction_id IN ({$lookupSql}) ORDER BY last_received_at DESC, id DESC LIMIT 1", $lookupParams);
        if ($paymentSale) {
            $payload['origens']['payment_sales'] = $paymentSale;
            if (array_key_exists('raw_payload_json', $paymentSale)) {
                $payload['origens']['payload_payment_sales'] = va_decode_json_payload((string)$paymentSale['raw_payload_json']);
            }
        }
    }

    $eventTables = [
        'hotmart' => 'hotmart_webhook_events',
        'pagarme' => 'pagarme_webhook_events',
        'dom' => 'dom_webhook_events',
        'firepay' => 'firepay_webhook_events',
    ];
    foreach ($eventTables as $eventProvider => $eventTable) {
        if ($providerForLookup !== '' && $providerForLookup !== $eventProvider) continue;
        if (!va_table_exists($pdo, $eventTable)) continue;

        if ($eventProvider === 'hotmart') {
            $events = va_rows($pdo, "SELECT * FROM {$eventTable} WHERE transaction_code IN ({$lookupSql}) ORDER BY received_at DESC, id DESC LIMIT 10", $lookupParams);
        } else {
            $events = va_rows($pdo, "SELECT * FROM {$eventTable} WHERE external_transaction_id IN ({$lookupSql}) ORDER BY received_at DESC, id DESC LIMIT 10", $lookupParams);
        }
        if ($events) {
            foreach ($events as &$eventRow) {
                if (array_key_exists('payload_json', $eventRow)) {
                    $eventRow['payload_json_decoded'] = va_decode_json_payload((string)$eventRow['payload_json']);
                }
            }
            unset($eventRow);
            $payload['origens']['eventos_' . $eventProvider] = $events;
        }
    }

    echo json_encode(['ok' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
}

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

// Montagem das Cláusulas SQL
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
    $whereClauses[] = "s.status = :status";
    $params['status'] = strtoupper($status);
}

if ($product !== '') {
    $whereClauses[] = "s.product_name = :product";
    $params['product'] = $product;
}

if ($q !== '') {
    $whereClauses[] = "(s.buyer_name LIKE :q OR s.buyer_email LIKE :q OR s.buyer_phone LIKE :q OR s.transaction_code LIKE :q OR s.product_name LIKE :q)";
    $params['q'] = '%' . $q . '%';
}

$whereSql = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

// Exportação CSV
if ($export === 'csv') {
    $csvSql = "SELECT s.id, s.provider, s.transaction_code, s.status, s.sale_date, s.payment_confirmed_at,
                      s.product_name, s.payment_method, s.installments, s.gross_revenue, s.net_revenue,
                      s.producer_net, s.fees, s.refunded_value, s.buyer_name, s.buyer_email, s.buyer_phone,
                      s.buyer_document, s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content
               FROM v_sales_master s
               {$whereSql}
               ORDER BY s.sale_date DESC, s.id DESC";
    $stmtCsv = $pdo->prepare($csvSql);
    $stmtCsv->execute($params);
    $rows = $stmtCsv->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="auditoria_vendas_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID', 'Gateway', 'Transacao', 'Status', 'Data Venda', 'Data Confirmacao',
        'Produto', 'Forma Pagamento', 'Parcelas', 'Bruto (R$)', 'Liquido (R$)',
        'Liquido Produtor (R$)', 'Taxas (R$)', 'Reembolsado (R$)', 'Comprador',
        'Email', 'Telefone', 'CPF/CNPJ', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content'
    ]);

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['id'],
            strtoupper((string)$r['provider']),
            $r['transaction_code'],
            $r['status'],
            $r['sale_date'],
            $r['payment_confirmed_at'],
            $r['product_name'],
            $r['payment_method'],
            $r['installments'],
            number_format((float)$r['gross_revenue'], 2, '.', ''),
            number_format((float)$r['net_revenue'], 2, '.', ''),
            number_format((float)$r['producer_net'], 2, '.', ''),
            number_format((float)$r['fees'], 2, '.', ''),
            number_format((float)$r['refunded_value'], 2, '.', ''),
            $r['buyer_name'],
            $r['buyer_email'],
            $r['buyer_phone'],
            $r['buyer_document'],
            $r['utm_source'],
            $r['utm_medium'],
            $r['utm_campaign'],
            $r['utm_term'],
            $r['utm_content']
        ]);
    }
    fclose($output);
    exit;
}

// Opções para Selects
$productsList = $pdo->query("SELECT DISTINCT product_name FROM v_sales_master WHERE product_name IS NOT NULL AND product_name <> '' ORDER BY product_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Totais Agregados (obedecendo estritamente aos filtros selecionados)
$isSpecificStatus = ($status !== '' && $status !== 'all');

if ($isSpecificStatus) {
    // Quando o usuário escolhe um status específico (ex: Aprovada, Pendente, Reembolsada, Cancelada)
    $totalsSql = "SELECT 
                    COUNT(*) AS total_sales,
                    COALESCE(SUM(s.gross_revenue), 0) AS total_gross,
                    COALESCE(SUM(s.net_revenue), 0) AS total_net,
                    COALESCE(SUM(s.producer_net), 0) AS total_producer_net,
                    COALESCE(SUM(s.fees), 0) AS total_fees,
                    COALESCE(SUM(CASE WHEN s.status IN ('REFUNDED', 'CHARGEBACK') THEN s.gross_revenue ELSE 0 END), 0) AS total_refunded
                  FROM v_sales_master s
                  {$whereSql}";
} else {
    // Quando o filtro de status é 'Todos os Status':
    // Transações Auditadas = Total de registros no filtro
    // Faturamento Bruto, Líquido Produtor e Taxas = Vendas Aprovadas (APPROVED)
    // Reembolso / Contestado = Vendas REFUNDED e CHARGEBACK
    $totalsSql = "SELECT 
                    COUNT(*) AS total_sales,
                    COALESCE(SUM(CASE WHEN s.status = 'APPROVED' THEN s.gross_revenue ELSE 0 END), 0) AS total_gross,
                    COALESCE(SUM(CASE WHEN s.status = 'APPROVED' THEN s.net_revenue ELSE 0 END), 0) AS total_net,
                    COALESCE(SUM(CASE WHEN s.status = 'APPROVED' THEN s.producer_net ELSE 0 END), 0) AS total_producer_net,
                    COALESCE(SUM(CASE WHEN s.status = 'APPROVED' THEN s.fees ELSE 0 END), 0) AS total_fees,
                    COALESCE(SUM(CASE WHEN s.status IN ('REFUNDED', 'CHARGEBACK') THEN s.gross_revenue ELSE 0 END), 0) AS total_refunded
                  FROM v_sales_master s
                  {$whereSql}";
}

$stmtTotals = $pdo->prepare($totalsSql);
$stmtTotals->execute($params);
$totals = $stmtTotals->fetch(PDO::FETCH_ASSOC) ?: [
    'total_sales' => 0, 'total_gross' => 0, 'total_net' => 0,
    'total_producer_net' => 0, 'total_fees' => 0, 'total_refunded' => 0
];

$totalRecords = (int)$totals['total_sales'];
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$offset       = ($page - 1) * $perPage;

// Consulta de Vendas Paginadas
$listSql = "SELECT s.*
            FROM v_sales_master s
            {$whereSql}
            ORDER BY s.sale_date DESC, s.id DESC
            LIMIT {$perPage} OFFSET {$offset}";
$stmtList = $pdo->prepare($listSql);
$stmtList->execute($params);
$sales = $stmtList->fetchAll(PDO::FETCH_ASSOC);

function va_status_badge(string $status): string {
    $s = strtoupper(trim($status));
    switch ($s) {
        case 'APPROVED':
            return '<span class="status-badge st-approved"><i class="ph ph-check-circle"></i> APROVADA</span>';
        case 'PENDING':
            return '<span class="status-badge st-pending"><i class="ph ph-clock"></i> PENDENTE</span>';
        case 'REFUNDED':
            return '<span class="status-badge st-refunded"><i class="ph ph-arrow-counter-clockwise"></i> REEMBOLSADA</span>';
        case 'CHARGEBACK':
            return '<span class="status-badge st-chargeback"><i class="ph ph-warning-circle"></i> CONTESTADO</span>';
        case 'CANCELED':
            return '<span class="status-badge st-canceled"><i class="ph ph-x-circle"></i> CANCELADA</span>';
        default:
            return '<span class="status-badge st-default">' . htmlspecialchars($s) . '</span>';
    }
}

function va_provider_badge(string $provider): string {
    $p = strtolower(trim($provider));
    switch ($p) {
        case 'hotmart':
            return '<span class="prov-badge prov-hotmart">HOTMART</span>';
        case 'dom':
            return '<span class="prov-badge prov-dom">DOM PAGAMENTOS</span>';
        case 'pagarme':
            return '<span class="prov-badge prov-pagarme">PAGAR.ME</span>';
        default:
            return '<span class="prov-badge prov-default">' . htmlspecialchars(strtoupper($p)) . '</span>';
    }
}
?>

<style>
.aud-container { display: flex; flex-direction: column; gap: 18px; font-family: system-ui, -apple-system, sans-serif; color: var(--text); }
.aud-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; background: var(--bg-card); border: 1px solid var(--border); padding: 18px 20px; border-radius: 12px; }
.aud-title h1 { font-size: 22px; margin: 0; font-weight: 750; color: #f8fafc; }
.aud-title p { margin: 4px 0 0; font-size: 13px; color: var(--muted); }
.aud-nav-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); border-radius: 10px; color: #60a5fa; font-weight: 650; font-size: 13px; text-decoration: none; transition: all .15s ease; }
.aud-nav-btn:hover { background: rgba(59,130,246,0.25); border-color: rgba(59,130,246,0.5); color: #93c5fd; }

.aud-cards { display: grid; grid-template-columns: repeat(5, minmax(150px, 1fr)); gap: 12px; }
.aud-card { background: var(--bg-card); border: 1px solid var(--border); padding: 14px 16px; border-radius: 10px; position: relative; overflow: hidden; }
.aud-card small { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); display: block; }
.aud-card strong { font-size: 20px; font-weight: 800; margin-top: 6px; display: block; color: #f1f5f9; }
.aud-card.highlight { border-color: rgba(34,197,94,0.3); background: linear-gradient(145deg, var(--bg-card), rgba(34,197,94,0.06)); }
.aud-card.highlight strong { color: #4ade80; }
.aud-card.refund strong { color: #f87171; }

.aud-filter-box { background: var(--bg-card); border: 1px solid var(--border); padding: 16px; border-radius: 12px; }
.aud-presets { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.aud-presets a { padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; color: var(--muted); font-size: 12px; font-weight: 600; text-decoration: none; }
.aud-presets a.active, .aud-presets a:hover { background: rgba(250,204,21,0.15); border-color: rgba(250,204,21,0.4); color: #facc15; }

.aud-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
@media (max-width: 1100px) { .aud-grid { grid-template-columns: 1fr 1fr 1fr; } }
@media (max-width: 700px) { .aud-grid { grid-template-columns: 1fr; } .aud-cards { grid-template-columns: 1fr 1fr; } }

.aud-fg label { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 5px; }
.aud-fg input, .aud-fg select { width: 100%; height: 38px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: #f1f5f9; padding: 0 10px; font-size: 12px; }
.aud-actions { display: flex; gap: 8px; }
.aud-btn { height: 38px; padding: 0 16px; border-radius: 8px; border: 0; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
.aud-btn-primary { background: #3b82f6; color: #fff; }
.aud-btn-primary:hover { background: #2563eb; }
.aud-btn-csv { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
.aud-btn-csv:hover { background: rgba(34,197,94,0.3); }

.aud-table-wrap { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; overflow: auto; }
.aud-table { width: 100%; border-collapse: collapse; min-width: 1100px; font-size: 12px; }
.aud-table th { background: #0f172a; color: var(--muted); text-transform: uppercase; font-size: 10px; letter-spacing: .06em; padding: 12px; text-align: left; border-bottom: 1px solid var(--border); position: sticky; top: 0; }
.aud-table td { padding: 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
.aud-table tr:hover td { background: rgba(255,255,255,0.02); }
.aud-sale-row { cursor: pointer; }
.aud-sale-row:focus-visible td { outline: 2px solid rgba(96,165,250,0.85); outline-offset: -2px; }

.buyer-info strong { display: block; color: #f8fafc; font-size: 13px; }
.buyer-info span { display: block; color: var(--muted); font-size: 11px; margin-top: 2px; }

.status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 750; letter-spacing: .03em; }
.st-approved { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
.st-pending { background: rgba(234,179,8,0.15); color: #facc15; border: 1px solid rgba(234,179,8,0.3); }
.st-refunded { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.st-chargeback { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
.st-canceled { background: rgba(148,163,184,0.15); color: #94a3b8; border: 1px solid rgba(148,163,184,0.3); }
.st-default { background: var(--bg-hover); color: var(--text); }

.prov-badge { display: inline-block; padding: 2px 7px; border-radius: 6px; font-size: 9px; font-weight: 800; letter-spacing: .05em; margin-top: 4px; }
.prov-hotmart { background: rgba(249,115,22,0.18); color: #fb923c; border: 1px solid rgba(249,115,22,0.3); }
.prov-dom { background: rgba(14,165,233,0.18); color: #38bdf8; border: 1px solid rgba(14,165,233,0.3); }
.prov-pagarme { background: rgba(168,85,247,0.18); color: #c084fc; border: 1px solid rgba(168,85,247,0.3); }

.money-box strong { color: #4ade80; display: block; font-size: 13px; }
.money-box span { color: var(--muted); font-size: 10px; }

.aud-paging { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-top: 1px solid var(--border); color: var(--muted); font-size: 12px; }
.aud-page-links { display: flex; gap: 6px; }
.aud-page-links a, .aud-page-links span { padding: 6px 12px; border: 1px solid var(--border); border-radius: 7px; text-decoration: none; color: var(--text); }
.aud-page-links .active { background: #3b82f6; color: #fff; border-color: #3b82f6; }

.aud-modal[hidden] { display: none; }
.aud-modal { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 22px; }
.aud-modal-backdrop { position: absolute; inset: 0; background: rgba(2,6,23,0.78); backdrop-filter: blur(4px); }
.aud-modal-panel { position: relative; width: min(1180px, 96vw); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; background: #070b12; border: 1px solid rgba(96,165,250,0.25); border-radius: 12px; box-shadow: 0 24px 80px rgba(0,0,0,0.55); }
.aud-modal-head { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; padding: 18px 20px; border-bottom: 1px solid var(--border); background: #0f172a; }
.aud-modal-title { min-width: 0; }
.aud-modal-title small { display: block; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; font-size: 10px; margin-bottom: 4px; }
.aud-modal-title strong { display: block; color: #f8fafc; font-size: 18px; overflow-wrap: anywhere; }
.aud-modal-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.aud-icon-btn { width: 38px; height: 38px; border-radius: 8px; border: 1px solid var(--border); background: rgba(15,23,42,0.8); color: #cbd5e1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.aud-icon-btn:hover { color: #fff; border-color: rgba(96,165,250,0.5); background: rgba(59,130,246,0.16); }
.aud-modal-body { overflow: auto; padding: 16px 20px 20px; }
.aud-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
.aud-detail-kv { border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: rgba(15,23,42,0.42); min-width: 0; }
.aud-detail-kv small { display: block; color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
.aud-detail-kv strong { color: #f8fafc; font-size: 12px; overflow-wrap: anywhere; }
.aud-detail-section { margin-top: 14px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: rgba(2,6,23,0.35); }
.aud-detail-section > summary { list-style: none; display: flex; justify-content: space-between; gap: 12px; padding: 12px 14px; cursor: pointer; color: #e2e8f0; font-weight: 750; background: rgba(15,23,42,0.72); }
.aud-detail-section > summary::-webkit-details-marker { display: none; }
.aud-detail-section > summary span { color: var(--muted); font-size: 11px; font-weight: 600; }
.aud-json-tree { padding: 10px 14px 14px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 11px; color: #cbd5e1; }
.aud-json-row { display: grid; grid-template-columns: minmax(180px, 34%) minmax(0, 1fr); gap: 12px; padding: 5px 0; border-bottom: 1px solid rgba(148,163,184,0.08); }
.aud-json-key { color: #93c5fd; overflow-wrap: anywhere; }
.aud-json-value { color: #e5e7eb; white-space: pre-wrap; overflow-wrap: anywhere; }
.aud-json-type { color: #64748b; }
.aud-modal-empty { color: var(--muted); padding: 26px; text-align: center; }
@media (max-width: 900px) { .aud-detail-grid { grid-template-columns: 1fr 1fr; } .aud-json-row { grid-template-columns: 1fr; gap: 4px; } }
@media (max-width: 600px) { .aud-modal { padding: 10px; } .aud-modal-head { flex-direction: column; } .aud-detail-grid { grid-template-columns: 1fr; } }
</style>

<div class="aud-container">
  
  <!-- CABEÇALHO COM NAVEGAÇÃO -->
  <div class="aud-head">
    <div class="aud-title">
      <h1>📋 Tabela de Auditoria de Vendas</h1>
      <p>Visão individualizada e reconciliada de todas as vendas (Hotmart, DOM Pagamentos e Pagar.me).</p>
    </div>
    <a href="vendas_analytics.php" class="aud-nav-btn">
      <i class="ph ph-arrow-left"></i> ⬅️ Voltar para Dashboard de Vendas
    </a>
  </div>

  <!-- 1. FORMULÁRIO DE FILTROS (NO TOPO) -->
  <div class="aud-filter-box">
    <div class="aud-presets">
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

    <form method="get" class="aud-grid">
      <input type="hidden" name="period" value="<?= htmlspecialchars($preset) ?>">
      
      <div class="aud-fg">
        <label>Buscar Aluno / Transação / Email / Tel</label>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nome, email, telefone ou codigo HP...">
      </div>

      <div class="aud-fg">
        <label>Gateway / Provedor</label>
        <select name="provider">
          <option value="all" <?= $provider === 'all' ? 'selected' : '' ?>>Todos os Gateways</option>
          <option value="hotmart" <?= $provider === 'hotmart' ? 'selected' : '' ?>>Hotmart</option>
          <option value="dom" <?= $provider === 'dom' ? 'selected' : '' ?>>DOM Pagamentos</option>
          <option value="pagarme" <?= $provider === 'pagarme' ? 'selected' : '' ?>>Pagar.me</option>
        </select>
      </div>

      <div class="aud-fg">
        <label>Status da Venda</label>
        <select name="status">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todos os Status</option>
          <option value="APPROVED" <?= $status === 'APPROVED' ? 'selected' : '' ?>>Aprovada (APPROVED)</option>
          <option value="PENDING" <?= $status === 'PENDING' ? 'selected' : '' ?>>Pendente (PENDING)</option>
          <option value="REFUNDED" <?= $status === 'REFUNDED' ? 'selected' : '' ?>>Reembolsada (REFUNDED)</option>
          <option value="CHARGEBACK" <?= $status === 'CHARGEBACK' ? 'selected' : '' ?>>Contestado (CHARGEBACK)</option>
          <option value="CANCELED" <?= $status === 'CANCELED' ? 'selected' : '' ?>>Cancelada (CANCELED)</option>
        </select>
      </div>

      <div class="aud-fg">
        <label>Produto / Oferta</label>
        <select name="product">
          <option value="">Todos os Produtos</option>
          <?php foreach ($productsList as $pName): ?>
            <option value="<?= htmlspecialchars($pName) ?>" <?= $product === $pName ? 'selected' : '' ?>>
              <?= htmlspecialchars($pName) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($preset === 'custom'): ?>
        <div class="aud-fg">
          <label>Data Início</label>
          <input type="date" name="from" value="<?= htmlspecialchars($startDate) ?>">
        </div>
        <div class="aud-fg">
          <label>Data Fim</label>
          <input type="date" name="to" value="<?= htmlspecialchars($endDate) ?>">
        </div>
      <?php endif; ?>

      <div class="aud-actions">
        <button type="submit" class="aud-btn aud-btn-primary">
          <i class="ph ph-magnifying-glass"></i> Filtrar
        </button>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="aud-btn aud-btn-csv">
          <i class="ph ph-download-simple"></i> Exportar CSV
        </a>
      </div>
    </form>
  </div>

  <!-- 2. CARDS DE RESUMO DA AUDITORIA (ABAIXO DOS FILTROS) -->
  <div class="aud-cards">
    <div class="aud-card">
      <small>Transações Auditadas</small>
      <strong><?= number_format($totalRecords, 0, ',', '.') ?></strong>
    </div>
    <div class="aud-card">
      <small>Faturamento Bruto</small>
      <strong>R$ <?= number_format((float)$totals['total_gross'], 2, ',', '.') ?></strong>
    </div>
    <div class="aud-card highlight">
      <small>Líquido Produtor</small>
      <strong>R$ <?= number_format((float)$totals['total_producer_net'], 2, ',', '.') ?></strong>
    </div>
    <div class="aud-card">
      <small>Taxas de Gateways</small>
      <strong>R$ <?= number_format((float)$totals['total_fees'], 2, ',', '.') ?></strong>
    </div>
    <div class="aud-card refund">
      <small>Reembolso / Contestado</small>
      <strong>R$ <?= number_format((float)$totals['total_refunded'], 2, ',', '.') ?></strong>
    </div>
  </div>

  <!-- 3. TABELA DE VENDAS -->
  <div class="aud-table-wrap">
    <table class="aud-table">
      <thead>
        <tr>
          <th>Data / Hora</th>
          <th>Transação & Gateway</th>
          <th>Status</th>
          <th>Comprador / Aluno</th>
          <th>Produto & Pagamento</th>
          <th>Valores (Bruto / Taxas / Líq)</th>
          <th>UTM Origem</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($sales)): ?>
          <tr>
            <td colspan="7" style="text-align:center; padding: 40px; color: var(--muted);">
              Nenhuma venda encontrada para os filtros selecionados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($sales as $s): ?>
            <tr class="aud-sale-row" tabindex="0" role="button" aria-label="Abrir payload da venda <?= htmlspecialchars((string)$s['transaction_code']) ?>" data-provider="<?= htmlspecialchars((string)$s['provider']) ?>" data-transaction="<?= htmlspecialchars((string)$s['transaction_code']) ?>">
              <td>
                <strong style="color: #f1f5f9; font-size: 12px; display:block;">
                  <?= date('d/m/Y H:i', strtotime((string)$s['sale_date'])) ?>
                </strong>
                <span style="color: var(--muted); font-size: 10px;">
                  <?= !empty($s['payment_confirmed_at']) ? 'Conf: ' . date('d/m/Y H:i', strtotime((string)$s['payment_confirmed_at'])) : 'Aguardando' ?>
                </span>
              </td>
              <td>
                <strong style="font-family: monospace; color: #93c5fd; font-size: 12px;">
                  <?= htmlspecialchars((string)$s['transaction_code']) ?>
                </strong>
                <div><?= va_provider_badge((string)$s['provider']) ?></div>
              </td>
              <td>
                <?= va_status_badge((string)$s['status']) ?>
              </td>
              <td class="buyer-info">
                <strong><?= htmlspecialchars((string)($s['buyer_name'] ?: 'Não informado')) ?></strong>
                <span><?= htmlspecialchars((string)($s['buyer_email'] ?: '-')) ?></span>
                <?php if (!empty($s['buyer_phone'])): ?>
                  <span><i class="ph ph-whatsapp-logo"></i> <?= htmlspecialchars((string)$s['buyer_phone']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <strong style="color: #e2e8f0; font-size: 12px;">
                  <?= htmlspecialchars((string)$s['product_name']) ?>
                </strong>
                <div style="color: var(--muted); font-size: 10px; margin-top: 2px;">
                  <?= htmlspecialchars(strtoupper((string)($s['payment_method'] ?: 'PIX/OUTRO'))) ?> 
                  <?= !empty($s['installments']) && (int)$s['installments'] > 1 ? '(' . (int)$s['installments'] . 'x)' : '' ?>
                </div>
              </td>
              <td class="money-box">
                <strong>R$ <?= number_format((float)$s['producer_net'], 2, ',', '.') ?> <small style="font-weight:normal; font-size:9px; color:#94a3b8;">(Produtor)</small></strong>
                <span>Bruto: R$ <?= number_format((float)$s['gross_revenue'], 2, ',', '.') ?></span>
                <?php if ((float)$s['fees'] > 0): ?>
                  <span style="color:#fca5a5; display:block;">Taxa: -R$ <?= number_format((float)$s['fees'], 2, ',', '.') ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($s['utm_source'])): ?>
                  <span style="display:inline-block; padding: 2px 6px; background: var(--bg); border: 1px solid var(--border); border-radius: 5px; font-size: 10px; color: var(--muted);">
                    <?= htmlspecialchars((string)$s['utm_source']) ?>
                  </span>
                <?php else: ?>
                  <span style="color: var(--muted); font-size: 10px;">-</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPages > 1): ?>
      <div class="aud-paging">
        <div>
          Exibindo página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (Total: <?= number_format($totalRecords, 0, ',', '.') ?> registros)
        </div>
        <div class="aud-page-links">
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

<div class="aud-modal" id="audSaleModal" hidden aria-hidden="true">
  <div class="aud-modal-backdrop" data-aud-close></div>
  <section class="aud-modal-panel" role="dialog" aria-modal="true" aria-labelledby="audSaleModalTitle">
    <header class="aud-modal-head">
      <div class="aud-modal-title">
        <small>Detalhamento do payload recebido</small>
        <strong id="audSaleModalTitle">Venda</strong>
      </div>
      <div class="aud-modal-actions">
        <button type="button" class="aud-icon-btn" id="audCopyPayload" title="Copiar JSON completo" aria-label="Copiar JSON completo"><i class="ph ph-copy"></i></button>
        <button type="button" class="aud-icon-btn" data-aud-close title="Fechar" aria-label="Fechar"><i class="ph ph-x"></i></button>
      </div>
    </header>
    <div class="aud-modal-body" id="audSaleModalBody">
      <div class="aud-modal-empty">Carregando...</div>
    </div>
  </section>
</div>

<script>
(function(){
  const modal = document.getElementById('audSaleModal');
  const title = document.getElementById('audSaleModalTitle');
  const body = document.getElementById('audSaleModalBody');
  const copyBtn = document.getElementById('audCopyPayload');
  let currentPayload = null;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  function scalar(value) {
    if (value === null) return '<span class="aud-json-type">null</span>';
    if (value === true || value === false) return '<span class="aud-json-type">' + value + '</span>';
    if (typeof value === 'number') return '<span class="aud-json-type">' + value + '</span>';
    if (typeof value === 'string' && value.trim() === '') return '<span class="aud-json-type">(vazio)</span>';
    return esc(value);
  }

  function typeLabel(value) {
    if (Array.isArray(value)) return 'array[' + value.length + ']';
    if (value && typeof value === 'object') return 'objeto';
    if (value === null) return 'null';
    return typeof value;
  }

  function rowsFrom(value, path, depth) {
    const pad = Math.min(depth * 16, 160);
    if (Array.isArray(value)) {
      if (!value.length) return '<div class="aud-json-row"><div class="aud-json-key" style="padding-left:' + pad + 'px">' + esc(path) + '</div><div class="aud-json-value"><span class="aud-json-type">array vazio</span></div></div>';
      return value.map((item, idx) => rowsFrom(item, path + '[' + idx + ']', depth + 1)).join('');
    }
    if (value && typeof value === 'object') {
      const keys = Object.keys(value);
      if (!keys.length) return '<div class="aud-json-row"><div class="aud-json-key" style="padding-left:' + pad + 'px">' + esc(path) + '</div><div class="aud-json-value"><span class="aud-json-type">objeto vazio</span></div></div>';
      return keys.map(key => rowsFrom(value[key], path ? path + '.' + key : key, depth + 1)).join('');
    }
    return '<div class="aud-json-row"><div class="aud-json-key" style="padding-left:' + pad + 'px">' + esc(path || 'valor') + '</div><div class="aud-json-value">' + scalar(value) + '</div></div>';
  }

  function section(label, value, open) {
    if (value === undefined || value === null || value === '') return '';
    return '<details class="aud-detail-section" ' + (open ? 'open' : '') + '><summary>' + esc(label) + '<span>' + esc(typeLabel(value)) + '</span></summary><div class="aud-json-tree">' + rowsFrom(value, '', 0) + '</div></details>';
  }

  function summaryGrid(master) {
    const items = [
      ['Gateway', master.provider],
      ['Transacao', master.transaction_code],
      ['Status', master.status],
      ['Data da venda', master.sale_date],
      ['Comprador', master.buyer_name],
      ['Email', master.buyer_email],
      ['Telefone', master.buyer_phone],
      ['Produto', master.product_name],
    ];
    return '<div class="aud-detail-grid">' + items.map(([k,v]) => '<div class="aud-detail-kv"><small>' + esc(k) + '</small><strong>' + scalar(v ?? '-') + '</strong></div>').join('') + '</div>';
  }

  function openModal() {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  async function loadDetail(row) {
    const provider = row.dataset.provider || '';
    const transaction = row.dataset.transaction || '';
    title.textContent = transaction || 'Venda';
    body.innerHTML = '<div class="aud-modal-empty">Carregando payload...</div>';
    currentPayload = null;
    openModal();

    const url = new URL(window.location.href);
    url.searchParams.set('action', 'sale_detail');
    url.searchParams.set('provider', provider);
    url.searchParams.set('transaction', transaction);
    url.searchParams.delete('export');

    try {
      const res = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
      const json = await res.json();
      if (!json.ok) throw new Error(json.message || 'Falha ao carregar payload.');
      currentPayload = json.data;
      const master = currentPayload.resumo_normalizado || {};
      title.textContent = (master.provider ? String(master.provider).toUpperCase() + ' - ' : '') + (master.transaction_code || transaction);
      const origins = currentPayload.origens || {};
      body.innerHTML = summaryGrid(master)
        + section('Dados normalizados da venda', master, true)
        + Object.keys(origins).map(key => section(key, origins[key], key.indexOf('payload') !== -1)).join('');
      if (!Object.keys(origins).length) {
        body.innerHTML += '<div class="aud-modal-empty">Nenhum payload bruto encontrado nas tabelas de origem para esta transacao.</div>';
      }
    } catch (err) {
      body.innerHTML = '<div class="aud-modal-empty">Nao foi possivel carregar o detalhe: ' + esc(err.message || err) + '</div>';
    }
  }

  document.querySelectorAll('.aud-sale-row').forEach(row => {
    row.addEventListener('click', event => {
      if (event.target.closest('a,button,input,select,textarea')) return;
      loadDetail(row);
    });
    row.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        loadDetail(row);
      }
    });
  });

  modal.querySelectorAll('[data-aud-close]').forEach(el => el.addEventListener('click', closeModal));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
  copyBtn.addEventListener('click', async () => {
    if (!currentPayload || !navigator.clipboard) return;
    await navigator.clipboard.writeText(JSON.stringify(currentPayload, null, 2));
    copyBtn.innerHTML = '<i class="ph ph-check"></i>';
    window.setTimeout(() => { copyBtn.innerHTML = '<i class="ph ph-copy"></i>'; }, 1200);
  });
})();
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
