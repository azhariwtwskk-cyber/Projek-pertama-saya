<?php
declare(strict_types=1);

return [
    'key' => '20260730_0025_staff_unified_session_hotfix',
    'name' => 'CPMS v3.3.6.1 staff unified session hotfix',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO role_permissions
                (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code = 'staff'
               AND p.permission_code = 'dashboard.view'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.6.1',
                 'Staff Unified Session & Permission Hotfix',
                 'Canonical Unified Login, automatic staff session repair, permission cache reset and complete logout.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.6.1'",
        ];
    },
];
