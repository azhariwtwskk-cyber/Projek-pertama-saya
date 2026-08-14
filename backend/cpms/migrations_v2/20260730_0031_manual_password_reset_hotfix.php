<?php
declare(strict_types=1);

return [
    'key' => '20260730_0031_manual_password_reset_hotfix',
    'name' => 'CPMS v3.4.2.1 system owner manual password reset hotfix',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES ('password_recovery.manual_reset',
                'Manually reset user password', 'security',
                'Allow System Owner to issue a temporary password')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code = 'system_owner'
               AND p.permission_code = 'password_recovery.manual_reset'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.2.1',
                'System Owner Manual Password Reset Hotfix',
                'Email-free temporary password reset, forced password change, session revocation and audit logging.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'password_recovery.manual_reset'",
            "DELETE FROM permissions
             WHERE permission_code = 'password_recovery.manual_reset'",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.2.1'",
        ];
    },
];
