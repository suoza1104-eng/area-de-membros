<?php
require_once __DIR__ . '/../app/push_notifications.php';
$__pwaFirebaseConfig = push_public_config();
$__pwaVapidKey = push_vapid_key();
$__pwaMessagingReady = $__pwaFirebaseConfig['apiKey'] !== '' && $__pwaFirebaseConfig['projectId'] !== '' && $__pwaVapidKey !== '';
?>
<?php if ($__pwaMessagingReady): ?>
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js"></script>
<?php endif; ?>
<script>
(function () {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
    let clientId = localStorage.getItem('push_client_id');
    if (!clientId) {
        clientId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2));
        localStorage.setItem('push_client_id', clientId);
    }
    <?php if ($__pwaMessagingReady): ?>
    window.areaMembrosEnablePush = async function () {
        if (!('Notification' in window)) throw new Error('Este navegador não oferece notificações.');
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') throw new Error('Permissão de notificações não concedida.');
        const registration = await navigator.serviceWorker.ready;
        if (!window.firebase) throw new Error('O serviço de notificações não foi carregado.');
        if (!firebase.apps.length) firebase.initializeApp(<?= json_encode($__pwaFirebaseConfig, JSON_UNESCAPED_SLASHES) ?>);
        const messaging = firebase.messaging();
        const token = await messaging.getToken({vapidKey:<?= json_encode($__pwaVapidKey) ?>,serviceWorkerRegistration:registration});
        if (!token) throw new Error('Não foi possível conectar este telefone.');
        const installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        const response = await fetch('api_push_device.php', {
            method:'POST',credentials:'same-origin',cache:'no-store',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body:JSON.stringify({action:'register',client_id:clientId,token:token,permission:'granted',installed:installed,platform:/Android/i.test(navigator.userAgent)?'android':'web'})
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'Não foi possível registrar este telefone.');
        localStorage.setItem('push_fcm_token', token);
        if (!window.__pushForegroundListener) {
            messaging.onMessage(function(payload){
                const data=payload&&payload.data?payload.data:{};
                registration.showNotification(data.title||'Área de Membros',{body:data.body||'',icon:'pwa-icon.svg',badge:'pwa-icon.svg',data:{click_url:data.click_url||'trilha.php'}});
            });
            window.__pushForegroundListener = true;
        }
        return result;
    };
    <?php endif; ?>
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('pwa-sw.php', {scope: './'}).then(function (registration) {
            window.__areaMembrosServiceWorker = registration;
            <?php if ($__pwaMessagingReady): ?>
            const tokenAnterior = localStorage.getItem('push_fcm_token');
            if ('Notification' in window && Notification.permission === 'granted' && tokenAnterior && window.firebase) {
                if (!firebase.apps.length) firebase.initializeApp(<?= json_encode($__pwaFirebaseConfig, JSON_UNESCAPED_SLASHES) ?>);
                const messaging = firebase.messaging();
                messaging.getToken({vapidKey:<?= json_encode($__pwaVapidKey) ?>,serviceWorkerRegistration:registration}).then(function(tokenAtual){
                    if (!tokenAtual) return;
                    localStorage.setItem('push_fcm_token',tokenAtual);
                    const installed=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
                    return fetch('api_push_device.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:installed?'installed':'heartbeat',client_id:clientId,token:tokenAtual,permission:'granted',installed:installed,platform:/Android/i.test(navigator.userAgent)?'android':'web'})});
                }).catch(function(){});
                messaging.onMessage(function(payload){
                    const data=payload&&payload.data?payload.data:{};
                    registration.showNotification(data.title||'Área de Membros',{body:data.body||'',icon:'pwa-icon.svg',badge:'pwa-icon.svg',data:{click_url:data.click_url||'trilha.php'}});
                });
                window.__pushForegroundListener = true;
            }
            <?php endif; ?>
        }).catch(function (error) {
            console.warn('PWA indisponível:', error);
        });
        try {
            const token = localStorage.getItem('push_fcm_token');
            const installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            if (installed && (!('Notification' in window) || Notification.permission !== 'granted' || !token)) {
                fetch('api_push_device.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'installed',client_id:clientId,token:'',permission:('Notification' in window?Notification.permission:'default'),installed:true,platform:/Android/i.test(navigator.userAgent)?'android':'web'})}).catch(function(){});
                return;
            }
            if (!token || !('Notification' in window)) return;
            fetch('api_push_device.php', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
                body: JSON.stringify({action:Notification.permission==='granted'?(installed?'installed':'heartbeat'):'disable',client_id:clientId,token:token,permission:Notification.permission,installed:installed,platform:/Android/i.test(navigator.userAgent)?'android':'web'})
            }).catch(function(){});
        } catch (error) {}
    });
})();
</script>
