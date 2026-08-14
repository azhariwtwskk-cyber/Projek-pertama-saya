<?php
declare(strict_types=1);

return [
    'key' => '20260803_0054_clerk_module_access',
    'name' => 'CPMS v3.6.0.4 property-scoped Clerk module access',
    'up' => static function (mysqli $db): array {
        $sql = [
            "CREATE TABLE IF NOT EXISTS cpms_user_permission_grants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                system_user_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                assigned_by_system_user_id INT UNSIGNED NULL,
                grant_source VARCHAR(60) NOT NULL
                    DEFAULT 'clerk_module_assignment',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_property_permission (
                    system_user_id,property_id,permission_id
                ),
                KEY idx_user_permission_property (
                    property_id,system_user_id,status
                ),
                KEY idx_user_permission_permission (permission_id),
                KEY idx_user_permission_assigned_by (
                    assigned_by_system_user_id
                ),
                CONSTRAINT fk_user_permission_user
                    FOREIGN KEY (system_user_id)
                    REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_user_permission_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_user_permission_permission
                    FOREIGN KEY (permission_id)
                    REFERENCES permissions(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_user_permission_assigned_by
                    FOREIGN KEY (assigned_by_system_user_id)
                    REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_resident_announcement_reads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                announcement_id BIGINT UNSIGNED NOT NULL,
                resident_id INT NOT NULL,
                viewed_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_announcement_resident (
                    announcement_id,resident_id
                ),
                KEY idx_announcement_read_property (
                    property_id,resident_id
                ),
                CONSTRAINT fk_announcement_read_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_announcement_read_announcement
                    FOREIGN KEY (announcement_id)
                    REFERENCES cpms_resident_announcements(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('clerk.modules.assign','Assign Clerk modules',
                 'permissions',
                 'Assign safe property-scoped modules to Clerk accounts')",

            "UPDATE permissions SET status='active'
             WHERE permission_code IN (
                'clerk.modules.assign','notices.view','notices.manage',
                'resident.announcement.manage','facilities.view',
                'facilities.manage','facility.booking.approve',
                'facility.booking.cancel','facility.booking.calendar',
                'work_orders.view','work_orders.create',
                'work_orders.update','residents.view',
                'resident.request.manage','inspection.view',
                'inspection.create','inspection.update',
                'reports.view','reports.export'
             )",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner','property_admin','manager'
               )
               AND r.status='active'
               AND p.status='active'
               AND p.permission_code='clerk.modules.assign'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES (
                '3.6.0.4','Clerk Module Access',
                'Property-scoped additional module assignment for Clerk accounts, including Announcement and Facility Booking management.'
             )",
        ];

        $columnExists = static function (
            string $table,
            string $column
        ) use ($db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $columnSql = [];

        if (!$columnExists(
            'cpms_resident_announcements',
            'notice_type'
        )) {
            $columnSql[] = "ALTER TABLE cpms_resident_announcements
                ADD COLUMN notice_type VARCHAR(30) NOT NULL
                    DEFAULT 'General' AFTER message";
        }

        if (!$columnExists(
            'cpms_resident_announcements',
            'priority'
        )) {
            $columnSql[] = "ALTER TABLE cpms_resident_announcements
                ADD COLUMN priority VARCHAR(20) NOT NULL
                    DEFAULT 'Normal' AFTER notice_type";
        }

        if (!$columnExists(
            'cpms_resident_announcements',
            'requires_confirmation'
        )) {
            $columnSql[] = "ALTER TABLE cpms_resident_announcements
                ADD COLUMN requires_confirmation TINYINT(1) NOT NULL
                    DEFAULT 0 AFTER priority";
        }

        return array_merge($columnSql, $sql);
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             INNER JOIN permissions p ON p.id=rp.permission_id
             WHERE p.permission_code='clerk.modules.assign'",
            "DELETE FROM permissions
             WHERE permission_code='clerk.modules.assign'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.4'",
            "DROP TABLE IF EXISTS cpms_user_permission_grants",
        ];
    },
];
