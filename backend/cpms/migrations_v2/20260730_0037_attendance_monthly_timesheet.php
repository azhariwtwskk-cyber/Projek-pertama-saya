<?php
declare(strict_types=1);

return [
    'key' => '20260730_0037_attendance_monthly_timesheet',
    'name' => 'CPMS v3.4.7 monthly attendance timesheet and payroll export',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_attendance_monthly_approvals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                attendance_month CHAR(7) NOT NULL,
                approval_status VARCHAR(20) NOT NULL DEFAULT 'Draft',
                notes VARCHAR(500) NULL,
                approved_by_system_user_id INT UNSIGNED NULL,
                approved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_attendance_month_property
                    (property_id, attendance_month),
                KEY idx_attendance_month_status
                    (property_id, approval_status),
                CONSTRAINT fk_attendance_month_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_attendance_month_approver
                    FOREIGN KEY (approved_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('attendance.payroll.export', 'Export attendance payroll',
                 'attendance', 'Export monthly attendance to CSV'),
                ('attendance.timesheet.approve', 'Approve attendance timesheet',
                 'attendance', 'Approve or lock monthly attendance')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner', 'property_admin', 'manager')
               AND p.permission_code IN
                ('attendance.payroll.export',
                 'attendance.timesheet.approve')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.7',
                'Monthly Attendance Timesheet and Payroll Export',
                'Monthly staff and security timesheet, approval status, lateness, worked hours, overtime and CSV payroll export.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN
                ('attendance.payroll.export',
                 'attendance.timesheet.approve')",
            "DELETE FROM permissions WHERE permission_code IN
                ('attendance.payroll.export',
                 'attendance.timesheet.approve')",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.7'",
            "DROP TABLE IF EXISTS cpms_attendance_monthly_approvals",
        ];
    },
];
