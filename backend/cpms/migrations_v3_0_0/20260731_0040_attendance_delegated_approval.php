<?php
declare(strict_types=1);

return [
    'key' => '20260731_0040_attendance_delegated_approval',
    'name' => 'CPMS v3.4.10 attendance administration and delegated approval',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_attendance_delegations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                delegated_by_system_user_id INT UNSIGNED NOT NULL,
                delegated_to_system_user_id INT UNSIGNED NOT NULL,
                delegation_scope VARCHAR(40) NOT NULL DEFAULT 'Timesheet',
                valid_from DATE NOT NULL,
                valid_until DATE NOT NULL,
                delegation_status VARCHAR(20) NOT NULL DEFAULT 'Active',
                reason VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_delegation_active
                    (property_id, delegated_to_system_user_id,
                     delegation_status, valid_from, valid_until),
                CONSTRAINT fk_delegation_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_delegation_from
                    FOREIGN KEY (delegated_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE CASCADE,
                CONSTRAINT fk_delegation_to
                    FOREIGN KEY (delegated_to_system_user_id)
                    REFERENCES system_users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_approval_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                attendance_month CHAR(7) NOT NULL,
                action_type VARCHAR(30) NOT NULL,
                old_status VARCHAR(20) NULL,
                new_status VARCHAR(20) NOT NULL,
                actor_system_user_id INT UNSIGNED NULL,
                delegation_id BIGINT UNSIGNED NULL,
                notes VARCHAR(500) NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_attendance_approval_audit
                    (property_id, attendance_month, created_at),
                CONSTRAINT fk_approval_audit_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_approval_audit_actor
                    FOREIGN KEY (actor_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL,
                CONSTRAINT fk_approval_audit_delegation
                    FOREIGN KEY (delegation_id)
                    REFERENCES cpms_attendance_delegations(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('attendance.admin.manage', 'Manage attendance administration',
                 'attendance', 'Manage property attendance operations'),
                ('attendance.approval.delegate',
                 'Delegate attendance approval', 'attendance',
                 'Temporarily delegate attendance approval'),
                ('attendance.approval.audit', 'View attendance approval audit',
                 'attendance', 'View timesheet approval audit history')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code IN
                ('attendance.admin.manage',
                 'attendance.approval.delegate',
                 'attendance.approval.audit')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.4.10',
                'Attendance Administration and Delegated Approval',
                'Property attendance control centre, temporary approval delegation and immutable approval audit.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
             ('attendance.admin.manage','attendance.approval.delegate',
              'attendance.approval.audit')",
            "DELETE FROM permissions WHERE permission_code IN
             ('attendance.admin.manage','attendance.approval.delegate',
              'attendance.approval.audit')",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.4.10'",
            "DROP TABLE IF EXISTS cpms_attendance_approval_audit",
            "DROP TABLE IF EXISTS cpms_attendance_delegations",
        ];
    },
];
