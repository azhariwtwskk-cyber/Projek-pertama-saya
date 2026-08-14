<?php
declare(strict_types=1);

return [
    'key' => '20260803_0051_resident_request_permission_hotfix',
    'name' => 'CPMS v3.6.0.1 resident request permission hotfix',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('resident.request.manage','Manage resident requests',
                 'resident',
                 'View and manage property-scoped resident requests')",

            "UPDATE permissions SET status='active'
             WHERE permission_code='resident.request.manage'",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r
             INNER JOIN permissions p
                ON p.permission_code='resident.request.manage'
             WHERE r.role_code IN
                ('system_owner','property_admin','manager')
               AND r.status='active' AND p.status='active'",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.6.0.1')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.6.0.1','Resident Request Permission Hotfix',
                'Grants resident.request.manage to Property Admin and Manager and aligns sidebar visibility with permission enforcement.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             INNER JOIN roles r ON r.id=rp.role_id
             INNER JOIN permissions p ON p.id=rp.permission_id
             WHERE p.permission_code='resident.request.manage'
               AND r.role_code IN
                   ('system_owner','property_admin','manager')",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.1'",
            "UPDATE system_settings SET setting_value='3.6.0'
             WHERE setting_key='software_version'",
        ];
    },
];
