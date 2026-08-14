<?php
declare(strict_types=1);

return [
    'key' => '20260731_0042_resident_request_workflow_integration',
    'name' => 'CPMS v3.5.1 resident complaint and work order integration',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_resident_request_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                service_request_id BIGINT UNSIGNED NOT NULL,
                complaint_reference VARCHAR(100) NULL,
                work_order_id INT UNSIGNED NULL,
                converted_by_system_user_id INT UNSIGNED NULL,
                converted_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_resident_request_link (service_request_id),
                KEY idx_resident_link_property (property_id),
                KEY idx_resident_link_complaint (complaint_reference),
                KEY idx_resident_link_work_order (work_order_id),
                CONSTRAINT fk_resident_link_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_resident_request_updates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                service_request_id BIGINT UNSIGNED NOT NULL,
                update_type VARCHAR(30) NOT NULL DEFAULT 'Status',
                old_status VARCHAR(30) NULL,
                new_status VARCHAR(30) NULL,
                message VARCHAR(500) NOT NULL,
                visible_to_resident TINYINT(1) NOT NULL DEFAULT 1,
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_resident_update_request
                    (property_id,service_request_id,visible_to_resident,created_at),
                CONSTRAINT fk_resident_update_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('resident.request.convert_complaint',
                 'Convert resident request to complaint','resident',
                 'Convert a resident service request into a complaint'),
                ('resident.request.convert_work_order',
                 'Convert resident request to work order','resident',
                 'Create a work order from a linked resident complaint'),
                ('resident.request.update_status',
                 'Update resident request status','resident',
                 'Update status and resident-visible workflow timeline')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code IN
                ('resident.request.convert_complaint',
                 'resident.request.convert_work_order',
                 'resident.request.update_status')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.1',
                'Resident Complaint and Service Request Integration',
                'Convert resident requests into complaints and work orders with resident-visible workflow updates.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.1'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('resident.request.convert_complaint',
                 'resident.request.convert_work_order',
                 'resident.request.update_status')",
            "DELETE FROM permissions WHERE permission_code IN
                ('resident.request.convert_complaint',
                 'resident.request.convert_work_order',
                 'resident.request.update_status')",
            "DROP TABLE IF EXISTS cpms_resident_request_updates",
            "DROP TABLE IF EXISTS cpms_resident_request_links",
        ];
    },
];
