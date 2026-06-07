<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/evolution_api.php';

proteger_admin();
$pdo = getPDO();
evolution_ensure_tables($pdo);

$menu = 'whatsapp_monitor';
$page_title = 'WhatsApp Monitor';

function wh_h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wh_status_badge(string $status): string {
    $status = strtoupper(trim($status));
    $class = 'badge-neutral';
    if ($status === 'CONNECTED') $class = 'badge-success';
    if ($status === 'CONNECTING') $class = 'badge-warning';
    if ($status === 'ERROR') $class = 'badge-danger';
    if ($status === 'DISCONNECTED') $class = 'badge-neutral';
    return '<span class="badge ' . $class . '">' . wh_h($status ?: 'DISCONNECTED') . '</span>';
}

function wh_clean_phone(?string $participant): string {
    $participant = trim((string)$participant);
    if ($participant === '') return '';
    $participant = preg_replace('/@.*$/', '', $participant) ?? $participant;
    return preg_replace('/\D+/', '', $participant) ?? '';
}

function wh_phone_from_payload(?string $rawPayload, ?string $fallbackParticipant = null): string {
    $payload = json_decode((string)$rawPayload, true);
    if (is_array($payload)) {
        $participants = $payload['data']['participants'] ?? [];
        if (is_array($participants)) {
            $first = reset($participants);
            if (is_array($first)) {
                $phone = wh_clean_phone((string)($first['phoneNumber'] ?? ''));
                if ($phone !== '') return $phone;
            }
        }

        $participantsData = $payload['data']['participantsData'] ?? [];
        if (is_array($participantsData)) {
            $firstData = reset($participantsData);
            if (is_array($firstData)) {
                $jid = $firstData['jid'] ?? [];
                if (is_array($jid)) {
                    $phone = wh_clean_phone((string)($jid['phoneNumber'] ?? ''));
                    if ($phone !== '') return $phone;
                }
            }
        }
    }
    return wh_clean_phone($fallbackParticipant);
}

function wh_event_label(?string $event, ?string $action = null): string {
    $event = trim((string)$event);
    $labels = [
        'WHATSAPP_GRUPO_ENTROU' => 'Entrou no grupo',
        'WHATSAPP_GRUPO_SAIU' => 'Saiu por conta propria',
        'WHATSAPP_GRUPO_REMOVIDO_ADMIN' => 'Removido por admin',
        'WHATSAPP_GRUPO_PROMOVIDO_ADMIN' => 'Promovido a admin',
        'WHATSAPP_GRUPO_REBAIXADO_ADMIN' => 'Rebaixado de admin',
    ];
    if (isset($labels[$event])) return $labels[$event];
    $action = trim((string)$action);
    return $action !== '' ? $action : '-';
}

function wh_trigger_label(?string $status): string {
    $status = trim((string)$status);
    $labels = [
        'triggered' => 'Gatilhos acionados',
        'blacklist_detected' => 'Blacklist detectada',
        'blacklist_detected_no_user' => 'Blacklist sem aluno',
        'blacklist_detected_backfill' => 'Blacklist retroativa',
        'identified_backfill' => 'Aluno identificado',
        'ignored_group' => 'Grupo ignorado',
        'user_not_found' => 'Aluno nao encontrado',
        'ignored' => 'Ignorado',
        'error' => 'Erro',
    ];
    return $labels[$status] ?? ($status !== '' ? $status : '-');
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_config') {
            evolution_set_config(
                trim((string)($_POST['base_url'] ?? '')),
                trim((string)($_POST['apikey'] ?? '')),
                (int)($_POST['timeout_seconds'] ?? 20)
            );
            header('Location: whatsapp_monitor.php?saved=1');
            exit;
        }

        if ($action === 'create_instance') {
            $name = trim((string)($_POST['name'] ?? ''));
            $instanceKey = trim((string)($_POST['instance_key'] ?? ''));
            $phone = preg_replace('/\D+/', '', (string)($_POST['phone_number'] ?? ''));
            if ($name === '') $name = 'Numero Espiao';
            if ($instanceKey === '') $instanceKey = evolution_slug_instance($name);
            $token = bin2hex(random_bytes(24));

            $st = $pdo->prepare("
                INSERT INTO whatsapp_instances
                    (name, instance_key, phone_number, status, instance_token, created_at, updated_at)
                VALUES
                    (:name, :instance_key, :phone_number, 'DISCONNECTED', :instance_token, NOW(), NOW())
            ");
            $st->execute([
                ':name' => $name,
                ':instance_key' => $instanceKey,
                ':phone_number' => $phone !== '' ? $phone : null,
                ':instance_token' => $token,
            ]);
            $id = (int)$pdo->lastInsertId();
            $instance = evolution_get_instance($pdo, $id);
            if ($instance) evolution_create_remote_instance($pdo, $instance);

            header('Location: whatsapp_monitor.php?created=' . $id);
            exit;
        }

        if ($action === 'connect_instance') {
            $id = (int)($_POST['id'] ?? 0);
            $instance = evolution_get_instance($pdo, $id);
            if (!$instance) throw new RuntimeException('Instancia nao encontrada.');
            evolution_connect_instance($pdo, $instance);
            header('Location: whatsapp_monitor.php?qr=' . $id);
            exit;
        }

        if ($action === 'refresh_status') {
            $id = (int)($_POST['id'] ?? 0);
            $instance = evolution_get_instance($pdo, $id);
            if (!$instance) throw new RuntimeException('Instancia nao encontrada.');
            evolution_fetch_state($pdo, $instance);
            header('Location: whatsapp_monitor.php?status=' . $id);
            exit;
        }

        if ($action === 'delete_local') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM whatsapp_instances WHERE id = :id LIMIT 1")->execute([':id' => $id]);
            header('Location: whatsapp_monitor.php?deleted=1');
            exit;
        }

        if ($action === 'set_group_webhook') {
            $instanceKey = trim((string)($_POST['webhook_instance_key'] ?? ''));
            if ($instanceKey === '') throw new RuntimeException('Informe a chave da instancia.');
            $webhookUrl = rtrim(BASE_URL, '/') . '/whatsapp_webhook.php?t=' . evolution_get_webhook_token();
            $res = evolution_set_group_webhook($instanceKey, $webhookUrl);
            if (!$res['ok']) {
                $detail = trim((string)($res['raw'] ?: $res['error']));
                throw new RuntimeException('Falha ao configurar webhook: ' . substr($detail, 0, 900));
            }
            set_setting('evolution_webhook_instance_key', $instanceKey);
            header('Location: whatsapp_monitor.php?webhook_set=1');
            exit;
        }

        if ($action === 'add_blacklist_number') {
            $phone = evolution_clean_whatsapp_phone((string)($_POST['blacklist_phone'] ?? ''));
            $reason = trim((string)($_POST['blacklist_reason'] ?? ''));
            if ($phone === '') throw new RuntimeException('Informe um telefone valido para a blacklist.');

            $st = $pdo->prepare("
                INSERT INTO whatsapp_blacklist_numbers (phone_number, reason, origem, is_active, created_at)
                VALUES (:phone, :reason, 'manual', 1, NOW())
                ON DUPLICATE KEY UPDATE
                    reason = VALUES(reason),
                    is_active = 1,
                    updated_at = NOW()
            ");
            $st->execute([
                ':phone' => $phone,
                ':reason' => $reason !== '' ? $reason : null,
            ]);
            header('Location: whatsapp_monitor.php?blacklist_saved=1');
            exit;
        }

        if ($action === 'toggle_blacklist_number') {
            $id = (int)($_POST['blacklist_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Registro de blacklist invalido.');
            $pdo->prepare("
                UPDATE whatsapp_blacklist_numbers
                   SET is_active = IF(is_active=1,0,1), updated_at = NOW()
                 WHERE id = :id
                 LIMIT 1
            ")->execute([':id' => $id]);
            header('Location: whatsapp_monitor.php?blacklist_saved=1');
            exit;
        }

        if ($action === 'refresh_group_names') {
            $instanceRows = $pdo->query("
                SELECT DISTINCT instance_key
                  FROM (
                        SELECT instance_key FROM whatsapp_groups
                        UNION
                        SELECT instance_key FROM whatsapp_webhook_raw_logs
                  ) x
                 WHERE instance_key IS NOT NULL
                   AND instance_key <> ''
                 LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $updated = 0;
            foreach ($instanceRows as $instRow) {
                $updated += evolution_sync_groups_for_instance($pdo, (string)($instRow['instance_key'] ?? ''));
            }

            $rows = $pdo->query("
                SELECT group_id, instance_key
                  FROM (
                        SELECT group_id, instance_key FROM whatsapp_groups
                        UNION
                        SELECT group_id, instance_key FROM whatsapp_webhook_raw_logs
                  ) x
                 WHERE group_id IS NOT NULL
                   AND group_id <> ''
                   AND instance_key IS NOT NULL
                   AND instance_key <> ''
                 ORDER BY group_id DESC
                 LIMIT 120
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                evolution_upsert_group($pdo, [
                    'group_id' => $row['group_id'] ?? '',
                    'instance_key' => $row['instance_key'] ?? '',
                ]);
                evolution_refresh_group_name_if_needed($pdo, [
                    'group_id' => $row['group_id'] ?? '',
                    'instance_key' => $row['instance_key'] ?? '',
                ]);
                $updated++;
            }
            header('Location: whatsapp_monitor.php?groups_refreshed=' . $updated);
            exit;
        }

        if ($action === 'toggle_group_ignored') {
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            if ($groupId === '') throw new RuntimeException('Grupo invalido.');
            $pdo->prepare("
                UPDATE whatsapp_groups
                   SET is_ignored = IF(is_ignored=1,0,1),
                       last_seen_at = NOW()
                 WHERE group_id = :gid
                 LIMIT 1
            ")->execute([':gid' => $groupId]);
            header('Location: whatsapp_monitor.php?group_scope_saved=1');
            exit;
        }

        if ($action === 'backfill_event_users') {
            $res = evolution_backfill_unmatched_group_events($pdo, 1000);
            header(
                'Location: whatsapp_monitor.php?backfill_done=1'
                . '&processed=' . (int)($res['processed'] ?? 0)
                . '&matched=' . (int)($res['matched'] ?? 0)
                . '&missing=' . (int)($res['still_missing'] ?? 0)
            );
            exit;
        }

        if ($action === 'apply_backfill_tags') {
            $res = evolution_apply_tags_to_identified_group_events($pdo, 2000);
            header(
                'Location: whatsapp_monitor.php?backfill_tags_done=1'
                . '&processed=' . (int)($res['processed'] ?? 0)
                . '&tagged=' . (int)($res['tagged'] ?? 0)
                . '&skipped=' . (int)($res['skipped'] ?? 0)
            );
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved'])) $notice = 'Configuracao da Evolution API salva.';
if (isset($_GET['created'])) $notice = 'Instancia criada. Se o QR nao aparecer, clique em "Gerar QR".';
if (isset($_GET['qr'])) $notice = 'QR Code solicitado. Leia com o WhatsApp do numero de teste.';
if (isset($_GET['status'])) $notice = 'Status atualizado.';
if (isset($_GET['deleted'])) $notice = 'Instancia removida apenas do painel local.';
if (isset($_GET['webhook_set'])) $notice = 'Webhook de grupos configurado na Evolution API.';
if (isset($_GET['blacklist_saved'])) $notice = 'Blacklist atualizada.';
if (isset($_GET['groups_refreshed'])) $notice = 'Atualizacao de nomes de grupos solicitada para ' . (int)$_GET['groups_refreshed'] . ' grupo(s).';
if (isset($_GET['group_scope_saved'])) $notice = 'Configuracao do grupo atualizada.';
if (isset($_GET['backfill_done'])) {
    $notice = 'Reprocessamento concluido: '
        . (int)($_GET['processed'] ?? 0) . ' evento(s) analisado(s), '
        . (int)($_GET['matched'] ?? 0) . ' aluno(s) identificado(s), '
        . (int)($_GET['missing'] ?? 0) . ' ainda sem aluno.';
}
if (isset($_GET['backfill_tags_done'])) {
    $notice = 'Tags retroativas aplicadas: '
        . (int)($_GET['processed'] ?? 0) . ' evento(s) analisado(s), '
        . (int)($_GET['tagged'] ?? 0) . ' tag(s) aplicada(s), '
        . (int)($_GET['skipped'] ?? 0) . ' ignorado(s).';
}

$cfg = evolution_get_config();
$instances = $pdo->query("SELECT * FROM whatsapp_instances ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeId = (int)($_GET['qr'] ?? $_GET['created'] ?? $_GET['status'] ?? 0);
$webhookToken = evolution_get_webhook_token();
$webhookUrl = rtrim(BASE_URL, '/') . '/whatsapp_webhook.php?t=' . $webhookToken;
$webhookInstanceKey = (string)get_setting('evolution_webhook_instance_key', 'monitor01');
$blacklistRows = [];
$groupRows = [];
try {
    $blacklistRows = $pdo->query("
        SELECT *
          FROM whatsapp_blacklist_numbers
         ORDER BY is_active DESC, id DESC
         LIMIT 80
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
try {
    $groupRows = $pdo->query("
        SELECT g.*,
               (SELECT COUNT(*) FROM whatsapp_group_events ge WHERE ge.group_id = g.group_id) AS total_events,
               (SELECT COUNT(*) FROM whatsapp_group_events ge WHERE ge.group_id = g.group_id AND ge.is_blacklisted = 1) AS total_blacklist
          FROM whatsapp_groups g
         ORDER BY g.last_seen_at DESC
         LIMIT 40
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$rawLogs = [];
try {
    $rawLogs = $pdo->query("
        SELECT l.id, l.token_ok, l.event_type, l.instance_key, l.group_id, l.action,
               l.participant_number, l.participant_phone, l.participant_id, l.author_id,
               l.interpreted_event, l.user_id, l.trigger_status, l.trigger_error,
               ge.is_blacklisted, ge.blacklist_id,
               bl.reason AS blacklist_reason,
               l.payload_raw, l.source_ip, l.received_at,
               g.group_name, g.picture_url AS group_picture_url, g.is_ignored AS group_is_ignored,
               u.nome AS user_nome, u.email AS user_email, u.telefone AS user_telefone,
               u.codigo_turma AS user_codigo_turma
        FROM whatsapp_webhook_raw_logs l
        LEFT JOIN whatsapp_group_events ge ON ge.raw_log_id = l.id
        LEFT JOIN whatsapp_blacklist_numbers bl ON bl.id = ge.blacklist_id
        LEFT JOIN whatsapp_groups g ON g.group_id = l.group_id
        LEFT JOIN users u ON u.id = l.user_id
        ORDER BY l.id DESC
        LIMIT 80
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

include __DIR__ . '/_header.php';
?>

<style>
.wm-wrap { padding: 4px 0 38px; }
.wm-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:18px; }
.wm-head h1 { font-size:24px; margin:0 0 4px; }
.wm-head p { margin:0; color:var(--muted); font-size:13px; max-width:780px; }
.wm-grid { display:grid; grid-template-columns:minmax(300px, .85fr) minmax(360px, 1.15fr); gap:16px; align-items:start; }
.wm-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:18px; box-shadow:var(--shadow); }
.wm-card h2 { font-size:15px; margin:0 0 4px; }
.wm-card-sub { font-size:12px; color:var(--muted); margin-bottom:16px; }
.wm-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.wm-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.wm-instance { border:1px solid var(--border); border-radius:12px; padding:14px; background:rgba(255,255,255,.025); margin-bottom:12px; }
.wm-instance.active { border-color:rgba(250,204,21,.42); background:rgba(250,204,21,.045); }
.wm-instance-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:8px; }
.wm-name { font-size:14px; font-weight:700; }
.wm-meta { font-size:11px; color:var(--muted); margin-top:2px; word-break:break-all; }
.wm-qrbox { display:grid; grid-template-columns:220px minmax(0,1fr); gap:16px; align-items:start; margin-top:12px; }
.wm-qr { width:220px; min-height:220px; border-radius:12px; border:1px solid var(--border); background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; padding:10px; }
.wm-qr img, .wm-qr canvas { max-width:198px; max-height:198px; }
.wm-kv { display:grid; grid-template-columns:120px minmax(0,1fr); gap:6px 10px; font-size:12px; }
.wm-kv b { color:var(--muted); font-weight:600; }
.wm-kv span { min-width:0; word-break:break-word; }
.wm-help { font-size:12px; color:var(--muted); line-height:1.6; }
.wm-code { font-family:monospace; background:rgba(255,255,255,.06); border:1px solid var(--border); border-radius:8px; padding:8px; font-size:11px; color:#93c5fd; max-height:110px; overflow:auto; word-break:break-all; }
.wm-danger-note { border:1px solid rgba(245,158,11,.28); background:rgba(245,158,11,.08); color:#fcd34d; border-radius:12px; padding:10px 12px; font-size:12px; margin-bottom:14px; }
.wm-full { margin-top:16px; }
.wm-url-row { display:flex; gap:8px; align-items:center; }
.wm-url-row input { font-family:monospace; font-size:12px; }
.wm-log-table td { font-size:12px; vertical-align:top; }
.wm-payload { max-width:520px; max-height:90px; overflow:auto; white-space:pre-wrap; word-break:break-word; font-family:monospace; font-size:11px; color:#93c5fd; background:rgba(255,255,255,.035); border:1px solid var(--border); border-radius:8px; padding:7px; }
.wm-group-cell { display:flex; align-items:center; gap:10px; min-width:220px; }
.wm-group-avatar { width:34px; height:34px; border-radius:999px; overflow:hidden; flex:0 0 auto; background:rgba(255,255,255,.08); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:12px; font-weight:700; }
.wm-group-avatar img { width:100%; height:100%; object-fit:cover; display:block; }
@media(max-width:1000px){ .wm-grid,.wm-qrbox{grid-template-columns:1fr}.wm-row{grid-template-columns:1fr}.wm-qr{width:100%;max-width:260px} }
</style>

<div class="wm-wrap">
    <div class="wm-head">
        <div>
            <h1>WhatsApp Monitor</h1>
            <p>Conecta instancias da Evolution API, recebe eventos de grupos, cruza participantes com alunos e dispara tags/webhooks/SuperFuncionario. Nenhuma remocao automatica e executada.</p>
        </div>
        <a class="btn btn-ghost" href="../README_EVOLUTION_API.md" target="_blank">README</a>
    </div>

    <?php if ($notice): ?><div class="alert alert-ok"><?= wh_h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= wh_h($error) ?></div><?php endif; ?>

    <div class="wm-danger-note">
        Use um numero secundario no teste. A conexao via WhatsApp Web/Baileys nao e a API oficial da Meta e pode sofrer desconexoes ou restricoes.
    </div>

    <div class="wm-grid">
        <div>
            <div class="wm-card">
                <h2>Configuracao Evolution API</h2>
                <div class="wm-card-sub">Informe a URL do servico Evolution e a chave global enviada no header <span class="code">apikey</span>.</div>
                <form method="post">
                    <input type="hidden" name="action" value="save_config">
                    <div class="form-group">
                        <label class="form-label">URL base</label>
                        <input type="url" name="base_url" value="<?= wh_h($cfg['base_url']) ?>" placeholder="http://127.0.0.1:8080">
                    </div>
                    <div class="form-group">
                        <label class="form-label">API key</label>
                        <input type="password" name="apikey" value="<?= wh_h($cfg['apikey']) ?>" placeholder="sua API key da Evolution">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Timeout segundos</label>
                        <input type="number" name="timeout_seconds" value="<?= (int)$cfg['timeout'] ?>" min="3" max="120">
                    </div>
                    <button class="btn btn-primary" type="submit">Salvar configuracao</button>
                </form>
            </div>

            <div class="wm-card">
                <h2>Nova instancia</h2>
                <div class="wm-card-sub">Cria uma instancia Baileys na Evolution API e prepara o QR Code.</div>
                <form method="post">
                    <input type="hidden" name="action" value="create_instance">
                    <div class="form-group">
                        <label class="form-label">Nome interno</label>
                        <input type="text" name="name" placeholder="Numero espiao 01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Chave da instancia</label>
                        <input type="text" name="instance_key" placeholder="spy-01">
                        <div class="text-xs text-muted mt-2">Use letras, numeros, hifen ou underline. Se vazio, o sistema gera pelo nome.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telefone opcional</label>
                        <input type="text" name="phone_number" placeholder="5599999999999">
                    </div>
                    <button class="btn btn-primary" type="submit">Criar instancia</button>
                </form>
            </div>
        </div>

        <div class="wm-card">
            <h2>Instancias</h2>
            <div class="wm-card-sub">Gere o QR, leia pelo WhatsApp e atualize o status ate aparecer conectado.</div>

            <?php if (!$instances): ?>
                <div class="text-muted text-sm">Nenhuma instancia cadastrada ainda.</div>
            <?php endif; ?>

            <?php foreach ($instances as $inst): ?>
                <?php
                $id = (int)$inst['id'];
                $isActive = $activeId === $id;
                $qrBase64 = trim((string)($inst['qr_base64'] ?? ''));
                $qrText = trim((string)($inst['qr_code_text'] ?? ''));
                $pairing = trim((string)($inst['pairing_code'] ?? ''));
                if ($qrBase64 !== '' && stripos($qrBase64, 'data:image') !== 0) {
                    $qrBase64 = 'data:image/png;base64,' . $qrBase64;
                }
                ?>
                <div class="wm-instance <?= $isActive ? 'active' : '' ?>">
                    <div class="wm-instance-top">
                        <div>
                            <div class="wm-name"><?= wh_h((string)$inst['name']) ?></div>
                            <div class="wm-meta"><?= wh_h((string)$inst['instance_key']) ?></div>
                            <?php if (!empty($inst['phone_number'])): ?>
                                <div class="wm-meta">Telefone: <?= wh_h((string)$inst['phone_number']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div><?= wh_status_badge((string)$inst['status']) ?></div>
                    </div>

                    <div class="wm-actions">
                        <form method="post">
                            <input type="hidden" name="action" value="connect_instance">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-info btn-sm" type="submit">Gerar QR</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="action" value="refresh_status">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Atualizar status</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Remover esta instancia apenas do painel local? A instancia remota nao sera deletada nesta fase.');">
                            <input type="hidden" name="action" value="delete_local">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Remover local</button>
                        </form>
                    </div>

                    <?php if ($qrBase64 !== '' || $qrText !== '' || $pairing !== '' || !empty($inst['last_error'])): ?>
                        <div class="wm-qrbox">
                            <div class="wm-qr">
                                <?php if ($qrBase64 !== ''): ?>
                                    <img src="<?= wh_h($qrBase64) ?>" alt="QR Code WhatsApp">
                                <?php elseif ($qrText !== ''): ?>
                                    <canvas class="wm-qrcode" data-qr="<?= wh_h($qrText) ?>"></canvas>
                                <?php else: ?>
                                    <span class="text-muted text-xs">QR indisponivel</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="wm-kv">
                                    <b>Status</b><span><?= wh_h((string)$inst['status']) ?></span>
                                    <b>Pairing</b><span><?= wh_h($pairing ?: '-') ?></span>
                                    <b>Atualizado</b><span><?= wh_h((string)($inst['updated_at'] ?? '-')) ?></span>
                                    <b>Conectado em</b><span><?= wh_h((string)($inst['last_connected_at'] ?? '-')) ?></span>
                                </div>
                                <?php if (!empty($inst['last_error'])): ?>
                                    <div class="alert alert-error mt-3"><?= wh_h((string)$inst['last_error']) ?></div>
                                <?php endif; ?>
                                <?php if ($qrText !== ''): ?>
                                    <div class="wm-help mt-3">Se o QR renderizado nao funcionar, use o codigo retornado pela Evolution para diagnostico:</div>
                                    <div class="wm-code mt-2"><?= wh_h(substr($qrText, 0, 1400)) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="wm-card wm-full">
        <h2>Webhook de grupos</h2>
        <div class="wm-card-sub">Recebe eventos <span class="code">GROUP_PARTICIPANTS_UPDATE</span>, interpreta entrada/saida/remocao e dispara gatilhos apenas quando encontra o aluno pelo telefone.</div>

        <div class="wm-danger-note">
            Configure primeiro em grupo de teste. O endpoint abaixo responde 200 e registra o payload para analisarmos o formato real da Evolution API.
        </div>

        <div class="form-group">
            <label class="form-label">URL do webhook</label>
            <div class="wm-url-row">
                <input id="wm-webhook-url" type="text" readonly value="<?= wh_h($webhookUrl) ?>">
                <button class="btn btn-ghost btn-sm" type="button" onclick="copyWebhookUrl()">Copiar</button>
            </div>
        </div>

        <form method="post" class="wm-actions">
            <input type="hidden" name="action" value="set_group_webhook">
            <div style="min-width:260px;flex:1;max-width:420px">
                <label class="form-label">Chave da instancia na Evolution</label>
                <input type="text" name="webhook_instance_key" value="<?= wh_h($webhookInstanceKey) ?>" placeholder="monitor01">
            </div>
            <button class="btn btn-primary" type="submit">Configurar webhook na Evolution</button>
        </form>
    </div>

    <div class="wm-grid wm-full">
        <div class="wm-card">
            <h2>Blacklist</h2>
            <div class="wm-card-sub">Números cadastrados aqui geram alerta quando entram em grupo monitorado. Nenhuma remoção automática é executada.</div>

            <form method="post">
                <input type="hidden" name="action" value="add_blacklist_number">
                <div class="form-group">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="blacklist_phone" placeholder="5522999999999">
                </div>
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <input type="text" name="blacklist_reason" placeholder="Spam, teste, bloqueio manual...">
                </div>
                <button class="btn btn-primary" type="submit">Adicionar na blacklist</button>
            </form>

            <?php if (!$blacklistRows): ?>
                <div class="text-muted text-sm mt-3">Nenhum número na blacklist ainda.</div>
            <?php else: ?>
                <div class="table-wrap mt-3">
                    <table class="wm-log-table">
                        <thead>
                            <tr>
                                <th>Telefone</th>
                                <th>Status</th>
                                <th>Motivo</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($blacklistRows as $b): ?>
                            <tr>
                                <td><?= wh_h((string)$b['phone_number']) ?></td>
                                <td><?= (int)$b['is_active'] === 1 ? '<span class="badge badge-danger">Ativo</span>' : '<span class="badge badge-neutral">Inativo</span>' ?></td>
                                <td><?= wh_h((string)($b['reason'] ?? '-')) ?></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_blacklist_number">
                                        <input type="hidden" name="blacklist_id" value="<?= (int)$b['id'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= (int)$b['is_active'] === 1 ? 'Desativar' : 'Ativar' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="wm-card">
            <h2>Grupos detectados</h2>
            <div class="wm-card-sub">Grupos vistos nos webhooks recebidos. Todo grupo novo entra como considerado por padrao; marque como ignorado para o sistema nao aplicar tags, blacklist nem gatilhos nele.</div>
            <form method="post" class="wm-actions" style="margin-bottom:12px">
                <input type="hidden" name="action" value="refresh_group_names">
                <button class="btn btn-ghost btn-sm" type="submit">Atualizar nomes dos grupos</button>
            </form>

            <?php if (!$groupRows): ?>
                <div class="text-muted text-sm">Nenhum grupo detectado ainda.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="wm-log-table">
                        <thead>
                            <tr>
                                <th>Grupo</th>
                                <th>Instância</th>
                                <th>Escopo</th>
                                <th>Eventos</th>
                                <th>Blacklist</th>
                                <th>Último evento</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($groupRows as $g): ?>
                            <tr>
                                <td>
                                    <div class="wm-group-cell">
                                        <div class="wm-group-avatar">
                                            <?php if (!empty($g['picture_url'])): ?>
                                                <img src="<?= wh_h((string)$g['picture_url']) ?>" alt="">
                                            <?php else: ?>
                                                <?= wh_h(substr((string)($g['group_name'] ?: $g['group_id']), 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php if (!empty($g['group_name'])): ?>
                                                <div><?= wh_h((string)$g['group_name']) ?></div>
                                                <div class="text-xs text-muted"><?= wh_h((string)$g['group_id']) ?></div>
                                            <?php else: ?>
                                                <div><?= wh_h((string)$g['group_id']) ?></div>
                                                <div class="text-xs text-muted">Nome ainda nao carregado</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= wh_h((string)($g['instance_key'] ?? '-')) ?></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_group_ignored">
                                        <input type="hidden" name="group_id" value="<?= wh_h((string)$g['group_id']) ?>">
                                        <label style="display:flex;align-items:center;gap:8px;white-space:nowrap">
                                            <input type="checkbox" onchange="this.form.submit()" <?= (int)($g['is_ignored'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            <span><?= (int)($g['is_ignored'] ?? 0) === 1 ? 'Ignorado' : 'Considerado' ?></span>
                                        </label>
                                    </form>
                                </td>
                                <td><?= (int)($g['total_events'] ?? 0) ?></td>
                                <td><?= (int)($g['total_blacklist'] ?? 0) ?></td>
                                <td style="white-space:nowrap"><?= wh_h((string)($g['last_seen_at'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="wm-card wm-full">
        <h2>Payloads recebidos</h2>
        <div class="wm-card-sub">Ultimos 80 eventos recebidos em <span class="code">public/whatsapp_webhook.php</span>.</div>
        <form method="post" class="wm-actions" style="margin-bottom:12px">
            <input type="hidden" name="action" value="refresh_group_names">
            <button class="btn btn-ghost btn-sm" type="submit">Atualizar nomes dos grupos</button>
            <button class="btn btn-ghost btn-sm" name="action" value="backfill_event_users" type="submit">Reprocessar alunos antigos</button>
            <button class="btn btn-ghost btn-sm" name="action" value="apply_backfill_tags" type="submit" onclick="return confirm('Aplicar tags nos alunos ja identificados pelos eventos antigos? Isso nao dispara Webhooks nem SuperFuncionario.');">Aplicar tags retroativas</button>
        </form>

        <?php if (!$rawLogs): ?>
            <div class="text-muted text-sm">Nenhum payload recebido ainda. Configure o webhook e faca um teste de entrada/saida em grupo.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="wm-log-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Recebido</th>
                            <th>Token</th>
                            <th>Evento</th>
                            <th>Instancia</th>
                            <th>Grupo</th>
                            <th>Evento</th>
                            <th>Telefone</th>
                            <th>Aluno</th>
                            <th>Blacklist</th>
                            <th>Gatilho</th>
                            <th>Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rawLogs as $log): ?>
                        <?php
                        $phone = (string)($log['participant_phone'] ?? '');
                        if ($phone === '') $phone = wh_phone_from_payload((string)$log['payload_raw'], (string)($log['participant_number'] ?? ''));
                        $userName = trim((string)($log['user_nome'] ?? ''));
                        $userId = (int)($log['user_id'] ?? 0);
                        ?>
                        <tr>
                            <td><?= (int)$log['id'] ?></td>
                            <td style="white-space:nowrap"><?= wh_h((string)$log['received_at']) ?></td>
                            <td><?= (int)$log['token_ok'] === 1 ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Falhou</span>' ?></td>
                            <td><?= wh_h((string)($log['event_type'] ?? '-')) ?></td>
                            <td><?= wh_h((string)($log['instance_key'] ?? '-')) ?></td>
                            <td>
                                <div class="wm-group-cell">
                                    <div class="wm-group-avatar">
                                        <?php if (!empty($log['group_picture_url'])): ?>
                                            <img src="<?= wh_h((string)$log['group_picture_url']) ?>" alt="">
                                        <?php else: ?>
                                            <?= wh_h(substr((string)($log['group_name'] ?: ($log['group_id'] ?? 'G')), 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if (!empty($log['group_name'])): ?>
                                            <div><?= wh_h((string)$log['group_name']) ?></div>
                                            <div class="text-xs text-muted"><?= wh_h((string)($log['group_id'] ?? '-')) ?></div>
                                        <?php else: ?>
                                            <div><?= wh_h((string)($log['group_id'] ?? '-')) ?></div>
                                        <?php endif; ?>
                                        <?php if ((int)($log['group_is_ignored'] ?? 0) === 1): ?>
                                            <div class="text-xs text-muted">Grupo ignorado</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div><?= wh_h(wh_event_label((string)($log['interpreted_event'] ?? ''), (string)($log['action'] ?? ''))) ?></div>
                                <div class="text-xs text-muted"><?= wh_h((string)($log['action'] ?? '-')) ?></div>
                            </td>
                            <td>
                                <div><?= wh_h($phone ?: '-') ?></div>
                                <?php if (!empty($log['participant_id'])): ?><div class="text-xs text-muted"><?= wh_h((string)$log['participant_id']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($userId > 0): ?>
                                    <a href="aluno_editar.php?id=<?= $userId ?>"><?= wh_h($userName !== '' ? $userName : ('Aluno #' . $userId)) ?></a>
                                    <div class="text-xs text-muted"><?= wh_h((string)($log['user_email'] ?? '')) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">Nao encontrado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)($log['is_blacklisted'] ?? 0) === 1): ?>
                                    <span class="badge badge-danger">Detectada</span>
                                    <div class="text-xs text-muted"><?= wh_h((string)($log['blacklist_reason'] ?? '')) ?></div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= wh_h(wh_trigger_label((string)($log['trigger_status'] ?? ''))) ?></div>
                                <?php if (!empty($log['trigger_error'])): ?><div class="text-xs text-muted"><?= wh_h(substr((string)$log['trigger_error'], 0, 160)) ?></div><?php endif; ?>
                            </td>
                            <td><div class="wm-payload"><?= wh_h(substr((string)$log['payload_raw'], 0, 2500)) ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
document.querySelectorAll('.wm-qrcode').forEach(function(canvas) {
    var text = canvas.getAttribute('data-qr') || '';
    if (!text || !window.QRCode) return;
    window.QRCode.toCanvas(canvas, text, { width: 198, margin: 1 }, function(err) {
        if (err) canvas.replaceWith(document.createTextNode('Falha ao renderizar QR'));
    });
});
function copyWebhookUrl() {
    var input = document.getElementById('wm-webhook-url');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard && navigator.clipboard.writeText
        ? navigator.clipboard.writeText(input.value)
        : document.execCommand('copy');
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
