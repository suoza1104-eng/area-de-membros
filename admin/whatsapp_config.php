<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/whatsapp_ai.php';

proteger_admin();
$pdo = getPDO();
evolution_ensure_tables($pdo);
whatsapp_ai_ensure_tables($pdo);
whatsapp_event_notifications_ensure_tables($pdo);

$menu = 'whatsapp_config';
$page_title = 'Configurações WhatsApp';

function wcfg_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wcfg_redirect(string $query): void {
    header('Location: whatsapp_config.php?' . $query);
    exit;
}

function wcfg_roles($value): array {
    $roles = is_array($value) ? $value : explode(',', (string)$value);
    $roles = array_values(array_unique(array_intersect(['spy', 'administrator', 'reserve'], array_map('trim', $roles))));
    return $roles ?: ['spy'];
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_evolution') {
            evolution_set_config(
                trim((string)($_POST['base_url'] ?? '')),
                trim((string)($_POST['apikey'] ?? '')),
                (int)($_POST['timeout_seconds'] ?? 20)
            );
            wcfg_redirect('saved=evolution');
        }

        if ($action === 'create_instance') {
            $name = trim((string)($_POST['name'] ?? '')) ?: 'Número WhatsApp';
            $instanceKey = trim((string)($_POST['instance_key'] ?? '')) ?: evolution_slug_instance($name);
            $phone = evolution_clean_whatsapp_phone((string)($_POST['phone_number'] ?? ''));
            $roles = wcfg_roles($_POST['operational_roles'] ?? []);
            $role = in_array('administrator', $roles, true) ? 'administrator' : (in_array('reserve', $roles, true) ? 'reserve' : 'spy');
            $pdo->prepare("
                INSERT INTO whatsapp_instances
                    (name, instance_key, phone_number, status, instance_token, operational_role, operational_roles, role_priority, is_enabled, created_at, updated_at)
                VALUES (:name, :instance_key, :phone, 'DISCONNECTED', :token, :role, :roles, :priority, 1, NOW(), NOW())
            ")->execute([
                ':name' => $name,
                ':instance_key' => $instanceKey,
                ':phone' => $phone ?: null,
                ':token' => bin2hex(random_bytes(24)),
                ':role' => $role,
                ':roles' => implode(',', $roles),
                ':priority' => max(1, (int)($_POST['role_priority'] ?? 100)),
            ]);
            $instance = evolution_get_instance($pdo, (int)$pdo->lastInsertId());
            if ($instance) evolution_create_remote_instance($pdo, $instance);
            wcfg_redirect('saved=instance');
        }

        if ($action === 'update_instance') {
            $id = (int)($_POST['id'] ?? 0);
            $roles = wcfg_roles($_POST['operational_roles'] ?? []);
            $role = in_array('administrator', $roles, true) ? 'administrator' : (in_array('reserve', $roles, true) ? 'reserve' : 'spy');
            $pdo->prepare("
                UPDATE whatsapp_instances
                   SET name=:name, phone_number=:phone, operational_role=:role, operational_roles=:roles,
                       role_priority=:priority, is_enabled=:enabled
                 WHERE id=:id LIMIT 1
            ")->execute([
                ':name' => trim((string)($_POST['name'] ?? '')) ?: 'Número WhatsApp',
                ':phone' => evolution_clean_whatsapp_phone((string)($_POST['phone_number'] ?? '')) ?: null,
                ':role' => $role,
                ':roles' => implode(',', $roles),
                ':priority' => max(1, (int)($_POST['role_priority'] ?? 100)),
                ':enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
                ':id' => $id,
            ]);
            wcfg_redirect('saved=instance');
        }

        if (in_array($action, ['connect_instance', 'refresh_instance', 'delete_instance', 'set_instance_webhook'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $instance = evolution_get_instance($pdo, $id);
            if (!$instance) throw new RuntimeException('Instância não encontrada.');
            if ($action === 'connect_instance') evolution_connect_instance($pdo, $instance);
            if ($action === 'refresh_instance') evolution_fetch_state($pdo, $instance);
            if ($action === 'delete_instance') {
                $pdo->prepare("DELETE FROM whatsapp_instances WHERE id=:id LIMIT 1")->execute([':id' => $id]);
            }
            if ($action === 'set_instance_webhook') {
                $url = rtrim(BASE_URL, '/') . '/whatsapp_webhook.php?t=' . evolution_get_webhook_token();
                $res = evolution_set_group_webhook((string)$instance['instance_key'], $url);
                if (empty($res['ok'])) throw new RuntimeException('Falha ao configurar webhook: ' . substr((string)($res['raw'] ?? $res['error']), 0, 800));
            }
            wcfg_redirect('saved=instance');
        }

        if ($action === 'toggle_group_ignore') {
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            $pdo->prepare("UPDATE whatsapp_groups SET is_ignored=IF(is_ignored=1,0,1), last_seen_at=NOW() WHERE group_id=:gid LIMIT 1")
                ->execute([':gid' => $groupId]);
            wcfg_redirect('saved=group');
        }

        if ($action === 'save_ai') {
            $current = whatsapp_ai_get_config();
            $postedKey = trim((string)($_POST['openai_api_key'] ?? ''));
            whatsapp_ai_set_config([
                'enabled' => !empty($_POST['enabled']),
                'openai_api_key' => !empty($_POST['clear_openai_api_key']) ? '' : ($postedKey !== '' ? $postedKey : $current['openai_api_key']),
                'model' => $_POST['model'] ?? '',
                'interval_minutes' => $_POST['interval_minutes'] ?? 5,
                'active_from' => $_POST['active_from'] ?? '08:00',
                'active_to' => $_POST['active_to'] ?? '22:00',
                'max_tokens' => $_POST['max_tokens'] ?? 800,
                'max_messages' => $_POST['max_messages'] ?? 80,
                'context_keep' => $_POST['context_keep'] ?? 6,
                'temperature' => $_POST['temperature'] ?? 0.2,
                'transcription_model' => $_POST['transcription_model'] ?? 'gpt-4o-mini-transcribe',
                'prompt' => $_POST['prompt'] ?? '',
                'criteria' => $_POST['criteria'] ?? '',
                'group_alerts_enabled' => !empty($_POST['group_alerts_enabled']),
                'group_suggestions_enabled' => !empty($_POST['group_suggestions_enabled']),
                'group_tags_enabled' => !empty($_POST['group_tags_enabled']),
                'direct_auto_reply_enabled' => !empty($_POST['direct_auto_reply_enabled']),
                'direct_support_link' => $_POST['direct_support_link'] ?? '',
                'direct_reply_template' => $_POST['direct_reply_template'] ?? '',
            ]);
            wcfg_redirect('saved=ai');
        }

        if ($action === 'save_blacklist') {
            evolution_blacklist_set_config([
                'auto_remove' => !empty($_POST['blacklist_auto_remove']),
                'notify_enabled' => !empty($_POST['blacklist_notify_enabled']),
                'recipient_ids' => $_POST['blacklist_recipient_ids'] ?? [],
                'group_ids' => $_POST['blacklist_group_ids'] ?? [],
                'message_template' => $_POST['blacklist_message_template'] ?? '',
            ]);
            wcfg_redirect('saved=blacklist');
        }

        if ($action === 'test_blacklist') {
            @set_time_limit(0);
            $cfgTest = evolution_blacklist_get_config();
            $context = [
                'numero' => '5511999999999',
                'grupo_id' => 'grupo-teste',
                'grupo_nome' => 'Grupo de teste',
                'motivo_blacklist' => 'Teste manual da notificação da Lista de fraude',
                'origem_blacklist' => 'painel',
                'aluno_identificado' => 'Sim',
                'aluno_id' => '123',
                'aluno_nome' => 'Aluno de Teste',
                'aluno_email' => 'teste@exemplo.com',
                'turmas' => 'TURMA-TESTE',
                'tags' => 'TESTE, BLACKLIST',
                'primeira_entrada' => date('d/m/Y H:i:s'),
                'data_ocorrencia' => date('d/m/Y H:i:s'),
                'status_remocao' => 'Mensagem de teste — nenhuma remoção executada',
            ];
            $message = evolution_render_template((string)$cfgTest['message_template'], $context);
            $destinations = [];
            if ($cfgTest['recipient_ids']) {
                $ph = implode(',', array_fill(0, count($cfgTest['recipient_ids']), '?'));
                $st = $pdo->prepare("SELECT nome, whatsapp_number FROM admin_equipe WHERE ativo=1 AND id IN ($ph) ORDER BY nome");
                $st->execute($cfgTest['recipient_ids']);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $destinations[] = ['type' => 'team', 'id' => (string)$row['whatsapp_number'], 'name' => (string)$row['nome'], 'instance' => ''];
                }
            }
            foreach ($cfgTest['group_ids'] as $groupId) {
                $st = $pdo->prepare("SELECT group_name, instance_key FROM whatsapp_groups WHERE group_id=:gid LIMIT 1");
                $st->execute([':gid' => $groupId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $destinations[] = ['type' => 'group', 'id' => $groupId, 'name' => (string)($row['group_name'] ?? $groupId), 'instance' => (string)($row['instance_key'] ?? '')];
            }
            if (!$destinations) throw new RuntimeException('Selecione e salve ao menos um membro ou grupo antes de testar.');
            if (count($destinations) > 20) throw new RuntimeException('O teste está limitado a 20 destinos por execução.');
            $fallback = evolution_select_messaging_instance($pdo, '');
            $sent = 0;
            foreach ($destinations as $index => $destination) {
                if ($index > 0) sleep(10);
                $instanceKey = trim((string)$destination['instance']) ?: $fallback;
                $res = evolution_http('POST', '/message/sendText/' . rawurlencode($instanceKey), [
                    'number' => $destination['type'] === 'team' ? evolution_clean_whatsapp_phone($destination['id']) : $destination['id'],
                    'text' => $message,
                ]);
                if (!empty($res['ok'])) $sent++;
            }
            wcfg_redirect('tested=' . $sent . '&total=' . count($destinations));
        }

        if ($action === 'save_event_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $name = trim((string)($_POST['rule_name'] ?? ''));
            $event = strtoupper(trim((string)($_POST['rule_event_code'] ?? '')));
            $template = trim((string)($_POST['rule_message_template'] ?? ''));
            $groups = is_array($_POST['rule_group_ids'] ?? null) ? $_POST['rule_group_ids'] : [];
            $team = is_array($_POST['rule_team_ids'] ?? null) ? array_map('intval', $_POST['rule_team_ids']) : [];
            if ($name === '' || $event === '' || $template === '' || (!$groups && !$team)) throw new RuntimeException('Preencha a regra e selecione destinos.');
            $pdo->beginTransaction();
            if ($ruleId > 0) {
                $pdo->prepare("UPDATE whatsapp_event_notification_rules SET name=:n,event_code=:e,instance_key=:i,message_template=:m,is_active=:a WHERE id=:id")
                    ->execute([':n'=>$name,':e'=>$event,':i'=>trim((string)($_POST['rule_instance_key'] ?? '')) ?: null,':m'=>$template,':a'=>!empty($_POST['rule_is_active'])?1:0,':id'=>$ruleId]);
            } else {
                $pdo->prepare("INSERT INTO whatsapp_event_notification_rules (name,event_code,instance_key,message_template,is_active,created_at,updated_at) VALUES (:n,:e,:i,:m,:a,NOW(),NOW())")
                    ->execute([':n'=>$name,':e'=>$event,':i'=>trim((string)($_POST['rule_instance_key'] ?? '')) ?: null,':m'=>$template,':a'=>!empty($_POST['rule_is_active'])?1:0]);
                $ruleId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_groups WHERE rule_id=:id")->execute([':id'=>$ruleId]);
            $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_team WHERE rule_id=:id")->execute([':id'=>$ruleId]);
            $ig = $pdo->prepare("INSERT INTO whatsapp_event_notification_rule_groups (rule_id,group_id) VALUES (:r,:g)");
            foreach (array_unique(array_filter(array_map('trim', $groups))) as $gid) $ig->execute([':r'=>$ruleId,':g'=>$gid]);
            $it = $pdo->prepare("INSERT INTO whatsapp_event_notification_rule_team (rule_id,admin_equipe_id) VALUES (:r,:t)");
            foreach (array_unique(array_filter($team)) as $tid) $it->execute([':r'=>$ruleId,':t'=>$tid]);
            $pdo->commit();
            wcfg_redirect('saved=rule');
        }

        if (in_array($action, ['toggle_event_rule', 'delete_event_rule'], true)) {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            if ($action === 'toggle_event_rule') {
                $pdo->prepare("UPDATE whatsapp_event_notification_rules SET is_active=IF(is_active=1,0,1) WHERE id=:id")->execute([':id'=>$ruleId]);
            } else {
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_groups WHERE rule_id=:id")->execute([':id'=>$ruleId]);
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_team WHERE rule_id=:id")->execute([':id'=>$ruleId]);
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rules WHERE id=:id")->execute([':id'=>$ruleId]);
            }
            wcfg_redirect('saved=rule');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved'])) $notice = 'Configuração salva.';
if (isset($_GET['tested'])) $notice = 'Teste concluído: ' . (int)$_GET['tested'] . ' de ' . (int)($_GET['total'] ?? 0) . ' envio(s) realizado(s).';

$evolutionCfg = evolution_get_config();
$aiCfg = whatsapp_ai_get_config();
$blacklistCfg = evolution_blacklist_get_config();
$instances = $pdo->query("SELECT * FROM whatsapp_instances ORDER BY role_priority, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$groups = $pdo->query("SELECT * FROM whatsapp_groups ORDER BY COALESCE(group_name,group_id)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$team = [];
try { $team = $pdo->query("SELECT id,nome,whatsapp_number,ativo FROM admin_equipe WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
$rules = $pdo->query("SELECT * FROM whatsapp_event_notification_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ruleGroups = [];
foreach ($pdo->query("SELECT * FROM whatsapp_event_notification_rule_groups")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $ruleGroups[(int)$row['rule_id']][] = (string)$row['group_id'];
$ruleTeam = [];
foreach ($pdo->query("SELECT * FROM whatsapp_event_notification_rule_team")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $ruleTeam[(int)$row['rule_id']][] = (int)$row['admin_equipe_id'];
$events = [
    'INSCRITO','INSCRICAO_GRATUITA','INSCRICAO_VITALICIA','ACESSO_VITALICIO_LIBERADO','REINSCRITO','PRIMEIRO_LOGIN','ASSISTIU_ALGUMA_AULA','CONCLUIU_TRILHA','CERT_EMITIDO',
    'WHATSAPP_GRUPO_ENTROU','WHATSAPP_GRUPO_SAIU','WHATSAPP_GRUPO_REMOVIDO_ADMIN','WHATSAPP_BLACKLIST_DETECTADO',
    'WHATSAPP_IA_ALERTA_LEVE','WHATSAPP_IA_ALERTA_MEDIO','WHATSAPP_IA_ALERTA_CRITICO',
    'WHATSAPP_DIRECT_GOLPE_SUSPEITO','WHATSAPP_DIRECT_DUVIDA_ALUNO',
];
foreach ($rules as $rule) $events[] = (string)$rule['event_code'];
$events = array_values(array_unique($events));
sort($events);

require __DIR__ . '/_header.php';
?>
<style>
.wc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
.wc-card{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:17px;margin-bottom:16px}
.wc-title{font-size:13px;font-weight:800;color:var(--text);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px}
.wc-help{font-size:11px;color:var(--muted);line-height:1.5;margin-top:5px}
.wc-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.wc-checks{display:grid;gap:6px;border:1px solid var(--border);border-radius:8px;padding:9px;max-height:190px;overflow:auto}
.wc-check{display:flex;gap:7px;align-items:flex-start;font-size:11px;color:var(--text)}
.wc-instance,.wc-rule{border:1px solid var(--border);border-radius:8px;padding:11px;margin-top:10px;background:rgba(255,255,255,.02)}
.wc-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.wc-role-options{display:flex;gap:12px;flex-wrap:wrap;border:1px solid var(--border);border-radius:8px;padding:10px}
.wc-status{display:flex;align-items:center;justify-content:space-between;gap:10px;border-radius:9px;padding:10px 12px;margin:8px 0 10px;font-size:12px;font-weight:800}
.wc-status.connected{background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.38);color:#86efac}
.wc-status.disconnected{background:rgba(239,68,68,.13);border:1px solid rgba(239,68,68,.34);color:#fca5a5}
.wc-model-help{margin-top:6px;padding:8px 10px;border-radius:8px;background:rgba(59,130,246,.08);color:var(--muted);font-size:11px;line-height:1.45}
@media(max-width:1000px){.wc-grid,.wc-row{grid-template-columns:1fr}}
</style>
<?php if ($notice): ?><div class="alert alert-ok mb-3"><?= wcfg_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-3"><?= wcfg_h($error) ?></div><?php endif; ?>

<div class="wc-grid">
<div>
    <div class="wc-card">
        <div class="wc-title">Evolution API</div>
        <form method="post">
            <input type="hidden" name="action" value="save_evolution">
            <div class="form-group"><label class="form-label">URL base</label><input type="url" name="base_url" value="<?= wcfg_h($evolutionCfg['base_url']) ?>"></div>
            <div class="form-group"><label class="form-label">API key</label><input type="password" name="apikey" value="<?= wcfg_h($evolutionCfg['apikey']) ?>"></div>
            <div class="form-group"><label class="form-label">Timeout</label><input type="number" name="timeout_seconds" min="3" max="120" value="<?= (int)$evolutionCfg['timeout'] ?>"></div>
            <button class="btn btn-primary">Salvar Evolution</button>
        </form>
    </div>

    <div class="wc-card">
        <div class="wc-title">Instâncias e funções</div>
        <div class="wc-help">Espião observa e registra. Administrador executa remoções. Reserva assume ações administrativas quando não houver administrador conectado.</div>
        <details style="margin-top:12px"><summary style="cursor:pointer;color:var(--primary);font-size:12px;font-weight:700">+ Nova instância</summary>
            <form method="post" class="wc-instance">
                <input type="hidden" name="action" value="create_instance">
                <div class="wc-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" required></div><div class="form-group"><label class="form-label">Chave</label><input name="instance_key"></div></div>
                <div class="form-group"><label class="form-label">Telefone</label><input name="phone_number"></div>
                <div class="form-group"><label class="form-label">Funções acumuláveis</label><div class="wc-role-options"><?php foreach (['spy'=>'Espião','administrator'=>'Administrador','reserve'=>'Reserva'] as $value=>$label): ?><label class="wc-check"><input type="checkbox" name="operational_roles[]" value="<?= $value ?>" <?= $value==='spy'?'checked':'' ?>> <?= $label ?></label><?php endforeach; ?></div></div>
                <div class="form-group"><label class="form-label">Prioridade</label><input type="number" name="role_priority" value="100" min="1"></div>
                <button class="btn btn-primary">Criar instância</button>
            </form>
        </details>
        <?php foreach ($instances as $instance): ?>
            <?php $instanceRoles = wcfg_roles((string)($instance['operational_roles'] ?? $instance['operational_role'] ?? 'spy')); $isConnected = strtoupper((string)$instance['status']) === 'CONNECTED'; ?>
            <div class="wc-instance">
                <form method="post">
                    <input type="hidden" name="action" value="update_instance"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>">
                    <div class="wc-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" value="<?= wcfg_h((string)$instance['name']) ?>"></div><div class="form-group"><label class="form-label">Telefone</label><input name="phone_number" value="<?= wcfg_h((string)$instance['phone_number']) ?>"></div></div>
                    <div class="form-group"><label class="form-label">Funções acumuláveis</label><div class="wc-role-options"><?php foreach (['spy'=>'Espião','administrator'=>'Administrador','reserve'=>'Reserva'] as $value=>$label): ?><label class="wc-check"><input type="checkbox" name="operational_roles[]" value="<?= $value ?>" <?= in_array($value,$instanceRoles,true)?'checked':'' ?>> <?= $label ?></label><?php endforeach; ?></div></div>
                    <div class="form-group"><label class="form-label">Prioridade administrativa</label><input type="number" name="role_priority" value="<?= (int)$instance['role_priority'] ?>"><div class="wc-help">Menor número tem preferência entre administradores e reservas conectados.</div></div>
                    <label class="form-label"><input type="checkbox" name="is_enabled" value="1" <?= (int)$instance['is_enabled']===1?'checked':'' ?>> Ativa</label>
                    <div class="wc-status <?= $isConnected?'connected':'disconnected' ?>"><span><?= $isConnected?'● CONECTADO':'● DESCONECTADO' ?></span><small><?= wcfg_h((string)$instance['instance_key']) ?></small></div>
                    <button class="btn btn-primary btn-sm">Salvar função</button>
                </form>
                <div class="wc-actions" style="margin-top:8px">
                    <?php foreach (['connect_instance'=>'Gerar QR','refresh_instance'=>'Atualizar status','set_instance_webhook'=>'Configurar webhook'] as $act=>$label): ?><form method="post"><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>"><button class="btn btn-ghost btn-xs"><?= $label ?></button></form><?php endforeach; ?>
                    <form method="post" onsubmit="return confirm('Remover instância local?')"><input type="hidden" name="action" value="delete_instance"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>"><button class="btn btn-ghost btn-xs" style="color:var(--danger)">Remover</button></form>
                </div>
                <?php $qr=(string)($instance['qr_base64']??''); if($qr): if(stripos($qr,'data:image')!==0)$qr='data:image/png;base64,'.$qr; ?><img src="<?= wcfg_h($qr) ?>" alt="QR" style="width:190px;margin-top:10px;background:#fff;padding:6px;border-radius:8px"><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="wc-card">
        <div class="wc-title">Grupos ignorados</div>
        <div class="wc-help">Grupos ignorados continuam visíveis no Monitor, mas não geram análise, tags ou gatilhos.</div>
        <div class="wc-checks" style="max-height:360px">
            <?php foreach ($groups as $group): ?><form method="post" class="wc-check"><input type="hidden" name="action" value="toggle_group_ignore"><input type="hidden" name="group_id" value="<?= wcfg_h((string)$group['group_id']) ?>"><input type="checkbox" onchange="this.form.submit()" <?= (int)$group['is_ignored']===1?'checked':'' ?>><span><?= wcfg_h((string)($group['group_name']?:$group['group_id'])) ?></span></form><?php endforeach; ?>
        </div>
    </div>
</div>

<div>
    <div class="wc-card">
        <div class="wc-title">IA WhatsApp</div>
        <form method="post">
            <input type="hidden" name="action" value="save_ai">
            <label class="form-label"><input type="checkbox" name="enabled" value="1" <?= $aiCfg['enabled']?'checked':'' ?>> Ativar IA</label>
            <div class="form-group" style="margin-top:12px">
                <label class="form-label">Funções da IA nos grupos</label>
                <label class="form-label"><input type="checkbox" name="group_alerts_enabled" value="1" <?= $aiCfg['group_alerts_enabled']?'checked':'' ?>> Monitorar e notificar alertas leves, médios e críticos</label>
                <label class="form-label"><input type="checkbox" name="group_suggestions_enabled" value="1" <?= $aiCfg['group_suggestions_enabled']?'checked':'' ?>> Gerar sugestões de respostas para aprovação</label>
                <label class="form-label"><input type="checkbox" name="group_tags_enabled" value="1" <?= $aiCfg['group_tags_enabled']?'checked':'' ?>> Aplicar tags automáticas relacionadas aos alertas</label>
                <div class="wc-help">As opções são independentes. Você pode manter os alertas ativos sem gerar sugestões de respostas.</div>
            </div>
            <?php
                $aiModels = [
                    'gpt-5.4-mini' => 'Recomendado: modelo atual forte e eficiente para alto volume, classificação e análise multimodal.',
                    'gpt-5.4-nano' => 'Menor custo e alta velocidade para classificação, extração e triagem em grande volume.',
                    'gpt-5.4' => 'Maior capacidade para análises complexas e casos ambíguos, com custo superior.',
                    'gpt-5.5' => 'Modelo de fronteira para os casos mais complexos; use quando qualidade máxima for prioridade.',
                    'gpt-4.1-mini' => 'Modelo sem raciocínio, rápido e consistente no seguimento de instruções.',
                    'gpt-4.1' => 'Modelo sem raciocínio com forte interpretação e grande janela de contexto.',
                    'gpt-4o-mini' => 'Opção econômica anterior para tarefas focadas e análise de texto ou imagem.',
                ];
                if (!isset($aiModels[(string)$aiCfg['model']])) $aiModels[(string)$aiCfg['model']] = 'Modelo personalizado atualmente configurado.';
            ?>
            <div class="wc-row"><div class="form-group"><label class="form-label">Modelo</label><select name="model" id="whatsapp-ai-model"><?php foreach($aiModels as $model=>$description): ?><option value="<?= wcfg_h($model) ?>" data-description="<?= wcfg_h($description) ?>" <?= (string)$aiCfg['model']===$model?'selected':'' ?>><?= wcfg_h($model) ?></option><?php endforeach; ?></select><div class="wc-model-help" id="whatsapp-ai-model-help"></div></div><div class="form-group"><label class="form-label">API key OpenAI</label><input type="password" name="openai_api_key" placeholder="<?= $aiCfg['openai_api_key']!==''?'Configurada — vazio mantém':'sk-...' ?>"></div></div>
            <div class="wc-row"><div class="form-group"><label class="form-label">Janela grupos (min)</label><input type="number" name="interval_minutes" value="<?= (int)$aiCfg['interval_minutes'] ?>"></div><div class="form-group"><label class="form-label">Janela direct</label><input value="10 minutos" disabled></div></div>
            <div class="wc-help" style="margin-top:-5px;margin-bottom:10px">O grupo é analisado após esse período sem mensagens, quando esse período passar desde a primeira mensagem pendente ou ao atingir o máximo de mensagens.</div>
            <div class="wc-row"><div class="form-group"><label class="form-label">Ativa de</label><input type="time" name="active_from" value="<?= wcfg_h((string)$aiCfg['active_from']) ?>"></div><div class="form-group"><label class="form-label">Até</label><input type="time" name="active_to" value="<?= wcfg_h((string)$aiCfg['active_to']) ?>"></div></div>
            <div class="wc-help" style="margin-top:-5px;margin-bottom:10px">Deixe um ou ambos os horários vazios para manter a IA ativa 24 horas por dia.</div>
            <div class="wc-row"><div class="form-group"><label class="form-label">Máx. mensagens</label><input type="number" name="max_messages" value="<?= (int)$aiCfg['max_messages'] ?>"></div><div class="form-group"><label class="form-label">Máx. tokens</label><input type="number" name="max_tokens" value="<?= (int)$aiCfg['max_tokens'] ?>"></div></div>
            <input type="hidden" name="context_keep" value="<?= (int)$aiCfg['context_keep'] ?>"><input type="hidden" name="temperature" value="<?= wcfg_h((string)$aiCfg['temperature']) ?>"><input type="hidden" name="transcription_model" value="<?= wcfg_h((string)$aiCfg['transcription_model']) ?>">
            <div class="form-group"><label class="form-label">Prompt</label><textarea name="prompt" rows="7"><?= wcfg_h((string)$aiCfg['prompt']) ?></textarea></div>
            <div class="form-group"><label class="form-label">Regras para tags e alertas leve, médio e crítico</label><textarea name="criteria" rows="8"><?= wcfg_h((string)$aiCfg['criteria']) ?></textarea><div class="wc-help">Defina quando a IA deve aplicar tags e disparar WHATSAPP_IA_ALERTA_LEVE, WHATSAPP_IA_ALERTA_MEDIO ou WHATSAPP_IA_ALERTA_CRITICO.</div></div>
            <hr style="border-color:var(--border);margin:14px 0">
            <label class="form-label"><input type="checkbox" name="direct_auto_reply_enabled" value="1" <?= $aiCfg['direct_auto_reply_enabled']?'checked':'' ?>> Responder automaticamente dúvidas no direct</label>
            <div class="form-group"><label class="form-label">Link do suporte</label><input type="url" name="direct_support_link" value="<?= wcfg_h((string)$aiCfg['direct_support_link']) ?>" placeholder="https://wa.me/5511999999999 ou link da central de suporte"></div>
            <div class="form-group"><label class="form-label">Resposta padrão direct</label><textarea name="direct_reply_template" rows="4"><?= wcfg_h((string)$aiCfg['direct_reply_template']) ?></textarea><div class="wc-help">Variáveis: {{support_link}}, {{aluno_nome}}</div></div>
            <button class="btn btn-primary">Salvar IA e direct</button>
        </form>
    </div>

    <div class="wc-card">
        <div class="wc-title">Automação da Lista de fraude</div>
        <div class="wc-help">O cadastro da Lista de fraude e dos números confiáveis fica na tela WhatsApp Monitor. Aqui são configuradas a remoção automática, as notificações e a mensagem.</div>
        <form method="post">
            <input type="hidden" name="action" value="save_blacklist">
            <label class="form-label"><input type="checkbox" name="blacklist_auto_remove" value="1" <?= $blacklistCfg['auto_remove']?'checked':'' ?>> Remover automaticamente</label>
            <label class="form-label"><input type="checkbox" name="blacklist_notify_enabled" value="1" <?= $blacklistCfg['notify_enabled']?'checked':'' ?>> Enviar alertas</label>
            <div class="wc-row">
                <div><label class="form-label">Equipe</label><div class="wc-checks"><?php foreach ($team as $member): ?><label class="wc-check"><input type="checkbox" name="blacklist_recipient_ids[]" value="<?= (int)$member['id'] ?>" <?= in_array((int)$member['id'],$blacklistCfg['recipient_ids'],true)?'checked':'' ?> <?= empty($member['whatsapp_number'])?'disabled':'' ?>><span><?= wcfg_h((string)$member['nome']) ?></span></label><?php endforeach; ?></div></div>
                <div><label class="form-label">Grupos</label><div class="wc-checks"><?php foreach ($groups as $group): ?><label class="wc-check"><input type="checkbox" name="blacklist_group_ids[]" value="<?= wcfg_h((string)$group['group_id']) ?>" <?= in_array((string)$group['group_id'],$blacklistCfg['group_ids'],true)?'checked':'' ?>><span><?= wcfg_h((string)($group['group_name']?:$group['group_id'])) ?></span></label><?php endforeach; ?></div></div>
            </div>
            <div class="form-group" style="margin-top:10px"><label class="form-label">Mensagem</label><textarea name="blacklist_message_template" rows="10"><?= wcfg_h((string)$blacklistCfg['message_template']) ?></textarea></div>
            <button class="btn btn-primary">Salvar Lista de fraude</button>
        </form>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('O teste enviará para todos os destinos salvos, com intervalo de 10 segundos. Continuar?')">
            <input type="hidden" name="action" value="test_blacklist">
            <button class="btn btn-ghost">Testar notificação</button>
        </form>
            <div class="wc-help">O teste respeita intervalo de 10 segundos entre cada destino.</div>
    </div>

    <div class="wc-card">
        <div class="wc-title">Gatilhos de notificação</div>
        <datalist id="wc-events"><?php foreach ($events as $event): ?><option value="<?= wcfg_h($event) ?>"></option><?php endforeach; ?></datalist>
        <details><summary style="cursor:pointer;color:var(--primary);font-size:12px;font-weight:700">+ Nova regra</summary>
            <?php $editRule=[]; $selectedG=[]; $selectedT=[]; include __DIR__ . '/_whatsapp_event_rule_form.php'; ?>
        </details>
        <?php foreach ($rules as $rule): $editRule=$rule; $selectedG=$ruleGroups[(int)$rule['id']]??[]; $selectedT=$ruleTeam[(int)$rule['id']]??[]; ?>
            <div class="wc-rule"><strong><?= wcfg_h((string)$rule['name']) ?></strong> · <span class="wc-help"><?= wcfg_h((string)$rule['event_code']) ?></span>
                <details style="margin-top:7px"><summary style="cursor:pointer;font-size:11px">Editar</summary><?php include __DIR__ . '/_whatsapp_event_rule_form.php'; ?></details>
                <div class="wc-actions"><form method="post"><input type="hidden" name="action" value="toggle_event_rule"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><button class="btn btn-ghost btn-xs"><?= (int)$rule['is_active']===1?'Desativar':'Ativar' ?></button></form><form method="post" onsubmit="return confirm('Excluir regra?')"><input type="hidden" name="action" value="delete_event_rule"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><button class="btn btn-ghost btn-xs" style="color:var(--danger)">Excluir</button></form></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</div>
<script>
(function(){
    var select=document.getElementById('whatsapp-ai-model');
    var help=document.getElementById('whatsapp-ai-model-help');
    function update(){if(!select||!help)return;var option=select.options[select.selectedIndex];help.textContent=option?option.getAttribute('data-description')||'':'';}
    if(select){select.addEventListener('change',update);update();}
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
