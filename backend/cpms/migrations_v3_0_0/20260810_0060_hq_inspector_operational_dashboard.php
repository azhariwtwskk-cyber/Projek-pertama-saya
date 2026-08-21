<?php
declare(strict_types=1);

return [
    'key' => '20260810_0060_hq_inspector_operational_dashboard',
    'name' => 'CPMS v3.2.6.5 HQ Inspector operational dashboard',

    'up' => static function (mysqli $db): array {
        $columnType = static function (
            mysqli $connection,
            string $table,
            string $column,
            string $fallback
        ): string {
            $statement = $connection->prepare(
                'SELECT COLUMN_TYPE
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND column_name = ?
                 LIMIT 1'
            );
            if (!$statement) {
                return $fallback;
            }

            $statement->bind_param('ss', $table, $column);
            $statement->execute();
            $row = $statement->get_result()->fetch_assoc();
            $statement->close();

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
        $actionIdType = $columnType(
            $db,
            'inspection_corrective_actions',
            'id',
            'BIGINT UNSIGNED'
        );
        $hqInspectorIdType = $columnType(
            $db,
            'hq_inspectors',
            'id',
            'INT UNSIGNED'
        );

        return [
            "CREATE TABLE IF NOT EXISTS inspection_hq_action_reviews (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                action_id {$actionIdType} NOT NULL,
                inspection_id {$inspectionIdType} NOT NULL,
                property_id {$propertyIdType} NOT NULL,
                hq_inspector_id {$hqInspectorIdType} NOT NULL,
                inspector_name VARCHAR(190) NOT NULL,
                decision VARCHAR(30) NOT NULL,
                remarks VARCHAR(2000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_hq_action_review_action
                    (action_id, property_id, created_at),
                KEY idx_hq_action_review_inspection
                    (inspection_id, property_id, created_at),
                CONSTRAINT fk_hq_action_review_action
                    FOREIGN KEY (action_id)
                    REFERENCES inspection_corrective_actions (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_hq_action_review_inspection
                    FOREIGN KEY (inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_hq_action_review_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_hq_action_review_inspector
                    FOREIGN KEY (hq_inspector_id)
                    REFERENCES hq_inspectors (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS inspection_reinspection_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                original_inspection_id {$inspectionIdType} NOT NULL,
                reinspection_id {$inspectionIdType} NOT NULL,
                property_id {$propertyIdType} NOT NULL,
                created_by_hq_inspector_id {$hqInspectorIdType} NOT NULL,
                created_by_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_reinspection_report
                    (property_id, reinspection_id),
                KEY idx_reinspection_original
                    (property_id, original_inspection_id, created_at),
                CONSTRAINT fk_reinspection_original
                    FOREIGN KEY (original_inspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_reinspection_new
                    FOREIGN KEY (reinspection_id)
                    REFERENCES inspection_reports (id)
                    ON UPDATE CASCADE
                    ON DELETE CASCADE,
                CONSTRAINT fk_reinspection_property
                    FOREIGN KEY (property_id)
                    REFERENCES cpms_properties (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT,
                CONSTRAINT fk_reinspection_inspector
                    FOREIGN KEY (created_by_hq_inspector_id)
                    REFERENCES hq_inspectors (id)
                    ON UPDATE CASCADE
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci",

            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.6.5',
                 'HQ Inspector Operational Dashboard',
                 'Operational KPIs, action queue, draft editing, HQ verification, reinspection and paginated inspection history.')",
        ];
    },

    'down' => static function (mysqli $db): array {
        return [
            'DROP TABLE IF EXISTS inspection_reinspection_links',
            'DROP TABLE IF EXISTS inspection_hq_action_reviews',
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.6.5'",
        ];
    },
];
