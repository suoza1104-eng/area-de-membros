<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/push_notifications.php';
proteger_admin();
$pdo = getPDO();
push_ensure_schema($pdo);

$canWrite = ($_SESSION['admin_tipo'] ?? 'principal') !== 'equipe';
if (!$canWrite) {
    $perms = json_decode((string)($_SESSION['equipe_perms'] ?? ''), true) ?: [];
    $canWrite = !empty($perms['notificacoes']['escrever']);
}
if (empty($_SESSION['push_admin_csrf'])) $_SESSION['push_admin_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['push_admin_csrf'];
$message = '';
$error = '';

function pn_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pn_pct(int $value, int $total): string { return number_format($total > 0 ? $value / $total * 100 : 0, 1, ',', '.') . '%'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWrite) throw new RuntimeException('Seu usuário não possui permissão de escrita.');
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        $action = (string)($_POST['action'] ?? '');
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
            header('Location: notificacoes.php?saved=1');
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
    }
}
if (!empty($_GET['saved'])) $message = 'Configuração salva.';

$publicConfig = push_public_config();
$serviceConfigured = trim((string)(get_setting('push_firebase_service_account', '') ?? '')) !== '';
$configured = push_is_configured();
$appName = (string)(get_setting('push_app_name', 'Área de Membros') ?? 'Área de Membros');

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

$menu = 'notificacoes';
$page_title = 'Notificações do aplicativo';
include __DIR__ . '/_header.php';
?>
<style>
.pn{display:flex;flex-direction:column;gap:16px}.pn-grid{display:grid;grid-template-columns:repeat(5,minmax(145px,1fr));gap:10px}.pn-kpi{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:15px}.pn-kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.pn-kpi strong{display:block;font-size:24px;margin-top:5px}.pn-kpi span{font-size:11px;color:var(--muted)}.pn-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.pn-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}.pn-card h2{font-size:15px;margin:0 0 4px}.pn-card>p{font-size:11px;color:var(--muted);margin:0 0 14px}.pn-form{display:grid;gap:11px}.pn-form label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}.pn-form input,.pn-form textarea,.pn-form select{width:100%;margin-top:4px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px;font:inherit}.pn-form textarea{min-height:90px;resize:vertical}.pn-status{padding:11px 13px;border-radius:9px;font-size:12px}.pn-ok{background:var(--success-dim);color:#86efac}.pn-error{background:var(--danger-dim);color:#fca5a5}.pn-table{overflow:auto}.pn-table table{min-width:900px}.pn-pill{display:inline-flex;padding:2px 7px;border-radius:999px;font-size:10px;background:var(--bg-hover);color:var(--muted)}.pn-pill.active,.pn-pill.accepted,.pn-pill.clicked{background:var(--success-dim);color:#86efac}.pn-pill.failed,.pn-pill.uninstalled,.pn-pill.revoked{background:var(--danger-dim);color:#fca5a5}@media(max-width:1000px){.pn-grid{grid-template-columns:repeat(2,1fr)}.pn-cols{grid-template-columns:1fr}}@media(max-width:560px){.pn-grid{grid-template-columns:1fr}}
</style>
<div class="pn">
    <?php if($message):?><div class="pn-status pn-ok"><?=pn_h($message)?></div><?php endif;?>
    <?php if($error):?><div class="pn-status pn-error"><?=pn_h($error)?></div><?php endif;?>
    <div class="pn-status <?=$configured?'pn-ok':'pn-error'?>">Firebase: <strong><?=$configured?'configurado':'configuração incompleta'?></strong>. URL privada para ativação no telefone: <code><?=pn_h(BASE_URL)?>/notificacoes_teste.php</code></div>

    <div class="pn-grid">
        <?php foreach([
            ['total','Dispositivos conectados'],['installed','Instalaram o app'],['uninstalled','Tokens excluídos/inativos'],['active24','Acesso nas últimas 24h'],['receiving','Recebendo notificações']
        ] as [$key,$label]):?>
        <div class="pn-kpi"><small><?=pn_h($label)?></small><strong><?=number_format($kpi[$key])?></strong><span><?=pn_pct($kpi[$key],max(1,$kpi['total']))?> dos dispositivos registrados</span></div>
        <?php endforeach;?>
    </div>

    <div class="pn-cols">
        <section class="pn-card">
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
        <section class="pn-card">
            <h2>Configuração Firebase</h2><p>Use um projeto Firebase Web e uma conta de serviço com acesso ao Cloud Messaging.</p>
            <form method="post" class="pn-form">
                <input type="hidden" name="csrf" value="<?=pn_h($csrf)?>"><input type="hidden" name="action" value="save_config">
                <label>Nome do aplicativo<input name="app_name" value="<?=pn_h($appName)?>"></label>
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
</div>
<?php include __DIR__ . '/_footer.php'; ?>
