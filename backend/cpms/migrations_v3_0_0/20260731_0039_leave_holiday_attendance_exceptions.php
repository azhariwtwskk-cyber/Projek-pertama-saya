<?php
declare(strict_types=1);

return [
    'key' => '20260731_0039_leave_holiday_attendance_exceptions',
    'name' => 'CPMS v3.4.9 leave balance, public holiday and attendance exceptions',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_leave_balances (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                leave_type_id INT UNSIGNED NOT NULL,
                leave_year SMALLINT UNSIGNED NOT NULL,
                entitlement_days DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                adjustment_days DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_leave_balance
                    (property_id, system_user_id, leave_type_id, leave_year),
                KEY idx_leave_balance_user
                    (system_user_id, leave_year),
                CONSTRAINT fk_leave_balance_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_leave_balance_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_leave_balance_type
                    FOREIGN KEY (leave_type_id) REFERENCES cpms_leave_types(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_public_holidays (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                holiday_date DATE NOT NULL,
                holiday_name VARCHAR(150) NOT NULL,
                holiday_scope VARCHAR(30) NOT NULL DEFAULT 'Property',
                paid_holiday TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_property_holiday
                    (property_id, holiday_date, holiday_name),
                KEY idx_holiday_property_date
                    (property_id, holiday_date),
                CONSTRAINT fk_public_holiday_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_public_holiday_creator
                    FOREIGN KEY (created_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_exceptions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                attendance_session_id BIGINT UNSIGNED NULL,
                exception_date DATE NOT NULL,
                exception_type VARCHAR(40) NOT NULL,
                exception_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                exception_status VARCHAR(20) NOT NULL DEFAULT 'Open',
                explanation VARCHAR(500) NULL,
                resolution_notes VARCHAR(500) NULL,
                resolved_by_system_user_id INT UNSIGNED NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_attendance_exception
                    (property_id, system_user_id, exception_date,
                     exception_type, attendance_session_id),
                KEY idx_exception_property_status
                    (property_id, exception_status, exception_date),
                CONSTRAINT fk_attendance_exception_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_attendance_exception_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_attendance_exception_session
                    FOREIGN KEY (attendance_session_id)
                    REFERENCES cpms_attendance_sessions(id) ON DELETE SET NULL,
                CONSTRAINT fk_attendance_exception_resolver
                    FOREIGN KEY (resolved_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('leave.balance.view', 'View leave balance', 'leave',
                 'View own leave entitlement and balance'),
                ('leave.balance.manage', 'Manage leave balance', 'leave',
                 'Set employee leave entitlement and adjustments'),
                ('attendance.holiday.manage', 'Manage public holidays',
                 'attendance', 'Maintain property public holiday calendar'),
                ('attendance.exception.view', 'View attendance exceptions',
                 'attendance', 'View attendance exception records'),
                ('attendance.exception.manage', 'Manage attendance exceptions',
                 'attendance', 'Review and resolve attendance exceptions')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner', 'property_admin', 'manager')
               AND p.permission_code IN
                ('leave.balance.view', 'leave.balance.manage',
                 'attendance.holiday.manage',
                 'attendance.exception.view',
                 'attendance.exception.manage')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff', 'security')
               AND p.permission_code IN
                ('leave.balance.view', 'attendance.exception.view')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.9',
                'Leave Balance, Public Holiday and Attendance Exception',
                'Annual leave entitlement, property holiday calendar and exception resolution workflow.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN
                ('leave.balance.view', 'leave.balance.manage',
                 'attendance.holiday.manage',
                 'attendance.exception.view',
                 'attendance.exception.manage')",
            "DELETE FROM permissions WHERE permission_code IN
                ('leave.balance.view', 'leave.balance.manage',
                 'attendance.holiday.manage',
                 'attendance.exception.view',
                 'attendance.exception.manage')",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.9'",
            "DROP TABLE IF EXISTS cpms_attendance_exceptions",
            "DROP TABLE IF EXISTS cpms_public_holidays",
            "DROP TABLE IF EXISTS cpms_leave_balances",
        ];
    },
];
