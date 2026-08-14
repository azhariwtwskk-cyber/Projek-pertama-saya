<?php
declare(strict_types=1);

return [
    'key' => '20260728_0015_inspection_checklist_scoring',
    'name' => 'CPMS v3.2.3 inspection checklist and scoring engine',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS inspection_checklist_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                template_name VARCHAR(190) NOT NULL,
                category VARCHAR(120) NOT NULL DEFAULT 'General',
                description TEXT NULL,
                passing_score DECIMAL(5,2) NOT NULL DEFAULT 80.00,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_user_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_checklist_template_name
                    (property_id, template_name),
                KEY idx_checklist_template_property
                    (property_id, status, category),
                CONSTRAINT fk_checklist_template_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_checklist_template_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                template_id INT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                item_order INT UNSIGNED NOT NULL DEFAULT 1,
                item_name VARCHAR(255) NOT NULL,
                guidance VARCHAR(1000) NULL,
                weight DECIMAL(7,2) NOT NULL DEFAULT 1.00,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_checklist_template_item
                    (template_id, item_order),
                CONSTRAINT fk_checklist_item_template
                    FOREIGN KEY (template_id)
                    REFERENCES inspection_checklist_templates (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_checklist_item_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_checklist_assessments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id BIGINT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                template_id INT UNSIGNED NOT NULL,
                template_name VARCHAR(190) NOT NULL,
                passing_score DECIMAL(5,2) NOT NULL DEFAULT 80.00,
                earned_score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                maximum_score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                score_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                overall_result VARCHAR(30) NOT NULL DEFAULT 'Pending',
                assessed_by_user_id INT UNSIGNED NULL,
                assessed_by_name VARCHAR(190) NULL,
                assessed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_checklist_assessment
                    (property_id, inspection_id),
                KEY idx_checklist_assessment_result
                    (property_id, overall_result, score_percent),
                CONSTRAINT fk_checklist_assessment_template
                    FOREIGN KEY (template_id)
                    REFERENCES inspection_checklist_templates (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_checklist_assessment_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_checklist_assessment_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                assessment_id BIGINT UNSIGNED NOT NULL,
                property_id INT UNSIGNED NOT NULL,
                template_item_id BIGINT UNSIGNED NOT NULL,
                item_order INT UNSIGNED NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                weight DECIMAL(7,2) NOT NULL DEFAULT 1.00,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                result VARCHAR(20) NOT NULL DEFAULT 'Pending',
                remarks VARCHAR(1000) NULL,
                score_awarded DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_checklist_assessment_item
                    (assessment_id, template_item_id),
                KEY idx_checklist_assessment_items
                    (assessment_id, item_order),
                CONSTRAINT fk_assessment_item_assessment
                    FOREIGN KEY (assessment_id)
                    REFERENCES inspection_checklist_assessments (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_assessment_item_property
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
                ('inspection.checklist.view',
                 'View Inspection Checklists', 'inspection',
                 'View checklist templates and inspection scores.'),
                ('inspection.checklist.manage',
                 'Manage Inspection Checklist Templates', 'inspection',
                 'Create and manage property checklist templates.'),
                ('inspection.checklist.assess',
                 'Assess Inspection Checklist', 'inspection',
                 'Apply and score a checklist for an inspection.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code IN (
                   'inspection.checklist.view',
                   'inspection.checklist.manage',
                   'inspection.checklist.assess'
               )",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('clerk','staff')
               AND p.permission_code = 'inspection.checklist.view'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.3',
                 'Inspection Checklist Template & Scoring Engine',
                 'Reusable property checklist templates, weighted scoring and automatic compliance result.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_checklist_assessment_items',
            'DROP TABLE IF EXISTS inspection_checklist_assessments',
            'DROP TABLE IF EXISTS inspection_checklist_template_items',
            'DROP TABLE IF EXISTS inspection_checklist_templates',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code IN (
                 'inspection.checklist.view',
                 'inspection.checklist.manage',
                 'inspection.checklist.assess'
             )",
            "DELETE FROM permissions
             WHERE permission_code IN (
                 'inspection.checklist.view',
                 'inspection.checklist.manage',
                 'inspection.checklist.assess'
             )",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.3'",
        ];
    },
];
