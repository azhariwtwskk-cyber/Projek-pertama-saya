<?php
declare(strict_types=1);

return [
    'key' => '20260730_0035_attendance_shift_late_overtime',
    'name' => 'CPMS v3.4.6 attendance shift late arrival and overtime engine',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_attendance_shifts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                shift_name VARCHAR(120) NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                working_days VARCHAR(30) NOT NULL DEFAULT '1,2,3,4,5',
                grace_minutes INT UNSIGNED NOT NULL DEFAULT 10,
                break_minutes INT UNSIGNED NOT NULL DEFAULT 60,
                minimum_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 30,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_attendance_shift_property (property_id, status),
                CONSTRAINT fk_attendance_shift_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_attendance_shift_creator
                    FOREIGN KEY (created_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_shift_assignments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                shift_id BIGINT UNSIGNED NOT NULL,
                effective_from DATE NOT NULL,
                effective_until DATE NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                assigned_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_shift_assignment_user
                    (property_id, system_user_id, effective_from, status),
                KEY idx_shift_assignment_shift (shift_id, status),
                CONSTRAINT fk_shift_assignment_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_shift_assignment_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id),
                CONSTRAINT fk_shift_assignment_shift
                    FOREIGN KEY (shift_id) REFERENCES cpms_attendance_shifts(id),
                CONSTRAINT fk_shift_assignment_creator
                    FOREIGN KEY (assigned_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE cpms_attendance_sessions
                ADD COLUMN shift_assignment_id BIGINT UNSIGNED NULL
                    AFTER user_role,
                ADD COLUMN scheduled_start_at DATETIME NULL
                    AFTER work_date,
                ADD COLUMN scheduled_end_at DATETIME NULL
                    AFTER scheduled_start_at,
                ADD COLUMN late_minutes INT UNSIGNED NOT NULL DEFAULT 0
                    AFTER scheduled_end_at,
                ADD COLUMN early_departure_minutes INT UNSIGNED NOT NULL DEFAULT 0
                    AFTER late_minutes,
                ADD COLUMN worked_minutes INT UNSIGNED NOT NULL DEFAULT 0
                    AFTER early_departure_minutes,
                ADD COLUMN overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0
                    AFTER worked_minutes,
                ADD KEY idx_attendance_shift_assignment (shift_assignment_id),
                ADD CONSTRAINT fk_attendance_session_shift_assignment
                    FOREIGN KEY (shift_assignment_id)
                    REFERENCES cpms_attendance_shift_assignments(id)
                    ON DELETE SET NULL",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('attendance.shift.manage', 'Manage attendance shifts',
                 'attendance', 'Create shifts and assign staff or security'),
                ('attendance.analytics.view', 'View attendance analytics',
                 'attendance', 'View lateness, early departure and overtime')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner', 'property_admin', 'manager')
               AND p.permission_code IN
                ('attendance.shift.manage', 'attendance.analytics.view')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.6',
                'Attendance Shift Late Arrival and Overtime Engine',
                'Property shifts, user assignment, overnight schedule, lateness, early departure and overtime calculation.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN
                ('attendance.shift.manage', 'attendance.analytics.view')",
            "DELETE FROM permissions WHERE permission_code IN
                ('attendance.shift.manage', 'attendance.analytics.view')",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.6'",
            "ALTER TABLE cpms_attendance_sessions
                DROP FOREIGN KEY fk_attendance_session_shift_assignment,
                DROP INDEX idx_attendance_shift_assignment,
                DROP COLUMN shift_assignment_id,
                DROP COLUMN scheduled_start_at,
                DROP COLUMN scheduled_end_at,
                DROP COLUMN late_minutes,
                DROP COLUMN early_departure_minutes,
                DROP COLUMN worked_minutes,
                DROP COLUMN overtime_minutes",
            "DROP TABLE IF EXISTS cpms_attendance_shift_assignments",
            "DROP TABLE IF EXISTS cpms_attendance_shifts",
        ];
    },
];
