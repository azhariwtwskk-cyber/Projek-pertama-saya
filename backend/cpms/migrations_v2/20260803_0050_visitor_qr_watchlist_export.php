<?php
declare(strict_types=1);

return [
    'key' => '20260803_0050_visitor_qr_watchlist_export',
    'name' => 'CPMS v3.6.0 visitor QR, watchlist and daily export',
    'up' => static function (mysqli $db): array {
        return [
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
                ('visitor.watchlist.view','View visitor watchlist','visitor',
                 'View property-scoped visitor watchlist alerts'),
                ('visitor.watchlist.manage','Manage visitor watchlist','visitor',
                 'Add, deactivate and reactivate visitor watchlist entries'),
                ('visitor.export','Export visitor register','visitor',
                 'Export property-scoped daily visitor registers')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND r.status='active' AND p.status='active'
               AND p.permission_code IN
                   ('visitor.watchlist.view','visitor.watchlist.manage',
                    'visitor.export')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='clerk' AND r.status='active'
               AND p.status='active' AND p.permission_code IN
                   ('visitor.watchlist.view','visitor.export')",

            "INSERT IGNORE INTO role_permissions (role_id,permission_id)
             SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
             WHERE r.role_code='security' AND r.status='active'
               AND p.status='active'
               AND p.permission_code='visitor.watchlist.view'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no,release_name,notes)
             VALUES ('3.6.0','Visitor QR, Watchlist and Daily Export',
                'Local QR passes, security QR lookup, property watchlist controls, denied-entry workflow and safe daily CSV export.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no='3.6.0'",
            "DELETE rp FROM role_permissions rp JOIN permissions p
             ON p.id=rp.permission_id WHERE p.permission_code IN
                ('visitor.watchlist.view','visitor.watchlist.manage',
                 'visitor.export')",
            "DELETE FROM permissions WHERE permission_code IN
                ('visitor.watchlist.view','visitor.watchlist.manage',
                 'visitor.export')",
            "DROP TABLE IF EXISTS cpms_visitor_watchlist",
        ];
    },
];
