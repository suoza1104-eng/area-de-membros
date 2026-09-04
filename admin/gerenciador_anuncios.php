<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics_dashboard.php';
proteger_admin();

$menu = 'ads_manager';
$page_title = 'Gerenciador de Anúncios';
$pdo = getPDO();
metrics_ensure_schema($pdo);

function am_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function am_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function am_num($value, int $decimals = 0): string { return number_format((float)$value, $decimals, ',', '.'); }
function am_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8');
}
function am_ads_cell(array $metrics, array $days, string $source, string $key, string $format = 'num'): string {
    $parts = [];
    foreach (['x', 'y', 'z'] as $w) {
        $view = md_ads_metric_view($metrics[$w] ?? [], $source);
        $value = $view[$key] ?? 0;
        $parts[] = $format === 'money' ? am_money($value) : ($format === 'decimal' ? am_num($value, 2) : am_num($value));
    }
    return implode(' <span class="ads-sep">/</span> ', $parts);
}
function am_compare_cell(float $a, float $b, bool $lowerBetter = false, string $format = 'money'): string {
    $fmt = static fn(float $v): string => $format === 'money' ? am_money($v) : am_num($v, 2);
    $delta = $b != 0 ? (($a - $b) / abs($b)) * 100 : null;
    $class = 'neutral';
    if ($delta !== null && abs($delta) >= .05) { $better = $lowerBetter ? $delta < 0 : $delta > 0; $class = $better ? 'good' : 'bad'; }
    return '<div>' . $fmt($a) . ' <span class="ads-sep">/</span> ' . $fmt($b) . '</div><span class="trend ' . $class . '">' . ($delta === null ? 'Sem base' : (($delta > 0 ? '+' : '') . number_format($delta, 1, ',', '.') . '%')) . '</span>';
}

function am_collect_ads(array $tree): array {
    $ads = [];
    foreach ($tree as $campaign) {
        foreach ($campaign['adsets'] as $adset) {
            foreach ($adset['ads'] as $ad) {
                $ads[] = ['campaign' => $campaign['name'], 'adset' => $adset['name'], 'ad' => $ad['name'], 'metrics' => $ad['metrics']['x'] ?? []];
            }
        }
    }
    return $ads;
}
// Ranqueia por uma pontuacao composta (ROAS pesa a favor, CPC e CPL pesam
// contra), cada metrica normalizada 0-1 dentro do proprio conjunto de
// anuncios com investimento no periodo — assim nenhuma das 3 metricas
// domina sozinha so por causa de escala (ex.: ROAS 5 vs CPC R$0,50).
function am_rank_top_ads(array $ads, string $source, int $limit = 10): array {
    $rows = [];
    foreach ($ads as $ad) {
        $view = md_ads_metric_view($ad['metrics'], $source);
        if ($view['spend'] <= 0) continue;
        $ad['view'] = $view;
        $rows[] = $ad;
    }
    if (!$rows) return [];
    $maxRoas = max(array_map(static fn($r) => $r['view']['roas'], $rows)) ?: 1.0;
    $maxCpc = max(array_map(static fn($r) => $r['view']['cpc'], $rows)) ?: 1.0;
    $maxCpl = max(array_map(static fn($r) => $r['view']['cpl'], $rows)) ?: 1.0;
    foreach ($rows as &$r) {
        $roasScore = $maxRoas > 0 ? $r['view']['roas'] / $maxRoas : 0.0;
        $cpcScore = $maxCpc > 0 ? 1.0 - ($r['view']['cpc'] / $maxCpc) : 1.0;
        $cplScore = $maxCpl > 0 ? 1.0 - ($r['view']['cpl'] / $maxCpl) : 1.0;
        $r['score'] = ($roasScore + $cpcScore + $cplScore) / 3;
    }
    unset($r);
    usort($rows, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($rows, 0, $limit);
}
function am_resolve_ad_ids(PDO $pdo, array $topAds, string $start, string $end): array {
    foreach ($topAds as &$row) {
        $found = md_row($pdo, "SELECT ad_id, integration_id FROM meta_ad_daily
            WHERE campaign_name = :c AND adset_name = :a AND ad_name = :d AND report_date BETWEEN :start AND :end
            ORDER BY report_date DESC LIMIT 1",
            ['c' => $row['campaign'], 'a' => $row['adset'], 'd' => $row['ad'], 'start' => $start, 'end' => $end]);
        $row['ad_id'] = $found['ad_id'] ?? null;
        $row['integration_id'] = isset($found['integration_id']) ? (int)$found['integration_id'] : null;
    }
    unset($row);
    return $topAds;
}
// Busca a miniatura do criativo direto na Graph API (nao fica salva em
// nenhuma tabela nossa), uma chamada por anuncio do Top 10 — no maximo 10
// chamadas. Testado com o endpoint em lote (?ids=a,b,c) primeiro, mas a
// Meta retornou "The ids query parameter is deprecated in v26.0+" para uma
// das contas ativas; buscar direto em /{ad_id} evita essa dependencia.
// Qualquer falha (token vencido, anuncio apagado, sem permissao) e
// ignorada silenciosamente: o card cai no placeholder "Sem previa".
function am_fetch_ad_creatives(array $adIdsByIntegration, array $integrationsById): array {
    $images = [];
    foreach ($adIdsByIntegration as $integrationId => $adIds) {
        $integration = $integrationsById[$integrationId] ?? null;
        if (!$integration || empty($integration['access_token'])) continue;
        foreach (array_unique($adIds) as $adId) {
            try {
                $resp = meta_api_get('/' . $adId, [
                    'fields' => 'creative{thumbnail_url,image_url}',
                    'access_token' => $integration['access_token'],
                ]);
                $creative = $resp['creative'] ?? [];
                $url = $creative['thumbnail_url'] ?? ($creative['image_url'] ?? null);
                if ($url) $images[(string)$adId] = (string)$url;
            } catch (Throwable $e) { /* segue sem imagem para este anuncio */ }
        }
    }
    return $images;
}

$preset = (string)($_GET['period'] ?? '7');
if (!in_array($preset, ['today', '7', '15', '30', '60', '90', '365', 'month', 'year', 'custom'], true)) $preset = '7';
$period = metrics_period($preset, $_GET['from'] ?? null, $_GET['to'] ?? null);
$endDate = $period['end'];
$compareDays = [
    'x' => max(1, min(400, (int)$period['days'])),
    'y' => max(1, min(400, (int)($_GET['compare_y'] ?? 30))),
    'z' => max(1, min(400, (int)($_GET['compare_z'] ?? 90))),
];
$model = ($_GET['model'] ?? '') === 'first_touch' ? 'first_touch' : 'last_touch';
$adsMetricSource = (string)($_GET['ads_metric_source'] ?? '') === 'meta' ? 'meta' : 'cross';
$windowLegend = $compareDays['x'] . 'd / ' . $compareDays['y'] . 'd / ' . $compareDays['z'] . 'd';

$adsHierarchy = md_ads_hierarchy($pdo, $endDate, $model, $compareDays);
$accounts = md_ads_group_by_account($pdo, $adsHierarchy['tree'], $adsHierarchy['windows'], $endDate);
$globalView = md_ads_metric_view($adsHierarchy['totals']['x'] ?? [], $adsMetricSource);
$crossView = md_ads_metric_view($adsHierarchy['totals']['x'] ?? [], 'cross');
$tv = [];
foreach (['x', 'y', 'z'] as $w) { $tv[$w] = md_ads_metric_view($adsHierarchy['totals'][$w] ?? [], $adsMetricSource); }

$integrations = metrics_active_integrations($pdo);
$integrationsById = [];
foreach ($integrations as $i) { $integrationsById[(int)$i['id']] = $i; }

$periodStartDt = $adsHierarchy['windows']['x'] . ' 00:00:00';
$periodEndDt = $endDate . ' 23:59:59';

// Total geral de vendas no periodo, independente de ter lead/campanha
// casada — complementa o bloco "atribuido" (que so conta o que o
// cruzamento por UTM conseguiu ligar a uma campanha).
$totalSalesRow = md_row($pdo, "SELECT COUNT(*) sales, COALESCE(SUM(s.net_revenue),0) net, COALESCE(SUM(s.producer_net),0) producer
    FROM v_sales_master s WHERE " . md_approved_sql('s') . " AND " . md_sale_revenue_date_sql('s') . " BETWEEN :start AND :end",
    ['start' => $periodStartDt, 'end' => $periodEndDt]);
$totalSales = (int)($totalSalesRow['sales'] ?? 0);
$totalRevenue = (float)($totalSalesRow['producer'] ?? 0);
$totalSpend = (float)($adsHierarchy['totals']['x']['spend'] ?? 0);
$totalLeads = (int)$crossView['leads'];
$totalRoas = $totalSpend > 0 ? $totalRevenue / $totalSpend : 0.0;
$totalCac = $totalSales > 0 ? $totalSpend / $totalSales : 0.0;
$totalCpl = $totalLeads > 0 ? $totalSpend / $totalLeads : 0.0;
$attributedSalesCount = (int)$crossView['sales'];
$attributionRate = $totalSales > 0 ? ($attributedSalesCount / $totalSales) * 100 : 0.0;
// Contagem direta (nao por subtracao) para nao arriscar descasamento entre a
// data de referencia de v_sales_master (total) e attribution_matches (cruzado).
$unattributedRow = md_row($pdo, "SELECT COUNT(*) c FROM v_sales_master s
    JOIN attribution_sales axs ON axs.transaction_code = s.transaction_code
    LEFT JOIN attribution_matches am ON am.sale_id = axs.id AND am.attribution_model = :model
    WHERE " . md_approved_sql('s') . " AND s.sale_date BETWEEN :start AND :end AND am.id IS NULL",
    ['model' => $model, 'start' => $periodStartDt, 'end' => $periodEndDt]);
$unattributed = (int)($unattributedRow['c'] ?? 0);

$palette = ['#facc15', '#38bdf8', '#22c55e', '#f472b6', '#a78bfa', '#f59e0b'];
$accountChart = ['labels' => [], 'spend' => [], 'revenue' => [], 'colors' => []];
foreach ($accounts as $i => $acc) {
    $view = md_ads_metric_view($acc['metrics']['x'] ?? [], 'cross');
    $accountChart['labels'][] = $acc['name'];
    $accountChart['spend'][] = round((float)($acc['metrics']['x']['spend'] ?? 0), 2);
    $accountChart['revenue'][] = round((float)$view['revenue'], 2);
    $accountChart['colors'][] = $palette[$i % count($palette)];
}

$metricCards = [
    ['spend', 'Investimento (' . $compareDays['x'] . 'd)', 'money'],
    ['leads', 'Leads reais', 'num'],
    ['sales', 'Vendas atribuídas', 'num'],
    ['revenue', 'Receita atribuída', 'money'],
    ['roas', 'ROAS', 'decimal'],
    ['cac', 'CAC', 'money'],
    ['cpl', 'CPL', 'money'],
    ['cpm', 'CPM (sempre Meta)', 'money'],
];
$topAdsRaw = am_rank_top_ads(am_collect_ads($adsHierarchy['tree']), $adsMetricSource, 10);
$topAdsRaw = am_resolve_ad_ids($pdo, $topAdsRaw, $adsHierarchy['windows']['x'], $endDate);
$adIdsByIntegration = [];
foreach ($topAdsRaw as $row) {
    if (!empty($row['ad_id']) && !empty($row['integration_id'])) { $adIdsByIntegration[$row['integration_id']][] = $row['ad_id']; }
}
$adCreatives = $adIdsByIntegration ? am_fetch_ad_creatives($adIdsByIntegration, $integrationsById) : [];

$queryParamsBase = $_GET;
unset($queryParamsBase['period'], $queryParamsBase['from'], $queryParamsBase['to'], $queryParamsBase['compare_y'], $queryParamsBase['compare_z'], $queryParamsBase['model'], $queryParamsBase['ads_metric_source']);

include __DIR__ . '/_header.php';
?>
<style>
.am{display:flex;flex-direction:column;gap:16px}.am *{box-sizing:border-box}
.am-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
.am-title h1{font-size:23px;margin:0;color:var(--text)}.am-title p{margin:5px 0 0;color:var(--muted);font-size:12px;max-width:640px}
.am-pills{display:flex;gap:8px;flex-wrap:wrap}
.am-syncpill{display:flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid var(--border);background:var(--bg-card);border-radius:999px;color:var(--muted);font-size:10.5px;white-space:nowrap}
.am-syncpill .dot{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 0 4px var(--success-dim);flex-shrink:0}
.am-syncpill.stale .dot{background:var(--warning);box-shadow:0 0 0 4px var(--warning-dim)}
.am-filter{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px}
.am-periods{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.am-periods a{padding:6px 10px;border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:11px;font-weight:650;text-decoration:none}
.am-periods a:hover,.am-periods a.active{background:var(--primary-dim);border-color:rgba(250,204,21,.3);color:var(--primary)}
.am-filter form{display:grid;grid-template-columns:repeat(6,minmax(110px,1fr));gap:9px;align-items:end}
.am-block-title{font-size:12px;color:var(--muted);margin:0 0 8px;font-weight:650;text-transform:uppercase;letter-spacing:.05em}
.am-filter label{display:block;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}
.am-filter select,.am-filter input{width:100%;height:34px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:0 9px;font-size:11px}
.am-filter .actions{display:flex;gap:7px}
.metric-grid{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px}
.metric{background:linear-gradient(145deg,var(--bg-card),rgba(13,21,38,.75));border:1px solid var(--border);border-radius:var(--r-lg);padding:13px;min-height:78px}
.metric span{display:block;font-size:10px;color:var(--muted);margin-bottom:6px}
.metric strong{font-size:19px;font-weight:780;letter-spacing:-.03em;color:var(--text)}
.chart-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}
.chart-box{height:280px;position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:12px}
.section-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:15px}
.section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:13px}
.section-head h2{font-size:15px;margin:0;color:var(--text)}.section-head p{font-size:10px;color:var(--muted);margin:3px 0 0}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:10px;max-width:100%}
.eff-table{width:100%;border-collapse:collapse}.eff-table th,.eff-table td{padding:10px;border-bottom:1px solid var(--border);font-size:10px;text-align:left}.eff-table th{color:var(--muted);text-transform:uppercase;font-size:9px;background:#101a2e}
.trend{display:inline-flex;align-items:center;padding:2px 6px;border-radius:999px;font-size:9px;font-weight:750;margin-top:3px}
.trend.good{color:#86efac;background:var(--success-dim)}.trend.bad{color:#fca5a5;background:var(--danger-dim)}.trend.neutral{color:var(--muted);background:var(--bg-hover)}
.am-alert{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:var(--warning-dim);border:1px solid rgba(245,158,11,.3);color:#fcd34d;font-size:12px}
.am-alert a{color:#fcd34d;font-weight:700;text-decoration:underline}
.am-account{border-left:4px solid var(--primary)}
.am-acc-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.am-acc-id{display:flex;align-items:center;gap:11px}
.am-avatar{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#111827;flex-shrink:0}
.am-acc-id strong{font-size:15px;color:var(--text);display:block}
.am-acc-id span{font-size:10px;color:var(--muted)}
.am-acc-tools{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.am-search{height:32px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:0 10px;font-size:11px;width:200px}
.am-btn{height:32px;padding:0 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--muted);font-size:10.5px;cursor:pointer}
.am-btn:hover{color:var(--text);border-color:var(--border-light)}
.am-chips{display:grid;grid-template-columns:repeat(7,minmax(90px,1fr));gap:8px;margin-bottom:14px}
.am-chip{background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:9px 10px}
.am-chip small{display:block;font-size:8.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.am-chip strong{font-size:13px;color:var(--text)}
.ads-scroll{overflow:auto;border:1px solid var(--border);border-radius:10px;max-height:600px}
.ads-table{border-collapse:separate;border-spacing:0;min-width:1250px;width:100%}
.ads-table th,.ads-table td{padding:9px 10px;border-bottom:1px solid var(--border);background:var(--bg-card);font-size:10px;white-space:nowrap;text-align:right}
.ads-table th{position:sticky;top:0;z-index:4;background:#101a2e;color:var(--muted);text-transform:uppercase;font-size:9px;cursor:pointer;user-select:none}
.ads-table th:hover{color:var(--text)}
.ads-table th.no-sort{cursor:default}
.ads-table th.no-sort:hover{color:var(--muted)}
.ads-table th:first-child,.ads-table td:first-child{position:sticky;left:0;z-index:3;text-align:left;min-width:300px;max-width:300px;box-shadow:8px 0 12px -12px #000}
.ads-table th:first-child{z-index:5}
.ads-table tr:hover td{background:#142039}.ads-table tr:hover td:first-child{background:#142039}
.ads-name{display:flex;align-items:center;gap:7px;min-width:0}
.ads-toggle{width:19px;height:19px;border:0;background:transparent;color:#60a5fa;cursor:pointer;padding:0;flex-shrink:0}
.ads-indent-1{padding-left:25px}.ads-indent-2{padding-left:50px}
.ads-level{font-size:8px;color:var(--muted);text-transform:uppercase;display:flex;align-items:center;gap:5px}
.ads-sep{color:#475569;margin:0 2px}.ads-values{font-weight:700}
.ads-head-note{font-size:9px;color:var(--muted);margin-top:2px;text-transform:none}
.am-camp-name{display:flex;align-items:center;gap:7px;min-width:0}
.am-camp-title{cursor:pointer}
.am-camp-title:hover strong{color:var(--primary)}
.badge-status{display:inline-flex;padding:2px 6px;border-radius:999px;font-size:8px;font-weight:750;text-transform:uppercase}
.badge-status.is-active{background:var(--success-dim);color:#86efac}
.badge-status.is-paused,.badge-status.is-archived{background:var(--bg-hover);color:var(--muted)}
.dot-sale{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dot-sale.has-sale{background:var(--success);box-shadow:0 0 0 3px var(--success-dim)}
.dot-sale.no-sale{background:var(--dim)}
.empty{padding:28px;text-align:center;color:var(--muted);font-size:11px}
.am-top-ads{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
.am-ad-card{position:relative;background:var(--bg);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;display:flex;flex-direction:column}
.am-ad-rank{position:absolute;top:8px;left:8px;background:var(--primary);color:#111827;font-weight:800;font-size:11px;padding:2px 8px;border-radius:999px;z-index:2}
.am-ad-thumb{width:100%;aspect-ratio:1/1;background:#0a1120;display:flex;align-items:center;justify-content:center}
.am-ad-thumb img{width:100%;height:100%;object-fit:cover}
.am-ad-noimg{color:var(--dim);font-size:10px;text-align:center;padding:10px}
.am-ad-info{padding:10px;display:flex;flex-direction:column;gap:4px}
.am-ad-info strong{font-size:11px;color:var(--text);line-height:1.35;overflow-wrap:anywhere}
.am-ad-ctx{font-size:9px;color:var(--muted);overflow-wrap:anywhere}
.am-ad-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:6px}
.am-ad-metrics div{background:var(--bg-card);border-radius:7px;padding:5px 6px}
.am-ad-metrics small{display:block;font-size:7.5px;color:var(--muted);text-transform:uppercase}
.am-ad-metrics strong{font-size:10.5px;color:var(--text)}
@media(max-width:1100px){.metric-grid{grid-template-columns:repeat(2,1fr)}.chart-grid{grid-template-columns:1fr}.am-filter form{grid-template-columns:repeat(3,1fr)}.am-chips{grid-template-columns:repeat(3,1fr)}}
</style>

<div class="am">
  <div class="am-head">
    <div class="am-title">
      <h1>Gerenciador de Anúncios</h1>
      <p>Árvore conta de anúncio → campanha → conjunto → anúncio. Leads e vendas são o cruzamento real por UTM feito neste sistema; gasto, CPM, CPC e frequência vêm sempre da Meta.</p>
    </div>
    <div class="am-pills">
      <?php if (!$integrations): ?>
        <span class="am-syncpill stale"><span class="dot"></span>Nenhuma conta Meta ativa</span>
      <?php else: foreach ($integrations as $integ):
        $lastSync = $integ['last_success_sync_at'] ?? null;
        $stale = !$lastSync || (time() - strtotime((string)$lastSync)) > 3 * 3600;
      ?>
        <span class="am-syncpill<?= $stale ? ' stale' : '' ?>"><span class="dot"></span><?= am_h($integ['name']) ?> · <?= $lastSync ? am_h(date('d/m H:i', strtotime((string)$lastSync))) : 'sem sync' ?></span>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="am-filter">
    <div class="am-periods">
      <?php foreach (['7' => '7 dias', '15' => '15 dias', '30' => '30 dias', '60' => '60 dias', '90' => '90 dias', '365' => '365 dias', 'month' => 'Mês atual', 'year' => 'Ano atual', 'custom' => 'Personalizado'] as $k => $label): ?>
      <a class="<?= $preset === $k ? 'active' : '' ?>" href="?<?= am_h(http_build_query(array_merge($_GET, ['period' => $k]))) ?>"><?= am_h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get">
      <input type="hidden" name="period" value="<?= am_h($preset) ?>">
      <?php foreach ($queryParamsBase as $k => $v): if (!is_scalar($v)) continue; ?><input type="hidden" name="<?= am_h((string)$k) ?>" value="<?= am_h((string)$v) ?>"><?php endforeach; ?>
      <?php if ($preset === 'custom'): ?>
      <div><label>Data início</label><input type="date" name="from" value="<?= am_h($period['start']) ?>"></div>
      <div><label>Data fim</label><input type="date" name="to" value="<?= am_h($period['end']) ?>"></div>
      <?php endif; ?>
      <div><label>Modelo de atribuição</label><select name="model"><option value="last_touch" <?= $model === 'last_touch' ? 'selected' : '' ?>>Último toque</option><option value="first_touch" <?= $model === 'first_touch' ? 'selected' : '' ?>>Primeiro toque</option></select></div>
      <div><label>Fonte de leads/vendas</label><select name="ads_metric_source"><option value="cross" <?= $adsMetricSource === 'cross' ? 'selected' : '' ?>>Cruzamento real</option><option value="meta" <?= $adsMetricSource === 'meta' ? 'selected' : '' ?>>Reportado pela Meta</option></select></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Aplicar</button></div>
    </form>
    <p style="margin:8px 0 0;font-size:10px;color:var(--muted)">Período selecionado: <strong><?= am_h(date('d/m/Y', strtotime($period['start']))) ?></strong> a <strong><?= am_h(date('d/m/Y', strtotime($period['end']))) ?></strong> (<?= $compareDays['x'] ?> dia(s)).</p>
  </div>

  <?php if ($unattributed > 0): ?>
  <div class="am-alert">
    <span><strong><?= am_num($unattributed) ?></strong> venda(s) aprovada(s) no período ainda sem lead/campanha casada (<?= am_num(100 - $attributionRate, 1) ?>% do total).</span>
    <a href="vendas_analytics.php?model=<?= am_h($model) ?>#nao-atribuidas">Atribuir manualmente →</a>
  </div>
  <?php endif; ?>

  <p class="am-block-title">Atribuído ao cruzamento (venda ↔ lead ↔ campanha)</p>
  <div class="metric-grid">
    <?php foreach ($metricCards as [$key, $label, $fmt]): $val = $globalView[$key] ?? 0; ?>
    <article class="metric"><span><?= am_h($label) ?></span><strong><?= $fmt === 'money' ? am_money($val) : ($fmt === 'decimal' ? am_num($val, 2) : am_num($val)) ?></strong></article>
    <?php endforeach; ?>
  </div>

  <p class="am-block-title">Total geral do período (atribuído + não atribuído)</p>
  <div class="metric-grid">
    <article class="metric"><span>Investimento total</span><strong><?= am_money($totalSpend) ?></strong></article>
    <article class="metric"><span>Leads totais</span><strong><?= am_num($totalLeads) ?></strong></article>
    <article class="metric"><span>Vendas totais</span><strong><?= am_num($totalSales) ?></strong></article>
    <article class="metric"><span>Faturamento líquido total</span><strong><?= am_money($totalRevenue) ?></strong></article>
    <article class="metric"><span>ROAS total</span><strong><?= am_num($totalRoas, 2) ?></strong></article>
    <article class="metric"><span>CAC total</span><strong><?= am_money($totalCac) ?></strong></article>
    <article class="metric"><span>CPL total</span><strong><?= am_money($totalCpl) ?></strong></article>
    <article class="metric"><span>Taxa de atribuição</span><strong><?= am_num($attributionRate, 1) ?>%</strong></article>
  </div>

  <div class="chart-grid">
    <div class="chart-box"><canvas id="amSpendChart"></canvas></div>
    <div class="chart-box"><canvas id="amRevenueChart"></canvas></div>
    <div class="chart-box"><canvas id="amAttrChart"></canvas></div>
  </div>

  <section class="section-card">
    <div class="section-head"><div><h2>Tendências de eficiência</h2><p>Comparação entre as 3 janelas configuradas.</p></div></div>
    <div class="table-wrap"><table class="eff-table"><thead><tr><th>Comparativo</th><th>CAC</th><th>CPL</th><th>ROAS</th><th>CPM</th><th>Frequência</th><th>CPC</th></tr></thead><tbody>
      <?php foreach ([['x', 'y'], ['y', 'z']] as [$a, $b]): ?>
      <tr><td><strong><?= $compareDays[$a] ?>d vs <?= $compareDays[$b] ?>d</strong></td>
        <td><?= am_compare_cell($tv[$a]['cac'], $tv[$b]['cac'], true) ?></td>
        <td><?= am_compare_cell($tv[$a]['cpl'], $tv[$b]['cpl'], true) ?></td>
        <td><?= am_compare_cell($tv[$a]['roas'], $tv[$b]['roas'], false, 'decimal') ?></td>
        <td><?= am_compare_cell($tv[$a]['cpm'], $tv[$b]['cpm'], true) ?></td>
        <td><?= am_compare_cell($tv[$a]['frequency'], $tv[$b]['frequency'], true, 'decimal') ?></td>
        <td><?= am_compare_cell($tv[$a]['cpc'], $tv[$b]['cpc'], true) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="section-card">
    <div class="section-head"><div><h2>Top 10 anúncios</h2><p>Ranqueados por uma combinação de ROAS, CPC e CPL no período (só anúncios com investimento; prévia buscada ao vivo na Meta).</p></div></div>
    <?php if (!$topAdsRaw): ?>
    <div class="empty">Sem anúncios com investimento no período selecionado.</div>
    <?php else: ?>
    <div class="am-top-ads">
      <?php foreach ($topAdsRaw as $rank => $topAd): $tView = $topAd['view']; $img = !empty($topAd['ad_id']) ? ($adCreatives[(string)$topAd['ad_id']] ?? '') : ''; ?>
      <article class="am-ad-card">
        <div class="am-ad-rank">#<?= $rank + 1 ?></div>
        <div class="am-ad-thumb"><?php if ($img): ?><img src="<?= am_h($img) ?>" alt="" loading="lazy"><?php else: ?><div class="am-ad-noimg">Sem prévia</div><?php endif; ?></div>
        <div class="am-ad-info">
          <strong><?= am_h($topAd['ad']) ?></strong>
          <span class="am-ad-ctx"><?= am_h($topAd['campaign']) ?> · <?= am_h($topAd['adset']) ?></span>
          <div class="am-ad-metrics">
            <div><small>ROAS</small><strong><?= am_num($tView['roas'], 2) ?></strong></div>
            <div><small>CPC</small><strong><?= am_money($tView['cpc']) ?></strong></div>
            <div><small>CPL</small><strong><?= am_money($tView['cpl']) ?></strong></div>
            <div><small>Gasto</small><strong><?= am_money($tView['spend']) ?></strong></div>
            <div><small>Vendas</small><strong><?= am_num($tView['sales']) ?></strong></div>
            <div><small>Leads</small><strong><?= am_num($tView['leads']) ?></strong></div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <?php foreach ($accounts as $accIndex => $account):
    $accColor = $account['integration_id'] ? $palette[$accIndex % count($palette)] : '#334155';
    $accView = md_ads_metric_view($account['metrics']['x'] ?? [], $adsMetricSource);
    $statusMap = $account['integration_id'] ? md_campaign_status_map($pdo, (int)$account['integration_id']) : [];
    $accTableId = 'acc' . $accIndex;
    $lastSync = $account['last_success_sync_at'] ?? null;
  ?>
  <section class="section-card am-account" style="border-left-color:<?= am_h($accColor) ?>" data-account-block="<?= $accTableId ?>">
    <div class="am-acc-head">
      <div class="am-acc-id">
        <div class="am-avatar" style="background:<?= am_h($accColor) ?>"><?= am_h(am_initials($account['name'])) ?></div>
        <div>
          <strong><?= am_h($account['name']) ?></strong>
          <span><?= $account['ad_account_id'] ? am_h($account['ad_account_id']) . ' · ' : '' ?><?= count($account['tree']) ?> campanha(s) no período<?= $lastSync ? ' · sync ' . am_h(date('d/m H:i', strtotime((string)$lastSync))) : '' ?></span>
        </div>
      </div>
      <div class="am-acc-tools">
        <input type="search" class="am-search" placeholder="Buscar campanha, conjunto ou anúncio…" data-search-for="<?= $accTableId ?>">
        <button type="button" class="am-btn" data-expand-all="<?= $accTableId ?>">Expandir tudo</button>
        <button type="button" class="am-btn" data-collapse-all="<?= $accTableId ?>">Recolher tudo</button>
      </div>
    </div>

    <div class="am-chips">
      <div class="am-chip"><small>Gasto</small><strong><?= am_money($account['metrics']['x']['spend'] ?? 0) ?></strong></div>
      <div class="am-chip"><small>Leads reais</small><strong><?= am_num($accView['leads']) ?></strong></div>
      <div class="am-chip"><small>Vendas atribuídas</small><strong><?= am_num($accView['sales']) ?></strong></div>
      <div class="am-chip"><small>Receita atribuída</small><strong><?= am_money($accView['revenue']) ?></strong></div>
      <div class="am-chip"><small>ROAS</small><strong><?= am_num($accView['roas'], 2) ?></strong></div>
      <div class="am-chip"><small>CPM</small><strong><?= am_money($accView['cpm']) ?></strong></div>
      <div class="am-chip"><small>Frequência</small><strong><?= am_num($accView['frequency'], 2) ?></strong></div>
    </div>

    <?php if (!$account['tree']): ?>
      <div class="empty">Sem campanhas com dados nessa janela.</div>
    <?php else: ?>
    <div class="ads-scroll">
      <table class="ads-table" data-account-table="<?= $accTableId ?>">
        <thead><tr>
          <th class="no-sort">Campanha / conjunto / anúncio<div class="ads-head-note">Clique no nome para abrir no dashboard geral</div></th>
          <?php foreach ([['spend', 'Gasto'], ['leads', 'Leads'], ['cpl', 'CPL'], ['cpc', 'CPC'], ['sales', 'Vendas'], ['cac', 'CAC'], ['roas', 'ROAS'], ['cpm', 'CPM'], ['frequency', 'Frequência']] as [$sortKey, $head]): ?>
          <th data-sort-key="<?= $sortKey ?>">↕ <?= $head ?><div class="ads-head-note"><?= am_h($windowLegend) ?></div></th>
          <?php endforeach; ?>
        </tr></thead>
        <?php foreach ($account['tree'] as $ci => $campaign):
          $cid = $accTableId . '-c' . substr(md5((string)$ci), 0, 10);
          $cView = md_ads_metric_view($campaign['metrics']['x'] ?? [], $adsMetricSource);
          $statusKey = normalize_match_key((string)$campaign['name']);
          $status = $statusMap[$statusKey] ?? '';
          $statusClass = $status !== '' ? 'is-' . strtolower(preg_replace('/[^a-z]+/i', '', $status)) : '';
        ?>
        <tbody class="camp-block" data-spend="<?= (float)($campaign['metrics']['x']['spend'] ?? 0) ?>" data-leads="<?= (int)$cView['leads'] ?>" data-sales="<?= (int)$cView['sales'] ?>" data-cpl="<?= (float)$cView['cpl'] ?>" data-cpc="<?= (float)$cView['cpc'] ?>" data-cac="<?= (float)$cView['cac'] ?>" data-roas="<?= (float)$cView['roas'] ?>" data-cpm="<?= (float)$cView['cpm'] ?>" data-frequency="<?= (float)$cView['frequency'] ?>" data-search-blob="<?= am_h(mb_strtolower((string)$campaign['name'], 'UTF-8')) ?>">
          <tr data-row-id="<?= $cid ?>">
            <td><div class="ads-name"><button type="button" class="ads-toggle" data-target="<?= $cid ?>" aria-expanded="false">▶</button>
              <span class="dot-sale <?= $cView['sales'] > 0 ? 'has-sale' : 'no-sale' ?>" title="<?= $cView['sales'] > 0 ? 'Teve venda no período' : 'Sem venda no período' ?>"></span>
              <div class="am-camp-title" onclick="location.href='vendas_analytics.php?campaign=<?= urlencode((string)$campaign['name']) ?>&model=<?= am_h($model) ?>'">
                <strong><?= am_h($campaign['name']) ?></strong>
                <div class="ads-level"><?php if ($statusClass): ?><span class="badge-status <?= am_h($statusClass) ?>"><?= am_h($status) ?></span><?php endif; ?><?= count($campaign['adsets']) ?> conjunto(s)</div>
              </div>
            </div></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'spend', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'leads') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'cpl', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'cpc', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'sales') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'cac', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'roas', 'decimal') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'cpm', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($campaign['metrics'], $compareDays, $adsMetricSource, 'frequency', 'decimal') ?></td>
          </tr>
          <?php foreach ($campaign['adsets'] as $ai => $adset): $aid = $cid . '-a' . substr(md5((string)$ai), 0, 8); $aView = md_ads_metric_view($adset['metrics']['x'] ?? [], $adsMetricSource); ?>
          <tr data-row-id="<?= $aid ?>" data-parent="<?= $cid ?>" hidden data-search-blob="<?= am_h(mb_strtolower((string)$campaign['name'] . ' ' . (string)$adset['name'], 'UTF-8')) ?>">
            <td><div class="ads-name ads-indent-1"><button type="button" class="ads-toggle" data-target="<?= $aid ?>" aria-expanded="false">▶</button>
              <span class="dot-sale <?= $aView['sales'] > 0 ? 'has-sale' : 'no-sale' ?>"></span>
              <div class="am-camp-title" onclick="location.href='vendas_analytics.php?campaign=<?= urlencode((string)$campaign['name']) ?>&adset=<?= urlencode((string)$adset['name']) ?>&model=<?= am_h($model) ?>'">
                <strong><?= am_h($adset['name']) ?></strong><div class="ads-level"><?= count($adset['ads']) ?> anúncio(s)</div>
              </div>
            </div></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'spend', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'leads') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'cpl', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'cpc', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'sales') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'cac', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'roas', 'decimal') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'cpm', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($adset['metrics'], $compareDays, $adsMetricSource, 'frequency', 'decimal') ?></td>
          </tr>
          <?php foreach ($adset['ads'] as $ad): $adView = md_ads_metric_view($ad['metrics']['x'] ?? [], $adsMetricSource); ?>
          <tr data-parent="<?= $aid ?>" hidden data-search-blob="<?= am_h(mb_strtolower((string)$campaign['name'] . ' ' . (string)$adset['name'] . ' ' . (string)$ad['name'], 'UTF-8')) ?>">
            <td><div class="ads-name ads-indent-2"><span class="dot-sale <?= $adView['sales'] > 0 ? 'has-sale' : 'no-sale' ?>"></span><div><strong><?= am_h($ad['name']) ?></strong><div class="ads-level">Anúncio</div></div></div></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'spend', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'leads') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'cpl', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'cpc', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'sales') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'cac', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'roas', 'decimal') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'cpm', 'money') ?></td>
            <td class="ads-values"><?= am_ads_cell($ad['metrics'], $compareDays, $adsMetricSource, 'frequency', 'decimal') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <?php if (!$accounts): ?>
  <section class="section-card"><div class="empty">Nenhuma conta de anúncio ativa configurada em Integrações.</div></section>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<script>
document.querySelectorAll('.ads-toggle').forEach(btn=>btn.addEventListener('click',()=>{
  const id=btn.dataset.target,opening=btn.getAttribute('aria-expanded')!=='true';btn.setAttribute('aria-expanded',opening?'true':'false');btn.textContent=opening?'▼':'▶';
  document.querySelectorAll(`[data-parent="${id}"]`).forEach(row=>{row.hidden=!opening;if(!opening){const child=row.dataset.rowId;if(child){const childBtn=row.querySelector('.ads-toggle');if(childBtn){childBtn.setAttribute('aria-expanded','false');childBtn.textContent='▶';}document.querySelectorAll(`[data-parent="${child}"]`).forEach(r=>r.hidden=true);}}});
}));

document.querySelectorAll('[data-expand-all]').forEach(btn=>btn.addEventListener('click',()=>{
  const table=document.querySelector(`[data-account-table="${btn.dataset.expandAll}"]`);if(!table)return;
  table.querySelectorAll('.ads-toggle').forEach(t=>{t.setAttribute('aria-expanded','true');t.textContent='▼';});
  table.querySelectorAll('[data-parent]').forEach(r=>r.hidden=false);
}));
document.querySelectorAll('[data-collapse-all]').forEach(btn=>btn.addEventListener('click',()=>{
  const table=document.querySelector(`[data-account-table="${btn.dataset.collapseAll}"]`);if(!table)return;
  table.querySelectorAll('.ads-toggle').forEach(t=>{t.setAttribute('aria-expanded','false');t.textContent='▶';});
  table.querySelectorAll('[data-parent]').forEach(r=>r.hidden=true);
}));

document.querySelectorAll('.am-search').forEach(input=>{
  input.addEventListener('input',()=>{
    const table=document.querySelector(`[data-account-table="${input.dataset.searchFor}"]`);if(!table)return;
    const term=input.value.trim().toLowerCase();
    if(term===''){
      table.querySelectorAll('tbody.camp-block').forEach(b=>b.style.display='');
      table.querySelectorAll('tr[data-parent]').forEach(r=>r.hidden=true);
      table.querySelectorAll('.ads-toggle').forEach(t=>{t.setAttribute('aria-expanded','false');t.textContent='▶';});
      return;
    }
    table.querySelectorAll('tbody.camp-block').forEach(block=>{
      let blockMatch=false;
      block.querySelectorAll('tr[data-parent]').forEach(r=>r.hidden=true);
      block.querySelectorAll('.ads-toggle').forEach(t=>{t.setAttribute('aria-expanded','false');t.textContent='▶';});
      block.querySelectorAll('tr').forEach(row=>{
        const blob=row.dataset.searchBlob||'';
        if(!blob.includes(term))return;
        blockMatch=true;row.hidden=false;
        let parentId=row.dataset.parent;
        while(parentId){
          const parentRow=block.querySelector(`[data-row-id="${parentId}"]`);
          if(!parentRow)break;
          parentRow.hidden=false;
          const btn=block.querySelector(`.ads-toggle[data-target="${parentId}"]`);
          if(btn){btn.setAttribute('aria-expanded','true');btn.textContent='▼';}
          parentId=parentRow.dataset.parent;
        }
      });
      block.style.display=blockMatch?'':'none';
    });
  });
});

document.querySelectorAll('.ads-table th[data-sort-key]').forEach(th=>{
  th.addEventListener('click',()=>{
    const table=th.closest('table');const key=th.dataset.sortKey;
    const dir=th.dataset.dir==='asc'?'desc':'asc';
    table.querySelectorAll('th[data-sort-key]').forEach(h=>delete h.dataset.dir);
    th.dataset.dir=dir;
    const blocks=Array.from(table.querySelectorAll('tbody.camp-block'));
    blocks.sort((a,b)=>{const av=parseFloat(a.dataset[key]||'0'),bv=parseFloat(b.dataset[key]||'0');return dir==='asc'?av-bv:bv-av;});
    blocks.forEach(b=>table.appendChild(b));
  });
});

function amMoneyLabel(v){if(!v)return '';return 'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:0});}
function amDoughnutOptions(title,formatter){
  return {responsive:true,maintainAspectRatio:false,cutout:'58%',plugins:{
    legend:{position:'bottom',labels:{color:'#94a3b8',boxWidth:10,font:{size:10}}},
    title:{display:true,text:title,color:'#e2e8f0',font:{size:11}},
    datalabels:{color:'#0b1220',backgroundColor:'rgba(255,255,255,.85)',borderRadius:4,padding:{top:2,bottom:2,left:5,right:5},font:{size:9,weight:'700'},formatter:formatter}
  }};
}
<?php if ($accounts): ?>
const amColors=<?= json_encode($accountChart['colors'], JSON_UNESCAPED_UNICODE) ?>;
const amLabels=<?= json_encode($accountChart['labels'], JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('amSpendChart'),{type:'doughnut',plugins:[ChartDataLabels],data:{labels:amLabels,datasets:[{data:<?= json_encode($accountChart['spend']) ?>,backgroundColor:amColors,borderWidth:0}]},options:amDoughnutOptions('Investimento por conta',amMoneyLabel)});
new Chart(document.getElementById('amRevenueChart'),{type:'doughnut',plugins:[ChartDataLabels],data:{labels:amLabels,datasets:[{data:<?= json_encode($accountChart['revenue']) ?>,backgroundColor:amColors,borderWidth:0}]},options:amDoughnutOptions('Faturamento líquido por conta',amMoneyLabel)});
<?php endif; ?>
new Chart(document.getElementById('amAttrChart'),{type:'doughnut',plugins:[ChartDataLabels],data:{labels:['Atribuídas','Não atribuídas'],datasets:[{data:[<?= (int)$attributedSalesCount ?>,<?= (int)$unattributed ?>],backgroundColor:['#22c55e','#334155'],borderWidth:0}]},options:amDoughnutOptions('Vendas atribuídas x não atribuídas',(v)=>v>0?v:'')});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
