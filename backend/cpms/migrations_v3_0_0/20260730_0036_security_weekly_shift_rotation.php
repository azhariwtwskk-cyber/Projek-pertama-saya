<?php
declare(strict_types=1);

return [
    'key' => '20260730_0036_security_weekly_shift_rotation',
    'name' => 'CPMS v3.4.6.1 security weekly shift rotation hotfix',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_attendance_rotation_plans (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                rotation_name VARCHAR(120) NOT NULL,
                anchor_date DATE NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_rotation_plan_property (property_id, status),
                CONSTRAINT fk_rotation_plan_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_rotation_plan_creator
                    FOREIGN KEY (created_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_rotation_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                rotation_plan_id BIGINT UNSIGNED NOT NULL,
                shift_id BIGINT UNSIGNED NOT NULL,
                sequence_order INT UNSIGNED NOT NULL,
                duration_weeks INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rotation_sequence
                    (rotation_plan_id, sequence_order),
                KEY idx_rotation_item_shift (shift_id),
                CONSTRAINT fk_rotation_item_plan
                    FOREIGN KEY (rotation_plan_id)
                    REFERENCES cpms_attendance_rotation_plans(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_rotation_item_shift
                    FOREIGN KEY (shift_id)
                    REFERENCES cpms_attendance_shifts(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_rotation_assignments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                rotation_plan_id BIGINT UNSIGNED NOT NULL,
                effective_from DATE NOT NULL,
                effective_until DATE NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                assigned_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_rotation_assignment_user
                    (property_id, system_user_id, effective_from, status),
                KEY idx_rotation_assignment_plan
                    (rotation_plan_id, status),
                CONSTRAINT fk_rotation_assignment_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_rotation_assignment_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id),
                CONSTRAINT fk_rotation_assignment_plan
                    FOREIGN KEY (rotation_plan_id)
                    REFERENCES cpms_attendance_rotation_plans(id),
                CONSTRAINT fk_rotation_assignment_creator
                    FOREIGN KEY (assigned_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE cpms_attendance_sessions
                ADD COLUMN rotation_assignment_id BIGINT UNSIGNED NULL
                    AFTER shift_assignment_id,
                ADD COLUMN resolved_shift_id BIGINT UNSIGNED NULL
                    AFTER rotation_assignment_id,
                ADD KEY idx_attendance_rotation_assignment
                    (rotation_assignment_id),
                ADD KEY idx_attendance_resolved_shift (resolved_shift_id),
                ADD CONSTRAINT fk_attendance_session_rotation_assignment
                    FOREIGN KEY (rotation_assignment_id)
                    REFERENCES cpms_attendance_rotation_assignments(id)
                    ON DELETE SET NULL,
                ADD CONSTRAINT fk_attendance_session_resolved_shift
                    FOREIGN KEY (resolved_shift_id)
                    REFERENCES cpms_attendance_shifts(id)
                    ON DELETE SET NULL",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.6.1',
                'Security Weekly Shift Rotation Hotfix',
                'Automatic weekly day and night shift rotation for security personnel.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.4.6.1'",
            "ALTER TABLE cpms_attendance_sessions
                DROP FOREIGN KEY fk_attendance_session_rotation_assignment,
                DROP FOREIGN KEY fk_attendance_session_resolved_shift,
                DROP INDEX idx_attendance_rotation_assignment,
                DROP INDEX idx_attendance_resolved_shift,
                DROP COLUMN rotation_assignment_id,
                DROP COLUMN resolved_shift_id",
            "DROP TABLE IF EXISTS cpms_attendance_rotation_assignments",
            "DROP TABLE IF EXISTS cpms_attendance_rotation_items",
            "DROP TABLE IF EXISTS cpms_attendance_rotation_plans",
        ];
    },
];
