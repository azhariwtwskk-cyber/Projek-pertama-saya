<?php
declare(strict_types=1);

return [
    'key' => '20260730_0034_mobile_gps_attendance_geofence',
    'name' => 'CPMS v3.4.5 mobile GPS attendance and geofencing foundation',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_property_geofences (
                property_id INT UNSIGNED NOT NULL,
                latitude DECIMAL(10,7) NOT NULL,
                longitude DECIMAL(10,7) NOT NULL,
                radius_m INT UNSIGNED NOT NULL DEFAULT 200,
                maximum_accuracy_m INT UNSIGNED NOT NULL DEFAULT 100,
                enforcement_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_by_system_user_id INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (property_id),
                CONSTRAINT fk_geofence_property FOREIGN KEY (property_id)
                    REFERENCES cpms_properties(id),
                CONSTRAINT fk_geofence_updated_by
                    FOREIGN KEY (updated_by_system_user_id)
                    REFERENCES system_users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                user_role VARCHAR(40) NOT NULL,
                work_date DATE NOT NULL,
                clock_in_at DATETIME NOT NULL,
                clock_in_latitude DECIMAL(10,7) NOT NULL,
                clock_in_longitude DECIMAL(10,7) NOT NULL,
                clock_in_accuracy_m DECIMAL(10,2) NOT NULL,
                clock_in_distance_m DECIMAL(10,2) NOT NULL,
                clock_out_at DATETIME NULL,
                clock_out_latitude DECIMAL(10,7) NULL,
                clock_out_longitude DECIMAL(10,7) NULL,
                clock_out_accuracy_m DECIMAL(10,2) NULL,
                clock_out_distance_m DECIMAL(10,2) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Open',
                open_session_key VARCHAR(100) NULL,
                device_fingerprint CHAR(64) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_attendance_user
                    (property_id, system_user_id, clock_in_at),
                KEY idx_attendance_status (property_id, status, work_date),
                UNIQUE KEY uq_attendance_open_session (open_session_key),
                CONSTRAINT fk_attendance_property FOREIGN KEY (property_id)
                    REFERENCES cpms_properties(id),
                CONSTRAINT fk_attendance_user FOREIGN KEY (system_user_id)
                    REFERENCES system_users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_attendance_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                system_user_id INT UNSIGNED NOT NULL,
                action_type VARCHAR(20) NOT NULL,
                accepted TINYINT(1) NOT NULL DEFAULT 0,
                reason_code VARCHAR(60) NOT NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                accuracy_m DECIMAL(10,2) NULL,
                distance_m DECIMAL(10,2) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_attendance_event_user
                    (property_id, system_user_id, created_at),
                KEY idx_attendance_event_result
                    (property_id, accepted, created_at),
                CONSTRAINT fk_attendance_event_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id),
                CONSTRAINT fk_attendance_event_user
                    FOREIGN KEY (system_user_id) REFERENCES system_users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('attendance.clock', 'Clock in and clock out',
                 'attendance', 'Record GPS attendance inside property geofence'),
                ('attendance.view', 'View attendance',
                 'attendance', 'View property attendance records'),
                ('attendance.geofence.manage', 'Manage attendance geofence',
                 'attendance', 'Configure property GPS centre and radius')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('staff', 'security')
               AND p.permission_code = 'attendance.clock'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner', 'property_admin', 'manager')
               AND p.permission_code IN
                ('attendance.view', 'attendance.geofence.manage')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES ('3.4.5',
                'Mobile GPS Attendance and Geofencing Foundation',
                'Server-validated clock in/out with property geofence, GPS accuracy and audit events.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN
                ('attendance.clock', 'attendance.view',
                 'attendance.geofence.manage')",
            "DELETE FROM permissions WHERE permission_code IN
                ('attendance.clock', 'attendance.view',
                 'attendance.geofence.manage')",
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '3.4.5'",
            "DROP TABLE IF EXISTS cpms_attendance_events",
            "DROP TABLE IF EXISTS cpms_attendance_sessions",
            "DROP TABLE IF EXISTS cpms_property_geofences",
        ];
    },
];
