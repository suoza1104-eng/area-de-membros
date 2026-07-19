<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/whatsapp_groups.php';

proteger_admin();
$pdo = getPDO();
whatsapp_groups_ensure_tables($pdo);

$menu = 'whatsapp_grupos';
$page_title = 'Grupos WhatsApp';

function wg_redirect(string $query = ''): void {
    header('Location: whatsapp_grupos.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function wg_section_query(string $section, array $params = []): string {
    $params = array_merge(['section' => $section], $params);
    return http_build_query($params);
}

function wg_roles($value): array {
    $allowed = ['spy', 'administrator', 'sender', 'creator', 'reserve'];
    $roles = is_array($value) ? $value : explode(',', (string)$value);
    $roles = array_values(array_unique(array_intersect($allowed, array_map('trim', $roles))));
    return $roles ?: ['spy'];
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'instance_state') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $instance = evolution_get_instance($pdo, $id);
    if (!$instance) {
        echo json_encode(['ok' => false, 'error' => 'Instancia nao encontrada.']);
        exit;
    }
    $before = (string)($instance['status'] ?? '');
    $res = evolution_fetch_state($pdo, $instance);
    $instance = evolution_get_instance($pdo, $id) ?: $instance;
    whatsapp_groups_log_connection($pdo, $instance, 'poll_state', $res, $before, (string)($instance['status'] ?? ''));
    echo json_encode([
        'ok' => !empty($res['ok']),
        'status' => (string)($instance['status'] ?? ''),
        'pairing_code' => (string)($instance['pairing_code'] ?? ''),
        'qr_base64' => (string)($instance['qr_base64'] ?? ''),
        'error' => (string)($instance['last_error'] ?? ''),
    ]);
    exit;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'create_instance') {
            $name = trim((string)($_POST['name'] ?? '')) ?: 'Numero WhatsApp';
            $key = trim((string)($_POST['instance_key'] ?? '')) ?: evolution_slug_instance($name);
            $roles = wg_roles($_POST['operational_roles'] ?? []);
            $role = in_array('administrator', $roles, true) ? 'administrator' : (in_array('reserve', $roles, true) ? 'reserve' : 'spy');
            $pdo->prepare("
                INSERT INTO whatsapp_instances
                    (name, instance_key, phone_number, status, instance_token, operational_role, operational_roles, role_priority, is_enabled, created_at, updated_at)
                VALUES
                    (:name, :instance_key, :phone, 'DISCONNECTED', :token, :role, :roles, :priority, 1, NOW(), NOW())
            ")->execute([
                ':name' => $name,
                ':instance_key' => $key,
                ':phone' => evolution_clean_whatsapp_phone((string)($_POST['phone_number'] ?? '')) ?: null,
                ':token' => bin2hex(random_bytes(24)),
                ':role' => $role,
                ':roles' => implode(',', $roles),
                ':priority' => max(1, (int)($_POST['role_priority'] ?? 100)),
            ]);
            $instance = evolution_get_instance($pdo, (int)$pdo->lastInsertId());
            $res = $instance ? evolution_create_remote_instance($pdo, $instance) : ['ok' => false, 'status' => 0, 'error' => 'Instancia local nao criada.'];
            whatsapp_groups_log_connection($pdo, $instance, 'create_instance', $res, 'NEW', $instance ? 'CONNECTING' : 'ERROR');
            wg_redirect('saved=instance#numeros');
        }

        if ($action === 'update_instance') {
            $id = (int)($_POST['id'] ?? 0);
            $roles = wg_roles($_POST['operational_roles'] ?? []);
            $role = in_array('administrator', $roles, true) ? 'administrator' : (in_array('reserve', $roles, true) ? 'reserve' : 'spy');
            $pdo->prepare("
                UPDATE whatsapp_instances
                   SET name=:name, phone_number=:phone, operational_role=:role, operational_roles=:roles,
                       role_priority=:priority, is_enabled=:enabled
                 WHERE id=:id
                 LIMIT 1
            ")->execute([
                ':name' => trim((string)($_POST['name'] ?? '')) ?: 'Numero WhatsApp',
                ':phone' => evolution_clean_whatsapp_phone((string)($_POST['phone_number'] ?? '')) ?: null,
                ':role' => $role,
                ':roles' => implode(',', $roles),
                ':priority' => max(1, (int)($_POST['role_priority'] ?? 100)),
                ':enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
                ':id' => $id,
            ]);
            wg_redirect('saved=instance#numeros');
        }

        if (in_array($action, ['connect_instance', 'refresh_instance', 'set_instance_webhook'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $instance = evolution_get_instance($pdo, $id);
            if (!$instance) throw new RuntimeException('Instancia nao encontrada.');
            $before = (string)($instance['status'] ?? '');
            if ($action === 'connect_instance') $res = evolution_connect_instance($pdo, $instance);
            elseif ($action === 'refresh_instance') $res = evolution_fetch_state($pdo, $instance);
            else {
                $url = rtrim(BASE_URL, '/') . '/whatsapp_webhook.php?t=' . evolution_get_webhook_token();
                $res = evolution_set_group_webhook((string)$instance['instance_key'], $url);
            }
            $updated = evolution_get_instance($pdo, $id) ?: $instance;
            whatsapp_groups_log_connection($pdo, $updated, $action, $res, $before, (string)($updated['status'] ?? ''));
            if (empty($res['ok']) && $action === 'set_instance_webhook') {
                throw new RuntimeException('Falha ao configurar webhook: ' . substr((string)($res['raw'] ?? $res['error']), 0, 600));
            }
            wg_redirect('saved=instance#numeros');
        }

        if ($action === 'create_campaign' || $action === 'update_campaign') {
            $id = (int)($_POST['campaign_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome da campanha.');
            $slug = whatsapp_groups_slug(trim((string)($_POST['slug'] ?? '')) ?: $name);
            $status = trim((string)($_POST['status'] ?? 'draft'));
            if (!in_array($status, ['draft','active','paused','archived'], true)) $status = 'draft';
            $params = [
                ':name' => $name,
                ':slug' => $slug,
                ':description' => trim((string)($_POST['description'] ?? '')) ?: null,
                ':status' => $status,
                ':default_instance_key' => trim((string)($_POST['default_instance_key'] ?? '')) ?: null,
                ':spy_instance_key' => trim((string)($_POST['spy_instance_key'] ?? '')) ?: null,
                ':max_leads_per_group' => max(0, (int)($_POST['max_leads_per_group'] ?? 0)),
                ':rotate_when_full' => !empty($_POST['rotate_when_full']) ? 1 : 0,
                ':verify_with_spy' => !empty($_POST['verify_with_spy']) ? 1 : 0,
                ':rate_per_minute' => max(1, (int)($_POST['rate_per_minute'] ?? 6)),
                ':rate_per_hour' => max(1, (int)($_POST['rate_per_hour'] ?? 120)),
                ':cooldown_seconds' => max(0, (int)($_POST['cooldown_seconds'] ?? 8)),
                ':public_url' => whatsapp_groups_public_campaign_url($slug),
            ];
            if ($action === 'update_campaign' && $id > 0) {
                $params[':id'] = $id;
                $pdo->prepare("
                    UPDATE whatsapp_group_campaigns
                       SET name=:name, slug=:slug, description=:description, status=:status,
                           default_instance_key=:default_instance_key, spy_instance_key=:spy_instance_key,
                           max_leads_per_group=:max_leads_per_group, rotate_when_full=:rotate_when_full,
                           verify_with_spy=:verify_with_spy, rate_per_minute=:rate_per_minute,
                           rate_per_hour=:rate_per_hour, cooldown_seconds=:cooldown_seconds,
                           public_url=:public_url, updated_at=NOW()
                     WHERE id=:id
                     LIMIT 1
                ")->execute($params);
            } else {
                $pdo->prepare("
                    INSERT INTO whatsapp_group_campaigns
                        (name, slug, description, status, default_instance_key, spy_instance_key, max_leads_per_group,
                         rotate_when_full, verify_with_spy, rate_per_minute, rate_per_hour, cooldown_seconds, public_url, created_at, updated_at)
                    VALUES
                        (:name, :slug, :description, :status, :default_instance_key, :spy_instance_key, :max_leads_per_group,
                         :rotate_when_full, :verify_with_spy, :rate_per_minute, :rate_per_hour, :cooldown_seconds, :public_url, NOW(), NOW())
                ")->execute($params);
            }
            wg_redirect('saved=campaign#campanhas');
        }

        if ($action === 'clone_campaign') {
            $id = (int)($_POST['campaign_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM whatsapp_group_campaigns WHERE id=:id LIMIT 1");
            $st->execute([':id' => $id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new RuntimeException('Campanha nao encontrada.');
            $slug = whatsapp_groups_slug((string)$c['slug'] . '-clone-' . date('His'));
            $pdo->prepare("
                INSERT INTO whatsapp_group_campaigns
                    (name, slug, description, status, default_instance_key, spy_instance_key, max_leads_per_group, rotate_when_full,
                     verify_with_spy, rate_per_minute, rate_per_hour, cooldown_seconds, public_url, created_at, updated_at)
                SELECT CONCAT(name, ' copia'), :slug, description, 'draft', default_instance_key, spy_instance_key, max_leads_per_group,
                       rotate_when_full, verify_with_spy, rate_per_minute, rate_per_hour, cooldown_seconds, :url, NOW(), NOW()
                  FROM whatsapp_group_campaigns WHERE id=:id
            ")->execute([':slug' => $slug, ':url' => whatsapp_groups_public_campaign_url($slug), ':id' => $id]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO whatsapp_group_campaign_groups
                    (campaign_id, group_id, group_name, invite_url, source, current_members, max_members, is_current, is_active, created_at, updated_at)
                SELECT :new_id, group_id, group_name, invite_url, source, current_members, max_members, is_current, is_active, NOW(), NOW()
                  FROM whatsapp_group_campaign_groups WHERE campaign_id=:old_id
            ")->execute([':new_id' => $newId, ':old_id' => $id]);
            wg_redirect('saved=campaign#campanhas');
        }

        if ($action === 'delete_campaign') {
            $id = (int)($_POST['campaign_id'] ?? 0);
            $pdo->prepare("UPDATE whatsapp_group_campaigns SET status='archived', updated_at=NOW() WHERE id=:id LIMIT 1")->execute([':id' => $id]);
            wg_redirect('saved=campaign#campanhas');
        }

        if ($action === 'add_campaign_group') {
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            if ($campaignId <= 0 || $groupId === '') throw new RuntimeException('Selecione campanha e grupo.');
            $groupName = trim((string)($_POST['group_name'] ?? ''));
            if ($groupName === '') {
                $st = $pdo->prepare("SELECT group_name FROM whatsapp_groups WHERE group_id=:gid LIMIT 1");
                $st->execute([':gid' => $groupId]);
                $groupName = trim((string)($st->fetchColumn() ?: ''));
            }
            $pdo->prepare("
                INSERT INTO whatsapp_group_campaign_groups
                    (campaign_id, group_id, group_name, invite_url, source, max_members, is_current, is_active, created_at, updated_at)
                VALUES
                    (:campaign_id, :group_id, :group_name, :invite_url, :source, :max_members, :is_current, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    group_name=VALUES(group_name), invite_url=VALUES(invite_url), max_members=VALUES(max_members), is_active=1, updated_at=NOW()
            ")->execute([
                ':campaign_id' => $campaignId,
                ':group_id' => $groupId,
                ':group_name' => $groupName ?: null,
                ':invite_url' => trim((string)($_POST['invite_url'] ?? '')) ?: null,
                ':source' => trim((string)($_POST['source'] ?? 'detected')) ?: 'detected',
                ':max_members' => max(0, (int)($_POST['max_members'] ?? 0)),
                ':is_current' => !empty($_POST['is_current']) ? 1 : 0,
            ]);
            wg_redirect('saved=group#grupos');
        }

        if ($action === 'toggle_campaign_group') {
            $id = (int)($_POST['campaign_group_id'] ?? 0);
            $pdo->prepare("UPDATE whatsapp_group_campaign_groups SET is_active=IF(is_active=1,0,1), updated_at=NOW() WHERE id=:id LIMIT 1")->execute([':id' => $id]);
            wg_redirect('saved=group#grupos');
        }

        if ($action === 'sync_groups') {
            $instance = trim((string)($_POST['instance_key'] ?? ''));
            if ($instance === '') throw new RuntimeException('Selecione a instancia.');
            $count = evolution_sync_groups_for_instance($pdo, $instance);
            wg_redirect('synced=' . $count . '#grupos');
        }

        if ($action === 'create_remote_group') {
            $instance = whatsapp_groups_select_instance($pdo, trim((string)($_POST['instance_key'] ?? '')), 'administrator');
            if ($instance === '') throw new RuntimeException('Nenhuma instancia administradora conectada.');
            $subject = trim((string)($_POST['subject'] ?? ''));
            $participants = array_values(array_filter(array_map('evolution_clean_whatsapp_phone', preg_split('/\r\n|\r|\n|,/', (string)($_POST['participants'] ?? '')))));
            if ($subject === '' || !$participants) throw new RuntimeException('Informe titulo e participantes.');
            $res = evolution_http('POST', '/group/create/' . rawurlencode($instance), [
                'subject' => $subject,
                'participants' => $participants,
                'description' => trim((string)($_POST['description'] ?? '')),
                'promoteParticipants' => !empty($_POST['promote_participants']),
            ]);
            if (empty($res['ok'])) throw new RuntimeException('Falha ao criar grupo: ' . substr((string)($res['raw'] ?? $res['error']), 0, 800));
            wg_redirect('saved=remote_group#grupos');
        }

        if ($action === 'create_scheduled_action') {
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            $type = trim((string)($_POST['action_type'] ?? 'send_text'));
            if (!isset(whatsapp_groups_action_types()[$type])) throw new RuntimeException('Tipo de acao invalido.');
            $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
            if ($scheduledAt === '') throw new RuntimeException('Informe a data/hora.');
            $scheduledAt = date('Y-m-d H:i:s', strtotime($scheduledAt));
            $pdo->prepare("
                INSERT INTO whatsapp_group_scheduled_actions
                    (campaign_id, group_id, instance_key, title, action_type, payload_json, scheduled_at, recurrence, recurrence_interval, status, max_attempts, created_at, updated_at)
                VALUES
                    (:campaign_id, :group_id, :instance_key, :title, :action_type, :payload_json, :scheduled_at, :recurrence, :recurrence_interval, 'scheduled', :max_attempts, NOW(), NOW())
            ")->execute([
                ':campaign_id' => $campaignId ?: null,
                ':group_id' => trim((string)($_POST['group_id'] ?? '')) ?: null,
                ':instance_key' => trim((string)($_POST['instance_key'] ?? '')) ?: null,
                ':title' => trim((string)($_POST['title'] ?? '')) ?: whatsapp_groups_action_types()[$type],
                ':action_type' => $type,
                ':payload_json' => json_encode(whatsapp_groups_payload_from_post($_POST), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':scheduled_at' => $scheduledAt,
                ':recurrence' => in_array((string)($_POST['recurrence'] ?? 'once'), ['once','daily','weekly','monthly'], true) ? (string)$_POST['recurrence'] : 'once',
                ':recurrence_interval' => max(1, (int)($_POST['recurrence_interval'] ?? 1)),
                ':max_attempts' => max(1, (int)($_POST['max_attempts'] ?? 3)),
            ]);
            $target = $campaignId > 0 ? wg_section_query('mensagens', ['campaign_id' => $campaignId, 'saved' => 'action']) : 'saved=action#programacoes';
            wg_redirect($target);
        }

        if ($action === 'run_action_now') {
            $id = (int)($_POST['action_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM whatsapp_group_scheduled_actions WHERE id=:id LIMIT 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Acao nao encontrada.');
            whatsapp_groups_execute_action($pdo, $row);
            $targetCampaign = (int)($row['campaign_id'] ?? 0);
            $target = $targetCampaign > 0 ? wg_section_query('mensagens', ['campaign_id' => $targetCampaign, 'saved' => 'run']) : 'saved=run#programacoes';
            wg_redirect($target);
        }

        if ($action === 'cancel_action') {
            $id = (int)($_POST['action_id'] ?? 0);
            $st = $pdo->prepare("SELECT campaign_id FROM whatsapp_group_scheduled_actions WHERE id=:id LIMIT 1");
            $st->execute([':id' => $id]);
            $targetCampaign = (int)($st->fetchColumn() ?: 0);
            $pdo->prepare("UPDATE whatsapp_group_scheduled_actions SET status='cancelled', updated_at=NOW() WHERE id=:id LIMIT 1")->execute([':id' => $id]);
            $target = $targetCampaign > 0 ? wg_section_query('mensagens', ['campaign_id' => $targetCampaign, 'saved' => 'action']) : 'saved=action#programacoes';
            wg_redirect($target);
        }

        if ($action === 'clone_action') {
            $id = (int)($_POST['action_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM whatsapp_group_scheduled_actions WHERE id=:id LIMIT 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Mensagem nao encontrada.');
            $pdo->prepare("
                INSERT INTO whatsapp_group_scheduled_actions
                    (campaign_id, group_id, instance_key, title, action_type, payload_json, scheduled_at, recurrence, recurrence_interval, status, max_attempts, created_at, updated_at)
                VALUES
                    (:campaign_id, :group_id, :instance_key, :title, :action_type, :payload_json, :scheduled_at, :recurrence, :recurrence_interval, 'scheduled', :max_attempts, NOW(), NOW())
            ")->execute([
                ':campaign_id' => $row['campaign_id'] ?: null,
                ':group_id' => $row['group_id'] ?: null,
                ':instance_key' => $row['instance_key'] ?: null,
                ':title' => trim((string)$row['title']) . ' copia',
                ':action_type' => (string)$row['action_type'],
                ':payload_json' => (string)($row['payload_json'] ?? ''),
                ':scheduled_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                ':recurrence' => (string)($row['recurrence'] ?? 'once'),
                ':recurrence_interval' => max(1, (int)($row['recurrence_interval'] ?? 1)),
                ':max_attempts' => max(1, (int)($row['max_attempts'] ?? 3)),
            ]);
            $targetCampaign = (int)($row['campaign_id'] ?? 0);
            $target = $targetCampaign > 0 ? wg_section_query('mensagens', ['campaign_id' => $targetCampaign, 'saved' => 'action']) : 'saved=action#programacoes';
            wg_redirect($target);
        }

        if ($action === 'delete_action') {
            $id = (int)($_POST['action_id'] ?? 0);
            $st = $pdo->prepare("SELECT campaign_id FROM whatsapp_group_scheduled_actions WHERE id=:id LIMIT 1");
            $st->execute([':id' => $id]);
            $targetCampaign = (int)($st->fetchColumn() ?: 0);
            $pdo->prepare("DELETE FROM whatsapp_group_scheduled_actions WHERE id=:id LIMIT 1")->execute([':id' => $id]);
            $target = $targetCampaign > 0 ? wg_section_query('mensagens', ['campaign_id' => $targetCampaign, 'saved' => 'action']) : 'saved=action#programacoes';
            wg_redirect($target);
        }

        if ($action === 'save_keyword') {
            $id = (int)($_POST['keyword_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $keyword = trim((string)($_POST['keyword'] ?? ''));
            if ($name === '' || $keyword === '') throw new RuntimeException('Informe nome e palavra-chave.');
            $params = [
                ':name' => $name,
                ':campaign_id' => (int)($_POST['campaign_id'] ?? 0) ?: null,
                ':group_id' => trim((string)($_POST['group_id'] ?? '')) ?: null,
                ':keyword' => $keyword,
                ':match_mode' => in_array((string)($_POST['match_mode'] ?? 'contains'), ['contains','equals','starts'], true) ? (string)$_POST['match_mode'] : 'contains',
                ':trigger_event' => strtoupper(trim((string)($_POST['trigger_event'] ?? 'WHATSAPP_GRUPO_PALAVRA_CHAVE'))) ?: 'WHATSAPP_GRUPO_PALAVRA_CHAVE',
                ':is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($id > 0) {
                $params[':id'] = $id;
                $pdo->prepare("UPDATE whatsapp_group_keyword_rules SET name=:name,campaign_id=:campaign_id,group_id=:group_id,keyword=:keyword,match_mode=:match_mode,trigger_event=:trigger_event,is_active=:is_active WHERE id=:id LIMIT 1")->execute($params);
            } else {
                $pdo->prepare("INSERT INTO whatsapp_group_keyword_rules (name,campaign_id,group_id,keyword,match_mode,trigger_event,is_active,created_at,updated_at) VALUES (:name,:campaign_id,:group_id,:keyword,:match_mode,:trigger_event,:is_active,NOW(),NOW())")->execute($params);
            }
            wg_redirect('saved=keyword#palavras');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved'])) $notice = 'Alteracao salva.';
if (isset($_GET['synced'])) $notice = 'Grupos sincronizados: ' . (int)$_GET['synced'] . '.';

$instances = $pdo->query("SELECT * FROM whatsapp_instances ORDER BY role_priority, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$connectedCount = 0;
foreach ($instances as $inst) if (strtoupper((string)$inst['status']) === 'CONNECTED') $connectedCount++;
$campaigns = $pdo->query("SELECT * FROM whatsapp_group_campaigns WHERE status <> 'archived' ORDER BY updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedSection = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['section'] ?? 'visao')) ?: 'visao';
$selectedCampaignId = (int)($_GET['campaign_id'] ?? 0);
$groups = $pdo->query("SELECT * FROM whatsapp_groups ORDER BY COALESCE(group_name, group_id)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$campaignGroups = $pdo->query("
    SELECT cg.*, c.name AS campaign_name
      FROM whatsapp_group_campaign_groups cg
      JOIN whatsapp_group_campaigns c ON c.id=cg.campaign_id
     ORDER BY c.updated_at DESC, cg.id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$campaignGroupCountsRows = $pdo->query("
    SELECT campaign_id, COUNT(*) AS total
      FROM whatsapp_group_campaign_groups
     GROUP BY campaign_id
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$campaignMessageCountsRows = $pdo->query("
    SELECT campaign_id, COUNT(*) AS total
      FROM whatsapp_group_scheduled_actions
     WHERE campaign_id IS NOT NULL
     GROUP BY campaign_id
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$actions = $pdo->query("
    SELECT a.*, c.name AS campaign_name, COALESCE(wg.group_name, a.group_id) AS resolved_group_name
      FROM whatsapp_group_scheduled_actions a
      LEFT JOIN whatsapp_group_campaigns c ON c.id=a.campaign_id
      LEFT JOIN whatsapp_groups wg ON wg.group_id=a.group_id
     ORDER BY FIELD(a.status,'processing','scheduled','error','sent','cancelled'), a.scheduled_at DESC
     LIMIT 80
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$selectedCampaign = null;
foreach ($campaigns as $campaignRow) {
    if ((int)$campaignRow['id'] === $selectedCampaignId) {
        $selectedCampaign = $campaignRow;
        break;
    }
}
if (!$selectedCampaign && $selectedCampaignId > 0) $selectedCampaignId = 0;
$campaignMessages = [];
if ($selectedCampaignId > 0) {
    $st = $pdo->prepare("
        SELECT a.*, c.name AS campaign_name, COALESCE(wg.group_name, a.group_id) AS resolved_group_name
          FROM whatsapp_group_scheduled_actions a
          LEFT JOIN whatsapp_group_campaigns c ON c.id=a.campaign_id
          LEFT JOIN whatsapp_groups wg ON wg.group_id=a.group_id
         WHERE a.campaign_id=:campaign_id
         ORDER BY FIELD(a.status,'processing','scheduled','error','sent','cancelled'), a.scheduled_at DESC, a.id DESC
    ");
    $st->execute([':campaign_id' => $selectedCampaignId]);
    $campaignMessages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$logs = $pdo->query("
    SELECT l.*, c.name AS campaign_name, COALESCE(wg.group_name, l.group_id) AS resolved_group_name
      FROM whatsapp_group_action_logs l
      LEFT JOIN whatsapp_group_campaigns c ON c.id=l.campaign_id
      LEFT JOIN whatsapp_groups wg ON wg.group_id=l.group_id
     ORDER BY l.id DESC
     LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$keywords = $pdo->query("
    SELECT k.*, c.name AS campaign_name, COALESCE(wg.group_name, k.group_id) AS resolved_group_name
      FROM whatsapp_group_keyword_rules k
      LEFT JOIN whatsapp_group_campaigns c ON c.id=k.campaign_id
      LEFT JOIN whatsapp_groups wg ON wg.group_id=k.group_id
     ORDER BY k.id DESC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$connectionLogs = $pdo->query("SELECT * FROM whatsapp_group_connection_logs ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalScheduled = 0; $totalErrors = 0; $totalSent = 0;
foreach ($actions as $a) {
    if ((string)$a['status'] === 'scheduled') $totalScheduled++;
    if ((string)$a['status'] === 'error') $totalErrors++;
    if ((string)$a['status'] === 'sent') $totalSent++;
}
$actionTypes = whatsapp_groups_action_types();
$campaignGroupCounts = [];
$campaignMessageCounts = [];
foreach ($campaignGroupCountsRows as $countRow) $campaignGroupCounts[(int)$countRow['campaign_id']] = (int)$countRow['total'];
foreach ($campaignMessageCountsRows as $countRow) $campaignMessageCounts[(int)$countRow['campaign_id']] = (int)$countRow['total'];

require __DIR__ . '/_header.php';
?>
<style>
.wg-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;border-bottom:1px solid var(--border);padding-bottom:10px}
.wg-tab{padding:8px 12px;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:12px;background:transparent;line-height:1.2}
.wg-tab:hover{background:var(--bg-hover);color:var(--text)}
.wg-tab.active{background:var(--primary-dim);border-color:rgba(250,204,21,.35);color:var(--primary);font-weight:700}
.wg-section{display:none;scroll-margin-top:80px}
.wg-section.active{display:block}
.wg-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px}
.wg-title{font-size:16px;font-weight:800;margin-bottom:4px}
.wg-sub{color:var(--muted);font-size:12px;margin-bottom:14px}
.wg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.wg-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.wg-directory{display:grid;grid-template-columns:repeat(auto-fill,minmax(295px,1fr));gap:20px;align-items:stretch}
.wg-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.wg-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.wg-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:999px;border:1px solid var(--border);font-size:11px;color:var(--muted)}
.wg-chip.ok{color:#86efac;background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.25)}
.wg-chip.err{color:#fca5a5;background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.25)}
.wg-chip.warn{color:#fde68a;background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.25)}
.wg-instance{border:1px solid var(--border);border-radius:10px;padding:12px;background:rgba(255,255,255,.02)}
.wg-qr{width:180px;background:#fff;border-radius:8px;padding:6px;margin-top:10px}
.wg-checks{display:flex;gap:12px;flex-wrap:wrap;border:1px solid var(--border);border-radius:9px;padding:10px}
.wg-check{display:flex;gap:6px;align-items:center;font-size:12px;color:var(--text)}
.wg-help{font-size:11px;color:var(--muted);line-height:1.45;margin-top:5px}
.wg-soft{background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.18);border-radius:10px;padding:12px;color:var(--muted);font-size:12px;line-height:1.5}
.wg-payload-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.wg-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.wg-page-title{font-size:22px;font-weight:800;letter-spacing:0;color:var(--text)}
.wg-breadcrumb{font-size:12px;color:var(--muted);margin-top:4px}
.wg-card-tile{position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:22px 18px 16px;min-height:268px;display:flex;flex-direction:column;box-shadow:0 8px 24px rgba(0,0,0,.16)}
.wg-card-tile:hover{border-color:var(--border-light);transform:translateY(-1px)}
.wg-tile-menu{position:absolute;top:16px;right:14px;color:var(--muted);font-weight:800;letter-spacing:2px}
.wg-tile-icon{width:52px;height:52px;margin:4px auto 18px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.04);color:var(--muted);font-size:30px}
.wg-tile-name{font-size:16px;font-weight:800;color:var(--text);line-height:1.25;margin-bottom:8px}
.wg-tile-meta{font-size:12px;color:var(--muted);line-height:1.55;word-break:break-word}
.wg-tile-progress{height:8px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden;margin:18px 0 8px}
.wg-tile-progress span{display:block;height:100%;background:#a855f7;border-radius:999px}
.wg-tile-buttons{display:grid;gap:8px;margin-top:auto;padding-top:16px}
.wg-tile-buttons .btn{justify-content:center;width:100%;border-radius:7px}
.wg-tile-buttons .btn-primary{background:#a855f7;color:white;border-color:#a855f7}
.wg-card-dim{background:rgba(148,163,184,.14);color:var(--text);border-color:transparent}
.wg-top-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.wg-inline-details summary{list-style:none;cursor:pointer}
.wg-inline-details summary::-webkit-details-marker{display:none}
.wg-message-layout{display:grid;grid-template-columns:minmax(310px,390px) minmax(0,1fr);gap:18px;align-items:start}
.wg-message-card{border:1px solid var(--border);background:rgba(255,255,255,.025);border-radius:8px;padding:14px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start;margin-bottom:10px}
.wg-message-title{font-weight:800;margin-bottom:4px}
.wg-message-preview{font-size:12px;color:var(--muted);max-width:680px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:1050px){.wg-grid,.wg-grid-3,.wg-row,.wg-payload-grid{grid-template-columns:1fr}}
@media(max-width:1050px){.wg-message-layout{grid-template-columns:1fr}.wg-page-head{display:block}.wg-top-actions{justify-content:flex-start;margin-top:12px}}
</style>

<?php if ($notice): ?><div class="alert alert-ok mb-3"><?= whatsapp_groups_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-3"><?= whatsapp_groups_h($error) ?></div><?php endif; ?>

<div class="wg-nav">
    <button type="button" class="wg-tab active" data-tab="visao">Visao geral</button>
    <button type="button" class="wg-tab" data-tab="numeros">Numeros</button>
    <button type="button" class="wg-tab" data-tab="campanhas">Campanhas</button>
    <button type="button" class="wg-tab" data-tab="mensagens">Mensagens</button>
    <button type="button" class="wg-tab" data-tab="grupos">Grupos</button>
    <button type="button" class="wg-tab" data-tab="programacoes">Programacoes</button>
    <button type="button" class="wg-tab" data-tab="palavras">Palavras-chave</button>
    <button type="button" class="wg-tab" data-tab="logs">Logs</button>
    <button type="button" class="wg-tab" data-tab="saude">Saude</button>
</div>

<section id="visao" class="wg-section active">
    <div class="kpi-grid">
        <div class="kpi kpi-g"><div class="kpi-label">Numeros conectados</div><div class="kpi-value"><?= (int)$connectedCount ?></div><div class="kpi-sub"><?= count($instances) ?> cadastrados</div></div>
        <div class="kpi kpi-y"><div class="kpi-label">Campanhas ativas</div><div class="kpi-value"><?= count(array_filter($campaigns, fn($c) => (string)$c['status'] === 'active')) ?></div><div class="kpi-sub"><?= count($campaigns) ?> em operacao</div></div>
        <div class="kpi kpi-b"><div class="kpi-label">Acoes programadas</div><div class="kpi-value"><?= (int)$totalScheduled ?></div><div class="kpi-sub"><?= (int)$totalSent ?> enviadas nesta listagem</div></div>
        <div class="kpi kpi-r"><div class="kpi-label">Erros recentes</div><div class="kpi-value"><?= (int)$totalErrors ?></div><div class="kpi-sub">ultimas 80 acoes</div></div>
    </div>
    <div class="wg-grid">
        <div class="panel"><div class="panel-title">Fila por status</div><canvas id="wgStatusChart"></canvas></div>
        <div class="panel"><div class="panel-title">Envios por tipo</div><canvas id="wgTypeChart"></canvas></div>
    </div>
</section>

<section id="numeros" class="wg-section wg-card">
    <div class="wg-title">Numeros conectados</div>
    <div class="wg-sub">Conexao por QR/pairing code, polling de status e funcoes operacionais por numero.</div>
    <div class="wg-grid">
        <form method="post" class="wg-card" style="margin:0">
            <input type="hidden" name="action" value="create_instance">
            <div class="wg-title" style="font-size:14px">Cadastrar numero</div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" placeholder="Disparador 01"></div><div class="form-group"><label class="form-label">Chave da instancia</label><input name="instance_key" placeholder="disparador-01"></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Telefone para pairing code</label><input name="phone_number" placeholder="5511999999999"></div><div class="form-group"><label class="form-label">Prioridade</label><input type="number" name="role_priority" value="100" min="1"></div></div>
            <div class="form-group"><label class="form-label">Funcoes</label><div class="wg-checks">
                <?php foreach (['spy'=>'Espiao','administrator'=>'Administrador','sender'=>'Disparador','creator'=>'Criador de grupos','reserve'=>'Stand-by'] as $value=>$label): ?>
                    <label class="wg-check"><input type="checkbox" name="operational_roles[]" value="<?= $value ?>" <?= $value==='spy'?'checked':'' ?>><?= $label ?></label>
                <?php endforeach; ?>
            </div></div>
            <button class="btn btn-primary">Criar e gerar conexao</button>
            <div class="wg-help">Com telefone preenchido, a Evolution pode retornar pairing code. Sem telefone, usa QR Code.</div>
        </form>
        <div class="wg-soft">
            <strong>Melhorias contra falha de conexao</strong><br>
            A pagina consulta o estado automaticamente enquanto uma instancia estiver conectando, exibe QR atualizado, pairing code e ultimo erro. Se uma tentativa ficar presa, aguarde expirar antes de gerar novo QR para evitar conflito de sessao.
        </div>
    </div>
    <div class="wg-grid-3" style="margin-top:14px">
        <?php foreach ($instances as $instance): $roles = wg_roles((string)($instance['operational_roles'] ?? $instance['operational_role'] ?? 'spy')); $status = strtoupper((string)$instance['status']); $qr=(string)($instance['qr_base64']??''); if($qr!=='' && stripos($qr,'data:image')!==0)$qr='data:image/png;base64,'.$qr; ?>
            <div class="wg-instance" data-instance-id="<?= (int)$instance['id'] ?>">
                <form method="post">
                    <input type="hidden" name="action" value="update_instance"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>">
                    <div class="wg-actions" style="justify-content:space-between;margin-bottom:8px"><strong><?= whatsapp_groups_h((string)$instance['name']) ?></strong><span class="wg-chip <?= $status==='CONNECTED'?'ok':($status==='ERROR'?'err':'warn') ?>" data-state><?= whatsapp_groups_h($status) ?></span></div>
                    <div class="wg-help"><?= whatsapp_groups_h((string)$instance['instance_key']) ?></div>
                    <div class="wg-row" style="margin-top:10px"><div class="form-group"><label class="form-label">Nome</label><input name="name" value="<?= whatsapp_groups_h((string)$instance['name']) ?>"></div><div class="form-group"><label class="form-label">Telefone</label><input name="phone_number" value="<?= whatsapp_groups_h((string)$instance['phone_number']) ?>"></div></div>
                    <div class="form-group"><label class="form-label">Funcoes</label><div class="wg-checks">
                        <?php foreach (['spy'=>'Espiao','administrator'=>'Admin','sender'=>'Disparador','creator'=>'Criador','reserve'=>'Stand-by'] as $value=>$label): ?>
                            <label class="wg-check"><input type="checkbox" name="operational_roles[]" value="<?= $value ?>" <?= in_array($value,$roles,true)?'checked':'' ?>><?= $label ?></label>
                        <?php endforeach; ?>
                    </div></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Prioridade</label><input type="number" name="role_priority" value="<?= (int)$instance['role_priority'] ?>"></div><label class="form-label" style="padding-top:27px"><input type="checkbox" name="is_enabled" value="1" <?= (int)$instance['is_enabled']===1?'checked':'' ?>> Ativo</label></div>
                    <button class="btn btn-primary btn-sm">Salvar</button>
                </form>
                <div class="wg-actions" style="margin-top:8px">
                    <form method="post"><input type="hidden" name="action" value="connect_instance"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>"><button class="btn btn-ghost btn-xs">QR/pairing</button></form>
                    <form method="post"><input type="hidden" name="action" value="refresh_instance"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>"><button class="btn btn-ghost btn-xs">Status</button></form>
                    <form method="post"><input type="hidden" name="action" value="set_instance_webhook"><input type="hidden" name="id" value="<?= (int)$instance['id'] ?>"><button class="btn btn-ghost btn-xs">Webhook</button></form>
                </div>
                <div class="wg-help">Pairing: <strong data-pairing><?= whatsapp_groups_h((string)($instance['pairing_code'] ?? '')) ?: '-' ?></strong></div>
                <?php if ($qr): ?><img class="wg-qr" data-qr src="<?= whatsapp_groups_h($qr) ?>" alt="QR Code"><?php else: ?><img class="wg-qr" data-qr src="" alt="QR Code" style="display:none"><?php endif; ?>
                <?php if (!empty($instance['last_error'])): ?><div class="wg-chip err" style="margin-top:8px"><?= whatsapp_groups_h(substr((string)$instance['last_error'],0,100)) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section id="campanhas" class="wg-section">
    <div class="wg-page-head">
        <div>
            <div class="wg-page-title">Suas campanhas de WhatsApp</div>
            <div class="wg-breadcrumb">Dashboard &gt; Suas campanhas de WhatsApp</div>
        </div>
        <div class="wg-top-actions">
            <a class="btn btn-ghost" href="whatsapp_grupos.php?section=campanhas_encerradas">Ver campanhas encerradas</a>
            <details class="wg-inline-details">
                <summary class="btn btn-primary">+ Criar nova campanha</summary>
                <form method="post" class="wg-card" style="position:absolute;right:24px;z-index:20;width:min(620px,calc(100vw - 48px));margin-top:10px">
                    <input type="hidden" name="action" value="create_campaign">
                    <div class="wg-title" style="font-size:14px">Nova campanha</div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" required placeholder="17/07/2026 - MCQDC"></div><div class="form-group"><label class="form-label">Slug</label><input name="slug" placeholder="mcqdc-170726"></div></div>
                    <div class="form-group"><label class="form-label">Descricao</label><textarea name="description" rows="2"></textarea></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Status</label><select name="status"><option value="draft">Rascunho</option><option value="active">Ativa</option><option value="paused">Pausada</option></select></div><div class="form-group"><label class="form-label">Max. leads por grupo</label><input type="number" name="max_leads_per_group" value="0"></div></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Instancia disparadora padrao</label><select name="default_instance_key"><option value="">Selecionar automaticamente</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Instancia espia</label><select name="spy_instance_key"><option value="">Sem verificacao fixa</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Envios/minuto</label><input type="number" name="rate_per_minute" value="6"></div><div class="form-group"><label class="form-label">Cooldown segundos</label><input type="number" name="cooldown_seconds" value="8"></div></div>
                    <label class="form-label"><input type="checkbox" name="rotate_when_full" checked> Rotacionar grupo quando lotar</label>
                    <label class="form-label"><input type="checkbox" name="verify_with_spy"> Verificar envio com numero espiao quando possivel</label>
                    <button class="btn btn-primary">Criar campanha</button>
                </form>
            </details>
        </div>
    </div>
    <div class="wg-directory">
        <?php foreach($campaigns as $c):
            $cid = (int)$c['id'];
            $groupCount = (int)($campaignGroupCounts[$cid] ?? 0);
            $messageCount = (int)($campaignMessageCounts[$cid] ?? 0);
            $limit = (int)($c['max_leads_per_group'] ?? 0);
            $entries = (int)($c['total_entries'] ?? 0);
            $usage = $limit > 0 && $groupCount > 0 ? min(100, (int)round(($entries / ($limit * $groupCount)) * 100)) : 100;
        ?>
            <article class="wg-card-tile">
                <span class="wg-tile-menu">...</span>
                <div class="wg-tile-icon">^</div>
                <div class="wg-tile-name"><?= whatsapp_groups_h((string)$c['name']) ?></div>
                <div class="wg-tile-meta">
                    <span class="wg-chip <?= (string)$c['status']==='active'?'ok':((string)$c['status']==='paused'?'warn':'') ?>"><?= whatsapp_groups_h(whatsapp_groups_status_label((string)$c['status'])) ?></span>
                    <?= $groupCount ?> grupo<?= $groupCount===1?'':'s' ?> · <?= $messageCount ?> mensagem<?= $messageCount===1?'':'s' ?>
                </div>
                <div class="wg-tile-meta" style="margin-top:8px"><?= whatsapp_groups_h((string)$c['public_url']) ?></div>
                <div class="wg-tile-progress"><span style="width:<?= $usage ?>%"></span></div>
                <div class="wg-tile-meta" style="text-align:center"><?= $groupCount ?> de <?= max(1, $groupCount) ?> grupos utilizados<?= $limit > 0 ? ' (' . $usage . '%)' : '' ?></div>
                <div class="wg-tile-buttons">
                    <a class="btn wg-card-dim" href="whatsapp_grupos.php?section=grupos">Seus Leads</a>
                    <details class="wg-inline-details">
                        <summary class="btn wg-card-dim">Configurações da campanha</summary>
                        <form method="post" class="wg-card" style="margin-top:8px"><input type="hidden" name="action" value="update_campaign"><input type="hidden" name="campaign_id" value="<?= $cid ?>">
                            <div class="wg-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" value="<?= whatsapp_groups_h((string)$c['name']) ?>"></div><div class="form-group"><label class="form-label">Slug</label><input name="slug" value="<?= whatsapp_groups_h((string)$c['slug']) ?>"></div></div>
                            <div class="form-group"><label class="form-label">Descricao</label><textarea name="description" rows="2"><?= whatsapp_groups_h((string)$c['description']) ?></textarea></div>
                            <div class="wg-row"><div class="form-group"><label class="form-label">Status</label><select name="status"><?php foreach(['draft'=>'Rascunho','active'=>'Ativa','paused'=>'Pausada'] as $v=>$l): ?><option value="<?= $v ?>" <?= (string)$c['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Max. leads/grupo</label><input type="number" name="max_leads_per_group" value="<?= (int)$c['max_leads_per_group'] ?>"></div></div>
                            <div class="wg-row"><div class="form-group"><label class="form-label">Disparadora</label><select name="default_instance_key"><option value="">Auto</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>" <?= (string)$c['default_instance_key']===(string)$i['instance_key']?'selected':'' ?>><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Espia</label><select name="spy_instance_key"><option value="">Auto/nenhuma</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>" <?= (string)$c['spy_instance_key']===(string)$i['instance_key']?'selected':'' ?>><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div></div>
                            <div class="wg-row"><div class="form-group"><label class="form-label">Envios/min</label><input type="number" name="rate_per_minute" value="<?= (int)$c['rate_per_minute'] ?>"></div><div class="form-group"><label class="form-label">Cooldown</label><input type="number" name="cooldown_seconds" value="<?= (int)$c['cooldown_seconds'] ?>"></div></div>
                            <label class="form-label"><input type="checkbox" name="rotate_when_full" <?= (int)$c['rotate_when_full']===1?'checked':'' ?>> Rotacionar quando lotar</label>
                            <label class="form-label"><input type="checkbox" name="verify_with_spy" <?= (int)$c['verify_with_spy']===1?'checked':'' ?>> Verificar com espiao</label>
                            <button class="btn btn-primary btn-sm">Salvar campanha</button>
                        </form>
                    </details>
                    <a class="btn wg-card-dim" href="whatsapp_grupos.php?section=logs">Estatísticas da campanha</a>
                    <a class="btn btn-primary" href="whatsapp_grupos.php?section=mensagens&campaign_id=<?= $cid ?>">Mensagens Programadas</a>
                    <div class="wg-actions">
                        <form method="post"><input type="hidden" name="action" value="clone_campaign"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn-ghost btn-xs">Clonar</button></form>
                        <form method="post" onsubmit="return confirm('Arquivar esta campanha?')"><input type="hidden" name="action" value="delete_campaign"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn-ghost btn-xs" style="color:var(--danger)">Arquivar</button></form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if(!$campaigns): ?><div class="wg-card">Nenhuma campanha criada.</div><?php endif; ?>
    </div>
</section>

<section id="campanhas_legacy" class="wg-section wg-card" style="display:none">
    <div class="wg-title">Campanhas</div>
    <div class="wg-sub">Crie campanhas com link publico, grupos vinculados, rotação, limites e verificacao pelo numero espiao.</div>
    <details open><summary class="btn btn-ghost btn-sm" style="display:inline-flex">+ Criar campanha</summary>
        <form method="post" class="wg-card" style="margin-top:10px">
            <input type="hidden" name="action" value="create_campaign">
            <div class="wg-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" required placeholder="17/07/2026 - MCQDC"></div><div class="form-group"><label class="form-label">Slug</label><input name="slug" placeholder="mcqdc-170726"></div></div>
            <div class="form-group"><label class="form-label">Descricao</label><textarea name="description" rows="2"></textarea></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Status</label><select name="status"><option value="draft">Rascunho</option><option value="active">Ativa</option><option value="paused">Pausada</option></select></div><div class="form-group"><label class="form-label">Max. leads por grupo</label><input type="number" name="max_leads_per_group" value="0"></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Instancia disparadora padrao</label><select name="default_instance_key"><option value="">Selecionar automaticamente</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Instancia espia</label><select name="spy_instance_key"><option value="">Sem verificacao fixa</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Envios/minuto</label><input type="number" name="rate_per_minute" value="6"></div><div class="form-group"><label class="form-label">Cooldown segundos</label><input type="number" name="cooldown_seconds" value="8"></div></div>
            <label class="form-label"><input type="checkbox" name="rotate_when_full" checked> Rotacionar grupo quando lotar</label>
            <label class="form-label"><input type="checkbox" name="verify_with_spy"> Verificar envio com numero espiao quando possivel</label>
            <button class="btn btn-primary">Criar campanha</button>
        </form>
    </details>
    <div class="table-wrap">
        <table><thead><tr><th>Campanha</th><th>Status</th><th>Link</th><th>Limites</th><th>Resultados</th><th>Acoes</th></tr></thead><tbody>
        <?php foreach($campaigns as $c): ?>
            <tr>
                <td><strong><?= whatsapp_groups_h((string)$c['name']) ?></strong><div class="text-xs text-muted"><?= whatsapp_groups_h((string)$c['description']) ?></div></td>
                <td><span class="wg-chip <?= (string)$c['status']==='active'?'ok':((string)$c['status']==='paused'?'warn':'') ?>"><?= whatsapp_groups_h(whatsapp_groups_status_label((string)$c['status'])) ?></span></td>
                <td class="truncate" style="max-width:260px"><?= whatsapp_groups_h((string)$c['public_url']) ?></td>
                <td><?= (int)$c['rate_per_minute'] ?>/min<br><span class="text-xs text-muted"><?= (int)$c['cooldown_seconds'] ?>s cooldown</span></td>
                <td><?= (int)$c['total_sent'] ?> enviados<br><span class="text-xs text-muted"><?= (int)$c['total_errors'] ?> erros</span></td>
                <td><div class="wg-actions"><form method="post"><input type="hidden" name="action" value="clone_campaign"><input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-ghost btn-xs">Clonar</button></form><form method="post"><input type="hidden" name="action" value="delete_campaign"><input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-ghost btn-xs" style="color:var(--danger)">Arquivar</button></form></div></td>
            </tr>
            <tr><td colspan="6"><details><summary style="cursor:pointer;color:var(--primary);font-size:12px">Editar campanha</summary>
                <form method="post" class="wg-card" style="margin-top:8px"><input type="hidden" name="action" value="update_campaign"><input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                    <div class="wg-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" value="<?= whatsapp_groups_h((string)$c['name']) ?>"></div><div class="form-group"><label class="form-label">Slug</label><input name="slug" value="<?= whatsapp_groups_h((string)$c['slug']) ?>"></div></div>
                    <div class="form-group"><label class="form-label">Descricao</label><textarea name="description" rows="2"><?= whatsapp_groups_h((string)$c['description']) ?></textarea></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Status</label><select name="status"><?php foreach(['draft'=>'Rascunho','active'=>'Ativa','paused'=>'Pausada'] as $v=>$l): ?><option value="<?= $v ?>" <?= (string)$c['status']===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Max. leads/grupo</label><input type="number" name="max_leads_per_group" value="<?= (int)$c['max_leads_per_group'] ?>"></div></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Disparadora</label><select name="default_instance_key"><option value="">Auto</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>" <?= (string)$c['default_instance_key']===(string)$i['instance_key']?'selected':'' ?>><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Espia</label><select name="spy_instance_key"><option value="">Auto/nenhuma</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>" <?= (string)$c['spy_instance_key']===(string)$i['instance_key']?'selected':'' ?>><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div></div>
                    <div class="wg-row"><div class="form-group"><label class="form-label">Envios/min</label><input type="number" name="rate_per_minute" value="<?= (int)$c['rate_per_minute'] ?>"></div><div class="form-group"><label class="form-label">Cooldown</label><input type="number" name="cooldown_seconds" value="<?= (int)$c['cooldown_seconds'] ?>"></div></div>
                    <label class="form-label"><input type="checkbox" name="rotate_when_full" <?= (int)$c['rotate_when_full']===1?'checked':'' ?>> Rotacionar quando lotar</label>
                    <label class="form-label"><input type="checkbox" name="verify_with_spy" <?= (int)$c['verify_with_spy']===1?'checked':'' ?>> Verificar com espiao</label>
                    <button class="btn btn-primary btn-sm">Salvar campanha</button>
                </form>
            </details></td></tr>
        <?php endforeach; ?>
        <?php if(!$campaigns): ?><tr><td colspan="6">Nenhuma campanha criada.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</section>

<section id="campanhas_encerradas" class="wg-section wg-card">
    <div class="wg-title">Campanhas encerradas</div>
    <div class="wg-sub">Campanhas arquivadas ficam fora da grade principal.</div>
    <div class="wg-help">Use a grade principal para operar campanhas ativas, pausadas e rascunhos. Campanhas arquivadas continuam preservadas no banco.</div>
</section>

<section id="mensagens" class="wg-section wg-card">
    <div class="wg-page-head">
        <div>
            <div class="wg-page-title">Mensagens da campanha</div>
            <div class="wg-breadcrumb">Campanhas &gt; <?= $selectedCampaign ? whatsapp_groups_h((string)$selectedCampaign['name']) : 'Selecione uma campanha' ?></div>
        </div>
        <div class="wg-top-actions"><a class="btn btn-ghost" href="whatsapp_grupos.php?section=campanhas">Voltar para campanhas</a></div>
    </div>
    <?php if (!$selectedCampaign): ?>
        <div class="wg-directory">
            <?php foreach($campaigns as $c): ?><a class="wg-card-tile" href="whatsapp_grupos.php?section=mensagens&campaign_id=<?= (int)$c['id'] ?>"><div class="wg-tile-icon">^</div><div class="wg-tile-name"><?= whatsapp_groups_h((string)$c['name']) ?></div><div class="wg-tile-meta">Abrir mensagens programadas desta campanha</div></a><?php endforeach; ?>
        </div>
    <?php else: ?>
    <div class="wg-message-layout">
        <form method="post" class="wg-card" style="margin:0"><input type="hidden" name="action" value="create_scheduled_action"><input type="hidden" name="campaign_id" value="<?= $selectedCampaignId ?>">
            <div class="wg-title" style="font-size:15px">Inserir nova mensagem</div>
            <div class="form-group"><label class="form-label">Grupo especifico</label><select name="group_id"><option value="">Usar grupo atual da campanha</option><?php foreach($groups as $g): ?><option value="<?= whatsapp_groups_h((string)$g['group_id']) ?>"><?= whatsapp_groups_h((string)($g['group_name'] ?: $g['group_id'])) ?></option><?php endforeach; ?></select></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Titulo interno</label><input name="title" placeholder="Aviso de abertura"></div><div class="form-group"><label class="form-label">Tipo</label><select name="action_type"><?php foreach($actionTypes as $v=>$l): ?><option value="<?= $v ?>"><?= whatsapp_groups_h($l) ?></option><?php endforeach; ?></select></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Data/hora</label><input type="datetime-local" name="scheduled_at" required value="<?= date('Y-m-d\TH:i') ?>"></div><div class="form-group"><label class="form-label">Instancia</label><select name="instance_key"><option value="">Auto</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div></div>
            <div class="form-group"><label class="form-label">Texto / mensagem</label><textarea name="text" rows="6" placeholder="Mensagem do grupo"></textarea></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">URL da midia/audio</label><input name="media_url" placeholder="https://..."></div><div class="form-group"><label class="form-label">Legenda</label><input name="caption" placeholder="Legenda"></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Recorrencia</label><select name="recurrence"><option value="once">Unica</option><option value="daily">Diaria</option><option value="weekly">Semanal</option><option value="monthly">Mensal</option></select></div><div class="form-group"><label class="form-label">Tentativas</label><input type="number" name="max_attempts" value="3" min="1"></div></div>
            <input type="hidden" name="recurrence_interval" value="1">
            <label class="form-label"><input type="checkbox" name="mentions_everyone"> Mencionar todos</label>
            <label class="form-label"><input type="checkbox" name="link_preview" checked> Preview de link</label>
            <button class="btn btn-primary">Programar mensagem</button>
        </form>
        <div>
            <?php foreach($campaignMessages as $a): $payload = json_decode((string)($a['payload_json'] ?? ''), true) ?: []; ?>
                <div class="wg-message-card">
                    <div>
                        <div class="wg-message-title"><?= whatsapp_groups_h((string)$a['title']) ?></div>
                        <div class="wg-message-preview"><?= whatsapp_groups_h((string)($payload['text'] ?? $payload['caption'] ?? $payload['media_url'] ?? 'Sem texto cadastrado')) ?></div>
                        <div class="wg-tile-meta" style="margin-top:8px"><?= date('d/m/Y H:i', strtotime((string)$a['scheduled_at'])) ?> · <?= whatsapp_groups_h($actionTypes[(string)$a['action_type']] ?? (string)$a['action_type']) ?> · <span class="wg-chip <?= (string)$a['status']==='sent'?'ok':((string)$a['status']==='error'?'err':'warn') ?>"><?= whatsapp_groups_h(whatsapp_groups_status_label((string)$a['status'])) ?></span></div>
                    </div>
                    <div class="wg-actions" style="justify-content:flex-end">
                        <form method="post"><input type="hidden" name="action" value="run_action_now"><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-xs">Rodar</button></form>
                        <form method="post"><input type="hidden" name="action" value="clone_action"><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-xs">Clonar</button></form>
                        <form method="post"><input type="hidden" name="action" value="cancel_action"><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-xs">Cancelar</button></form>
                        <form method="post" onsubmit="return confirm('Deletar esta mensagem?')"><input type="hidden" name="action" value="delete_action"><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-xs" style="color:var(--danger)">Deletar</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if(!$campaignMessages): ?><div class="wg-soft">Nenhuma mensagem programada para esta campanha.</div><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</section>

<section id="grupos" class="wg-section wg-card">
    <div class="wg-title">Grupos e links</div>
    <div class="wg-sub">Vincule grupos detectados/importados a campanhas, sincronize grupos e crie grupos pela Evolution quando a instancia tiver permissao.</div>
    <div class="wg-grid">
        <form method="post" class="wg-card" style="margin:0"><input type="hidden" name="action" value="add_campaign_group">
            <div class="wg-title" style="font-size:14px">Adicionar grupo a campanha</div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Campanha</label><select name="campaign_id"><?php foreach($campaigns as $c): ?><option value="<?= (int)$c['id'] ?>"><?= whatsapp_groups_h((string)$c['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Grupo detectado</label><select name="group_id"><option value="">Selecione ou digite abaixo</option><?php foreach($groups as $g): ?><option value="<?= whatsapp_groups_h((string)$g['group_id']) ?>"><?= whatsapp_groups_h((string)($g['group_name'] ?: $g['group_id'])) ?></option><?php endforeach; ?></select></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Nome manual</label><input name="group_name"></div><div class="form-group"><label class="form-label">Link de convite</label><input name="invite_url" placeholder="https://chat.whatsapp.com/..."></div></div>
            <div class="wg-row"><div class="form-group"><label class="form-label">Origem</label><select name="source"><option value="detected">Detectado</option><option value="imported">Importado</option><option value="created">Criado pelo sistema</option></select></div><div class="form-group"><label class="form-label">Max. membros</label><input type="number" name="max_members" value="0"></div></div>
            <label class="form-label"><input type="checkbox" name="is_current"> Grupo atual de entrada</label>
            <button class="btn btn-primary">Adicionar grupo</button>
        </form>
        <div class="wg-card" style="margin:0">
            <div class="wg-title" style="font-size:14px">Sincronizar / criar</div>
            <form method="post" class="wg-actions"><input type="hidden" name="action" value="sync_groups"><select name="instance_key" style="max-width:260px"><option value="">Instancia</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select><button class="btn btn-ghost btn-sm">Sincronizar grupos</button></form>
            <details style="margin-top:12px"><summary style="cursor:pointer;color:var(--primary)">Criar grupo pela Evolution</summary>
                <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="create_remote_group">
                    <div class="form-group"><label class="form-label">Instancia criadora</label><select name="instance_key"><option value="">Auto administrador</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label class="form-label">Titulo</label><input name="subject" maxlength="25"></div>
                    <div class="form-group"><label class="form-label">Descricao</label><textarea name="description" rows="2"></textarea></div>
                    <div class="form-group"><label class="form-label">Participantes, separados por virgula ou linha</label><textarea name="participants" rows="3"></textarea></div>
                    <label class="form-label"><input type="checkbox" name="promote_participants"> Promover participantes a admin</label>
                    <button class="btn btn-primary btn-sm">Criar grupo</button>
                </form>
            </details>
        </div>
    </div>
    <div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Campanha</th><th>Grupo</th><th>Link</th><th>Lotacao</th><th>Status</th><th>Acoes</th></tr></thead><tbody>
        <?php foreach($campaignGroups as $g): ?><tr><td><?= whatsapp_groups_h((string)$g['campaign_name']) ?></td><td><?= whatsapp_groups_h((string)($g['group_name'] ?: $g['group_id'])) ?></td><td class="truncate" style="max-width:260px"><?= whatsapp_groups_h((string)$g['invite_url']) ?></td><td><?= (int)$g['current_members'] ?> / <?= (int)$g['max_members'] ?></td><td><span class="wg-chip <?= (int)$g['is_active']===1?'ok':'err' ?>"><?= (int)$g['is_active']===1?'Ativo':'Inativo' ?></span></td><td><form method="post"><input type="hidden" name="action" value="toggle_campaign_group"><input type="hidden" name="campaign_group_id" value="<?= (int)$g['id'] ?>"><button class="btn btn-ghost btn-xs"><?= (int)$g['is_active']===1?'Desativar':'Ativar' ?></button></form></td></tr><?php endforeach; ?>
        <?php if(!$campaignGroups): ?><tr><td colspan="6">Nenhum grupo vinculado.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section id="programacoes" class="wg-section wg-card">
    <div class="wg-title">Acoes e mensagens programadas</div>
    <div class="wg-sub">Texto, midia, audio, documento, video, localizacao, contato, reacao, enquete e acoes administrativas do grupo.</div>
    <form method="post" class="wg-card"><input type="hidden" name="action" value="create_scheduled_action">
        <div class="wg-row"><div class="form-group"><label class="form-label">Campanha</label><select name="campaign_id"><option value="">Sem campanha</option><?php foreach($campaigns as $c): ?><option value="<?= (int)$c['id'] ?>"><?= whatsapp_groups_h((string)$c['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Grupo especifico</label><select name="group_id"><option value="">Usar grupo atual da campanha</option><?php foreach($groups as $g): ?><option value="<?= whatsapp_groups_h((string)$g['group_id']) ?>"><?= whatsapp_groups_h((string)($g['group_name'] ?: $g['group_id'])) ?></option><?php endforeach; ?></select></div></div>
        <div class="wg-row"><div class="form-group"><label class="form-label">Titulo interno</label><input name="title" placeholder="Aviso de abertura"></div><div class="form-group"><label class="form-label">Tipo</label><select name="action_type" id="wgActionType"><?php foreach($actionTypes as $v=>$l): ?><option value="<?= $v ?>"><?= whatsapp_groups_h($l) ?></option><?php endforeach; ?></select></div></div>
        <div class="wg-row"><div class="form-group"><label class="form-label">Data/hora</label><input type="datetime-local" name="scheduled_at" required value="<?= date('Y-m-d\TH:i') ?>"></div><div class="form-group"><label class="form-label">Instancia opcional</label><select name="instance_key"><option value="">Auto</option><?php foreach($instances as $i): ?><option value="<?= whatsapp_groups_h((string)$i['instance_key']) ?>"><?= whatsapp_groups_h((string)$i['name']) ?></option><?php endforeach; ?></select></div></div>
        <div class="wg-payload-grid">
            <div class="form-group"><label class="form-label">Texto / mensagem</label><textarea name="text" rows="5" placeholder="Mensagem do grupo"></textarea></div>
            <div class="form-group"><label class="form-label">URL da midia/audio</label><input name="media_url" placeholder="https://..."><div class="wg-help">Imagem, audio, documento, video ou foto do grupo.</div><label class="form-label" style="margin-top:8px">Tipo de midia</label><select name="media_type"><option value="image">Imagem</option><option value="video">Video</option><option value="document">Documento</option></select></div>
            <div class="form-group"><label class="form-label">Legenda / arquivo</label><input name="caption" placeholder="Legenda"><input name="file_name" style="margin-top:8px" placeholder="arquivo.pdf"></div>
            <div class="form-group"><label class="form-label">Enquete</label><input name="poll_name" placeholder="Pergunta"><textarea name="poll_options" rows="3" placeholder="Opcao 1&#10;Opcao 2"></textarea><input type="number" name="selectable_count" value="1" min="1"></div>
            <div class="form-group"><label class="form-label">Localizacao</label><input name="location_name" placeholder="Nome"><input name="location_address" style="margin-top:8px" placeholder="Endereco"><div class="wg-row" style="margin-top:8px"><input name="latitude" placeholder="Latitude"><input name="longitude" placeholder="Longitude"></div></div>
            <div class="form-group"><label class="form-label">Contato / reacao / grupo</label><input name="contact_name" placeholder="Nome contato"><input name="contact_phone" style="margin-top:8px" placeholder="Telefone contato"><input name="message_id" style="margin-top:8px" placeholder="ID msg para reacao"><input name="reaction" style="margin-top:8px" placeholder="Reacao"><input name="subject" style="margin-top:8px" placeholder="Novo titulo"><textarea name="group_description" rows="2" placeholder="Nova descricao"></textarea></div>
        </div>
        <div class="wg-row"><div class="form-group"><label class="form-label">Recorrencia</label><select name="recurrence"><option value="once">Unica</option><option value="daily">Diaria</option><option value="weekly">Semanal</option><option value="monthly">Mensal</option></select></div><div class="form-group"><label class="form-label">Intervalo / tentativas</label><div class="wg-row"><input type="number" name="recurrence_interval" value="1" min="1"><input type="number" name="max_attempts" value="3" min="1"></div></div></div>
        <label class="form-label"><input type="checkbox" name="mentions_everyone"> Mencionar todos quando enviar texto</label>
        <label class="form-label"><input type="checkbox" name="link_preview" checked> Ativar preview de link</label>
        <button class="btn btn-primary">Programar acao</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Quando</th><th>Acao</th><th>Campanha/grupo</th><th>Status</th><th>Tentativas</th><th>Acoes</th></tr></thead><tbody>
        <?php foreach($actions as $a): ?><tr><td><?= date('d/m/Y H:i', strtotime((string)$a['scheduled_at'])) ?></td><td><strong><?= whatsapp_groups_h((string)$a['title']) ?></strong><div class="text-xs text-muted"><?= whatsapp_groups_h($actionTypes[(string)$a['action_type']] ?? (string)$a['action_type']) ?></div></td><td><?= whatsapp_groups_h((string)($a['campaign_name'] ?: '-')) ?><div class="text-xs text-muted"><?= whatsapp_groups_h((string)($a['resolved_group_name'] ?: 'Grupo da campanha')) ?></div></td><td><span class="wg-chip <?= (string)$a['status']==='sent'?'ok':((string)$a['status']==='error'?'err':'warn') ?>"><?= whatsapp_groups_h(whatsapp_groups_status_label((string)$a['status'])) ?></span></td><td><?= (int)$a['attempts'] ?>/<?= (int)$a['max_attempts'] ?></td><td><div class="wg-actions"><form method="post"><input type="hidden" name="action" value="run_action_now"><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-xs">Rodar</button></form><form method="post"><input type="hidden" name="action" value="cancel_action"><input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-xs" style="color:var(--danger)">Cancelar</button></form></div></td></tr><?php endforeach; ?>
        <?php if(!$actions): ?><tr><td colspan="6">Nenhuma acao programada.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section id="palavras" class="wg-section wg-card">
    <div class="wg-title">Palavras-chave</div>
    <div class="wg-sub">Regras para transformar mensagens recebidas em gatilhos do sistema. O webhook ja captura mensagens, esta tabela centraliza as regras para processamento.</div>
    <form method="post" class="wg-card"><input type="hidden" name="action" value="save_keyword">
        <div class="wg-row"><div class="form-group"><label class="form-label">Nome</label><input name="name" placeholder="Pediu suporte"></div><div class="form-group"><label class="form-label">Palavra</label><input name="keyword" placeholder="suporte"></div></div>
        <div class="wg-row"><div class="form-group"><label class="form-label">Campanha</label><select name="campaign_id"><option value="">Todas</option><?php foreach($campaigns as $c): ?><option value="<?= (int)$c['id'] ?>"><?= whatsapp_groups_h((string)$c['name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Grupo</label><select name="group_id"><option value="">Todos</option><?php foreach($groups as $g): ?><option value="<?= whatsapp_groups_h((string)$g['group_id']) ?>"><?= whatsapp_groups_h((string)($g['group_name'] ?: $g['group_id'])) ?></option><?php endforeach; ?></select></div></div>
        <div class="wg-row"><div class="form-group"><label class="form-label">Modo</label><select name="match_mode"><option value="contains">Contem</option><option value="equals">Igual</option><option value="starts">Comeca com</option></select></div><div class="form-group"><label class="form-label">Evento disparado</label><input name="trigger_event" value="WHATSAPP_GRUPO_PALAVRA_CHAVE"></div></div>
        <label class="form-label"><input type="checkbox" name="is_active" checked> Regra ativa</label>
        <button class="btn btn-primary">Salvar palavra-chave</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Regra</th><th>Palavra</th><th>Escopo</th><th>Evento</th><th>Status</th></tr></thead><tbody>
        <?php foreach($keywords as $k): ?><tr><td><?= whatsapp_groups_h((string)$k['name']) ?></td><td><?= whatsapp_groups_h((string)$k['keyword']) ?></td><td><?= whatsapp_groups_h((string)($k['campaign_name'] ?: 'Todas')) ?><div class="text-xs text-muted"><?= whatsapp_groups_h((string)($k['resolved_group_name'] ?: 'Todos os grupos')) ?></div></td><td><?= whatsapp_groups_h((string)$k['trigger_event']) ?></td><td><span class="wg-chip <?= (int)$k['is_active']===1?'ok':'err' ?>"><?= (int)$k['is_active']===1?'Ativa':'Inativa' ?></span></td></tr><?php endforeach; ?>
        <?php if(!$keywords): ?><tr><td colspan="5">Nenhuma regra criada.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section id="logs" class="wg-section wg-card">
    <div class="wg-title">Logs de envios</div>
    <div class="wg-sub">Historico auditavel dos envios e acoes administrativas executadas pelo worker ou manualmente.</div>
    <div class="table-wrap"><table><thead><tr><th>Data</th><th>Status</th><th>Acao</th><th>Campanha/grupo</th><th>Instancia</th><th>Erro/resposta</th></tr></thead><tbody>
        <?php foreach($logs as $l): ?><tr><td><?= date('d/m/Y H:i:s', strtotime((string)$l['created_at'])) ?></td><td><span class="wg-chip <?= (string)$l['status']==='sent'?'ok':'err' ?>"><?= whatsapp_groups_h((string)$l['status']) ?></span></td><td><?= whatsapp_groups_h($actionTypes[(string)$l['action_type']] ?? (string)$l['action_type']) ?></td><td><?= whatsapp_groups_h((string)($l['campaign_name'] ?: '-')) ?><div class="text-xs text-muted"><?= whatsapp_groups_h((string)($l['resolved_group_name'] ?: $l['group_id'])) ?></div></td><td><?= whatsapp_groups_h((string)$l['instance_key']) ?></td><td class="truncate" style="max-width:360px"><?= whatsapp_groups_h((string)($l['error_message'] ?: $l['response_body'])) ?></td></tr><?php endforeach; ?>
        <?php if(!$logs): ?><tr><td colspan="6">Nenhum log de envio.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section id="saude" class="wg-section wg-card">
    <div class="wg-title">Saude e seguranca operacional</div>
    <div class="wg-sub">Diagnostico de conexao, ultimos erros e orientacoes para operar sem rajadas agressivas.</div>
    <div class="wg-grid">
        <div class="wg-soft">
            <strong>Boas praticas configuradas no modulo</strong><br>
            Fila com status, cooldown por campanha, limite por minuto/hora salvo na campanha, logs completos, selecao apenas de numeros conectados e funcao stand-by para continuidade operacional.
        </div>
        <div class="wg-soft">
            <strong>Pontos de atencao</strong><br>
            Use grupos com consentimento, evite trocar de numero sem necessidade, nao envie rajadas para grupos frios, mantenha poucos envios por minuto e monitore desconexoes antes de programar campanhas grandes.
        </div>
    </div>
    <div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Data</th><th>Instancia</th><th>Acao</th><th>Antes</th><th>Depois</th><th>Erro</th></tr></thead><tbody>
        <?php foreach($connectionLogs as $l): ?><tr><td><?= date('d/m/Y H:i:s', strtotime((string)$l['created_at'])) ?></td><td><?= whatsapp_groups_h((string)$l['instance_key']) ?></td><td><?= whatsapp_groups_h((string)$l['action']) ?></td><td><?= whatsapp_groups_h((string)$l['status_before']) ?></td><td><?= whatsapp_groups_h((string)$l['status_after']) ?></td><td class="truncate" style="max-width:320px"><?= whatsapp_groups_h((string)$l['error_message']) ?></td></tr><?php endforeach; ?>
        <?php if(!$connectionLogs): ?><tr><td colspan="6">Nenhum log de conexao.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<script>
(function(){
    const tabButtons = Array.from(document.querySelectorAll('.wg-tab'));
    const sections = Array.from(document.querySelectorAll('.wg-section'));
    const charts = [];
    function activateTab(tab, updateHash) {
        if (!tab || !document.getElementById(tab)) tab = 'visao';
        tabButtons.forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === tab));
        sections.forEach(section => section.classList.toggle('active', section.id === tab));
        if (updateHash) {
            const url = new URL(location.href);
            url.searchParams.set('section', tab);
            if (tab !== 'mensagens') url.searchParams.delete('campaign_id');
            url.hash = '';
            history.replaceState(null, '', url.toString());
        }
        charts.forEach(chart => { if (chart && typeof chart.resize === 'function') chart.resize(); });
    }
    tabButtons.forEach(btn => btn.addEventListener('click', function(){
        activateTab(btn.getAttribute('data-tab') || 'visao', true);
    }));
    window.addEventListener('hashchange', function(){ activateTab((location.hash || '').replace('#', ''), false); });

    const statusCounts = <?= json_encode(array_count_values(array_map(fn($a)=>(string)$a['status'], $actions)), JSON_UNESCAPED_UNICODE) ?>;
    const typeCounts = <?= json_encode(array_count_values(array_map(fn($a)=>(string)$a['action_type'], $actions)), JSON_UNESCAPED_UNICODE) ?>;
    if (window.Chart) {
        charts.push(new Chart(document.getElementById('wgStatusChart'), {type:'doughnut',data:{labels:Object.keys(statusCounts),datasets:[{data:Object.values(statusCounts),backgroundColor:['#facc15','#22c55e','#ef4444','#38bdf8','#64748b']}]},options:{plugins:{legend:{labels:{color:'#94a3b8'}}}}}));
        charts.push(new Chart(document.getElementById('wgTypeChart'), {type:'bar',data:{labels:Object.keys(typeCounts),datasets:[{data:Object.values(typeCounts),backgroundColor:'#38bdf8'}]},options:{scales:{x:{ticks:{color:'#94a3b8'},grid:{color:'rgba(255,255,255,.06)'}},y:{ticks:{color:'#94a3b8'},grid:{color:'rgba(255,255,255,.06)'}}},plugins:{legend:{display:false}}}}));
    }
    activateTab((location.hash || '').replace('#', '') || <?= json_encode($selectedSection, JSON_UNESCAPED_UNICODE) ?>, false);
    document.querySelectorAll('[data-instance-id]').forEach(function(card){
        const state = card.querySelector('[data-state]');
        if (!state || !/CONNECTING|DISCONNECTED|ERROR/.test(state.textContent)) return;
        const id = card.getAttribute('data-instance-id');
        let runs = 0;
        const timer = setInterval(function(){
            runs++;
            fetch('whatsapp_grupos.php?ajax=instance_state&id='+encodeURIComponent(id), {credentials:'same-origin'})
                .then(r=>r.json()).then(data=>{
                    if (!data || !data.status) return;
                    state.textContent = data.status;
                    state.classList.toggle('ok', data.status === 'CONNECTED');
                    state.classList.toggle('warn', data.status === 'CONNECTING' || data.status === 'DISCONNECTED');
                    state.classList.toggle('err', data.status === 'ERROR');
                    const pairing = card.querySelector('[data-pairing]');
                    if (pairing && data.pairing_code) pairing.textContent = data.pairing_code;
                    const qr = card.querySelector('[data-qr]');
                    if (qr && data.qr_base64) {
                        qr.src = data.qr_base64.indexOf('data:image') === 0 ? data.qr_base64 : 'data:image/png;base64,' + data.qr_base64;
                        qr.style.display = '';
                    }
                    if (data.status === 'CONNECTED' || runs >= 20) clearInterval(timer);
                }).catch(()=>{ if (runs >= 5) clearInterval(timer); });
        }, 4000);
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
