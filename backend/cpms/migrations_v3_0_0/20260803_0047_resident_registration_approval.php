<?php
declare(strict_types=1);

return [
    'key' => '20260803_0047_resident_registration_approval',
    'name' => 'CPMS v3.5.7 Resident Registration & Account Approval',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_resident_registrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                application_reference VARCHAR(40) NOT NULL,
                full_name VARCHAR(180) NOT NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(40) NOT NULL,
                block_name VARCHAR(50) NOT NULL,
                unit_no VARCHAR(50) NOT NULL,
                resident_type VARCHAR(30) NOT NULL DEFAULT 'OWNER',
                password_hash VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                review_notes VARCHAR(500) NULL,
                reviewed_by_system_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                approved_resident_id BIGINT UNSIGNED NULL,
                assigned_resident_code VARCHAR(50) NULL,
                source_ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_resident_registration_reference
                    (application_reference),
                KEY idx_resident_registration_queue
                    (property_id,status,submitted_at),
                KEY idx_resident_registration_phone
                    (property_id,phone),
                KEY idx_resident_registration_unit
                    (property_id,block_name,unit_no),
                KEY idx_resident_registration_reviewer
                    (reviewed_by_system_user_id),
                KEY idx_resident_registration_resident
                    (approved_resident_id),
                CONSTRAINT fk_resident_registration_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_resident_registration_reviewer
                    FOREIGN KEY (reviewed_by_system_user_id)
                    REFERENCES system_users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_resident_registration_resident
                    FOREIGN KEY (approved_resident_id)
                    REFERENCES cpms_residents(id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('resident.registration.manage',
                 'Manage resident registrations',
                 'resident',
                 'Review, approve or reject resident account applications')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id
             FROM roles r
             INNER JOIN permissions p
                ON p.permission_code='resident.registration.manage'
             WHERE r.role_code IN ('property_admin','manager')",

            "INSERT INTO system_settings (setting_key,setting_value)
             VALUES ('software_version','3.5.7')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES
                ('3.5.7',
                 'Resident Registration & Account Approval',
                 'Public resident applications, property-scoped approval and unified resident account creation.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE rp
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id=rp.permission_id
             WHERE p.permission_code='resident.registration.manage'",
            "DELETE FROM permissions
             WHERE permission_code='resident.registration.manage'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.5.7'",
            "UPDATE system_settings
             SET setting_value='3.5.6.2'
             WHERE setting_key='software_version'",
            'DROP TABLE IF EXISTS cpms_resident_registrations',
        ];
    },
];
