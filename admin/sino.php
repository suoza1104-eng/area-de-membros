<?php
// FILE: admin/sino.php
// A configuracao do Sino agora e a aba "Sino (WhatsApp)" da tela Integracoes.
declare(strict_types=1);

require_once __DIR__ . '/../app/funcoes.php';
proteger_admin();
header('Location: integracoes.php?tab=sino');
exit;
