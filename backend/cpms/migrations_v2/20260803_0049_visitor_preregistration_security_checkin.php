<?php
declare(strict_types=1);

return [
    'key' => '20260803_0049_visitor_preregistration_security_checkin',
    'name' => 'CPMS v3.5.9 visitor pre-registration and security check-in',
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

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('visitor.pre_register','Pre-register visitors','visitor',
                 'Create and manage own resident visitor passes'),
                ('visitor.checkin','Visitor check-in and check-out','visitor',
                 'Security arrival and departure processing'),
                ('visitor.monitor','Monitor property visitors','visitor',
                 'Monitor property-scoped visitor activity')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='resident' AND r.status='active'
               AND p.status='active' AND p.permission_code IN
                   ('visitor.view','visitor.pre_register')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='security' AND r.status='active'
               AND p.status='active' AND p.permission_code IN
                   ('visitor.view','visitor.manage','visitor.checkin')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                   ('system_owner','property_admin','manager','clerk')
               AND r.status='active' AND p.status='active'
               AND p.permission_code IN
                   ('visitor.view','visitor.manage','visitor.monitor')",

            "INSERT IGNORE INTO cpms_property_modules
                (property_id,module_key,is_enabled,updated_at)
             SELECT id,'visitor_management',1,NOW()
             FROM cpms_properties",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.9',
                'Visitor Pre-Registration and Security Check-In',
                'Resident visitor passes, walk-in registration, security check-in/out, notifications and property monitoring.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.9'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('visitor.pre_register','visitor.checkin','visitor.monitor')",
            "DELETE FROM permissions WHERE permission_code IN
                ('visitor.pre_register','visitor.checkin','visitor.monitor')",
            "DROP TABLE IF EXISTS cpms_visitor_events",
            "DROP TABLE IF EXISTS cpms_visitor_passes",
        ];
    },
];
