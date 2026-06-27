<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/metrics_dashboard.php';
proteger_admin();

$menu = 'vendas_analytics';
$page_title = 'Inteligencia de Negocio';
$pdo = getPDO();
metrics_ensure_schema($pdo);

function va_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function va_money($value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function va_num($value, int $decimals = 0): string { return number_format((float)$value, $decimals, ',', '.'); }
function va_pct($value, int $decimals = 1): string { return va_num($value, $decimals) . '%'; }
function va_selected($a, $b): string { return (string)$a === (string)$b ? ' selected' : ''; }
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

$current = md_snapshot($pdo, $period['start'], $period['end'], $filters);
$previous = md_snapshot($pdo, $period['previous_start'], $period['previous_end'], $filters);
$daily = md_daily_series($pdo, $period['start'], $period['end'], $filters);
$monthly = md_monthly_series($pdo, $filters);
$breakdowns = md_breakdowns($pdo, $period['start'], $period['end'], $filters);
$cohorts = md_cohorts($pdo, $period['start'], $period['end'], $filters);
$adsets = md_adset_performance($pdo);
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
@media(max-width:1300px){.metric-grid{grid-template-columns:repeat(4,1fr)}.filter-grid{grid-template-columns:repeat(3,1fr)}.four-col{grid-template-columns:repeat(2,1fr)}}@media(max-width:900px){.metric-grid{grid-template-columns:repeat(2,1fr)}.chart-grid,.two-col,.three-col,.four-col{grid-template-columns:1fr}.context-grid{grid-template-columns:repeat(2,1fr)}.bi-head{flex-direction:column}.sync-pill{white-space:normal}}@media(max-width:600px){.filter-grid{grid-template-columns:1fr 1fr}.fg-actions{grid-column:span 2}.metric-grid{grid-template-columns:1fr 1fr;gap:7px}.metric{padding:11px;min-height:96px}.metric-value{font-size:16px}.context-grid{grid-template-columns:1fr}.chart-box{height:285px}.section-card{padding:11px}.bi-title h1{font-size:19px}}
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

  <section class="section-card">
    <div class="section-head"><div><h2>Campanhas e conjuntos: consistencia 7 x 30 x 90 dias</h2><p>ROAS usa liquido do produtor. Resiliente = vendas recorrentes sem deterioracao relevante.</p></div></div>
    <div class="table-wrap"><table class="bi-table"><thead><tr><th>Campanha / conjunto</th><th>Gasto 7d</th><th>Leads 7d</th><th>CPL 7d / 30d</th><th>Vendas 7d / 30d</th><th>CAC 7d / 30d</th><th>ROAS 7d</th><th>ROAS 30d</th><th>ROAS 90d</th><th>Leitura</th></tr></thead><tbody>
      <?php foreach($adsets as $r):?><tr><td><strong><?=va_h($r['adset_name'])?></strong><div class="subtext"><?=va_h($r['campaign_name'])?></div></td><td><?=va_money($r['spend7'])?></td><td><?=va_num($r['leads7'])?></td><td><?=va_money($r['cpl7'])?> <span class="subtext">/ <?=va_money($r['cpl30'])?></span></td><td><?=va_num($r['sales7'])?> <span class="subtext">/ <?=va_num($r['sales30'])?></span></td><td><?=va_money($r['cac7'])?> <span class="subtext">/ <?=va_money($r['cac30'])?></span></td><td><?=va_num($r['roas7'],2)?></td><td><?=va_num($r['roas30'],2)?></td><td><?=va_num($r['roas90'],2)?></td><td><span class="<?=$r['resilient']?'resilient':'watch'?>"><?=$r['resilient']?'Resiliente':($r['trend']==='up'?'Em melhora':'Observar')?></span></td></tr><?php endforeach;?>
      <?php if(!$adsets):?><tr><td colspan="10" class="empty">Sem dados Meta para classificar conjuntos.</td></tr><?php endif;?>
    </tbody></table></div>
  </section>

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
</div>

<script>
(function(){
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
