<?php
declare(strict_types=1);

return [
    'key' => '20260728_0010_unified_auth_security_foundation',
    'name' => 'CPMS v3.0.7 unified auth security foundation',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_auth_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_type VARCHAR(50) NOT NULL,
                user_id INT UNSIGNED NULL,
                property_id INT UNSIGNED NULL,
                event_type VARCHAR(80) NOT NULL,
                description VARCHAR(1000) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_auth_audit_user (user_type, user_id),
                KEY idx_auth_audit_property (property_id),
                KEY idx_auth_audit_event (event_type),
                KEY idx_auth_audit_created (created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_auth_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_type VARCHAR(50) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NULL,
                role_name VARCHAR(80) NOT NULL,
                session_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                last_activity_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_auth_session_hash (session_hash),
                KEY idx_auth_session_user (user_type, user_id),
                KEY idx_auth_session_property (property_id),
                KEY idx_auth_session_active (revoked_at, last_activity_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_auth_login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                login_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                failure_count INT UNSIGNED NOT NULL DEFAULT 0,
                first_failed_at DATETIME NOT NULL,
                last_failed_at DATETIME NOT NULL,
                locked_until DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_auth_attempt_identity_ip
                    (login_hash, ip_address),
                KEY idx_auth_attempt_locked (locked_until),
                KEY idx_auth_attempt_updated (updated_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.0.7', 'Unified Login Audit Trail & Security Hardening',
                 'Database login throttling, authentication audit events and unified session tracking.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS cpms_auth_login_attempts',
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.0.7'",
        ];
    },
];
