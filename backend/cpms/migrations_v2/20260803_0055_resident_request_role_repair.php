<?php
declare(strict_types=1);

return [
    'key' => '20260803_0055_resident_request_role_repair',
    'name' => 'CPMS v3.6.0.5 resident request role repair',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES (
                'resident.request.manage','Manage resident requests',
                'resident',
                'View and manage property-scoped resident requests'
             )",

            "UPDATE permissions SET status='active'
             WHERE permission_code='resident.request.manage'",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r
             INNER JOIN permissions p
                ON p.permission_code='resident.request.manage'
             WHERE r.role_code IN (
                    'system_owner','property_admin','manager'
               )
               AND r.status='active'
               AND p.status='active'",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.6.0.5')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES (
                '3.6.0.5','Resident Request Role Repair',
                'Restores resident.request.manage for Property Admin and Manager and adds a PHP 7.4-compatible static fallback.'
             )",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.5'",
            "UPDATE system_settings SET setting_value='3.6.0.4'
             WHERE setting_key='software_version'",
        ];
    },
];
