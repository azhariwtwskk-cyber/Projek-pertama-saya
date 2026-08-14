<?php
declare(strict_types=1);

return [
    'key' => '20260803_0053_clerk_work_order_view_permission_hotfix',
    'name' => 'CPMS v3.6.0.3 clerk work order view permission hotfix',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('work_orders.view','View work orders','work_orders',
                 'View property-scoped work orders')",

            "UPDATE permissions SET status='active'
             WHERE permission_code='work_orders.view'",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r
             INNER JOIN permissions p
                ON p.permission_code='work_orders.view'
             WHERE r.role_code='clerk'",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.6.0.3')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.6.0.3','Clerk Work Order View Permission Hotfix',
                'Aligns the legacy Property Portal permission matrix with the database role grant so Clerk can view property-scoped Work Orders without create, update, assignment or approval access.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.3'",
            "UPDATE system_settings SET setting_value='3.6.0.2'
             WHERE setting_key='software_version'",
        ];
    },
];
