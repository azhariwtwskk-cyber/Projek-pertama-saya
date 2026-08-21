<?php
declare(strict_types=1);

return [
    'key' => '20260727_0008_existing_system_user_role_backfill',
    'name' => 'CPMS v3.0.4.1 existing system user role backfill',

    'up' => static function (mysqli $db): array {
        $columnResult = $db->query(
            "SELECT COUNT(*) AS total
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'system_users'
               AND column_name = 'role'"
        );

        if (!$columnResult) {
            throw new RuntimeException($db->error);
        }

        $columnRow = $columnResult->fetch_assoc();

        if ((int) ($columnRow['total'] ?? 0) === 0) {
            throw new RuntimeException(
                'system_users.role is unavailable for compatibility backfill.'
            );
        }

        return [
            "INSERT INTO user_roles
                (
                    system_user_id,
                    role_id,
                    property_id,
                    status
                )
             SELECT
                users.id,
                roles.id,
                CASE
                    WHEN roles.role_code = 'system_owner'
                        THEN NULL
                    ELSE users.property_id
                END,
                'active'
             FROM system_users users
             INNER JOIN roles
                ON BINARY roles.role_code = BINARY CASE
                    WHEN LOWER(TRIM(users.role)) IN (
                        'system_owner',
                        'system_admin'
                    ) THEN 'system_owner'
                    WHEN LOWER(TRIM(users.role)) = 'property_admin'
                        THEN 'property_admin'
                    WHEN LOWER(TRIM(users.role)) = 'manager'
                        THEN 'manager'
                    WHEN LOWER(TRIM(users.role)) = 'clerk'
                        THEN 'clerk'
                    WHEN LOWER(TRIM(users.role)) = 'staff'
                        THEN 'staff'
                    WHEN LOWER(TRIM(users.role)) IN (
                        'security',
                        'security_guard'
                    ) THEN 'security'
                    WHEN LOWER(TRIM(users.role)) = 'resident'
                        THEN 'resident'
                    WHEN LOWER(TRIM(users.role)) = 'contractor'
                        THEN 'contractor'
                    WHEN LOWER(TRIM(users.role)) = 'vendor'
                        THEN 'vendor'
                    ELSE ''
                END
             WHERE users.status = 'active'
               AND TRIM(COALESCE(users.role, '')) <> ''
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_roles assigned
                    WHERE assigned.system_user_id = users.id
                      AND assigned.role_id = roles.id
                      AND (
                            (
                                roles.role_code = 'system_owner'
                                AND assigned.property_id IS NULL
                            )
                            OR (
                                roles.role_code <> 'system_owner'
                                AND assigned.property_id = users.property_id
                            )
                      )
               )",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.0.4.1', 'Existing System User Role Backfill',
                 'Mapped pre-existing system_users.role values into canonical user_roles assignments.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            "DELETE assigned
             FROM user_roles assigned
             INNER JOIN system_users users
                ON users.id = assigned.system_user_id
             INNER JOIN roles
                ON roles.id = assigned.role_id
             WHERE users.source_table IS NULL
               AND (
                    (
                        LOWER(TRIM(users.role)) IN (
                            'system_owner',
                            'system_admin'
                        )
                        AND roles.role_code = 'system_owner'
                        AND assigned.property_id IS NULL
                    )
                    OR (
                        BINARY LOWER(TRIM(users.role))
                            = BINARY roles.role_code
                        AND assigned.property_id = users.property_id
                    )
               )",

            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.0.4.1'",
        ];
    },
];
