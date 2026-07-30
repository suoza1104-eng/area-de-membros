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
$aiDraft = null;

function tg_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function tg_dt($v): string { $s=trim((string)$v); if($s==='') return ''; $t=strtotime($s); return $t?date('d/m/Y H:i:s',$t):$s; }
function tg_json_attr($v): string { return tg_h(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)); }
function tg_check(string $csrf): void { if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessao expirada. Recarregue a pagina.'); }
function tg_redirect(string $tab, string $msg = ''): void { header('Location: telegram.php?tab=' . rawurlencode($tab) . ($msg !== '' ? '&msg=' . rawurlencode($msg) : '')); exit; }
function tg_build_buttons(array $post): array {
    $buttons = [];
    $types = is_array($post['button_type'] ?? null) ? $post['button_type'] : [];
    $labels = is_array($post['button_text'] ?? null) ? $post['button_text'] : [];
    $urls = is_array($post['button_url'] ?? null) ? $post['button_url'] : [];
    $payloads = is_array($post['button_payload'] ?? null) ? $post['button_payload'] : [];
    $widths = is_array($post['button_width'] ?? null) ? $post['button_width'] : [];
    foreach ($labels as $i => $label) {
        $text = trim((string)$label);
        $type = in_array((string)($types[$i] ?? 'url'), ['url','private_bot','callback'], true) ? (string)$types[$i] : 'url';
        $width = (string)($widths[$i] ?? 'full') === 'half' ? 'half' : 'full';
        $url = trim((string)($urls[$i] ?? ''));
        $payload = trim((string)($payloads[$i] ?? ''));
        if ($text === '') continue;
        $button = ['type'=>$type, 'text'=>mb_substr($text, 0, 64), 'width'=>$width];
        if ($type === 'private_bot') {
            $private = telegram_private_bot_url();
            if ($private === '') throw new RuntimeException('Salve o token e instale o webhook para detectar o usuario do bot antes de criar botao privado.');
            $button['url'] = $payload !== '' ? $private . '?start=' . rawurlencode(mb_substr($payload, 0, 64)) : $private;
        } elseif ($type === 'callback') {
            if ($payload === '') throw new RuntimeException('Botao "' . $text . '" precisa de uma acao/comando.');
            $button['callback_data'] = mb_substr($payload, 0, 64);
        } else {
            if ($url === '') continue;
            if (!preg_match('~^(https://|tg://)~i', $url)) throw new RuntimeException('Botao "' . $text . '" precisa usar URL https:// ou tg://.');
            $button['url'] = $url;
        }
        $buttons[] = $button;
    }
    return array_slice($buttons, 0, 8);
}
function tg_media_upload_url(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no upload da midia.');
    $original = (string)($file['name'] ?? 'midia');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp','gif','mp4','mov','webm'];
    if (!in_array($ext, $allowed, true)) throw new RuntimeException('Use imagem jpg/png/webp/gif ou video mp4/mov/webm.');
    $dir = dirname(__DIR__) . '/uploads/telegram';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Nao foi possivel criar pasta de uploads.');
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?: 'midia';
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . substr($safe, 0, 70) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('Nao foi possivel salvar a midia.');
    return rtrim(BASE_URL, '/') . '/uploads/telegram/' . rawurlencode($name);
}

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
            if (telegram_bot_token() !== '') telegram_refresh_bot_profile();
            tg_redirect('settings', 'Configuracoes salvas.');
        } elseif ($action === 'install_webhook') {
            telegram_install_webhook();
            tg_redirect('settings', 'Webhook instalado no Telegram.');
        } elseif ($action === 'generate_message_ai') {
            $aiDraft = telegram_generate_message_ai((string)($_POST['ai_prompt'] ?? ''), [
                'group_id'=>(int)($_POST['group_id'] ?? 0),
                'trigger_type'=>(string)($_POST['trigger_type'] ?? 'member_joined'),
            ]);
            $tab = 'messages';
        } elseif ($action === 'send_test') {
            telegram_send_message(trim((string)$_POST['chat_id']), trim((string)$_POST['message']));
            tg_redirect('groups', 'Mensagem de teste enviada.');
        } elseif ($action === 'save_auto_message') {
            $id = (int)($_POST['id'] ?? 0);
            $groupId = (int)($_POST['group_id'] ?? 0);
            $trigger = (string)($_POST['trigger_type'] ?? 'scheduled');
            if (!in_array($trigger, ['scheduled','member_joined','member_left'], true)) $trigger = 'scheduled';
            $kind = in_array((string)($_POST['message_kind'] ?? 'text'), ['text','photo','video'], true) ? (string)$_POST['message_kind'] : 'text';
            $mediaUrl = tg_media_upload_url($_FILES['media_file'] ?? []);
            if ($mediaUrl === '') $mediaUrl = trim((string)($_POST['media_url'] ?? ''));
            $buttons = tg_build_buttons($_POST);
            $sendAt = trim((string)($_POST['send_at'] ?? ''));
            $next = $trigger === 'scheduled' ? ($sendAt !== '' ? date('Y-m-d H:i:s', strtotime($sendAt)) : date('Y-m-d H:i:s')) : null;
            $params = [
                'group_id'=>$groupId > 0 ? $groupId : null,
                'name'=>mb_substr(trim((string)($_POST['name'] ?? 'Mensagem Telegram')),0,180),
                'trigger'=>$trigger,
                'kind'=>$kind,
                'text'=>trim((string)($_POST['message_text'] ?? '')),
                'media'=>$mediaUrl !== '' ? $mediaUrl : null,
                'buttons'=>$buttons ? json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'parse_mode'=>in_array((string)($_POST['parse_mode'] ?? ''), ['HTML','MarkdownV2'], true) ? (string)$_POST['parse_mode'] : null,
                'send_at'=>$next,
                'repeat'=>max(0,(int)($_POST['repeat_minutes'] ?? 0)),
                'next'=>$next,
            ];
            if ($id > 0) {
                $params['id'] = $id;
                $pdo->prepare("UPDATE telegram_auto_messages SET group_id=:group_id,name=:name,trigger_type=:trigger,message_kind=:kind,message_text=:text,media_url=:media,buttons_json=:buttons,parse_mode=:parse_mode,send_at=:send_at,repeat_minutes=:repeat,next_run_at=:next,updated_at=NOW() WHERE id=:id")->execute($params);
                tg_redirect('messages', 'Mensagem atualizada.');
            }
            $pdo->prepare("INSERT INTO telegram_auto_messages (group_id,name,trigger_type,message_kind,message_text,media_url,buttons_json,parse_mode,send_at,repeat_minutes,status,next_run_at,created_at,updated_at)
                VALUES (:group_id,:name,:trigger,:kind,:text,:media,:buttons,:parse_mode,:send_at,:repeat,'active',:next,NOW(),NOW())")->execute($params);
            tg_redirect('messages', 'Mensagem automatica criada.');
        } elseif ($action === 'toggle_auto_message') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE telegram_auto_messages SET status=IF(status='active','paused','active'),updated_at=NOW() WHERE id=:id")->execute(['id'=>$id]);
            tg_redirect('messages', 'Status atualizado.');
        } elseif ($action === 'clone_auto_message') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("INSERT INTO telegram_auto_messages (group_id,name,trigger_type,message_kind,message_text,media_url,buttons_json,parse_mode,send_at,repeat_minutes,status,next_run_at,created_at,updated_at)
                SELECT group_id,CONCAT(name,' (copia)'),trigger_type,message_kind,message_text,media_url,buttons_json,parse_mode,send_at,repeat_minutes,'paused',next_run_at,NOW(),NOW()
                FROM telegram_auto_messages WHERE id=:id")->execute(['id'=>$id]);
            tg_redirect('messages', 'Mensagem clonada como rascunho pausado.');
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
$autoMessages = $pdo->query("SELECT m.*,g.title group_title,g.chat_id FROM telegram_auto_messages m LEFT JOIN telegram_groups g ON g.id=m.group_id ORDER BY COALESCE(m.next_run_at,m.send_at,m.created_at) ASC,m.id ASC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$aiRules = $pdo->query("SELECT r.*,g.title group_title FROM telegram_ai_rules r LEFT JOIN telegram_groups g ON g.id=r.group_id ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$aiActions = $pdo->query("SELECT a.*,g.title group_title,g.chat_id,e.message_text FROM telegram_ai_actions a LEFT JOIN telegram_groups g ON g.id=a.group_id LEFT JOIN telegram_events e ON e.id=a.event_id ORDER BY a.id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$logs = $pdo->query("SELECT e.*,g.title group_title FROM telegram_events e LEFT JOIN telegram_groups g ON g.id=e.group_id ORDER BY e.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$webhookUrl = telegram_webhook_url();
$tokenMask = telegram_bot_token() !== '' ? substr(telegram_bot_token(), 0, 8) . '...' . substr(telegram_bot_token(), -5) : 'nao configurado';

include __DIR__ . '/_header.php';
?>
<style>
.tg{display:grid;gap:14px}.tg-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.tg-head h1{font-size:22px}.tg-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:10px}.tg-nav a{padding:7px 10px;border-radius:8px;color:var(--muted);font-size:12px;text-decoration:none}.tg-nav a.active,.tg-nav a:hover{background:var(--primary-dim);color:var(--primary)}.tg-card{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px;box-shadow:var(--shadow)}.tg-subpanel{padding-top:10px;border-top:1px solid var(--border)}.tg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.tg-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.tg-kpi strong{font-size:25px}.tg-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.tg-field label{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;text-transform:uppercase}.tg-field input,.tg-field select,.tg-field textarea{width:100%;padding:9px 11px;border:1px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text)}.tg-field textarea{min-height:96px}.tg-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.tg-table{overflow:auto}.tg-table table{width:100%;border-collapse:collapse}.tg-table th,.tg-table td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:top}.tg-table th{font-size:10px;color:var(--muted);text-transform:uppercase}.tg-pill{display:inline-flex;padding:3px 8px;border-radius:999px;background:var(--bg-hover);font-size:10px}.tg-pill.ok{background:var(--success-dim);color:#86efac}.tg-pill.warn{background:var(--warning-dim);color:#facc15}.tg-pill.bad{background:var(--danger-dim);color:#fca5a5}.tg-code{display:block;padding:9px;border:1px solid var(--border);border-radius:8px;background:#071020;color:#bae6fd;word-break:break-all;font-size:12px}.tg-note{font-size:11px;color:var(--muted);line-height:1.45}.tg-msg{padding:10px 12px;border-radius:9px;background:var(--success-dim);color:#86efac}.tg-error{padding:10px 12px;border-radius:9px;background:var(--danger-dim);color:#fca5a5}.tg-wide{grid-column:1/-1}.tg-msg-workspace{display:grid;grid-template-columns:minmax(340px,1fr) minmax(360px,480px);gap:14px;align-items:start}.tg-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px}.tg-message-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(278px,1fr));gap:12px}.tg-message-card{border:1px solid var(--border);border-radius:8px;background:var(--bg);overflow:visible;min-height:250px;display:flex;flex-direction:column}.tg-message-card.active{outline:2px solid var(--primary);outline-offset:2px}.tg-message-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;background:var(--bg-hover);padding:10px 12px;border-radius:8px 8px 0 0}.tg-message-top strong{display:block;min-width:0;overflow-wrap:anywhere;line-height:1.25}.tg-message-time{font-size:11px;color:var(--muted);line-height:1.35}.tg-card-actions{display:flex;gap:4px;flex:0 0 auto}.tg-icon-btn{width:28px;height:28px;min-width:28px;border:1px solid var(--border);border-radius:8px;background:var(--bg-card);color:var(--text);display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.tg-icon-btn:hover{border-color:var(--primary);color:var(--primary)}.tg-card-body{padding:12px;display:grid;gap:10px;flex:1}.tg-preview-bubble{background:#dcf8c6;color:#294235;border-radius:8px 8px 2px 8px;padding:10px 11px;font-size:12px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;max-height:none;overflow:visible}.tg-preview-media{height:118px;border-radius:7px;background:linear-gradient(135deg,#d9f0ff,#f6f8fb);display:flex;align-items:center;justify-content:center;color:#4b5563;font-size:12px;border:1px solid rgba(0,0,0,.06)}.tg-preview-buttons{display:grid;gap:5px}.tg-preview-button{border:1px solid rgba(54,119,87,.28);background:rgba(255,255,255,.72);color:#26724a;text-align:center;border-radius:7px;padding:6px;font-size:11px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tg-empty{border:1px dashed var(--border);border-radius:8px;padding:30px;text-align:center;color:var(--muted)}.tg-editor{position:sticky;top:82px}.tg-editor-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}.tg-editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tg-button-row{border:1px solid var(--border);border-radius:8px;padding:10px;background:var(--bg);display:grid;gap:8px}.tg-button-row-head{display:flex;justify-content:space-between;gap:8px;align-items:center}.tg-button-settings{display:grid;grid-template-columns:1fr 1fr;gap:8px}.tg-ai-panel{display:none;margin-bottom:12px}.tg-ai-panel.open{display:block}.tg-form-actions{position:sticky;bottom:0;background:var(--bg-card);border-top:1px solid var(--border);padding-top:12px;margin-top:12px}.tg-confirm-form{display:inline}@media(max-width:1100px){.tg-msg-workspace{grid-template-columns:1fr}.tg-editor{position:static}}@media(max-width:800px){.tg-head{display:grid}.tg-actions{width:100%}.tg-editor-grid,.tg-button-settings{grid-template-columns:1fr}.tg-message-list{grid-template-columns:1fr}}
</style>
<style>
.tg-preview-buttons{grid-template-columns:1fr 1fr}
.tg-preview-button.full{grid-column:1/-1}
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
  <?php
    $draftButtons = is_array($aiDraft['button_suggestions'] ?? null) ? $aiDraft['button_suggestions'] : [];
    $initialDraft = ['name'=>$aiDraft['name'] ?? '', 'message'=>$aiDraft['message'] ?? '', 'buttons'=>$draftButtons];
  ?>
  <div class="tg-msg-workspace" data-ai-draft="<?=tg_json_attr($initialDraft)?>">
    <section class="tg-card">
      <div class="tg-toolbar">
        <div>
          <div class="panel-title">Mensagens em ordem cronologica</div>
          <div class="tg-note"><?=count($autoMessages)?> mensagem(ns) cadastrada(s)</div>
        </div>
        <button type="button" class="btn btn-primary" id="tgNewMessage">Nova mensagem</button>
      </div>
      <div class="tg-message-list" id="tgMessageList">
        <?php foreach($autoMessages as $m):
          $buttons = json_decode((string)($m['buttons_json'] ?? ''), true);
          if (!is_array($buttons)) $buttons = [];
          $payload = [
            'id'=>(int)$m['id'],
            'name'=>(string)$m['name'],
            'group_id'=>(int)($m['group_id'] ?? 0),
            'trigger_type'=>(string)$m['trigger_type'],
            'message_kind'=>(string)($m['message_kind'] ?? 'text'),
            'message_text'=>(string)$m['message_text'],
            'media_url'=>(string)($m['media_url'] ?? ''),
            'buttons'=>$buttons,
            'parse_mode'=>(string)($m['parse_mode'] ?? ''),
            'send_at'=>$m['send_at'] ? date('Y-m-d\TH:i', strtotime((string)$m['send_at'])) : '',
            'repeat_minutes'=>(int)$m['repeat_minutes'],
          ];
        ?>
          <article class="tg-message-card" data-message="<?=tg_json_attr($payload)?>">
            <div class="tg-message-top">
              <div>
                <strong><?=tg_h($m['name'])?></strong>
                <div class="tg-message-time"><?=tg_h($m['next_run_at'] ? tg_dt($m['next_run_at']) : ucfirst((string)$m['trigger_type']))?></div>
              </div>
              <div class="tg-card-actions">
                <button type="button" class="tg-icon-btn tg-edit-card" title="Editar">✎</button>
                <form method="post" class="tg-confirm-form"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="tg-icon-btn" name="action" value="clone_auto_message" title="Clonar">⧉</button></form>
                <form method="post" class="tg-confirm-form"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="tg-icon-btn" name="action" value="toggle_auto_message" title="Pausar ou ativar">Ⅱ</button></form>
                <form method="post" class="tg-confirm-form" onsubmit="return confirm('Excluir mensagem?')"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="tg-icon-btn" name="action" value="delete_auto_message" title="Apagar">×</button></form>
              </div>
            </div>
            <div class="tg-card-body">
              <div class="tg-actions"><span class="tg-pill <?=$m['status']==='active'?'ok':'warn'?>"><?=tg_h($m['status'])?></span><span class="tg-pill"><?=tg_h($m['message_kind'] ?? 'text')?></span><span class="tg-note"><?=tg_h($m['group_title'] ?: 'Todos os grupos')?></span></div>
              <?php if(($m['message_kind'] ?? 'text') !== 'text'): ?><div class="tg-preview-media"><?=tg_h($m['message_kind'])?><?=!empty($m['media_url'])?' anexado':''?></div><?php endif; ?>
              <div class="tg-preview-bubble"><?=tg_h(mb_substr((string)$m['message_text'], 0, 520))?></div>
              <?php if($buttons): ?><div class="tg-preview-buttons"><?php foreach(array_slice($buttons,0,8) as $b): $bw=(string)($b['width'] ?? 'full') === 'half' ? 'half' : 'full'; ?><div class="tg-preview-button <?=$bw?>"><?=tg_h($b['text'] ?? 'Botao')?></div><?php endforeach; ?></div><?php endif; ?>
              <div class="tg-note">Enviadas: <?=(int)$m['sent_count']?><?=!empty($m['last_sent_at'])?' · Ultimo: '.tg_h(tg_dt($m['last_sent_at'])):''?></div>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$autoMessages): ?><div class="tg-empty">Nenhuma mensagem cadastrada ainda.</div><?php endif; ?>
      </div>
    </section>

    <aside class="tg-card tg-editor">
      <div class="tg-editor-head">
        <div><div class="panel-title" id="tgEditorTitle">Configurar mensagem</div><div class="tg-note">Texto, midia, recorrencia e botoes do Telegram.</div></div>
        <button type="button" class="btn btn-ghost sm" id="tgToggleAi">IA</button>
      </div>
      <section class="tg-ai-panel tg-card" id="tgAiPanel">
        <form method="post" class="tg-form"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="generate_message_ai">
          <label class="tg-field"><label>Grupo de contexto</label><select name="group_id"><option value="0">Todos os grupos</option><?php foreach($groups as $g): ?><option value="<?=(int)$g['id']?>"><?=tg_h($g['title'])?></option><?php endforeach; ?></select></label>
          <label class="tg-field"><label>Objetivo</label><select name="trigger_type"><option value="member_joined">Boas-vindas</option><option value="scheduled">Aviso programado</option><option value="member_left">Recuperar saida</option></select></label>
          <label class="tg-field tg-wide"><label>Prompt</label><textarea name="ai_prompt" placeholder="Ex: crie uma mensagem com 5 botoes para oferta do produto X, cada botao com uma forma de pagamento.">Crie uma mensagem curta para lembrar os alunos da live de hoje e incentivar a entrarem no horario.</textarea></label>
          <button class="btn btn-primary" type="submit">Criar com IA</button>
        </form>
      </section>
      <form method="post" enctype="multipart/form-data" id="tgMessageForm">
        <input type="hidden" name="csrf" value="<?=tg_h($csrf)?>">
        <input type="hidden" name="action" value="save_auto_message">
        <input type="hidden" name="id" id="tgMsgId">
        <div class="tg-editor-grid">
          <label class="tg-field"><label>Nome interno</label><input name="name" id="tgName" value="<?=tg_h($aiDraft['name'] ?? 'Nova mensagem Telegram')?>" required></label>
          <label class="tg-field"><label>Grupo</label><select name="group_id" id="tgGroup"><option value="0">Todos os grupos</option><?php foreach($groups as $g): ?><option value="<?=(int)$g['id']?>"><?=tg_h($g['title'])?></option><?php endforeach; ?></select></label>
          <label class="tg-field"><label>Gatilho</label><select name="trigger_type" id="tgTrigger"><option value="scheduled">Data/hora programada</option><option value="member_joined">Quando entrar</option><option value="member_left">Quando sair</option></select></label>
          <label class="tg-field"><label>Tipo</label><select name="message_kind" id="tgKind"><option value="text">Mensagem de texto</option><option value="photo">Imagem + legenda</option><option value="video">Video + legenda</option></select></label>
          <label class="tg-field"><label>Enviar em</label><input type="datetime-local" name="send_at" id="tgSendAt"></label>
          <label class="tg-field"><label>Repetir a cada X minutos</label><input type="number" name="repeat_minutes" id="tgRepeat" min="0" value="0"></label>
          <label class="tg-field"><label>Formato</label><select name="parse_mode" id="tgParse"><option value="">Texto simples</option><option value="HTML">HTML Telegram</option><option value="MarkdownV2">MarkdownV2</option></select></label>
          <label class="tg-field"><label>URL da midia</label><input name="media_url" id="tgMediaUrl" placeholder="https://.../imagem.jpg ou video.mp4"></label>
          <label class="tg-field tg-wide"><label>Upload da midia</label><input type="file" name="media_file" accept="image/*,video/mp4,video/webm,video/quicktime"></label>
          <label class="tg-field tg-wide"><label>Mensagem / legenda</label><textarea name="message_text" id="tgText" required><?=tg_h($aiDraft['message'] ?? 'Bem-vindo, {{nome}}. Aula ao vivo chegando.')?></textarea><span class="tg-note">Variaveis: {{nome}}, {{username}}, {{grupo}}, {{chat_id}}, {{telegram_id}}</span></label>
        </div>
        <div class="tg-subpanel tg-wide mt-3">
          <div class="tg-toolbar"><div><div class="panel-title">Botoes</div><div class="tg-note">Cada botao recebe uma funcao propria, dentro do que o Telegram permite.</div></div><button type="button" class="btn btn-ghost sm" id="tgAddButton">Inserir botao</button></div>
          <div id="tgButtons"></div>
        </div>
        <div class="tg-subpanel mt-3">
          <div class="panel-title">Preview</div>
          <div class="tg-card-body">
            <div class="tg-preview-media" id="tgPreviewMedia" style="display:none"></div>
            <div class="tg-preview-bubble" id="tgPreviewText"></div>
            <div class="tg-preview-buttons" id="tgPreviewButtons"></div>
          </div>
        </div>
        <div class="tg-form-actions tg-actions">
          <button class="btn btn-primary" type="submit" id="tgSaveBtn">Salvar mensagem</button>
          <button type="button" class="btn btn-ghost" id="tgClearForm">Limpar</button>
        </div>
      </form>
    </aside>
  </div>
<?php elseif($tab==='messages' && false): ?>
  <section class="tg-card">
    <div class="panel-title">Criar texto com IA</div>
    <form method="post" class="tg-form mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="generate_message_ai">
      <label class="tg-field"><label>Grupo de contexto</label><select name="group_id"><option value="0">Todos os grupos</option><?php foreach($groups as $g): ?><option value="<?=(int)$g['id']?>"><?=tg_h($g['title'])?></option><?php endforeach; ?></select></label>
      <label class="tg-field"><label>Objetivo</label><select name="trigger_type"><option value="member_joined">Boas-vindas</option><option value="scheduled">Aviso programado</option><option value="member_left">Recuperar saida</option></select></label>
      <label class="tg-field tg-wide"><label>Prompt para IA</label><textarea name="ai_prompt" placeholder="Ex: crie uma mensagem curta avisando que a live começa em 1 hora, com tom urgente e profissional.">Crie uma mensagem curta para lembrar os alunos da live de hoje e incentivar a entrarem no horario.</textarea></label>
      <div class="tg-actions"><button class="btn btn-primary" type="submit">Criar texto com IA</button></div>
    </form>
  </section>
  <section class="tg-card">
    <div class="panel-title">Nova mensagem automatica</div>
    <?php $draftButtons = is_array($aiDraft['button_suggestions'] ?? null) ? $aiDraft['button_suggestions'] : []; ?>
    <form method="post" enctype="multipart/form-data" class="tg-form mt-3"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="action" value="save_auto_message">
      <label class="tg-field"><label>Nome</label><input name="name" value="<?=tg_h($aiDraft['name'] ?? 'Boas-vindas Telegram')?>" required></label>
      <label class="tg-field"><label>Grupo</label><select name="group_id"><option value="0">Todos os grupos</option><?php foreach($groups as $g): ?><option value="<?=(int)$g['id']?>"><?=tg_h($g['title'])?></option><?php endforeach; ?></select></label>
      <label class="tg-field"><label>Gatilho</label><select name="trigger_type"><option value="member_joined">Quando entrar</option><option value="member_left">Quando sair</option><option value="scheduled">Data/hora programada</option></select></label>
      <label class="tg-field"><label>Tipo</label><select name="message_kind"><option value="text">Somente texto</option><option value="photo">Imagem + legenda</option><option value="video">Video + legenda</option></select></label>
      <label class="tg-field"><label>Enviar em</label><input type="datetime-local" name="send_at"></label>
      <label class="tg-field"><label>Repetir a cada X minutos</label><input type="number" name="repeat_minutes" min="0" value="0"></label>
      <label class="tg-field"><label>Formato</label><select name="parse_mode"><option value="">Texto simples</option><option value="HTML">HTML Telegram</option><option value="MarkdownV2">MarkdownV2</option></select></label>
      <label class="tg-field"><label>URL da midia</label><input name="media_url" placeholder="https://.../imagem.jpg ou video.mp4"></label>
      <label class="tg-field"><label>Upload da midia</label><input type="file" name="media_file" accept="image/*,video/mp4,video/webm,video/quicktime"></label>
      <label class="tg-field tg-wide"><label>Mensagem / legenda</label><textarea name="message_text" required><?=tg_h($aiDraft['message'] ?? 'Bem-vindo, {{nome}}. Aula ao vivo chegando.')?></textarea><span class="tg-note">Variaveis: {{nome}}, {{username}}, {{grupo}}, {{chat_id}}, {{telegram_id}}</span></label>
      <div class="tg-subpanel tg-wide">
        <div class="panel-title">Botoes e menus</div>
        <div class="tg-form mt-3">
          <?php for($i=0;$i<4;$i++): $b=is_array($draftButtons[$i] ?? null)?$draftButtons[$i]:[]; ?>
            <label class="tg-field"><label>Texto do botao <?=($i+1)?></label><input name="button_text[]" value="<?=tg_h($b['text'] ?? '')?>" placeholder="Abrir aula"></label>
            <label class="tg-field"><label>URL do botao <?=($i+1)?></label><input name="button_url[]" value="<?=tg_h($b['url'] ?? '')?>" placeholder="https://..."></label>
          <?php endfor; ?>
          <label class="tg-field"><label>Botao privado</label><select name="add_private_button"><option value="">Nao adicionar</option><option value="1">Adicionar botao para chamar o bot</option></select></label>
          <label class="tg-field"><label>Texto do botao privado</label><input name="private_button_text" value="Falar no privado"></label>
        </div>
        <p class="tg-note">Botoes de link abrem paginas HTTPS. O botao privado usa o usuario do bot detectado pelo Telegram.</p>
      </div>
      <div class="tg-actions"><button class="btn btn-primary" type="submit">Salvar mensagem</button></div>
    </form>
  </section>
  <section class="tg-card">
    <div class="panel-title">Mensagens cadastradas</div>
    <div class="tg-table mt-3"><table><thead><tr><th>Nome</th><th>Grupo</th><th>Tipo</th><th>Gatilho</th><th>Status</th><th>Proximo envio</th><th>Enviadas</th><th>Acoes</th></tr></thead><tbody>
      <?php foreach($autoMessages as $m): ?><tr><td><?=tg_h($m['name'])?><br><span class="tg-note"><?=tg_h(mb_substr((string)$m['message_text'],0,90))?></span></td><td><?=tg_h($m['group_title'] ?: 'Todos')?></td><td><span class="tg-pill"><?=tg_h($m['message_kind'] ?? 'text')?></span><?=!empty($m['buttons_json'])?' <span class="tg-pill ok">botoes</span>':''?></td><td><?=tg_h($m['trigger_type'])?></td><td><span class="tg-pill <?=$m['status']==='active'?'ok':'warn'?>"><?=tg_h($m['status'])?></span></td><td><?=tg_h(tg_dt($m['next_run_at']))?></td><td><?=(int)$m['sent_count']?></td><td><form method="post" class="tg-actions"><input type="hidden" name="csrf" value="<?=tg_h($csrf)?>"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="btn btn-ghost sm" name="action" value="toggle_auto_message">Pausar/ativar</button><button class="btn btn-danger sm" name="action" value="delete_auto_message" onclick="return confirm('Excluir mensagem?')">Excluir</button></form></td></tr><?php endforeach; ?>
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
<?php if($tab==='messages'): ?>
<script>
(function(){
  const root=document.querySelector('.tg-msg-workspace'); if(!root) return;
  const form=document.getElementById('tgMessageForm'), buttonsWrap=document.getElementById('tgButtons');
  const fields={id:document.getElementById('tgMsgId'),name:document.getElementById('tgName'),group:document.getElementById('tgGroup'),trigger:document.getElementById('tgTrigger'),kind:document.getElementById('tgKind'),sendAt:document.getElementById('tgSendAt'),repeat:document.getElementById('tgRepeat'),parse:document.getElementById('tgParse'),media:document.getElementById('tgMediaUrl'),text:document.getElementById('tgText')};
  const previewText=document.getElementById('tgPreviewText'), previewMedia=document.getElementById('tgPreviewMedia'), previewButtons=document.getElementById('tgPreviewButtons');
  const editorTitle=document.getElementById('tgEditorTitle'), saveBtn=document.getElementById('tgSaveBtn');
  function esc(s){return String(s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
  function buttonTemplate(b){
    const type=b.type||(b.callback_data?'callback':'url'), payload=b.callback_data||'', url=b.url||'';
    const width=b.width==='half'?'half':'full';
    return `<div class="tg-button-row">
      <div class="tg-button-row-head"><strong>Botao</strong><button type="button" class="tg-icon-btn tg-remove-button" title="Remover">×</button></div>
      <div class="tg-button-settings">
        <label class="tg-field"><label>Texto</label><input name="button_text[]" value="${esc(b.text||'')}" placeholder="Ex: Pix"></label>
        <label class="tg-field"><label>Funcao</label><select name="button_type[]" class="tg-button-type"><option value="url" ${type==='url'?'selected':''}>Abrir link</option><option value="private_bot" ${type==='private_bot'?'selected':''}>Chamar bot</option><option value="callback" ${type==='callback'?'selected':''}>Acao interna</option></select></label>
        <label class="tg-field"><label>Layout</label><select name="button_width[]" class="tg-button-width"><option value="full" ${width==='full'?'selected':''}>Grande</option><option value="half" ${width==='half'?'selected':''}>Metade</option></select></label>
        <label class="tg-field tg-button-url"><label>Link</label><input name="button_url[]" value="${esc(type==='url'?url:'')}" placeholder="https://..."></label>
        <label class="tg-field tg-button-payload"><label>Parametro / comando</label><input name="button_payload[]" value="${esc(type==='callback'?payload:'')}" placeholder="oferta_pix ou start"></label>
      </div>
    </div>`;
  }
  function syncButtonRows(){
    buttonsWrap.querySelectorAll('.tg-button-row').forEach(row=>{
      const type=row.querySelector('.tg-button-type').value;
      row.querySelector('.tg-button-url').style.display=type==='url'?'block':'none';
      row.querySelector('.tg-button-payload').style.display=type==='url'?'none':'block';
    });
    renderPreview();
  }
  function addButton(b={}){ if(buttonsWrap.children.length>=8) return; buttonsWrap.insertAdjacentHTML('beforeend',buttonTemplate(b)); syncButtonRows(); }
  function renderPreview(){
    const text=fields.text.value.trim()||'Nenhuma mensagem adicionada.';
    previewText.textContent=text;
    const kind=fields.kind.value, media=fields.media.value.trim();
    previewMedia.style.display=kind==='text'?'none':'flex';
    previewMedia.textContent=kind==='photo'?(media?'Imagem anexada':'Imagem'):(media?'Video anexado':'Video');
    previewButtons.innerHTML='';
    buttonsWrap.querySelectorAll('.tg-button-row').forEach(row=>{
      const label=row.querySelector('[name="button_text[]"]').value.trim();
      const width=row.querySelector('[name="button_width[]"]').value==='half'?'half':'full';
      if(label) previewButtons.insertAdjacentHTML('beforeend',`<div class="tg-preview-button ${width}">${esc(label)}</div>`);
    });
  }
  function fill(data, markActive){
    fields.id.value=data.id||''; fields.name.value=data.name||'Nova mensagem Telegram'; fields.group.value=String(data.group_id||0);
    fields.trigger.value=data.trigger_type||'scheduled'; fields.kind.value=data.message_kind||'text'; fields.sendAt.value=data.send_at||'';
    fields.repeat.value=data.repeat_minutes||0; fields.parse.value=data.parse_mode||''; fields.media.value=data.media_url||''; fields.text.value=data.message_text||'';
    buttonsWrap.innerHTML=''; (Array.isArray(data.buttons)?data.buttons:[]).forEach(addButton);
    if(!buttonsWrap.children.length) addButton({});
    editorTitle.textContent=data.id?'Editar mensagem':'Configurar mensagem'; saveBtn.textContent=data.id?'Salvar alteracoes':'Salvar mensagem';
    document.querySelectorAll('.tg-message-card').forEach(card=>card.classList.toggle('active', markActive && card.dataset.message && JSON.parse(card.dataset.message).id===data.id));
    syncButtonRows(); renderPreview();
  }
  function resetForm(){
    fill({name:'Nova mensagem Telegram',group_id:0,trigger_type:'scheduled',message_kind:'text',message_text:'',buttons:[],repeat_minutes:0}, false);
    form.reset(); fields.id.value=''; fields.name.value='Nova mensagem Telegram'; fields.trigger.value='scheduled'; fields.kind.value='text'; fields.repeat.value='0'; buttonsWrap.innerHTML=''; addButton({}); renderPreview();
  }
  document.querySelectorAll('.tg-message-card').forEach(card=>{
    card.addEventListener('click',e=>{ if(e.target.closest('form')) return; fill(JSON.parse(card.dataset.message), true); });
    const edit=card.querySelector('.tg-edit-card'); if(edit) edit.addEventListener('click',e=>{e.stopPropagation(); fill(JSON.parse(card.dataset.message), true);});
  });
  document.getElementById('tgNewMessage').addEventListener('click', resetForm);
  document.getElementById('tgClearForm').addEventListener('click', resetForm);
  document.getElementById('tgAddButton').addEventListener('click',()=>addButton({}));
  buttonsWrap.addEventListener('click',e=>{ if(e.target.closest('.tg-remove-button')){ e.target.closest('.tg-button-row').remove(); renderPreview(); }});
  buttonsWrap.addEventListener('input',syncButtonRows); buttonsWrap.addEventListener('change',syncButtonRows);
  ['input','change'].forEach(ev=>form.addEventListener(ev,renderPreview));
  document.getElementById('tgToggleAi').addEventListener('click',()=>document.getElementById('tgAiPanel').classList.toggle('open'));
  let draft={}; try{draft=JSON.parse(root.dataset.aiDraft||'{}')}catch(e){}
  if(draft.message){fill({name:draft.name||'Mensagem criada com IA',group_id:0,trigger_type:'scheduled',message_kind:'text',message_text:draft.message,buttons:draft.buttons||[],repeat_minutes:0},false);}
  else{resetForm();}
})();
</script>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
