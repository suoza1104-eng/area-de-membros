<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics_dashboard.php';
proteger_admin();

$menu = 'vendas_analytics';
$page_title = 'Inteligencia de Negocio';
$pdo = getPDO();
metrics_ensure_schema($pdo);
ensure_manual_attribution_table_attr($pdo);

function va_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function va_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function va_num($value, int $decimals = 0): string { return number_format((float)$value, $decimals, ',', '.'); }
function va_pct($value, int $decimals = 1): string { return va_num($value, $decimals) . '%'; }
function va_selected($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
function va_duration($seconds): string {
    $seconds = max(0, (int)$seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    if ($days > 0) return $days . 'd ' . $hours . 'h';
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0 ? $hours . 'h ' . $minutes . 'min' : $minutes . 'min';
}
function va_delta(array $current, array $previous, string $key): ?float { return metrics_delta((float)($current[$key] ?? 0), (float)($previous[$key] ?? 0)); }
function va_delta_html(?float $delta, bool $lowerIsBetter = false): string {
    if ($delta === null) return '<span class="trend neutral">Sem base</span>';
    $improved = $lowerIsBetter ? $delta < 0 : $delta > 0;
    $neutral = abs($delta) < 0.05;
    $class = $neutral ? 'neutral' : ($improved ? 'good' : 'bad');
    $arrow = $neutral ? '&minus;' : ($delta > 0 ? '&#8593;' : '&#8595;');
    return '<span class="trend ' . $class . '">' . $arrow . ' ' . number_format(abs($delta), 1, ',', '.') . '%</span>';
}
function va_metric_value(array $data, string $key, string $format): string {
    $v = (float)($data[$key] ?? 0);
    if ($format === 'money') return va_money($v);
    if ($format === 'pct') return va_pct($v);
    if ($format === 'decimal') return va_num($v, 2);
    return va_num($v, 0);
}
function va_ads_cell(array $metrics,array $days,string $source,string $key,string $format='num'): string {
    $parts=[];foreach(['x','y','z'] as $w){$view=md_ads_metric_view($metrics[$w]??[],$source);$value=$view[$key]??0;$parts[]=$format==='money'?va_money($value):($format==='decimal'?va_num($value,2):va_num($value));}
    return implode(' <span class="ads-sep">/</span> ',$parts);
}
function va_compare_cell(float $a,float $b,bool $lowerBetter=false,string $format='money'): string {
    $fmt=static fn(float $v):string=>$format==='money'?va_money($v):va_num($v,2);$delta=$b!=0?(($a-$b)/abs($b))*100:null;$class='neutral';if($delta!==null&&abs($delta)>=.05){$better=$lowerBetter?$delta<0:$delta>0;$class=$better?'good':'bad';}
    return '<div>'.$fmt($a).' <span class="ads-sep">/</span> '.$fmt($b).'</div><span class="trend '.$class.'">'.($delta===null?'Sem base':(($delta>0?'+':'').number_format($delta,1,',','.').'%')).'</span>';
}

if ((string)($_GET['ajax'] ?? '') === 'lead_search') {
    header('Content-Type: application/json; charset=UTF-8');
    $term=trim((string)($_GET['q']??''));$rows=[];
    if(mb_strlen($term)>=2){$st=$pdo->prepare("SELECT id,source_user_id,lead_name,lead_email,lead_phone_raw,turma_codigo,created_at FROM attribution_leads WHERE lead_name LIKE :q OR lead_email LIKE :q OR lead_phone_raw LIKE :q OR CAST(source_user_id AS CHAR)=:exact ORDER BY created_at DESC LIMIT 20");$st->execute(['q'=>'%'.$term.'%','exact'=>$term]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}
    echo json_encode(['ok'=>true,'rows'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['acao']??'')==='atribuir_venda_manual') {
    $returnQuery=(string)($_POST['return_query']??'');
    try {
        if(!hash_equals((string)($_SESSION['sales_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Recarregue a página.');
        $saleId=(int)($_POST['sale_id']??0);$leadId=(int)($_POST['lead_id']??0);$model=(string)($_POST['attribution_model']??'last_touch');if(!in_array($model,['first_touch','last_touch'],true))$model='last_touch';
        $sale=md_row($pdo,"SELECT * FROM attribution_sales WHERE source_sale_id=:id LIMIT 1",['id'=>$saleId]);$lead=md_row($pdo,"SELECT * FROM attribution_leads WHERE id=:id LIMIT 1",['id'=>$leadId]);
        if(!$sale||!$lead)throw new RuntimeException('Venda ou lead não encontrado para atribuição.');
        $integration=metrics_active_integration($pdo);$resolved=['campaign_group'=>(string)$lead['utm_campaign_group'],'campaign_group_norm'=>(string)$lead['utm_campaign_group_norm'],'campaign_name'=>(string)$lead['utm_campaign_name'],'campaign_name_norm'=>(string)$lead['utm_campaign_name_norm'],'ad_name'=>(string)$lead['utm_ad_name'],'ad_name_norm'=>(string)$lead['utm_ad_name_norm']];
        if($integration){$candidate=resolve_meta_names_from_lead(build_meta_name_lookup($pdo,(int)$integration['id']),$lead);if(!empty($candidate['matched']))$resolved=array_merge($resolved,$candidate);}
        $manual=$pdo->prepare("INSERT INTO manual_sale_attributions (transaction_code,attribution_model,campaign_group,campaign_group_norm,campaign_name,campaign_name_norm,ad_name,ad_name_norm,source_user_id,lead_utm_source,lead_utm_medium,lead_utm_campaign,lead_utm_term,lead_utm_content,assigned_by,notes) VALUES (:tx,:model,:cg,:cgn,:cn,:cnn,:ad,:adn,:uid,:us,:um,:uc,:ut,:uco,:by,'Atribuição manual pelo painel de vendas') ON DUPLICATE KEY UPDATE campaign_group=VALUES(campaign_group),campaign_group_norm=VALUES(campaign_group_norm),campaign_name=VALUES(campaign_name),campaign_name_norm=VALUES(campaign_name_norm),ad_name=VALUES(ad_name),ad_name_norm=VALUES(ad_name_norm),source_user_id=VALUES(source_user_id),assigned_by=VALUES(assigned_by),updated_at=NOW()");
        $manual->execute(['tx'=>$sale['transaction_code'],'model'=>$model,'cg'=>$resolved['campaign_group'],'cgn'=>$resolved['campaign_group_norm'],'cn'=>$resolved['campaign_name'],'cnn'=>$resolved['campaign_name_norm'],'ad'=>$resolved['ad_name'],'adn'=>$resolved['ad_name_norm'],'uid'=>$lead['source_user_id'],'us'=>$lead['utm_source'],'um'=>$lead['utm_campaign_group'],'uc'=>$lead['utm_campaign_name'],'ut'=>$lead['utm_term'],'uco'=>$lead['utm_ad_name'],'by'=>(string)($_SESSION['equipe_nome']??'Administrador')]);
        $saleTs=strtotime((string)$sale['sale_date']);$leadTs=strtotime((string)$lead['created_at']);upsert_attribution_match($pdo,['sale_id'=>(int)$sale['id'],'lead_id'=>(int)$lead['id'],'attribution_model'=>$model,'match_type'=>'manual','attribution_seconds_diff'=>max(0,$saleTs-$leadTs),'lead_created_at'=>$lead['created_at'],'sale_date'=>$sale['sale_date'],'campaign_group'=>$resolved['campaign_group'],'campaign_group_norm'=>$resolved['campaign_group_norm'],'campaign_name'=>$resolved['campaign_name'],'campaign_name_norm'=>$resolved['campaign_name_norm'],'ad_name'=>$resolved['ad_name'],'ad_name_norm'=>$resolved['ad_name_norm'],'revenue_value'=>(float)$sale['producer_net'],'product_name'=>(string)$sale['product_name']]);
        header('Location: vendas_analytics.php?'.$returnQuery.'&manual_ok=1#nao-atribuidas');exit;
    } catch(Throwable $e){header('Location: vendas_analytics.php?'.$returnQuery.'&manual_err='.urlencode($e->getMessage()).'#nao-atribuidas');exit;}
}
if(empty($_SESSION['sales_csrf']))$_SESSION['sales_csrf']=bin2hex(random_bytes(24));

$preset = (string)($_GET['period'] ?? 'month');
if (!in_array($preset, ['7','30','90','365','month','quarter','year','custom'], true)) $preset = 'month';
$period = metrics_period($preset, $_GET['from'] ?? null, $_GET['to'] ?? null);
$filters = [
    'basis' => in_array(($_GET['basis'] ?? ''), ['gross_revenue','net_revenue','producer_net'], true) ? $_GET['basis'] : (get_setting('metrics_default_revenue_basis', 'producer_net') ?: 'producer_net'),
    'model' => ($_GET['model'] ?? '') === 'first_touch' ? 'first_touch' : 'last_touch',
    'product' => trim((string)($_GET['product'] ?? '')),
    'turma' => trim((string)($_GET['turma'] ?? '')),
    'campaign' => trim((string)($_GET['campaign'] ?? '')),
    'adset' => trim((string)($_GET['adset'] ?? '')),
];
$compareDays=[
    'x'=>max(1,min(365,(int)($_GET['compare_x']??7))),
    'y'=>max(1,min(365,(int)($_GET['compare_y']??30))),
    'z'=>max(1,min(365,(int)($_GET['compare_z']??90))),
];
$adsMetricSource=(string)($_GET['ads_metric_source']??'cross')==='meta'?'meta':'cross';

$current = md_snapshot($pdo, $period['start'], $period['end'], $filters);
$previous = md_snapshot($pdo, $period['previous_start'], $period['previous_end'], $filters);
$daily = md_daily_series($pdo, $period['start'], $period['end'], $filters);
$monthly = md_monthly_series($pdo, $filters);
$breakdowns = md_breakdowns($pdo, $period['start'], $period['end'], $filters);
$cohorts = md_cohorts($pdo, $period['start'], $period['end'], $filters);
$adsHierarchy = md_ads_hierarchy($pdo,$period['end'],$filters['model'],$compareDays);
$options = md_filter_options($pdo);
$integration = metrics_active_integration($pdo);

$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month');
$dayOffset = (int)$monthStart->diff($today)->days;
$previousMonthStart = $monthStart->modify('-1 month');
$previousMonthEnd = min($previousMonthStart->modify('+' . $dayOffset . ' days'), $previousMonthStart->modify('last day of this month'));
$lastYearStart = $monthStart->modify('-1 year');
$lastYearEnd = min($lastYearStart->modify('+' . $dayOffset . ' days'), $lastYearStart->modify('last day of this month'));
$mtd = md_snapshot($pdo, $monthStart->format('Y-m-d'), $today->format('Y-m-d'), $filters);
$prevMtd = md_snapshot($pdo, $previousMonthStart->format('Y-m-d'), $previousMonthEnd->format('Y-m-d'), $filters);
$yearMtd = md_snapshot($pdo, $lastYearStart->format('Y-m-d'), $lastYearEnd->format('Y-m-d'), $filters);
$avg12 = ['revenue'=>0,'sales'=>0,'leads'=>0,'spend'=>0,'roas'=>0,'cac'=>0];
$avg12Months = 0;
for ($i=1; $i<=12; $i++) {
    $s=$monthStart->modify('-'.$i.' months'); $e=min($s->modify('+'.$dayOffset.' days'),$s->modify('last day of this month'));
    $snap=md_snapshot($pdo,$s->format('Y-m-d'),$e->format('Y-m-d'),$filters);
    if ((float)$snap['sales'] > 0 || (float)$snap['leads'] > 0 || (float)$snap['spend'] > 0) {
        foreach(array_keys($avg12) as $key)$avg12[$key]+=(float)$snap[$key];
        $avg12Months++;
    }
}
foreach($avg12 as $key=>$value)$avg12[$key]=$avg12Months>0?$value/$avg12Months:0;

$salesQuery = trim((string)($_GET['sales_q'] ?? ''));
$salesStatus = (string)($_GET['sales_status'] ?? 'all');
if (!in_array($salesStatus, ['all', 'approved', 'refunded'], true)) $salesStatus = 'all';
$salesPage = max(1, (int)($_GET['sales_page'] ?? 1));
$salesPerPage = 50;
$salesParams = [
    'sales_start' => $period['start'] . ' 00:00:00',
    'sales_end' => $period['end'] . ' 23:59:59',
    'detail_model' => $filters['model'],
];
$salesFilter = md_filter_sql($filters, 'sale', $salesParams);
$salesWhere = ["COALESCE(s.transaction_date,s.payment_confirmed_at,s.imported_at) BETWEEN :sales_start AND :sales_end"];
if ($salesStatus === 'approved') $salesWhere[] = md_approved_sql('s');
if ($salesStatus === 'refunded') $salesWhere[] = md_refund_sql('s');
if ($salesQuery !== '') {
    $salesWhere[] = "(s.transaction_code LIKE :sales_q OR s.buyer_name LIKE :sales_q OR s.buyer_email LIKE :sales_q OR s.buyer_phone_norm LIKE :sales_q OR s.product_name LIKE :sales_q OR s.price_name LIKE :sales_q)";
    $salesParams['sales_q'] = '%' . $salesQuery . '%';
}
$salesWhereSql = implode(' AND ', $salesWhere) . $salesFilter;
$salesFromSql = "
    FROM hotmart_sales_live s
    LEFT JOIN attribution_sales axs_detail ON axs_detail.source_sale_id = s.id
    LEFT JOIN attribution_matches am_detail ON am_detail.sale_id = axs_detail.id AND am_detail.attribution_model = :detail_model
    LEFT JOIN attribution_leads al_detail ON al_detail.id = am_detail.lead_id
    LEFT JOIN users u_detail ON u_detail.id = s.matched_user_id
";
$salesCountStmt = $pdo->prepare("SELECT COUNT(DISTINCT s.id) {$salesFromSql} WHERE {$salesWhereSql}");
$salesCountStmt->execute($salesParams);
$salesTotal = (int)$salesCountStmt->fetchColumn();
$salesPages = max(1, (int)ceil($salesTotal / $salesPerPage));
$salesPage = min($salesPage, $salesPages);
$salesOffset = ($salesPage - 1) * $salesPerPage;
$salesSql = "
    SELECT s.*,
           COALESCE(NULLIF(al_detail.turma_codigo,''), NULLIF(u_detail.codigo_turma,''), 'Sem turma') AS turma_atribuida,
           al_detail.created_at AS lead_created_at,
           am_detail.match_type,
           am_detail.attribution_seconds_diff,
           am_detail.campaign_group,
           am_detail.campaign_name,
           am_detail.ad_name,
           COALESCE(NULLIF(s.utm_source,''), NULLIF(al_detail.utm_source,''), NULLIF(u_detail.utm_source,'')) AS detail_utm_source,
           COALESCE(NULLIF(s.utm_medium,''), NULLIF(u_detail.utm_medium,'')) AS detail_utm_medium,
           COALESCE(NULLIF(s.utm_campaign,''), NULLIF(al_detail.utm_campaign_group,''), NULLIF(u_detail.utm_campaign,'')) AS detail_utm_campaign,
           COALESCE(NULLIF(s.utm_term,''), NULLIF(al_detail.utm_term,''), NULLIF(u_detail.utm_term,'')) AS detail_utm_term,
           COALESCE(NULLIF(s.utm_content,''), NULLIF(u_detail.utm_content,'')) AS detail_utm_content
      {$salesFromSql}
     WHERE {$salesWhereSql}
  ORDER BY COALESCE(s.transaction_date,s.payment_confirmed_at,s.imported_at) DESC, s.id DESC
     LIMIT {$salesPerPage} OFFSET {$salesOffset}
";
$salesStmt = $pdo->prepare($salesSql);
$salesStmt->execute($salesParams);
$salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$unattributedParams=['model'=>$filters['model'],'start'=>$period['start'].' 00:00:00','end'=>$period['end'].' 23:59:59'];
$unattributedProduct='';
if($filters['product']!==''){$unattributedProduct=' AND s.product_name=:product';$unattributedParams['product']=$filters['product'];}
$unattributedRows=md_rows($pdo,"SELECT s.id,s.transaction_code,s.transaction_date,s.product_name,s.price_name,s.gross_revenue,s.producer_net,s.buyer_name,s.buyer_email,s.buyer_phone_raw FROM hotmart_sales_live s JOIN attribution_sales axs ON axs.source_sale_id=s.id LEFT JOIN attribution_matches am ON am.sale_id=axs.id AND am.attribution_model=:model WHERE ".md_approved_sql('s')." AND COALESCE(s.transaction_date,s.payment_confirmed_at) BETWEEN :start AND :end AND am.id IS NULL{$unattributedProduct} ORDER BY COALESCE(s.transaction_date,s.payment_confirmed_at) DESC LIMIT 50",$unattributedParams);
$manualReturn=$_GET;unset($manualReturn['manual_ok'],$manualReturn['manual_err']);$manualReturnQuery=http_build_query($manualReturn);

$metricCards = [
    ['spend','Investimento Meta','money',true,'Gasto confirmado pela Meta'],
    ['leads','Leads captados','number',false,'Cadastros no banco'],
    ['sales','Vendas aprovadas','number',false,'Transacoes unicas concluídas'],
    ['gross_revenue','Faturamento bruto','money',false,'Valor pago pelo cliente'],
    ['net_revenue','Receita liquida','money',false,'Apos taxas da plataforma'],
    ['producer_net','Liquido do produtor','money',false,'Comissoes liquidas recebidas'],
    ['fees','Taxas e diferencas','money',true,'Bruto menos liquido do produtor'],
    ['conversion_rate','Conversao lead/venda','pct',false,'Vendas aprovadas / leads'],
    ['roas','ROAS','decimal',false,'Receita selecionada / gasto'],
    ['cac','CAC','money',true,'Gasto / vendas aprovadas'],
    ['cpl','CPL','money',true,'Gasto / leads captados'],
    ['average_ticket','Ticket medio bruto','money',false,'Faturamento bruto / vendas'],
    ['cpc','CPC','money',true,'Gasto / cliques'],
    ['cpm','CPM','money',true,'Custo por mil impressoes'],
    ['ctr','CTR','pct',false,'Cliques / impressoes'],
    ['frequency','Frequencia','decimal',true,'Impressoes / alcance'],
    ['refund_rate','Taxa de reembolso','pct',true,'Reembolsos / vendas + reembolsos'],
    ['attribution_rate','Cobertura de atribuicao','pct',false,'Vendas ligadas a um lead/campanha'],
];

$basisLabels=['gross_revenue'=>'faturamento bruto','net_revenue'=>'receita liquida','producer_net'=>'liquido do produtor'];
$lastSync=$integration['last_success_sync_at']??null;

include __DIR__ . '/_header.php';
?>

<style>
.bi{display:flex;flex-direction:column;gap:16px}.bi *{box-sizing:border-box}.bi-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.bi-title h1{font-size:23px;margin:0;color:var(--text)}.bi-title p{margin:5px 0 0;color:var(--muted);font-size:12px}.sync-pill{display:flex;align-items:center;gap:8px;padding:8px 11px;border:1px solid var(--border);background:var(--bg-card);border-radius:999px;color:var(--muted);font-size:11px;white-space:nowrap}.sync-dot{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 0 4px var(--success-dim)}
.bi-filter{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px}.periods{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.periods a{padding:6px 10px;border:1px solid var(--border);border-radius:8px;color:var(--muted);font-size:11px;font-weight:650;text-decoration:none}.periods a:hover,.periods a.active{background:var(--primary-dim);border-color:rgba(250,204,21,.3);color:var(--primary)}.filter-grid{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:9px}.fg label{display:block;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px}.fg select,.fg input{width:100%;height:34px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:0 9px;font-size:11px}.fg-actions{display:flex;align-items:flex-end;gap:7px}.fg-actions .btn{height:34px;display:inline-flex;align-items:center;justify-content:center}
.bi-note{padding:9px 12px;background:rgba(56,189,248,.07);border:1px solid rgba(56,189,248,.17);border-radius:9px;color:#93c5fd;font-size:11px}.metric-grid{display:grid;grid-template-columns:repeat(6,minmax(145px,1fr));gap:10px}.metric{background:linear-gradient(145deg,var(--bg-card),rgba(13,21,38,.75));border:1px solid var(--border);border-radius:var(--r-lg);padding:13px;min-height:104px;position:relative;overflow:hidden}.metric:after{content:'';position:absolute;width:55px;height:55px;border-radius:50%;right:-24px;top:-24px;background:var(--primary-dim)}.metric-label{font-size:10px;color:var(--muted);min-height:30px}.metric-value{font-size:19px;font-weight:780;letter-spacing:-.03em;color:var(--text);white-space:nowrap}.metric-foot{display:flex;align-items:center;gap:7px;margin-top:7px}.metric-hint{font-size:9px;color:var(--dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.trend{display:inline-flex;align-items:center;padding:2px 6px;border-radius:999px;font-size:9px;font-weight:750}.trend.good{color:#86efac;background:var(--success-dim)}.trend.bad{color:#fca5a5;background:var(--danger-dim)}.trend.neutral{color:var(--muted);background:var(--bg-hover)}
.section-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:15px}.section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:13px}.section-head h2{font-size:15px;margin:0;color:var(--text)}.section-head p{font-size:10px;color:var(--muted);margin:3px 0 0}.context-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.context{padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)}.context small{color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em}.context strong{display:block;font-size:17px;margin:4px 0}.context-line{display:flex;justify-content:space-between;align-items:center;font-size:10px;color:var(--muted)}
.chart-grid{display:grid;grid-template-columns:1.35fr 1fr;gap:12px}.chart-box{height:330px;position:relative}.chart-box.small{height:270px}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}.three-col{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.four-col{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:10px}.bi-table{width:100%;border-collapse:collapse;min-width:780px}.bi-table th{position:sticky;top:0;background:#101a2e;color:var(--muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em;text-align:left;padding:9px;border-bottom:1px solid var(--border)}.bi-table td{padding:9px;border-bottom:1px solid var(--border);font-size:11px;color:var(--text)}.bi-table tr:last-child td{border-bottom:0}.bi-table tr:hover td{background:var(--bg-hover)}.subtext{font-size:9px;color:var(--muted);margin-top:2px}.resilient{display:inline-flex;padding:3px 7px;border-radius:999px;background:var(--success-dim);color:#86efac;font-size:9px;font-weight:700}.watch{display:inline-flex;padding:3px 7px;border-radius:999px;background:var(--warning-dim);color:#fcd34d;font-size:9px;font-weight:700}.bar-list{display:flex;flex-direction:column;gap:10px}.bar-row{display:grid;grid-template-columns:minmax(100px,1fr) 2fr auto;gap:9px;align-items:center;font-size:10px}.bar-track{height:7px;background:var(--bg);border-radius:99px;overflow:hidden}.bar-fill{height:100%;background:linear-gradient(90deg,var(--primary),#fb923c);border-radius:99px}.empty{padding:28px;text-align:center;color:var(--muted);font-size:11px}
.sales-tools{display:grid;grid-template-columns:minmax(220px,1fr) 180px auto;gap:8px;align-items:end}.sales-tools input,.sales-tools select{width:100%;height:36px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);padding:0 9px;font-size:11px}.sales-tools label{display:block;margin-bottom:4px;color:var(--muted);font-size:9px;text-transform:uppercase}.sales-table{min-width:1500px}.sales-status{display:inline-flex;padding:3px 7px;border-radius:999px;background:var(--bg-hover);color:var(--text);font-size:9px;font-weight:750}.sales-money strong{display:block;color:#bbf7d0}.sales-pagination{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;color:var(--muted);font-size:10px}.sales-pages{display:flex;gap:6px}.sales-pages a,.sales-pages span{padding:6px 9px;border:1px solid var(--border);border-radius:7px;color:var(--text);text-decoration:none}.sales-pages .active{background:var(--primary-dim);color:var(--primary);border-color:rgba(250,204,21,.3)}.utm-stack{max-width:260px;overflow-wrap:anywhere}
.ads-controls{display:grid;grid-template-columns:repeat(3,minmax(90px,120px)) minmax(210px,1fr) auto;gap:9px;align-items:end;margin-bottom:12px}.ads-controls label{display:block;color:var(--muted);font-size:9px;text-transform:uppercase;margin-bottom:4px}.ads-controls input[type=number]{width:100%;height:35px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);padding:0 9px}.ads-source{display:flex;align-items:center;gap:8px;height:35px;padding:0 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg)}.ads-source label{margin:0;text-transform:none;font-size:11px;color:var(--text)}.ads-scroll{overflow:auto;border:1px solid var(--border);border-radius:10px;max-height:650px}.ads-table{border-collapse:separate;border-spacing:0;min-width:1450px;width:100%}.ads-table th,.ads-table td{padding:9px 10px;border-bottom:1px solid var(--border);background:var(--bg-card);font-size:10px;white-space:nowrap;text-align:right}.ads-table th{position:sticky;top:0;z-index:4;background:#101a2e;color:var(--muted);text-transform:uppercase;font-size:9px}.ads-table th:first-child,.ads-table td:first-child{position:sticky;left:0;z-index:3;text-align:left;min-width:330px;max-width:330px;box-shadow:8px 0 12px -12px #000}.ads-table th:first-child{z-index:5}.ads-table tr:hover td{background:#142039}.ads-table tr:hover td:first-child{background:#142039}.ads-name{display:flex;align-items:center;gap:7px;min-width:0}.ads-toggle{width:19px;height:19px;border:0;background:transparent;color:#60a5fa;cursor:pointer;padding:0}.ads-indent-1{padding-left:25px}.ads-indent-2{padding-left:50px}.ads-level{font-size:8px;color:var(--muted);text-transform:uppercase}.ads-sep{color:#475569;margin:0 2px}.ads-values{font-weight:700}.ads-head-note{font-size:9px;color:var(--muted);margin-top:3px}.eff-table{width:100%;border-collapse:collapse}.eff-table th,.eff-table td{padding:10px;border-bottom:1px solid var(--border);font-size:10px;text-align:left}.eff-table th{color:var(--muted);text-transform:uppercase;font-size:9px}.manual-alert{padding:10px 12px;border-radius:9px;margin-bottom:10px;font-size:11px}.manual-alert.ok{background:var(--success-dim);color:#86efac}.manual-alert.err{background:var(--danger-dim);color:#fca5a5}.unattr-table{min-width:1050px}.lead-picker{position:relative;min-width:290px}.lead-picker input[type=search]{width:100%;height:32px;background:var(--bg);border:1px solid var(--border);border-radius:7px;color:var(--text);padding:0 8px;font-size:10px}.lead-results{position:absolute;left:0;right:0;top:35px;z-index:20;background:#0f172a;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow);max-height:220px;overflow:auto;display:none}.lead-option{display:block;width:100%;padding:8px;border:0;border-bottom:1px solid var(--border);background:transparent;color:var(--text);text-align:left;font-size:10px;cursor:pointer}.lead-option:hover{background:var(--bg-hover)}.lead-selected{margin:5px 0;color:#86efac;font-size:9px}.manual-form-actions{display:flex;gap:6px;align-items:center}
@media(max-width:1300px){.metric-grid{grid-template-columns:repeat(4,1fr)}.filter-grid{grid-template-columns:repeat(3,1fr)}.four-col{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.metric-grid{grid-template-columns:repeat(2,1fr)}.chart-grid,.two-col,.three-col,.four-col{grid-template-columns:1fr}.context-grid{grid-template-columns:repeat(2,1fr)}.bi-head{flex-direction:column}.sync-pill{white-space:normal}}@media(max-width:600px){.filter-grid{grid-template-columns:1fr 1fr}.fg-actions{grid-column:span 2}.metric-grid{grid-template-columns:1fr 1fr;gap:7px}.metric{padding:11px;min-height:96px}.metric-value{font-size:16px}.context-grid{grid-template-columns:1fr}.chart-box{height:285px}.section-card{padding:11px}.bi-title h1{font-size:19px}}
@media(max-width:700px){.sales-tools{grid-template-columns:1fr}.sales-pagination{align-items:flex-start;flex-direction:column}}
@media(max-width:800px){.ads-controls{grid-template-columns:repeat(3,1fr)}.ads-source,.ads-controls .fg-actions{grid-column:1/-1}}
</style>

<div class="bi">
  <div class="bi-head">
    <div class="bi-title"><h1>Painel de desempenho do negocio</h1><p>Trafego, leads, atribuicao e vendas reconciliados na mesma linha do tempo.</p></div>
    <div class="sync-pill"><span class="sync-dot"></span><?= $lastSync ? 'Meta atualizada em '.va_h(date('d/m/Y H:i',strtotime((string)$lastSync))) : 'Integracao Meta aguardando sincronizacao' ?></div>
  </div>

  <form class="bi-filter" method="get">
    <div class="periods">
      <?php foreach(['7'=>'7 dias','30'=>'30 dias','90'=>'90 dias','365'=>'365 dias','month'=>'Mes atual','quarter'=>'Trimestre','year'=>'Ano atual','custom'=>'Personalizado'] as $k=>$label): ?>
        <a class="<?= $preset===$k?'active':'' ?>" href="?<?= va_h(http_build_query(array_merge($_GET,['period'=>$k]))) ?>"><?= va_h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="period" value="<?= va_h($preset) ?>">
    <div class="filter-grid">
      <?php if($preset==='custom'): ?><div class="fg"><label>Inicio</label><input type="date" name="from" value="<?= va_h($period['start']) ?>"></div><div class="fg"><label>Fim</label><input type="date" name="to" value="<?= va_h($period['end']) ?>"></div><?php endif; ?>
      <div class="fg"><label>Base do ROAS</label><select name="basis"><option value="producer_net"<?=va_selected($filters['basis'],'producer_net')?>>Liquido produtor</option><option value="net_revenue"<?=va_selected($filters['basis'],'net_revenue')?>>Receita liquida</option><option value="gross_revenue"<?=va_selected($filters['basis'],'gross_revenue')?>>Faturamento bruto</option></select></div>
      <div class="fg"><label>Atribuicao</label><select name="model"><option value="last_touch"<?=va_selected($filters['model'],'last_touch')?>>Ultimo toque</option><option value="first_touch"<?=va_selected($filters['model'],'first_touch')?>>Primeiro toque</option></select></div>
      <div class="fg"><label>Campanha</label><select name="campaign"><option value="">Todas</option><?php foreach($options['campaigns'] as $v):?><option<?=va_selected($filters['campaign'],$v)?>><?=va_h($v)?></option><?php endforeach;?></select></div>
      <div class="fg"><label>Conjunto</label><select name="adset"><option value="">Todos</option><?php foreach($options['adsets'] as $v):?><option<?=va_selected($filters['adset'],$v)?>><?=va_h($v)?></option><?php endforeach;?></select></div>
      <div class="fg"><label>Produto</label><select name="product"><option value="">Todos</option><?php foreach($options['products'] as $v):?><option<?=va_selected($filters['product'],$v)?>><?=va_h($v)?></option><?php endforeach;?></select></div>
      <div class="fg"><label>Turma</label><select name="turma"><option value="">Todas</option><?php foreach($options['turmas'] as $v):?><option<?=va_selected($filters['turma'],$v)?>><?=va_h($v)?></option><?php endforeach;?></select></div>
      <div class="fg-actions"><button class="btn btn-primary" type="submit">Aplicar</button><a class="btn btn-ghost" href="vendas_analytics.php">Limpar</a></div>
    </div>
  </form>

  <div class="bi-note">Periodo: <strong><?=va_h(date('d/m/Y',strtotime($period['start'])))?></strong> a <strong><?=va_h(date('d/m/Y',strtotime($period['end'])))?></strong>. Comparacao dos cards: periodo imediatamente anterior com os mesmos <?= (int)$period['days'] ?> dias. ROAS calculado por <?=va_h($basisLabels[$filters['basis']])?>.</div>

  <div class="metric-grid">
    <?php foreach($metricCards as [$key,$label,$format,$lower,$hint]): ?>
      <article class="metric"><div class="metric-label"><?=va_h($label)?></div><div class="metric-value"><?=va_metric_value($current,$key,$format)?></div><div class="metric-foot"><?=va_delta_html(va_delta($current,$previous,$key),$lower)?><span class="metric-hint"><?=va_h($hint)?></span></div></article>
    <?php endforeach; ?>
  </div>

  <section class="section-card">
    <div class="section-head"><div><h2>Mes atual ate o dia <?=date('d')?></h2><p>Comparacoes com a mesma quantidade de dias, sem comparar mes parcial com mes cheio.</p></div></div>
    <div class="context-grid">
      <?php foreach([['Mes atual',$mtd,null,true],['Mes anterior',$prevMtd,$mtd,((float)$prevMtd['sales']>0||(float)$prevMtd['spend']>0)],['Mesmo mes ano passado',$yearMtd,$mtd,((float)$yearMtd['sales']>0||(float)$yearMtd['spend']>0)],['Media historica disponivel ('.$avg12Months.' meses)',$avg12,$mtd,$avg12Months>0]] as [$label,$snap,$against,$hasBase]): ?>
      <div class="context"><small><?=va_h($label)?></small><strong><?=$hasBase?va_money($snap['revenue']):'Sem base'?></strong><div class="context-line"><span><?=$hasBase?va_num($snap['sales']).' vendas &middot; ROAS '.va_num($snap['roas'],2):'Historico ainda indisponivel'?></span><?php if($against&&$hasBase):?><?=va_delta_html(metrics_delta((float)$against['revenue'],(float)$snap['revenue']))?><?php endif;?></div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="chart-grid">
    <section class="section-card"><div class="section-head"><div><h2>Receita, investimento e ROAS</h2><p>Evolucao diaria no periodo selecionado.</p></div></div><div class="chart-box"><canvas id="financeChart"></canvas></div></section>
    <section class="section-card"><div class="section-head"><div><h2>Leads e vendas</h2><p>Volume e conversao ao longo dos dias.</p></div></div><div class="chart-box"><canvas id="volumeChart"></canvas></div></section>
  </div>

  <section class="section-card"><div class="section-head"><div><h2>Evolucao dos ultimos 12 meses</h2><p>Bruto, liquido, liquido do produtor e quantidade de vendas.</p></div></div><div class="chart-box"><canvas id="monthlyChart"></canvas></div></section>

  <?php
    $windowLegend=$compareDays['x'].'d / '.$compareDays['y'].'d / '.$compareDays['z'].'d';
    $renderAdsMetrics=static function(array $metrics)use($compareDays,$adsMetricSource):void{
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'spend','money').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'leads').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'cpl','money').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'cpc','money').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'sales').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'cac','money').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'roas','decimal').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'cpm','money').'</td>';
      echo '<td class="ads-values">'.va_ads_cell($metrics,$compareDays,$adsMetricSource,'frequency','decimal').'</td>';
    };
  ?>
  <section class="section-card" id="ads-hierarchy">
    <div class="section-head"><div><h2>Campanhas, conjuntos e anúncios</h2><p><?= $adsMetricSource==='meta'?'Resultados informados pela Meta.':'Resultados reais cruzados por UTM e compra.' ?> CPM, CPC, frequência e gasto sempre vêm da Meta.</p></div></div>
    <form class="ads-controls" method="get" action="#ads-hierarchy">
      <?php foreach($_GET as $key=>$value):if(in_array((string)$key,['compare_x','compare_y','compare_z','ads_metric_source'],true)||!is_scalar($value))continue;?><input type="hidden" name="<?=va_h((string)$key)?>" value="<?=va_h((string)$value)?>"><?php endforeach;?>
      <div><label>Período X (dias)</label><input type="number" min="1" max="365" name="compare_x" value="<?=$compareDays['x']?>"></div>
      <div><label>Período Y (dias)</label><input type="number" min="1" max="365" name="compare_y" value="<?=$compareDays['y']?>"></div>
      <div><label>Período Z (dias)</label><input type="number" min="1" max="365" name="compare_z" value="<?=$compareDays['z']?>"></div>
      <div class="ads-source"><input type="checkbox" id="adsMetaMode" name="ads_metric_source" value="meta" <?=$adsMetricSource==='meta'?'checked':''?>><label for="adsMetaMode">Usar resultados apresentados pela Meta</label></div>
      <div class="fg-actions"><button class="btn btn-primary" type="submit">Aplicar</button></div>
    </form>
    <div class="ads-scroll"><table class="ads-table"><thead><tr><th>Campanha / conjunto / anúncio<div class="ads-head-note">Clique para expandir</div></th><?php foreach(['Gasto','Leads','CPL','CPC','Vendas','CAC','ROAS','CPM','Frequência'] as $head):?><th><?=$head?><div class="ads-head-note"><?=va_h($windowLegend)?></div></th><?php endforeach;?></tr></thead><tbody>
    <?php foreach($adsHierarchy['tree'] as $ci=>$campaign):$cid='camp-'.substr(md5((string)$ci),0,10);?>
      <tr data-row-id="<?=$cid?>"><td><div class="ads-name"><button type="button" class="ads-toggle" data-target="<?=$cid?>" aria-expanded="false">▶</button><div><strong><?=va_h($campaign['name'])?></strong><div class="ads-level">Campanha · <?=count($campaign['adsets'])?> conjuntos</div></div></div></td><?php $renderAdsMetrics($campaign['metrics']);?></tr>
      <?php foreach($campaign['adsets'] as $ai=>$adset):$aid=$cid.'-'.substr(md5((string)$ai),0,8);?>
        <tr data-row-id="<?=$aid?>" data-parent="<?=$cid?>" hidden><td><div class="ads-name ads-indent-1"><button type="button" class="ads-toggle" data-target="<?=$aid?>" aria-expanded="false">▶</button><div><strong><?=va_h($adset['name'])?></strong><div class="ads-level">Conjunto · <?=count($adset['ads'])?> anúncios</div></div></div></td><?php $renderAdsMetrics($adset['metrics']);?></tr>
        <?php foreach($adset['ads'] as $ad):?><tr data-parent="<?=$aid?>" hidden><td><div class="ads-name ads-indent-2"><span style="color:#22c55e">●</span><div><strong><?=va_h($ad['name'])?></strong><div class="ads-level">Anúncio</div></div></div></td><?php $renderAdsMetrics($ad['metrics']);?></tr><?php endforeach;?>
      <?php endforeach;?>
    <?php endforeach;?>
    <?php if(!$adsHierarchy['tree']):?><tr><td colspan="10" class="empty">Sem campanhas no período comparado.</td></tr><?php endif;?>
    </tbody></table></div>
  </section>

  <?php $tv=[];foreach(['x','y','z'] as $w)$tv[$w]=md_ads_metric_view($adsHierarchy['totals'][$w]??[],$adsMetricSource);?>
  <section class="section-card"><div class="section-head"><div><h2>Tendências de eficiência</h2><p>Comparação configurável dos indicadores consolidados.</p></div></div><div class="table-wrap"><table class="eff-table"><thead><tr><th>Comparativo</th><th>CAC</th><th>CPL</th><th>ROAS</th><th>CPM</th><th>Frequência</th><th>CPC</th></tr></thead><tbody>
    <?php foreach([['x','y'],['y','z']] as [$a,$b]):?><tr><td><strong><?=$compareDays[$a]?>d vs <?=$compareDays[$b]?>d</strong></td><td><?=va_compare_cell($tv[$a]['cac'],$tv[$b]['cac'],true)?></td><td><?=va_compare_cell($tv[$a]['cpl'],$tv[$b]['cpl'],true)?></td><td><?=va_compare_cell($tv[$a]['roas'],$tv[$b]['roas'],false,'decimal')?></td><td><?=va_compare_cell($tv[$a]['cpm'],$tv[$b]['cpm'],true)?></td><td><?=va_compare_cell($tv[$a]['frequency'],$tv[$b]['frequency'],true,'decimal')?></td><td><?=va_compare_cell($tv[$a]['cpc'],$tv[$b]['cpc'],true)?></td></tr><?php endforeach;?>
  </tbody></table></div></section>

  <div class="four-col">
    <section class="section-card"><div class="section-head"><div><h2>Formas de pagamento</h2><p>Vendas aprovadas por meio.</p></div></div><div class="bar-list"><?php $maxPay=max(array_column($breakdowns['payments'],'qty')?:[1]);foreach($breakdowns['payments'] as $r):?><div class="bar-row"><span><?=va_h($r['label'])?></span><div class="bar-track"><div class="bar-fill" style="width:<?=min(100,(float)$r['qty']/$maxPay*100)?>%"></div></div><strong><?=va_num($r['qty'])?></strong></div><?php endforeach;?><?php if(!$breakdowns['payments']):?><div class="empty">Sem dados.</div><?php endif;?></div></section>
    <section class="section-card"><div class="section-head"><div><h2>Parcelamento</h2><p>Distribuicao de parcelas.</p></div></div><div class="bar-list"><?php $maxInst=max(array_column($breakdowns['installments'],'qty')?:[1]);foreach($breakdowns['installments'] as $r):?><div class="bar-row"><span><?=va_h($r['label'])?></span><div class="bar-track"><div class="bar-fill" style="width:<?=min(100,(float)$r['qty']/$maxInst*100)?>%"></div></div><strong><?=va_num($r['qty'])?></strong></div><?php endforeach;?><?php if(!$breakdowns['installments']):?><div class="empty">Sem dados.</div><?php endif;?></div></section>
    <section class="section-card"><div class="section-head"><div><h2>Canal da venda</h2><p>Hotmart e futuras plataformas.</p></div></div><div class="bar-list"><?php $maxSource=max(array_column($breakdowns['sources'],'qty')?:[1]);foreach($breakdowns['sources'] as $r):?><div class="bar-row"><span><?=va_h(ucfirst($r['label']))?></span><div class="bar-track"><div class="bar-fill" style="width:<?=min(100,(float)$r['qty']/$maxSource*100)?>%"></div></div><strong><?=va_num($r['qty'])?></strong></div><?php endforeach;?></div></section>
    <section class="section-card"><div class="section-head"><div><h2>Qualidade da atribuicao</h2><p>Cobertura do cruzamento venda &rarr; lead.</p></div></div><div class="chart-box small"><canvas id="attributionChart"></canvas></div></section>
  </div>

  <div class="two-col">
    <section class="section-card"><div class="section-head"><div><h2>Desempenho por produto</h2><p>Faturamento e ticket por curso/oferta.</p></div></div><div class="table-wrap"><table class="bi-table"><thead><tr><th>Produto</th><th>Vendas</th><th>Bruto</th><th>Liquido produtor</th><th>Ticket</th></tr></thead><tbody><?php foreach($breakdowns['products'] as $r):?><tr><td><strong><?=va_h($r['label'])?></strong></td><td><?=va_num($r['sales'])?></td><td><?=va_money($r['gross'])?></td><td><?=va_money($r['producer'])?></td><td><?=va_money($r['ticket'])?></td></tr><?php endforeach;?><?php if(!$breakdowns['products']):?><tr><td colspan="5" class="empty">Sem vendas no periodo.</td></tr><?php endif;?></tbody></table></div></section>
    <section class="section-card"><div class="section-head"><div><h2>Conversao por turma</h2><p>Vendas do periodo divididas pelos leads historicos da turma atribuida.</p></div></div><div class="table-wrap"><table class="bi-table"><thead><tr><th>Turma</th><th>Leads historicos</th><th>Vendas no periodo</th><th>Conversao</th><th>Bruto</th><th>Liquido produtor</th></tr></thead><tbody><?php foreach($cohorts as $r):?><tr><td><strong><?=va_h($r['turma'])?></strong></td><td><?=va_num($r['leads'])?></td><td><?=va_num($r['sales'])?></td><td><?=va_pct($r['conversion'])?></td><td><?=va_money($r['gross'])?></td><td><?=va_money($r['producer'])?></td></tr><?php endforeach;?><?php if(!$cohorts):?><tr><td colspan="6" class="empty">Sem turmas atribuidas no periodo.</td></tr><?php endif;?></tbody></table></div></section>
  </div>

  <section class="section-card" id="nao-atribuidas">
    <div class="section-head"><div><h2>Vendas não atribuídas</h2><p>Vendas aprovadas sem lead vinculado no modelo <?=va_h($filters['model']==='first_touch'?'First touch':'Last touch')?>. Pesquise o lead e confirme a atribuição manual.</p></div></div>
    <?php if(isset($_GET['manual_ok'])):?><div class="manual-alert ok">Atribuição manual salva. O vínculo será preservado nas próximas sincronizações.</div><?php endif;?>
    <?php if(!empty($_GET['manual_err'])):?><div class="manual-alert err"><?=va_h((string)$_GET['manual_err'])?></div><?php endif;?>
    <div class="table-wrap"><table class="bi-table unattr-table"><thead><tr><th>Venda</th><th>Comprador</th><th>Produto</th><th>Valor</th><th>Atribuir ao lead</th></tr></thead><tbody>
    <?php foreach($unattributedRows as $sale):?>
      <tr><td><strong><?=va_h(date('d/m/Y H:i',strtotime((string)$sale['transaction_date'])))?></strong><div class="subtext"><?=va_h((string)$sale['transaction_code'])?></div></td><td><strong><?=va_h((string)$sale['buyer_name'])?></strong><div class="subtext"><?=va_h((string)$sale['buyer_email'])?></div><div class="subtext"><?=va_h((string)$sale['buyer_phone_raw'])?></div></td><td><strong><?=va_h((string)$sale['product_name'])?></strong><div class="subtext"><?=va_h((string)$sale['price_name'])?></div></td><td><strong><?=va_money($sale['gross_revenue'])?></strong><div class="subtext">Produtor: <?=va_money($sale['producer_net'])?></div></td><td>
        <form method="post" class="manual-attribution-form"><input type="hidden" name="acao" value="atribuir_venda_manual"><input type="hidden" name="csrf" value="<?=va_h((string)$_SESSION['sales_csrf'])?>"><input type="hidden" name="sale_id" value="<?=(int)$sale['id']?>"><input type="hidden" name="lead_id" value=""><input type="hidden" name="attribution_model" value="<?=va_h($filters['model'])?>"><input type="hidden" name="return_query" value="<?=va_h($manualReturnQuery)?>"><div class="lead-picker"><input type="search" class="lead-search" placeholder="Nome, e-mail, telefone ou ID" autocomplete="off"><div class="lead-results"></div><div class="lead-selected">Nenhum lead selecionado</div></div><div class="manual-form-actions"><button class="btn btn-primary" type="submit" disabled>Confirmar atribuição</button></div></form>
      </td></tr>
    <?php endforeach;?>
    <?php if(!$unattributedRows):?><tr><td colspan="5" class="empty">Todas as vendas aprovadas do período estão atribuídas.</td></tr><?php endif;?>
    </tbody></table></div>
  </section>

  <section class="section-card" id="lista-vendas">
    <div class="section-head"><div><h2>Relação detalhada de vendas</h2><p>Todas as transações recebidas no período, com comprador, valores, turma, atribuição e UTMs.</p></div></div>
    <form class="sales-tools" method="get" action="#lista-vendas">
      <?php foreach ($_GET as $key => $value): if (in_array((string)$key, ['sales_q','sales_status','sales_page'], true) || !is_scalar($value)) continue; ?>
        <input type="hidden" name="<?=va_h((string)$key)?>" value="<?=va_h((string)$value)?>">
      <?php endforeach; ?>
      <div><label>Buscar venda</label><input type="search" name="sales_q" value="<?=va_h($salesQuery)?>" placeholder="Nome, e-mail, telefone, produto ou transação"></div>
      <div><label>Status</label><select name="sales_status"><option value="all"<?=va_selected($salesStatus,'all')?>>Todos</option><option value="approved"<?=va_selected($salesStatus,'approved')?>>Aprovadas</option><option value="refunded"<?=va_selected($salesStatus,'refunded')?>>Reembolsos/chargebacks</option></select></div>
      <div class="fg-actions"><button class="btn btn-primary" type="submit">Filtrar lista</button></div>
    </form>
    <div class="table-wrap" style="margin-top:12px">
      <table class="bi-table sales-table">
        <thead><tr><th>Data / transação</th><th>Comprador</th><th>Produto / pagamento</th><th>Valores</th><th>Status</th><th>Turma / jornada</th><th>UTMs</th><th>Atribuição</th></tr></thead>
        <tbody>
        <?php foreach ($salesRows as $sale): ?>
          <?php $saleDate=(string)($sale['transaction_date'] ?: $sale['payment_confirmed_at'] ?: $sale['imported_at']); ?>
          <tr>
            <td><strong><?=va_h($saleDate ? date('d/m/Y H:i',strtotime($saleDate)) : '-')?></strong><div class="subtext"><?=va_h((string)$sale['transaction_code'])?></div><div class="subtext"><?=va_h((string)($sale['sales_channel'] ?: 'hotmart'))?></div></td>
            <td><strong><?=va_h((string)($sale['buyer_name'] ?: '-'))?></strong><div class="subtext"><?=va_h((string)$sale['buyer_email'])?></div><div class="subtext"><?=va_h((string)($sale['buyer_phone_raw'] ?: $sale['buyer_phone_norm']))?></div></td>
            <td><strong><?=va_h((string)($sale['product_name'] ?: 'Sem produto'))?></strong><div class="subtext"><?=va_h((string)($sale['price_name'] ?: $sale['price_code']))?></div><div class="subtext"><?=va_h((string)($sale['payment_type'] ?: 'Pagamento não informado'))?><?= (int)$sale['installments_number'] > 1 ? ' · '.(int)$sale['installments_number'].'x' : '' ?></div></td>
            <td class="sales-money"><strong>Bruto: <?=va_money($sale['gross_revenue'])?></strong><div class="subtext">Líquido: <?=va_money($sale['net_revenue'])?></div><div class="subtext">Produtor: <?=va_money($sale['producer_net'])?></div></td>
            <td><span class="sales-status"><?=va_h((string)($sale['status'] ?: $sale['webhook_event'] ?: '-'))?></span><?php if((float)$sale['refunded_value']>0):?><div class="subtext">Devolvido: <?=va_money($sale['refunded_value'])?></div><?php endif;?></td>
            <td><strong><?=va_h((string)$sale['turma_atribuida'])?></strong><?php if(!empty($sale['lead_created_at'])):?><div class="subtext">Inscrição: <?=va_h(date('d/m/Y H:i',strtotime((string)$sale['lead_created_at'])))?></div><?php endif;?><?php if((int)($sale['attribution_seconds_diff']??0)>0):?><div class="subtext">Até a compra: <?=va_h(va_duration($sale['attribution_seconds_diff']))?></div><?php endif;?></td>
            <td class="utm-stack"><strong><?=va_h((string)($sale['detail_utm_source'] ?: 'Orgânico/não informado'))?></strong><div class="subtext">Medium: <?=va_h((string)($sale['detail_utm_medium'] ?: '-'))?></div><div class="subtext">Campaign: <?=va_h((string)($sale['detail_utm_campaign'] ?: '-'))?></div><div class="subtext">Term: <?=va_h((string)($sale['detail_utm_term'] ?: '-'))?></div><div class="subtext">Content: <?=va_h((string)($sale['detail_utm_content'] ?: '-'))?></div></td>
            <td><strong><?=va_h((string)($sale['campaign_group'] ?: '-'))?></strong><div class="subtext"><?=va_h((string)($sale['campaign_name'] ?: '-'))?></div><div class="subtext">Anúncio: <?=va_h((string)($sale['ad_name'] ?: '-'))?></div><div class="subtext">Match: <?=va_h((string)($sale['match_type'] ?: $sale['match_method'] ?: 'não atribuído'))?></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$salesRows): ?><tr><td colspan="8" class="empty">Nenhuma venda encontrada com estes filtros.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="sales-pagination"><span><?=va_num($salesTotal)?> transações · página <?=$salesPage?> de <?=$salesPages?></span><div class="sales-pages"><?php if($salesPage>1):?><a href="?<?=va_h(http_build_query(array_merge($_GET,['sales_page'=>$salesPage-1])))?>#lista-vendas">Anterior</a><?php endif;?><span class="active"><?=$salesPage?></span><?php if($salesPage<$salesPages):?><a href="?<?=va_h(http_build_query(array_merge($_GET,['sales_page'=>$salesPage+1])))?>#lista-vendas">Próxima</a><?php endif;?></div></div>
  </section>
</div>

<script>
(function(){
document.querySelectorAll('.ads-toggle').forEach(btn=>btn.addEventListener('click',()=>{
  const id=btn.dataset.target,opening=btn.getAttribute('aria-expanded')!=='true';btn.setAttribute('aria-expanded',opening?'true':'false');btn.textContent=opening?'▼':'▶';
  document.querySelectorAll(`[data-parent="${id}"]`).forEach(row=>{row.hidden=!opening;if(!opening){const child=row.dataset.rowId;if(child){const childBtn=row.querySelector('.ads-toggle');if(childBtn){childBtn.setAttribute('aria-expanded','false');childBtn.textContent='▶';}document.querySelectorAll(`[data-parent="${child}"]`).forEach(r=>r.hidden=true);}}});
}));
document.querySelectorAll('.manual-attribution-form').forEach(form=>{
  const input=form.querySelector('.lead-search'),results=form.querySelector('.lead-results'),selected=form.querySelector('.lead-selected'),leadId=form.querySelector('input[name=lead_id]'),submit=form.querySelector('button[type=submit]');let timer;
  input.addEventListener('input',()=>{clearTimeout(timer);leadId.value='';submit.disabled=true;selected.textContent='Nenhum lead selecionado';const q=input.value.trim();if(q.length<2){results.style.display='none';return;}timer=setTimeout(async()=>{try{const url=new URL(window.location.href);url.search='';url.searchParams.set('ajax','lead_search');url.searchParams.set('q',q);const response=await fetch(url,{headers:{Accept:'application/json'}});const data=await response.json();results.innerHTML='';(data.rows||[]).forEach(lead=>{const option=document.createElement('button');option.type='button';option.className='lead-option';option.textContent=`${lead.lead_name||'Sem nome'} · ${lead.lead_email||lead.lead_phone_raw||'ID '+lead.source_user_id} · Turma ${lead.turma_codigo||'-'}`;option.addEventListener('click',()=>{leadId.value=lead.id;input.value=lead.lead_name||lead.lead_email||lead.source_user_id;selected.textContent=`Selecionado: ${option.textContent}`;submit.disabled=false;results.style.display='none';});results.appendChild(option);});results.style.display=(data.rows||[]).length?'block':'none';}catch(e){results.style.display='none';}},300);});
  form.addEventListener('submit',e=>{if(!leadId.value){e.preventDefault();}});
});
const daily=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const monthly=<?=json_encode($monthly,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const money=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}).format(v||0);
const grid='rgba(255,255,255,.06)',ticks='#64748b',legend={labels:{color:ticks,boxWidth:10,usePointStyle:true,font:{size:10}}};
const base={responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend},scales:{x:{ticks:{color:ticks,maxTicksLimit:12},grid:{display:false}},y:{ticks:{color:ticks},grid:{color:grid}}}};
const basis='<?=va_h($filters['basis'])?>'; const revKey=basis==='gross_revenue'?'gross':(basis==='net_revenue'?'net':'producer');
new Chart(document.getElementById('financeChart'),{data:{labels:daily.map(x=>x.date.slice(5)),datasets:[{type:'bar',label:'Investimento',data:daily.map(x=>x.spend),backgroundColor:'rgba(56,189,248,.35)',borderColor:'#38bdf8',borderWidth:1,borderRadius:3},{type:'line',label:'Receita',data:daily.map(x=>x[revKey]),borderColor:'#facc15',backgroundColor:'#facc15',tension:.3,pointRadius:2},{type:'line',label:'ROAS',data:daily.map(x=>x.spend>0?x[revKey]/x.spend:0),borderColor:'#22c55e',backgroundColor:'#22c55e',yAxisID:'roas',tension:.3,pointRadius:2}]},options:{...base,plugins:{...base.plugins,tooltip:{callbacks:{label:c=>c.dataset.label==='ROAS'?`ROAS: ${Number(c.raw).toFixed(2)}`:`${c.dataset.label}: ${money(c.raw)}`}}},scales:{...base.scales,roas:{position:'right',ticks:{color:'#22c55e'},grid:{drawOnChartArea:false}}}}});
new Chart(document.getElementById('volumeChart'),{data:{labels:daily.map(x=>x.date.slice(5)),datasets:[{type:'bar',label:'Leads',data:daily.map(x=>x.leads),backgroundColor:'rgba(168,85,247,.38)',borderRadius:3},{type:'line',label:'Vendas',data:daily.map(x=>x.sales),borderColor:'#fb7185',backgroundColor:'#fb7185',tension:.3,pointRadius:2}]},options:base});
new Chart(document.getElementById('monthlyChart'),{data:{labels:monthly.map(x=>x.month),datasets:[{type:'bar',label:'Bruto',data:monthly.map(x=>x.gross),backgroundColor:'rgba(250,204,21,.28)',borderRadius:3},{type:'bar',label:'Liquido',data:monthly.map(x=>x.net),backgroundColor:'rgba(56,189,248,.28)',borderRadius:3},{type:'line',label:'Liquido produtor',data:monthly.map(x=>x.producer),borderColor:'#22c55e',backgroundColor:'#22c55e',tension:.3},{type:'line',label:'Vendas',data:monthly.map(x=>x.sales),borderColor:'#fb7185',yAxisID:'qty',tension:.3}]},options:{...base,scales:{...base.scales,qty:{position:'right',ticks:{color:'#fb7185'},grid:{drawOnChartArea:false}}}}});
new Chart(document.getElementById('attributionChart'),{type:'doughnut',data:{labels:['Atribuidas','Nao atribuidas'],datasets:[{data:[<?= (int)$current['attributed_sales']?>,<?=max(0,(int)$current['sales']-(int)$current['attributed_sales'])?>],backgroundColor:['#22c55e','#334155'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{position:'bottom',labels:{color:ticks,boxWidth:10,font:{size:10}}}}}});
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
