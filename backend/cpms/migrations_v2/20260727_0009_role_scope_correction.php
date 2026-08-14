<?php
declare(strict_types=1);

return [
    'key' => '20260727_0009_role_scope_correction',
    'name' => 'CPMS v3.0.5.1 role scope correction',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_v3_role_assignment_backup (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_key VARCHAR(100) NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                role_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NULL,
                assigned_by_user_id INT UNSIGNED NULL,
                assigned_at DATETIME NULL,
                expires_at DATETIME NULL,
                status VARCHAR(30) NOT NULL,
                backed_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_role_assignment_backup (
                    migration_key,
                    system_user_id,
                    role_id,
                    property_id
                )
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO cpms_v3_role_assignment_backup
                (
                    migration_key,
                    system_user_id,
                    role_id,
                    property_id,
                    assigned_by_user_id,
                    assigned_at,
                    expires_at,
                    status
                )
             SELECT
                '20260727_0009_role_scope_correction',
                assignments.system_user_id,
                assignments.role_id,
                assignments.property_id,
                assignments.assigned_by_user_id,
                assignments.assigned_at,
                assignments.expires_at,
                assignments.status
             FROM user_roles assignments
             INNER JOIN system_users users
                ON users.id = assignments.system_user_id
             INNER JOIN roles
                ON roles.id = assignments.role_id
             LEFT JOIN property_admins property_user
                ON users.source_table = 'property_admins'
               AND users.source_id = property_user.id
             WHERE
                (
                    users.source_table = 'property_admins'
                    AND BINARY roles.role_code <> BINARY CASE
                        WHEN LOWER(TRIM(property_user.role)) = 'manager'
                            THEN 'manager'
                        WHEN LOWER(TRIM(property_user.role)) = 'clerk'
                            THEN 'clerk'
                        ELSE 'property_admin'
                    END
                )
                OR (
                    users.source_table = 'staff'
                    AND roles.role_code <> 'staff'
                )
                OR (
                    users.source_table = 'security_guards'
                    AND roles.role_code <> 'security'
                )
                OR (
                    users.source_table = 'admins'
                    AND roles.role_code <> 'system_owner'
                )",

            "DELETE assignments
             FROM user_roles assignments
             INNER JOIN system_users users
                ON users.id = assignments.system_user_id
             INNER JOIN roles
                ON roles.id = assignments.role_id
             LEFT JOIN property_admins property_user
                ON users.source_table = 'property_admins'
               AND users.source_id = property_user.id
             WHERE
                (
                    users.source_table = 'property_admins'
                    AND BINARY roles.role_code <> BINARY CASE
                        WHEN LOWER(TRIM(property_user.role)) = 'manager'
                            THEN 'manager'
                        WHEN LOWER(TRIM(property_user.role)) = 'clerk'
                            THEN 'clerk'
                        ELSE 'property_admin'
                    END
                )
                OR (
                    users.source_table = 'staff'
                    AND roles.role_code <> 'staff'
                )
                OR (
                    users.source_table = 'security_guards'
                    AND roles.role_code <> 'security'
                )
                OR (
                    users.source_table = 'admins'
                    AND roles.role_code <> 'system_owner'
                )",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.0.5.1', 'Role Scope Correction',
                 'Removed role assignments that conflict with the canonical legacy source role.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "INSERT INTO user_roles
                (
                    system_user_id,
                    role_id,
                    property_id,
                    assigned_by_user_id,
                    assigned_at,
                    expires_at,
                    status
                )
             SELECT
                backup.system_user_id,
                backup.role_id,
                backup.property_id,
                backup.assigned_by_user_id,
                COALESCE(backup.assigned_at, NOW()),
                backup.expires_at,
                backup.status
             FROM cpms_v3_role_assignment_backup backup
             WHERE backup.migration_key =
                    '20260727_0009_role_scope_correction'
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_roles current_assignment
                    WHERE current_assignment.system_user_id =
                            backup.system_user_id
                      AND current_assignment.role_id = backup.role_id
                      AND (
                            current_assignment.property_id =
                                backup.property_id
                            OR (
                                current_assignment.property_id IS NULL
                                AND backup.property_id IS NULL
                            )
                      )
               )",

            "DELETE FROM cpms_v3_role_assignment_backup
             WHERE migration_key =
                '20260727_0009_role_scope_correction'",

            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.0.5.1'",
        ];
    },
];
