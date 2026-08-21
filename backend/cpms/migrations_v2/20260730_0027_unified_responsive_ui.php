<?php
declare(strict_types=1);

return [
    'key' => '20260730_0027_unified_responsive_ui',
    'name' => 'CPMS v3.3.7 unified responsive UI foundation',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.7',
                 'Unified Responsive UI Foundation',
                 'Shared responsive layout for Property, System Owner, Staff, Security, HQ Inspector and Unified Login portals.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.7'",
        ];
    },
];
