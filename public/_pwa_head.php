<?php require_once __DIR__ . '/../app/push_notifications.php'; $__pwaAppIcon = push_app_icon_url(); ?>
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#facc15">
<link rel="icon" href="<?=htmlspecialchars($__pwaAppIcon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
<link rel="apple-touch-icon" href="<?=htmlspecialchars($__pwaAppIcon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>">
