<?php
declare(strict_types=1);

return [
    'key' => '20260731_0043_resident_facility_booking_foundation',
    'name' => 'CPMS v3.5.2 resident facility booking and approval',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_facilities (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                facility_name VARCHAR(150) NOT NULL,
                description VARCHAR(500) NULL,
                location VARCHAR(180) NULL,
                capacity INT UNSIGNED NULL,
                opening_time TIME NOT NULL DEFAULT '08:00:00',
                closing_time TIME NOT NULL DEFAULT '22:00:00',
                slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                booking_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                advance_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                approval_required TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_facility_property_name
                    (property_id,facility_name),
                KEY idx_facility_property_active (property_id,active),
                CONSTRAINT fk_facility_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_facility_bookings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                facility_id INT UNSIGNED NOT NULL,
                resident_id INT NOT NULL,
                booking_reference VARCHAR(40) NOT NULL,
                booking_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                purpose VARCHAR(250) NOT NULL,
                guest_count INT UNSIGNED NOT NULL DEFAULT 1,
                booking_status VARCHAR(25) NOT NULL DEFAULT 'Pending',
                fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_status VARCHAR(25) NOT NULL DEFAULT 'Not Required',
                reviewed_by_system_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                review_notes VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_facility_booking_reference (booking_reference),
                KEY idx_facility_booking_slot
                    (property_id,facility_id,booking_date,start_time,end_time),
                KEY idx_facility_booking_resident
                    (property_id,resident_id,booking_status),
                CONSTRAINT fk_booking_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_booking_facility
                    FOREIGN KEY (facility_id) REFERENCES cpms_facilities(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_facility_booking_updates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                booking_id BIGINT UNSIGNED NOT NULL,
                old_status VARCHAR(25) NULL,
                new_status VARCHAR(25) NOT NULL,
                message VARCHAR(500) NOT NULL,
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_booking_update (property_id,booking_id,created_at),
                CONSTRAINT fk_booking_update_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_booking_update_booking
                    FOREIGN KEY (booking_id) REFERENCES cpms_facility_bookings(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('facility.booking.approve','Approve facility booking',
                 'facilities','Approve or reject resident facility bookings'),
                ('facility.booking.cancel','Cancel facility booking',
                 'facilities','Cancel an approved or pending booking')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code IN
                ('facility.booking.approve','facility.booking.cancel')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.2',
                'Resident Facility Booking and Approval Foundation',
                'Property facilities, resident booking, slot conflict control and management approval.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.2'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('facility.booking.approve','facility.booking.cancel')",
            "DELETE FROM permissions WHERE permission_code IN
                ('facility.booking.approve','facility.booking.cancel')",
            "DROP TABLE IF EXISTS cpms_facility_booking_updates",
            "DROP TABLE IF EXISTS cpms_facility_bookings",
            "DROP TABLE IF EXISTS cpms_facilities",
        ];
    },
];
