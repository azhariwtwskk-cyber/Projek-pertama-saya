<?php
declare(strict_types=1);

return [
    'key' => '20260728_0023_pm_budget_control',
    'name' => 'CPMS v3.3.5 maintenance budget control',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_pm_budgets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                budget_year SMALLINT UNSIGNED NOT NULL,
                budget_month TINYINT UNSIGNED NOT NULL,
                budget_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                warning_percent DECIMAL(5,2) NOT NULL DEFAULT 80,
                notes VARCHAR(1000) NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                updated_by_user_id INT UNSIGNED NULL,
                updated_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pm_budget_period
                    (property_id, budget_year, budget_month),
                KEY idx_pm_budget_year
                    (property_id, budget_year),
                CONSTRAINT fk_pm_budget_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name,
                 description)
             VALUES
                ('maintenance.budget',
                 'Manage Maintenance Budget',
                 'maintenance',
                 'Manage property maintenance budgets and cost variance controls.')",

            "INSERT IGNORE INTO role_permissions
                (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN (
                    'system_owner', 'property_admin', 'manager'
                  )
               AND p.permission_code = 'maintenance.budget'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.5',
                 'Preventive Maintenance Budget & Cost Variance Control',
                 'Property-scoped monthly budget, warning threshold, planned cost, actual cost and variance controls.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS cpms_pm_budgets',

            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'maintenance.budget'",

            "DELETE FROM permissions
             WHERE permission_code = 'maintenance.budget'",

            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.5'",
        ];
    },
];
