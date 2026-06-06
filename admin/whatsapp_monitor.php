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
                throw new RuntimeException('Falha ao configurar webhook: ' . ($res['error'] ?: $res['raw']));
            }
            set_setting('evolution_webhook_instance_key', $instanceKey);
            header('Location: whatsapp_monitor.php?webhook_set=1');
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

$cfg = evolution_get_config();
$instances = $pdo->query("SELECT * FROM whatsapp_instances ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeId = (int)($_GET['qr'] ?? $_GET['created'] ?? $_GET['status'] ?? 0);
$webhookToken = evolution_get_webhook_token();
$webhookUrl = rtrim(BASE_URL, '/') . '/whatsapp_webhook.php?t=' . $webhookToken;
$webhookInstanceKey = (string)get_setting('evolution_webhook_instance_key', 'monitor01');
$rawLogs = [];
try {
    $rawLogs = $pdo->query("
        SELECT id, token_ok, event_type, instance_key, group_id, action, participant_number, payload_raw, source_ip, received_at
        FROM whatsapp_webhook_raw_logs
        ORDER BY id DESC
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
@media(max-width:1000px){ .wm-grid,.wm-qrbox{grid-template-columns:1fr}.wm-row{grid-template-columns:1fr}.wm-qr{width:100%;max-width:260px} }
</style>

<div class="wm-wrap">
    <div class="wm-head">
        <div>
            <h1>WhatsApp Monitor</h1>
            <p>Fase 1: conectar uma instancia da Evolution API por QR Code. Esta tela nao recebe webhooks, nao monitora grupos e nao remove participantes.</p>
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
        <h2>Webhook de grupos - Fase 2</h2>
        <div class="wm-card-sub">Recebe apenas eventos <span class="code">GROUP_PARTICIPANTS_UPDATE</span> e salva payload bruto. Nenhuma acao automatica e executada.</div>

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

    <div class="wm-card wm-full">
        <h2>Payloads recebidos</h2>
        <div class="wm-card-sub">Ultimos 80 eventos recebidos em <span class="code">public/whatsapp_webhook.php</span>.</div>

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
                            <th>Acao</th>
                            <th>Participante</th>
                            <th>Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rawLogs as $log): ?>
                        <tr>
                            <td><?= (int)$log['id'] ?></td>
                            <td style="white-space:nowrap"><?= wh_h((string)$log['received_at']) ?></td>
                            <td><?= (int)$log['token_ok'] === 1 ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Falhou</span>' ?></td>
                            <td><?= wh_h((string)($log['event_type'] ?? '-')) ?></td>
                            <td><?= wh_h((string)($log['instance_key'] ?? '-')) ?></td>
                            <td><?= wh_h((string)($log['group_id'] ?? '-')) ?></td>
                            <td><?= wh_h((string)($log['action'] ?? '-')) ?></td>
                            <td><?= wh_h((string)($log['participant_number'] ?? '-')) ?></td>
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
