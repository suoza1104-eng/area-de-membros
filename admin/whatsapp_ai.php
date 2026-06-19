<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/whatsapp_ai.php';

proteger_admin();
$pdo = getPDO();
evolution_ensure_tables($pdo);
whatsapp_ai_ensure_tables($pdo);
whatsapp_event_notifications_ensure_tables($pdo);

$menu = 'whatsapp_ai';
$page_title = 'IA WhatsApp';

function wai_h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wai_dt(?string $v): string {
    if (!$v) return '-';
    try { return (new DateTime($v))->format('d/m/Y H:i:s'); } catch (Throwable $e) { return $v; }
}

function wai_badge(string $text, string $kind = 'neutral'): string {
    $map = [
        'success' => 'background:rgba(34,197,94,.12);color:#86efac;border-color:rgba(34,197,94,.25)',
        'warn' => 'background:rgba(250,204,21,.12);color:#fde68a;border-color:rgba(250,204,21,.25)',
        'danger' => 'background:rgba(239,68,68,.12);color:#fca5a5;border-color:rgba(239,68,68,.25)',
        'info' => 'background:rgba(59,130,246,.12);color:#93c5fd;border-color:rgba(59,130,246,.25)',
        'neutral' => 'background:rgba(148,163,184,.10);color:#cbd5e1;border-color:rgba(148,163,184,.20)',
    ];
    return '<span style="display:inline-flex;align-items:center;padding:3px 8px;border:1px solid;border-radius:999px;font-size:11px;font-weight:700;' . $map[$kind] . '">' . wai_h($text) . '</span>';
}

function wai_actor(): string {
    $name = trim((string)($_SESSION['equipe_nome'] ?? 'Administrador'));
    $email = trim((string)($_SESSION['equipe_email'] ?? ''));
    return $email !== '' ? $name . ' <' . $email . '>' : $name;
}

function wai_action_label(string $type): string {
    $labels = [
        'send_group_message' => 'Enviar mensagem no grupo',
        'apply_tag' => 'Aplicar tag',
        'trigger_webhook' => 'Disparar webhook',
        'internal_alert' => 'Alerta interno',
    ];
    return $labels[$type] ?? $type;
}

$notice = '';
$error = '';
$runResult = null;

$generatedCronToken = '';
try {
    if (trim((string)get_setting('cron_whatsapp_ai_token', '')) === '') {
        $generatedCronToken = bin2hex(random_bytes(24));
        set_setting('cron_whatsapp_ai_token', $generatedCronToken);
    }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_config') {
            $current = whatsapp_ai_get_config();
            $postedKey = trim((string)($_POST['openai_api_key'] ?? ''));
            $clearKey = !empty($_POST['clear_openai_api_key']);
            whatsapp_ai_set_config([
                'enabled' => !empty($_POST['enabled']),
                'openai_api_key' => $clearKey ? '' : ($postedKey !== '' ? $postedKey : (string)$current['openai_api_key']),
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
            ]);
            header('Location: whatsapp_ai.php?saved=1');
            exit;
        }

        if ($action === 'save_blacklist_config') {
            evolution_blacklist_set_config([
                'auto_remove' => !empty($_POST['blacklist_auto_remove']),
                'notify_enabled' => !empty($_POST['blacklist_notify_enabled']),
                'recipient_ids' => $_POST['blacklist_recipient_ids'] ?? [],
                'message_template' => $_POST['blacklist_message_template'] ?? '',
            ]);
            header('Location: whatsapp_ai.php?blacklist_saved=1');
            exit;
        }

        if ($action === 'save_event_notification_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $name = trim((string)($_POST['rule_name'] ?? ''));
            $eventCode = strtoupper(trim((string)($_POST['rule_event_code'] ?? '')));
            $instanceKey = trim((string)($_POST['rule_instance_key'] ?? ''));
            $messageTemplate = trim((string)($_POST['rule_message_template'] ?? ''));
            $groupIds = $_POST['rule_group_ids'] ?? [];
            $teamIds = $_POST['rule_team_ids'] ?? [];
            if (!is_array($groupIds)) $groupIds = [];
            if (!is_array($teamIds)) $teamIds = [];
            $groupIds = array_values(array_unique(array_filter(array_map('trim', $groupIds))));
            $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
            if ($name === '' || $eventCode === '' || $messageTemplate === '') {
                throw new RuntimeException('Informe nome, evento e mensagem da regra.');
            }
            if (!$groupIds && !$teamIds) {
                throw new RuntimeException('Selecione pelo menos um grupo ou membro da equipe.');
            }

            $pdo->beginTransaction();
            try {
                if ($ruleId > 0) {
                    $pdo->prepare("
                        UPDATE whatsapp_event_notification_rules
                           SET name = :name, event_code = :event_code, instance_key = :instance_key,
                               message_template = :message_template, is_active = :is_active
                         WHERE id = :id
                         LIMIT 1
                    ")->execute([
                        ':name' => $name,
                        ':event_code' => $eventCode,
                        ':instance_key' => $instanceKey ?: null,
                        ':message_template' => $messageTemplate,
                        ':is_active' => !empty($_POST['rule_is_active']) ? 1 : 0,
                        ':id' => $ruleId,
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO whatsapp_event_notification_rules
                            (name, event_code, instance_key, message_template, is_active, created_at, updated_at)
                        VALUES (:name, :event_code, :instance_key, :message_template, :is_active, NOW(), NOW())
                    ")->execute([
                        ':name' => $name,
                        ':event_code' => $eventCode,
                        ':instance_key' => $instanceKey ?: null,
                        ':message_template' => $messageTemplate,
                        ':is_active' => !empty($_POST['rule_is_active']) ? 1 : 0,
                    ]);
                    $ruleId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_groups WHERE rule_id = :id")->execute([':id' => $ruleId]);
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_team WHERE rule_id = :id")->execute([':id' => $ruleId]);
                $insGroup = $pdo->prepare("INSERT INTO whatsapp_event_notification_rule_groups (rule_id, group_id) VALUES (:rid, :gid)");
                foreach ($groupIds as $groupId) $insGroup->execute([':rid' => $ruleId, ':gid' => $groupId]);
                $insTeam = $pdo->prepare("INSERT INTO whatsapp_event_notification_rule_team (rule_id, admin_equipe_id) VALUES (:rid, :tid)");
                foreach ($teamIds as $teamId) $insTeam->execute([':rid' => $ruleId, ':tid' => $teamId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            header('Location: whatsapp_ai.php?event_rule_saved=1');
            exit;
        }

        if ($action === 'toggle_event_notification_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $pdo->prepare("UPDATE whatsapp_event_notification_rules SET is_active = IF(is_active=1,0,1) WHERE id = :id LIMIT 1")
                ->execute([':id' => $ruleId]);
            header('Location: whatsapp_ai.php?event_rule_saved=1');
            exit;
        }

        if ($action === 'delete_event_notification_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_groups WHERE rule_id = :id")->execute([':id' => $ruleId]);
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rule_team WHERE rule_id = :id")->execute([':id' => $ruleId]);
                $pdo->prepare("DELETE FROM whatsapp_event_notification_rules WHERE id = :id")->execute([':id' => $ruleId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            header('Location: whatsapp_ai.php?event_rule_deleted=1');
            exit;
        }

        if ($action === 'toggle_group_ignore') {
            $gid = trim((string)($_POST['group_id'] ?? ''));
            if ($gid === '') throw new RuntimeException('Grupo invalido.');
            $pdo->prepare("UPDATE whatsapp_groups SET is_ignored = IF(is_ignored=1,0,1), last_seen_at = NOW() WHERE group_id = :gid LIMIT 1")
                ->execute([':gid' => $gid]);
            header('Location: whatsapp_ai.php?groups=1');
            exit;
        }

        if ($action === 'rotate_token') {
            set_setting('cron_whatsapp_ai_token', bin2hex(random_bytes(24)));
            header('Location: whatsapp_ai.php?token=1');
            exit;
        }

        if ($action === 'run_now') {
            $runResult = whatsapp_ai_process_due($pdo, 10);
            $notice = 'Processamento executado: ' . (int)$runResult['batches_created'] . ' pacote(s), ' . (int)$runResult['messages_processed'] . ' mensagem(ns).';
            if (!empty($runResult['skipped'])) $notice = 'Processamento ignorado: IA desligada ou fora do horario configurado.';
            if (!empty($runResult['error'])) $error = (string)$runResult['error'];
        }

        if ($action === 'approve_action') {
            $actionId = (int)($_POST['action_id'] ?? 0);
            $messageOverride = array_key_exists('message_text', $_POST) ? (string)$_POST['message_text'] : null;
            whatsapp_ai_approve_action($pdo, $actionId, wai_actor(), $messageOverride);
            header('Location: whatsapp_ai.php?action_done=1');
            exit;
        }

        if ($action === 'ignore_action') {
            $actionId = (int)($_POST['action_id'] ?? 0);
            whatsapp_ai_ignore_action($pdo, $actionId, wai_actor());
            header('Location: whatsapp_ai.php?action_ignored=1');
            exit;
        }

        if ($action === 'resolve_batch') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            whatsapp_ai_resolve_batch($pdo, $batchId, wai_actor());
            header('Location: whatsapp_ai.php?batch_resolved=1');
            exit;
        }

        if ($action === 'requeue_batch') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $count = whatsapp_ai_requeue_batch($pdo, $batchId, wai_actor());
            header('Location: whatsapp_ai.php?batch_requeued=' . (int)$count);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved'])) $notice = 'Configuracao da IA salva.';
if (isset($_GET['blacklist_saved'])) $notice = 'Automacao da blacklist salva.';
if (isset($_GET['event_rule_saved'])) $notice = 'Regra de notificacao por evento salva.';
if (isset($_GET['event_rule_deleted'])) $notice = 'Regra de notificacao removida.';
if (isset($_GET['groups'])) $notice = 'Configuracao do grupo atualizada.';
if (isset($_GET['token'])) $notice = 'Token do cron atualizado.';
if (isset($_GET['action_done'])) $notice = 'Acao aprovada e executada.';
if (isset($_GET['action_ignored'])) $notice = 'Acao ignorada.';
if (isset($_GET['batch_resolved'])) $notice = 'Pacote marcado como resolvido.';
if (isset($_GET['batch_requeued'])) $notice = 'Pacote reaberto: ' . (int)$_GET['batch_requeued'] . ' mensagem(ns) voltaram para a fila. Clique em Processar agora para analisar novamente.';

$cfg = whatsapp_ai_get_config();
$blacklistCfg = evolution_blacklist_get_config();
$cronToken = $generatedCronToken !== '' ? $generatedCronToken : trim((string)get_setting('cron_whatsapp_ai_token', ''));
$cronUrl = rtrim(BASE_URL, '/') . '/cron_whatsapp_ai.php?token=' . $cronToken;

$stats = [
    'pending' => 0,
    'batches' => 0,
    'interventions' => 0,
    'contexts' => 0,
    'actions_pending' => 0,
    'media' => 0,
];
try { $stats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_messages WHERE processed_batch_id IS NULL")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['batches'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_batches")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['interventions'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_batches WHERE needs_intervention = 1")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['contexts'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_contexts")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['actions_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_actions WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
try { $stats['media'] = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_ai_messages WHERE media_kind IS NOT NULL AND media_kind <> ''")->fetchColumn(); } catch (Throwable $e) {}

$aiSeverityChart = ['labels' => ['Leve', 'Médio', 'Crítico'], 'data' => [0, 0, 0]];
$aiCategoryChart = ['labels' => [], 'data' => []];
try {
    $severityRows = $pdo->query("
        SELECT LOWER(COALESCE(severity,'')) AS severity, COUNT(*) AS total
          FROM whatsapp_ai_batches
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND needs_intervention = 1
         GROUP BY LOWER(COALESCE(severity,''))
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($severityRows as $row) {
        $severity = whatsapp_ai_normalize_severity((string)$row['severity']);
        $index = $severity === 'CRITICO' ? 2 : ($severity === 'MEDIO' ? 1 : 0);
        $aiSeverityChart['data'][$index] += (int)$row['total'];
    }
    $categoryRows = $pdo->query("
        SELECT COALESCE(NULLIF(category,''),'sem categoria') AS category, COUNT(*) AS total
          FROM whatsapp_ai_batches
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY COALESCE(NULLIF(category,''),'sem categoria')
         ORDER BY total DESC
         LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($categoryRows as $row) {
        $aiCategoryChart['labels'][] = (string)$row['category'];
        $aiCategoryChart['data'][] = (int)$row['total'];
    }
} catch (Throwable $e) {}

$groups = [];
try {
    $groups = $pdo->query("
        SELECT g.*,
               (SELECT COUNT(*) FROM whatsapp_ai_messages m WHERE m.group_id = g.group_id) AS ai_messages,
               (SELECT COUNT(*) FROM whatsapp_ai_messages m WHERE m.group_id = g.group_id AND m.processed_batch_id IS NULL) AS pending_messages,
               (SELECT MAX(message_at) FROM whatsapp_ai_messages m WHERE m.group_id = g.group_id) AS last_message_at
          FROM whatsapp_groups g
         ORDER BY g.is_ignored ASC, COALESCE(g.group_name, g.group_id) ASC
         LIMIT 120
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$teamRecipients = [];
try {
    $teamRecipients = $pdo->query("
        SELECT id, nome, email, whatsapp_number, whatsapp_blacklist_exempt, ativo
          FROM admin_equipe
         WHERE ativo = 1
         ORDER BY nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$blacklistActions = [];
try {
    $blacklistActions = $pdo->query("
        SELECT ba.*, g.group_name, u.nome AS user_name
          FROM whatsapp_blacklist_actions ba
          LEFT JOIN whatsapp_groups g ON g.group_id = ba.group_id
          LEFT JOIN users u ON u.id = ba.user_id
         ORDER BY ba.created_at DESC, ba.id DESC
         LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$notificationInstances = [];
try {
    $notificationInstances = $pdo->query("SELECT instance_key, name, status FROM whatsapp_instances ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$notificationGroups = [];
try {
    $notificationGroups = $pdo->query("
        SELECT group_id, group_name, instance_key, is_ignored
          FROM whatsapp_groups
         ORDER BY COALESCE(group_name, group_id) ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$eventNotificationRules = [];
$eventNotificationRuleGroups = [];
$eventNotificationRuleTeam = [];
try {
    $eventNotificationRules = $pdo->query("SELECT * FROM whatsapp_event_notification_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($pdo->query("SELECT rule_id, group_id FROM whatsapp_event_notification_rule_groups")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $eventNotificationRuleGroups[(int)$row['rule_id']][] = (string)$row['group_id'];
    }
    foreach ($pdo->query("SELECT rule_id, admin_equipe_id FROM whatsapp_event_notification_rule_team")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $eventNotificationRuleTeam[(int)$row['rule_id']][] = (int)$row['admin_equipe_id'];
    }
} catch (Throwable $e) {}

$notificationEventCodes = [
    'INSCRITO', 'REINSCRITO', 'PRIMEIRO_LOGIN', 'ASSISTIU_ALGUMA_AULA', 'CONCLUIU_TRILHA',
    'RETORNO_AGENDADO', 'CERT_EMITIDO', 'REENVIO_CERTIFICADO', 'CERT_SENHA_ERRADA',
    'LIVE_TURMA', 'LIVE_REAGENDADA', 'LIVE_REAGENDAMENTO_LEMBRETE', 'LIVE_REAGENDAMENTO_EXPIRADO',
    'LIVE_ACESSOU', 'LIVE_OFERTA', 'LIVE_COMPRA', 'LIVE_EVENTO',
    'WHATSAPP_GRUPO_ENTROU', 'WHATSAPP_GRUPO_SAIU', 'WHATSAPP_GRUPO_REMOVIDO_ADMIN',
    'WHATSAPP_BLACKLIST_DETECTADO',
];
foreach (['webhooks', 'superfuncionario_rules', 'manychat_rules'] as $table) {
    try {
        foreach ($pdo->query("SELECT DISTINCT evento FROM {$table} WHERE evento IS NOT NULL AND evento <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventCode) {
            $notificationEventCodes[] = strtoupper(trim((string)$eventCode));
        }
    } catch (Throwable $e) {}
}
foreach ($eventNotificationRules as $rule) $notificationEventCodes[] = strtoupper((string)$rule['event_code']);
$notificationEventCodes = array_values(array_unique(array_filter($notificationEventCodes)));
sort($notificationEventCodes);

$eventNotificationLogs = [];
try {
    $eventNotificationLogs = $pdo->query("
        SELECT l.*, r.name AS rule_name
          FROM whatsapp_event_notification_logs l
          LEFT JOIN whatsapp_event_notification_rules r ON r.id = l.rule_id
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$batches = [];
try {
    $batches = $pdo->query("
        SELECT b.*, g.group_name
          FROM whatsapp_ai_batches b
          LEFT JOIN whatsapp_groups g ON g.group_id = b.group_id
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$actions = [];
try {
    $actions = $pdo->query("
        SELECT a.*, b.summary, b.category, b.severity, b.suggested_response, b.created_at AS batch_created_at, g.group_name
          FROM whatsapp_ai_actions a
          JOIN whatsapp_ai_batches b ON b.id = a.batch_id
          LEFT JOIN whatsapp_groups g ON g.group_id = a.group_id
         WHERE a.status IN ('pending','error')
         ORDER BY FIELD(a.status, 'pending', 'error'), a.created_at DESC, a.id DESC
         LIMIT 40
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$recentActions = [];
try {
    $recentActions = $pdo->query("
        SELECT a.*, b.category, g.group_name
          FROM whatsapp_ai_actions a
          JOIN whatsapp_ai_batches b ON b.id = a.batch_id
          LEFT JOIN whatsapp_groups g ON g.group_id = a.group_id
         WHERE a.status <> 'pending'
         ORDER BY COALESCE(a.executed_at, a.ignored_at, a.approved_at, a.created_at) DESC, a.id DESC
         LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$actionMessages = [];
if ($actions) {
    $batchIds = array_values(array_unique(array_map(static fn($a) => (int)$a['batch_id'], $actions)));
    $in = implode(',', array_filter($batchIds));
    if ($in !== '') {
        try {
            $rows = $pdo->query("
                SELECT m.*, u.nome AS aluno_nome
                  FROM whatsapp_ai_messages m
                  LEFT JOIN users u ON u.id = m.user_id
                 WHERE m.processed_batch_id IN ($in)
                 ORDER BY m.processed_batch_id ASC, m.message_at ASC, m.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $bid = (int)$row['processed_batch_id'];
                if (!isset($actionMessages[$bid])) $actionMessages[$bid] = [];
                if (count($actionMessages[$bid]) < 8) $actionMessages[$bid][] = $row;
            }
        } catch (Throwable $e) {}
    }
}

$messages = [];
try {
    $messages = $pdo->query("
        SELECT m.*, u.nome AS aluno_nome, g.group_name
          FROM whatsapp_ai_messages m
          LEFT JOIN users u ON u.id = m.user_id
          LEFT JOIN whatsapp_groups g ON g.group_id = m.group_id
         ORDER BY m.message_at DESC, m.id DESC
         LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/_header.php';
?>

<style>
.wai-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.wai-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:14px}
.wai-kpi-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.wai-kpi-value{font-size:24px;color:var(--text);font-weight:800;margin-top:6px}
.wai-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:16px;align-items:start}
.wai-card{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px}
.wai-title{font-size:13px;font-weight:800;color:var(--text);margin-bottom:12px;text-transform:uppercase;letter-spacing:.06em}
.wai-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.wai-help{font-size:11px;color:var(--muted);margin-top:5px;line-height:1.45}
.wai-table{width:100%;border-collapse:collapse}
.wai-table th,.wai-table td{padding:9px 8px;border-bottom:1px solid var(--border);font-size:12px;text-align:left;vertical-align:top}
.wai-table th{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.wai-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:#93c5fd;word-break:break-all}
.wai-msg{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted)}
.wai-review{border:1px solid var(--border);background:rgba(255,255,255,.025);border-radius:8px;padding:12px;margin-bottom:12px}
.wai-review-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.wai-review-title{font-size:13px;font-weight:800;color:var(--text)}
.wai-review-meta{font-size:11px;color:var(--muted);margin-top:3px}
.wai-message-list{background:rgba(0,0,0,.16);border:1px solid var(--border);border-radius:8px;padding:8px;margin:10px 0}
.wai-message-line{font-size:11px;color:var(--muted);line-height:1.45;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.wai-message-line:last-child{border-bottom:none}
.wai-actions{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-top:10px}
.wai-destination-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.wai-check-list{display:grid;gap:6px;border:1px solid var(--border);border-radius:8px;padding:9px;max-height:190px;overflow:auto}
.wai-check-item{display:flex;align-items:flex-start;gap:7px;font-size:11px;color:var(--text)}
.wai-rule-card{border:1px solid var(--border);border-radius:8px;padding:11px;margin-top:10px;background:rgba(255,255,255,.02)}
@media(max-width:1100px){.wai-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wai-layout{grid-template-columns:1fr}.wai-row{grid-template-columns:1fr}}
@media(max-width:700px){.wai-destination-grid{grid-template-columns:1fr}}
</style>

<?php if ($notice): ?><div class="alert alert-ok mb-3"><?= wai_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error mb-3"><?= wai_h($error) ?></div><?php endif; ?>

<div class="wai-grid">
    <div class="wai-kpi"><div class="wai-kpi-label">Mensagens pendentes</div><div class="wai-kpi-value"><?= (int)$stats['pending'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Pacotes analisados</div><div class="wai-kpi-value"><?= (int)$stats['batches'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Intervencoes sugeridas</div><div class="wai-kpi-value"><?= (int)$stats['interventions'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Acoes pendentes</div><div class="wai-kpi-value"><?= (int)$stats['actions_pending'] ?></div></div>
    <div class="wai-kpi"><div class="wai-kpi-label">Midias capturadas</div><div class="wai-kpi-value"><?= (int)$stats['media'] ?></div></div>
</div>

<div class="wai-row" style="margin-bottom:16px">
    <div class="wai-card" style="margin:0"><div class="wai-title">Alertas por nível · 30 dias</div><div style="height:220px"><canvas id="waiSeverityChart"></canvas></div></div>
    <div class="wai-card" style="margin:0"><div class="wai-title">Categorias analisadas · 30 dias</div><div style="height:220px"><canvas id="waiCategoryChart"></canvas></div></div>
</div>

<div class="wai-layout">
    <div>
        <div class="wai-card">
            <div class="wai-title">Operação da IA</div>
            <div class="wai-help">Configurações, instâncias, grupos ignorados, blacklist, direct e gatilhos foram centralizados em Configurações WhatsApp.</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <a class="btn btn-primary" href="whatsapp_config.php">Abrir Configurações WhatsApp</a>
                <form method="post"><input type="hidden" name="action" value="run_now"><button class="btn btn-ghost" type="submit">Processar agora</button></form>
            </div>
        </div>

        <?php if (false): ?>
        <div class="wai-card">
            <div class="wai-title">Configuracao da IA</div>
            <form method="post">
                <input type="hidden" name="action" value="save_config">
                <div class="form-group">
                    <label class="form-label"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Ativar analise por IA</label>
                    <div class="wai-help">A IA analisa e cria sugestoes. Mensagens, tags e webhooks so rodam depois de aprovacao manual na fila de revisao.</div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Modelo</label>
                        <input type="text" name="model" value="<?= wai_h((string)$cfg['model']) ?>" placeholder="gpt-4.1-mini">
                    </div>
                    <div class="form-group">
                        <label class="form-label">API key OpenAI</label>
                        <input type="password" name="openai_api_key" value="" placeholder="<?= $cfg['openai_api_key'] !== '' ? 'Chave configurada - deixe vazio para manter' : 'sk-...' ?>">
                        <?php if ($cfg['openai_api_key'] !== ''): ?>
                        <label class="wai-help"><input type="checkbox" name="clear_openai_api_key" value="1"> Remover chave salva</label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Intervalo de empacotamento em minutos</label>
                        <input type="number" min="1" max="120" name="interval_minutes" value="<?= (int)$cfg['interval_minutes'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Maximo de mensagens por pacote</label>
                        <input type="number" min="1" max="300" name="max_messages" value="<?= (int)$cfg['max_messages'] ?>">
                    </div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Horario de ligar</label>
                        <input type="time" name="active_from" value="<?= wai_h((string)$cfg['active_from']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Horario de desligar</label>
                        <input type="time" name="active_to" value="<?= wai_h((string)$cfg['active_to']) ?>">
                    </div>
                </div>

                <div class="wai-row">
                    <div class="form-group">
                        <label class="form-label">Maximo de tokens de saida</label>
                        <input type="number" min="100" max="4000" name="max_tokens" value="<?= (int)$cfg['max_tokens'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contextos anteriores por grupo</label>
                        <input type="number" min="0" max="50" name="context_keep" value="<?= (int)$cfg['context_keep'] ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Temperatura</label>
                    <input type="number" min="0" max="2" step="0.1" name="temperature" value="<?= wai_h((string)$cfg['temperature']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Modelo de transcricao de audio</label>
                    <input type="text" name="transcription_model" value="<?= wai_h((string)$cfg['transcription_model']) ?>" placeholder="gpt-4o-mini-transcribe">
                    <div class="wai-help">Usado apenas quando uma mensagem de audio tiver URL ou base64 acessivel no payload da Evolution.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Prompt orientativo</label>
                    <textarea name="prompt" rows="8"><?= wai_h((string)$cfg['prompt']) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Criterios adicionais</label>
                    <textarea name="criteria" rows="6" placeholder="Ex: responder duvidas tecnicas de eletrica, sinalizar interesse de compra, alertar baixo calao..."><?= wai_h((string)$cfg['criteria']) ?></textarea>
                </div>

                <button class="btn btn-primary" type="submit">Salvar configuracao</button>
            </form>
        </div>

        <div class="wai-card">
            <div class="wai-title">Automacao da blacklist</div>
            <form method="post">
                <input type="hidden" name="action" value="save_blacklist_config">

                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="blacklist_auto_remove" value="1" <?= $blacklistCfg['auto_remove'] ? 'checked' : '' ?>>
                        Remover automaticamente números da blacklist
                    </label>
                    <div class="wai-help">A instância espiã que recebeu o evento tentará remover o participante imediatamente do grupo. O número conectado precisa ser administrador do grupo.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="blacklist_notify_enabled" value="1" <?= $blacklistCfg['notify_enabled'] ? 'checked' : '' ?>>
                        Notificar a equipe pelo WhatsApp
                    </label>
                    <div class="wai-help">Este é o alerta direto específico da blacklist. Se criar uma regra geral abaixo para o mesmo evento e os mesmos membros, desative esta opção para evitar mensagens duplicadas.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Membros que receberão o alerta</label>
                    <div style="display:grid;gap:8px;border:1px solid var(--border);border-radius:8px;padding:10px">
                        <?php if (!$teamRecipients): ?>
                            <div class="wai-help">Nenhum membro ativo cadastrado na tela Equipe.</div>
                        <?php endif; ?>
                        <?php foreach ($teamRecipients as $member): ?>
                            <?php
                            $memberId = (int)$member['id'];
                            $memberPhone = trim((string)($member['whatsapp_number'] ?? ''));
                            $selected = in_array($memberId, $blacklistCfg['recipient_ids'], true);
                            ?>
                            <label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--text)">
                                <input type="checkbox" name="blacklist_recipient_ids[]" value="<?= $memberId ?>"
                                    <?= $selected ? 'checked' : '' ?> <?= $memberPhone === '' ? 'disabled' : '' ?>>
                                <span>
                                    <strong><?= wai_h((string)$member['nome']) ?></strong>
                                    <span class="wai-help" style="display:block;margin:1px 0 0">
                                        <?= $memberPhone !== '' ? wai_h($memberPhone) : 'Sem WhatsApp cadastrado' ?>
                                        <?= (int)($member['whatsapp_blacklist_exempt'] ?? 1) === 1 ? ' · protegido contra banimento' : '' ?>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="wai-help">Cadastre ou altere os telefones na tela Equipe.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mensagem padrão do alerta</label>
                    <textarea name="blacklist_message_template" rows="14"><?= wai_h((string)$blacklistCfg['message_template']) ?></textarea>
                    <div class="wai-help">
                        Variáveis: <code>{{numero}}</code>, <code>{{grupo_nome}}</code>, <code>{{grupo_id}}</code>,
                        <code>{{motivo_blacklist}}</code>, <code>{{origem_blacklist}}</code>,
                        <code>{{aluno_identificado}}</code>, <code>{{aluno_id}}</code>, <code>{{aluno_nome}}</code>,
                        <code>{{aluno_email}}</code>, <code>{{turmas}}</code>, <code>{{tags}}</code>,
                        <code>{{primeira_entrada}}</code>, <code>{{data_ocorrencia}}</code> e <code>{{status_remocao}}</code>.
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">Salvar automação da blacklist</button>
            </form>
        </div>

        <div class="wai-card">
            <div class="wai-title">Notificações WhatsApp por evento</div>
            <div class="wai-help" style="margin-bottom:12px">
                Crie regras reutilizáveis para qualquer evento atual ou futuro. Uma regra pode enviar a mesma mensagem para vários grupos e membros da equipe.
            </div>

            <?php
            $newEventMessage = "🔔 *AVISO DE EVENTO*\n\n"
                . "*Evento:* {{evento}}\n"
                . "*Aluno:* {{user.nome}}\n"
                . "*E-mail:* {{user.email}}\n"
                . "*WhatsApp:* {{user.telefone}}\n"
                . "*Turmas:* {{user.turmas}}\n"
                . "*Tags:* {{user.tags}}\n"
                . "*Data:* {{data_evento}}";
            $renderEventRuleForm = static function (
                array $rule,
                array $selectedGroups,
                array $selectedTeam
            ) use ($notificationGroups, $teamRecipients, $notificationInstances, $notificationEventCodes, $newEventMessage): void {
                $ruleId = (int)($rule['id'] ?? 0);
                $isNew = $ruleId <= 0;
                $eventCode = (string)($rule['event_code'] ?? '');
                $instanceKey = (string)($rule['instance_key'] ?? '');
                $template = (string)($rule['message_template'] ?? $newEventMessage);
                ?>
                <form method="post" class="wai-rule-card">
                    <input type="hidden" name="action" value="save_event_notification_rule">
                    <input type="hidden" name="rule_id" value="<?= $ruleId ?>">
                    <div class="wai-row">
                        <div class="form-group">
                            <label class="form-label">Nome da regra</label>
                            <input type="text" name="rule_name" required value="<?= wai_h((string)($rule['name'] ?? '')) ?>" placeholder="Ex: Alerta de blacklist para suporte">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Evento</label>
                            <input type="text" name="rule_event_code" list="wai-event-codes" required value="<?= wai_h($eventCode) ?>" placeholder="WHATSAPP_BLACKLIST_DETECTADO">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Instância para mensagens diretas à equipe</label>
                        <select name="rule_instance_key">
                            <option value="">Primeira instância conectada</option>
                            <?php foreach ($notificationInstances as $instance): ?>
                                <option value="<?= wai_h((string)$instance['instance_key']) ?>" <?= $instanceKey === (string)$instance['instance_key'] ? 'selected' : '' ?>>
                                    <?= wai_h((string)($instance['name'] ?: $instance['instance_key'])) ?> · <?= wai_h((string)$instance['status']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wai-destination-grid">
                        <div>
                            <label class="form-label">Grupos que receberão</label>
                            <div class="wai-check-list">
                                <?php if (!$notificationGroups): ?><span class="wai-help">Nenhum grupo sincronizado.</span><?php endif; ?>
                                <?php foreach ($notificationGroups as $group): ?>
                                    <?php $gid = (string)$group['group_id']; ?>
                                    <label class="wai-check-item">
                                        <input type="checkbox" name="rule_group_ids[]" value="<?= wai_h($gid) ?>" <?= in_array($gid, $selectedGroups, true) ? 'checked' : '' ?>>
                                        <span>
                                            <?= wai_h((string)($group['group_name'] ?: $gid)) ?>
                                            <?= (int)($group['is_ignored'] ?? 0) === 1 ? ' · monitoramento ignorado' : '' ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Equipe que receberá</label>
                            <div class="wai-check-list">
                                <?php if (!$teamRecipients): ?><span class="wai-help">Nenhum membro ativo.</span><?php endif; ?>
                                <?php foreach ($teamRecipients as $member): ?>
                                    <?php $mid = (int)$member['id']; $hasPhone = trim((string)($member['whatsapp_number'] ?? '')) !== ''; ?>
                                    <label class="wai-check-item">
                                        <input type="checkbox" name="rule_team_ids[]" value="<?= $mid ?>" <?= in_array($mid, $selectedTeam, true) ? 'checked' : '' ?> <?= !$hasPhone ? 'disabled' : '' ?>>
                                        <span><?= wai_h((string)$member['nome']) ?><?= !$hasPhone ? ' · sem WhatsApp' : '' ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:12px">
                        <label class="form-label">Mensagem</label>
                        <textarea name="rule_message_template" rows="10" required><?= wai_h($template) ?></textarea>
                        <div class="wai-help">
                            Variáveis: <code>{{evento}}</code>, <code>{{data_evento}}</code>, qualquer campo <code>{{user.campo}}</code>,
                            qualquer campo <code>{{extra.campo}}</code> e <code>{{destino.nome}}</code>. Exemplos:
                            <code>{{user.nome}}</code>, <code>{{user.tags}}</code>, <code>{{extra.blacklist.reason}}</code>.
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                        <label class="form-label" style="margin:0">
                            <input type="checkbox" name="rule_is_active" value="1" <?= $isNew || (int)($rule['is_active'] ?? 0) === 1 ? 'checked' : '' ?>>
                            Regra ativa
                        </label>
                        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Criar regra' : 'Salvar alterações' ?></button>
                    </div>
                </form>
                <?php
            };
            ?>

            <datalist id="wai-event-codes">
                <?php foreach ($notificationEventCodes as $eventCode): ?><option value="<?= wai_h($eventCode) ?>"></option><?php endforeach; ?>
            </datalist>

            <details>
                <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--primary)">+ Criar nova regra</summary>
                <?php $renderEventRuleForm([], [], []); ?>
            </details>

            <?php if (!$eventNotificationRules): ?>
                <div class="wai-help" style="padding:14px 0">Nenhuma regra de notificação por evento criada.</div>
            <?php endif; ?>
            <?php foreach ($eventNotificationRules as $rule): ?>
                <?php $rid = (int)$rule['id']; ?>
                <div class="wai-rule-card">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
                        <div>
                            <strong style="font-size:12px;color:var(--text)"><?= wai_h((string)$rule['name']) ?></strong>
                            <div class="wai-help"><?= wai_h((string)$rule['event_code']) ?></div>
                        </div>
                        <?= wai_badge((int)$rule['is_active'] === 1 ? 'Ativa' : 'Inativa', (int)$rule['is_active'] === 1 ? 'success' : 'neutral') ?>
                    </div>
                    <details style="margin-top:8px">
                        <summary style="cursor:pointer;font-size:11px;color:var(--muted)">Editar regra</summary>
                        <?php $renderEventRuleForm($rule, $eventNotificationRuleGroups[$rid] ?? [], $eventNotificationRuleTeam[$rid] ?? []); ?>
                    </details>
                    <div style="display:flex;gap:7px;margin-top:8px">
                        <form method="post">
                            <input type="hidden" name="action" value="toggle_event_notification_rule">
                            <input type="hidden" name="rule_id" value="<?= $rid ?>">
                            <button class="btn btn-ghost btn-xs" type="submit"><?= (int)$rule['is_active'] === 1 ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('Excluir esta regra de notificacao?')">
                            <input type="hidden" name="action" value="delete_event_notification_rule">
                            <input type="hidden" name="rule_id" value="<?= $rid ?>">
                            <button class="btn btn-ghost btn-xs" type="submit" style="color:var(--danger)">Excluir</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="wai-card">
            <div class="wai-title">Cron</div>
            <div class="wai-help" style="margin-bottom:8px">Configure este endpoint no cron do servidor, idealmente a cada minuto. A propria configuracao da IA decide se ja passou o intervalo de empacotamento.</div>
            <div class="wai-code"><?= wai_h($cronUrl) ?></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <form method="post"><input type="hidden" name="action" value="run_now"><button class="btn" type="submit">Processar agora</button></form>
                <form method="post" onsubmit="return confirm('Gerar novo token do cron? O cron configurado no servidor precisara ser atualizado.')"><input type="hidden" name="action" value="rotate_token"><button class="btn btn-ghost" type="submit">Gerar novo token</button></form>
            </div>
        </div>
        <?php endif; ?>

        <div class="wai-card">
            <div class="wai-title">Fila de revisao manual</div>
            <div class="wai-help" style="margin-bottom:10px">Alertas leve, médio e crítico e suas tags são executados automaticamente. Esta fila mantém respostas sugeridas e demais ações que exigem aprovação humana.</div>
            <?php if (!$actions): ?>
                <div style="color:var(--muted);font-size:12px;text-align:center;padding:20px">Nenhuma acao pendente no momento.</div>
            <?php endif; ?>
            <?php foreach ($actions as $a): ?>
                <?php
                $type = (string)$a['action_type'];
                $status = (string)$a['status'];
                $messagesForAction = $actionMessages[(int)$a['batch_id']] ?? [];
                $messageText = (string)($a['message_text'] ?? '');
                ?>
                <div class="wai-review">
                    <div class="wai-review-head">
                        <div>
                            <div class="wai-review-title"><?= wai_h(wai_action_label($type)) ?></div>
                            <div class="wai-review-meta">
                                <?= wai_h((string)($a['group_name'] ?: $a['group_id'])) ?> · pacote #<?= (int)$a['batch_id'] ?> · <?= wai_dt((string)$a['batch_created_at']) ?>
                            </div>
                        </div>
                        <div><?= wai_badge($status, $status === 'error' ? 'danger' : 'warn') ?></div>
                    </div>

                    <div style="font-size:12px;color:var(--text);line-height:1.5">
                        <strong>Resumo:</strong> <?= wai_h((string)($a['summary'] ?: '-')) ?>
                    </div>
                    <div class="wai-help">
                        Categoria: <?= wai_h((string)($a['category'] ?: '-')) ?> · Nivel: <?= wai_h((string)($a['severity'] ?: '-')) ?>
                        <?php if (!empty($a['target_name']) || !empty($a['target_phone'])): ?>
                            · Alvo: <?= wai_h(trim((string)$a['target_name'] . ' ' . (string)$a['target_phone'])) ?>
                        <?php endif; ?>
                        <?php if (!empty($a['tag_name'])): ?> · Tag: <?= wai_h((string)$a['tag_name']) ?><?php endif; ?>
                        <?php if (!empty($a['event_name'])): ?> · Evento: <?= wai_h((string)$a['event_name']) ?><?php endif; ?>
                    </div>

                    <?php if ($messagesForAction): ?>
                    <div class="wai-message-list">
                        <?php foreach ($messagesForAction as $m): ?>
                            <?php
                            $author = trim((string)($m['aluno_nome'] ?: $m['sender_name'] ?: $m['sender_phone'] ?: 'Participante'));
                            ?>
                            <div class="wai-message-line">
                                <strong><?= wai_h($author) ?>:</strong> <?= wai_h((string)$m['message_text']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($a['error_message'])): ?>
                        <div class="alert alert-error" style="margin:8px 0"><?= wai_h((string)$a['error_message']) ?></div>
                    <?php endif; ?>

                    <div class="wai-actions">
                        <form method="post" style="flex:1;min-width:260px">
                            <input type="hidden" name="action" value="approve_action">
                            <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
                            <?php if ($type === 'send_group_message'): ?>
                                <textarea name="message_text" rows="4"><?= wai_h($messageText) ?></textarea>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit" onclick="return confirm('Aprovar e executar esta acao agora?')">Aprovar</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="action" value="ignore_action">
                            <input type="hidden" name="action_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-ghost" type="submit">Ignorar</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="action" value="resolve_batch">
                            <input type="hidden" name="batch_id" value="<?= (int)$a['batch_id'] ?>">
                            <button class="btn btn-ghost" type="submit">Resolver pacote</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="wai-card">
            <div class="wai-title">Ultimos pacotes analisados</div>
            <div style="overflow-x:auto">
                <table class="wai-table">
                    <thead><tr><th>Data</th><th>Grupo</th><th>Status</th><th>Categoria</th><th>Resumo</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$batches): ?>
                    <tr><td colspan="6" style="color:var(--muted);text-align:center;padding:18px">Nenhum pacote analisado ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($batches as $b): ?>
                    <tr>
                        <td><?= wai_dt((string)$b['created_at']) ?></td>
                        <td><?= wai_h((string)($b['group_name'] ?: $b['group_id'])) ?><div class="wai-code"><?= wai_h((string)$b['group_id']) ?></div></td>
                        <td>
                            <?php
                            $status = (string)$b['status'];
                            echo wai_badge($status, $status === 'done' ? (!empty($b['needs_intervention']) ? 'warn' : 'success') : ($status === 'error' ? 'danger' : 'info'));
                            ?>
                            <div class="wai-help"><?= (int)$b['message_count'] ?> msg</div>
                        </td>
                        <td><?= wai_h((string)($b['category'] ?: '-')) ?><div class="wai-help"><?= wai_h((string)($b['severity'] ?: '')) ?></div></td>
                        <td>
                            <div style="max-width:520px;color:var(--text)"><?= wai_h((string)($b['summary'] ?: $b['error_message'] ?: '-')) ?></div>
                            <?php if (!empty($b['suggested_response'])): ?><div class="wai-help">Sugestao: <?= wai_h((string)$b['suggested_response']) ?></div><?php endif; ?>
                        </td>
                        <td style="text-align:right">
                            <?php if ((string)$b['status'] === 'error'): ?>
                                <form method="post" style="margin:0">
                                    <input type="hidden" name="action" value="requeue_batch">
                                    <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                                    <button class="btn btn-ghost btn-xs" type="submit" onclick="return confirm('Reabrir este pacote para reprocessar as mesmas mensagens?')">Reprocessar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div>
        <div class="wai-card">
            <div class="wai-title">Envios por evento</div>
            <div style="overflow-x:auto;max-height:430px">
                <table class="wai-table">
                    <thead><tr><th>Data</th><th>Regra/evento</th><th>Destino</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$eventNotificationLogs): ?>
                        <tr><td colspan="4" style="color:var(--muted);text-align:center;padding:18px">Nenhuma notificação por evento enviada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($eventNotificationLogs as $log): ?>
                        <tr>
                            <td><?= wai_dt((string)$log['created_at']) ?></td>
                            <td>
                                <strong><?= wai_h((string)($log['rule_name'] ?: ('Regra #' . $log['rule_id']))) ?></strong>
                                <div class="wai-help"><?= wai_h((string)$log['event_code']) ?></div>
                            </td>
                            <td>
                                <?= wai_h((string)($log['destination_name'] ?: $log['destination_id'])) ?>
                                <div class="wai-help"><?= wai_h((string)$log['destination_type']) ?></div>
                            </td>
                            <td>
                                <?= wai_badge((string)$log['status'], (string)$log['status'] === 'sent' ? 'success' : 'danger') ?>
                                <?php if (!empty($log['error_message'])): ?><div class="wai-help"><?= wai_h((string)$log['error_message']) ?></div><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wai-card">
            <div class="wai-title">Últimas ações da blacklist</div>
            <div style="overflow-x:auto;max-height:430px">
                <table class="wai-table">
                    <thead><tr><th>Data</th><th>Contato</th><th>Remoção</th><th>Alerta</th></tr></thead>
                    <tbody>
                    <?php if (!$blacklistActions): ?>
                        <tr><td colspan="4" style="color:var(--muted);text-align:center;padding:18px">Nenhuma ação automática registrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($blacklistActions as $ba): ?>
                        <tr>
                            <td><?= wai_dt((string)$ba['created_at']) ?></td>
                            <td>
                                <strong><?= wai_h((string)($ba['user_name'] ?: $ba['participant_phone'] ?: 'Não identificado')) ?></strong>
                                <div class="wai-help"><?= wai_h((string)$ba['participant_phone']) ?></div>
                                <div class="wai-help"><?= wai_h((string)($ba['group_name'] ?: $ba['group_id'])) ?></div>
                            </td>
                            <td>
                                <?php
                                $removal = (string)$ba['removal_status'];
                                echo wai_badge(
                                    $removal,
                                    $removal === 'removed' ? 'success' : ($removal === 'error' ? 'danger' : ($removal === 'protected_team' ? 'info' : 'neutral'))
                                );
                                ?>
                            </td>
                            <td>
                                <?= wai_badge((string)($ba['notification_status'] ?: '-'), (string)$ba['notification_status'] === 'sent' ? 'success' : ((string)$ba['notification_status'] === 'error' ? 'danger' : 'neutral')) ?>
                                <div class="wai-help"><?= (int)$ba['notification_sent'] ?>/<?= (int)$ba['notification_recipients'] ?> enviado(s)</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wai-card">
            <div class="wai-title">Grupos monitorados</div>
            <div style="overflow-x:auto;max-height:520px">
                <table class="wai-table">
                    <thead><tr><th>Grupo</th><th>Mensagens</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$groups): ?>
                    <tr><td colspan="4" style="color:var(--muted);text-align:center;padding:18px">Nenhum grupo sincronizado ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($groups as $g): ?>
                    <tr>
                        <td>
                            <strong><?= wai_h((string)($g['group_name'] ?: $g['group_id'])) ?></strong>
                            <div class="wai-code"><?= wai_h((string)$g['group_id']) ?></div>
                            <div class="wai-help">Ultima msg: <?= wai_dt((string)($g['last_message_at'] ?? '')) ?></div>
                        </td>
                        <td><?= (int)$g['ai_messages'] ?><div class="wai-help"><?= (int)$g['pending_messages'] ?> pendentes</div></td>
                        <td><?= ((int)$g['is_ignored'] === 1) ? wai_badge('Ignorado', 'neutral') : wai_badge('Ativo', 'success') ?></td>
                        <td style="text-align:right">
                            <form method="post">
                                <input type="hidden" name="action" value="toggle_group_ignore">
                                <input type="hidden" name="group_id" value="<?= wai_h((string)$g['group_id']) ?>">
                                <button class="btn btn-ghost btn-xs" type="submit"><?= ((int)$g['is_ignored'] === 1) ? 'Ativar' : 'Ignorar' ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wai-card">
            <div class="wai-title">Historico de acoes</div>
            <div style="overflow-x:auto;max-height:360px">
                <table class="wai-table">
                    <thead><tr><th>Acao</th><th>Status</th><th>Auditoria</th></tr></thead>
                    <tbody>
                    <?php if (!$recentActions): ?>
                    <tr><td colspan="3" style="color:var(--muted);text-align:center;padding:18px">Nenhuma acao processada ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentActions as $ra): ?>
                    <tr>
                        <td>
                            <strong><?= wai_h(wai_action_label((string)$ra['action_type'])) ?></strong>
                            <div class="wai-help"><?= wai_h((string)($ra['group_name'] ?: $ra['group_id'])) ?></div>
                            <?php if (!empty($ra['tag_name'])): ?><div class="wai-help">Tag: <?= wai_h((string)$ra['tag_name']) ?></div><?php endif; ?>
                            <?php if (!empty($ra['event_name'])): ?><div class="wai-help">Evento: <?= wai_h((string)$ra['event_name']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $raStatus = (string)$ra['status'];
                            echo wai_badge($raStatus, $raStatus === 'executed' ? 'success' : ($raStatus === 'error' ? 'danger' : 'neutral'));
                            ?>
                            <?php if (!empty($ra['error_message'])): ?><div class="wai-help"><?= wai_h((string)$ra['error_message']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <div class="wai-help">Aprovado: <?= wai_h((string)($ra['approved_by'] ?: '-')) ?></div>
                            <div class="wai-help">Ignorado: <?= wai_h((string)($ra['ignored_by'] ?: '-')) ?></div>
                            <div class="wai-help"><?= wai_dt((string)($ra['executed_at'] ?: $ra['ignored_at'] ?: $ra['approved_at'] ?: $ra['created_at'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wai-card">
            <div class="wai-title">Ultimas mensagens capturadas</div>
            <div style="overflow-x:auto;max-height:520px">
                <table class="wai-table">
                    <thead><tr><th>Data</th><th>Autor</th><th>Mensagem</th></tr></thead>
                    <tbody>
                    <?php if (!$messages): ?>
                    <tr><td colspan="3" style="color:var(--muted);text-align:center;padding:18px">Nenhuma mensagem capturada ainda.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($messages as $m): ?>
                    <tr>
                        <td><?= wai_dt((string)$m['message_at']) ?></td>
                        <td>
                            <?= wai_h((string)($m['aluno_nome'] ?: $m['sender_name'] ?: 'Participante')) ?>
                            <div class="wai-help"><?= wai_h((string)($m['sender_phone'] ?: $m['sender_id'] ?: '')) ?></div>
                        </td>
                        <td>
                            <div class="wai-msg"><?= wai_h((string)$m['message_text']) ?></div>
                            <?php if (!empty($m['media_kind'])): ?>
                                <div class="wai-help">Midia: <?= wai_h((string)$m['media_kind']) ?><?= !empty($m['transcription_text']) ? ' · transcrita' : '' ?><?= !empty($m['transcription_status']) && empty($m['transcription_text']) ? ' · ' . wai_h((string)$m['transcription_status']) : '' ?></div>
                            <?php endif; ?>
                            <div class="wai-help"><?= wai_h((string)($m['group_name'] ?: $m['group_id'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    if (typeof Chart === 'undefined') return;
    new Chart(document.getElementById('waiSeverityChart'), {
        type:'doughnut',
        data:{labels:<?= json_encode($aiSeverityChart['labels'], JSON_UNESCAPED_UNICODE) ?>,datasets:[{data:<?= json_encode($aiSeverityChart['data']) ?>,backgroundColor:['#38bdf8','#facc15','#ef4444']}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
    });
    new Chart(document.getElementById('waiCategoryChart'), {
        type:'bar',
        data:{labels:<?= json_encode($aiCategoryChart['labels'], JSON_UNESCAPED_UNICODE) ?>,datasets:[{data:<?= json_encode($aiCategoryChart['data']) ?>,backgroundColor:'#8b5cf6'}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}
    });
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>
