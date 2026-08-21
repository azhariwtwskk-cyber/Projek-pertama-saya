<?php
declare(strict_types=1);

return [
    'key' => '20260801_0045_workforce_android_api_foundation',
    'name' => 'CPMS v4.0.1 Workforce Android production API foundation',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_api_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                system_user_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                role_name VARCHAR(40) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                device_name VARCHAR(120) NULL,
                app_version VARCHAR(30) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                expires_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_api_token_hash (token_hash),
                KEY idx_cpms_api_token_user
                    (system_user_id,property_id,role_name,revoked_at,expires_at),
                CONSTRAINT fk_cpms_api_token_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_cpms_api_token_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_api_login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                login_key_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                succeeded TINYINT(1) NOT NULL DEFAULT 0,
                user_agent VARCHAR(500) NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cpms_api_login_limit
                    (login_key_hash,ip_address,succeeded,attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_api_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NULL,
                system_user_id INT UNSIGNED NULL,
                action_name VARCHAR(100) NOT NULL,
                outcome VARCHAR(30) NOT NULL DEFAULT 'success',
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                metadata_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cpms_api_audit_property
                    (property_id,action_name,created_at),
                KEY idx_cpms_api_audit_user
                    (system_user_id,created_at),
                CONSTRAINT fk_cpms_api_audit_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_cpms_api_audit_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_workforce_patrol_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                guard_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                patrol_reference VARCHAR(50) NOT NULL,
                started_at DATETIME NOT NULL,
                start_latitude DECIMAL(10,7) NOT NULL,
                start_longitude DECIMAL(10,7) NOT NULL,
                start_accuracy_m DECIMAL(10,2) NOT NULL,
                start_distance_m DECIMAL(10,2) NULL,
                completed_at DATETIME NULL,
                end_latitude DECIMAL(10,7) NULL,
                end_longitude DECIMAL(10,7) NULL,
                end_accuracy_m DECIMAL(10,2) NULL,
                patrol_notes TEXT NULL,
                linked_patrol_id INT UNSIGNED NULL,
                session_status VARCHAR(20) NOT NULL DEFAULT 'Active',
                active_session_key VARCHAR(100) NULL,
                device_info VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_workforce_patrol_reference (patrol_reference),
                UNIQUE KEY uq_workforce_active_patrol (active_session_key),
                KEY idx_workforce_patrol_guard
                    (property_id,guard_id,session_status,started_at),
                CONSTRAINT fk_workforce_patrol_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_workforce_patrol_guard
                    FOREIGN KEY (guard_id) REFERENCES security_guards(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_workforce_patrol_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_workforce_patrol_link
                    FOREIGN KEY (linked_patrol_id) REFERENCES security_patrols(id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_workforce_checkpoint_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                patrol_session_id BIGINT UNSIGNED NOT NULL,
                checkpoint_id INT UNSIGNED NOT NULL,
                guard_id INT UNSIGNED NOT NULL,
                scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                accuracy_m DECIMAL(10,2) NULL,
                device_info VARCHAR(500) NULL,
                remarks TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_workforce_checkpoint_session
                    (patrol_session_id,checkpoint_id),
                KEY idx_workforce_checkpoint_property
                    (property_id,guard_id,scanned_at),
                CONSTRAINT fk_workforce_checkpoint_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_workforce_checkpoint_session
                    FOREIGN KEY (patrol_session_id)
                    REFERENCES cpms_workforce_patrol_sessions(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_workforce_checkpoint_checkpoint
                    FOREIGN KEY (checkpoint_id) REFERENCES security_checkpoints(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_workforce_checkpoint_guard
                    FOREIGN KEY (guard_id) REFERENCES security_guards(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_security_incidents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                guard_id INT UNSIGNED NOT NULL,
                patrol_session_id BIGINT UNSIGNED NULL,
                incident_reference VARCHAR(50) NOT NULL,
                location_name VARCHAR(190) NOT NULL,
                description TEXT NOT NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                accuracy_m DECIMAL(10,2) NULL,
                photo_path VARCHAR(500) NULL,
                incident_status VARCHAR(30) NOT NULL DEFAULT 'Open',
                reviewed_by_system_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                review_notes VARCHAR(1000) NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_security_incident_ref (incident_reference),
                KEY idx_cpms_security_incident_property
                    (property_id,incident_status,recorded_at),
                KEY idx_cpms_security_incident_guard
                    (guard_id,recorded_at),
                CONSTRAINT fk_cpms_security_incident_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_cpms_security_incident_guard
                    FOREIGN KEY (guard_id) REFERENCES security_guards(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_cpms_security_incident_session
                    FOREIGN KEY (patrol_session_id)
                    REFERENCES cpms_workforce_patrol_sessions(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_cpms_security_incident_reviewer
                    FOREIGN KEY (reviewed_by_system_user_id)
                    REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('workforce.api.use','Use CPMS Workforce Android API',
                 'workforce','Use property-scoped Android Workforce API'),
                ('security.incident.mobile','Create mobile security incident',
                 'security','Submit security incident from Android application')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff','security')
               AND p.permission_code='workforce.api.use'",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='security'
               AND p.permission_code='security.incident.mobile'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('4.0.1','Workforce Android Production API Foundation',
                'Bearer token authentication, property isolation, GPS attendance, patrol sessions, checkpoints, incidents, photo upload and API audit.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='4.0.1'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('workforce.api.use','security.incident.mobile')",
            "DELETE FROM permissions WHERE permission_code IN
                ('workforce.api.use','security.incident.mobile')",
            "DROP TABLE IF EXISTS cpms_security_incidents",
            "DROP TABLE IF EXISTS cpms_workforce_checkpoint_events",
            "DROP TABLE IF EXISTS cpms_workforce_patrol_sessions",
            "DROP TABLE IF EXISTS cpms_api_audit_logs",
            "DROP TABLE IF EXISTS cpms_api_login_attempts",
            "DROP TABLE IF EXISTS cpms_api_tokens",
        ];
    },
];

