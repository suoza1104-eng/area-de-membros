<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';
proteger_admin();
$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$s=email_settings($pdo);

$totals=$pdo->query("SELECT COUNT(*) total,SUM(status IN ('sent','delivered')) sent,SUM(delivered_at IS NOT NULL) delivered,SUM(status='bounced') bounced,SUM(status IN ('complaint','complained')) complaints,SUM(first_opened_at IS NOT NULL) opened,SUM(first_clicked_at IS NOT NULL) clicked,SUM(status IN ('unsubscribed','suppressed')) unsubscribed FROM email_messages")->fetch(PDO::FETCH_ASSOC)?:[];
$supp=(int)$pdo->query('SELECT COUNT(DISTINCT email) FROM email_suppressions WHERE active=1')->fetchColumn();
$recent=$pdo->query("SELECT m.*,u.nome FROM email_messages m LEFT JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC)?:[];
$daily=$pdo->query("SELECT DATE(created_at) day,COUNT(*) sent,SUM(delivered_at IS NOT NULL) delivered,SUM(first_opened_at IS NOT NULL) opened,SUM(first_clicked_at IS NOT NULL) clicked,SUM(status='bounced') bounced FROM email_messages WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY day")->fetchAll(PDO::FETCH_ASSOC)?:[];
$templateRows=$pdo->query("SELECT t.id,t.name,t.subject,COUNT(m.id) sent,SUM(m.delivered_at IS NOT NULL) delivered,SUM(m.first_opened_at IS NOT NULL) opened,SUM(m.first_clicked_at IS NOT NULL) clicked,SUM(m.status='bounced') bounced FROM email_templates t LEFT JOIN email_template_versions v ON v.template_id=t.id LEFT JOIN email_messages m ON m.template_version_id=v.id WHERE t.status<>'deleted' GROUP BY t.id,t.name,t.subject ORDER BY sent DESC,t.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC)?:[];
$maxTemplateValue=1;
foreach($templateRows as $r){
    foreach(['sent','delivered','opened','clicked','bounced'] as $k)$maxTemplateValue=max($maxTemplateValue,(int)($r[$k]??0));
}

$menu='email_marketing';
$page_title='E-mail marketing';
include __DIR__.'/_header.php';
echo email_admin_styles();
function em_pct($a,$b):string{return $b>0?number_format(100*$a/$b,1,',','.').'%':'0,0%';}
function em_width($value,$max):string{return number_format(max(0,min(100,100*((int)$value)/max(1,(int)$max))),2,'.','').'%';}
?>
<style>
.em-charts{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px}.em-chart{height:310px;position:relative}
.em-template-metrics{display:grid;gap:14px}.em-template-row{display:grid;grid-template-columns:minmax(220px,280px) minmax(300px,1fr) minmax(360px,.9fr);gap:14px;align-items:center;padding:14px;border:1px solid var(--border);border-radius:12px;background:var(--bg)}
.em-template-title strong{display:block;font-size:13px;color:var(--text)}.em-template-title span{display:block;font-size:11px;color:var(--muted);margin-top:4px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.em-bars{display:grid;gap:7px}.em-bar-line{display:grid;grid-template-columns:78px minmax(120px,1fr) 44px;gap:8px;align-items:center;font-size:10px;color:var(--muted)}.em-bar-track{height:9px;border-radius:999px;background:#0f172a;overflow:hidden}.em-bar-fill{height:100%;border-radius:999px;min-width:2px}.em-bar-fill.zero{min-width:0}.em-bar-sent{background:#64748b}.em-bar-delivered{background:#22c55e}.em-bar-opened{background:#38bdf8}.em-bar-clicked{background:#facc15}.em-bar-bounced{background:#ef4444}
.em-rate-cards{display:grid;grid-template-columns:repeat(4,minmax(74px,1fr));gap:8px}.em-rate-card{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-card);min-height:62px}.em-rate-card small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase}.em-rate-card strong{display:block;margin-top:6px;font-size:18px}.em-rate-card.good strong{color:#86efac}.em-rate-card.info strong{color:#7dd3fc}.em-rate-card.warn strong{color:#fde68a}.em-rate-card.bad strong{color:#fca5a5}
@media(max-width:1100px){.em-template-row{grid-template-columns:1fr}.em-rate-cards{grid-template-columns:repeat(2,minmax(120px,1fr))}}@media(max-width:850px){.em-charts{grid-template-columns:1fr}}
</style>
<div class="em">
  <div class="em-head">
    <div><h1>E-mail marketing</h1><p class="text-muted">Campanhas, automações e saúde de entrega pelo provedor ativo.</p></div>
    <a class="btn btn-primary" href="email_campanhas.php?new=1">+ Nova campanha</a>
  </div>
  <?=email_admin_nav('dashboard')?>
  <div class="em-card <?=$s['engine_enabled']==='1'?'em-ok':'em-bad'?>">Motor: <strong><?=$s['engine_enabled']==='1'?'ativo':'pausado'?></strong> · Região <?=email_h($s['region']??'-')?> · Remetente <?=email_h(($s['provider_active']??'aws_ses')==='resend'?($s['resend_from_email']??'não configurado'):($s['from_email']??'não configurado'))?> <a href="email_config.php">Configurar</a></div>

  <div class="em-grid">
    <?php foreach([['sent','Aceitos pelo provedor'],['delivered','Entregues'],['opened','Aberturas únicas'],['clicked','Cliques únicos'],['bounced','Bounces'],['complaints','Reclamações'],['unsubscribed','Descadastros']] as [$k,$l]):?>
      <div class="em-card em-kpi"><small><?=email_h($l)?></small><strong><?=number_format((int)($totals[$k]??0),0,',','.')?></strong><span class="text-muted"><?=in_array($k,['delivered','bounced'],true)?em_pct((int)($totals[$k]??0),(int)($totals['sent']??0)):em_pct((int)($totals[$k]??0),(int)($totals['delivered']??0))?></span></div>
    <?php endforeach?>
    <div class="em-card em-kpi"><small>Suprimidos ativos</small><strong><?=number_format($supp,0,',','.')?></strong><a href="email_contatos.php">Ver lista</a></div>
  </div>

  <section class="em-card">
    <h2>Desempenho por modelo de e-mail</h2>
    <p class="text-muted">Taxas calculadas sempre sobre o total de envios de cada modelo.</p>
    <div class="em-template-metrics">
      <?php foreach($templateRows as $r):
        $sent=(int)($r['sent']??0);$delivered=(int)($r['delivered']??0);$opened=(int)($r['opened']??0);$clicked=(int)($r['clicked']??0);$bounced=(int)($r['bounced']??0);
        $bars=[['Envios',$sent,'sent'],['Entregues',$delivered,'delivered'],['Abertos',$opened,'opened'],['Cliques',$clicked,'clicked'],['Bounces',$bounced,'bounced']];
      ?>
        <article class="em-template-row">
          <div class="em-template-title"><strong><?=email_h($r['name'])?></strong><span><?=email_h($r['subject']??'')?></span></div>
          <div class="em-bars">
            <?php foreach($bars as [$label,$value,$kind]):?>
              <div class="em-bar-line"><span><?=email_h($label)?></span><div class="em-bar-track"><div class="em-bar-fill em-bar-<?=$kind?> <?=$value?'':'zero'?>" style="width:<?=em_width($value,$maxTemplateValue)?>"></div></div><b><?=number_format($value,0,',','.')?></b></div>
            <?php endforeach?>
          </div>
          <div class="em-rate-cards">
            <div class="em-rate-card good"><small>Entrega</small><strong><?=em_pct($delivered,$sent)?></strong></div>
            <div class="em-rate-card info"><small>Abertura</small><strong><?=em_pct($opened,$sent)?></strong></div>
            <div class="em-rate-card warn"><small>Clique</small><strong><?=em_pct($clicked,$sent)?></strong></div>
            <div class="em-rate-card bad"><small>Bounce</small><strong><?=em_pct($bounced,$sent)?></strong></div>
          </div>
        </article>
      <?php endforeach?>
      <?php if(!$templateRows):?><div class="text-muted">Nenhum modelo de e-mail criado.</div><?php endif?>
    </div>
  </section>

  <div class="em-charts"><section class="em-card"><h2>Evolução dos últimos 30 dias</h2><div class="em-chart"><canvas id="emailEvolutionChart"></canvas></div></section><section class="em-card"><h2>Funil de entrega e engajamento</h2><div class="em-chart"><canvas id="emailFunnelChart"></canvas></div></section></div>
  <section class="em-card"><h2>Envios recentes</h2><div class="em-table"><table><thead><tr><th>Data</th><th>Aluno</th><th>Assunto</th><th>Status</th><th>Entrega</th><th>Abertura</th><th>Clique</th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?=email_h(date('d/m/Y H:i',strtotime($r['created_at'])))?></td><td><?=email_h($r['nome']?:$r['recipient_email'])?></td><td><?=email_h($r['subject'])?></td><td><span class="em-pill"><?=email_h($r['status'])?></span></td><td><?=$r['delivered_at']?'Sim':'-'?></td><td><?=$r['first_opened_at']?'Sim':'-'?></td><td><?=$r['first_clicked_at']?'Sim':'-'?></td></tr><?php endforeach?><?php if(!$recent):?><tr><td colspan="7" class="text-muted">Nenhum envio registrado.</td></tr><?php endif?></tbody></table></div></section>
</div>
<script>(()=>{if(!window.Chart)return;const daily=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,totals=<?=json_encode($totals,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,legend={labels:{color:'#94a3b8'}};new Chart(document.getElementById('emailEvolutionChart'),{type:'line',data:{labels:daily.map(x=>x.day.split('-').reverse().slice(0,2).join('/')),datasets:[{label:'Entregues',data:daily.map(x=>+x.delivered),borderColor:'#22c55e',backgroundColor:'#22c55e33',fill:true,tension:.3},{label:'Aberturas',data:daily.map(x=>+x.opened),borderColor:'#38bdf8',tension:.3},{label:'Cliques',data:daily.map(x=>+x.clicked),borderColor:'#facc15',tension:.3},{label:'Bounces',data:daily.map(x=>+x.bounced),borderColor:'#ef4444',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend},scales:{x:{ticks:{color:'#64748b'}},y:{beginAtZero:true,ticks:{precision:0,color:'#64748b'}}}}});new Chart(document.getElementById('emailFunnelChart'),{type:'doughnut',data:{labels:['Entregues','Abertos','Clicados','Bounces','Reclamações'],datasets:[{data:[+totals.delivered||0,+totals.opened||0,+totals.clicked||0,+totals.bounced||0,+totals.complaints||0],backgroundColor:['#22c55e','#38bdf8','#facc15','#ef4444','#a78bfa']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{color:'#94a3b8'}}}}});})();</script>
<?php include __DIR__.'/_footer.php'; ?>
