<?php
declare(strict_types=1);

return [
    'key' => '20260728_0016_non_compliance_action_links',
    'name' => 'CPMS v3.2.4 non-compliance auto corrective action',

    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS inspection_checklist_action_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                property_id INT UNSIGNED NOT NULL,
                inspection_id BIGINT UNSIGNED NOT NULL,
                assessment_id BIGINT UNSIGNED NOT NULL,
                assessment_item_id BIGINT UNSIGNED NOT NULL,
                corrective_action_id BIGINT UNSIGNED NOT NULL,
                created_by_user_id INT UNSIGNED NULL,
                created_by_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_checklist_failed_item_action
                    (property_id, assessment_item_id),
                KEY idx_checklist_action_inspection
                    (property_id, inspection_id),
                KEY idx_checklist_action_corrective
                    (property_id, corrective_action_id),
                CONSTRAINT fk_checklist_action_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_checklist_action_assessment
                    FOREIGN KEY (assessment_id)
                    REFERENCES inspection_checklist_assessments (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_checklist_action_item
                    FOREIGN KEY (assessment_item_id)
                    REFERENCES inspection_checklist_assessment_items (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_checklist_action_corrective
                    FOREIGN KEY (corrective_action_id)
                    REFERENCES inspection_corrective_actions (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('inspection.checklist.create_actions',
                 'Create Actions From Failed Checklist Items',
                 'inspection',
                 'Generate corrective actions from failed checklist items.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN ('system_owner','property_admin','manager')
               AND p.permission_code =
                   'inspection.checklist.create_actions'",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.4',
                 'Non-Compliance Auto Corrective Action',
                 'Converts failed checklist items into assigned corrective actions while preventing duplicates.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_checklist_action_links',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code =
                 'inspection.checklist.create_actions'",
            "DELETE FROM permissions
             WHERE permission_code =
                 'inspection.checklist.create_actions'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.4'",
        ];
    },
];
