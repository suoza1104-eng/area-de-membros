<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/push_notifications.php';
require_once __DIR__ . '/../app/push_flows.php';
proteger_admin();
$pdo = getPDO();
push_ensure_schema($pdo);
push_flows_ensure_schema($pdo);

$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['notificacoes']['escrever']);
}
if (empty($_SESSION['push_admin_csrf'])) $_SESSION['push_admin_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['push_admin_csrf'];
$message = '';
$error = '';
$openSettings = false;

function pn_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pn_pct(int $value, int $total): string { return number_format($total > 0 ? $value / $total * 100 : 0, 1, ',', '.') . '%'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Seu usuário não possui permissão de escrita.');
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        $action = (string)($_POST['action'] ?? '');
        $adminName = (string)($_SESSION['equipe_nome'] ?? 'Administrador');
        if ($action === 'flow_create') {
            $flowId = push_flow_create($pdo, (string)($_POST['name'] ?? 'Novo fluxo'), $adminName);
            header('Location: notificacao_fluxo.php?id=' . $flowId);
            exit;
        }
        if ($action === 'flow_clone') {
            $flowId = push_flow_clone($pdo, (int)($_POST['flow_id'] ?? 0), $adminName);
            header('Location: notificacao_fluxo.php?id=' . $flowId);
            exit;
        }
        if ($action === 'flow_toggle') {
            push_flow_set_status($pdo, (int)($_POST['flow_id'] ?? 0), (string)($_POST['status'] ?? ''), $adminName);
            header('Location: notificacoes.php?flow_saved=1');
            exit;
        }
        if ($action === 'flow_delete') {
            push_flow_delete($pdo, (int)($_POST['flow_id'] ?? 0));
            header('Location: notificacoes.php?flow_deleted=1');
            exit;
        }
        if ($action === 'save_config') {
            $fields = [
                'push_app_name'=>'app_name','push_firebase_api_key'=>'api_key','push_firebase_auth_domain'=>'auth_domain',
                'push_firebase_project_id'=>'project_id','push_firebase_storage_bucket'=>'storage_bucket',
                'push_firebase_sender_id'=>'sender_id','push_firebase_app_id'=>'app_id','push_firebase_vapid_key'=>'vapid_key',
            ];
            foreach ($fields as $setting => $postKey) set_setting($setting, trim((string)($_POST[$postKey] ?? '')));
            $serviceJson = trim((string)($_POST['service_account_json'] ?? ''));
            if ($serviceJson !== '') {
                $decoded = json_decode($serviceJson, true);
                if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) throw new RuntimeException('JSON da conta de serviço inválido.');
                set_setting('push_firebase_service_account', $serviceJson);
            }
            if (!empty($_FILES['app_icon']['tmp_name'])) {
                if ((int)($_FILES['app_icon']['size'] ?? 0) > 2 * 1024 * 1024) throw new RuntimeException('O ícone do aplicativo deve ter no máximo 2 MB.');
                $tmp = (string)$_FILES['app_icon']['tmp_name'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $extensions = ['image/png'=>'png','image/webp'=>'webp'];
                if (!isset($extensions[$mime])) throw new RuntimeException('Envie o ícone em PNG ou WebP.');
                $dimensions = @getimagesize($tmp);
                if (!$dimensions || (int)$dimensions[0] !== (int)$dimensions[1]) throw new RuntimeException('O ícone precisa ser quadrado. Recomendamos 512 × 512 px.');
                if ((int)$dimensions[0] < 192) throw new RuntimeException('O ícone precisa ter pelo menos 192 × 192 px. Recomendamos 512 × 512 px.');
                if ((int)$dimensions[0] > 2048) throw new RuntimeException('O ícone deve ter no máximo 2048 × 2048 px. Recomendamos 512 × 512 px.');
                $dir = __DIR__ . '/../public/uploads/pwa';
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar a pasta do ícone.');
                $filename = 'app-icon-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($tmp, $dir . '/' . $filename)) throw new RuntimeException('Não foi possível salvar o ícone do aplicativo.');
                set_setting('push_app_icon_url', 'uploads/pwa/' . $filename);
            }
            header('Location: notificacoes.php?saved=1');
            exit;
        }
        if ($action === 'save_app_settings') {
            $textFields = [
                'push_tag_installed'=>'tag_installed','push_tag_authorized'=>'tag_authorized','push_tag_uninstalled'=>'tag_uninstalled',
                'push_popup_title'=>'popup_title','push_popup_text'=>'popup_text','push_popup_button_label'=>'popup_button_label',
                'push_popup_image_url'=>'popup_image_url',
            ];
            foreach ($textFields as $setting => $postKey) set_setting($setting, trim((string)($_POST[$postKey] ?? '')));
            foreach ([
                'push_uninstall_remove_installed_tag'=>'uninstall_remove_installed_tag','push_popup_enabled'=>'popup_enabled',
                'push_popup_show_non_chrome'=>'popup_show_non_chrome',
                'push_popup_show_apple'=>'popup_show_apple','push_popup_close_enabled'=>'popup_close_enabled',
                'push_popup_pulse_enabled'=>'popup_pulse_enabled','push_popup_request_notifications'=>'popup_request_notifications',
            ] as $setting => $postKey) set_setting($setting, isset($_POST[$postKey]) ? '1' : '0');
            if (!empty($_FILES['popup_image']['tmp_name'])) {
                if ((int)($_FILES['popup_image']['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('A imagem deve ter no máximo 5 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$_FILES['popup_image']['tmp_name']);
                $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!isset($extensions[$mime])) throw new RuntimeException('Envie uma imagem JPG, PNG ou WebP.');
                $dir = __DIR__ . '/../public/uploads/pwa';
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar a pasta da imagem.');
                $filename = 'popup-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                if (!move_uploaded_file((string)$_FILES['popup_image']['tmp_name'], $dir . '/' . $filename)) throw new RuntimeException('Não foi possível salvar a imagem.');
                set_setting('push_popup_image_url', 'uploads/pwa/' . $filename);
            }
            header('Location: notificacoes.php?app_saved=1');
            exit;
        }
        if ($action === 'send_test') {
            if (!push_is_configured()) throw new RuntimeException('Configure todas as credenciais do Firebase antes do teste.');
            $deviceId = max(0, (int)($_POST['device_id'] ?? 0));
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $clickUrl = trim((string)($_POST['click_url'] ?? 'trilha.php')) ?: 'trilha.php';
            if ($deviceId <= 0) throw new RuntimeException('Selecione um dispositivo conectado.');
            if ($title === '' || mb_strlen($title) > 150) throw new RuntimeException('Informe um título de até 150 caracteres.');
            if ($body === '' || mb_strlen($body) > 500) throw new RuntimeException('Informe uma mensagem de até 500 caracteres.');
            if (strpos($clickUrl, '://') !== false || str_starts_with($clickUrl, '//')) throw new RuntimeException('Use um link interno, como trilha.php.');
            $st = $pdo->prepare("SELECT d.*,u.nome,u.email FROM push_devices d LEFT JOIN users u ON u.id=d.user_id WHERE d.id=:id AND d.status='active' AND d.notification_permission='granted' LIMIT 1");
            $st->execute(['id'=>$deviceId]);
            $device = $st->fetch(PDO::FETCH_ASSOC);
            if (!$device) throw new RuntimeException('Dispositivo não está ativo ou não autorizou notificações.');
            $pdo->prepare("INSERT INTO push_notifications (title,body,click_url,target_type,target_value,total_targets,status,created_by) VALUES (:title,:body,:url,'device_test',:target,1,'processing',:admin)")
                ->execute(['title'=>$title,'body'=>$body,'url'=>$clickUrl,'target'=>(string)$deviceId,'admin'=>(string)($_SESSION['equipe_nome']??'Administrador')]);
            $notificationId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO push_delivery_logs (notification_id,device_id,user_id,status) VALUES (:notification,:device,:user,'queued')")
                ->execute(['notification'=>$notificationId,'device'=>$deviceId,'user'=>(int)$device['user_id']]);
            $deliveryId = (int)$pdo->lastInsertId();
            try {
                $result = push_send_to_device($pdo,$device,$notificationId,$deliveryId,$title,$body,$clickUrl);
                $accepted = !empty($result['accepted']) ? 1 : 0;
                $failed = $accepted ? 0 : 1;
                $pdo->prepare("UPDATE push_notifications SET accepted_count=:accepted,failed_count=:failed,status=:status,finished_at=NOW() WHERE id=:id")
                    ->execute(['accepted'=>$accepted,'failed'=>$failed,'status'=>$accepted?'sent':'failed','id'=>$notificationId]);
                if (!$accepted) throw new RuntimeException((string)($result['error'] ?? 'Firebase recusou a notificação.'));
                $message = 'Notificação aceita pelo Firebase e enviada ao dispositivo selecionado.';
            } catch (Throwable $sendError) {
                $pdo->prepare("UPDATE push_delivery_logs SET status=IF(status='queued','failed',status),error_message=COALESCE(error_message,:error),sent_at=COALESCE(sent_at,NOW()) WHERE id=:id")
                    ->execute(['error'=>substr($sendError->getMessage(),0,500),'id'=>$deliveryId]);
                $pdo->prepare("UPDATE push_notifications SET failed_count=1,status='failed',finished_at=NOW() WHERE id=:id")
                    ->execute(['id'=>$notificationId]);
                throw $sendError;
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $openSettings = in_array((string)($_POST['action'] ?? ''), ['save_config','save_app_settings'], true);
    }
}
if (!empty($_GET['saved'])) $message = 'Configuração salva.';

if (!empty($_GET['app_saved'])) $message = 'Configurações do aplicativo salvas.';
if (!empty($_GET['flow_saved'])) $message = 'Fluxo atualizado.';
if (!empty($_GET['flow_deleted'])) $message = 'Fluxo excluído.';

$publicConfig = push_public_config();
$serviceConfigured = trim((string)(get_setting('push_firebase_service_account', '') ?? '')) !== '';
$configured = push_is_configured();
$appName = (string)(get_setting('push_app_name', 'Área de Membros') ?? 'Área de Membros');
$appIcon = push_app_icon_url();
$appIconPreview = preg_match('~^(?:https?:)?//|^data:|^/~i', $appIcon) ? $appIcon : '../public/' . ltrim($appIcon, '/');
$appSettings = [
    'tag_installed'=>(string)(get_setting('push_tag_installed','')??''),
    'tag_authorized'=>(string)(get_setting('push_tag_authorized','')??''),
    'tag_uninstalled'=>(string)(get_setting('push_tag_uninstalled','')??''),
    'uninstall_remove_installed_tag'=>push_setting_enabled('push_uninstall_remove_installed_tag',true),
    'popup_enabled'=>push_setting_enabled('push_popup_enabled',true),
    'popup_show_non_chrome'=>push_setting_enabled('push_popup_show_non_chrome',true),
    'popup_show_apple'=>push_setting_enabled('push_popup_show_apple',false),
    'popup_close_enabled'=>push_setting_enabled('push_popup_close_enabled',true),
    'popup_pulse_enabled'=>push_setting_enabled('push_popup_pulse_enabled',true),
    'popup_request_notifications'=>push_setting_enabled('push_popup_request_notifications',true),
    'popup_title'=>(string)(get_setting('push_popup_title','Assista às aulas com mais qualidade')??''),
    'popup_text'=>(string)(get_setting('push_popup_text','Instale o aplicativo para ter reprodução mais estável, acesso rápido e avisos importantes no celular.')??''),
    'popup_button_label'=>(string)(get_setting('push_popup_button_label','Instalar aplicativo agora')??''),
    'popup_image_url'=>(string)(get_setting('push_popup_image_url','pwa-install-phone.jpg')??''),
];

$kpi = ['total'=>0,'installed'=>0,'uninstalled'=>0,'active24'=>0,'receiving'=>0];
try {
    $kpiRow = $pdo->query("SELECT COUNT(*) total,
        SUM(installed_at IS NOT NULL) installed,
        SUM(status='uninstalled') uninstalled,
        SUM(last_seen_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)) active24,
        SUM(status='active' AND notification_permission='granted' AND token IS NOT NULL) receiving
        FROM push_devices")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($kpi as $key=>$unused) $kpi[$key]=(int)($kpiRow[$key]??0);
} catch (Throwable $e) {}

$devices = $pdo->query("SELECT d.*,u.nome,u.email FROM push_devices d LEFT JOIN users u ON u.id=d.user_id ORDER BY d.last_seen_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeDevices = array_values(array_filter($devices, static fn($d) => ($d['status']??'')==='active' && ($d['notification_permission']??'')==='granted' && !empty($d['token'])));
$logs = $pdo->query("SELECT l.*,n.title,n.body,u.nome,u.email FROM push_delivery_logs l JOIN push_notifications n ON n.id=l.notification_id LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$flows = $pdo->query("SELECT f.*,v.version_number current_version_number FROM push_flows f LEFT JOIN push_flow_versions v ON v.id=f.current_version_id ORDER BY f.updated_at DESC,f.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$previewImage = trim($appSettings['popup_image_url']);
if ($previewImage === '') $previewImage = 'pwa-install-phone.jpg';
if (!preg_match('~^(?:https?:)?//|^data:|^/~i', $previewImage)) $previewImage = '../public/' . ltrim($previewImage, '/');

$menu = 'notificacoes';
$page_title = 'Notificações do aplicativo';
include __DIR__ . '/_header.php';
?>
<style>
.pn{display:flex;flex-direction:column;gap:16px}.pn-grid{display:grid;grid-template-columns:repeat(5,minmax(145px,1fr));gap:10px}.pn-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:15px}.pn-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.pn-kpi strong{display:block;font-size:24px;margin-top:5px}.pn-kpi span{font-size:11px;color:var(--muted)}.pn-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.pn-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}.pn-card h2{font-size:15px;margin:0 0 4px}.pn-card>p{font-size:11px;color:var(--muted);margin:0 0 14px}.pn-form{display:grid;gap:11px}.pn-form label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}.pn-form input,.pn-form textarea,.pn-form select{width:100%;margin-top:4px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px;font:inherit}.pn-form textarea{min-height:90px;resize:vertical}.pn-status{padding:11px 13px;border-radius:9px;font-size:12px}.pn-ok{background:var(--success-dim);color:#86efac}.pn-error{background:var(--danger-dim);color:#fca5a5}.pn-table{overflow:auto}.pn-table table{min-width:900px}.pn-pill{display:inline-flex;padding:2px 7px;border-radius:999px;font-size:10px;background:var(--bg-hover);color:var(--muted)}.pn-pill.active,.pn-pill.accepted,.pn-pill.clicked{background:var(--success-dim);color:#86efac}.pn-pill.failed,.pn-pill.uninstalled,.pn-pill.revoked{background:var(--danger-dim);color:#fca5a5}@media(max-width:1000px){.pn-grid{grid-template-columns:repeat(2,1fr)}.pn-cols{grid-template-columns:1fr}}@media(max-width:560px){.pn-grid{grid-template-columns:1fr}}
.pn-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.pn-check{display:flex!important;align-items:flex-start;gap:8px;padding:10px;border:1px solid var(--border);border-radius:9px;color:var(--text)!important;text-transform:none!important;font-size:11px!important}.pn-check input{width:auto!important;margin:1px 0 0!important}.pn-help{font-size:10px;color:var(--muted);line-height:1.45}@media(max-width:700px){.pn-checks{grid-template-columns:1fr}}
.pn-preview-actions{display:flex;flex-wrap:wrap;gap:8px}.pn-preview-overlay{display:none;position:fixed;inset:0;z-index:12000;padding:18px;background:rgba(2,6,15,.9);align-items:center;justify-content:center}.pn-preview-overlay.open{display:flex}.pn-preview-phone{position:relative;width:min(880px,100%);max-height:calc(100vh - 36px);display:grid;grid-template-columns:minmax(280px,42%) minmax(0,1fr);overflow:hidden;border:1px solid rgba(250,204,21,.35);border-radius:26px;background:radial-gradient(circle at 92% 8%,rgba(250,204,21,.17),transparent 35%),linear-gradient(145deg,#101827,#080e1a 68%);box-shadow:0 28px 100px rgba(0,0,0,.75)}.pn-preview-timer{position:absolute;top:0;left:0;right:0;z-index:3;height:4px;background:rgba(255,255,255,.08)}.pn-preview-timer span{display:block;width:100%;height:100%;background:#facc15;transform-origin:left}.pn-preview-overlay.open .pn-preview-timer span{animation:pnPreviewTimer 20s linear forwards}.pn-preview-image{min-height:510px;background:#060b15}.pn-preview-image img{width:100%;height:100%;display:block;object-fit:contain}.pn-preview-content{display:flex;flex-direction:column;justify-content:center;padding:48px 42px;color:#fff}.pn-preview-eyebrow{align-self:flex-start;padding:5px 9px;border:1px solid rgba(250,204,21,.25);border-radius:999px;background:rgba(250,204,21,.08);color:#fde047;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.pn-preview-content h3{margin:15px 0 10px;font-size:32px;line-height:1.08}.pn-preview-content p{margin:0;color:#aeb9cc;font-size:14px;line-height:1.6}.pn-preview-benefits{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:22px 0;color:#e2e8f0;font-size:12px;font-weight:650}.pn-preview-benefits span:before{content:'✓';margin-right:7px;color:#facc15}.pn-preview-cta{width:100%;border:1px solid rgba(255,255,255,.3);border-radius:14px;padding:15px 18px;background:linear-gradient(135deg,#fde047,#eab308);color:#171301;font-size:15px;font-weight:900}.pn-preview-close{position:absolute;top:14px;right:14px;z-index:4;display:flex;align-items:center;gap:7px;border:1px solid #fde047;border-radius:999px;padding:9px 13px;background:#facc15;color:#171301;font-size:12px;font-weight:900;cursor:pointer;box-shadow:0 5px 20px rgba(250,204,21,.3)}.pn-preview-close b{font-size:18px;line-height:.8}@keyframes pnPreviewTimer{from{transform:scaleX(1)}to{transform:scaleX(0)}}@media(max-width:720px){.pn-preview-overlay{padding:0}.pn-preview-phone{min-height:100dvh;max-height:100dvh;border:0;border-radius:0;grid-template-columns:1fr;grid-template-rows:minmax(230px,38dvh) 1fr;overflow-y:auto}.pn-preview-image{min-height:0}.pn-preview-content{justify-content:flex-start;padding:25px 22px}.pn-preview-content h3{font-size:27px}.pn-preview-close{top:12px;right:12px;padding:9px 11px}}
.pn-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.pn-toolbar-copy h1{margin:0;font-size:20px}.pn-toolbar-copy p{margin:4px 0 0;color:var(--muted);font-size:11px}.pn-gear{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(250,204,21,.35);border-radius:10px;padding:10px 13px;background:rgba(250,204,21,.08);color:#fde047;font-weight:800;cursor:pointer}.pn-gear svg{width:17px;height:17px}.pn-test-card{grid-column:1/-1}.pn-settings-source{display:none}.pn-settings-source.pn-settings-mounted{display:block}.pn-settings-overlay{display:none;position:fixed;inset:0;z-index:11000;background:rgba(2,6,15,.72);backdrop-filter:blur(5px)}.pn-settings-overlay.open{display:block}.pn-settings-panel{position:absolute;top:0;right:0;width:min(760px,100%);height:100%;overflow:auto;background:var(--bg);border-left:1px solid var(--border);box-shadow:-24px 0 70px rgba(0,0,0,.5)}.pn-settings-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--border);background:var(--bg)}.pn-settings-head h2{margin:0;font-size:17px}.pn-settings-close{border:1px solid var(--border);border-radius:9px;padding:8px 11px;background:var(--bg-card);color:var(--text);cursor:pointer}.pn-settings-body{display:grid;gap:16px;padding:18px}.pn-settings-body .pn-card{margin:0}.pn-icon-row{display:grid;grid-template-columns:76px 1fr;align-items:center;gap:13px;padding:12px;border:1px solid var(--border);border-radius:11px;background:var(--bg)}.pn-icon-preview{width:76px;height:76px;border-radius:18px;object-fit:cover;background:#fff}.pn-icon-copy strong{display:block;font-size:12px}.pn-icon-copy span{display:block;margin-top:5px;color:var(--muted);font-size:10px;line-height:1.5}@media(max-width:600px){.pn-toolbar{align-items:flex-start}.pn-gear span{display:none}.pn-icon-row{grid-template-columns:62px 1fr}.pn-icon-preview{width:62px;height:62px}}
.pn-flow-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.pn-flow-head h2{margin:0;font-size:15px}.pn-flow-head p{margin:3px 0 0;color:var(--muted);font-size:11px}.pn-flow-list{display:grid;gap:8px}.pn-flow-row{display:grid;grid-template-columns:minmax(220px,1fr) 120px 120px 160px auto;align-items:center;gap:12px;padding:12px;border:1px solid var(--border);border-radius:11px;background:var(--bg)}.pn-flow-name strong{display:block;font-size:13px}.pn-flow-name span,.pn-flow-meta{color:var(--muted);font-size:10px}.pn-flow-status{display:inline-flex;justify-content:center;padding:4px 8px;border-radius:999px;background:var(--bg-hover);color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.pn-flow-status.active{background:var(--success-dim);color:#86efac}.pn-flow-status.paused{background:var(--warning-dim);color:#fcd34d}.pn-flow-actions{display:flex;justify-content:flex-end;gap:6px;flex-wrap:wrap}.pn-flow-actions form{display:inline}.pn-flow-empty{padding:28px;text-align:center;border:1px dashed var(--border-light);border-radius:11px;color:var(--muted);font-size:12px}@media(max-width:1050px){.pn-flow-row{grid-template-columns:1fr 100px auto}.pn-flow-version,.pn-flow-date{display:none}}@media(max-width:650px){.pn-flow-head{align-items:flex-start;flex-direction:column}.pn-flow-row{grid-template-columns:1fr}.pn-flow-actions{justify-content:flex-start}}
</style>
<div class="pn">
    <?php if($message):?><div class="pn-status pn-ok"><?=pn_h($message)?></div><?php endif;?>
    <?php if($error):?><div class="pn-status pn-error"><?=pn_h($error)?></div><?php endif;?>
    <div class="pn-toolbar">
        <div class="pn-toolbar-copy"><h1>Central de notificações</h1><p>Acompanhe dispositivos, testes e entregas. Os fluxos automatizados serão adicionados na próxima etapa.</p></div>
        <button class="pn-gear" id="pnSettingsOpen" type="button" aria-haspopup="dialog"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.13.37.34.7.6 1 .3.27.68.4 1.1.4h.09v4h-.09c-.42 0-.8.13-1.1.4-.26.3-.47.63-.6 1.2Z"/></svg><span>Configurações</span></button>
    </div>
    <div class="pn-status <?=$configured?'pn-ok':'pn-error'?>">Firebase: <strong><?=$configured?'configurado':'configuração incompleta'?></strong>. URL privada para ativação no telefone: <code><?=pn_h(BASE_URL)?>/notificacoes_teste.php</code></div>

    <div class="pn-grid">
        <?php foreach([
            ['total','Dispositivos conectados'],['installed','Instalaram o app'],['uninstalled','Tokens excluídos/inativos'],['active24','Acesso nas últimas 24h'],['receiving','Recebendo notificações']
        ] as [$key,$label]):?>
        <div class="pn-kpi"><small><?=pn_h($label)?></small><strong><?=number_format($kpi[$key])?></strong><span><?=pn_pct($kpi[$key],max(1,$kpi['total']))?> dos dispositivos registrados</span></div>
        <?php endforeach;?>
    </div>

    <section class="pn-card">
        <div class="pn-flow-head"><div><h2>Fluxos de automação</h2><p>O editor e o versionamento estão ativos. A execução automática será conectada somente na etapa 3.</p></div><?php if($canWrite):?><form method="post"><input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="flow_create"><button class="btn btn-primary" type="submit">+ Criar novo fluxo</button></form><?php endif;?></div>
        <div class="pn-flow-list">
            <?php foreach($flows as $flow):?>
            <div class="pn-flow-row">
                <div class="pn-flow-name"><strong><?=pn_h($flow['name'])?></strong><span><?=pn_h($flow['description'] ?: 'Sem descrição')?></span></div>
                <div><span class="pn-flow-status <?=pn_h($flow['status'])?>"><?=pn_h($flow['status']==='active'?'Publicado':($flow['status']==='paused'?'Pausado':'Rascunho'))?></span></div>
                <div class="pn-flow-version pn-flow-meta"><?=$flow['current_version_number']?'Versão '.(int)$flow['current_version_number']:'Não publicado'?></div>
                <div class="pn-flow-date pn-flow-meta">Atualizado em <?=pn_h(date('d/m/Y H:i',strtotime($flow['updated_at'])))?></div>
                <div class="pn-flow-actions">
                    <a class="btn btn-ghost btn-xs" href="notificacao_fluxo.php?id=<?=(int)$flow['id']?>">Editar</a>
                    <?php if($canWrite):?>
                    <?php if(!empty($flow['current_version_id'])):?><form method="post"><input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="flow_toggle"><input type="hidden" name="flow_id" value="<?=(int)$flow['id']?>"><input type="hidden" name="status" value="<?=$flow['status']==='active'?'paused':'active'?>"><button class="btn btn-ghost btn-xs" type="submit"><?=$flow['status']==='active'?'Pausar':'Ativar'?></button></form><?php endif;?>
                    <form method="post"><input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="flow_clone"><input type="hidden" name="flow_id" value="<?=(int)$flow['id']?>"><button class="btn btn-ghost btn-xs" type="submit">Clonar</button></form>
                    <form method="post" onsubmit="return confirm('Excluir este fluxo e todas as versões?')"><input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="flow_delete"><input type="hidden" name="flow_id" value="<?=(int)$flow['id']?>"><button class="btn btn-danger btn-xs" type="submit">Excluir</button></form>
                    <?php endif;?>
                </div>
            </div>
            <?php endforeach;?>
            <?php if(!$flows):?><div class="pn-flow-empty">Nenhum fluxo criado. Use “Criar novo fluxo” para abrir o editor visual.</div><?php endif;?>
        </div>
    </section>

    <div class="pn-cols">
        <section class="pn-card pn-test-card">
            <h2>Enviar notificação de teste</h2><p>Envia somente para o dispositivo escolhido.</p>
            <form method="post" class="pn-form">
                <input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="send_test">
                <label>Dispositivo<select name="device_id" required><option value="">Selecione</option><?php foreach($activeDevices as $d):?><option value="<?=(int)$d['id']?>"><?=pn_h(($d['nome']?:$d['email']?:('Aluno #'.$d['user_id'])).' · '.($d['platform']?:'web').' · #'.$d['id'])?></option><?php endforeach;?></select></label>
                <label>Título<input name="title" maxlength="150" value="Nova mensagem na área de membros" required></label>
                <label>Mensagem<textarea name="body" maxlength="500" required>Este é um teste de notificação do aplicativo.</textarea></label>
                <label>Link interno ao tocar<input name="click_url" value="trilha.php" placeholder="trilha.php"></label>
                <button class="btn btn-primary" type="submit" <?=$canWrite&&$configured&&$activeDevices?'':'disabled'?>>Enviar teste</button>
            </form>
        </section>
        <section class="pn-card pn-settings-source" id="pnFirebaseSettings">
            <h2>Configuração Firebase</h2><p>Use um projeto Firebase Web e uma conta de serviço com acesso ao Cloud Messaging.</p>
            <form method="post" enctype="multipart/form-data" class="pn-form">
                <input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="save_config">
                <label>Nome do aplicativo<input name="app_name" value="<?=pn_h($appName)?>"></label>
                <div class="pn-icon-row"><img class="pn-icon-preview" src="<?=pn_h($appIconPreview)?>" alt="Ícone atual do aplicativo"><div class="pn-icon-copy"><strong>Ícone do aplicativo</strong><span>Recomendado: PNG ou WebP quadrado, 512 × 512 px, até 2 MB. Evite textos pequenos e mantenha a marca afastada das bordas.</span><input type="file" name="app_icon" accept="image/png,image/webp"></div></div>
                <label>API key<input name="api_key" value="<?=pn_h($publicConfig['apiKey'])?>"></label>
                <label>Auth domain<input name="auth_domain" value="<?=pn_h($publicConfig['authDomain'])?>"></label>
                <label>Project ID<input name="project_id" value="<?=pn_h($publicConfig['projectId'])?>"></label>
                <label>Storage bucket<input name="storage_bucket" value="<?=pn_h($publicConfig['storageBucket'])?>"></label>
                <label>Messaging sender ID<input name="sender_id" value="<?=pn_h($publicConfig['messagingSenderId'])?>"></label>
                <label>App ID<input name="app_id" value="<?=pn_h($publicConfig['appId'])?>"></label>
                <label>Chave pública VAPID<input name="vapid_key" value="<?=pn_h(push_vapid_key())?>"></label>
                <label>JSON da conta de serviço<textarea name="service_account_json" placeholder="<?=$serviceConfigured?'Configurado. Deixe vazio para preservar o segredo.':'Cole o JSON completo da conta de serviço'?>"></textarea></label>
                <button class="btn btn-primary" type="submit" <?=$canWrite?'':'disabled'?>>Salvar configuração</button>
            </form>
        </section>
    </div>

    <section class="pn-card"><h2>Dispositivos</h2><p>“Excluído” só pode ser detectado depois que o Firebase rejeitar um novo envio para o token.</p><div class="pn-table"><table><thead><tr><th>ID</th><th>Aluno</th><th>Plataforma</th><th>Instalado</th><th>Permissão</th><th>Status</th><th>Último acesso</th></tr></thead><tbody><?php foreach($devices as $d):?><tr><td>#<?=(int)$d['id']?></td><td><?=pn_h($d['nome']?:('Aluno #'.$d['user_id']))?><div class="text-xs text-muted"><?=pn_h($d['email']??'')?></div></td><td><?=pn_h($d['platform'])?><div class="text-xs text-muted"><?=pn_h($d['browser']??'')?></div></td><td><?=$d['installed_at']?pn_h(date('d/m/Y H:i',strtotime($d['installed_at']))):'Não confirmado'?></td><td><?=pn_h($d['notification_permission'])?></td><td><span class="pn-pill <?=pn_h($d['status'])?>"><?=pn_h($d['status'])?></span></td><td><?=pn_h(date('d/m/Y H:i',strtotime($d['last_seen_at'])))?></td></tr><?php endforeach;?><?php if(!$devices):?><tr><td colspan="7" class="text-muted">Nenhum dispositivo registrado.</td></tr><?php endif;?></tbody></table></div></section>
    <section class="pn-card"><h2>Logs de envio</h2><p>“Aceita” significa que o Firebase aceitou a mensagem; o clique confirma interação do aluno.</p><div class="pn-table"><table><thead><tr><th>Data</th><th>Aluno</th><th>Notificação</th><th>Status</th><th>HTTP</th><th>Clique</th><th>Erro</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?=pn_h(date('d/m/Y H:i:s',strtotime($l['created_at'])))?></td><td><?=pn_h($l['nome']?:('Aluno #'.$l['user_id']))?></td><td><strong><?=pn_h($l['title'])?></strong><div class="text-xs text-muted"><?=pn_h($l['body'])?></div></td><td><span class="pn-pill <?=pn_h($l['status'])?>"><?=pn_h($l['status'])?></span></td><td><?=pn_h($l['http_status']??'-')?></td><td><?=$l['clicked_at']?pn_h(date('d/m/Y H:i:s',strtotime($l['clicked_at']))):'-'?></td><td><?=pn_h($l['error_message']??'')?></td></tr><?php endforeach;?><?php if(!$logs):?><tr><td colspan="7" class="text-muted">Nenhum envio realizado.</td></tr><?php endif;?></tbody></table></div></section>
    <section class="pn-card pn-settings-source" id="pnSimulatorSettings">
        <h2>Simulador dos avisos</h2><p>Veja como cada popup aparece para o aluno. A simulação não instala o aplicativo nem solicita permissões reais.</p>
        <div class="pn-preview-actions">
            <button class="btn btn-primary" type="button" data-preview="install">Popup de instalação</button>
            <button class="btn" type="button" data-preview="browser">Fora do Google Chrome</button>
            <button class="btn" type="button" data-preview="live">Ativar aviso da aula ao vivo</button>
        </div>
    </section>
    <section class="pn-card pn-settings-source" id="pnAppSettings">
        <h2>Eventos, tags e popup de instalação</h2><p>Personalize a instalação e as automações disparadas em cada etapa do aplicativo.</p>
        <form method="post" enctype="multipart/form-data" class="pn-form">
            <input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="save_app_settings">
            <div class="pn-cols"><div class="pn-form">
                <label>Tag ao instalar<input name="tag_installed" value="<?=pn_h($appSettings['tag_installed'])?>" placeholder="APP_INSTALADO"></label>
                <label>Tag ao autorizar notificações<input name="tag_authorized" value="<?=pn_h($appSettings['tag_authorized'])?>" placeholder="APP_NOTIFICACOES_ATIVAS"></label>
                <label>Tag ao detectar desinstalação<input name="tag_uninstalled" value="<?=pn_h($appSettings['tag_uninstalled'])?>" placeholder="APP_DESINSTALADO"></label>
                <label class="pn-check"><input type="checkbox" name="uninstall_remove_installed_tag" <?=$appSettings['uninstall_remove_installed_tag']?'checked':''?>> Remover a tag de instalado ao detectar desinstalação</label>
                <div class="pn-help">Eventos: APP_INSTALADO, APP_NOTIFICACOES_AUTORIZADAS e APP_DESINSTALADO_DETECTADO.</div>
            </div><div class="pn-form">
                <label>Título do popup<input name="popup_title" maxlength="120" value="<?=pn_h($appSettings['popup_title'])?>"></label>
                <label>Texto do popup<textarea name="popup_text" maxlength="600"><?=pn_h($appSettings['popup_text'])?></textarea></label>
                <label>Texto do botão<input name="popup_button_label" maxlength="80" value="<?=pn_h($appSettings['popup_button_label'])?>"></label>
                <label>URL/caminho da imagem<input name="popup_image_url" value="<?=pn_h($appSettings['popup_image_url'])?>"></label>
                <label>Enviar nova imagem<input type="file" name="popup_image" accept="image/jpeg,image/png,image/webp"></label>
            </div></div>
            <div class="pn-checks"><?php foreach (['popup_enabled'=>'Ativar popup de instalação','popup_show_non_chrome'=>'Exibir orientação fora do Chrome','popup_show_apple'=>'Exibir também em aparelhos Apple','popup_close_enabled'=>'Permitir fechar o popup','popup_pulse_enabled'=>'Ativar animação do botão','popup_request_notifications'=>'Lembrar até o aluno ativar as notificações'] as $key=>$label):?><label class="pn-check"><input type="checkbox" name="<?=$key?>" <?=$appSettings[$key]?'checked':''?>> <?=pn_h($label)?></label><?php endforeach;?></div>
            <button class="btn btn-primary" type="submit" <?=$canWrite?'':'disabled'?>>Salvar eventos e popup</button>
        </form>
    </section>
</div>
<div class="pn-settings-overlay" id="pnSettings" role="dialog" aria-modal="true" aria-labelledby="pnSettingsTitle">
    <aside class="pn-settings-panel">
        <div class="pn-settings-head"><h2 id="pnSettingsTitle">Configurações do aplicativo</h2><button class="pn-settings-close" id="pnSettingsClose" type="button">Fechar ×</button></div>
        <div class="pn-settings-body" id="pnSettingsBody"><?php if($openSettings&&$error):?><div class="pn-status pn-error"><?=pn_h($error)?></div><?php endif;?></div>
    </aside>
</div>
<div class="pn-preview-overlay" id="pnPreview" role="dialog" aria-modal="true" aria-labelledby="pnPreviewTitle">
    <div class="pn-preview-phone">
        <div class="pn-preview-timer"><span></span></div>
        <button class="pn-preview-close" id="pnPreviewClose" type="button" aria-label="Fechar e ir para as aulas"><b>×</b> Ir para as aulas</button>
        <div class="pn-preview-image"><img src="<?=pn_h($previewImage)?>" alt="Prévia do aplicativo no celular"></div>
        <div class="pn-preview-content">
            <span class="pn-preview-eyebrow">Aplicativo da área de membros</span>
            <h3 id="pnPreviewTitle"></h3><p id="pnPreviewText"></p>
            <div class="pn-preview-benefits" id="pnPreviewBenefits"></div>
            <button class="pn-preview-cta" id="pnPreviewCta" type="button"></button>
        </div>
    </div>
</div>
<script>
(function(){
    const settings=document.getElementById('pnSettings'),settingsBody=document.getElementById('pnSettingsBody');
    document.querySelectorAll('.pn-settings-source').forEach(function(section){section.classList.add('pn-settings-mounted');settingsBody.appendChild(section)});
    function closeSettings(){settings.classList.remove('open');document.body.style.overflow=''}
    document.getElementById('pnSettingsOpen').addEventListener('click',function(){settings.classList.add('open');document.body.style.overflow='hidden'});
    document.getElementById('pnSettingsClose').addEventListener('click',closeSettings);settings.addEventListener('click',function(event){if(event.target===settings)closeSettings()});
    if(<?= $openSettings ? 'true' : 'false' ?>){settings.classList.add('open');document.body.style.overflow='hidden'}
    const overlay=document.getElementById('pnPreview'),title=document.getElementById('pnPreviewTitle'),text=document.getElementById('pnPreviewText'),cta=document.getElementById('pnPreviewCta'),benefits=document.getElementById('pnPreviewBenefits');
    const modes={
        install:{title:<?=json_encode($appSettings['popup_title'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?>,text:<?=json_encode($appSettings['popup_text'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?>,cta:<?=json_encode($appSettings['popup_button_label'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP)?>,benefits:['Acesso em um toque','Avisos importantes','Experiência de aplicativo','Instalação gratuita']},
        browser:{title:'Abra suas aulas no Google Chrome',text:'Este aplicativo foi preparado para reproduzir suas aulas pelo Google Chrome, oferecendo mais estabilidade, velocidade e qualidade.',cta:'Abrir no Google Chrome',benefits:['Mais estabilidade','Melhor reprodução','Instalação segura','Acesso rápido']},
        live:{title:'Receba o aviso da próxima aula ao vivo',text:'Ative as notificações para avisarmos no seu celular quando a aula ao vivo estiver próxima. Assim você não perde o horário.',cta:'Quero receber o aviso da aula',benefits:['Aviso da aula ao vivo','Lembretes no celular','Novas liberações','Comunicados importantes']}
    };
    let closeTimer=null;function close(){clearTimeout(closeTimer);overlay.classList.remove('open');document.body.style.overflow=settings.classList.contains('open')?'hidden':''}
    document.querySelectorAll('[data-preview]').forEach(function(button){button.addEventListener('click',function(){const mode=modes[button.dataset.preview];title.textContent=mode.title;text.textContent=mode.text;cta.textContent=mode.cta;benefits.innerHTML='';mode.benefits.forEach(function(item){const span=document.createElement('span');span.textContent=item;benefits.appendChild(span)});overlay.classList.remove('open');void overlay.offsetWidth;overlay.classList.add('open');document.body.style.overflow='hidden';clearTimeout(closeTimer);closeTimer=setTimeout(close,20000)})});
    document.getElementById('pnPreviewClose').addEventListener('click',close);overlay.addEventListener('click',function(event){if(event.target===overlay)close()});document.addEventListener('keydown',function(event){if(event.key==='Escape'){close();closeSettings()}});cta.addEventListener('click',function(){cta.textContent='Demonstração: nenhuma ação foi executada'});
})();
</script>
<?php include __DIR__ . '/_footer.php'; ?>
