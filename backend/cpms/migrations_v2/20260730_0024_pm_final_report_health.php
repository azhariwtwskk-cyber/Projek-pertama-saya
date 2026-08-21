<?php
declare(strict_types=1);

return [
    'key' => '20260730_0024_pm_final_report_health',
    'name' => 'CPMS v3.3.6 maintenance final report and health',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name,
                 description)
             VALUES
                ('maintenance.report',
                 'View Maintenance Final Report',
                 'maintenance',
                 'View and export property preventive maintenance final reports and module health.')",

            "INSERT IGNORE INTO role_permissions
                (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner', 'property_admin', 'manager'
                  )
               AND p.permission_code = 'maintenance.report'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.6',
                 'Preventive Maintenance Final Report, PDF Export & Module Health',
                 'Property-branded annual report, print/PDF export and full v3.3 module health validation.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'maintenance.report'",

            "DELETE FROM permissions
             WHERE permission_code = 'maintenance.report'",

            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.6'",
        ];
    },
];
