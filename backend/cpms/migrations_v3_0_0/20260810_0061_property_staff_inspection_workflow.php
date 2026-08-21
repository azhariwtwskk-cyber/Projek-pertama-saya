<?php
declare(strict_types=1);

return [
    'key' => '20260810_0061_property_staff_inspection_workflow',
    'name' => 'CPMS v3.2.6.6 Property and Staff inspection workflow',

    'up' => static function (mysqli $db): array {
        $columnType = static function (
            mysqli $connection,
            string $table,
            string $column,
            string $fallback
        ): string {
            $stmt = $connection->prepare(
                'SELECT COLUMN_TYPE
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ? AND column_name = ?
                 LIMIT 1'
            );
            if (!$stmt) {
                return $fallback;
            }
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $liveType = strtolower(trim((string) ($row['COLUMN_TYPE'] ?? '')));
            if (!preg_match(
                '/^(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?( unsigned)?$/',
                $liveType,
                $matches
            )) {
                return $fallback;
            }
            return strtoupper((string) $matches[1])
                . (!empty($matches[2]) ? ' UNSIGNED' : '');
        };

        $propertyIdType = $columnType(
            $db,
            'cpms_properties',
            'id',
            'INT UNSIGNED'
        );
        $inspectionIdType = $columnType(
            $db,
            'inspection_reports',
            'id',
            'INT UNSIGNED'
        );
        $findingIdType = $columnType(
            $db,
            'inspection_findings',
            'id',
            'BIGINT UNSIGNED'
        );
        $actionIdType = $columnType(
            $db,
            'inspection_corrective_actions',
            'id',
            'BIGINT UNSIGNED'
        );
        $systemUserIdType = $columnType(
            $db,
            'system_users',
            'id',
            'INT UNSIGNED'
        );

        return [
            "CREATE TABLE IF NOT EXISTS inspection_finding_action_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                finding_id {$findingIdType} NOT NULL,
                action_id {$actionIdType} NOT NULL,
                inspection_id {$inspectionIdType} NOT NULL,
                property_id {$propertyIdType} NOT NULL,
                assigned_by_system_user_id {$systemUserIdType} NULL,
                assigned_by_name VARCHAR(190) NULL,
                supervisor_status VARCHAR(30) NOT NULL DEFAULT 'Not Submitted',
                supervisor_reviewed_by_user_id {$systemUserIdType} NULL,
                supervisor_reviewed_by_name VARCHAR(190) NULL,
                supervisor_remarks VARCHAR(2000) NULL,
                supervisor_reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_finding_action_finding (finding_id),
                UNIQUE KEY uq_finding_action_action (action_id),
                KEY idx_finding_action_inspection
                    (inspection_id, property_id),
                KEY idx_finding_action_supervisor
                    (property_id, supervisor_status, updated_at),
                CONSTRAINT fk_finding_action_finding
                    FOREIGN KEY (finding_id)
                    REFERENCES inspection_findings (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_finding_action_action
                    FOREIGN KEY (action_id)
                    REFERENCES inspection_corrective_actions (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_finding_action_inspection
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_finding_action_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_finding_action_assigner
                    FOREIGN KEY (assigned_by_system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_finding_action_supervisor_user
                    FOREIGN KEY (supervisor_reviewed_by_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_action_progress_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                action_id {$actionIdType} NOT NULL,
                inspection_id {$inspectionIdType} NOT NULL,
                property_id {$propertyIdType} NOT NULL,
                actor_system_user_id {$systemUserIdType} NULL,
                actor_name VARCHAR(190) NOT NULL,
                actor_role VARCHAR(50) NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                old_status VARCHAR(40) NULL,
                new_status VARCHAR(40) NULL,
                notes VARCHAR(2000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_action_progress_action
                    (action_id, property_id, created_at),
                KEY idx_action_progress_inspection
                    (inspection_id, property_id, created_at),
                CONSTRAINT fk_action_progress_action
                    FOREIGN KEY (action_id)
                    REFERENCES inspection_corrective_actions (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_action_progress_inspection
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_action_progress_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_action_progress_actor
                    FOREIGN KEY (actor_system_user_id)
                    REFERENCES system_users (id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO permissions
                (permission_code, permission_name, module_name, description)
             VALUES
                ('inspection.hq_report.manage',
                 'Manage HQ inspection reports', 'inspection',
                 'Receive HQ reports, assign findings and review staff rectification.')",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code IN
                ('system_owner','property_admin','manager','supervisor')
               AND p.permission_code = 'inspection.hq_report.manage'",

            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
             FROM roles r CROSS JOIN permissions p
             WHERE r.role_code = 'supervisor'
               AND p.permission_code IN
                ('dashboard.view','inspection.view','inspection.action.manage',
                 'inspection.action.verify','notifications.view',
                 'notifications.manage')",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.6.6',
                 'Property and Staff Inspection Workflow',
                 'Finding-level assignment, staff evidence workspace, supervisor review and end-to-end progress history.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_action_progress_log',
            'DROP TABLE IF EXISTS inspection_finding_action_links',
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_code = 'inspection.hq_report.manage'",
            "DELETE FROM permissions
             WHERE permission_code = 'inspection.hq_report.manage'",
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.6.6'",
        ];
    },
];
