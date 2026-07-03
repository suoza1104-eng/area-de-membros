<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/push_notifications.php';
proteger_aluno();
$config = push_public_config();
$vapidKey = push_vapid_key();
$configured = $config['apiKey'] !== '' && $config['projectId'] !== '' && $vapidKey !== '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Teste do aplicativo</title>
    <?php include __DIR__ . '/_pwa_head.php'; ?>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;background:#080e1a;color:#e2e8f0;font-family:Inter,system-ui,sans-serif;display:grid;place-items:center;padding:20px}.box{width:min(100%,520px);background:#0d1526;border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:24px;box-shadow:0 18px 50px rgba(0,0,0,.35)}h1{font-size:22px;margin:0 0 8px}p{color:#94a3b8;font-size:14px;line-height:1.55}.steps{background:#080e1a;border-radius:12px;padding:14px 18px;margin:18px 0;color:#cbd5e1;font-size:13px;line-height:1.7}.btn{width:100%;border:0;border-radius:10px;padding:13px 16px;background:#facc15;color:#111827;font-size:15px;font-weight:800;cursor:pointer}.btn:disabled{opacity:.55;cursor:not-allowed}.status{margin-top:14px;padding:12px;border-radius:10px;background:rgba(56,189,248,.09);color:#93c5fd;font-size:13px;word-break:break-word}.status.ok{background:rgba(34,197,94,.1);color:#86efac}.status.err{background:rgba(239,68,68,.1);color:#fca5a5}.meta{margin-top:12px;color:#64748b;font-size:11px;text-align:center}a{color:#facc15}
    </style>
</head>
<body>
<main class="box">
    <h1>Teste do aplicativo</h1>
    <p>Esta página não aparece no menu dos alunos. Use-a para instalar manualmente e conectar este telefone às notificações.</p>
    <div class="steps">
        <strong>Instalação no Android</strong><br>
        1. Abra esta página no Google Chrome.<br>
        2. Toque em “Instalar aplicativo” abaixo.<br>
        3. Confirme a instalação na janela do Android.<br>
        4. Abra o novo ícone na tela inicial.<br>
        5. Volte a esta página e ative as notificações.
    </div>
    <button class="btn" id="installApp" type="button">Instalar aplicativo</button>
    <div class="status" id="installStatus">Verificando se o aplicativo pode ser instalado.</div>
    <?php if (!$configured): ?>
        <div class="status err">O Firebase ainda não foi configurado no painel administrativo.</div>
    <?php else: ?>
        <button class="btn" id="enablePush" type="button" style="margin-top:14px">Ativar notificações neste telefone</button>
        <div class="status" id="pushStatus">Aguardando ativação.</div>
    <?php endif; ?>
    <div class="meta" id="installMode"></div>
    <p style="text-align:center;margin-bottom:0"><a href="trilha.php">Voltar para as aulas</a></p>
</main>
<?php include __DIR__ . '/_pwa_runtime.php'; ?>
<script>
(function () {
    const installButton = document.getElementById('installApp');
    const installStatus = document.getElementById('installStatus');
    const runningStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    let installPrompt = null;

    function setInstallStatus(message, type) {
        installStatus.textContent = message;
        installStatus.className = 'status' + (type ? ' ' + type : '');
    }
    if (runningStandalone) {
        installButton.disabled = true;
        installButton.textContent = 'Aplicativo instalado';
        setInstallStatus('Você já está usando a versão instalada.', 'ok');
    } else {
        installButton.disabled = true;
        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            installPrompt = event;
            installButton.disabled = false;
            setInstallStatus('Pronto para instalar com um toque.', 'ok');
        });
        installButton.addEventListener('click', async function () {
            if (!installPrompt) {
                setInstallStatus('O Chrome ainda não liberou a instalação. Atualize esta página ou use o menu ⋮ > Instalar app.', 'err');
                return;
            }
            installButton.disabled = true;
            installPrompt.prompt();
            const choice = await installPrompt.userChoice;
            installPrompt = null;
            if (choice.outcome === 'accepted') {
                installButton.textContent = 'Instalação confirmada';
                setInstallStatus('O Android está instalando o aplicativo.', 'ok');
            } else {
                installButton.disabled = false;
                setInstallStatus('Instalação cancelada. Você pode tentar novamente.', '');
            }
        });
    }
    window.addEventListener('appinstalled', function () {
        installButton.disabled = true;
        installButton.textContent = 'Aplicativo instalado';
        setInstallStatus('Aplicativo instalado. Abra o novo ícone na tela inicial.', 'ok');
    });
})();
</script>
<?php if ($configured): ?>
<script>
(function () {
    const firebaseConfig = <?= json_encode($config, JSON_UNESCAPED_SLASHES) ?>;
    const vapidKey = <?= json_encode($vapidKey) ?>;
    const button = document.getElementById('enablePush');
    const status = document.getElementById('pushStatus');
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    document.getElementById('installMode').textContent = standalone ? 'Executando como aplicativo instalado.' : 'Executando no navegador.';

    function setStatus(message, type) {
        status.textContent = message;
        status.className = 'status' + (type ? ' ' + type : '');
    }
    function platform() {
        const ua = navigator.userAgent || '';
        if (/Android/i.test(ua)) return 'android';
        if (/iPhone|iPad|iPod/i.test(ua)) return 'ios';
        return 'web';
    }
    function browser() {
        const ua = navigator.userAgent || '';
        if (/Edg/i.test(ua)) return 'edge';
        if (/SamsungBrowser/i.test(ua)) return 'samsung';
        if (/Chrome/i.test(ua)) return 'chrome';
        if (/Safari/i.test(ua)) return 'safari';
        return 'other';
    }
    async function saveDevice(token, action) {
        let clientId = localStorage.getItem('push_client_id');
        if (!clientId) {
            clientId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2));
            localStorage.setItem('push_client_id', clientId);
        }
        const response = await fetch('api_push_device.php', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({action: action || 'register', client_id: clientId, token: token || '', permission: Notification.permission, installed: standalone, platform: platform(), browser: browser()})
        });
        const json = await response.json();
        if (!response.ok || !json.ok) throw new Error(json.message || json.error || 'Falha ao registrar dispositivo.');
        return json;
    }
    async function enable() {
        button.disabled = true;
        try {
            if (!window.isSecureContext) throw new Error('As notificações exigem HTTPS.');
            if (!('Notification' in window) || !('serviceWorker' in navigator)) throw new Error('Este navegador não suporta notificações web.');
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('Permissão de notificações não concedida.');
            setStatus('Conectando este telefone...', '');
            const registration = await navigator.serviceWorker.ready;
            if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
            const messaging = firebase.messaging();
            const token = await messaging.getToken({vapidKey: vapidKey, serviceWorkerRegistration: registration});
            if (!token) throw new Error('O Firebase não retornou um token para este telefone.');
            await saveDevice(token, 'register');
            if (!window.__pushForegroundListener) {
                messaging.onMessage(function(payload) {
                    const data = payload && payload.data ? payload.data : {};
                    registration.showNotification(data.title || 'Área de Membros', {body:data.body || '',icon:'pwa-icon.svg',data:{click_url:data.click_url || 'trilha.php'}});
                });
                window.__pushForegroundListener = true;
            }
            localStorage.setItem('push_fcm_token', token);
            setStatus('Telefone conectado. Agora envie uma notificação de teste pelo painel administrativo.', 'ok');
        } catch (error) {
            setStatus(error && error.message ? error.message : String(error), 'err');
            button.disabled = false;
        }
    }
    button.addEventListener('click', enable);
    window.addEventListener('appinstalled', function() {
        const token = localStorage.getItem('push_fcm_token');
        if (token) saveDevice(token, 'installed').catch(function(){});
    });
    const existingToken = localStorage.getItem('push_fcm_token');
    if (existingToken && Notification.permission === 'granted') {
        saveDevice(existingToken, standalone ? 'installed' : 'heartbeat').then(function() {
            setStatus('Este telefone já está conectado para receber notificações.', 'ok');
        }).catch(function(){});
    }
})();
</script>
<?php endif; ?>
</body>
</html>
