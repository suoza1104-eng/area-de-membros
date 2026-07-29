<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/telegram_groups.php';

proteger_admin();
$pdo = getPDO();
telegram_ensure_schema($pdo);

$menu = 'telegram';
$page_title = 'Telegram';
$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['telegram']['escrever']);
}
if (empty($_SESSION['telegram_csrf'])) $_SESSION['telegram_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['telegram_csrf'];
$tab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview','groups','messages','ai','logs','settings'], true)) $tab = 'overview';
$notice = '';
$error = '';

function tg_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function tg_dt($v): string { $s=trim((string)$v); if($s==='') return ''; $t=strtotime($s); return $t?date('d/m/Y H:i:s',$t):$s; }
function tg_check(string $csrf): void { if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.'); }
function tg_redirect(string $tab, string $msg = ''): void { header('Location: telegram.php?tab=' . rawurlencode($tab) . ($msg !== '' ? '&msg=' . rawurlencode($msg) : '')); exit; }

if (isset($_GET['msg'])) $notice = (string)$_GET['msg'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Sem permissao para alterar Telegram.');
        tg_check($csrf);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            telegram_set_setting('bot_token', trim((string)($_POST['bot_token'] ?? '')));
            telegram_set_setting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
            telegram_set_setting('ai_model', trim((string)($_POST['ai_model'] ?? 'gpt-5.4-mini')) ?: 'gpt-5.4-mini');
            tg_redirect('settings', 'Configuracoes salvas.');
        } elseif ($action === 'install_webhook') {
            telegram_install_webhook();
            tg_redirect('settings', 'Webhook instalado no Telegram.');
        } elseif ($action === 'send_test') {
            telegram_send_message(trim((string)$_POST['chat_id']), trim((string)$_POST['message']));
            tg_redirect('groups', 'Mensagem de teste enviada.');
        } elseif ($action === 'save_auto_message') {
            $groupId = (int)($_POST['group_id'] ?? 0);
            $trigger = (string)($_POST['trigger_type'] ?? 'scheduled');
            if (!in_array($trigger, ['scheduled','member_joined','member_left'], true)) $trigger = 'scheduled';
            $sendAt = trim((string)($_POST['send_at'] ?? ''));
            $next = $trigger === 'scheduled' ? ($sendAt !== '' ? date('Y-m-d H:i:s', strtotime($sendAt)) : date('Y-m-d H:i:s')) : null;
            $pdo->prepare("INSERT INTO telegram_auto_messages (group_id,name,trigger_type,message_text,send_at,repeat_minutes,status,next_run_at,created_at,updated_at)
                VALUES (:group_id,:name,:trigger,:text,:send_at,:repeat,'active',:next,NOW(),NOW())")
                ->execute([
                    'group_id'=>$groupId > 0 ? $groupId : null,
                    'name'=>mb_substr(trim((string)($_POST['name'] ?? 'Mensagem Telegram')),0,180),
                    'trigger'=>$trigger,
                    'text'=>trim((string)($_POST['message_text'] ?? '')),
                    'send_at'=>$next,
                    'repeat'=>max(0,(int)($_POST['repeat_minutes'] ?? 0)),
                    'next'=>$next,
                ]);
            tg_redirect('messages', 'Mensagem automatica criada.');
        } elseif ($action === 'toggle_auto_message') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE telegram_auto_messages SET status=IF(status='active','paused','active'),updated_at=NOW() WHERE id=:id")->execute(['id'=>$id]);
            tg_redirect('messages', 'Status atualizado.');
        } elseif ($action === 'delete_auto_message') {
            $pdo->prepare("DELETE FROM telegram_auto_messages WHERE id=:id")->execute(['id'=>(int)$_POST['id']]);
            tg_redirect('messages', 'Mensagem removida.');
        } elseif ($action === 'save_ai_rule') {
            $pdo->prepare("INSERT INTO telegram_ai_rules (name,group_id,is_enabled,mode,prompt,min_confidence,action_policy,created_at,updated_at)
                VALUES (:name,:group_id,1,:mode,:prompt,:confidence,:policy,NOW(),NOW())")
                ->execute([
                    'name'=>mb_substr(trim((string)($_POST['name'] ?? 'Regra IA')),0,180),
                    'group_id'=>(int)($_POST['group_id'] ?? 0) > 0 ? (int)$_POST['group_id'] : null,
                    'mode'=>in_array((string)($_POST['mode'] ?? ''), ['suggest','auto'], true) ? (string)$_POST['mode'] : 'suggest',
                    'prompt'=>trim((string)($_POST['prompt'] ?? '')),
                    'confidence'=>max(0,min(1,(float)($_POST['min_confidence'] ?? .8))),
                    'policy'=>(string)($_POST['action_policy'] ?? 'reply_only'),
                ]);
            tg_redirect('ai', 'Regra de IA criada.');
        } elseif ($action === 'toggle_ai_rule') {
            $pdo->prepare("UPDATE telegram_ai_rules SET is_enabled=IF(is_enabled=1,0,1),updated_at=NOW() WHERE id=:id")->execute(['id'=>(int)$_POST['id']]);
            tg_redirect('ai', 'Regra atualizada.');
        } elseif ($action === 'execute_ai_action') {
            telegram_execute_ai_action($pdo, (int)$_POST['id']);
            tg_redirect('ai', 'Acao processada.');
        } elseif ($action === 'ignore_ai_action') {
            $pdo->prepare("UPDATE telegram_ai_actions SET status='ignored',updated_at=NOW() WHERE id=:id")->execute(['id'=>(int)$_POST['id']]);
            tg_redirect('ai', 'Acao ignorada.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$groups = $pdo->query('SELECT * FROM telegram_groups ORDER BY last_event_at DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$stats = [
    'groups'=>(int)$pdo->query('SELECT COUNT(*) FROM telegram_groups')->fetchColumn(),
    'members'=>(int)$pdo->query("SELECT COUNT(*) FROM telegram_members WHERE status IN ('member','administrator','creator')")->fetchColumn(),
    'joins'=>(int)$pdo->query("SELECT COUNT(*) FROM telegram_events WHERE event_type='member_joined' AND received_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
    'lefts'=>(int)$pdo->query("SELECT COUNT(*) FROM telegram_events WHERE event_type='member_left' AND received_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
    'messages'=>(int)$pdo->query("SELECT COUNT(*) FROM telegram_events WHERE event_type IN ('message','message_edited') AND received_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
    'ai_pending'=>(int)$pdo->query("SELECT COUNT(*) FROM telegram_ai_actions WHERE status IN ('pending','ready')")->fetchColumn(),
];
$autoMessages = $pdo->query("SELECT m.*,g.title group_title,g.chat_id FROM telegram_auto_messages m LEFT JOIN telegram_groups g ON g.id=m.group_id ORDER BY m.id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$aiRules = $pdo->query("SELECT r.*,g.title group_title FROM telegram_ai_rules r LEFT JOIN telegram_groups g ON g.id=r.group_id ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$aiActions = $pdo->query("SELECT a.*,g.title group_title,g.chat_id,e.message_text FROM telegram_ai_actions a LEFT JOIN telegram_groups g ON g.id=a.group_id LEFT JOIN telegram_events e ON e.id=a.event_id ORDER BY a.id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$logs = $pdo->query("SELECT e.*,g.title group_title FROM telegram_events e LEFT JOIN telegram_groups g ON g.id=e.group_id ORDER BY e.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$webhookUrl = telegram_webhook_url();
$tokenMask = telegram_bot_token() !== '' ? substr(telegram_bot_token(), 0, 8) . '...' . substr(telegram_bot_token(), -5) : 'nao configurado';

include __DIR__ . '/_header.php';
?>
<style>
.tg{display:grid;gap:14px}.tg-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.tg-head h1{font-size:22px}.tg-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px}.tg-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.tg-nav a.active,.tg-nav a:hover{background:var(--primary-dim);color:var(--primary)}.tg-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:var(--shadow)}.tg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.tg-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.tg-kpi strong{font-size:25px}.tg-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.tg-field label{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;text-transform:uppercase}.tg-field input,.tg-field select,.tg-field textarea{width:100%;padding:9px 11px;border:1px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text)}.tg-field textarea{min-height:96px}.tg-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.tg-table{overflow:auto}.tg-table table{width:100%;border-collapse:collapse}.tg-table th,.tg-table td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:top}.tg-table th{font-size:10px;color:var(--muted);text-transform:uppercase}.tg-pill{display:inline-flex;padding:3px 8px;border-radius:999px;background:var(--bg-hover);font-size:10px}.tg-pill.ok{background:var(--success-dim);color:#86efac}.tg-pill.warn{background:var(--warning-dim);color:#facc15}.tg-pill.bad{background:var(--danger-dim);color:#fca5a5}.tg-code{display:block;padding:9px;border:1px solid var(--border);border-radius:8px;background:#071020;color:#bae6fd;word-break:break-all;font-size:12px}.tg-note{font-size:11px;color:var(--muted);line-height:1.45}.tg-msg{padding:10px 12px;border-radius:9px;background:var(--success-dim);color:#86efac}.tg-error{padding:10px 12px;border-radius:9px;background:var(--danger-dim);color:#fca5a5}.tg-wide{grid-column:1/-1}@media(max-width:800px){.tg-head{display:grid}.tg-actions{width:100%}}
</style>
<div class="tg">
  <div class="tg-head">
    <div><h1>Telegram</h1><p class="text-muted">Monitoramento de grupos, entradas, saidas, mensagens automaticas, IA e moderacao.</p></div>
    <div class="tg-actions"><span class="tg-pill <?=telegram_bot_token()!==''?'ok':'bad'?>">Bot <?=$tokenMask?></span><span class="tg-pill <?=telegram_setting('ai_enabled','0')==='1'?'ok':'warn'?>">IA <?=telegram_setting('ai_enabled','0')==='1'?'ativa':'pausada'?></span></div>
  </div>
  <nav class="tg-nav">
    <?php foreach(['overview'=>'Visao geral','groups'=>'Grupos','messages'=>'Mensagens','ai'=>'IA e moderacao','logs'=>'Eventos e logs','settings'=>'Configuracoes'] as $k=>$label): ?>
      <a class="<?=$tab===$k?'active':''?>" href="telegram.php?tab=<?=$k?>"><?=tg_h($label)?></a>
    <?php endforeach; ?>
  </nav>
  <?php if($notice): ?><div class="tg-msg"><?=tg_h($notice)?></div><?php endif; ?>
  <?php if($error): ?><div class="tg-error"><?=tg_h($error)?></div><?php endif; ?>

<?php if($tab==='overview'): ?>
  <section class="tg-grid">
    <div class="tg-card tg-kpi"><small>Grupos monitorados</small><strong><?=$stats['groups']?></strong></div>
    <div class="tg-card tg-kpi"><small>Membros conhecidos</small><strong><?=$stats['members']?></strong></div>
    <div class="tg-card tg-kpi"><small>Entradas 7 dias</small><strong><?=$stats['joins']?></strong></div>
    <div class="tg-card tg-kpi"><small>Saidas 7 dias</small><strong><?=$stats['lefts']?></strong></div>
    <div class="tg-card tg-kpi"><small>Mensagens 7 dias</small><strong><?=$stats['messages']?></strong></div>
    <div class="tg-card tg-kpi"><small>Acoes IA pendentes</small><strong><?=$stats['ai_pending']?></strong></div>
  </section>
  <section class="tg-card">
    <div class="panel-title">Como esta preparado</div>
    <div class="tg-grid" style="margin-top:10px">
      <div><strong>Webhook em tempo real</strong><p class="tg-note">Recebe mensagens, entradas, saidas e mudancas do bot no grupo.</p></div>
      <div><strong>Automacoes</strong><p class="tg-note">Mensagens de boas-vindas, saida e programadas pelo cron.</p></div>
      <div><strong>IA e moderacao</strong><p class="tg-note">Analisa mensagens, sugere resposta, responde ou bane quando configurado em modo automatico.</p></div>
      <div><strong>Logs auditaveis</strong><p class="tg-note">Cada update recebido fica registrado para conferencia e diagnostico.</p></div>
    </div>
  </section>
<?php elseif($tab==='groups'): ?>
  <section class="tg-card">
    <div class="panel-title">Grupos detectados</div>
    <div class="tg-table mt-3"><table><thead><tr><th>Grupo</th><th>Chat ID</th><th>Membros</th><th>Entradas</th><th>Saidas</th><th>Mensagens</th><th>Permissoes bot</th><th>Ultimo evento</th></tr></thead><tbody>
      <?php foreach($groups as $g): ?><tr><td><strong><?=tg_h($g['title'])?></strong><br><span class="tg-note"><?=tg_h($g['type'])?></span></td><td><code><?=tg_h($g['chat_id'])?></code></td><td><?=(int)$g['member_count']?></td><td><?=(int)$g['joined_count']?></td><td><?=(int)$g['left_count']?></td><td><?=(int)$g['message_count']?></td><td><span class="tg-pill <?=!empty($g['can_restrict_members'])?'ok':'warn'?>">ban <?=!empty($g['can_restrict_members'])?'ok':'pendente'?></span> <span class="tg-pill <?=!empty($g['can_delete_messages'])?'ok':'warn'?>">delete <?=!empty($g['can_delete_messages'])?'ok':'pendente'?></span></td><td><?=tg_h(tg_dt($g['last_event_at']))?></td></tr><?php endforeach; ?>
      <?php if(!$groups): ?><tr><td colspan="8" class="tg-note">Nenhum grupo recebido ainda. Adicione o bot ao grupo e mande uma mensagem la.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="tg-card">
    <div class="panel-title">Enviar teste</div>
    <form method="post" class="tg-form mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="send_test">
      <label class="tg-field"><label>Chat ID</label><input name="chat_id" placeholder="-100..." required></label>
      <label class="tg-field tg-wide"><label>Mensagem</label><textarea name="message" required>Teste de envio pelo painel Telegram.</textarea></label>
      <div class="tg-actions"><button class="btn btn-primary" type="submit">Enviar mensagem</button></div>
    </form>
  </section>
<?php elseif($tab==='messages'): ?>
  <section class="tg-card">
    <div class="panel-title">Nova mensagem automatica</div>
    <form method="post" class="tg-form mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="save_auto_message">
      <label class="tg-field"><label>Nome</label><input name="name" value="Boas-vindas Telegram" required></label>
      <label class="tg-field"><label>Grupo</label><select name="group_id"><option value="0">Todos os grupos</option><?php foreach($groups as $g): ?><option value="<?=(int)$g['id']?>"><?=tg_h($g['title'])?></option><?php endforeach; ?></select></label>
      <label class="tg-field"><label>Gatilho</label><select name="trigger_type"><option value="member_joined">Quando entrar</option><option value="member_left">Quando sair</option><option value="scheduled">Data/hora programada</option></select></label>
      <label class="tg-field"><label>Enviar em</label><input type="datetime-local" name="send_at"></label>
      <label class="tg-field"><label>Repetir a cada X minutos</label><input type="number" name="repeat_minutes" min="0" value="0"></label>
      <label class="tg-field tg-wide"><label>Mensagem</label><textarea name="message_text" required>Bem-vindo, {{nome}}. Aula ao vivo chegando.</textarea><span class="tg-note">Variaveis: {{nome}}, {{username}}, {{grupo}}, {{chat_id}}, {{telegram_id}}</span></label>
      <div class="tg-actions"><button class="btn btn-primary" type="submit">Salvar mensagem</button></div>
    </form>
  </section>
  <section class="tg-card">
    <div class="panel-title">Mensagens cadastradas</div>
    <div class="tg-table mt-3"><table><thead><tr><th>Nome</th><th>Grupo</th><th>Gatilho</th><th>Status</th><th>Proximo envio</th><th>Enviadas</th><th>Acoes</th></tr></thead><tbody>
      <?php foreach($autoMessages as $m): ?><tr><td><?=tg_h($m['name'])?></td><td><?=tg_h($m['group_title'] ?: 'Todos')?></td><td><?=tg_h($m['trigger_type'])?></td><td><span class="tg-pill <?=$m['status']==='active'?'ok':'warn'?>"><?=tg_h($m['status'])?></span></td><td><?=tg_h(tg_dt($m['next_run_at']))?></td><td><?=(int)$m['sent_count']?></td><td><form method="post" class="tg-actions"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="btn btn-ghost sm" name="action" value="toggle_auto_message">Pausar/ativar</button><button class="btn btn-danger sm" name="action" value="delete_auto_message" onclick="return confirm('Excluir mensagem?')">Excluir</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
<?php elseif($tab==='ai'): ?>
  <section class="tg-card">
    <div class="panel-title">Nova regra de IA</div>
    <form method="post" class="tg-form mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="save_ai_rule">
      <label class="tg-field"><label>Nome</label><input name="name" value="Moderacao e respostas" required></label>
      <label class="tg-field"><label>Grupo</label><select name="group_id"><option value="0">Todos os grupos</option><?php foreach($groups as $g): ?><option value="<?=(int)$g['id']?>"><?=tg_h($g['title'])?></option><?php endforeach; ?></select></label>
      <label class="tg-field"><label>Modo</label><select name="mode"><option value="suggest">Sugerir para aprovar</option><option value="auto">Automatico</option></select></label>
      <label class="tg-field"><label>Confianca minima</label><input name="min_confidence" type="number" step="0.01" min="0" max="1" value="0.80"></label>
      <label class="tg-field"><label>Politica</label><select name="action_policy"><option value="reply_only">Responder apenas</option><option value="reply_or_flag">Responder ou sinalizar</option><option value="reply_or_ban">Responder ou banir</option></select></label>
      <label class="tg-field tg-wide"><label>Prompt</label><textarea name="prompt" required>Voce monitora um grupo Telegram de alunos. Classifique spam, golpes, links suspeitos, ofensas e duvidas reais. Responda duvidas simples com tom curto e profissional. So use ban para spam/golpe/ofensa grave com alta confianca.</textarea></label>
      <div class="tg-actions"><button class="btn btn-primary" type="submit">Salvar regra</button></div>
    </form>
  </section>
  <section class="tg-card">
    <div class="panel-title">Regras</div>
    <div class="tg-table mt-3"><table><thead><tr><th>Regra</th><th>Grupo</th><th>Modo</th><th>Confianca</th><th>Status</th><th>Acoes</th></tr></thead><tbody>
      <?php foreach($aiRules as $r): ?><tr><td><?=tg_h($r['name'])?></td><td><?=tg_h($r['group_title'] ?: 'Todos')?></td><td><?=tg_h($r['mode'])?></td><td><?=number_format((float)$r['min_confidence'],2,',','.')?></td><td><span class="tg-pill <?=!empty($r['is_enabled'])?'ok':'warn'?>"><?=!empty($r['is_enabled'])?'ativa':'pausada'?></span></td><td><form method="post"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn btn-ghost sm" name="action" value="toggle_ai_rule">Pausar/ativar</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="tg-card">
    <div class="panel-title">Fila de IA e moderacao</div>
    <div class="tg-table mt-3"><table><thead><tr><th>Quando</th><th>Grupo</th><th>Acao</th><th>Conf.</th><th>Mensagem</th><th>Motivo/resposta</th><th>Status</th><th>Acoes</th></tr></thead><tbody>
      <?php foreach($aiActions as $a): ?><tr><td><?=tg_h(tg_dt($a['created_at']))?></td><td><?=tg_h($a['group_title'])?></td><td><?=tg_h($a['action'])?></td><td><?=number_format((float)$a['confidence']*100,1,',','.')?>%</td><td><?=tg_h(mb_substr((string)$a['message_text'],0,120))?></td><td><?=tg_h(mb_substr((string)($a['reason'] ?: $a['suggested_reply']),0,180))?></td><td><span class="tg-pill <?=$a['status']==='executed'?'ok':($a['status']==='failed'?'bad':'warn')?>"><?=tg_h($a['status'])?></span></td><td><form method="post" class="tg-actions"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$a['id']?>"><button class="btn btn-primary sm" name="action" value="execute_ai_action">Executar</button><button class="btn btn-ghost sm" name="action" value="ignore_ai_action">Ignorar</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
<?php elseif($tab==='logs'): ?>
  <section class="tg-card">
    <div class="panel-title">Eventos recebidos</div>
    <div class="tg-table mt-3"><table><thead><tr><th>Quando</th><th>Grupo</th><th>Evento</th><th>User ID</th><th>Mensagem</th><th>Status</th></tr></thead><tbody>
      <?php foreach($logs as $l): ?><tr><td><?=tg_h(tg_dt($l['received_at']))?></td><td><?=tg_h($l['group_title'])?></td><td><?=tg_h($l['event_type'])?></td><td><?=tg_h($l['telegram_user_id'])?></td><td><?=tg_h(mb_substr((string)$l['message_text'],0,180))?></td><td><?=tg_h($l['processed_status'])?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
<?php elseif($tab==='settings'): ?>
  <section class="tg-card">
    <div class="panel-title">Conexao Telegram</div>
    <form method="post" class="tg-form mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="save_settings">
      <label class="tg-field tg-wide"><label>Token do bot</label><input type="password" name="bot_token" value="<?=tg_h(telegram_bot_token())?>" autocomplete="new-password" placeholder="123456:ABC..."></label>
      <label class="tg-field"><label>Modelo IA</label><input name="ai_model" value="<?=tg_h(telegram_setting('ai_model','gpt-5.4-mini'))?>"></label>
      <label class="tg-field"><label>IA ativa</label><select name="ai_enabled_select" onchange="document.getElementById('tg-ai-enabled').checked=this.value==='1'"><option value="0" <?=telegram_setting('ai_enabled','0')!=='1'?'selected':''?>>Nao</option><option value="1" <?=telegram_setting('ai_enabled','0')==='1'?'selected':''?>>Sim</option></select><input id="tg-ai-enabled" type="checkbox" name="ai_enabled" value="1" <?=telegram_setting('ai_enabled','0')==='1'?'checked':''?> style="display:none"></label>
      <div class="tg-actions tg-wide"><button class="btn btn-primary" type="submit">Salvar configuracoes</button></div>
    </form>
  </section>
  <section class="tg-card">
    <div class="panel-title">Webhook</div>
    <p class="tg-note">URL publica configurada para o Bot API.</p>
    <code class="tg-code"><?=tg_h($webhookUrl)?></code>
    <form method="post" class="tg-actions mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><button class="btn btn-primary" name="action" value="install_webhook">Instalar webhook no Telegram</button></form>
  </section>
  <section class="tg-card">
    <div class="panel-title">Checklist do grupo</div>
    <div class="tg-grid mt-3">
      <div><strong>BotFather</strong><p class="tg-note">Crie o bot, copie o token e desative privacy mode se quiser ler todas as mensagens do grupo.</p></div>
      <div><strong>Grupo</strong><p class="tg-note">Adicione o bot no seu grupo de 3 mil pessoas e promova como administrador.</p></div>
      <div><strong>Permissoes</strong><p class="tg-note">Para banir ou apagar spam, habilite restringir membros e apagar mensagens.</p></div>
      <div><strong>Teste</strong><p class="tg-note">Mande uma mensagem no grupo. O grupo deve aparecer na aba Grupos.</p></div>
    </div>
  </section>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>

