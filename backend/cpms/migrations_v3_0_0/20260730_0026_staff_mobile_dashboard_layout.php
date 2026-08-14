<?php
declare(strict_types=1);

return [
    'key' => '20260730_0026_staff_mobile_dashboard_layout',
    'name' => 'CPMS v3.3.6.2 staff mobile dashboard layout',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.6.2',
                 'Staff Mobile Dashboard Layout Hotfix',
                 'Adds a responsive staff header, permission-aware mobile navigation and a permanent mobile logout button.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.6.2'",
        ];
    },
];
