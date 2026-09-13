<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$theme = (string)(get_setting('theme_primary', '#facc15') ?? '#facc15');
$background = (string)(get_setting('theme_bg_main', '#080e1a') ?? '#080e1a');

echo json_encode([
    'id' => './admin_app.php',
    'name' => 'Gestao de Vendas',
    'short_name' => 'Vendas Admin',
    'description' => 'Aplicativo administrativo para acompanhar vendas e alertas do negocio.',
    'start_url' => './admin_app.php?source=pwa',
    'scope' => './',
    'display' => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'background_color' => $background,
    'theme_color' => $theme,
    'icons' => [
        ['src' => '../public/pwa-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => '../public/pwa-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
