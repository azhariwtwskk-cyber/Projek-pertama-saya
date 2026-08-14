<?php
declare(strict_types=1);

return [
    'key' => '20260727_0007_legacy_user_migration',
    'name' => 'CPMS v3.0.2 legacy user migration',

    'up' => static function (mysqli $db): array {
        $sql = [];

        $columnExists = static function (
            string $table,
            string $column
        ) use ($db): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?'
            );

            if (!$stmt) {
                throw new RuntimeException($db->error);
            }

            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : [];
            $stmt->close();

            return (int) ($row['total'] ?? 0) > 0;
        };

        $requiredUserColumns = [
            'property_id' => 'INT UNSIGNED NULL AFTER id',
            'email' => 'VARCHAR(190) NULL AFTER username',
            'password_hash' => 'VARCHAR(255) NULL AFTER email',
            'full_name' => 'VARCHAR(190) NULL AFTER password_hash',
            'phone' => 'VARCHAR(50) NULL AFTER full_name',
            'status' => "VARCHAR(30) NOT NULL DEFAULT 'active' AFTER phone",
            'must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
            'last_login_at' => 'DATETIME NULL AFTER must_change_password',
            'source_table' => 'VARCHAR(64) NULL AFTER last_login_at',
            'source_id' => 'INT UNSIGNED NULL AFTER source_table',
            'created_by_user_id' => 'INT UNSIGNED NULL AFTER source_id',
            'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id',
            'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ];

        foreach ($requiredUserColumns as $column => $definition) {
            if ($columnExists('system_users', $column)) {
                continue;
            }

            $sql[] = 'ALTER TABLE system_users ADD COLUMN `'
                . str_replace('`', '``', $column)
                . '` '
                . $definition;
        }

        $requiredRoles = [
            'system_owner',
            'property_admin',
            'manager',
            'clerk',
            'staff',
            'security',
        ];

        $escapedRoles = array_map(
            static function (string $role) use ($db): string {
                return "'" . $db->real_escape_string($role) . "'";
            },
            $requiredRoles
        );

        $roleResult = $db->query(
            'SELECT COUNT(*) AS total
             FROM roles
             WHERE role_code IN (' . implode(', ', $escapedRoles) . ')
               AND status = \'active\''
        );

        if (!$roleResult) {
            throw new RuntimeException($db->error);
        }

        $roleRow = $roleResult->fetch_assoc();

        if ((int) ($roleRow['total'] ?? 0) !== count($requiredRoles)) {
            throw new RuntimeException(
                'Unified roles are incomplete. Run migration 0006 first.'
            );
        }

        $hasLegacySourceColumns = $columnExists(
            'system_users',
            'source_table'
        ) && $columnExists('system_users', 'source_id');

        $conflictChecks = [
            [
                'label' => 'admins username',
                'sql' => "SELECT COUNT(*) AS total
                          FROM admins legacy
                          INNER JOIN system_users target
                              ON LOWER(target.username) = LOWER(legacy.username)
                          WHERE NOT (
                              target.source_table = 'admins'
                              AND target.source_id = legacy.id
                          )",
            ],
            [
                'label' => 'property_admins username',
                'sql' => "SELECT COUNT(*) AS total
                          FROM property_admins legacy
                          INNER JOIN system_users target
                              ON LOWER(target.username) = LOWER(legacy.username)
                          WHERE NOT (
                              target.source_table = 'property_admins'
                              AND target.source_id = legacy.id
                          )",
            ],
            [
                'label' => 'staff username',
                'sql' => "SELECT COUNT(*) AS total
                          FROM staff legacy
                          INNER JOIN system_users target
                              ON LOWER(target.username) = LOWER(legacy.username)
                          WHERE NOT (
                              target.source_table = 'staff'
                              AND target.source_id = legacy.id
                          )",
            ],
            [
                'label' => 'security_guards username',
                'sql' => "SELECT COUNT(*) AS total
                          FROM security_guards legacy
                          INNER JOIN system_users target
                              ON LOWER(target.username) = LOWER(legacy.username)
                          WHERE NOT (
                              target.source_table = 'security_guards'
                              AND target.source_id = legacy.id
                          )",
            ],
            [
                'label' => 'property_admins email',
                'sql' => "SELECT COUNT(*) AS total
                          FROM property_admins legacy
                          INNER JOIN system_users target
                              ON LOWER(target.email) = LOWER(legacy.email)
                          WHERE legacy.email IS NOT NULL
                            AND TRIM(legacy.email) <> ''
                            AND NOT (
                                target.source_table = 'property_admins'
                                AND target.source_id = legacy.id
                            )",
            ],
        ];

        if ($hasLegacySourceColumns) {
            foreach ($conflictChecks as $check) {
                $result = $db->query($check['sql']);

                if (!$result) {
                    throw new RuntimeException($db->error);
                }

                $row = $result->fetch_assoc();
                $count = (int) ($row['total'] ?? 0);

                if ($count > 0) {
                    throw new RuntimeException(
                        'Migration stopped: '
                        . $count
                        . ' conflict(s) detected for '
                        . $check['label']
                        . '.'
                    );
                }
            }
        }

        return array_merge($sql, [
            "INSERT INTO system_users
                (
                    property_id,
                    username,
                    email,
                    password_hash,
                    full_name,
                    phone,
                    status,
                    must_change_password,
                    source_table,
                    source_id
                )
             SELECT
                NULL,
                TRIM(legacy.username),
                NULL,
                legacy.password,
                TRIM(legacy.username),
                NULL,
                'active',
                0,
                'admins',
                legacy.id
             FROM admins legacy
             WHERE NOT EXISTS (
                SELECT 1
                FROM system_users target
                WHERE target.source_table = 'admins'
                  AND target.source_id = legacy.id
             )",

            "INSERT INTO system_users
                (
                    property_id,
                    username,
                    email,
                    password_hash,
                    full_name,
                    phone,
                    status,
                    must_change_password,
                    last_login_at,
                    source_table,
                    source_id
                )
             SELECT
                legacy.property_id,
                TRIM(legacy.username),
                NULLIF(TRIM(legacy.email), ''),
                legacy.password_hash,
                CASE
                    WHEN TRIM(legacy.full_name) = ''
                        THEN TRIM(legacy.username)
                    ELSE TRIM(legacy.full_name)
                END,
                legacy.phone,
                CASE
                    WHEN LOWER(TRIM(legacy.status)) IN
                        ('active', 'inactive', 'suspended')
                        THEN LOWER(TRIM(legacy.status))
                    ELSE 'active'
                END,
                legacy.must_change_password,
                legacy.last_login_at,
                'property_admins',
                legacy.id
             FROM property_admins legacy
             WHERE NOT EXISTS (
                SELECT 1
                FROM system_users target
                WHERE target.source_table = 'property_admins'
                  AND target.source_id = legacy.id
             )",

            "INSERT INTO system_users
                (
                    property_id,
                    username,
                    email,
                    password_hash,
                    full_name,
                    phone,
                    status,
                    must_change_password,
                    source_table,
                    source_id
                )
             SELECT
                legacy.property_id,
                TRIM(legacy.username),
                NULL,
                legacy.password,
                CASE
                    WHEN TRIM(legacy.full_name) = ''
                        THEN TRIM(legacy.username)
                    ELSE TRIM(legacy.full_name)
                END,
                NULL,
                'active',
                0,
                'staff',
                legacy.id
             FROM staff legacy
             WHERE NOT EXISTS (
                SELECT 1
                FROM system_users target
                WHERE target.source_table = 'staff'
                  AND target.source_id = legacy.id
             )",

            "INSERT INTO system_users
                (
                    property_id,
                    username,
                    email,
                    password_hash,
                    full_name,
                    phone,
                    status,
                    must_change_password,
                    source_table,
                    source_id
                )
             SELECT
                legacy.property_id,
                TRIM(legacy.username),
                NULL,
                legacy.password,
                CASE
                    WHEN TRIM(legacy.full_name) = ''
                        THEN TRIM(legacy.username)
                    ELSE TRIM(legacy.full_name)
                END,
                NULL,
                'active',
                0,
                'security_guards',
                legacy.id
             FROM security_guards legacy
             WHERE NOT EXISTS (
                SELECT 1
                FROM system_users target
                WHERE target.source_table = 'security_guards'
                  AND target.source_id = legacy.id
             )",

            "INSERT INTO user_roles
                (system_user_id, role_id, property_id, status)
             SELECT
                users.id,
                roles.id,
                NULL,
                'active'
             FROM system_users users
             INNER JOIN roles
                ON roles.role_code = 'system_owner'
             WHERE users.source_table = 'admins'
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_roles assigned
                    WHERE assigned.system_user_id = users.id
                      AND assigned.role_id = roles.id
                      AND assigned.property_id IS NULL
               )",

            "INSERT INTO user_roles
                (system_user_id, role_id, property_id, status)
             SELECT
                users.id,
                roles.id,
                users.property_id,
                'active'
             FROM system_users users
             INNER JOIN property_admins legacy
                ON users.source_table = 'property_admins'
               AND users.source_id = legacy.id
             INNER JOIN roles
                ON roles.role_code = CASE
                    WHEN LOWER(TRIM(legacy.role)) = 'manager'
                        THEN 'manager'
                    WHEN LOWER(TRIM(legacy.role)) = 'clerk'
                        THEN 'clerk'
                    ELSE 'property_admin'
                END
             WHERE NOT EXISTS (
                SELECT 1
                FROM user_roles assigned
                WHERE assigned.system_user_id = users.id
                  AND assigned.role_id = roles.id
                  AND assigned.property_id = users.property_id
             )",

            "INSERT INTO user_roles
                (system_user_id, role_id, property_id, status)
             SELECT
                users.id,
                roles.id,
                users.property_id,
                'active'
             FROM system_users users
             INNER JOIN roles
                ON roles.role_code = 'staff'
             WHERE users.source_table = 'staff'
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_roles assigned
                    WHERE assigned.system_user_id = users.id
                      AND assigned.role_id = roles.id
                      AND assigned.property_id = users.property_id
               )",

            "INSERT INTO user_roles
                (system_user_id, role_id, property_id, status)
             SELECT
                users.id,
                roles.id,
                users.property_id,
                'active'
             FROM system_users users
             INNER JOIN roles
                ON roles.role_code = 'security'
             WHERE users.source_table = 'security_guards'
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_roles assigned
                    WHERE assigned.system_user_id = users.id
                      AND assigned.role_id = roles.id
                      AND assigned.property_id = users.property_id
               )",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.0.2', 'Legacy User Migration',
                 'Copied validated legacy accounts into system_users and user_roles. Legacy tables remain active.')",
        ]);
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE assigned
             FROM user_roles assigned
             INNER JOIN system_users users
                ON users.id = assigned.system_user_id
             WHERE users.source_table IN (
                'admins',
                'property_admins',
                'staff',
                'security_guards'
             )",

            "DELETE FROM system_users
             WHERE source_table IN (
                'admins',
                'property_admins',
                'staff',
                'security_guards'
             )",

            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.0.2'",
        ];
    },
];
