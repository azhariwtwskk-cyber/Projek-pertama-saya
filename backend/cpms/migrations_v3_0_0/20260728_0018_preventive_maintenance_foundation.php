<?php
declare(strict_types=1);

return [
    'key' => '20260728_0018_preventive_maintenance_foundation',
    'name' => 'CPMS v3.3.0 preventive maintenance foundation',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_pm_schedules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                asset_id BIGINT UNSIGNED NULL,
                asset_name VARCHAR(190) NOT NULL,
                schedule_name VARCHAR(190) NOT NULL,
                maintenance_type VARCHAR(80) NOT NULL DEFAULT 'Preventive',
                frequency_unit VARCHAR(20) NOT NULL DEFAULT 'monthly',
                frequency_interval INT UNSIGNED NOT NULL DEFAULT 1,
                next_due_date DATE NOT NULL,
                last_completed_date DATE NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
                assigned_system_user_id INT UNSIGNED NULL,
                assigned_name VARCHAR(190) NULL,
                vendor_name VARCHAR(190) NULL,
                estimated_cost DECIMAL(12,2) NULL,
                instructions TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_user_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pm_schedule_due
                    (property_id, status, next_due_date),
                KEY idx_pm_schedule_assignee
                    (property_id, assigned_system_user_id, status),
                KEY idx_pm_schedule_asset
                    (property_id, asset_id),
                CONSTRAINT fk_pm_schedule_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_pm_schedule_user
                    FOREIGN KEY (assigned_system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_pm_work_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                schedule_id BIGINT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                completed_date DATE NOT NULL,
                result VARCHAR(30) NOT NULL DEFAULT 'Completed',
                work_notes TEXT NOT NULL,
                meter_reading VARCHAR(100) NULL,
                actual_cost DECIMAL(12,2) NULL,
                downtime_minutes INT UNSIGNED NULL,
                next_due_date DATE NOT NULL,
                performed_by_user_id INT UNSIGNED NULL,
                performed_by_name VARCHAR(190) NULL,
                verified_by_user_id INT UNSIGNED NULL,
                verified_by_name VARCHAR(190) NULL,
                verified_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pm_work_schedule
                    (schedule_id, completed_date),
                KEY idx_pm_work_property
                    (property_id, completed_date),
                CONSTRAINT fk_pm_work_schedule
                    FOREIGN KEY (schedule_id)
                    REFERENCES cpms_pm_schedules (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_pm_work_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cpms_pm_evidence (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_log_id BIGINT UNSIGNED NOT NULL,
                schedule_id BIGINT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                image_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NULL,
                mime_type VARCHAR(100) NULL,
                file_size INT UNSIGNED NULL,
                caption VARCHAR(500) NULL,
                uploaded_by_user_id INT UNSIGNED NULL,
                uploaded_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pm_evidence_log
                    (work_log_id, schedule_id),
                CONSTRAINT fk_pm_evidence_log
                    FOREIGN KEY (work_log_id)
                    REFERENCES cpms_pm_work_logs (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_pm_evidence_schedule
                    FOREIGN KEY (schedule_id)
                    REFERENCES cpms_pm_schedules (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_pm_evidence_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('maintenance.view',
                 'View Preventive Maintenance', 'maintenance',
                 'View property maintenance schedules and service history.'),
                ('maintenance.manage',
                 'Manage Preventive Maintenance', 'maintenance',
                 'Create and update maintenance schedules.'),
                ('maintenance.complete',
                 'Complete Preventive Maintenance', 'maintenance',
                 'Record completed maintenance and upload evidence.'),
                ('maintenance.verify',
                 'Verify Preventive Maintenance', 'maintenance',
                 'Verify completed preventive maintenance work.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code IN (
                   'maintenance.view','maintenance.manage',
                   'maintenance.complete','maintenance.verify'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('clerk','staff','contractor')
               AND p.permission_code IN (
                   'maintenance.view','maintenance.complete'
               )",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.3.0',
                 'Preventive Maintenance Foundation',
                 'Property-scoped recurring schedules, due monitoring, completion logs, cost, downtime and evidence.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS cpms_pm_evidence',
            'DROP TABLE IF EXISTS cpms_pm_work_logs',
            'DROP TABLE IF EXISTS cpms_pm_schedules',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN (
                 'maintenance.view','maintenance.manage',
                 'maintenance.complete','maintenance.verify'
             )",
            "DELETE FROM permissions
             WHERE permission_code IN (
                 'maintenance.view','maintenance.manage',
                 'maintenance.complete','maintenance.verify'
             )",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.3.0'",
        ];
    },
];
