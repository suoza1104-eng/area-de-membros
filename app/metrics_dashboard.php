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

function md_sale_revenue_date_sql(string $alias = 's'): string
{
    return "COALESCE({$alias}.payment_confirmed_at,{$alias}.transaction_date)";
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
              AND " . md_sale_revenue_date_sql('s') . " BETWEEN :start AND :end{$saleFilter}", $saleParams);

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
              AND " . md_sale_revenue_date_sql('hs') . " BETWEEN :start AND :end{$attrExtra}", $attrParams);

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
    $out['profit'] = (float)$out['net_revenue'] - (float)$out['spend'];
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
    $saleDateExpr=md_sale_revenue_date_sql('s');
    foreach(md_rows($pdo,"SELECT DATE({$saleDateExpr}) d,COUNT(*) qty,SUM(s.gross_revenue) gross,SUM(s.net_revenue) net,SUM(s.producer_net) producer FROM hotmart_sales_live s WHERE ".md_approved_sql('s')." AND {$saleDateExpr} BETWEEN :start AND :end{$sf} GROUP BY DATE({$saleDateExpr})",$sParams) as $r) if(isset($days[$r['d']])) { $days[$r['d']]['sales']=(int)$r['qty']; $days[$r['d']]['gross']=(float)$r['gross']; $days[$r['d']]['net']=(float)$r['net']; $days[$r['d']]['producer']=(float)$r['producer']; }
    return array_values($days);
}

function md_monthly_series(PDO $pdo, array $filters): array
{
    $end=(new DateTimeImmutable('last day of this month'))->format('Y-m-d').' 23:59:59';
    $start=(new DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m-d').' 00:00:00';
    $params=['start'=>$start,'end'=>$end]; $sf=md_filter_sql($filters,'sale',$params);
    $saleDateExpr=md_sale_revenue_date_sql('s');
    return md_rows($pdo,"SELECT DATE_FORMAT({$saleDateExpr},'%Y-%m') month,COUNT(*) sales,SUM(s.gross_revenue) gross,SUM(s.net_revenue) net,SUM(s.producer_net) producer FROM hotmart_sales_live s WHERE ".md_approved_sql('s')." AND {$saleDateExpr} BETWEEN :start AND :end{$sf} GROUP BY month ORDER BY month",$params);
}

function md_breakdowns(PDO $pdo, string $start, string $end, array $filters): array
{
    $params=['start'=>$start.' 00:00:00','end'=>$end.' 23:59:59']; $sf=md_filter_sql($filters,'sale',$params);
    $saleDateExpr=md_sale_revenue_date_sql('s');
    $base=" FROM hotmart_sales_live s WHERE ".md_approved_sql('s')." AND {$saleDateExpr} BETWEEN :start AND :end{$sf}";
    $payments=md_rows($pdo,"SELECT COALESCE(NULLIF(s.payment_type,''),'Nao informado') label,COUNT(*) qty,SUM(s.gross_revenue) gross,SUM(s.producer_net) producer{$base} GROUP BY label ORDER BY qty DESC",$params);
    $installments=md_rows($pdo,"SELECT CASE WHEN s.installments_number IS NULL OR s.installments_number=0 THEN 'Nao informado' WHEN s.installments_number=1 THEN 'A vista' ELSE CONCAT(s.installments_number,'x') END label,COUNT(*) qty,SUM(s.gross_revenue) gross{$base} GROUP BY label ORDER BY qty DESC",$params);
    $products=md_rows($pdo,"SELECT COALESCE(NULLIF(s.product_name,''),'Sem produto') label,COUNT(*) sales,SUM(s.gross_revenue) gross,SUM(s.producer_net) producer,AVG(s.gross_revenue) ticket{$base} GROUP BY label ORDER BY producer DESC LIMIT 20",$params);
    $sources=md_rows($pdo,"SELECT COALESCE(NULLIF(s.sales_channel,''),'hotmart') label,COUNT(*) qty,SUM(s.gross_revenue) gross{$base} GROUP BY label ORDER BY qty DESC",$params);
    return ['payments'=>$payments,'installments'=>$installments,'products'=>$products,'sources'=>$sources];
}

function md_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}`");
        $stmt->execute();
        $cache[$table] = array_fill_keys(array_map(static fn($r) => (string)$r['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []), true);
    } catch (Throwable $e) {
        $cache[$table] = [];
    }
    return $cache[$table];
}

function md_pick_column(PDO $pdo, string $table, array $candidates): string
{
    $cols = md_table_columns($pdo, $table);
    foreach ($candidates as $column) {
        if (isset($cols[$column])) return $column;
    }
    return '';
}

function md_in_params(array $values, string $prefix, array &$params): string
{
    $placeholders = [];
    $i = 0;
    foreach ($values as $value) {
        $key = $prefix . $i++;
        $placeholders[] = ':' . $key;
        $params[$key] = $value;
    }
    return implode(',', $placeholders);
}

function md_tag_meaning(string $tag, string $description = ''): string
{
    $tag = trim($tag);
    $description = trim($description);
    if ($description !== '') return $description;
    $upper = strtoupper($tag);
    if (preg_match('/^VIU_AULA_(\d+)$/', $upper, $m)) return 'Aluno marcou ou acionou visualizacao/conclusao da aula ID ' . $m[1] . '.';
    if (strpos($upper, 'LIVE_ACESSOU') !== false || strpos($upper, 'ACESSOU') !== false) return 'Lead acessou a live ou sala do evento.';
    if (strpos($upper, 'LIVE_OFERTA') !== false || strpos($upper, 'OFERTA') !== false) return 'Lead chegou ao momento de oferta da live.';
    if (strpos($upper, 'LIVE_COMPRA') !== false || strpos($upper, 'COMPRA') !== false) return 'Lead clicou/comprou em evento ligado a live.';
    if (strpos($upper, 'REAGENDAMENTO') !== false) return 'Estado do fluxo de reagendamento de live.';
    if (strpos($upper, 'BLOQUEAR') !== false) return 'Controle operacional de bloqueio/desbloqueio de disparos.';
    if (strpos($upper, 'WHATSAPP') !== false) return 'Sinal gerado por interacao ou alerta de WhatsApp.';
    return 'Tag registrada no CRM/automacoes; sem descricao cadastrada no banco.';
}

function md_buyer_profile(PDO $pdo, string $start, string $end, array $filters, int $detailLimit = 500): array
{
    $detailLimit = max(50, min(1500, $detailLimit));
    $params = ['start' => $start . ' 00:00:00', 'end' => $end . ' 23:59:59'];
    $saleFilter = md_filter_sql($filters, 'sale', $params);
    $saleDateExpr = md_sale_revenue_date_sql('s');
    $sales = md_rows($pdo, "SELECT s.id,s.transaction_code,s.status,s.webhook_event,{$saleDateExpr} sale_date,
              s.product_code,s.product_name,s.price_name,s.payment_type,s.installments_number,s.sales_channel,
              s.gross_revenue,s.net_revenue,s.producer_net,s.buyer_name,s.buyer_email,s.buyer_phone_raw,
              s.buyer_phone_norm,s.matched_user_id,s.match_method,s.utm_source,s.utm_medium,s.utm_campaign,s.utm_term,s.utm_content
            FROM hotmart_sales_live s
            WHERE " . md_approved_sql('s') . "
              AND {$saleDateExpr} BETWEEN :start AND :end{$saleFilter}
            ORDER BY {$saleDateExpr} ASC, s.id ASC", $params);

    $emails = [];
    $phones = [];
    $userIds = [];
    foreach ($sales as $sale) {
        $uid = (int)($sale['matched_user_id'] ?? 0);
        if ($uid > 0) $userIds[$uid] = $uid;
        $email = normalize_email_value($sale['buyer_email'] ?? '');
        $phone = normalize_phone_value($sale['buyer_phone_norm'] ?: ($sale['buyer_phone_raw'] ?? ''));
        if ($email !== '') $emails[$email] = $email;
        if ($phone !== '') $phones[$phone] = $phone;
    }

    $users = [];
    $emailToUsers = [];
    $phoneToUsers = [];
    $loadUsers = static function(array $rows) use (&$users, &$emailToUsers, &$phoneToUsers): void {
        foreach ($rows as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0) continue;
            $users[$id] = $u;
            $email = normalize_email_value($u['email'] ?? '');
            $phone = normalize_phone_value($u['telefone'] ?? '');
            if ($email !== '') $emailToUsers[$email][$id] = $id;
            if ($phone !== '') $phoneToUsers[$phone][$id] = $id;
        }
    };
    if ($userIds) {
        $p = [];
        $in = md_in_params(array_values($userIds), 'u', $p);
        $loadUsers(md_rows($pdo, "SELECT * FROM users WHERE id IN ({$in})", $p));
    }
    if ($emails) {
        $p = [];
        $in = md_in_params(array_values($emails), 'e', $p);
        $loadUsers(md_rows($pdo, "SELECT * FROM users WHERE LOWER(TRIM(email)) IN ({$in}) ORDER BY id DESC", $p));
    }
    if ($phones) {
        $p = [];
        $in = md_in_params(array_values($phones), 'p', $p);
        $expr = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''),' ',''),'-',''),'(',''),')',''),'+',''),11)";
        $loadUsers(md_rows($pdo, "SELECT * FROM users WHERE {$expr} IN ({$in}) ORDER BY id DESC", $p));
    }

    $buyers = [];
    $saleDetails = [];
    foreach ($sales as $sale) {
        $email = normalize_email_value($sale['buyer_email'] ?? '');
        $phone = normalize_phone_value($sale['buyer_phone_norm'] ?: ($sale['buyer_phone_raw'] ?? ''));
        $uid = (int)($sale['matched_user_id'] ?? 0);
        if ($uid <= 0 && $email !== '' && !empty($emailToUsers[$email])) $uid = (int)array_key_first($emailToUsers[$email]);
        if ($uid <= 0 && $phone !== '' && !empty($phoneToUsers[$phone])) $uid = (int)array_key_first($phoneToUsers[$phone]);
        if ($uid > 0) $userIds[$uid] = $uid;
        $buyerKey = $uid > 0 ? 'u:' . $uid : ($email !== '' ? 'e:' . $email : ($phone !== '' ? 'p:' . $phone : 'tx:' . (string)$sale['transaction_code']));
        if (!isset($buyers[$buyerKey])) {
            $u = $uid > 0 ? ($users[$uid] ?? []) : [];
            $buyers[$buyerKey] = [
                'buyer_key' => $buyerKey,
                'user_id' => $uid ?: null,
                'name' => (string)($u['nome'] ?? $sale['buyer_name'] ?? ''),
                'email' => (string)($u['email'] ?? $sale['buyer_email'] ?? ''),
                'phone' => (string)($u['telefone'] ?? $sale['buyer_phone_raw'] ?? $sale['buyer_phone_norm'] ?? ''),
                'lead_created_at' => (string)($u['created_at'] ?? ''),
                'turma' => (string)($u['codigo_turma'] ?? $u['turma_codigo'] ?? ''),
                'scheduled_live_at' => (string)($u['data_live'] ?? ''),
                'utm' => [
                    'source' => (string)($u['utm_source'] ?? $sale['utm_source'] ?? ''),
                    'medium' => (string)($u['utm_medium'] ?? $sale['utm_medium'] ?? ''),
                    'campaign' => (string)($u['utm_campaign'] ?? $sale['utm_campaign'] ?? ''),
                    'term' => (string)($u['utm_term'] ?? $sale['utm_term'] ?? ''),
                    'content' => (string)($u['utm_content'] ?? $sale['utm_content'] ?? ''),
                ],
                'sales' => [],
                'tags' => [],
                'events' => [],
                'live_events' => [],
                'lesson_views' => 0,
                'lessons_completed' => 0,
                'first_lesson_view_at' => '',
                'first_live_access_at' => '',
                'first_event_at' => '',
                'revenue' => 0.0,
            ];
        }
        $saleRow = [
            'transaction' => (string)$sale['transaction_code'],
            'date' => (string)$sale['sale_date'],
            'product' => (string)($sale['product_name'] ?: 'Sem produto'),
            'offer' => (string)($sale['price_name'] ?? ''),
            'gross' => (float)$sale['gross_revenue'],
            'producer_net' => (float)$sale['producer_net'],
            'payment_type' => (string)($sale['payment_type'] ?? ''),
            'installments' => (int)($sale['installments_number'] ?? 0),
            'channel' => (string)($sale['sales_channel'] ?? ''),
        ];
        $buyers[$buyerKey]['sales'][] = $saleRow;
        $buyers[$buyerKey]['revenue'] += (float)$sale['producer_net'];
        if (count($saleDetails) < $detailLimit) $saleDetails[] = $saleRow + ['buyer_key' => $buyerKey];
    }

    $resolvedUserIds = [];
    foreach ($buyers as $buyer) {
        if (!empty($buyer['user_id'])) $resolvedUserIds[(int)$buyer['user_id']] = (int)$buyer['user_id'];
    }

    $tagStats = [];
    if ($resolvedUserIds && metrics_table_exists($pdo, 'user_tags') && metrics_table_exists($pdo, 'tags')) {
        $p = [];
        $in = md_in_params(array_values($resolvedUserIds), 'tu', $p);
        $descCol = md_pick_column($pdo, 'tags', ['descricao','description','significado']);
        $descSql = $descCol !== '' ? ",t.`{$descCol}` description" : ",'' description";
        foreach (md_rows($pdo, "SELECT ut.user_id,t.nome tag_name,ut.origem,ut.created_at{$descSql} FROM user_tags ut JOIN tags t ON t.id=ut.tag_id WHERE ut.user_id IN ({$in}) ORDER BY ut.created_at ASC", $p) as $row) {
            $uid = (int)$row['user_id'];
            $tag = (string)$row['tag_name'];
            $meaning = md_tag_meaning($tag, (string)($row['description'] ?? ''));
            foreach ($buyers as &$buyer) {
                if ((int)($buyer['user_id'] ?? 0) === $uid) {
                    $buyer['tags'][] = ['tag' => $tag, 'meaning' => $meaning, 'origin' => (string)$row['origem'], 'date' => (string)$row['created_at']];
                    break;
                }
            }
            unset($buyer);
            if (!isset($tagStats[$tag])) $tagStats[$tag] = ['tag' => $tag, 'meaning' => $meaning, 'buyers' => [], 'count' => 0, 'origins' => []];
            $tagStats[$tag]['buyers'][$uid] = true;
            $tagStats[$tag]['count']++;
            $origin = (string)$row['origem'];
            if ($origin !== '') $tagStats[$tag]['origins'][$origin] = ($tagStats[$tag]['origins'][$origin] ?? 0) + 1;
        }
    }
    foreach ($tagStats as &$tagRow) {
        $tagRow['buyers'] = count($tagRow['buyers']);
        arsort($tagRow['origins']);
    }
    unset($tagRow);
    uasort($tagStats, static fn($a, $b) => ((int)$b['buyers'] <=> (int)$a['buyers']) ?: ((int)$b['count'] <=> (int)$a['count']));

    if ($resolvedUserIds && metrics_table_exists($pdo, 'lesson_view_events')) {
        $p = [];
        $in = md_in_params(array_values($resolvedUserIds), 'lv', $p);
        foreach (md_rows($pdo, "SELECT user_id,COUNT(*) views,MIN(viewed_at) first_view FROM lesson_view_events WHERE user_id IN ({$in}) GROUP BY user_id", $p) as $row) {
            foreach ($buyers as &$buyer) {
                if ((int)($buyer['user_id'] ?? 0) === (int)$row['user_id']) {
                    $buyer['lesson_views'] = (int)$row['views'];
                    $buyer['first_lesson_view_at'] = (string)$row['first_view'];
                    break;
                }
            }
            unset($buyer);
        }
    }
    if ($resolvedUserIds && metrics_table_exists($pdo, 'lesson_progress')) {
        $p = [];
        $in = md_in_params(array_values($resolvedUserIds), 'lp', $p);
        foreach (md_rows($pdo, "SELECT user_id,COUNT(*) completed,MIN(completed_at) first_completed FROM lesson_progress WHERE user_id IN ({$in}) AND status='completed' GROUP BY user_id", $p) as $row) {
            foreach ($buyers as &$buyer) {
                if ((int)($buyer['user_id'] ?? 0) === (int)$row['user_id']) {
                    $buyer['lessons_completed'] = (int)$row['completed'];
                    if ($buyer['first_lesson_view_at'] === '') $buyer['first_lesson_view_at'] = (string)$row['first_completed'];
                    break;
                }
            }
            unset($buyer);
        }
    }
    if ($resolvedUserIds && metrics_table_exists($pdo, 'automation_flow_events')) {
        $p = [];
        $in = md_in_params(array_values($resolvedUserIds), 'ev', $p);
        foreach (md_rows($pdo, "SELECT user_id,event_code,created_at,payload_json FROM automation_flow_events WHERE user_id IN ({$in}) ORDER BY created_at ASC LIMIT 5000", $p) as $row) {
            foreach ($buyers as &$buyer) {
                if ((int)($buyer['user_id'] ?? 0) === (int)$row['user_id']) {
                    if (count($buyer['events']) < 30) $buyer['events'][] = ['event' => (string)$row['event_code'], 'date' => (string)$row['created_at']];
                    if ($buyer['first_event_at'] === '') $buyer['first_event_at'] = (string)$row['created_at'];
                    break;
                }
            }
            unset($buyer);
        }
    }
    if ($resolvedUserIds && metrics_table_exists($pdo, 'live_event_recebimentos') && metrics_table_exists($pdo, 'live_events')) {
        $p = [];
        $in = md_in_params(array_values($resolvedUserIds), 'le', $p);
        foreach (md_rows($pdo, "SELECT r.user_id,e.nome,e.tipo,e.tag_nome,r.recebido_em,r.processado_em FROM live_event_recebimentos r JOIN live_events e ON e.id=r.event_id WHERE r.user_id IN ({$in}) AND r.status='processado' ORDER BY r.recebido_em ASC LIMIT 3000", $p) as $row) {
            foreach ($buyers as &$buyer) {
                if ((int)($buyer['user_id'] ?? 0) === (int)$row['user_id']) {
                    $event = ['name' => (string)$row['nome'], 'type' => (string)$row['tipo'], 'tag' => (string)$row['tag_nome'], 'date' => (string)$row['recebido_em']];
                    if (count($buyer['live_events']) < 20) $buyer['live_events'][] = $event;
                    if ((string)$row['tipo'] === 'acessou' && $buyer['first_live_access_at'] === '') $buyer['first_live_access_at'] = (string)$row['recebido_em'];
                    break;
                }
            }
            unset($buyer);
        }
    }

    $productStats = [];
    $warmupDays = [];
    $liveBuyers = 0;
    $noLessonBuyers = 0;
    $unmatchedBuyers = 0;
    foreach ($buyers as &$buyer) {
        if (empty($buyer['user_id'])) $unmatchedBuyers++;
        if ((int)$buyer['lesson_views'] <= 0 && (int)$buyer['lessons_completed'] <= 0) $noLessonBuyers++;
        if ($buyer['first_live_access_at'] !== '') $liveBuyers++;
        $leadTs = strtotime((string)$buyer['lead_created_at']);
        $firstSaleTs = null;
        foreach ($buyer['sales'] as $sale) {
            $saleTs = strtotime((string)$sale['date']);
            if ($firstSaleTs === null || ($saleTs && $saleTs < $firstSaleTs)) $firstSaleTs = $saleTs ?: $firstSaleTs;
            $product = (string)$sale['product'];
            if (!isset($productStats[$product])) $productStats[$product] = ['product' => $product, 'sales' => 0, 'buyers' => [], 'revenue' => 0.0, 'warmup_days' => []];
            $productStats[$product]['sales']++;
            $productStats[$product]['buyers'][$buyer['buyer_key']] = true;
            $productStats[$product]['revenue'] += (float)$sale['producer_net'];
            if ($leadTs && $saleTs && $saleTs >= $leadTs) $productStats[$product]['warmup_days'][] = round(($saleTs - $leadTs) / 86400, 2);
        }
        if ($leadTs && $firstSaleTs && $firstSaleTs >= $leadTs) $warmupDays[] = round(($firstSaleTs - $leadTs) / 86400, 2);
        $buyer['days_to_first_purchase'] = ($leadTs && $firstSaleTs && $firstSaleTs >= $leadTs) ? round(($firstSaleTs - $leadTs) / 86400, 2) : null;
    }
    unset($buyer);
    foreach ($productStats as &$product) {
        $days = $product['warmup_days'];
        sort($days);
        $product['buyers'] = count($product['buyers']);
        $product['avg_warmup_days'] = $days ? array_sum($days) / count($days) : null;
        $product['median_warmup_days'] = $days ? $days[(int)floor((count($days) - 1) / 2)] : null;
        unset($product['warmup_days']);
    }
    unset($product);
    uasort($productStats, static fn($a, $b) => ((float)$b['revenue'] <=> (float)$a['revenue']));
    sort($warmupDays);

    $detailBuyers = array_values($buyers);
    usort($detailBuyers, static fn($a, $b) => ((float)$b['revenue'] <=> (float)$a['revenue']));
    $truncated = count($detailBuyers) > $detailLimit;
    $detailBuyers = array_slice($detailBuyers, 0, $detailLimit);

    return [
        'period' => ['start' => $start, 'end' => $end],
        'filters' => $filters,
        'summary' => [
            'sales' => count($sales),
            'buyers' => count($buyers),
            'resolved_buyers' => count($buyers) - $unmatchedBuyers,
            'unmatched_buyers' => $unmatchedBuyers,
            'buyers_without_any_lesson' => $noLessonBuyers,
            'buyers_without_any_lesson_pct' => count($buyers) > 0 ? $noLessonBuyers / count($buyers) * 100 : 0,
            'buyers_with_live_access' => $liveBuyers,
            'buyers_with_live_access_pct' => count($buyers) > 0 ? $liveBuyers / count($buyers) * 100 : 0,
            'avg_days_to_purchase' => $warmupDays ? array_sum($warmupDays) / count($warmupDays) : null,
            'median_days_to_purchase' => $warmupDays ? $warmupDays[(int)floor((count($warmupDays) - 1) / 2)] : null,
        ],
        'top_tags' => array_slice(array_values($tagStats), 0, 30),
        'products' => array_values($productStats),
        'buyers' => $detailBuyers,
        'sales_sample' => $saleDetails,
        'truncated' => $truncated,
        'detail_limit' => $detailLimit,
    ];
}

function md_buyer_profile_ai(PDO $pdo, array $profile): array
{
    $apiKey = trim((string)get_setting('buyer_profile_ai_openai_api_key', ''));
    if ($apiKey === '') $apiKey = trim((string)get_setting('whatsapp_ai_openai_api_key', ''));
    if ($apiKey === '') $apiKey = trim((string)get_setting('openai_api_key', ''));
    if ($apiKey === '') throw new RuntimeException('Configure a chave da OpenAI no agente de vendas.');
    if (!function_exists('curl_init')) throw new RuntimeException('Extensao cURL do PHP nao disponivel.');
    $model = trim((string)get_setting('buyer_profile_ai_model', ''));
    if ($model === '') $model = trim((string)get_setting('whatsapp_ai_model', 'gpt-4.1-mini')) ?: 'gpt-4.1-mini';
    $maxTokens = max(800, min(8000, (int)get_setting('buyer_profile_ai_max_tokens', '2400')));
    $systemPrompt = trim((string)get_setting('buyer_profile_ai_prompt', ''));
    if ($systemPrompt === '') {
        $systemPrompt = 'Voce e um analista senior de growth para venda de cursos online. Responda em portugues do Brasil, com insights praticos, sem inventar dados. Use os dados enviados para identificar perfis que compram, tags fortes, eventos decisivos, tempo de aquecimento por curso, influencia de live, gargalos e onde colocar mais energia.';
    }
    $prompt = [
        'role' => 'system',
        'content' => $systemPrompt
    ];
    $user = [
        'role' => 'user',
        'content' => "Analise esta base de compradores filtrada no dashboard. Gere:\n1. resumo executivo;\n2. perfil dos leads que mais compram;\n3. tags mais importantes e o que elas indicam;\n4. cursos que vendem rapido vs depois de aquecer;\n5. impacto de live e datas de acesso;\n6. gargalos do percurso;\n7. recomendacoes objetivas de onde injetar energia nos proximos 7 dias.\n\nBASE_JSON:\n" . json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ];
    $payload = [
        'model' => $model,
        'input' => [$prompt, $user],
        'max_output_tokens' => $maxTokens,
    ];
    if (strpos($model, 'gpt-5') !== 0) $payload['temperature'] = 0.2;
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 90,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') throw new RuntimeException('Falha ao chamar OpenAI: ' . $err);
    $decoded = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) ? (string)($decoded['error']['message'] ?? $raw) : $raw;
        throw new RuntimeException('OpenAI HTTP ' . $code . ': ' . substr($msg, 0, 1000));
    }
    $text = (string)($decoded['output_text'] ?? '');
    if ($text === '' && is_array($decoded['output'] ?? null)) {
        foreach ($decoded['output'] as $out) {
            foreach (($out['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text') $text .= (string)($content['text'] ?? '');
            }
        }
    }
    if (trim($text) === '') throw new RuntimeException('A OpenAI retornou uma resposta vazia.');
    return ['model' => $model, 'analysis' => trim($text), 'raw' => $decoded];
}

function md_cohorts(PDO $pdo, string $start, string $end, array $filters): array
{
    $useInscricaoLogs=metrics_table_exists($pdo,'inscricao_logs')&&trim((string)($filters['campaign']??''))===''&&trim((string)($filters['adset']??''))==='';
    $filterTurma=trim((string)($filters['turma']??''));
    $leadParams=[];
    if($useInscricaoLogs){
        $leadWhere=["il.codigo_turma IS NOT NULL AND il.codigo_turma<>''"];
        if($filterTurma!==''){$leadWhere[]="il.codigo_turma=:turma";$leadParams['turma']=$filterTurma;}
        $leads=md_rows($pdo,"SELECT COALESCE(NULLIF(il.codigo_turma,''),'Sem turma') turma,COUNT(DISTINCT il.user_id) leads,DATE(MIN(il.created_at)) entry_start,DATE(MAX(il.created_at)) entry_end FROM inscricao_logs il WHERE ".implode(' AND ',$leadWhere)." GROUP BY turma",$leadParams);
        $dailyRows=md_rows($pdo,"SELECT DATE(il.created_at) entry_date,COALESCE(NULLIF(il.codigo_turma,''),'Sem turma') turma,COUNT(DISTINCT il.user_id) leads FROM inscricao_logs il WHERE ".implode(' AND ',$leadWhere)." GROUP BY DATE(il.created_at),turma",$leadParams);
    }else{
        $leadFilter=md_filter_sql($filters,'lead',$leadParams);
        $leadFilter=$leadFilter!==''?' WHERE '.substr($leadFilter,5):'';
        $leads=md_rows($pdo,"SELECT COALESCE(NULLIF(l.turma_codigo,''),'Sem turma') turma,COUNT(*) leads,DATE(MIN(l.created_at)) entry_start,DATE(MAX(l.created_at)) entry_end FROM attribution_leads l{$leadFilter} GROUP BY turma",$leadParams);
        $dailyRows=md_rows($pdo,"SELECT DATE(l.created_at) entry_date,COALESCE(NULLIF(l.turma_codigo,''),'Sem turma') turma,COUNT(*) leads FROM attribution_leads l{$leadFilter} GROUP BY DATE(l.created_at),turma",$leadParams);
    }
    $dailyTotals=[];$dailyByTurma=[];
    foreach($dailyRows as $r){$d=(string)$r['entry_date'];$t=(string)$r['turma'];$q=(int)$r['leads'];$dailyTotals[$d]=($dailyTotals[$d]??0)+$q;$dailyByTurma[$d][$t]=($dailyByTurma[$d][$t]??0)+$q;}
    $spendByDate=[];
    if($dailyTotals){$dates=array_keys($dailyTotals);$spendRows=md_rows($pdo,"SELECT report_date,SUM(spend) spend FROM meta_account_daily WHERE report_date BETWEEN :start AND :end GROUP BY report_date",['start'=>min($dates),'end'=>max($dates)]);foreach($spendRows as $r)$spendByDate[(string)$r['report_date']]=(float)$r['spend'];}
    $spendByTurma=[];
    foreach($dailyByTurma as $d=>$items){$daySpend=$spendByDate[$d]??0.0;$dayTotal=$dailyTotals[$d]??0;if($daySpend<=0||$dayTotal<=0)continue;foreach($items as $t=>$q)$spendByTurma[$t]=($spendByTurma[$t]??0)+($daySpend*((int)$q/$dayTotal));}
    $leadMap=[];
    foreach($leads as $r){
        $leadTurma=(string)$r['turma'];
        $spend=(float)($spendByTurma[$leadTurma]??0);
        $leadMap[$leadTurma]=[
            'leads'=>(int)$r['leads'],
            'entry_start'=>(string)($r['entry_start']??''),
            'entry_end'=>(string)($r['entry_end']??''),
            'traffic_cost'=>$spend,
            'cpl'=>(int)$r['leads']>0?$spend/(int)$r['leads']:0,
        ];
    }
    $saleParams=['start'=>$start.' 00:00:00','end'=>$end.' 23:59:59'];
    $saleExtra='';
    if(!empty($filters['product'])){$saleExtra.=' AND s.product_name=:product';$saleParams['product']=$filters['product'];}
    $saleDateExpr=md_sale_revenue_date_sql('s');
    $sales=md_rows($pdo,"SELECT s.transaction_code,s.gross_revenue,s.producer_net,s.matched_user_id,s.buyer_email,s.buyer_phone_norm,{$saleDateExpr} sale_date,COALESCE(NULLIF(u.codigo_turma,''),NULLIF(u.turma_codigo,'')) matched_turma FROM hotmart_sales_live s LEFT JOIN users u ON u.id=s.matched_user_id WHERE ".md_approved_sql('s')." AND {$saleDateExpr} BETWEEN :start AND :end{$saleExtra}",$saleParams);
    $userIds=[];$emails=[];$phones=[];
    foreach($sales as $s){
        $uid=(int)($s['matched_user_id']??0); if($uid>0)$userIds[$uid]=$uid;
        $email=normalize_email_value($s['buyer_email']??''); if($uid<=0&&$email!=='')$emails[$email]=$email;
        $phone=normalize_phone_value($s['buyer_phone_norm']??''); if($uid<=0&&$phone!=='')$phones[$phone]=$phone;
    }
    $emailToUsers=[];$phoneToUsers=[];
    if($emails){$params=[];$in=[];$i=0;foreach($emails as $email){$k='e'.$i++;$in[]=':'.$k;$params[$k]=$email;}foreach(md_rows($pdo,"SELECT id,LOWER(TRIM(email)) email_norm FROM users WHERE LOWER(TRIM(email)) IN (".implode(',',$in).") ORDER BY id DESC",$params) as $u){$e=(string)$u['email_norm'];if($e!=='')$emailToUsers[$e][(int)$u['id']]=(int)$u['id'];}}
    if($phones){$params=[];$in=[];$i=0;foreach($phones as $phone){$k='p'.$i++;$in[]=':'.$k;$params[$k]=$phone;}foreach(md_rows($pdo,"SELECT id,RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''),' ',''),'-',''),'(',''),')',''),'+',''),11) phone_norm FROM users WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone,''),' ',''),'-',''),'(',''),')',''),'+',''),11) IN (".implode(',',$in).") ORDER BY id DESC",$params) as $u){$p=(string)$u['phone_norm'];if($p!=='')$phoneToUsers[$p][(int)$u['id']]=(int)$u['id'];}}
    foreach($sales as $s){$email=normalize_email_value($s['buyer_email']??'');$phone=normalize_phone_value($s['buyer_phone_norm']??'');$ids=[];$uid=(int)($s['matched_user_id']??0);if($uid>0)$ids[$uid]=$uid;foreach(($emailToUsers[$email]??[]) as $id)$ids[$id]=$id;foreach(($phoneToUsers[$phone]??[]) as $id)$ids[$id]=$id;foreach($ids as $id)$userIds[$id]=$id;}
    $logsByUser=[];
    if($userIds){$params=[];$in=[];$i=0;foreach($userIds as $uid){$k='u'.$i++;$in[]=':'.$k;$params[$k]=$uid;}foreach(md_rows($pdo,"SELECT user_id,codigo_turma,created_at FROM inscricao_logs WHERE user_id IN (".implode(',',$in).") AND codigo_turma IS NOT NULL AND codigo_turma<>'' ORDER BY user_id,created_at DESC",$params) as $log)$logsByUser[(int)$log['user_id']][]=$log;}
    $rowsByTurma=[];
    foreach($sales as $s){
        $matchedTurma=trim((string)($s['matched_turma']??''));
        if($matchedTurma!==''&&($filterTurma===''||$matchedTurma===$filterTurma)){
            $tx=(string)$s['transaction_code'];
            if(!isset($rowsByTurma[$matchedTurma]))$rowsByTurma[$matchedTurma]=['turma'=>$matchedTurma,'sales'=>0,'gross'=>0.0,'producer'=>0.0,'transactions'=>[]];
            if($tx===''||!isset($rowsByTurma[$matchedTurma]['transactions'][$tx])){
                if($tx!=='')$rowsByTurma[$matchedTurma]['transactions'][$tx]=true;
                $rowsByTurma[$matchedTurma]['sales']++;
                $rowsByTurma[$matchedTurma]['gross']+=(float)$s['gross_revenue'];
                $rowsByTurma[$matchedTurma]['producer']+=(float)$s['producer_net'];
            }
            continue;
        }
        $email=normalize_email_value($s['buyer_email']??'');$phone=normalize_phone_value($s['buyer_phone_norm']??'');$ids=[];$uid=(int)($s['matched_user_id']??0);if($uid>0)$ids[$uid]=$uid;foreach(($emailToUsers[$email]??[]) as $id)$ids[$id]=$id;foreach(($phoneToUsers[$phone]??[]) as $id)$ids[$id]=$id;
        if(!$ids)continue;
        $saleTurmas=[];
        foreach($ids as $id)foreach(($logsByUser[$id]??[]) as $log){$tc=trim((string)($log['codigo_turma']??''));if($tc!==''&&($filterTurma===''||$tc===$filterTurma))$saleTurmas[$tc]=$tc;}
        if(!$saleTurmas)continue;
        $tx=(string)$s['transaction_code'];
        foreach($saleTurmas as $saleTurma){
            if(!isset($rowsByTurma[$saleTurma]))$rowsByTurma[$saleTurma]=['turma'=>$saleTurma,'sales'=>0,'gross'=>0.0,'producer'=>0.0,'transactions'=>[]];
            if($tx!==''&&isset($rowsByTurma[$saleTurma]['transactions'][$tx]))continue;
            if($tx!=='')$rowsByTurma[$saleTurma]['transactions'][$tx]=true;
            $rowsByTurma[$saleTurma]['sales']++;
            $rowsByTurma[$saleTurma]['gross']+=(float)$s['gross_revenue'];
            $rowsByTurma[$saleTurma]['producer']+=(float)$s['producer_net'];
        }
    }
    foreach($leadMap as $leadTurma=>$leadData)if($leadTurma!==''&&!isset($rowsByTurma[$leadTurma]))$rowsByTurma[$leadTurma]=['turma'=>$leadTurma,'sales'=>0,'gross'=>0.0,'producer'=>0.0,'transactions'=>[]];
    $rows=array_values($rowsByTurma);
    usort($rows,static function(array $a,array $b):int{$parse=static function(string $t):int{$dt=DateTimeImmutable::createFromFormat('dmy',$t);return $dt?$dt->getTimestamp():0;};return ($parse((string)$b['turma'])<=>$parse((string)$a['turma']))?:strcmp((string)$b['turma'],(string)$a['turma']);});
    $rows=array_slice($rows,0,50);
    foreach($rows as &$r){
        $leadData=$leadMap[$r['turma']]??['leads'=>0,'entry_start'=>'','entry_end'=>'','traffic_cost'=>0,'cpl'=>0];
        $r['leads']=(int)$leadData['leads'];
        $r['entry_start']=(string)$leadData['entry_start'];
        $r['entry_end']=(string)$leadData['entry_end'];
        $r['traffic_cost']=(float)$leadData['traffic_cost'];
        $r['cpl']=(float)$leadData['cpl'];
        $r['conversion']=$r['leads']>0?(int)$r['sales']/(int)$r['leads']*100:0;
        $r['roas']=$r['traffic_cost']>0?(float)$r['producer']/$r['traffic_cost']:0;
    } unset($r);
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
    $hsSaleDateExpr=md_sale_revenue_date_sql('hs');
    $attrs=md_rows($pdo,"SELECT am.campaign_group, am.campaign_name,
      COUNT(DISTINCT CASE WHEN {$hsSaleDateExpr}>=:d7 THEN hs.transaction_code END) sales7,SUM(CASE WHEN {$hsSaleDateExpr}>=:d7 THEN hs.producer_net ELSE 0 END) revenue7,
      COUNT(DISTINCT CASE WHEN {$hsSaleDateExpr}>=:d30 THEN hs.transaction_code END) sales30,SUM(CASE WHEN {$hsSaleDateExpr}>=:d30 THEN hs.producer_net ELSE 0 END) revenue30,
      COUNT(DISTINCT hs.transaction_code) sales90,SUM(hs.producer_net) revenue90
      FROM attribution_matches am JOIN attribution_sales axs ON axs.id=am.sale_id JOIN hotmart_sales_live hs ON hs.transaction_code=axs.transaction_code
      WHERE am.attribution_model='last_touch' AND ".md_approved_sql('hs')." AND {$hsSaleDateExpr} BETWEEN :d90 AND :enddt GROUP BY am.campaign_group,am.campaign_name",['d7'=>$start7.' 00:00:00','d30'=>$start30.' 00:00:00','d90'=>$start90.' 00:00:00','enddt'=>$end.' 23:59:59']);
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
