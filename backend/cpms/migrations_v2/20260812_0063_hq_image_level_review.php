<?php
declare(strict_types=1);

return [
    'key' => '20260812_0063_hq_image_level_review',
    'name' => 'CPMS v3.2.6.10 HQ image-level after review',

    'up' => static function (mysqli $db): array {
        $exists = static function (mysqli $connection, string $column): bool {
            $stmt = $connection->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = "inspection_action_images"
                   AND column_name = ?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $column);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $statements = [];
        if (!$exists($db, 'hq_review_status')) {
            $statements[] =
                "ALTER TABLE inspection_action_images
                 ADD COLUMN hq_review_status VARCHAR(30)
                    NOT NULL DEFAULT 'Pending'
                 AFTER source_inspection_image_id";
        }
        if (!$exists($db, 'hq_review_remarks')) {
            $statements[] =
                'ALTER TABLE inspection_action_images
                 ADD COLUMN hq_review_remarks VARCHAR(2000) NULL
                 AFTER hq_review_status';
        }
        if (!$exists($db, 'hq_reviewed_by_id')) {
            $statements[] =
                'ALTER TABLE inspection_action_images
                 ADD COLUMN hq_reviewed_by_id INT UNSIGNED NULL
                 AFTER hq_review_remarks';
        }
        if (!$exists($db, 'hq_reviewed_by_name')) {
            $statements[] =
                'ALTER TABLE inspection_action_images
                 ADD COLUMN hq_reviewed_by_name VARCHAR(190) NULL
                 AFTER hq_reviewed_by_id';
        }
        if (!$exists($db, 'hq_reviewed_at')) {
            $statements[] =
                'ALTER TABLE inspection_action_images
                 ADD COLUMN hq_reviewed_at DATETIME NULL
                 AFTER hq_reviewed_by_name';
        }

        $statements[] =
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('3.2.6.10',
                 'HQ Image-Level Review',
                 'Allows HQ Inspector to accept or reject each After image individually and keep rejected/missing evidence visible for follow-up inspections.')";

        return $statements;
    },

    'down' => static function (mysqli $db): array {
        return [
            'ALTER TABLE inspection_action_images DROP COLUMN hq_reviewed_at',
            'ALTER TABLE inspection_action_images DROP COLUMN hq_reviewed_by_name',
            'ALTER TABLE inspection_action_images DROP COLUMN hq_reviewed_by_id',
            'ALTER TABLE inspection_action_images DROP COLUMN hq_review_remarks',
            'ALTER TABLE inspection_action_images DROP COLUMN hq_review_status',
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no = '3.2.6.10'",
        ];
    },
];
