<?php
declare(strict_types=1);

return [
    'key' => '20260727_0001_engine_baseline',
    'name' => 'Migration engine baseline',
    'up' => static function (mysqli $db): array {
        return [
            "CREATE TABLE IF NOT EXISTS cpms_v2_schema_versions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                version_no VARCHAR(50) NOT NULL,
                release_name VARCHAR(190) NOT NULL,
                installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_v2_schema_version (version_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "INSERT IGNORE INTO cpms_v2_schema_versions
                (version_no, release_name, notes)
             VALUES
                ('2.0.5', 'Database Migration Engine',
                 'Migration registry, schema snapshots and rollback foundation.')",
        ];
    },
    'down' => static function (mysqli $db): array {
        return [
            "DELETE FROM cpms_v2_schema_versions WHERE version_no = '2.0.5'",
        ];
    },
];
