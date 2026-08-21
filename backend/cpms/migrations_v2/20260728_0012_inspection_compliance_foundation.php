<?php
declare(strict_types=1);

return [
    'key' => '20260728_0012_inspection_compliance_foundation',
    'name' => 'CPMS v3.2.0 inspection and compliance foundation',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS inspection_reports (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                inspection_no VARCHAR(80) NOT NULL,
                inspection_date DATE NOT NULL,
                inspection_type VARCHAR(120) NOT NULL DEFAULT 'General Inspection',
                category VARCHAR(120) NOT NULL DEFAULT 'General',
                location VARCHAR(190) NOT NULL,
                priority VARCHAR(30) NOT NULL DEFAULT 'Medium',
                risk_level VARCHAR(30) NOT NULL DEFAULT 'Medium',
                status VARCHAR(40) NOT NULL DEFAULT 'Draft',
                description TEXT NULL,
                finding TEXT NULL,
                recommendation TEXT NULL,
                due_date DATE NULL,
                corrective_action_required TINYINT(1) NOT NULL DEFAULT 0,
                reported_by_id INT UNSIGNED NULL,
                reported_by_name VARCHAR(190) NULL,
                reviewed_by_id INT UNSIGNED NULL,
                reviewed_by_name VARCHAR(190) NULL,
                reviewed_at DATETIME NULL,
                verified_by_id INT UNSIGNED NULL,
                verified_by_name VARCHAR(190) NULL,
                verified_at DATETIME NULL,
                closed_at DATETIME NULL,
                work_order_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_inspection_property_no
                    (property_id, inspection_no),
                KEY idx_inspection_property_status
                    (property_id, status),
                KEY idx_inspection_property_date
                    (property_id, inspection_date),
                KEY idx_inspection_due
                    (property_id, due_date, status),
                KEY idx_inspection_work_order (work_order_id),
                CONSTRAINT fk_inspection_report_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                image_type VARCHAR(40) NOT NULL DEFAULT 'Finding',
                image_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NULL,
                mime_type VARCHAR(100) NULL,
                file_size INT UNSIGNED NULL,
                caption VARCHAR(500) NULL,
                uploaded_by_id INT UNSIGNED NULL,
                uploaded_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_inspection_image_report
                    (inspection_id, property_id),
                KEY idx_inspection_image_property (property_id),
                CONSTRAINT fk_inspection_image_report
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_inspection_image_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_checklist_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                item_no INT UNSIGNED NOT NULL DEFAULT 1,
                item_name VARCHAR(255) NOT NULL,
                result VARCHAR(40) NOT NULL DEFAULT 'Pending',
                remarks VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_inspection_checklist
                    (inspection_id, property_id),
                CONSTRAINT fk_inspection_checklist_report
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_inspection_checklist_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_status_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                old_status VARCHAR(40) NULL,
                new_status VARCHAR(40) NOT NULL,
                remarks VARCHAR(2000) NULL,
                changed_by_id INT UNSIGNED NULL,
                changed_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_inspection_history
                    (inspection_id, property_id, created_at),
                CONSTRAINT fk_inspection_history_report
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_inspection_history_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS compliance_schedules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                category VARCHAR(120) NOT NULL DEFAULT 'General',
                location VARCHAR(190) NULL,
                frequency_type VARCHAR(40) NOT NULL DEFAULT 'yearly',
                next_due_date DATE NOT NULL,
                responsible_person VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_by_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_compliance_property_due
                    (property_id, status, next_due_date),
                CONSTRAINT fk_compliance_schedule_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS compliance_completion_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                schedule_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                completed_date DATE NOT NULL,
                previous_due_date DATE NOT NULL,
                next_due_date DATE NULL,
                result VARCHAR(40) NOT NULL DEFAULT 'Compliant',
                remarks TEXT NULL,
                inspection_id INT UNSIGNED NULL,
                completed_by_id INT UNSIGNED NULL,
                completed_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_compliance_history_schedule
                    (schedule_id, property_id),
                KEY idx_compliance_history_inspection (inspection_id),
                CONSTRAINT fk_compliance_history_schedule
                    FOREIGN KEY (schedule_id)
                    REFERENCES compliance_schedules (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_compliance_history_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_corrective_actions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id BIGINT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                action_no VARCHAR(80) NOT NULL,
                title VARCHAR(190) NOT NULL,
                description TEXT NOT NULL,
                assigned_type VARCHAR(40) NOT NULL DEFAULT 'staff',
                assigned_system_user_id INT UNSIGNED NULL,
                assigned_legacy_id INT UNSIGNED NULL,
                assigned_name VARCHAR(190) NULL,
                priority VARCHAR(30) NOT NULL DEFAULT 'Medium',
                due_date DATE NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'Open',
                rectification_notes TEXT NULL,
                rectified_by_user_id INT UNSIGNED NULL,
                rectified_by_name VARCHAR(190) NULL,
                rectified_at DATETIME NULL,
                verified_by_user_id INT UNSIGNED NULL,
                verified_by_name VARCHAR(190) NULL,
                verified_at DATETIME NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_corrective_action_no
                    (property_id, action_no),
                KEY idx_corrective_inspection
                    (inspection_id, property_id),
                KEY idx_corrective_assignee
                    (assigned_system_user_id, status),
                KEY idx_corrective_due
                    (property_id, due_date, status),
                CONSTRAINT fk_corrective_action_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_corrective_action_assignee
                    FOREIGN KEY (assigned_system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL,
                CONSTRAINT fk_corrective_action_rectified_by
                    FOREIGN KEY (rectified_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL,
                CONSTRAINT fk_corrective_action_verified_by
                    FOREIGN KEY (verified_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL,
                CONSTRAINT fk_corrective_action_created_by
                    FOREIGN KEY (created_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_action_images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                action_id BIGINT UNSIGNED NOT NULL,
                inspection_id BIGINT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                image_phase VARCHAR(40) NOT NULL DEFAULT 'Evidence',
                image_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NULL,
                mime_type VARCHAR(100) NULL,
                file_size INT UNSIGNED NULL,
                caption VARCHAR(500) NULL,
                uploaded_by_user_id INT UNSIGNED NULL,
                uploaded_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_action_image_action
                    (action_id, property_id),
                KEY idx_action_image_inspection
                    (inspection_id, property_id),
                CONSTRAINT fk_action_image_action
                    FOREIGN KEY (action_id)
                    REFERENCES inspection_corrective_actions (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_action_image_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_action_image_uploader
                    FOREIGN KEY (uploaded_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('inspection.action.manage',
                 'Manage corrective actions',
                 'inspection',
                 'Create and assign corrective actions'),
                ('inspection.action.rectify',
                 'Rectify corrective actions',
                 'inspection',
                 'Update assigned corrective actions and evidence'),
                ('inspection.action.verify',
                 'Verify corrective actions',
                 'inspection',
                 'Verify and close corrective actions'),
                ('compliance.view',
                 'View compliance',
                 'compliance',
                 'View compliance schedules and history'),
                ('compliance.create',
                 'Create compliance',
                 'compliance',
                 'Create compliance schedules'),
                ('compliance.update',
                 'Update compliance',
                 'compliance',
                 'Complete and update compliance schedules'),
                ('compliance.approve',
                 'Approve compliance',
                 'compliance',
                 'Approve compliance completion records')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_code = 'system_owner'
               AND p.permission_code IN (
                   'inspection.action.manage',
                   'inspection.action.rectify',
                   'inspection.action.verify',
                   'compliance.view',
                   'compliance.create',
                   'compliance.update',
                   'compliance.approve'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_code = 'property_admin'
               AND p.permission_code IN (
                   'inspection.action.manage',
                   'inspection.action.rectify',
                   'inspection.action.verify',
                   'compliance.view',
                   'compliance.create',
                   'compliance.update',
                   'compliance.approve'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_code = 'manager'
               AND p.permission_code IN (
                   'inspection.action.manage',
                   'inspection.action.rectify',
                   'inspection.action.verify',
                   'compliance.view',
                   'compliance.create',
                   'compliance.update',
                   'compliance.approve'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_code IN ('staff', 'contractor')
               AND p.permission_code IN (
                   'inspection.action.rectify',
                   'compliance.view'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r
             CROSS JOIN permissions p
             WHERE r.role_code = 'clerk'
               AND p.permission_code = 'compliance.view'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.0', 'Inspection & Compliance Foundation',
                 'Property-scoped inspections, compliance schedules, corrective actions, evidence and approval permissions.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_action_images',
            'DROP TABLE IF EXISTS inspection_corrective_actions',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN (
                 'inspection.action.manage',
                 'inspection.action.rectify',
                 'inspection.action.verify',
                 'compliance.view',
                 'compliance.create',
                 'compliance.update',
                 'compliance.approve'
             )",
            "DELETE FROM permissions
             WHERE permission_code IN (
                 'inspection.action.manage',
                 'inspection.action.rectify',
                 'inspection.action.verify',
                 'compliance.view',
                 'compliance.create',
                 'compliance.update',
                 'compliance.approve'
             )",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.0'",
        ];
    },
];
