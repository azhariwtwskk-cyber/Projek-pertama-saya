<?php
declare(strict_types=1);

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode(
    [
        'id' => './staff-app',
        'name' => 'CPMS Staff',
        'short_name' => 'CPMS Staff',
        'description' => 'Staff work and maintenance workspace.',
        'lang' => 'ms',
        'start_url' => './staff_dashboard.php?source=pwa',
        'scope' => './',
        'display' => 'standalone',
        'display_override' => ['standalone', 'minimal-ui'],
        'orientation' => 'any',
        'background_color' => '#f5f2ed',
        'theme_color' => '#6f3f2c',
        'icons' => [
            [
                'src' => 'pwa/icons/icon-192.png',
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ],
            [
                'src' => 'pwa/icons/icon-512.png',
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ],
        ],
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
