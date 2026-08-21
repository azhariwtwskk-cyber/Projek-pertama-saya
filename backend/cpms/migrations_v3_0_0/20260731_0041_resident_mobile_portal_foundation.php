<?php
declare(strict_types=1);

return [
    'key' => '20260731_0041_resident_mobile_portal_foundation',
    'name' => 'CPMS v3.5.0 resident mobile portal foundation',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_resident_announcements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                message TEXT NOT NULL,
                publish_from DATETIME NOT NULL,
                publish_until DATETIME NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Published',
                created_by_system_user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_resident_announcement_property (property_id,status,publish_from),
                CONSTRAINT fk_resident_announcement_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_resident_service_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                resident_id INT NOT NULL,
                request_reference VARCHAR(40) NOT NULL,
                request_type VARCHAR(80) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                description TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'Submitted',
                priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_resident_request_reference (request_reference),
                KEY idx_resident_request_owner (property_id,resident_id,status),
                CONSTRAINT fk_resident_request_property
                    FOREIGN KEY (property_id) REFERENCES cpms_properties(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO roles
                (role_code,role_name,scope,is_system,status)
             VALUES ('resident','Resident','property',1,'active')",

            "INSERT IGNORE INTO permissions
                (permission_code,permission_name,module_name,description)
             VALUES
                ('resident.dashboard.view','Resident dashboard','resident','Access resident mobile dashboard'),
                ('resident.profile.view','Resident profile','resident','View own resident profile and unit'),
                ('resident.announcement.view','Resident announcements','resident','View published property announcements'),
                ('resident.request.create','Create service request','resident','Create a resident service request'),
                ('resident.request.view_own','View own requests','resident','View own resident service requests'),
                ('resident.announcement.manage','Manage resident announcements','resident','Publish property announcements'),
                ('resident.request.manage','Manage resident requests','resident','Manage property resident requests')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='resident'
               AND p.permission_code IN
                ('resident.dashboard.view','resident.profile.view',
                 'resident.announcement.view','resident.request.create',
                 'resident.request.view_own')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code LIKE 'resident.%'",

            "INSERT IGNORE INTO system_users
                (property_id,username,email,password_hash,full_name,phone,
                 status,must_change_password,source_table,source_id)
             SELECT r.property_id,r.resident_code,NULLIF(TRIM(r.email),''),
                    r.password_hash,r.full_name,r.phone,
                    CASE WHEN r.is_active=1 THEN 'active' ELSE 'inactive' END,
                    0,'cpms_residents',r.id
             FROM cpms_residents r
             WHERE r.resident_code IS NOT NULL
               AND TRIM(r.resident_code)<>''",

            "INSERT IGNORE INTO user_roles
                (system_user_id,role_id,property_id,status)
             SELECT u.id,ro.id,u.property_id,'active'
             FROM system_users u CROSS JOIN roles ro
             WHERE u.source_table='cpms_residents'
               AND ro.role_code='resident'
               AND u.property_id IS NOT NULL",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.5.0','Resident Mobile Portal Foundation',
                'Unified resident login, mobile dashboard, property announcements and own service requests.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.5.0'",
            "DELETE FROM user_roles WHERE system_user_id IN
             (SELECT id FROM system_users WHERE source_table='cpms_residents')",
            "DELETE FROM system_users WHERE source_table='cpms_residents'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code LIKE 'resident.%'",
            "DELETE FROM permissions WHERE permission_code LIKE 'resident.%'",
            "DROP TABLE IF EXISTS cpms_resident_service_requests",
            "DROP TABLE IF EXISTS cpms_resident_announcements",
        ];
    },
];
