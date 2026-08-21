<?php
declare(strict_types=1);

return [
    'key' => '20260728_0020_pm_due_reminder_engine',
    'name' => 'CPMS v3.3.2 preventive maintenance due reminders',

    'up' => static function (mysqli $db): array {
        return [
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner', 'property_admin', 'manager',
                    'staff', 'contractor'
                  )
               AND p.permission_code IN (
                    'notifications.view', 'notifications.manage'
                  )",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.2',
                 'Preventive Maintenance Notifications & Due Reminder Engine',
                 'Property-scoped assignment, seven-day due, overdue, pending verification and verified maintenance notifications.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.2'",
        ];
    },
];
