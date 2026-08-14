<?php
declare(strict_types=1);

return [
    'key' => '20260810_0059_inspection_photo_limits',
    'name' => 'CPMS v3.2.6.4 configurable inspection photo limits',

    'up' => static function (mysqli $db): array {
        $propertyIdType = 'INT UNSIGNED';
        $stmt = $db->prepare(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = "cpms_properties"
               AND column_name = "id"
             LIMIT 1'
        );
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $liveType = strtolower(trim((string) ($row['COLUMN_TYPE'] ?? '')));
            if (preg_match(
                '/^(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?( unsigned)?$/',
                $liveType,
                $matches
            )) {
                $propertyIdType = strtoupper((string) $matches[1])
                    . (!empty($matches[2]) ? ' UNSIGNED' : '');
            }
        }

        return [
            "CREATE TABLE IF NOT EXISTS inspection_property_settings (
                property_id {$propertyIdType} NOT NULL,
                max_photos_per_inspection SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                updated_by_system_user_id INT UNSIGNED NULL,
                updated_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (property_id),
                CONSTRAINT fk_inspection_setting_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO inspection_property_settings
                (property_id, max_photos_per_inspection)
             SELECT id, 100
             FROM cpms_properties",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('inspection.settings.manage',
                 'Manage Inspection Settings', 'inspection',
                 'Manage property-specific inspection photo limits.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','compliance_manager')
               AND p.permission_code = 'inspection.settings.manage'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.6.4',
                 'Configurable Inspection Photo Limits',
                 'Property-specific 30-300 photo limit with default 100 and bulk upload compatibility.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_property_settings',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'inspection.settings.manage'",
            "DELETE FROM permissions
             WHERE permission_code = 'inspection.settings.manage'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.6.4'",
        ];
    },
];
