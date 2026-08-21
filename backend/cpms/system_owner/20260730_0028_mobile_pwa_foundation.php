<?php
declare(strict_types=1);

return [
    'key' => '20260730_0028_mobile_pwa_foundation',
    'name' => 'CPMS v3.4.0 staff and security mobile PWA foundation',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.4.0',
                 'Staff & Security Mobile PWA Foundation',
                 'Separate installable Staff and Security apps sharing a secure service worker, offline fallback and mobile upload compatibility.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.4.0'",
        ];
    },
];
