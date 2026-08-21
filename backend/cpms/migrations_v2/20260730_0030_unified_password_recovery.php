<?php
declare(strict_types=1);

return [
    'key' => '20260730_0030_unified_password_recovery',
    'name' => 'CPMS v3.4.2 unified password recovery and secure reset',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                system_user_id INT UNSIGNED NOT NULL,
                selector CHAR(32) NOT NULL,
                verifier_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                requested_ip VARCHAR(45) NULL,
                requested_user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_reset_selector (selector),
                KEY idx_cpms_reset_user
                    (system_user_id, used_at, expires_at),
                KEY idx_cpms_reset_expiry (expires_at),
                CONSTRAINT fk_cpms_reset_user
                    FOREIGN KEY (system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_password_reset_settings (
                id TINYINT UNSIGNED NOT NULL DEFAULT 1,
                sender_name VARCHAR(120) NOT NULL DEFAULT 'CPMS Enterprise',
                sender_email VARCHAR(190) NOT NULL,
                reset_lifetime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES ('password_recovery.manage',
                'Manage password recovery', 'security',
                'Configure and inspect unified password recovery health')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code = 'system_owner'
               AND p.permission_code = 'password_recovery.manage'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.2',
                'Unified Password Recovery and Secure Reset',
                'Single-use expiring reset tokens, audit trail, session revocation and unified login recovery.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS cpms_password_reset_tokens',
            'DROP TABLE IF EXISTS cpms_password_reset_settings',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'password_recovery.manage'",
            "DELETE FROM permissions
             WHERE permission_code = 'password_recovery.manage'",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.2'",
        ];
    },
];
