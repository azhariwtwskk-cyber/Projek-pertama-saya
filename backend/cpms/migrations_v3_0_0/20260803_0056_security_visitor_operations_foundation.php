<?php
declare(strict_types=1);

return [
    'key' => '20260803_0056_security_visitor_operations_foundation',
    'name' => 'CPMS v3.6.0.6 security visitor operations foundation',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_visitor_passes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                resident_id BIGINT UNSIGNED NOT NULL,
                pass_code VARCHAR(40) NOT NULL,
                pass_token CHAR(64) NOT NULL,
                visitor_name VARCHAR(180) NOT NULL,
                visitor_phone VARCHAR(40) NULL,
                vehicle_no VARCHAR(30) NULL,
                host_block VARCHAR(50) NOT NULL,
                host_unit VARCHAR(50) NOT NULL,
                visit_date DATE NOT NULL,
                expected_start_time TIME NOT NULL,
                expected_end_time TIME NULL,
                visit_purpose VARCHAR(250) NOT NULL,
                security_notes VARCHAR(500) NULL,
                registration_source VARCHAR(30) NOT NULL DEFAULT 'Resident',
                visitor_status VARCHAR(30) NOT NULL DEFAULT 'Expected',
                checkin_at DATETIME NULL,
                checkout_at DATETIME NULL,
                checked_in_by_guard_id INT UNSIGNED NULL,
                checked_out_by_guard_id INT UNSIGNED NULL,
                cancelled_at DATETIME NULL,
                cancelled_by_system_user_id INT UNSIGNED NULL,
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_visitor_pass_code (pass_code),
                UNIQUE KEY uq_visitor_pass_token (pass_token),
                KEY idx_visitor_property_date
                    (property_id,visit_date,visitor_status),
                KEY idx_visitor_resident
                    (property_id,resident_id,created_at),
                KEY idx_visitor_vehicle
                    (property_id,vehicle_no,visit_date),
                CONSTRAINT fk_visitor_pass_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_visitor_pass_resident
                    FOREIGN KEY (resident_id) REFERENCES cpms_residents(id)
                    ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_visitor_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                pass_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                actor_system_user_id INT UNSIGNED NULL,
                guard_id INT UNSIGNED NULL,
                event_notes VARCHAR(500) NULL,
                event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_visitor_event
                    (property_id,pass_id,event_at),
                CONSTRAINT fk_visitor_event_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_visitor_event_pass
                    FOREIGN KEY (pass_id) REFERENCES cpms_visitor_passes(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_visitor_watchlist (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                person_name VARCHAR(180) NULL,
                visitor_phone VARCHAR(40) NULL,
                vehicle_no VARCHAR(30) NULL,
                reason VARCHAR(500) NOT NULL,
                risk_level VARCHAR(20) NOT NULL DEFAULT 'Medium',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_system_user_id INT UNSIGNED NULL,
                updated_by_system_user_id INT UNSIGNED NULL,
                deactivated_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_visitor_watchlist_active
                    (property_id,active,risk_level),
                KEY idx_visitor_watchlist_phone
                    (property_id,visitor_phone),
                KEY idx_visitor_watchlist_vehicle
                    (property_id,vehicle_no),
                KEY idx_visitor_watchlist_name
                    (property_id,person_name),
                CONSTRAINT fk_visitor_watchlist_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('visitor.view','View visitors','visitor',
                 'View property-scoped visitor records'),
                ('visitor.manage','Manage visitors','visitor',
                 'Register and update property-scoped visitor records'),
                ('visitor.pre_register','Pre-register visitors','visitor',
                 'Create and manage own resident visitor passes'),
                ('visitor.checkin','Visitor check-in and check-out','visitor',
                 'Security arrival and departure processing'),
                ('visitor.monitor','Monitor property visitors','visitor',
                 'Monitor property-scoped visitor activity'),
                ('visitor.watchlist.view','View visitor watchlist','visitor',
                 'View property-scoped visitor watchlist alerts'),
                ('visitor.watchlist.manage','Manage visitor watchlist','visitor',
                 'Add and update property-scoped watchlist entries'),
                ('visitor.export','Export visitor register','visitor',
                 'Export property-scoped daily visitor registers')",

            "UPDATE permissions SET status='active'
             WHERE permission_code IN (
                'visitor.view','visitor.manage','visitor.pre_register',
                'visitor.checkin','visitor.monitor',
                'visitor.watchlist.view','visitor.watchlist.manage',
                'visitor.export'
             )",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='resident' AND r.status='active'
               AND p.status='active'
               AND p.permission_code IN (
                    'visitor.view','visitor.pre_register'
               )",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='security' AND r.status='active'
               AND p.status='active'
               AND p.permission_code IN (
                    'visitor.view','visitor.manage','visitor.checkin',
                    'visitor.watchlist.view'
               )",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner','property_admin','manager'
               )
               AND r.status='active' AND p.status='active'
               AND p.permission_code IN (
                    'visitor.view','visitor.manage','visitor.monitor',
                    'visitor.watchlist.view','visitor.watchlist.manage',
                    'visitor.export'
               )",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='clerk' AND r.status='active'
               AND p.status='active'
               AND p.permission_code IN (
                    'visitor.view','visitor.monitor',
                    'visitor.watchlist.view','visitor.export'
               )",

            "INSERT INTO cpms_property_modules
                (property_id,module_key,is_enabled,updated_at)
             SELECT id,'visitor_management',1,NOW()
             FROM cpms_properties
             ON DUPLICATE KEY UPDATE
                is_enabled=VALUES(is_enabled),updated_at=NOW()",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.6.0.6')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES (
                '3.6.0.6','Security Visitor Operations Foundation',
                'Self-contained visitor pass, manual QR verification, property watchlist, denied workflow and safe daily CSV export.'
             )",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.6'",
            "UPDATE system_settings SET setting_value='3.6.0.5'
             WHERE setting_key='software_version'",
        ];
    },
];
