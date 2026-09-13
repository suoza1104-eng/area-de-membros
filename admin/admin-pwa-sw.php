<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/push_notifications.php';

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
$config = push_public_config();
?>
const FIREBASE_CONFIG = <?= json_encode($config, JSON_UNESCAPED_SLASHES) ?>;
const ADMIN_ICON = '../public/pwa-icon.svg';

importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

if (FIREBASE_CONFIG.apiKey && FIREBASE_CONFIG.projectId && !firebase.apps.length) {
  firebase.initializeApp(FIREBASE_CONFIG);
  const messaging = firebase.messaging();
  messaging.onBackgroundMessage(function(payload) {
    const data = payload && payload.data ? payload.data : {};
    if (data.channel && data.channel !== 'admin') return;
    const title = data.title || 'Gestao de Vendas';
    return self.registration.showNotification(title, {
      body: data.body || '',
      icon: ADMIN_ICON,
      badge: ADMIN_ICON,
      tag: data.notification_id ? 'admin-push-' + data.notification_id : undefined,
      requireInteraction: data.event_code === 'PAGAMENTO_APROVADO',
      data: {
        click_url: data.click_url || 'vendas_analytics.php',
        event_code: data.event_code || '',
        sound_key: data.sound_key || 'cash'
      }
    });
  });
}

self.addEventListener('install', function() { self.skipWaiting(); });
self.addEventListener('activate', function(event) { event.waitUntil(self.clients.claim()); });
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.click_url) || 'vendas_analytics.php';
  event.waitUntil(self.clients.openWindow(target));
});
