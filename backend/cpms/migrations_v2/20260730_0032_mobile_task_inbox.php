<?php
declare(strict_types=1);

return [
    'key' => '20260730_0032_mobile_task_inbox',
    'name' => 'CPMS v3.4.3 mobile task inbox and notification centre',
    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES ('mobile_inbox.view',
                'View mobile task inbox', 'mobile',
                'View property-scoped tasks and reminders on mobile')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff', 'security')
               AND p.permission_code = 'mobile_inbox.view'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.3',
                'Mobile Task Inbox and Notification Centre',
                'Unified mobile inbox for work orders, maintenance, corrective actions and patrol reminders.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id
             WHERE p.permission_code = 'mobile_inbox.view'
               AND r.role_code IN ('staff', 'security')",
            "DELETE FROM permissions WHERE permission_code = 'mobile_inbox.view'",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.3'",
        ];
    },
];
