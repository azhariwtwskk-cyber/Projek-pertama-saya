<?php
declare(strict_types=1);

return [
    'key' => '20260728_0019_staff_maintenance_workspace',
    'name' => 'CPMS v3.3.1 staff preventive maintenance workspace',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff','contractor')
               AND p.permission_code IN (
                   'maintenance.view','maintenance.complete'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code = 'maintenance.verify'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.1',
                 'Staff Preventive Maintenance Workspace',
                 'Staff-assigned maintenance workspace, evidence submission and Property Admin verification.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.1'",
        ];
    },
];
