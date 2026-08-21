<?php
declare(strict_types=1);

return [
    'key' => '20260731_0038_attendance_leave_payroll_foundation',
    'name' => 'CPMS v3.4.8 attendance dashboard, leave and payroll foundation',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_leave_types (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                leave_code VARCHAR(30) NOT NULL,
                leave_name VARCHAR(100) NOT NULL,
                paid_leave TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_leave_type_property_code
                    (property_id, leave_code),
                CONSTRAINT fk_leave_type_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_leave_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                leave_type_id INT UNSIGNED NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                total_days DECIMAL(6,2) NOT NULL DEFAULT 1.00,
                reason VARCHAR(500) NULL,
                request_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                reviewed_by_system_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                review_notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_leave_property_status
                    (property_id, request_status, start_date),
                KEY idx_leave_user_date
                    (system_user_id, start_date, end_date),
                CONSTRAINT fk_leave_request_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_leave_request_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_leave_request_type
                    FOREIGN KEY (leave_type_id) REFERENCES cpms_leave_types(id),
                CONSTRAINT fk_leave_request_reviewer
                    FOREIGN KEY (reviewed_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_payroll_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                employee_no VARCHAR(50) NULL,
                payroll_status VARCHAR(20) NOT NULL DEFAULT 'Active',
                ordinary_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                overtime_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payroll_notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_payroll_profile_user
                    (property_id, system_user_id),
                CONSTRAINT fk_payroll_profile_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_payroll_profile_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_payroll_exports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                attendance_month CHAR(7) NOT NULL,
                export_reference VARCHAR(60) NOT NULL,
                exported_by_system_user_id INT UNSIGNED NULL,
                exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                row_count INT UNSIGNED NOT NULL DEFAULT 0,
                export_status VARCHAR(20) NOT NULL DEFAULT 'Generated',
                PRIMARY KEY (id),
                UNIQUE KEY uq_payroll_export_reference (export_reference),
                KEY idx_payroll_export_month
                    (property_id, attendance_month),
                CONSTRAINT fk_payroll_export_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_payroll_export_user
                    FOREIGN KEY (exported_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO cpms_leave_types
                (property_id, leave_code, leave_name, paid_leave)
             SELECT id, 'ANNUAL', 'Annual Leave', 1 FROM cpms_properties",
            "INSERT IGNORE INTO cpms_leave_types
                (property_id, leave_code, leave_name, paid_leave)
             SELECT id, 'MEDICAL', 'Medical Leave', 1 FROM cpms_properties",
            "INSERT IGNORE INTO cpms_leave_types
                (property_id, leave_code, leave_name, paid_leave)
             SELECT id, 'UNPAID', 'Unpaid Leave', 0 FROM cpms_properties",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('attendance.dashboard.view', 'View attendance dashboard',
                 'attendance', 'View attendance operational dashboard'),
                ('leave.request', 'Request leave', 'leave',
                 'Submit personal leave request'),
                ('leave.manage', 'Manage leave', 'leave',
                 'Review and approve property leave requests'),
                ('payroll.profile.manage', 'Manage payroll profiles', 'payroll',
                 'Maintain payroll integration profile'),
                ('payroll.integration.view', 'View payroll integration',
                 'payroll', 'View payroll-ready attendance data')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner', 'property_admin', 'manager')
               AND p.permission_code IN
                ('attendance.dashboard.view', 'leave.manage',
                 'payroll.profile.manage', 'payroll.integration.view')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff', 'security')
               AND p.permission_code IN
                ('attendance.dashboard.view', 'leave.request')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.8',
                'Attendance Dashboard, Leave and Payroll Integration',
                'Attendance KPI dashboard, leave workflow and payroll integration foundation.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN
                ('attendance.dashboard.view', 'leave.request',
                 'leave.manage', 'payroll.profile.manage',
                 'payroll.integration.view')",
            "DELETE FROM permissions WHERE permission_code IN
                ('attendance.dashboard.view', 'leave.request',
                 'leave.manage', 'payroll.profile.manage',
                 'payroll.integration.view')",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.8'",
            "DROP TABLE IF EXISTS cpms_payroll_exports",
            "DROP TABLE IF EXISTS cpms_payroll_profiles",
            "DROP TABLE IF EXISTS cpms_leave_requests",
            "DROP TABLE IF EXISTS cpms_leave_types",
        ];
    },
];
