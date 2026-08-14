<?php
declare(strict_types=1);

return [
    'key' => '20260803_0052_unified_property_user_synchronization',
    'name' => 'CPMS v3.6.0.2 unified property user synchronization',
    'up' => static function (mysqli $db): array {
        return [
            "UPDATE roles SET status='active'
             WHERE role_code IN ('property_admin','manager','clerk')",

            "UPDATE system_users users
             INNER JOIN property_admins legacy
                ON users.username = TRIM(legacy.username)
               AND users.property_id = legacy.property_id
             LEFT JOIN system_users existing_source
                ON existing_source.source_table='property_admins'
               AND existing_source.source_id=legacy.id
               AND existing_source.id<>users.id
             SET users.source_table='property_admins',
                 users.source_id=legacy.id
             WHERE (users.source_table IS NULL OR users.source_table='')
               AND existing_source.id IS NULL",

            "INSERT INTO system_users (
                property_id,username,email,password_hash,full_name,phone,
                status,must_change_password,last_login_at,
                source_table,source_id
             )
             SELECT
                legacy.property_id,
                TRIM(legacy.username),
                CASE
                    WHEN email_conflict.id IS NULL
                        THEN NULLIF(TRIM(legacy.email),'')
                    ELSE NULL
                END,
                legacy.password_hash,
                CASE
                    WHEN TRIM(legacy.full_name)=''
                        THEN TRIM(legacy.username)
                    ELSE TRIM(legacy.full_name)
                END,
                NULLIF(TRIM(legacy.phone),''),
                CASE
                    WHEN LOWER(TRIM(legacy.status)) IN
                        ('active','inactive','suspended')
                        THEN LOWER(TRIM(legacy.status))
                    ELSE 'inactive'
                END,
                CASE WHEN legacy.must_change_password=1 THEN 1 ELSE 0 END,
                legacy.last_login_at,
                'property_admins',
                legacy.id
             FROM property_admins legacy
             LEFT JOIN system_users source_user
                ON source_user.source_table='property_admins'
               AND source_user.source_id=legacy.id
             LEFT JOIN system_users username_conflict
                ON username_conflict.username=TRIM(legacy.username)
             LEFT JOIN system_users email_conflict
                ON TRIM(COALESCE(legacy.email,''))<>''
               AND LOWER(email_conflict.email)=LOWER(TRIM(legacy.email))
             WHERE source_user.id IS NULL
               AND username_conflict.id IS NULL",

            "UPDATE system_users users
             INNER JOIN property_admins legacy
                ON users.source_table='property_admins'
               AND users.source_id=legacy.id
             SET users.property_id=legacy.property_id,
                 users.password_hash=legacy.password_hash,
                 users.full_name=CASE
                    WHEN TRIM(legacy.full_name)=''
                        THEN TRIM(legacy.username)
                    ELSE TRIM(legacy.full_name)
                 END,
                 users.phone=NULLIF(TRIM(legacy.phone),''),
                 users.status=CASE
                    WHEN LOWER(TRIM(legacy.status)) IN
                        ('active','inactive','suspended')
                        THEN LOWER(TRIM(legacy.status))
                    ELSE 'inactive'
                 END,
                 users.must_change_password=CASE
                    WHEN legacy.must_change_password=1 THEN 1 ELSE 0
                 END",

            "UPDATE user_roles assignments
             INNER JOIN system_users users
                ON users.id=assignments.system_user_id
               AND users.source_table='property_admins'
             INNER JOIN property_admins legacy
                ON legacy.id=users.source_id
             INNER JOIN roles assigned_role
                ON assigned_role.id=assignments.role_id
             SET assignments.status='inactive'
             WHERE assigned_role.role_code IN
                ('property_admin','manager','clerk')
               AND (
                    assignments.property_id IS NULL
                    OR assignments.property_id<>legacy.property_id
                    OR assigned_role.role_code<>CASE
                        WHEN LOWER(TRIM(legacy.role))='manager'
                            THEN 'manager'
                        WHEN LOWER(TRIM(legacy.role))='clerk'
                            THEN 'clerk'
                        ELSE 'property_admin'
                    END
               )",

            "INSERT INTO user_roles
                (system_user_id,role_id,property_id,status)
             SELECT
                users.id,
                canonical_role.id,
                legacy.property_id,
                'active'
             FROM property_admins legacy
             INNER JOIN system_users users
                ON users.source_table='property_admins'
               AND users.source_id=legacy.id
             INNER JOIN roles canonical_role
                ON canonical_role.role_code=CASE
                    WHEN LOWER(TRIM(legacy.role))='manager'
                        THEN 'manager'
                    WHEN LOWER(TRIM(legacy.role))='clerk'
                        THEN 'clerk'
                    ELSE 'property_admin'
                END
             ON DUPLICATE KEY UPDATE
                status='active',expires_at=NULL",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.6.0.2')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.6.0.2','Unified Property User Synchronization',
                'Backfills and synchronizes Property Admin, Manager and Clerk identities, property scope, password state, account status and canonical Unified Login roles.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.2'",
            "UPDATE system_settings SET setting_value='3.6.0.1'
             WHERE setting_key='software_version'",
        ];
    },
];
