<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/funcoes.php';

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$name = trim((string)(get_setting('push_app_name', 'Área de Membros') ?? 'Área de Membros')) ?: 'Área de Membros';
$shortName = mb_substr($name, 0, 30);
$theme = (string)(get_setting('theme_primary', '#facc15') ?? '#facc15');
$background = (string)(get_setting('theme_bg_main', '#080e1a') ?? '#080e1a');

echo json_encode([
    'id' => './',
    'name' => $name,
    'short_name' => $shortName,
    'description' => 'Área de membros e aulas',
    'start_url' => './trilha.php?source=pwa',
    'scope' => './',
    'display' => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'background_color' => $background,
    'theme_color' => $theme,
    'icons' => [
        ['src'=>'pwa-icon.svg','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
