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
    $windowsHtml = '<span class="am-val-windows">' . implode(' <span class="ads-sep">/</span> ', $parts) . '</span>';

    $filterView = md_ads_metric_view($metrics['filter'] ?? ($metrics['x'] ?? []), $source);
    $filterVal = $filterView[$key] ?? 0;
    $filterFormatted = $format === 'money' ? am_money($filterVal) : ($format === 'decimal' ? am_num($filterVal, 2) : am_num($filterVal));
    $filterHtml = '<span class="am-val-filter" style="display:none">' . $filterFormatted . '</span>';

    return $windowsHtml . $filterHtml;
}
function am_kpi_td(array $metrics, array $days, string $source, string $key, string $label, string $format, string $level): string {
    return '<td class="ads-values" data-kpi-cell data-level="' . am_h($level) . '" data-kpi="' . am_h($key) . '" data-format="' . am_h($format) . '" data-kpi-label="' . am_h($label) . '">' . am_ads_cell($metrics, $days, $source, $key, $format) . '</td>';
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
// Normalizacao min-max classica: devolve 0..1 de acordo com a posicao do
// valor entre o pior e o melhor do proprio grupo. $lowerIsBetter inverte a
// escala (CPC baixo e melhor; ROAS e CTR altos sao melhores).
function am_normalize_minmax(float $value, float $min, float $max, bool $lowerIsBetter): float {
    if ($max <= $min) return 1.0;
    $ratio = max(0.0, min(1.0, ($value - $min) / ($max - $min)));
    return $lowerIsBetter ? 1.0 - $ratio : $ratio;
}
// Ranqueia por uma pontuacao composta de ROAS (quanto maior, melhor), CPC
// (quanto menor, melhor) e CTR (quanto maior, melhor) — os 3 criterios que
// definem um anuncio eficiente. Cada metrica e normalizada min-max dentro do
// proprio conjunto de anuncios com investimento no periodo (nao contra um
// valor fixo), entao a pontuacao reflete a posicao relativa real do anuncio
// naquele periodo. A pontuacao 0..1 vira uma nota de 1 a 10 para exibicao.
// Os botoes de ordenacao no card reorganizam a sequencia por qualquer KPI
// isolado (sem recalcular a nota, que continua sendo o score composto fixo).
function am_rank_top_ads(array $ads, string $source, int $limit = 14): array {
    $rows = [];
    foreach ($ads as $ad) {
        $view = md_ads_metric_view($ad['metrics'], $source);
        if ($view['spend'] <= 0) continue;
        $ad['view'] = $view;
        $rows[] = $ad;
    }
    if (!$rows) return [];
    $roasVals = array_map(static fn($r) => $r['view']['roas'], $rows);
    $cpcVals = array_map(static fn($r) => $r['view']['cpc'], $rows);
    $ctrVals = array_map(static fn($r) => $r['view']['ctr'], $rows);
    $roasMin = min($roasVals); $roasMax = max($roasVals);
    $cpcMin = min($cpcVals); $cpcMax = max($cpcVals);
    $ctrMin = min($ctrVals); $ctrMax = max($ctrVals);
    foreach ($rows as &$r) {
        $roasScore = am_normalize_minmax($r['view']['roas'], $roasMin, $roasMax, false);
        $cpcScore = am_normalize_minmax($r['view']['cpc'], $cpcMin, $cpcMax, true);
        $ctrScore = am_normalize_minmax($r['view']['ctr'], $ctrMin, $ctrMax, false);
        $r['score'] = ($roasScore + $cpcScore + $ctrScore) / 3;
        $r['nota'] = max(1, min(10, (int)round(1 + $r['score'] * 9)));
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
// nenhuma tabela nossa), uma chamada por anuncio do Top 14. Testado com o
// endpoint em lote (?ids=a,b,c) primeiro, mas a Meta retornou "The ids query
// parameter is deprecated in v26.0+" para uma das contas ativas; buscar
// direto em /{ad_id} evita essa dependencia. Qualquer falha (token vencido,
// anuncio apagado, sem permissao) e ignorada silenciosamente: o card cai no
// placeholder "Sem previa".
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

// ---------------------------------------------------------------------
// Historico diario/semanal/mensal de um KPI, para o popup que abre ao
// clicar em cima de um valor nas tabelas de campanha/conjunto/conta. Cada
// nivel filtra numa tabela diferente e, para leads/vendas (dados nossos via
// UTM), sempre compara pela coluna NORMALIZADA — mesma boa pratica de
// normalizar-antes-de-comparar usada no motor de atribuicao, sem isso
// variacoes de encoding da mesma campanha/conjunto/anuncio ficariam de fora.
// ---------------------------------------------------------------------
function am_period_expr(string $col, string $granularity): string {
    if ($granularity === 'week') return "DATE(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY))";
    if ($granularity === 'month') return "DATE(DATE_FORMAT($col, '%Y-%m-01'))";
    return "DATE($col)";
}
function am_week_start(DateTimeImmutable $d): DateTimeImmutable {
    $iso = (int)$d->format('N');
    return $d->modify('-' . ($iso - 1) . ' days');
}
function am_period_buckets(string $start, string $end, string $granularity): array {
    $out = [];
    $last = new DateTimeImmutable($end);
    if ($granularity === 'week') {
        $cursor = am_week_start(new DateTimeImmutable($start));
        while ($cursor <= $last) { $out[] = $cursor->format('Y-m-d'); $cursor = $cursor->modify('+7 days'); }
    } elseif ($granularity === 'month') {
        $cursor = (new DateTimeImmutable($start))->modify('first day of this month');
        while ($cursor <= $last) { $out[] = $cursor->format('Y-m-d'); $cursor = $cursor->modify('first day of next month'); }
    } else {
        $cursor = new DateTimeImmutable($start);
        while ($cursor <= $last) { $out[] = $cursor->format('Y-m-d'); $cursor = $cursor->modify('+1 day'); }
    }
    return $out;
}
// Para o nivel "conta", devolve os nomes de campanha (bruto, como aparece em
// meta_campaign_daily/attribution_matches) que pertencem aquela conta —
// mesma classificacao que md_ads_group_by_account ja faz, recalculada aqui
// de forma independente (sem depender da arvore ja carregada) porque este
// endpoint pode ser chamado isoladamente.
function am_account_campaign_names(PDO $pdo, ?int $integrationId): array {
    $lookup = build_meta_name_lookup($pdo, 0);
    if ($integrationId) {
        $names = [];
        foreach ($lookup['campaign_accounts'] as $norm => $accId) {
            if ((int)$accId === $integrationId) $names[] = $lookup['campaigns'][$norm] ?? '';
        }
        return array_values(array_filter($names));
    }
    $all = md_rows($pdo, "SELECT DISTINCT campaign_group_norm, campaign_group FROM attribution_matches WHERE campaign_group_norm<>''");
    $names = [];
    foreach ($all as $row) {
        if (!isset($lookup['campaigns'][(string)$row['campaign_group_norm']])) $names[] = (string)$row['campaign_group'];
    }
    return $names;
}
function am_build_kpi_history(PDO $pdo, string $level, array $scope, string $model, string $source, string $start, string $end, string $granularity): array {
    $granularity = in_array($granularity, ['day', 'week', 'month'], true) ? $granularity : 'day';
    $metaTable = 'meta_account_daily';
    $metaWhere = 'report_date BETWEEN :start AND :end';
    $metaParams = ['start' => $start, 'end' => $end];
    $leadWhere = 'created_at BETWEEN :start AND :end';
    $leadParams = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59'];
    $saleWhere = "m.attribution_model=:model AND m.sale_date BETWEEN :start AND :end AND vsm.status='APPROVED'";
    $saleParams = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59', 'model' => $model];
    $skipLeads = false;

    if ($level === 'ad') {
        $cNorm = normalize_match_key((string)($scope['campaign'] ?? '')); $aNorm = normalize_match_key((string)($scope['adset'] ?? '')); $dNorm = normalize_match_key((string)($scope['ad'] ?? ''));
        $metaTable = 'meta_ad_daily'; $metaWhere .= ' AND campaign_name=:c AND adset_name=:a AND ad_name=:d';
        $metaParams += ['c' => $scope['campaign'], 'a' => $scope['adset'], 'd' => $scope['ad']];
        $leadWhere .= ' AND utm_campaign_group_norm=:c AND utm_campaign_name_norm=:a AND utm_ad_name_norm=:d';
        $leadParams += ['c' => $cNorm, 'a' => $aNorm, 'd' => $dNorm];
        $saleWhere .= ' AND m.campaign_group_norm=:c AND m.campaign_name_norm=:a AND m.ad_name_norm=:d';
        $saleParams += ['c' => $cNorm, 'a' => $aNorm, 'd' => $dNorm];
    } elseif ($level === 'adset') {
        $cNorm = normalize_match_key((string)($scope['campaign'] ?? '')); $aNorm = normalize_match_key((string)($scope['adset'] ?? ''));
        $metaTable = 'meta_adset_daily'; $metaWhere .= ' AND campaign_name=:c AND adset_name=:a';
        $metaParams += ['c' => $scope['campaign'], 'a' => $scope['adset']];
        $leadWhere .= ' AND utm_campaign_group_norm=:c AND utm_campaign_name_norm=:a';
        $leadParams += ['c' => $cNorm, 'a' => $aNorm];
        $saleWhere .= ' AND m.campaign_group_norm=:c AND m.campaign_name_norm=:a';
        $saleParams += ['c' => $cNorm, 'a' => $aNorm];
    } elseif ($level === 'campaign') {
        $cNorm = normalize_match_key((string)($scope['campaign'] ?? ''));
        $metaTable = 'meta_campaign_daily'; $metaWhere .= ' AND campaign_name=:c';
        $metaParams += ['c' => $scope['campaign']];
        $leadWhere .= ' AND utm_campaign_group_norm=:c';
        $leadParams += ['c' => $cNorm];
        $saleWhere .= ' AND m.campaign_group_norm=:c';
        $saleParams += ['c' => $cNorm];
    } elseif ($level === 'account') {
        $integrationId = !empty($scope['integration_id']) ? (int)$scope['integration_id'] : null;
        $campaignNames = am_account_campaign_names($pdo, $integrationId);
        if ($integrationId) {
            $metaTable = 'meta_account_daily'; $metaWhere .= ' AND integration_id=:id'; $metaParams['id'] = $integrationId;
            $saleWhere .= ' AND m.integration_id=:id'; $saleParams['id'] = $integrationId;
        } else {
            $saleWhere .= ' AND m.integration_id IS NULL';
            if ($campaignNames) {
                $in = []; $i = 0; foreach ($campaignNames as $n) { $k = 'mc' . $i++; $in[] = ':' . $k; $metaParams[$k] = $n; }
                $metaTable = 'meta_campaign_daily'; $metaWhere .= ' AND campaign_name IN (' . implode(',', $in) . ')';
            } else { $metaWhere .= ' AND 1=0'; }
        }
        if ($campaignNames) {
            $in = []; $i = 0; foreach ($campaignNames as $n) { $k = 'cn' . $i++; $in[] = ':' . $k; $leadParams[$k] = normalize_match_key($n); }
            $leadWhere .= ' AND utm_campaign_group_norm IN (' . implode(',', $in) . ')';
            $in2 = []; $i = 0; foreach ($campaignNames as $n) { $k = 'sn' . $i++; $in2[] = ':' . $k; $saleParams[$k] = normalize_match_key($n); }
            $saleWhere .= ' AND m.campaign_group_norm IN (' . implode(',', $in2) . ')';
        } else { $skipLeads = true; $saleWhere .= ' AND 1=0'; }
    }

    $metaExpr = am_period_expr('report_date', $granularity);
    $metaRows = md_rows($pdo, "SELECT {$metaExpr} period, SUM(spend) spend, SUM(impressions) impressions, SUM(reach) reach, SUM(clicks) clicks, SUM(leads) meta_leads, SUM(purchases) meta_sales, SUM(purchase_value) meta_revenue FROM {$metaTable} WHERE {$metaWhere} GROUP BY period", $metaParams);
    $metaMap = [];
    foreach ($metaRows as $r) $metaMap[(string)$r['period']] = $r;

    $leadMap = []; $saleMap = [];
    if ($source !== 'meta') {
        if (!$skipLeads) {
            $leadExpr = am_period_expr('created_at', $granularity);
            foreach (md_rows($pdo, "SELECT {$leadExpr} period, COUNT(*) qty FROM attribution_leads WHERE {$leadWhere} GROUP BY period", $leadParams) as $r) $leadMap[(string)$r['period']] = (int)$r['qty'];
        }
        $saleExpr = am_period_expr('m.sale_date', $granularity);
        $saleRows = md_rows($pdo, "SELECT {$saleExpr} period, COUNT(*) qty, SUM(m.revenue_value) revenue
            FROM attribution_matches m JOIN attribution_sales axs ON axs.id=m.sale_id JOIN v_sales_master vsm ON vsm.transaction_code=axs.transaction_code
            WHERE {$saleWhere} GROUP BY period", $saleParams);
        foreach ($saleRows as $r) $saleMap[(string)$r['period']] = $r;
    }

    $out = [];
    foreach (am_period_buckets($start, $end, $granularity) as $p) {
        $mRow = $metaMap[$p] ?? [];
        $spend = (float)($mRow['spend'] ?? 0); $impressions = (int)($mRow['impressions'] ?? 0); $reach = (int)($mRow['reach'] ?? 0); $clicks = (int)($mRow['clicks'] ?? 0);
        if ($source === 'meta') { $leads = (int)($mRow['meta_leads'] ?? 0); $sales = (int)($mRow['meta_sales'] ?? 0); $revenue = (float)($mRow['meta_revenue'] ?? 0); }
        else { $leads = (int)($leadMap[$p] ?? 0); $sales = (int)($saleMap[$p]['qty'] ?? 0); $revenue = (float)($saleMap[$p]['revenue'] ?? 0); }
        $out[] = [
            'period' => $p, 'spend' => round($spend, 2), 'leads' => $leads, 'sales' => $sales, 'revenue' => round($revenue, 2),
            'roas' => $spend > 0 ? round($revenue / $spend, 4) : 0, 'cac' => $sales > 0 ? round($spend / $sales, 2) : 0,
            'cpl' => $leads > 0 ? round($spend / $leads, 2) : 0, 'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : 0,
            'cpm' => $impressions > 0 ? round($spend / $impressions * 1000, 2) : 0, 'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0,
            'frequency' => $reach > 0 ? round($impressions / $reach, 2) : 0,
        ];
    }
    return $out;
}

$preset = (string)($_GET['period'] ?? '7');
if (!in_array($preset, ['today', '7', '15', '30', '60', '90', '365', 'month', 'year', 'custom'], true)) $preset = '7';
$period = metrics_period($preset, $_GET['from'] ?? null, $_GET['to'] ?? null);
$endDate = $period['end'];
$compareDays = [
    'x' => max(1, min(400, (int)($_GET['compare_x'] ?? 7))),
    'y' => max(1, min(400, (int)($_GET['compare_y'] ?? 30))),
    'z' => max(1, min(400, (int)($_GET['compare_z'] ?? 90))),
    'filter' => max(1, min(400, (int)$period['days'])),
];
$model = ($_GET['model'] ?? '') === 'first_touch' ? 'first_touch' : 'last_touch';
$adsMetricSource = (string)($_GET['ads_metric_source'] ?? '') === 'meta' ? 'meta' : 'cross';
$windowLegend = $compareDays['x'] . 'd / ' . $compareDays['y'] . 'd / ' . $compareDays['z'] . 'd';

$integrations = metrics_active_integrations($pdo);
$integrationsById = [];
foreach ($integrations as $i) { $integrationsById[(int)$i['id']] = $i; }
$palette = ['#facc15', '#38bdf8', '#22c55e', '#f472b6', '#a78bfa', '#f59e0b'];

$adsColumns = [
    ['spend', 'Gasto', 'money'], ['leads', 'Leads', 'num'], ['cpl', 'CPL', 'money'], ['cpc', 'CPC', 'money'],
    ['sales', 'Vendas', 'num'], ['cac', 'CAC', 'money'], ['roas', 'ROAS', 'decimal'], ['cpm', 'CPM', 'money'], ['frequency', 'Frequência', 'decimal'],
];
$chipColumns = [
    ['spend', 'Gasto', 'money'], ['leads', 'Leads reais', 'num'], ['sales', 'Vendas atribuídas', 'num'], ['revenue', 'Receita atribuída', 'money'],
    ['roas', 'ROAS', 'decimal'], ['cpm', 'CPM', 'money'], ['frequency', 'Frequência', 'decimal'],
];

$ajaxParams = ['period' => $preset, 'model' => $model, 'ads_metric_source' => $adsMetricSource, 'compare_y' => $compareDays['y'], 'compare_z' => $compareDays['z']];
if ($preset === 'custom') { $ajaxParams['from'] = $period['start']; $ajaxParams['to'] = $period['end']; }
$ajaxQueryBase = http_build_query($ajaxParams);

// ---------------------------------------------------------------------
// AJAX: historico de um KPI (JSON), usado pelo popup. Nao renderiza nada
// da pagina — so roda quando o usuario efetivamente clica num valor.
// ---------------------------------------------------------------------
if (isset($_GET['ajax_kpi_history'])) {
    header('Content-Type: application/json; charset=utf-8');
    $level = in_array(($_GET['level'] ?? ''), ['ad', 'adset', 'campaign', 'account', 'global'], true) ? (string)$_GET['level'] : 'global';
    $scope = [
        'campaign' => trim((string)($_GET['campaign'] ?? '')),
        'adset' => trim((string)($_GET['adset'] ?? '')),
        'ad' => trim((string)($_GET['ad'] ?? '')),
        'integration_id' => ctype_digit((string)($_GET['account_key'] ?? '')) ? (int)$_GET['account_key'] : null,
    ];
    $range = max(1, min(730, (int)($_GET['range'] ?? 30)));
    $end = (string)($_GET['end'] ?? $endDate);
    try { $endDt = new DateTimeImmutable($end); } catch (Throwable $e) { $endDt = new DateTimeImmutable($endDate); }
    $start = $endDt->modify('-' . ($range - 1) . ' days')->format('Y-m-d');
    $granularity = (string)($_GET['granularity'] ?? 'day');
    $historyModel = ((string)($_GET['model'] ?? $model)) === 'first_touch' ? 'first_touch' : 'last_touch';
    $source = ((string)($_GET['source'] ?? $adsMetricSource)) === 'meta' ? 'meta' : 'cross';
    echo json_encode(am_build_kpi_history($pdo, $level, $scope, $historyModel, $source, $start, $endDt->format('Y-m-d'), $granularity), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// AJAX: fragmento HTML de uma secao. So roda a consulta pesada
// (md_ads_hierarchy) quando a pessoa efetivamente expande uma secao —
// a pagina inicial carrega so os cabecalhos recuados, nada de banco alem
// da lista (barata) de integracoes ativas.
// ---------------------------------------------------------------------
$ajaxSection = (string)($_GET['ajax_section'] ?? '');
if ($ajaxSection !== '') {
    header('Content-Type: text/html; charset=utf-8');
    $adsHierarchy = md_ads_hierarchy($pdo, $endDate, $model, $compareDays);
    $accounts = md_ads_group_by_account($pdo, $adsHierarchy['tree'], $adsHierarchy['windows'], $endDate);
    $globalView = md_ads_metric_view($adsHierarchy['totals']['x'] ?? [], $adsMetricSource);
    $crossView = md_ads_metric_view($adsHierarchy['totals']['x'] ?? [], 'cross');
    $tv = [];
    foreach (['x', 'y', 'z'] as $w) { $tv[$w] = md_ads_metric_view($adsHierarchy['totals'][$w] ?? [], $adsMetricSource); }

    $periodStartDt = $adsHierarchy['windows']['x'] . ' 00:00:00';
    $periodEndDt = $endDate . ' 23:59:59';
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
    $unattributedRow = md_row($pdo, "SELECT COUNT(*) c FROM v_sales_master s
        JOIN attribution_sales axs ON axs.transaction_code = s.transaction_code
        LEFT JOIN attribution_matches am ON am.sale_id = axs.id AND am.attribution_model = :model
        WHERE " . md_approved_sql('s') . " AND s.sale_date BETWEEN :start AND :end AND am.id IS NULL",
        ['model' => $model, 'start' => $periodStartDt, 'end' => $periodEndDt]);
    $unattributed = (int)($unattributedRow['c'] ?? 0);

    if ($ajaxSection === 'kpis_attributed') {
        $metricCards = [
            ['spend', 'Investimento (' . $compareDays['x'] . 'd)', 'money'], ['leads', 'Leads reais', 'num'], ['sales', 'Vendas atribuídas', 'num'],
            ['revenue', 'Receita atribuída', 'money'], ['roas', 'ROAS', 'decimal'], ['cac', 'CAC', 'money'], ['cpl', 'CPL', 'money'], ['cpm', 'CPM (sempre Meta)', 'money'],
        ]; ?>
        <div class="metric-grid">
          <?php foreach ($metricCards as [$key, $label, $fmt]): $val = $globalView[$key] ?? 0; ?>
          <article class="metric"><span><?= am_h($label) ?></span><strong><?= $fmt === 'money' ? am_money($val) : ($fmt === 'decimal' ? am_num($val, 2) : am_num($val)) ?></strong></article>
          <?php endforeach; ?>
        </div>
    <?php }
    elseif ($ajaxSection === 'kpis_total') { ?>
        <?php if ($unattributed > 0): ?>
        <div class="am-alert" style="margin-bottom:12px">
          <span><strong><?= am_num($unattributed) ?></strong> venda(s) aprovada(s) no período ainda sem lead/campanha casada (<?= am_num(100 - $attributionRate, 1) ?>% do total).</span>
          <a href="vendas_analytics.php?model=<?= am_h($model) ?>#nao-atribuidas">Atribuir manualmente →</a>
        </div>
        <?php endif; ?>
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
    <?php }
    elseif ($ajaxSection === 'charts') {
        $accountChart = ['labels' => [], 'spend' => [], 'revenue' => [], 'colors' => []];
        foreach ($accounts as $i => $acc) {
            $view = md_ads_metric_view($acc['metrics']['x'] ?? [], 'cross');
            $accountChart['labels'][] = $acc['name'];
            $accountChart['spend'][] = round((float)($acc['metrics']['x']['spend'] ?? 0), 2);
            $accountChart['revenue'][] = round((float)$view['revenue'], 2);
            $accountChart['colors'][] = $palette[$i % count($palette)];
        } ?>
        <div class="chart-grid">
          <div class="chart-box"><canvas id="amSpendChart"></canvas></div>
          <div class="chart-box"><canvas id="amRevenueChart"></canvas></div>
          <div class="chart-box"><canvas id="amAttrChart"></canvas></div>
        </div>
        <script>
        (function(){
          function amMoneyLabel(v){if(!v)return '';return 'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:0});}
          function amDoughnutOptions(title,formatter){
            return {responsive:true,maintainAspectRatio:false,cutout:'58%',plugins:{
              legend:{position:'bottom',labels:{color:'#94a3b8',boxWidth:10,font:{size:10}}},
              title:{display:true,text:title,color:'#e2e8f0',font:{size:11}},
              datalabels:{color:'#0b1220',backgroundColor:'rgba(255,255,255,.85)',borderRadius:4,padding:{top:2,bottom:2,left:5,right:5},font:{size:9,weight:'700'},formatter:formatter}
            }};
          }
          <?php if ($accounts): ?>
          var amColors=<?= json_encode($accountChart['colors'], JSON_UNESCAPED_UNICODE) ?>;
          var amLabels=<?= json_encode($accountChart['labels'], JSON_UNESCAPED_UNICODE) ?>;
          new Chart(document.getElementById('amSpendChart'),{type:'doughnut',plugins:[ChartDataLabels],data:{labels:amLabels,datasets:[{data:<?= json_encode($accountChart['spend']) ?>,backgroundColor:amColors,borderWidth:0}]},options:amDoughnutOptions('Investimento por conta',amMoneyLabel)});
          new Chart(document.getElementById('amRevenueChart'),{type:'doughnut',plugins:[ChartDataLabels],data:{labels:amLabels,datasets:[{data:<?= json_encode($accountChart['revenue']) ?>,backgroundColor:amColors,borderWidth:0}]},options:amDoughnutOptions('Faturamento líquido por conta',amMoneyLabel)});
          <?php endif; ?>
          new Chart(document.getElementById('amAttrChart'),{type:'doughnut',plugins:[ChartDataLabels],data:{labels:['Atribuídas','Não atribuídas'],datasets:[{data:[<?= (int)$attributedSalesCount ?>,<?= (int)$unattributed ?>],backgroundColor:['#22c55e','#334155'],borderWidth:0}]},options:amDoughnutOptions('Vendas atribuídas x não atribuídas',function(v){return v>0?v:'';})});
        })();
        </script>
    <?php }
    elseif ($ajaxSection === 'efficiency') { ?>
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
    <?php }
    elseif ($ajaxSection === 'top_ads') {
        $topAdsRaw = am_rank_top_ads(am_collect_ads($adsHierarchy['tree']), $adsMetricSource, 14);
        $topAdsRaw = am_resolve_ad_ids($pdo, $topAdsRaw, $adsHierarchy['windows']['x'], $endDate);
        $adIdsByIntegration = [];
        foreach ($topAdsRaw as $row) { if (!empty($row['ad_id']) && !empty($row['integration_id'])) { $adIdsByIntegration[$row['integration_id']][] = $row['ad_id']; } }
        $adCreatives = $adIdsByIntegration ? am_fetch_ad_creatives($adIdsByIntegration, $integrationsById) : [];
        $sortButtons = [['roas', 'ROAS'], ['cpc', 'CPC'], ['ctr', 'CTR'], ['sales', 'Vendas'], ['leads', 'Leads'], ['cpl', 'CPL'], ['spend', 'Custo']];
        if (!$topAdsRaw): ?>
        <div class="empty">Sem anúncios com investimento no período selecionado.</div>
        <?php else: ?>
        <div class="am-top-ads-tools"><span>Ordenar por:</span><div class="am-modal-btngroup" id="amTopAdsSort">
          <?php foreach ($sortButtons as [$k, $l]): ?><button type="button" data-sort-key="<?= $k ?>"><?= am_h($l) ?></button><?php endforeach; ?>
        </div></div>
        <div class="am-top-ads" id="amTopAdsGrid">
          <?php foreach ($topAdsRaw as $rank => $topAd): $tView = $topAd['view']; $img = !empty($topAd['ad_id']) ? ($adCreatives[(string)$topAd['ad_id']] ?? '') : ''; $notaClass = $topAd['nota'] >= 8 ? 'is-high' : ($topAd['nota'] >= 5 ? 'is-mid' : 'is-low'); ?>
          <article class="am-ad-card" data-roas="<?= $tView['roas'] ?>" data-cpc="<?= $tView['cpc'] ?>" data-ctr="<?= $tView['ctr'] ?>" data-sales="<?= $tView['sales'] ?>" data-leads="<?= $tView['leads'] ?>" data-cpl="<?= $tView['cpl'] ?>" data-spend="<?= $tView['spend'] ?>">
            <div class="am-ad-rank">#<?= $rank + 1 ?></div>
            <div class="am-ad-nota <?= $notaClass ?>" title="Nota de eficiência (ROAS + CPC + CTR)"><?= $topAd['nota'] ?><small>/10</small></div>
            <div class="am-ad-thumb"><?php if ($img): ?><img src="<?= am_h($img) ?>" alt="" loading="lazy"><?php else: ?><div class="am-ad-noimg">Sem prévia</div><?php endif; ?></div>
            <div class="am-ad-info">
              <strong><?= am_h($topAd['ad']) ?></strong>
              <span class="am-ad-ctx"><?= am_h($topAd['campaign']) ?> · <?= am_h($topAd['adset']) ?></span>
              <div class="am-ad-metrics">
                <div><small>ROAS</small><strong><?= am_num($tView['roas'], 2) ?></strong></div>
                <div><small>CPC</small><strong><?= am_money($tView['cpc']) ?></strong></div>
                <div><small>CTR</small><strong><?= am_num($tView['ctr'], 2) ?>%</strong></div>
                <div><small>CPL</small><strong><?= am_money($tView['cpl']) ?></strong></div>
                <div><small>Gasto</small><strong><?= am_money($tView['spend']) ?></strong></div>
                <div><small>Vendas</small><strong><?= am_num($tView['sales']) ?></strong></div>
                <div><small>Leads</small><strong><?= am_num($tView['leads']) ?></strong></div>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif;
    }
    elseif ($ajaxSection === 'account_tree') {
        $accountKey = (string)($_GET['account_key'] ?? '');
        $account = null;
        foreach ($accounts as $acc) { $k = $acc['integration_id'] ? (string)$acc['integration_id'] : 'unresolved'; if ($k === $accountKey) { $account = $acc; break; } }
        if (!$account) { ?>
        <div class="empty">Nenhuma campanha encontrada para esta conta no período selecionado.</div>
        <?php } else {
            $accView = md_ads_metric_view($account['metrics']['x'] ?? [], $adsMetricSource);
            $statusMap = $account['integration_id'] ? md_campaign_status_map($pdo, (int)$account['integration_id']) : [];
            $accTableId = 'acc' . preg_replace('/[^a-z0-9]/i', '', $accountKey); ?>
        <div class="am-acc-tools">
          <input type="search" class="am-search" placeholder="Buscar campanha, conjunto ou anúncio…" data-search-for="<?= $accTableId ?>">
          <div class="am-view-mode-toggle">
            <span class="am-toggle-label">Modo:</span>
            <button type="button" class="am-mode-btn active" data-mode="windows" data-table-target="<?= $accTableId ?>">7d / 30d / 90d</button>
            <button type="button" class="am-mode-btn" data-mode="filter" data-table-target="<?= $accTableId ?>">Filtro Selecionado (<?= $compareDays['filter'] ?>d)</button>
          </div>
          <button type="button" class="am-btn" data-expand-all="<?= $accTableId ?>">Expandir tudo</button>
          <button type="button" class="am-btn" data-collapse-all="<?= $accTableId ?>">Recolher tudo</button>
        </div>
        <div class="am-chips">
          <?php foreach ($chipColumns as [$key, $label, $fmt]): $val = $accView[$key] ?? 0; ?>
          <div class="am-chip" data-kpi-cell data-level="account" data-kpi="<?= $key ?>" data-format="<?= $fmt ?>" data-kpi-label="<?= am_h($label) ?>"><small><?= am_h($label) ?></small><strong><?= $fmt === 'money' ? am_money($val) : ($fmt === 'decimal' ? am_num($val, 2) : am_num($val)) ?></strong></div>
          <?php endforeach; ?>
        </div>
        <?php if (!$account['tree']): ?>
          <div class="empty">Sem campanhas com dados nessa janela.</div>
        <?php else: ?>
        <div class="ads-scroll">
          <table class="ads-table" data-account-table="<?= $accTableId ?>">
            <thead><tr>
              <th class="no-sort">Campanha / conjunto / anúncio<div class="ads-head-note">Clique no nome para abrir no dashboard geral · clique num valor para ver a evolução</div></th>
              <?php foreach ($adsColumns as [$sortKey, $head, $fmt]): ?>
              <th data-sort-key="<?= $sortKey ?>">↕ <?= am_h($head) ?><div class="ads-head-note"><?= am_h($windowLegend) ?></div></th>
              <?php endforeach; ?>
            </tr></thead>
            <?php foreach ($account['tree'] as $ci => $campaign):
              $cid = $accTableId . '-c' . substr(md5((string)$ci), 0, 10);
              $cView = md_ads_metric_view($campaign['metrics']['x'] ?? [], $adsMetricSource);
              $statusKey = normalize_match_key((string)$campaign['name']);
              $status = $statusMap[$statusKey] ?? '';
              $statusClass = $status !== '' ? 'is-' . strtolower(preg_replace('/[^a-z]+/i', '', $status)) : '';
            ?>
            <tbody class="camp-block" data-campaign-name="<?= am_h($campaign['name']) ?>" data-spend="<?= (float)($campaign['metrics']['x']['spend'] ?? 0) ?>" data-leads="<?= (int)$cView['leads'] ?>" data-sales="<?= (int)$cView['sales'] ?>" data-cpl="<?= (float)$cView['cpl'] ?>" data-cpc="<?= (float)$cView['cpc'] ?>" data-cac="<?= (float)$cView['cac'] ?>" data-roas="<?= (float)$cView['roas'] ?>" data-cpm="<?= (float)$cView['cpm'] ?>" data-frequency="<?= (float)$cView['frequency'] ?>" data-search-blob="<?= am_h(mb_strtolower((string)$campaign['name'], 'UTF-8')) ?>">
              <tr data-row-id="<?= $cid ?>">
                <td><div class="ads-name"><button type="button" class="ads-toggle" data-target="<?= $cid ?>" aria-expanded="false">▶</button>
                  <span class="dot-sale <?= $cView['sales'] > 0 ? 'has-sale' : 'no-sale' ?>" title="<?= $cView['sales'] > 0 ? 'Teve venda no período' : 'Sem venda no período' ?>"></span>
                  <div class="am-camp-title" onclick="location.href='vendas_analytics.php?campaign=<?= urlencode((string)$campaign['name']) ?>&model=<?= am_h($model) ?>'">
                    <strong><?= am_h($campaign['name']) ?></strong>
                    <div class="ads-level"><?php if ($statusClass): ?><span class="badge-status <?= am_h($statusClass) ?>"><?= am_h($status) ?></span><?php endif; ?><?= count($campaign['adsets']) ?> conjunto(s)</div>
                  </div>
                </div></td>
                <?php foreach ($adsColumns as [$key, $label, $fmt]): ?><?= am_kpi_td($campaign['metrics'], $compareDays, $adsMetricSource, $key, $label, $fmt, 'campaign') ?><?php endforeach; ?>
              </tr>
              <?php foreach ($campaign['adsets'] as $ai => $adset): $aid = $cid . '-a' . substr(md5((string)$ai), 0, 8); ?>
              <tr data-row-id="<?= $aid ?>" data-parent="<?= $cid ?>" data-adset-name="<?= am_h($adset['name']) ?>" hidden data-search-blob="<?= am_h(mb_strtolower((string)$campaign['name'] . ' ' . (string)$adset['name'], 'UTF-8')) ?>">
                <td><div class="ads-name ads-indent-1"><button type="button" class="ads-toggle" data-target="<?= $aid ?>" aria-expanded="false">▶</button>
                  <span class="dot-sale <?= md_ads_metric_view($adset['metrics']['x'] ?? [], $adsMetricSource)['sales'] > 0 ? 'has-sale' : 'no-sale' ?>"></span>
                  <div class="am-camp-title" onclick="location.href='vendas_analytics.php?campaign=<?= urlencode((string)$campaign['name']) ?>&adset=<?= urlencode((string)$adset['name']) ?>&model=<?= am_h($model) ?>'">
                    <strong><?= am_h($adset['name']) ?></strong><div class="ads-level"><?= count($adset['ads']) ?> anúncio(s)</div>
                  </div>
                </div></td>
                <?php foreach ($adsColumns as [$key, $label, $fmt]): ?><?= am_kpi_td($adset['metrics'], $compareDays, $adsMetricSource, $key, $label, $fmt, 'adset') ?><?php endforeach; ?>
              </tr>
              <?php foreach ($adset['ads'] as $ad): $adView = md_ads_metric_view($ad['metrics']['x'] ?? [], $adsMetricSource); ?>
              <tr data-parent="<?= $aid ?>" data-adset-name="<?= am_h($adset['name']) ?>" data-ad-name="<?= am_h($ad['name']) ?>" hidden data-search-blob="<?= am_h(mb_strtolower((string)$campaign['name'] . ' ' . (string)$adset['name'] . ' ' . (string)$ad['name'], 'UTF-8')) ?>">
                <td><div class="ads-name ads-indent-2"><span class="dot-sale <?= $adView['sales'] > 0 ? 'has-sale' : 'no-sale' ?>"></span><div><strong><?= am_h($ad['name']) ?></strong><div class="ads-level">Anúncio</div></div></div></td>
                <?php foreach ($adsColumns as [$key, $label, $fmt]): ?><?= am_kpi_td($ad['metrics'], $compareDays, $adsMetricSource, $key, $label, $fmt, 'ad') ?><?php endforeach; ?>
              </tr>
              <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
        </div>
        <?php endif;
        }
    }
    exit;
}

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
.am-filter label{display:block;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}
.am-filter select,.am-filter input{width:100%;height:34px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:0 9px;font-size:11px}
.am-filter .actions{display:flex;gap:7px}
.metric-grid{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px}
.metric{background:linear-gradient(145deg,var(--bg-card),rgba(13,21,38,.75));border:1px solid var(--border);border-radius:var(--r-lg);padding:13px;min-height:78px}
.metric span{display:block;font-size:10px;color:var(--muted);margin-bottom:6px}
.metric strong{font-size:19px;font-weight:780;letter-spacing:-.03em;color:var(--text)}
.chart-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}
.chart-box{height:280px;position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:12px}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:10px;max-width:100%}
.eff-table{width:100%;border-collapse:collapse}.eff-table th,.eff-table td{padding:10px;border-bottom:1px solid var(--border);font-size:10px;text-align:left}.eff-table th{color:var(--muted);text-transform:uppercase;font-size:9px;background:#101a2e}
.trend{display:inline-flex;align-items:center;padding:2px 6px;border-radius:999px;font-size:9px;font-weight:750;margin-top:3px}
.trend.good{color:#86efac;background:var(--success-dim)}.trend.bad{color:#fca5a5;background:var(--danger-dim)}.trend.neutral{color:var(--muted);background:var(--bg-hover)}
.am-alert{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:var(--warning-dim);border:1px solid rgba(245,158,11,.3);color:#fcd34d;font-size:12px}
.am-alert a{color:#fcd34d;font-weight:700;text-decoration:underline}
.am-acc-id{display:flex;align-items:center;gap:11px}
.am-avatar{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#111827;flex-shrink:0}
.am-acc-id strong{font-size:15px;color:var(--text);display:block}
.am-acc-id span{font-size:10px;color:var(--muted)}
.am-acc-tools{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.am-search{height:32px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:0 10px;font-size:11px;width:200px}
.am-btn{height:32px;padding:0 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--muted);font-size:10.5px;cursor:pointer}
.am-btn:hover{color:var(--text);border-color:var(--border-light)}
.am-view-mode-toggle{display:inline-flex;align-items:center;gap:4px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:2px 4px;height:32px}
.am-toggle-label{font-size:10px;color:var(--muted);font-weight:600;padding:0 4px}
.am-mode-btn{height:26px;padding:0 9px;border:1px solid transparent;background:transparent;color:var(--muted);border-radius:6px;font-size:10px;font-weight:650;cursor:pointer;transition:all .2s ease}
.am-mode-btn:hover{color:var(--text)}
.am-mode-btn.active{color:var(--primary);background:var(--primary-dim);border-color:rgba(250,204,21,.35)}
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
.ads-values[data-kpi-cell]{cursor:pointer}
.ads-values[data-kpi-cell]:hover{background:var(--primary-dim) !important;color:var(--primary)}
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
.am-top-ads-tools{display:flex;align-items:center;gap:9px;margin-bottom:12px;font-size:10.5px;color:var(--muted);flex-wrap:wrap}
.am-top-ads{display:grid;grid-template-columns:repeat(7,1fr);gap:12px}
.am-ad-card{position:relative;background:var(--bg);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden;display:flex;flex-direction:column}
.am-ad-rank{position:absolute;top:8px;left:8px;background:var(--primary);color:#111827;font-weight:800;font-size:11px;padding:2px 8px;border-radius:999px;z-index:2}
.am-ad-nota{position:absolute;top:8px;right:8px;z-index:2;display:flex;align-items:baseline;gap:1px;font-weight:800;font-size:13px;padding:3px 8px;border-radius:999px;color:#fff}
.am-ad-nota small{font-weight:650;font-size:8px;opacity:.85}
.am-ad-nota.is-high{background:rgba(34,197,94,.9)}
.am-ad-nota.is-mid{background:rgba(245,158,11,.9)}
.am-ad-nota.is-low{background:rgba(239,68,68,.9)}
.am-ad-thumb{width:100%;aspect-ratio:1/1;background:#0a1120;display:flex;align-items:center;justify-content:center}
.am-ad-thumb img{width:100%;height:100%;object-fit:cover}
.am-ad-noimg{color:var(--dim);font-size:10px;text-align:center;padding:10px}
.am-ad-info{padding:10px;display:flex;flex-direction:column;gap:4px}
.am-ad-info strong{font-size:11px;color:var(--text);line-height:1.35;overflow-wrap:anywhere}
.am-ad-ctx{font-size:9px;color:var(--muted);overflow-wrap:anywhere}
.am-ad-metrics{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-top:6px}
.am-ad-metrics div{background:var(--bg-card);border-radius:7px;padding:5px 6px}
.am-ad-metrics small{display:block;font-size:7.5px;color:var(--muted);text-transform:uppercase}
.am-ad-metrics strong{font-size:10.5px;color:var(--text)}
.am-collapsible{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden}
.am-collapsible>summary{list-style:none;cursor:pointer;padding:15px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.am-collapsible>summary::-webkit-details-marker{display:none}
.am-collapsible>summary h2{font-size:15px;margin:0;color:var(--text)}
.am-collapsible>summary p{font-size:10.5px;color:var(--muted);margin:4px 0 0;max-width:680px}
.am-chevron{flex-shrink:0;color:var(--muted);font-size:12px;margin-top:2px}
.am-section-body{padding:0 15px 15px}
.am-section-loading{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:11px;padding:6px 0 20px}
.am-spin{width:15px;height:15px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:am-spin .7s linear infinite;flex-shrink:0}
@keyframes am-spin{to{transform:rotate(360deg)}}
.am-modal{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px}
.am-modal[hidden]{display:none}
.am-modal-backdrop{position:absolute;inset:0;background:rgba(3,7,18,.75)}
.am-modal-box{position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);width:min(940px,100%);max-height:90vh;overflow:auto;padding:18px;display:flex;flex-direction:column;gap:14px}
.am-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.am-modal-head h3{margin:0;font-size:16px;color:var(--text)}.am-modal-head p{margin:4px 0 0;font-size:11px;color:var(--muted)}
.am-modal-close{background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--muted);width:30px;height:30px;cursor:pointer;flex-shrink:0}
.am-modal-close:hover{color:var(--text)}
.am-modal-tools{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.am-modal-btngroup{display:flex;gap:5px;flex-wrap:wrap}
.am-modal-btngroup button{height:28px;padding:0 10px;border:1px solid var(--border);background:var(--bg);color:var(--muted);border-radius:7px;font-size:10.5px;cursor:pointer}
.am-modal-btngroup button.active,.am-modal-btngroup button:hover{color:var(--primary);border-color:rgba(250,204,21,.3);background:var(--primary-dim)}
.am-modal-body{position:relative;display:flex;flex-direction:column;gap:14px}
.am-modal-body.is-loading .am-modal-chart-wrap,.am-modal-body.is-loading .table-wrap{opacity:.35;pointer-events:none}
.am-modal-chart-wrap{height:260px;position:relative}
.am-modal-spinner{display:none;position:absolute;top:110px;left:50%;transform:translateX(-50%);z-index:5;width:26px;height:26px;border:3px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:am-spin .7s linear infinite}
.am-modal-body.is-loading .am-modal-spinner{display:block}
@media(max-width:1400px){.am-top-ads{grid-template-columns:repeat(5,1fr)}}
@media(max-width:1100px){.metric-grid{grid-template-columns:repeat(2,1fr)}.chart-grid{grid-template-columns:1fr}.am-filter form{grid-template-columns:repeat(3,1fr)}.am-chips{grid-template-columns:repeat(3,1fr)}.am-top-ads{grid-template-columns:repeat(3,1fr)}}
@media(max-width:640px){.am-top-ads{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="am">
  <div class="am-head">
    <div class="am-title">
      <h1>Gerenciador de Anúncios</h1>
      <p>Árvore conta de anúncio → campanha → conjunto → anúncio. Leads e vendas são o cruzamento real por UTM feito neste sistema; gasto, CPM, CPC e frequência vêm sempre da Meta. Cada seção abaixo carrega sob demanda, ao ser expandida.</p>
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
      <?php $queryParamsBase = $_GET; unset($queryParamsBase['period'], $queryParamsBase['from'], $queryParamsBase['to'], $queryParamsBase['compare_y'], $queryParamsBase['compare_z'], $queryParamsBase['model'], $queryParamsBase['ads_metric_source']); ?>
      <?php foreach ($queryParamsBase as $k => $v): if (!is_scalar($v)) continue; ?><input type="hidden" name="<?= am_h((string)$k) ?>" value="<?= am_h((string)$v) ?>"><?php endforeach; ?>
      <?php if ($preset === 'custom'): ?>
      <div><label>Data início</label><input type="date" name="from" value="<?= am_h($period['start']) ?>"></div>
      <div><label>Data fim</label><input type="date" name="to" value="<?= am_h($period['end']) ?>"></div>
      <?php endif; ?>
      <div><label>Modelo de atribuição</label><select name="model"><option value="last_touch" <?= $model === 'last_touch' ? 'selected' : '' ?>>Último toque</option><option value="first_touch" <?= $model === 'first_touch' ? 'selected' : '' ?>>Primeiro toque</option></select></div>
      <div><label>Fonte de leads/vendas</label><select name="ads_metric_source"><option value="cross" <?= $adsMetricSource === 'cross' ? 'selected' : '' ?>>Cruzamento real</option><option value="meta" <?= $adsMetricSource === 'meta' ? 'selected' : '' ?>>Reportado pela Meta</option></select></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Aplicar</button></div>
    </form>
    <p style="margin:8px 0 0;font-size:10px;color:var(--muted)">Período selecionado: <strong><?= am_h(date('d/m/Y', strtotime($period['start']))) ?></strong> a <strong><?= am_h(date('d/m/Y', strtotime($period['end']))) ?></strong> (<?= $period['days'] ?> dia(s)).</p>
  </div>

  <details class="am-collapsible" data-ajax-url="?ajax_section=kpis_attributed&<?= $ajaxQueryBase ?>">
    <summary><div><h2>Atribuído ao cruzamento</h2><p>Investimento, leads, vendas e ROAS considerando apenas o que o cruzamento por UTM conseguiu ligar a uma campanha.</p></div><span class="am-chevron">▶</span></summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar</div></div>
  </details>

  <details class="am-collapsible" data-ajax-url="?ajax_section=kpis_total&<?= $ajaxQueryBase ?>">
    <summary><div><h2>Total geral do período</h2><p>Os mesmos indicadores somando todas as vendas do período — atribuídas ou não — para comparação, mais o alerta de vendas sem cruzamento.</p></div><span class="am-chevron">▶</span></summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar</div></div>
  </details>

  <details class="am-collapsible" data-ajax-url="?ajax_section=charts&<?= $ajaxQueryBase ?>">
    <summary><div><h2>Gráficos</h2><p>Investimento e faturamento líquido por conta (rosca), e a proporção de vendas atribuídas x não atribuídas.</p></div><span class="am-chevron">▶</span></summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar</div></div>
  </details>

  <details class="am-collapsible" data-ajax-url="?ajax_section=efficiency&<?= $ajaxQueryBase ?>">
    <summary><div><h2>Tendências de eficiência</h2><p>Como CAC, CPL, ROAS, CPM, frequência e CPC evoluíram entre as 3 janelas de comparação configuradas.</p></div><span class="am-chevron">▶</span></summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar</div></div>
  </details>

  <details class="am-collapsible" data-ajax-url="?ajax_section=top_ads&<?= $ajaxQueryBase ?>">
    <summary><div><h2>Top 14 anúncios</h2><p>Os anúncios mais eficientes do período (nota 1–10 combinando ROAS, CPC e CTR), com prévia buscada ao vivo na Meta — reordene por qualquer indicador.</p></div><span class="am-chevron">▶</span></summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar</div></div>
  </details>

  <?php foreach ($integrations as $idx => $integ):
    $accColor = $palette[$idx % count($palette)];
    $accKey = (string)(int)$integ['id'];
    $lastSync = $integ['last_success_sync_at'] ?? null;
  ?>
  <details class="am-collapsible am-account" style="border-left:4px solid <?= am_h($accColor) ?>" data-account-key="<?= am_h($accKey) ?>" data-account-name="<?= am_h($integ['name']) ?>" data-ajax-url="?ajax_section=account_tree&account_key=<?= urlencode($accKey) ?>&<?= $ajaxQueryBase ?>">
    <summary>
      <div class="am-acc-id">
        <div class="am-avatar" style="background:<?= am_h($accColor) ?>"><?= am_h(am_initials($integ['name'])) ?></div>
        <div><strong><?= am_h($integ['name']) ?></strong><span><?= !empty($integ['ad_account_id']) ? am_h((string)$integ['ad_account_id']) . ' · ' : '' ?><?= $lastSync ? 'sync ' . am_h(date('d/m H:i', strtotime((string)$lastSync))) : 'sem sync' ?></span></div>
      </div>
      <span class="am-chevron">▶</span>
    </summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar campanhas, conjuntos e anúncios</div></div>
  </details>
  <?php endforeach; ?>

  <?php if ($integrations): ?>
  <details class="am-collapsible am-account" style="border-left:4px solid #334155" data-account-key="unresolved" data-account-name="Sem conta identificada" data-ajax-url="?ajax_section=account_tree&account_key=unresolved&<?= $ajaxQueryBase ?>">
    <summary>
      <div class="am-acc-id">
        <div class="am-avatar" style="background:#334155">?</div>
        <div><strong>Sem conta identificada</strong><span>Campanhas cruzadas sem conta de anúncio resolvida</span></div>
      </div>
      <span class="am-chevron">▶</span>
    </summary>
    <div class="am-section-body"><div class="am-section-loading"><span class="am-spin"></span>Clique na seta para carregar</div></div>
  </details>
  <?php else: ?>
  <section class="section-card"><div class="empty">Nenhuma conta de anúncio ativa configurada em Integrações.</div></section>
  <?php endif; ?>
</div>

<div id="amKpiModal" class="am-modal" hidden>
  <div class="am-modal-backdrop"></div>
  <div class="am-modal-box">
    <div class="am-modal-head">
      <div><h3 id="amKpiModalTitle">Evolução</h3><p id="amKpiModalSubtitle">—</p></div>
      <button type="button" class="am-modal-close" aria-label="Fechar">✕</button>
    </div>
    <div class="am-modal-tools">
      <div class="am-modal-btngroup" id="amModalGran">
        <button type="button" data-gran="day">Dia</button>
        <button type="button" data-gran="week">Semana</button>
        <button type="button" data-gran="month">Mês</button>
      </div>
      <div class="am-modal-btngroup" id="amModalRange">
        <button type="button" data-range="7">7d</button>
        <button type="button" data-range="30">30d</button>
        <button type="button" data-range="90">90d</button>
        <button type="button" data-range="365">365d</button>
      </div>
      <div class="am-modal-secondary-wrap" style="display:flex;align-items:center;gap:6px;margin-left:auto;">
        <label for="amModalSecondarySelect" style="font-size:11px;color:var(--muted);white-space:nowrap;font-weight:500;">Segunda variável (Linha Azul):</label>
        <select id="amModalSecondarySelect" style="height:28px;padding:0 8px;border:1px solid var(--border);background:var(--bg);color:var(--text);border-radius:7px;font-size:11px;outline:none;cursor:pointer;">
          <option value="">Nenhuma (Apenas Barra)</option>
          <option value="spend">Gasto (R$)</option>
          <option value="leads">Leads</option>
          <option value="sales">Vendas</option>
          <option value="revenue">Receita (R$)</option>
          <option value="roas">ROAS</option>
          <option value="cac">CAC (R$)</option>
          <option value="cpl">CPL (R$)</option>
          <option value="cpc">CPC (R$)</option>
          <option value="cpm">CPM (R$)</option>
          <option value="ctr">CTR (%)</option>
          <option value="frequency">Frequência</option>
        </select>
      </div>
    </div>
    <div class="am-modal-body">
      <div class="am-modal-spinner"></div>
      <div class="am-modal-chart-wrap"><canvas id="amKpiModalChart"></canvas></div>
      <div class="table-wrap"><table class="eff-table">
        <thead><tr><th>Período</th><th>Gasto</th><th>Leads</th><th>Vendas</th><th>Receita</th><th>ROAS</th><th>CAC</th><th>CPL</th><th>CPC</th><th>CPM</th><th>CTR</th><th>Freq.</th></tr></thead>
        <tbody id="amKpiModalTableBody"></tbody>
      </table></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<script>
(function(){
  var amPageEndDate = <?= json_encode($endDate) ?>;
  var amPageModel = <?= json_encode($model) ?>;
  var amPageSource = <?= json_encode($adsMetricSource) ?>;

  function amExecuteScripts(container){
    container.querySelectorAll('script').forEach(function(old){
      var s = document.createElement('script');
      if (old.src) s.src = old.src; else s.textContent = old.textContent;
      old.replaceWith(s);
    });
  }
  function amLoadSection(det){
    det.dataset.loaded = '1';
    var body = det.querySelector('.am-section-body');
    fetch(det.dataset.ajaxUrl).then(function(r){ if(!r.ok) throw new Error('http '+r.status); return r.text(); }).then(function(html){
      body.innerHTML = html;
      amExecuteScripts(body);
    }).catch(function(){
      body.innerHTML = '<div class="empty">Erro ao carregar esta seção. <a href="javascript:void(0)" data-retry style="color:var(--primary)">Tentar de novo</a></div>';
      det.dataset.loaded = '0';
    });
  }
  document.addEventListener('toggle', function(e){
    var det = e.target;
    if (!det.classList || !det.classList.contains('am-collapsible')) return;
    var chevron = det.querySelector(':scope > summary .am-chevron');
    if (chevron) chevron.textContent = det.open ? '▼' : '▶';
    if (det.open && det.dataset.loaded !== '1') amLoadSection(det);
  }, true);

  document.addEventListener('click', function(e){
    var modeBtn = e.target.closest('.am-mode-btn');
    if (modeBtn) {
      var tableId = modeBtn.dataset.tableTarget;
      var mode = modeBtn.dataset.mode;
      var parentToggle = modeBtn.closest('.am-view-mode-toggle');
      if (parentToggle) {
        parentToggle.querySelectorAll('.am-mode-btn').forEach(function(b){ b.classList.remove('active'); });
      }
      modeBtn.classList.add('active');

      var table = document.querySelector('table[data-account-table="' + tableId + '"]');
      if (table) {
        var notes = table.querySelectorAll('.ads-head-note');
        if (mode === 'filter') {
          notes.forEach(function(n){
            if (!n.dataset.origText) n.dataset.origText = n.textContent;
            if (n.dataset.origText.indexOf('d /') !== -1) {
              n.textContent = 'Filtro (<?= $compareDays['filter'] ?>d)';
            }
          });
          table.querySelectorAll('.am-val-windows').forEach(function(el){ el.style.display = 'none'; });
          table.querySelectorAll('.am-val-filter').forEach(function(el){ el.style.display = 'inline'; });
        } else {
          notes.forEach(function(n){
            if (n.dataset.origText) n.textContent = n.dataset.origText;
          });
          table.querySelectorAll('.am-val-windows').forEach(function(el){ el.style.display = 'inline'; });
          table.querySelectorAll('.am-val-filter').forEach(function(el){ el.style.display = 'none'; });
        }
      }
      return;
    }

    var retry = e.target.closest('[data-retry]');
    if (retry) { var det = retry.closest('.am-collapsible'); if (det) amLoadSection(det); return; }

    var toggle = e.target.closest('.ads-toggle');
    if (toggle) {
      var id = toggle.dataset.target, opening = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', opening ? 'true' : 'false'); toggle.textContent = opening ? '▼' : '▶';
      document.querySelectorAll('[data-parent="'+id+'"]').forEach(function(row){
        row.hidden = !opening;
        if (!opening) {
          var child = row.dataset.rowId;
          if (child) {
            var childBtn = row.querySelector('.ads-toggle');
            if (childBtn) { childBtn.setAttribute('aria-expanded','false'); childBtn.textContent = '▶'; }
            document.querySelectorAll('[data-parent="'+child+'"]').forEach(function(r){ r.hidden = true; });
          }
        }
      });
      return;
    }
    var expandAll = e.target.closest('[data-expand-all]');
    if (expandAll) {
      var t1 = document.querySelector('[data-account-table="'+expandAll.dataset.expandAll+'"]');
      if (t1) { t1.querySelectorAll('.ads-toggle').forEach(function(b){ b.setAttribute('aria-expanded','true'); b.textContent='▼'; }); t1.querySelectorAll('[data-parent]').forEach(function(r){ r.hidden=false; }); }
      return;
    }
    var collapseAll = e.target.closest('[data-collapse-all]');
    if (collapseAll) {
      var t2 = document.querySelector('[data-account-table="'+collapseAll.dataset.collapseAll+'"]');
      if (t2) { t2.querySelectorAll('.ads-toggle').forEach(function(b){ b.setAttribute('aria-expanded','false'); b.textContent='▶'; }); t2.querySelectorAll('[data-parent]').forEach(function(r){ r.hidden=true; }); }
      return;
    }
    var sortTh = e.target.closest('.ads-table th[data-sort-key]');
    if (sortTh) {
      var table = sortTh.closest('table'); var key = sortTh.dataset.sortKey;
      var dir = sortTh.dataset.dir === 'asc' ? 'desc' : 'asc';
      table.querySelectorAll('th[data-sort-key]').forEach(function(h){ delete h.dataset.dir; });
      sortTh.dataset.dir = dir;
      var blocks = Array.from(table.querySelectorAll('tbody.camp-block'));
      blocks.sort(function(a,b){ var av=parseFloat(a.dataset[key]||'0'), bv=parseFloat(b.dataset[key]||'0'); return dir==='asc'?av-bv:bv-av; });
      blocks.forEach(function(b){ table.appendChild(b); });
      return;
    }
    var adSortBtn = e.target.closest('#amTopAdsSort [data-sort-key]');
    if (adSortBtn) {
      var grid = document.getElementById('amTopAdsGrid'); if (!grid) return;
      var akey = adSortBtn.dataset.sortKey;
      var adir = adSortBtn.dataset.dir === 'desc' ? 'asc' : 'desc';
      document.querySelectorAll('#amTopAdsSort [data-sort-key]').forEach(function(b){ delete b.dataset.dir; b.classList.remove('active'); });
      adSortBtn.dataset.dir = adir; adSortBtn.classList.add('active');
      var cards = Array.from(grid.children);
      cards.sort(function(a,b){ var av=parseFloat(a.dataset[akey]||'0'), bv=parseFloat(b.dataset[akey]||'0'); return adir==='asc'?av-bv:bv-av; });
      cards.forEach(function(c,i){ grid.appendChild(c); var rankEl = c.querySelector('.am-ad-rank'); if (rankEl) rankEl.textContent = '#'+(i+1); });
      return;
    }

    var kpiCell = e.target.closest('[data-kpi-cell]');
    if (kpiCell) {
      var level = kpiCell.dataset.level;
      var scope = { level: level, kpi: kpiCell.dataset.kpi, format: kpiCell.dataset.format, label: kpiCell.dataset.kpiLabel };
      if (level === 'account') {
        var accSec = kpiCell.closest('.am-account');
        scope.accountKey = accSec ? accSec.dataset.accountKey : '';
        scope.accountName = accSec ? accSec.dataset.accountName : '';
      } else {
        var tbody = kpiCell.closest('tbody.camp-block');
        scope.campaign = tbody ? tbody.dataset.campaignName : '';
        if (level !== 'campaign') {
          var tr = kpiCell.closest('tr');
          scope.adset = tr ? tr.dataset.adsetName : '';
          if (level === 'ad') scope.ad = tr ? tr.dataset.adName : '';
        }
      }
      amOpenKpiModal(scope);
      return;
    }
    if (e.target.closest('.am-modal-close') || e.target.classList.contains('am-modal-backdrop')) { amCloseKpiModal(); return; }
    var granBtn = e.target.closest('#amModalGran [data-gran]');
    if (granBtn) { amModalState.granularity = granBtn.dataset.gran; amModalState.userGran = true; amModalSetActiveButtons(); amFetchKpiHistory(); return; }
    var rangeBtn = e.target.closest('#amModalRange [data-range]');
    if (rangeBtn) {
      amModalState.range = parseInt(rangeBtn.dataset.range, 10);
      if (!amModalState.userGran) amModalState.granularity = amModalState.range <= 31 ? 'day' : (amModalState.range <= 180 ? 'week' : 'month');
      amModalSetActiveButtons(); amFetchKpiHistory(); return;
    }
  });
  document.addEventListener('input', function(e){
    if (!e.target.classList || !e.target.classList.contains('am-search')) return;
    var input = e.target;
    var table = document.querySelector('[data-account-table="'+input.dataset.searchFor+'"]');
    if (!table) return;
    var term = input.value.trim().toLowerCase();
    if (term === '') {
      table.querySelectorAll('tbody.camp-block').forEach(function(b){ b.style.display=''; });
      table.querySelectorAll('tr[data-parent]').forEach(function(r){ r.hidden=true; });
      table.querySelectorAll('.ads-toggle').forEach(function(t){ t.setAttribute('aria-expanded','false'); t.textContent='▶'; });
      return;
    }
    table.querySelectorAll('tbody.camp-block').forEach(function(block){
      var blockMatch = false;
      block.querySelectorAll('tr[data-parent]').forEach(function(r){ r.hidden=true; });
      block.querySelectorAll('.ads-toggle').forEach(function(t){ t.setAttribute('aria-expanded','false'); t.textContent='▶'; });
      block.querySelectorAll('tr').forEach(function(row){
        var blob = row.dataset.searchBlob || '';
        if (!blob.includes(term)) return;
        blockMatch = true; row.hidden = false;
        var parentId = row.dataset.parent;
        while (parentId) {
          var parentRow = block.querySelector('[data-row-id="'+parentId+'"]');
          if (!parentRow) break;
          parentRow.hidden = false;
          var btn = block.querySelector('.ads-toggle[data-target="'+parentId+'"]');
          if (btn) { btn.setAttribute('aria-expanded','true'); btn.textContent='▼'; }
          parentId = parentRow.dataset.parent;
        }
      });
      block.style.display = blockMatch ? '' : 'none';
    });
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') amCloseKpiModal(); });

  var amKpiFmt = {
    money: function(v){ return 'R$ ' + Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); },
    decimal: function(v){ return Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); },
    num: function(v){ return Number(v||0).toLocaleString('pt-BR'); },
    pct: function(v){ return Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '%'; },
  };
  var amKpiOptions = {
    spend: { label: 'Gasto', format: 'money' },
    leads: { label: 'Leads', format: 'num' },
    sales: { label: 'Vendas', format: 'num' },
    revenue: { label: 'Receita', format: 'money' },
    roas: { label: 'ROAS', format: 'decimal' },
    cac: { label: 'CAC', format: 'money' },
    cpl: { label: 'CPL', format: 'money' },
    cpc: { label: 'CPC', format: 'money' },
    cpm: { label: 'CPM', format: 'money' },
    ctr: { label: 'CTR', format: 'pct' },
    frequency: { label: 'Frequência', format: 'decimal' }
  };
  var amModalChart = null;
  var amModalState = { level:'', campaign:'', adset:'', ad:'', accountKey:'', accountName:'', kpi:'', format:'num', label:'', granularity:'day', range:30, userGran:false, secondaryKpi:'', rawRows:[] };

  function amPeriodLabel(p, gran){
    var d = new Date(p + 'T00:00:00');
    if (isNaN(d.getTime())) return p;
    if (gran === 'month') return d.toLocaleDateString('pt-BR',{month:'short',year:'2-digit'});
    return d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
  }
  window.amOpenKpiModal = function(scope){
    amModalState = Object.assign({ range: 30, userGran: false, secondaryKpi: '', rawRows: [] }, scope);
    amModalState.granularity = amModalState.range <= 31 ? 'day' : (amModalState.range <= 180 ? 'week' : 'month');
    var secSelect = document.getElementById('amModalSecondarySelect');
    if (secSelect) secSelect.value = '';
    document.getElementById('amKpiModal').hidden = false;
    document.body.style.overflow = 'hidden';
    amUpdateModalTitle();
    amModalSetActiveButtons();
    amFetchKpiHistory();
  };

  document.addEventListener('change', function(e){
    if (e.target && e.target.id === 'amModalSecondarySelect') {
      amModalState.secondaryKpi = e.target.value;
      if (amModalState.rawRows && amModalState.rawRows.length > 0) {
        amRenderKpiHistory(amModalState.rawRows);
      }
    }
  });

  function amUpdateModalTitle(){
    var s = amModalState, where = 'Visão geral';
    if (s.level === 'account') where = s.accountName || '';
    else if (s.level === 'campaign') where = s.campaign || '';
    else if (s.level === 'adset') where = (s.campaign||'') + ' › ' + (s.adset||'');
    else if (s.level === 'ad') where = (s.campaign||'') + ' › ' + (s.adset||'') + ' › ' + (s.ad||'');
    document.getElementById('amKpiModalTitle').textContent = 'Evolução de ' + (s.label || s.kpi);
    document.getElementById('amKpiModalSubtitle').textContent = where;
  }
  function amModalSetActiveButtons(){
    document.querySelectorAll('#amModalGran [data-gran]').forEach(function(b){ b.classList.toggle('active', b.dataset.gran === amModalState.granularity); });
    document.querySelectorAll('#amModalRange [data-range]').forEach(function(b){ b.classList.toggle('active', String(b.dataset.range) === String(amModalState.range)); });
  }
  function amCloseKpiModal(){ document.getElementById('amKpiModal').hidden = true; document.body.style.overflow = ''; }
  function amFetchKpiHistory(){
    var s = amModalState;
    var bodyEl = document.querySelector('#amKpiModal .am-modal-body');
    bodyEl.classList.add('is-loading');
    var params = new URLSearchParams({
      ajax_kpi_history: '1', level: s.level, kpi: s.kpi, granularity: s.granularity, range: s.range,
      end: amPageEndDate, model: amPageModel, source: amPageSource,
      campaign: s.campaign||'', adset: s.adset||'', ad: s.ad||'', account_key: s.accountKey||'',
    });
    fetch('gerenciador_anuncios.php?' + params.toString())
      .then(function(r){ return r.json(); })
      .then(function(rows){ amRenderKpiHistory(rows); })
      .catch(function(){ document.getElementById('amKpiModalTableBody').innerHTML = '<tr><td colspan="12">Erro ao carregar.</td></tr>'; })
      .finally(function(){ bodyEl.classList.remove('is-loading'); });
  }
  function amRenderKpiHistory(rows){
    amModalState.rawRows = rows;
    var s = amModalState;
    var labels = rows.map(function(r){ return amPeriodLabel(r.period, s.granularity); });
    var primaryValues = rows.map(function(r){ return r[s.kpi] || 0; });
    var primaryFmt = amKpiFmt[s.format] || amKpiFmt.num;

    var datasets = [
      {
        type: 'bar',
        label: s.label || s.kpi,
        data: primaryValues,
        backgroundColor: '#facc15',
        borderRadius: 4,
        yAxisID: 'y',
        order: 2
      }
    ];

    var scales = {
      x: { ticks: { color: '#94a3b8', font:{size:9} }, grid: { display:false } },
      y: {
        type: 'linear',
        position: 'left',
        ticks: { color: '#facc15', font:{size:9} },
        grid: { color: 'rgba(148,163,184,.12)' }
      }
    };

    var secKpi = s.secondaryKpi;
    var secConfig = secKpi ? amKpiOptions[secKpi] : null;

    if (secConfig) {
      var secValues = rows.map(function(r){ return r[secKpi] || 0; });
      var secFmt = amKpiFmt[secConfig.format] || amKpiFmt.num;

      datasets.push({
        type: 'line',
        label: secConfig.label,
        data: secValues,
        borderColor: '#3b82f6',
        backgroundColor: '#3b82f6',
        borderWidth: 2,
        pointBackgroundColor: '#3b82f6',
        pointRadius: 4,
        pointHoverRadius: 6,
        tension: 0.2,
        yAxisID: 'y1',
        order: 1
      });

      scales.y1 = {
        type: 'linear',
        position: 'right',
        ticks: { color: '#60a5fa', font:{size:9} },
        grid: { display: false }
      };
    }

    var ctx = document.getElementById('amKpiModalChart');
    if (amModalChart) amModalChart.destroy();

    amModalChart = new Chart(ctx, {
      plugins: [ChartDataLabels],
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: secConfig ? true : false,
            labels: { color: '#e2e8f0', font: { size: 11 } }
          },
          datalabels: {
            display: true,
            anchor: 'end',
            align: 'top',
            font: { size: 9, weight: '700' },
            formatter: function(value, context) {
              if (value === null || value === undefined) return '';
              if (context.datasetIndex === 0) {
                return value ? primaryFmt(value) : '';
              } else if (context.datasetIndex === 1 && secConfig) {
                var secFmt = amKpiFmt[secConfig.format] || amKpiFmt.num;
                return value ? secFmt(value) : '';
              }
              return '';
            },
            color: function(context) {
              if (context.datasetIndex === 1) {
                return '#60a5fa'; // Blue data labels for secondary line
              }
              return '#e2e8f0'; // Default text color for bars
            }
          }
        },
        scales: scales
      }
    });

    var tbody = document.getElementById('amKpiModalTableBody');
    var html = rows.slice().reverse().map(function(r){
      return '<tr><td>'+amPeriodLabel(r.period, s.granularity)+'</td><td>'+amKpiFmt.money(r.spend)+'</td><td>'+amKpiFmt.num(r.leads)+'</td><td>'+amKpiFmt.num(r.sales)+'</td><td>'+amKpiFmt.money(r.revenue)+'</td><td>'+amKpiFmt.decimal(r.roas)+'</td><td>'+amKpiFmt.money(r.cac)+'</td><td>'+amKpiFmt.money(r.cpl)+'</td><td>'+amKpiFmt.money(r.cpc)+'</td><td>'+amKpiFmt.money(r.cpm)+'</td><td>'+amKpiFmt.pct(r.ctr)+'</td><td>'+amKpiFmt.decimal(r.frequency)+'</td></tr>';
    }).join('');
    tbody.innerHTML = html || '<tr><td colspan="12">Sem dados no período.</td></tr>';
  }
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
