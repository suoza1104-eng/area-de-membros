<?php
declare(strict_types=1);

require_once __DIR__ . '/metrics.php';

function md_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function md_row(PDO $pdo, string $sql, array $params = []): array
{
    $rows = md_rows($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function md_approved_sql(string $alias = 's'): string
{
    return "(UPPER(COALESCE({$alias}.webhook_event,'')) IN ('PURCHASE_APPROVED','PURCHASE_COMPLETE')
        OR UPPER(COALESCE({$alias}.status,'')) IN ('APROVADO','APPROVED','PURCHASE_APPROVED','COMPLETO','COMPLETE','COMPLETED','PURCHASE_COMPLETE','PAID'))";
}

function md_refund_sql(string $alias = 's'): string
{
    return "UPPER(COALESCE({$alias}.status,'')) IN ('REEMBOLSADO','REFUNDED','PURCHASE_REFUNDED','CHARGEBACK','PURCHASE_CHARGEBACK')";
}

function md_basis_column(string $basis): string
{
    return in_array($basis, ['gross_revenue','net_revenue','producer_net'], true) ? $basis : 'producer_net';
}

function md_filter_sql(array $filters, string $kind, array &$params): string
{
    $where = [];
    $campaign = trim((string)($filters['campaign'] ?? ''));
    $adset = trim((string)($filters['adset'] ?? ''));
    $product = trim((string)($filters['product'] ?? ''));
    $turma = trim((string)($filters['turma'] ?? ''));
    $model = ($filters['model'] ?? 'last_touch') === 'first_touch' ? 'first_touch' : 'last_touch';

    if ($kind === 'lead') {
        if ($campaign !== '') { $where[] = "COALESCE(NULLIF(l.utm_campaign_group,''),NULLIF(l.utm_source,''),'Organico') = :lead_campaign"; $params['lead_campaign'] = $campaign; }
        if ($adset !== '') { $where[] = "COALESCE(NULLIF(l.utm_campaign_name,''),'Sem conjunto') = :lead_adset"; $params['lead_adset'] = $adset; }
        if ($turma !== '') { $where[] = "COALESCE(NULLIF(l.turma_codigo,''),'Sem turma') = :lead_turma"; $params['lead_turma'] = $turma; }
    } elseif ($kind === 'sale') {
        if ($product !== '') { $where[] = 's.product_name = :sale_product'; $params['sale_product'] = $product; }
        if ($campaign !== '' || $adset !== '' || $turma !== '') {
            $exists = ["axs.source_sale_id = s.id", "am.attribution_model = :sale_model"];
            $params['sale_model'] = $model;
            if ($campaign !== '') { $exists[] = 'am.campaign_group = :sale_campaign'; $params['sale_campaign'] = $campaign; }
            if ($adset !== '') { $exists[] = 'am.campaign_name = :sale_adset'; $params['sale_adset'] = $adset; }
            if ($turma !== '') { $exists[] = "COALESCE(NULLIF(al.turma_codigo,''),'Sem turma') = :sale_turma"; $params['sale_turma'] = $turma; }
            $where[] = 'EXISTS (SELECT 1 FROM attribution_sales axs JOIN attribution_matches am ON am.sale_id=axs.id JOIN attribution_leads al ON al.id=am.lead_id WHERE ' . implode(' AND ', $exists) . ')';
        }
    } elseif ($kind === 'meta') {
        if ($campaign !== '') { $where[] = 'm.campaign_name = :meta_campaign'; $params['meta_campaign'] = $campaign; }
        if ($adset !== '') { $where[] = 'm.adset_name = :meta_adset'; $params['meta_adset'] = $adset; }
    }
    return $where ? ' AND ' . implode(' AND ', $where) : '';
}

function md_snapshot(PDO $pdo, string $start, string $end, array $filters): array
{
    $basis = md_basis_column((string)($filters['basis'] ?? 'producer_net'));
    $metaParams = ['start' => $start, 'end' => $end];
    $campaign = trim((string)($filters['campaign'] ?? ''));
    $adset = trim((string)($filters['adset'] ?? ''));
    if ($adset !== '') {
        $metaTable = 'meta_adset_daily';
        $metaFilter = md_filter_sql($filters, 'meta', $metaParams);
    } elseif ($campaign !== '') {
        $metaTable = 'meta_campaign_daily';
        $metaFilter = '';
        $metaParams['meta_campaign'] = $campaign;
        $metaFilter = ' AND m.campaign_name = :meta_campaign';
    } else {
        $metaTable = 'meta_account_daily';
        $metaFilter = '';
    }
    $meta = md_row($pdo, "SELECT COALESCE(SUM(m.spend),0) spend, COALESCE(SUM(m.impressions),0) impressions,
              COALESCE(SUM(m.reach),0) reach, COALESCE(SUM(m.clicks),0) clicks,
              COALESCE(SUM(m.inline_link_clicks),0) link_clicks,
              COALESCE(SUM(m.landing_page_views),0) landing_views,
              COALESCE(SUM(m.leads),0) meta_leads,
              CASE WHEN SUM(m.impressions)>0 THEN SUM(m.spend)/SUM(m.impressions)*1000 ELSE 0 END cpm,
              CASE WHEN SUM(m.clicks)>0 THEN SUM(m.spend)/SUM(m.clicks) ELSE 0 END cpc,
              CASE WHEN SUM(m.impressions)>0 THEN SUM(m.clicks)/SUM(m.impressions)*100 ELSE 0 END ctr,
              CASE WHEN SUM(m.reach)>0 THEN SUM(m.impressions)/SUM(m.reach) ELSE 0 END frequency
            FROM {$metaTable} m WHERE m.report_date BETWEEN :start AND :end{$metaFilter}", $metaParams);

    $leadParams = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59'];
    $leadFilter = md_filter_sql($filters, 'lead', $leadParams);
    $leads = md_row($pdo, "SELECT COUNT(*) leads FROM attribution_leads l WHERE l.created_at BETWEEN :start AND :end{$leadFilter}", $leadParams);

    $saleParams = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59'];
    $saleFilter = md_filter_sql($filters, 'sale', $saleParams);
    $sales = md_row($pdo, "SELECT COUNT(*) sales, COUNT(DISTINCT s.matched_user_id) buyers,
              COALESCE(SUM(s.gross_revenue),0) gross_revenue,
              COALESCE(SUM(s.net_revenue),0) net_revenue,
              COALESCE(SUM(s.producer_net),0) producer_net,
              COALESCE(AVG(s.gross_revenue),0) average_ticket,
              SUM(s.matched_user_id IS NOT NULL) matched_sales
            FROM hotmart_sales_live s
            WHERE " . md_approved_sql('s') . "
              AND COALESCE(s.transaction_date,s.payment_confirmed_at) BETWEEN :start AND :end{$saleFilter}", $saleParams);

    $attrParams = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59', 'model' => ($filters['model'] ?? 'last_touch') === 'first_touch' ? 'first_touch' : 'last_touch'];
    $attrWhere = [];
    if (!empty($filters['campaign'])) { $attrWhere[] = 'am.campaign_group=:ac'; $attrParams['ac'] = $filters['campaign']; }
    if (!empty($filters['adset'])) { $attrWhere[] = 'am.campaign_name=:aa'; $attrParams['aa'] = $filters['adset']; }
    if (!empty($filters['product'])) { $attrWhere[] = 'axs.product_name=:ap'; $attrParams['ap'] = $filters['product']; }
    if (!empty($filters['turma'])) { $attrWhere[] = "COALESCE(NULLIF(al.turma_codigo,''),'Sem turma')=:at"; $attrParams['at'] = $filters['turma']; }
    $attrExtra = $attrWhere ? ' AND ' . implode(' AND ', $attrWhere) : '';
    $attr = md_row($pdo, "SELECT COUNT(DISTINCT hs.transaction_code) attributed_sales,
              COALESCE(SUM(hs.{$basis}),0) attributed_revenue
            FROM attribution_matches am
            JOIN attribution_sales axs ON axs.id=am.sale_id
            JOIN hotmart_sales_live hs ON hs.transaction_code=axs.transaction_code
            JOIN attribution_leads al ON al.id=am.lead_id
            WHERE am.attribution_model=:model
              AND " . md_approved_sql('hs') . "
              AND COALESCE(hs.transaction_date,hs.payment_confirmed_at) BETWEEN :start AND :end{$attrExtra}", $attrParams);

    $refundParams = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59'];
    $refundFilter = md_filter_sql($filters, 'sale', $refundParams);
    $refunds = md_row($pdo, "SELECT COUNT(*) refunds,
              COALESCE(SUM(CASE WHEN s.refunded_value>0 THEN s.refunded_value ELSE s.gross_revenue END),0) refunded_value
            FROM hotmart_sales_live s WHERE " . md_refund_sql('s') . "
              AND COALESCE(s.refund_or_chargeback_at,s.updated_at,s.transaction_date) BETWEEN :start AND :end{$refundFilter}", $refundParams);

    $out = array_merge([
        'spend'=>0,'impressions'=>0,'reach'=>0,'clicks'=>0,'link_clicks'=>0,'landing_views'=>0,'meta_leads'=>0,
        'cpm'=>0,'cpc'=>0,'ctr'=>0,'frequency'=>0,'leads'=>0,'sales'=>0,'buyers'=>0,
        'gross_revenue'=>0,'net_revenue'=>0,'producer_net'=>0,'average_ticket'=>0,'matched_sales'=>0,
        'attributed_sales'=>0,'attributed_revenue'=>0,'refunds'=>0,'refunded_value'=>0,
    ], $meta, $leads, $sales, $attr, $refunds);
    foreach ($out as $key => $value) if (is_numeric($value)) $out[$key] = (float)$value;
    $revenue = (float)$out[$basis];
    $out['revenue'] = $revenue;
    $out['fees'] = max(0.0, (float)$out['gross_revenue'] - (float)$out['producer_net']);
    $out['conversion_rate'] = $out['leads'] > 0 ? $out['sales'] / $out['leads'] * 100 : 0;
    $out['cpl'] = $out['leads'] > 0 ? $out['spend'] / $out['leads'] : 0;
    $out['cac'] = $out['sales'] > 0 ? $out['spend'] / $out['sales'] : 0;
    $out['roas'] = $out['spend'] > 0 ? $revenue / $out['spend'] : 0;
    $out['refund_rate'] = ($out['sales'] + $out['refunds']) > 0 ? $out['refunds'] / ($out['sales'] + $out['refunds']) * 100 : 0;
    $out['attribution_rate'] = $out['sales'] > 0 ? $out['attributed_sales'] / $out['sales'] * 100 : 0;
    return $out;
}

function md_daily_series(PDO $pdo, string $start, string $end, array $filters): array
{
    $days = [];
    $cursor = new DateTimeImmutable($start); $last = new DateTimeImmutable($end);
    while ($cursor <= $last) { $days[$cursor->format('Y-m-d')] = ['date'=>$cursor->format('Y-m-d'),'spend'=>0,'leads'=>0,'sales'=>0,'gross'=>0,'net'=>0,'producer'=>0]; $cursor=$cursor->modify('+1 day'); }

    $mParams=['start'=>$start,'end'=>$end]; $campaign=trim((string)($filters['campaign']??'')); $adset=trim((string)($filters['adset']??''));
    if ($adset!=='') { $table='meta_adset_daily'; $extra=md_filter_sql($filters,'meta',$mParams); }
    elseif ($campaign!=='') { $table='meta_campaign_daily'; $mParams['campaign']=$campaign; $extra=' AND m.campaign_name=:campaign'; }
    else { $table='meta_account_daily'; $extra=''; }
    foreach (md_rows($pdo,"SELECT m.report_date d,SUM(m.spend) spend FROM {$table} m WHERE m.report_date BETWEEN :start AND :end{$extra} GROUP BY m.report_date",$mParams) as $r) if(isset($days[$r['d']])) $days[$r['d']]['spend']=(float)$r['spend'];

    $lParams=['start'=>$start.' 00:00:00','end'=>$end.' 23:59:59']; $lf=md_filter_sql($filters,'lead',$lParams);
    foreach(md_rows($pdo,"SELECT DATE(l.created_at) d,COUNT(*) qty FROM attribution_leads l WHERE l.created_at BETWEEN :start AND :end{$lf} GROUP BY DATE(l.created_at)",$lParams) as $r) if(isset($days[$r['d']])) $days[$r['d']]['leads']=(int)$r['qty'];

    $sParams=['start'=>$start.' 00:00:00','end'=>$end.' 23:59:59']; $sf=md_filter_sql($filters,'sale',$sParams);
    foreach(md_rows($pdo,"SELECT DATE(COALESCE(s.transaction_date,s.payment_confirmed_at)) d,COUNT(*) qty,SUM(s.gross_revenue) gross,SUM(s.net_revenue) net,SUM(s.producer_net) producer FROM hotmart_sales_live s WHERE ".md_approved_sql('s')." AND COALESCE(s.transaction_date,s.payment_confirmed_at) BETWEEN :start AND :end{$sf} GROUP BY DATE(COALESCE(s.transaction_date,s.payment_confirmed_at))",$sParams) as $r) if(isset($days[$r['d']])) { $days[$r['d']]['sales']=(int)$r['qty']; $days[$r['d']]['gross']=(float)$r['gross']; $days[$r['d']]['net']=(float)$r['net']; $days[$r['d']]['producer']=(float)$r['producer']; }
    return array_values($days);
}

function md_monthly_series(PDO $pdo, array $filters): array
{
    $end=(new DateTimeImmutable('last day of this month'))->format('Y-m-d').' 23:59:59';
    $start=(new DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m-d').' 00:00:00';
    $params=['start'=>$start,'end'=>$end]; $sf=md_filter_sql($filters,'sale',$params);
    return md_rows($pdo,"SELECT DATE_FORMAT(COALESCE(s.transaction_date,s.payment_confirmed_at),'%Y-%m') month,COUNT(*) sales,SUM(s.gross_revenue) gross,SUM(s.net_revenue) net,SUM(s.producer_net) producer FROM hotmart_sales_live s WHERE ".md_approved_sql('s')." AND COALESCE(s.transaction_date,s.payment_confirmed_at) BETWEEN :start AND :end{$sf} GROUP BY month ORDER BY month",$params);
}

function md_breakdowns(PDO $pdo, string $start, string $end, array $filters): array
{
    $params=['start'=>$start.' 00:00:00','end'=>$end.' 23:59:59']; $sf=md_filter_sql($filters,'sale',$params);
    $base=" FROM hotmart_sales_live s WHERE ".md_approved_sql('s')." AND COALESCE(s.transaction_date,s.payment_confirmed_at) BETWEEN :start AND :end{$sf}";
    $payments=md_rows($pdo,"SELECT COALESCE(NULLIF(s.payment_type,''),'Nao informado') label,COUNT(*) qty,SUM(s.gross_revenue) gross,SUM(s.producer_net) producer{$base} GROUP BY label ORDER BY qty DESC",$params);
    $installments=md_rows($pdo,"SELECT CASE WHEN s.installments_number IS NULL OR s.installments_number=0 THEN 'Nao informado' WHEN s.installments_number=1 THEN 'A vista' ELSE CONCAT(s.installments_number,'x') END label,COUNT(*) qty,SUM(s.gross_revenue) gross{$base} GROUP BY label ORDER BY qty DESC",$params);
    $products=md_rows($pdo,"SELECT COALESCE(NULLIF(s.product_name,''),'Sem produto') label,COUNT(*) sales,SUM(s.gross_revenue) gross,SUM(s.producer_net) producer,AVG(s.gross_revenue) ticket{$base} GROUP BY label ORDER BY producer DESC LIMIT 20",$params);
    $sources=md_rows($pdo,"SELECT COALESCE(NULLIF(s.sales_channel,''),'hotmart') label,COUNT(*) qty,SUM(s.gross_revenue) gross{$base} GROUP BY label ORDER BY qty DESC",$params);
    return ['payments'=>$payments,'installments'=>$installments,'products'=>$products,'sources'=>$sources];
}

function md_cohorts(PDO $pdo, string $start, string $end, array $filters): array
{
    $leadParams=[];
    $leadFilter=md_filter_sql($filters,'lead',$leadParams);
    $leadFilter=$leadFilter!==''?' WHERE '.substr($leadFilter,5):'';
    $leads=md_rows($pdo,"SELECT COALESCE(NULLIF(l.turma_codigo,''),'Sem turma') turma,COUNT(*) leads FROM attribution_leads l{$leadFilter} GROUP BY turma",$leadParams);
    $leadMap=[]; foreach($leads as $r)$leadMap[$r['turma']]=(int)$r['leads'];
    $params=['start'=>$start.' 00:00:00','end'=>$end.' 23:59:59','model'=>($filters['model']??'last_touch')==='first_touch'?'first_touch':'last_touch'];
    $rows=md_rows($pdo,"SELECT COALESCE(NULLIF(al.turma_codigo,''),'Sem turma') turma,COUNT(DISTINCT hs.transaction_code) sales,SUM(hs.gross_revenue) gross,SUM(hs.producer_net) producer FROM attribution_matches am JOIN attribution_sales axs ON axs.id=am.sale_id JOIN hotmart_sales_live hs ON hs.transaction_code=axs.transaction_code JOIN attribution_leads al ON al.id=am.lead_id WHERE am.attribution_model=:model AND ".md_approved_sql('hs')." AND COALESCE(hs.transaction_date,hs.payment_confirmed_at) BETWEEN :start AND :end GROUP BY turma ORDER BY producer DESC LIMIT 30",$params);
    foreach($rows as &$r){$r['leads']=$leadMap[$r['turma']]??0;$r['conversion']=$r['leads']>0?(int)$r['sales']/(int)$r['leads']*100:0;} unset($r);
    return $rows;
}

function md_adset_performance(PDO $pdo): array
{
    $start90=(new DateTimeImmutable('today'))->modify('-89 days')->format('Y-m-d');
    $start30=(new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
    $start7=(new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
    $end=(new DateTimeImmutable('today'))->format('Y-m-d');
    $meta=md_rows($pdo,"SELECT campaign_name,adset_name,
      SUM(CASE WHEN report_date>=:d7 THEN spend ELSE 0 END) spend7,SUM(CASE WHEN report_date>=:d7 THEN leads ELSE 0 END) leads7,
      SUM(CASE WHEN report_date>=:d30 THEN spend ELSE 0 END) spend30,SUM(CASE WHEN report_date>=:d30 THEN leads ELSE 0 END) leads30,
      SUM(spend) spend90,SUM(leads) leads90
      FROM meta_adset_daily WHERE report_date BETWEEN :d90 AND :end GROUP BY campaign_name,adset_name HAVING spend90>0 ORDER BY spend30 DESC LIMIT 80",['d7'=>$start7,'d30'=>$start30,'d90'=>$start90,'end'=>$end]);
    $attrs=md_rows($pdo,"SELECT am.campaign_group, am.campaign_name,
      COUNT(DISTINCT CASE WHEN hs.transaction_date>=:d7 THEN hs.transaction_code END) sales7,SUM(CASE WHEN hs.transaction_date>=:d7 THEN hs.producer_net ELSE 0 END) revenue7,
      COUNT(DISTINCT CASE WHEN hs.transaction_date>=:d30 THEN hs.transaction_code END) sales30,SUM(CASE WHEN hs.transaction_date>=:d30 THEN hs.producer_net ELSE 0 END) revenue30,
      COUNT(DISTINCT hs.transaction_code) sales90,SUM(hs.producer_net) revenue90
      FROM attribution_matches am JOIN attribution_sales axs ON axs.id=am.sale_id JOIN hotmart_sales_live hs ON hs.transaction_code=axs.transaction_code
      WHERE am.attribution_model='last_touch' AND ".md_approved_sql('hs')." AND hs.transaction_date BETWEEN :d90 AND :enddt GROUP BY am.campaign_group,am.campaign_name",['d7'=>$start7.' 00:00:00','d30'=>$start30.' 00:00:00','d90'=>$start90.' 00:00:00','enddt'=>$end.' 23:59:59']);
    $map=[];foreach($attrs as $r)$map[normalize_text_value($r['campaign_group']).'|'.normalize_text_value($r['campaign_name'])]=$r;
    foreach($meta as &$r){$a=$map[normalize_text_value($r['campaign_name']).'|'.normalize_text_value($r['adset_name'])]??[];foreach(['sales7','sales30','sales90','revenue7','revenue30','revenue90'] as $k)$r[$k]=(float)($a[$k]??0);foreach([7,30,90] as $w){$sp=(float)$r['spend'.$w];$ld=(float)$r['leads'.$w];$sa=(float)$r['sales'.$w];$rv=(float)$r['revenue'.$w];$r['cpl'.$w]=$ld>0?$sp/$ld:0;$r['cac'.$w]=$sa>0?$sp/$sa:0;$r['roas'.$w]=$sp>0?$rv/$sp:0;}$r['trend']=($r['roas7']>=$r['roas30']?'up':'down');$r['resilient']=$r['sales30']>=2&&$r['roas7']>=max(.8,$r['roas30']*.8)&&$r['roas30']>=max(.8,$r['roas90']*.8);}unset($r);
    usort($meta,function($a,$b){return ($b['roas30']<=>$a['roas30'])?:($b['sales30']<=>$a['sales30']);});
    return array_slice($meta,0,30);
}

function md_filter_options(PDO $pdo): array
{
    $products=array_column(md_rows($pdo,"SELECT DISTINCT product_name v FROM hotmart_sales_live WHERE product_name IS NOT NULL AND product_name<>'' ORDER BY v"),'v');
    $campaigns=array_column(md_rows($pdo,"SELECT DISTINCT campaign_name v FROM meta_campaign_daily WHERE campaign_name<>'' ORDER BY v"),'v');
    $adsets=array_column(md_rows($pdo,"SELECT DISTINCT adset_name v FROM meta_adset_daily WHERE adset_name<>'' ORDER BY v"),'v');
    $turmas=array_column(md_rows($pdo,"SELECT DISTINCT NULLIF(turma_codigo,'') v FROM attribution_leads HAVING v IS NOT NULL ORDER BY v"),'v');
    return compact('products','campaigns','adsets','turmas');
}

function md_ads_empty_metrics(array $windows): array
{
    $out=[];
    foreach(array_keys($windows) as $key)$out[$key]=['spend'=>0.0,'impressions'=>0,'reach'=>0,'clicks'=>0,'meta_leads'=>0,'meta_sales'=>0,'meta_revenue'=>0.0,'meta_roas_revenue'=>0.0,'cross_leads'=>0,'cross_sales'=>0,'cross_revenue'=>0.0];
    return $out;
}

function md_ads_merge_metrics(array &$target,array $source): void
{
    foreach($source as $window=>$values)foreach($values as $key=>$value)$target[$window][$key]=($target[$window][$key]??0)+$value;
}

function md_ads_hierarchy(PDO $pdo,string $endDate,string $model,array $windowDays): array
{
    $model=$model==='first_touch'?'first_touch':'last_touch';
    $windows=[];$end=new DateTimeImmutable($endDate);
    foreach($windowDays as $key=>$days){$days=max(1,min(365,(int)$days));$windows[$key]=$end->modify('-'.($days-1).' days')->format('Y-m-d');}
    $start=min($windows);$leaves=[];
    $ensure=function(string $campaign,string $adset,string $ad)use(&$leaves,$windows):string{
        $campaign=trim($campaign)?:'Sem campanha';$adset=trim($adset)?:'Sem conjunto';$ad=trim($ad)?:'Sem anúncio';
        $key=normalize_match_key($campaign).'|'.normalize_match_key($adset).'|'.normalize_match_key($ad);
        if(!isset($leaves[$key]))$leaves[$key]=['campaign'=>$campaign,'adset'=>$adset,'ad'=>$ad,'metrics'=>md_ads_empty_metrics($windows)];
        return $key;
    };
    $windowKeys=function(string $date)use($windows):array{$out=[];foreach($windows as $key=>$from)if($date>=$from)$out[]=$key;return $out;};

    $meta=md_rows($pdo,"SELECT report_date,campaign_name,adset_name,ad_name,SUM(spend) spend,SUM(impressions) impressions,SUM(reach) reach,SUM(clicks) clicks,SUM(leads) leads,SUM(purchases) purchases,SUM(purchase_value) purchase_value,SUM(purchase_roas*spend) meta_roas_revenue FROM meta_ad_daily WHERE report_date BETWEEN :start AND :end GROUP BY report_date,campaign_name,adset_name,ad_name",['start'=>$start,'end'=>$endDate]);
    foreach($meta as $r){$key=$ensure((string)$r['campaign_name'],(string)$r['adset_name'],(string)$r['ad_name']);foreach($windowKeys((string)$r['report_date']) as $w){$m=&$leaves[$key]['metrics'][$w];$m['spend']+=(float)$r['spend'];$m['impressions']+=(int)$r['impressions'];$m['reach']+=(int)$r['reach'];$m['clicks']+=(int)$r['clicks'];$m['meta_leads']+=(int)$r['leads'];$m['meta_sales']+=(int)$r['purchases'];$m['meta_revenue']+=(float)$r['purchase_value'];$m['meta_roas_revenue']+=(float)$r['meta_roas_revenue'];unset($m);}}

    $leads=md_rows($pdo,"SELECT DATE(created_at) report_date,utm_campaign_group campaign,utm_campaign_name adset,utm_ad_name ad,COUNT(*) qty FROM attribution_leads WHERE created_at BETWEEN :start AND :end GROUP BY DATE(created_at),utm_campaign_group_norm,utm_campaign_name_norm,utm_ad_name_norm,utm_campaign_group,utm_campaign_name,utm_ad_name",['start'=>$start.' 00:00:00','end'=>$endDate.' 23:59:59']);
    foreach($leads as $r){$key=$ensure((string)$r['campaign'],(string)$r['adset'],(string)$r['ad']);foreach($windowKeys((string)$r['report_date']) as $w)$leaves[$key]['metrics'][$w]['cross_leads']+=(int)$r['qty'];}

    $sales=md_rows($pdo,"SELECT DATE(sale_date) report_date,campaign_group campaign,campaign_name adset,ad_name ad,COUNT(*) qty,SUM(revenue_value) revenue FROM attribution_matches WHERE attribution_model=:model AND sale_date BETWEEN :start AND :end GROUP BY DATE(sale_date),campaign_group_norm,campaign_name_norm,ad_name_norm,campaign_group,campaign_name,ad_name",['model'=>$model,'start'=>$start.' 00:00:00','end'=>$endDate.' 23:59:59']);
    foreach($sales as $r){$key=$ensure((string)$r['campaign'],(string)$r['adset'],(string)$r['ad']);foreach($windowKeys((string)$r['report_date']) as $w){$leaves[$key]['metrics'][$w]['cross_sales']+=(int)$r['qty'];$leaves[$key]['metrics'][$w]['cross_revenue']+=(float)$r['revenue'];}}

    $tree=[];
    foreach($leaves as $leaf){$c=$leaf['campaign'];$a=$leaf['adset'];if(!isset($tree[$c]))$tree[$c]=['name'=>$c,'metrics'=>md_ads_empty_metrics($windows),'adsets'=>[]];if(!isset($tree[$c]['adsets'][$a]))$tree[$c]['adsets'][$a]=['name'=>$a,'metrics'=>md_ads_empty_metrics($windows),'ads'=>[]];$tree[$c]['adsets'][$a]['ads'][]=['name'=>$leaf['ad'],'metrics'=>$leaf['metrics']];md_ads_merge_metrics($tree[$c]['adsets'][$a]['metrics'],$leaf['metrics']);md_ads_merge_metrics($tree[$c]['metrics'],$leaf['metrics']);}
    $metaFields=['spend','impressions','reach','clicks','meta_leads','meta_sales','meta_revenue','meta_roas_revenue'];
    $loadLevel=function(string $table,string $selectNames,string $groupNames)use($pdo,$start,$endDate,$windows,$windowKeys):array{$map=[];$rows=md_rows($pdo,"SELECT report_date,{$selectNames},SUM(spend) spend,SUM(impressions) impressions,SUM(reach) reach,SUM(clicks) clicks,SUM(leads) meta_leads,SUM(purchases) meta_sales,SUM(purchase_value) meta_revenue,SUM(purchase_roas*spend) meta_roas_revenue FROM {$table} WHERE report_date BETWEEN :start AND :end GROUP BY report_date,{$groupNames}",['start'=>$start,'end'=>$endDate]);foreach($rows as $r){$key=normalize_match_key((string)$r['campaign_name']).(isset($r['adset_name'])?'|'.normalize_match_key((string)$r['adset_name']):'');if(!isset($map[$key]))$map[$key]=md_ads_empty_metrics($windows);foreach($windowKeys((string)$r['report_date']) as $w)foreach(['spend','impressions','reach','clicks','meta_leads','meta_sales','meta_revenue','meta_roas_revenue'] as $field)$map[$key][$w][$field]+=(float)$r[$field];}return $map;};
    $campaignMeta=$loadLevel('meta_campaign_daily','campaign_name','campaign_name');$adsetMeta=$loadLevel('meta_adset_daily','campaign_name,adset_name','campaign_name,adset_name');
    foreach($tree as &$campaign){$ck=normalize_match_key((string)$campaign['name']);if(isset($campaignMeta[$ck]))foreach($windows as $w=>$unused)foreach($metaFields as $field)$campaign['metrics'][$w][$field]=$campaignMeta[$ck][$w][$field];foreach($campaign['adsets'] as &$adset){$ak=$ck.'|'.normalize_match_key((string)$adset['name']);if(isset($adsetMeta[$ak]))foreach($windows as $w=>$unused)foreach($metaFields as $field)$adset['metrics'][$w][$field]=$adsetMeta[$ak][$w][$field];}unset($adset);}unset($campaign);
    $totals=md_ads_empty_metrics($windows);foreach($tree as $campaign)md_ads_merge_metrics($totals,$campaign['metrics']);
    $accountRows=md_rows($pdo,"SELECT report_date,SUM(spend) spend,SUM(impressions) impressions,SUM(reach) reach,SUM(clicks) clicks,SUM(leads) meta_leads,SUM(purchases) meta_sales,SUM(purchase_value) meta_revenue,SUM(purchase_roas*spend) meta_roas_revenue FROM meta_account_daily WHERE report_date BETWEEN :start AND :end GROUP BY report_date",['start'=>$start,'end'=>$endDate]);$accountMetrics=md_ads_empty_metrics($windows);foreach($accountRows as $r)foreach($windowKeys((string)$r['report_date']) as $w)foreach($metaFields as $field)$accountMetrics[$w][$field]+=(float)$r[$field];foreach($windows as $w=>$unused)foreach($metaFields as $field)$totals[$w][$field]=$accountMetrics[$w][$field];
    $sortMetric=static fn($x):float=>(float)($x['metrics']['x']['spend']??0);
    uasort($tree,static fn($a,$b)=>$sortMetric($b)<=>$sortMetric($a));
    foreach($tree as &$campaign){uasort($campaign['adsets'],static fn($a,$b)=>$sortMetric($b)<=>$sortMetric($a));foreach($campaign['adsets'] as &$adset)usort($adset['ads'],static fn($a,$b)=>$sortMetric($b)<=>$sortMetric($a));unset($adset);}unset($campaign);
    return ['windows'=>$windows,'tree'=>$tree,'totals'=>$totals];
}

function md_ads_metric_view(array $metrics,string $source): array
{
    $spend=(float)($metrics['spend']??0);$impressions=(int)($metrics['impressions']??0);$reach=(int)($metrics['reach']??0);$clicks=(int)($metrics['clicks']??0);$meta=$source==='meta';
    $leads=(int)($metrics[$meta?'meta_leads':'cross_leads']??0);$sales=(int)($metrics[$meta?'meta_sales':'cross_sales']??0);$revenue=(float)($metrics[$meta?'meta_roas_revenue':'cross_revenue']??0);
    return ['spend'=>$spend,'leads'=>$leads,'sales'=>$sales,'revenue'=>$revenue,'cpl'=>$leads>0?$spend/$leads:0,'cac'=>$sales>0?$spend/$sales:0,'roas'=>$spend>0?$revenue/$spend:0,'cpc'=>$clicks>0?$spend/$clicks:0,'cpm'=>$impressions>0?$spend/$impressions*1000:0,'frequency'=>$reach>0?$impressions/$reach:0];
}
