<?php
declare(strict_types=1);

return [
    'key' => '20260803_0057_resident_registration_queue_access',
    'name' => 'CPMS v3.6.0.7 resident registration queue access',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES (
                'resident.registration.manage',
                'Manage resident registrations',
                'resident',
                'Review, approve or reject property-scoped Resident Portal account applications'
             )",

            "UPDATE permissions SET status='active'
             WHERE permission_code='resident.registration.manage'",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r
             INNER JOIN permissions p
                ON p.permission_code='resident.registration.manage'
             WHERE r.role_code IN ('property_admin','manager')
               AND r.status='active'
               AND p.status='active'",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.6.0.7')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES (
                '3.6.0.7','Resident Registration Queue Access',
                'Restores the property-scoped approval queue on the dashboard and sidebar and enables safe Clerk delegation.'
             )",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.7'",
            "UPDATE system_settings SET setting_value='3.6.0.6'
             WHERE setting_key='software_version'",
        ];
    },
];
