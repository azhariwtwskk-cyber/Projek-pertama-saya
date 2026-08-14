<?php
declare(strict_types=1);

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode(
    [
        'id' => './security-app',
        'name' => 'CPMS Security',
        'short_name' => 'CPMS Security',
        'description' => 'Security patrol and visitor workspace.',
        'lang' => 'ms',
        'start_url' => './security_dashboard.php?source=pwa',
        'scope' => './',
        'display' => 'standalone',
        'display_override' => ['standalone', 'minimal-ui'],
        'orientation' => 'any',
        'background_color' => '#f4f2ee',
        'theme_color' => '#0f172a',
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
