<?php
declare(strict_types=1);

return [
    'key' => '20260728_0022_pm_performance_analytics',
    'name' => 'CPMS v3.3.4 maintenance performance analytics',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name,
                 description)
             VALUES
                ('maintenance.analytics',
                 'View Maintenance Analytics',
                 'maintenance',
                 'View property maintenance cost, downtime and performance analytics.')",

            "INSERT IGNORE INTO role_permissions
                (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner', 'property_admin', 'manager'
                  )
               AND p.permission_code = 'maintenance.analytics'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.4',
                 'Preventive Maintenance Cost, Downtime & Performance Analytics',
                 'Property-scoped cost trends, downtime, schedule coverage, verification, asset and staff performance analytics.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'maintenance.analytics'",

            "DELETE FROM permissions
             WHERE permission_code = 'maintenance.analytics'",

            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.4'",
        ];
    },
];
