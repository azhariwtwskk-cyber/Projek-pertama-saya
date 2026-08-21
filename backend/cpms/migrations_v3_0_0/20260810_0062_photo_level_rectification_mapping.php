<?php
declare(strict_types=1);

return [
    'key' => '20260810_0062_photo_level_rectification_mapping',
    'name' => 'CPMS v3.2.6.9 photo-level Before and After mapping',

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

        $exists = static function (
            mysqli $connection,
            string $kind,
            string $name
        ): bool {
            if ($kind === 'column') {
                $sql = 'SELECT COUNT(*) AS total
                        FROM information_schema.columns
                        WHERE table_schema = DATABASE()
                          AND table_name = "inspection_action_images"
                          AND column_name = ?';
            } elseif ($kind === 'index') {
                $sql = 'SELECT COUNT(*) AS total
                        FROM information_schema.statistics
                        WHERE table_schema = DATABASE()
                          AND table_name = "inspection_action_images"
                          AND index_name = ?';
            } else {
                $sql = 'SELECT COUNT(*) AS total
                        FROM information_schema.table_constraints
                        WHERE constraint_schema = DATABASE()
                          AND table_name = "inspection_action_images"
                          AND constraint_name = ?';
            }
            $stmt = $connection->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $sourceImageIdType = $columnType(
            $db,
            'inspection_images',
            'id',
            'BIGINT UNSIGNED'
        );
        $statements = [];

        if (!$exists($db, 'column', 'source_inspection_image_id')) {
            $statements[] =
                "ALTER TABLE inspection_action_images
                 ADD COLUMN source_inspection_image_id {$sourceImageIdType} NULL
                 AFTER image_phase";
        }
        if (!$exists($db, 'index', 'idx_action_image_source')) {
            $statements[] =
                'ALTER TABLE inspection_action_images
                 ADD KEY idx_action_image_source
                    (source_inspection_image_id, property_id)';
        }
        if (!$exists(
            $db,
            'constraint',
            'fk_action_image_source_inspection'
        )) {
            $statements[] =
                'ALTER TABLE inspection_action_images
                 ADD CONSTRAINT fk_action_image_source_inspection
                 FOREIGN KEY (source_inspection_image_id)
                 REFERENCES inspection_images (id)
                 ON UPDATE CASCADE ON DELETE SET NULL';
        }

        /*
         * Safe legacy backfill: an existing After image is mapped only when
         * its linked finding has exactly one original Inspector image.
         * Multi-photo findings remain unpaired for manual selection.
         */
        $statements[] =
            'UPDATE inspection_action_images ai
             INNER JOIN inspection_finding_action_links l
                ON l.action_id = ai.action_id
               AND l.inspection_id = ai.inspection_id
               AND l.property_id = ai.property_id
             INNER JOIN (
                SELECT finding_id, inspection_id, property_id,
                       MIN(inspection_image_id) AS source_image_id
                FROM inspection_finding_images
                GROUP BY finding_id, inspection_id, property_id
                HAVING COUNT(*) = 1
             ) single_source
                ON single_source.finding_id = l.finding_id
               AND single_source.inspection_id = l.inspection_id
               AND single_source.property_id = l.property_id
             SET ai.source_inspection_image_id = single_source.source_image_id
             WHERE ai.image_phase = "After"
               AND ai.source_inspection_image_id IS NULL';

        $statements[] =
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.6.9',
                 'Photo-level Before and After Mapping',
                 'Links every Staff After image to the exact Inspector source photo for verified side-by-side reporting.')";

        return $statements;
    },

    'down' => static function (mysqli $db): array {
        return [
            'ALTER TABLE inspection_action_images
             DROP FOREIGN KEY fk_action_image_source_inspection',
            'ALTER TABLE inspection_action_images
             DROP INDEX idx_action_image_source',
            'ALTER TABLE inspection_action_images
             DROP COLUMN source_inspection_image_id',
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.6.9'",
        ];
    },
];
