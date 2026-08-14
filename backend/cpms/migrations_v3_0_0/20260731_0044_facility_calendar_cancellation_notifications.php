<?php
declare(strict_types=1);

return [
    'key' => '20260731_0044_facility_calendar_cancellation_notifications',
    'name' => 'CPMS v3.5.3 facility calendar cancellation and resident notifications',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_resident_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                resident_id INT NOT NULL,
                notification_type VARCHAR(40) NOT NULL DEFAULT 'general',
                title VARCHAR(180) NOT NULL,
                message VARCHAR(500) NOT NULL,
                action_url VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_resident_notification
                    (property_id,resident_id,is_read,created_at),
                CONSTRAINT fk_resident_notification_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_facility_booking_cancellations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                booking_id BIGINT UNSIGNED NOT NULL,
                resident_id INT NOT NULL,
                reason VARCHAR(500) NOT NULL,
                request_status VARCHAR(25) NOT NULL DEFAULT 'Pending',
                reviewed_by_system_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                review_notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_facility_cancellation_booking (booking_id),
                KEY idx_facility_cancellation_property
                    (property_id,request_status,created_at),
                CONSTRAINT fk_facility_cancellation_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_facility_cancellation_booking
                    FOREIGN KEY (booking_id) REFERENCES cpms_facility_bookings(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('resident.notifications.view','View resident notifications',
                 'resident_portal','View property-scoped resident notifications'),
                ('facility.booking.calendar','View facility booking calendar',
                 'facilities','View property facility booking calendar')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner','property_admin','manager','resident')
               AND p.permission_code IN
                ('resident.notifications.view','facility.booking.calendar')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.3',
                'Facility Calendar Cancellation and Resident Notifications',
                'Resident booking calendar, controlled cancellation workflow and resident notification centre.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.3'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('resident.notifications.view','facility.booking.calendar')",
            "DELETE FROM permissions WHERE permission_code IN
                ('resident.notifications.view','facility.booking.calendar')",
            "DROP TABLE IF EXISTS cpms_facility_booking_cancellations",
            "DROP TABLE IF EXISTS cpms_resident_notifications",
        ];
    },
];
