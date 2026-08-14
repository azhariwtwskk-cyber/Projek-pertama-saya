<?php
declare(strict_types=1);

return [
    'key' => '20260728_0017_inspection_report_export',
    'name' => 'CPMS v3.2.5 inspection summary report and PDF export',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('inspection.report.export',
                 'Export Inspection Summary Report',
                 'inspection',
                 'View, print and save the complete inspection report as PDF.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner','property_admin','manager','clerk'
                  )
               AND p.permission_code = 'inspection.report.export'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.5',
                 'Inspection Summary Report & PDF Export',
                 'Print-ready A4 report containing inspection details, scoring, evidence, actions and approval history.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'inspection.report.export'",
            "DELETE FROM permissions
             WHERE permission_code = 'inspection.report.export'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.5'",
        ];
    },
];
