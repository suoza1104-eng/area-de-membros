<?php
declare(strict_types=1);
require_once __DIR__.'/../app/email_marketing.php';
proteger_admin();
$pdo=getPDO();
email_marketing_ensure_schema($pdo);
$s=email_settings($pdo);

function em_pct($a,$b):string{return $b>0?number_format(100*$a/$b,1,',','.').'%':'0,0%';}
function em_width($value,$max):string{return number_format(max(0,min(100,100*((int)$value)/max(1,(int)$max))),2,'.','').'%';}
function em_fetch_all(PDO $pdo,string $sql,array $params=[]):array{$st=$pdo->prepare($sql);$st->execute($params);return$st->fetchAll(PDO::FETCH_ASSOC)?:[];}
function em_fetch_one(PDO $pdo,string $sql,array $params=[]):array{$st=$pdo->prepare($sql);$st->execute($params);return$st->fetch(PDO::FETCH_ASSOC)?:[];}
function em_table_exists(PDO $pdo,string $table):bool{if(!preg_match('/^[A-Za-z0-9_]+$/',$table))return false;try{$st=$pdo->prepare('SHOW TABLES LIKE :table');$st->execute(['table'=>$table]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}
function em_column_exists(PDO $pdo,string $table,string $column):bool{if(!preg_match('/^[A-Za-z0-9_]+$/',$table.$column))return false;try{$st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");$st->execute(['column'=>$column]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}}
function em_query_column(PDO $pdo,string $sql):array{try{return$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[];}catch(Throwable $e){return[];}}
function em_query_assoc(PDO $pdo,string $sql):array{try{return$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return[];}}

$filters=[
    'date_from'=>trim((string)($_GET['date_from']??date('Y-m-d',strtotime('-29 days')))),
    'date_to'=>trim((string)($_GET['date_to']??date('Y-m-d'))),
    'turma'=>trim((string)($_GET['turma']??'')),
    'tag'=>trim((string)($_GET['tag']??'')),
    'template_id'=>(int)($_GET['template_id']??0),
    'status'=>trim((string)($_GET['status']??'')),
    'metric'=>trim((string)($_GET['metric']??'')),
    'q'=>trim((string)($_GET['q']??'')),
];
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_from']))$filters['date_from']=date('Y-m-d',strtotime('-29 days'));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_to']))$filters['date_to']=date('Y-m-d');
if(!in_array($filters['metric'],['','delivered','opened','clicked','bounced'],true))$filters['metric']='';
$hasTags=em_table_exists($pdo,'tags')&&em_table_exists($pdo,'user_tags')&&em_column_exists($pdo,'tags','nome');

$from="email_messages m LEFT JOIN users u ON u.id=m.user_id LEFT JOIN email_template_versions v ON v.id=m.template_version_id LEFT JOIN email_templates t ON t.id=v.template_id";
$where=['m.created_at>=:date_from','m.created_at<=:date_to'];
$params=['date_from'=>$filters['date_from'].' 00:00:00','date_to'=>$filters['date_to'].' 23:59:59'];
if($filters['turma']!==''){$where[]='u.codigo_turma=:turma';$params['turma']=$filters['turma'];}
if($hasTags&&$filters['tag']!==''){$where[]="EXISTS(SELECT 1 FROM user_tags ut JOIN tags tg ON tg.id=ut.tag_id WHERE ut.user_id=m.user_id AND tg.nome=:tag)";$params['tag']=$filters['tag'];}
if($filters['template_id']>0){$where[]='t.id=:template_id';$params['template_id']=$filters['template_id'];}
if($filters['status']!==''){$where[]='m.status=:status';$params['status']=$filters['status'];}
if($filters['q']!==''){$where[]='(u.nome LIKE :q_nome OR m.recipient_email LIKE :q_email OR m.subject LIKE :q_subject)';$params['q_nome']=$params['q_email']=$params['q_subject']='%'.$filters['q'].'%';}
if($filters['metric']==='delivered')$where[]='m.delivered_at IS NOT NULL';
if($filters['metric']==='opened')$where[]='m.first_opened_at IS NOT NULL';
if($filters['metric']==='clicked')$where[]='m.first_clicked_at IS NOT NULL';
if($filters['metric']==='bounced')$where[]="m.status='bounced'";
$whereSql=implode(' AND ',$where);

$totals=em_fetch_one($pdo,"SELECT COUNT(*) total,SUM(m.status IN ('sent','delivered')) sent,SUM(m.delivered_at IS NOT NULL) delivered,SUM(m.status='bounced') bounced,SUM(m.status IN ('complaint','complained')) complaints,SUM(m.first_opened_at IS NOT NULL) opened,SUM(m.first_clicked_at IS NOT NULL) clicked,SUM(m.status IN ('unsubscribed','suppressed')) unsubscribed FROM $from WHERE $whereSql",$params);
$supp=(int)$pdo->query('SELECT COUNT(DISTINCT email) FROM email_suppressions WHERE active=1')->fetchColumn();
$recent=em_fetch_all($pdo,"SELECT m.*,u.nome,t.name template_name FROM $from WHERE $whereSql ORDER BY m.id DESC LIMIT 300",$params);
$daily=em_fetch_all($pdo,"SELECT DATE(m.created_at) day,COUNT(*) sent,SUM(m.delivered_at IS NOT NULL) delivered,SUM(m.first_opened_at IS NOT NULL) opened,SUM(m.first_clicked_at IS NOT NULL) clicked,SUM(m.status='bounced') bounced FROM $from WHERE $whereSql GROUP BY DATE(m.created_at) ORDER BY day",$params);
$templateRows=em_fetch_all($pdo,"SELECT t.id,t.name,t.subject,COUNT(m.id) sent,SUM(m.delivered_at IS NOT NULL) delivered,SUM(m.first_opened_at IS NOT NULL) opened,SUM(m.first_clicked_at IS NOT NULL) clicked,SUM(m.status='bounced') bounced FROM $from WHERE $whereSql AND t.id IS NOT NULL GROUP BY t.id,t.name,t.subject HAVING COUNT(m.id)>0 ORDER BY (SUM(m.first_opened_at IS NOT NULL)/NULLIF(COUNT(m.id),0)) DESC,COUNT(m.id) DESC",$params);
$maxTemplateValue=1;
foreach($templateRows as $r)foreach(['sent','delivered','opened','clicked','bounced'] as $k)$maxTemplateValue=max($maxTemplateValue,(int)($r[$k]??0));

$turmas=em_query_column($pdo,"SELECT DISTINCT codigo_turma FROM users WHERE codigo_turma IS NOT NULL AND codigo_turma<>'' ORDER BY codigo_turma");
$tags=$hasTags?em_query_column($pdo,'SELECT nome FROM tags'.(em_column_exists($pdo,'tags','ativo')?' WHERE ativo=1':'').' ORDER BY nome'):[];
$templates=em_query_assoc($pdo,"SELECT id,name FROM email_templates WHERE status<>'deleted' ORDER BY name");
$statuses=em_query_column($pdo,"SELECT DISTINCT status FROM email_messages WHERE status IS NOT NULL AND status<>'' ORDER BY status");

$menu='email_marketing';
$page_title='E-mail marketing';
include __DIR__.'/_header.php';
echo email_admin_styles();
?>
<style>
.em-charts{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px}.em-chart{height:310px;position:relative}
.em-global-filters{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px}.em-global-filters label{display:grid;gap:5px;color:var(--muted);font-size:10px;text-transform:uppercase}.em-global-filters input,.em-global-filters select{padding:9px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text)}.em-filter-actions{display:flex;gap:8px;align-items:end;flex-wrap:wrap}
.em-template-metrics{display:grid;gap:14px}.em-template-row{display:grid;grid-template-columns:minmax(220px,280px) minmax(300px,1fr) minmax(360px,.9fr);gap:14px;align-items:center;padding:14px;border:1px solid var(--border);border-radius:12px;background:var(--bg)}
.em-template-title strong{display:block;font-size:13px;color:var(--text)}.em-template-title span{display:block;font-size:11px;color:var(--muted);margin-top:4px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.em-bars{display:grid;gap:7px}.em-bar-line{display:grid;grid-template-columns:78px minmax(120px,1fr) 44px;gap:8px;align-items:center;font-size:10px;color:var(--muted)}.em-bar-track{height:9px;border-radius:999px;background:#0f172a;overflow:hidden}.em-bar-fill{height:100%;border-radius:999px;min-width:2px}.em-bar-fill.zero{min-width:0}.em-bar-sent{background:#64748b}.em-bar-delivered{background:#22c55e}.em-bar-opened{background:#38bdf8}.em-bar-clicked{background:#facc15}.em-bar-bounced{background:#ef4444}
.em-rate-cards{display:grid;grid-template-columns:repeat(4,minmax(74px,1fr));gap:8px}.em-rate-card{padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-card);min-height:62px}.em-rate-card small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase}.em-rate-card strong{display:block;margin-top:6px;font-size:18px}.em-rate-card.good strong{color:#86efac}.em-rate-card.info strong{color:#7dd3fc}.em-rate-card.warn strong{color:#fde68a}.em-rate-card.bad strong{color:#fca5a5}
.em-sortable th[data-sort]{cursor:pointer;user-select:none}.em-sortable th[data-sort]::after{content:'↕';font-size:9px;margin-left:5px;color:#64748b}.em-sortable th.sorted-asc::after{content:'↑';color:var(--primary)}.em-sortable th.sorted-desc::after{content:'↓';color:var(--primary)}
.em{display:flex;flex-direction:column;gap:16px}.em-dashboard-filters{order:2}.em>.em-card.em-ok,.em>.em-card.em-bad{order:3}.em>.em-grid{order:4}.em>.em-charts{order:5}.em>section.em-card:not(.em-dashboard-filters){order:6}
@media(max-width:1100px){.em-template-row{grid-template-columns:1fr}.em-rate-cards{grid-template-columns:repeat(2,minmax(120px,1fr))}}@media(max-width:850px){.em-charts,.em-global-filters{grid-template-columns:1fr}}
</style>
<div class="em">
  <div class="em-head"><div><h1>E-mail marketing</h1><p class="text-muted">Campanhas, automações e saúde de entrega pelo provedor ativo.</p></div><a class="btn btn-primary" href="email_campanhas.php?new=1">+ Nova campanha</a></div>
  <?=email_admin_nav('dashboard')?>
  <div class="em-card <?=$s['engine_enabled']==='1'?'em-ok':'em-bad'?>">Motor: <strong><?=$s['engine_enabled']==='1'?'ativo':'pausado'?></strong> · Região <?=email_h($s['region']??'-')?> · Remetente <?=email_h(($s['provider_active']??'aws_ses')==='resend'?($s['resend_from_email']??'não configurado'):($s['from_email']??'não configurado'))?> <a href="email_config.php">Configurar</a></div>

  <div class="em-grid"><?php foreach([['sent','Aceitos pelo provedor'],['delivered','Entregues'],['opened','Aberturas únicas'],['clicked','Cliques únicos'],['bounced','Bounces'],['complaints','Reclamações'],['unsubscribed','Descadastros']] as [$k,$l]):?><div class="em-card em-kpi"><small><?=email_h($l)?></small><strong><?=number_format((int)($totals[$k]??0),0,',','.')?></strong><span class="text-muted"><?=in_array($k,['delivered','bounced'],true)?em_pct((int)($totals[$k]??0),(int)($totals['sent']??0)):em_pct((int)($totals[$k]??0),(int)($totals['delivered']??0))?></span></div><?php endforeach?><div class="em-card em-kpi"><small>Suprimidos ativos</small><strong><?=number_format($supp,0,',','.')?></strong><a href="email_contatos.php">Ver lista</a></div></div>

  <section class="em-card em-dashboard-filters">
    <h2>Filtros do dashboard</h2>
    <form method="get" class="em-global-filters">
      <label>Data inicial<input type="date" name="date_from" value="<?=email_h($filters['date_from'])?>"></label>
      <label>Data final<input type="date" name="date_to" value="<?=email_h($filters['date_to'])?>"></label>
      <label>Turma<select name="turma"><option value="">Todas</option><?php foreach($turmas as $t):?><option value="<?=email_h($t)?>" <?=$filters['turma']===(string)$t?'selected':''?>><?=email_h($t)?></option><?php endforeach?></select></label>
      <label>Tag<select name="tag"><option value="">Todas</option><?php foreach($tags as $tag):?><option value="<?=email_h($tag)?>" <?=$filters['tag']===(string)$tag?'selected':''?>><?=email_h($tag)?></option><?php endforeach?></select></label>
      <label>Modelo<select name="template_id"><option value="0">Todos</option><?php foreach($templates as $tpl):?><option value="<?=(int)$tpl['id']?>" <?=$filters['template_id']===(int)$tpl['id']?'selected':''?>><?=email_h($tpl['name'])?></option><?php endforeach?></select></label>
      <label>Status<select name="status"><option value="">Todos</option><?php foreach($statuses as $st):?><option value="<?=email_h($st)?>" <?=$filters['status']===(string)$st?'selected':''?>><?=email_h($st)?></option><?php endforeach?></select></label>
      <label>Evento<select name="metric"><option value="">Todos</option><option value="delivered" <?=$filters['metric']==='delivered'?'selected':''?>>Entregues</option><option value="opened" <?=$filters['metric']==='opened'?'selected':''?>>Abertos</option><option value="clicked" <?=$filters['metric']==='clicked'?'selected':''?>>Clicados</option><option value="bounced" <?=$filters['metric']==='bounced'?'selected':''?>>Bounces</option></select></label>
      <label>Busca<input name="q" value="<?=email_h($filters['q'])?>" placeholder="Aluno, e-mail ou assunto"></label>
      <div class="em-filter-actions"><button class="btn btn-primary" type="submit">Aplicar filtros</button><a class="btn btn-ghost" href="email_dashboard.php">Limpar</a></div>
    </form>
  </section>

  <div class="em-charts"><section class="em-card"><h2>Performance diária no período filtrado</h2><div class="em-chart"><canvas id="emailEvolutionChart"></canvas></div></section><section class="em-card"><h2>Funil de entrega e engajamento</h2><div class="em-chart"><canvas id="emailFunnelChart"></canvas></div></section></div>

  <section class="em-card">
    <h2>Desempenho por modelo de e-mail</h2>
    <p class="text-muted">Somente modelos com envios no período filtrado. Ranking por taxa de abertura sobre o total enviado.</p>
    <div class="em-template-metrics">
      <?php foreach($templateRows as $r):$sent=(int)($r['sent']??0);$delivered=(int)($r['delivered']??0);$opened=(int)($r['opened']??0);$clicked=(int)($r['clicked']??0);$bounced=(int)($r['bounced']??0);$bars=[['Envios',$sent,'sent'],['Entregues',$delivered,'delivered'],['Abertos',$opened,'opened'],['Cliques',$clicked,'clicked'],['Bounces',$bounced,'bounced']];?>
        <article class="em-template-row"><div class="em-template-title"><strong><?=email_h($r['name'])?></strong><span><?=email_h($r['subject']??'')?></span></div><div class="em-bars"><?php foreach($bars as [$label,$value,$kind]):?><div class="em-bar-line"><span><?=email_h($label)?></span><div class="em-bar-track"><div class="em-bar-fill em-bar-<?=$kind?> <?=$value?'':'zero'?>" style="width:<?=em_width($value,$maxTemplateValue)?>"></div></div><b><?=number_format($value,0,',','.')?></b></div><?php endforeach?></div><div class="em-rate-cards"><div class="em-rate-card good"><small>Entrega</small><strong><?=em_pct($delivered,$sent)?></strong></div><div class="em-rate-card info"><small>Abertura</small><strong><?=em_pct($opened,$sent)?></strong></div><div class="em-rate-card warn"><small>Clique</small><strong><?=em_pct($clicked,$sent)?></strong></div><div class="em-rate-card bad"><small>Bounce</small><strong><?=em_pct($bounced,$sent)?></strong></div></div></article>
      <?php endforeach?><?php if(!$templateRows):?><div class="text-muted">Nenhum modelo com envios no filtro aplicado.</div><?php endif?>
    </div>
  </section>

  <section class="em-card"><h2>Envios recentes</h2><div class="em-table"><table class="em-sortable" id="recentTable"><thead><tr><th data-sort="date">Data</th><th data-sort="student">Aluno</th><th data-sort="subject">Assunto</th><th data-sort="model">Modelo</th><th data-sort="status">Status</th><th data-sort="delivery">Entrega</th><th data-sort="open">Abertura</th><th data-sort="click">Clique</th></tr></thead><tbody><?php foreach($recent as $r):$student=(string)($r['nome']?:$r['recipient_email']);$delivery=$r['delivered_at']?'sim':'nao';$open=$r['first_opened_at']?'sim':'nao';$click=$r['first_clicked_at']?'sim':'nao';?><tr><td data-value="<?=email_h((string)strtotime($r['created_at']))?>"><?=email_h(date('d/m/Y H:i',strtotime($r['created_at'])))?></td><td data-value="<?=email_h(mb_strtolower($student))?>"><?=email_h($student)?></td><td data-value="<?=email_h(mb_strtolower((string)$r['subject']))?>"><?=email_h($r['subject'])?></td><td data-value="<?=email_h(mb_strtolower((string)($r['template_name']??'')))?>"><?=email_h($r['template_name']??'-')?></td><td data-value="<?=email_h(mb_strtolower((string)$r['status']))?>"><span class="em-pill"><?=email_h($r['status'])?></span></td><td data-value="<?=$delivery?>"><?=$delivery==='sim'?'Sim':'-'?></td><td data-value="<?=$open?>"><?=$open==='sim'?'Sim':'-'?></td><td data-value="<?=$click?>"><?=$click==='sim'?'Sim':'-'?></td></tr><?php endforeach?><?php if(!$recent):?><tr><td colspan="8" class="text-muted">Nenhum envio encontrado para o filtro aplicado.</td></tr><?php endif?></tbody></table></div></section>
</div>
<script>
(()=>{if(window.Chart){const daily=<?=json_encode($daily,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,totals=<?=json_encode($totals,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,legend={labels:{color:'#94a3b8'}};new Chart(document.getElementById('emailEvolutionChart'),{type:'line',data:{labels:daily.map(x=>x.day.split('-').reverse().slice(0,2).join('/')),datasets:[{label:'Entregues',data:daily.map(x=>+x.delivered),borderColor:'#22c55e',backgroundColor:'#22c55e33',fill:true,tension:.3},{label:'Aberturas',data:daily.map(x=>+x.opened),borderColor:'#38bdf8',tension:.3},{label:'Cliques',data:daily.map(x=>+x.clicked),borderColor:'#facc15',tension:.3},{label:'Bounces',data:daily.map(x=>+x.bounced),borderColor:'#ef4444',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend},scales:{x:{ticks:{color:'#64748b'}},y:{beginAtZero:true,ticks:{precision:0,color:'#64748b'}}}}});new Chart(document.getElementById('emailFunnelChart'),{type:'doughnut',data:{labels:['Entregues','Abertos','Clicados','Bounces','Reclamações'],datasets:[{data:[+totals.delivered||0,+totals.opened||0,+totals.clicked||0,+totals.bounced||0,+totals.complaints||0],backgroundColor:['#22c55e','#38bdf8','#facc15','#ef4444','#a78bfa']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{color:'#94a3b8'}}}}})}const table=document.getElementById('recentTable');if(!table)return;const tbody=table.tBodies[0],rows=[...tbody.rows];let sortKey='date',sortDir='desc';const idx={date:0,student:1,subject:2,model:3,status:4,delivery:5,open:6,click:7};function sortRows(key){sortDir=sortKey===key&&sortDir==='asc'?'desc':'asc';sortKey=key;table.querySelectorAll('th').forEach(th=>th.classList.remove('sorted-asc','sorted-desc'));table.querySelector(`th[data-sort="${key}"]`)?.classList.add(sortDir==='asc'?'sorted-asc':'sorted-desc');rows.sort((a,b)=>{const av=a.cells[idx[key]]?.dataset.value||'',bv=b.cells[idx[key]]?.dataset.value||'',an=Number(av),bn=Number(bv);let cmp=!Number.isNaN(an)&&!Number.isNaN(bn)?an-bn:av.localeCompare(bv,'pt-BR');return sortDir==='asc'?cmp:-cmp}).forEach(r=>tbody.appendChild(r))}table.querySelectorAll('th[data-sort]').forEach(th=>th.onclick=()=>sortRows(th.dataset.sort));sortRows('date')})();
</script>
<?php include __DIR__.'/_footer.php'; ?>
