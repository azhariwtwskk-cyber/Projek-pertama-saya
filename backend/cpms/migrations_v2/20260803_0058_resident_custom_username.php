<?php
declare(strict_types=1);

return [
    'key' => '20260803_0058_resident_custom_username',
    'name' => 'CPMS v3.6.0.9 Resident Custom Username',
    'up' => static function (mysqli $db): array {
        $tableExists = static function (
            mysqli $connection,
            string $tableName
        ): bool {
            $stmt = $connection->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $tableName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $columnExists = static function (
            mysqli $connection,
            string $tableName,
            string $columnName
        ): bool {
            $stmt = $connection->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name=? AND column_name=?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $tableName, $columnName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $indexExists = static function (
            mysqli $connection,
            string $tableName,
            string $indexName
        ): bool {
            $stmt = $connection->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.statistics
                 WHERE table_schema=DATABASE()
                   AND table_name=? AND index_name=?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $tableName, $indexName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        if (!$tableExists($db, 'cpms_resident_registrations')) {
            throw new RuntimeException(
                'Jalankan migration Resident Registration v3.5.7 terlebih dahulu.'
            );
        }

        $sql = [];

        if (!$columnExists(
            $db,
            'cpms_resident_registrations',
            'requested_username'
        )) {
            $sql[] = "ALTER TABLE cpms_resident_registrations
                ADD COLUMN requested_username VARCHAR(100) NULL
                AFTER application_reference";
        }

        if (!$columnExists(
            $db,
            'cpms_resident_registrations',
            'assigned_username'
        )) {
            $sql[] = "ALTER TABLE cpms_resident_registrations
                ADD COLUMN assigned_username VARCHAR(100) NULL
                AFTER assigned_resident_code";
        }

        if (!$indexExists(
            $db,
            'cpms_resident_registrations',
            'idx_resident_registration_username'
        )) {
            $sql[] = "ALTER TABLE cpms_resident_registrations
                ADD KEY idx_resident_registration_username
                    (requested_username,status)";
        }

        $sql[] = "UPDATE cpms_resident_registrations a
            INNER JOIN system_users u
                ON u.source_table='cpms_residents'
               AND u.source_id=a.approved_resident_id
            SET a.requested_username=COALESCE(
                    NULLIF(a.requested_username,''),u.username
                ),
                a.assigned_username=COALESCE(
                    NULLIF(a.assigned_username,''),u.username
                )
            WHERE a.status='Approved'
              AND a.approved_resident_id IS NOT NULL";

        $sql[] = "INSERT INTO system_settings (setting_key,setting_value)
            VALUES ('software_version','3.6.0.9')
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)";

        $sql[] = "INSERT IGNORE INTO cpms_v2_schema_versions
            (version_no,release_name,notes)
            VALUES (
                '3.6.0.9','Resident Custom Username',
                'Residents choose a globally unique login username while resident_code remains an internal property reference.'
            )";

        return $sql;
    },
    'down' => static function (mysqli $db): array {
        $columnExists = static function (
            mysqli $connection,
            string $tableName,
            string $columnName
        ): bool {
            $stmt = $connection->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name=? AND column_name=?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $tableName, $columnName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $indexExists = static function (
            mysqli $connection,
            string $tableName,
            string $indexName
        ): bool {
            $stmt = $connection->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.statistics
                 WHERE table_schema=DATABASE()
                   AND table_name=? AND index_name=?'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ss', $tableName, $indexName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $sql = [
            "DELETE FROM cpms_v2_schema_versions
             WHERE version_no='3.6.0.9'",
            "UPDATE system_settings SET setting_value='3.6.0.8'
             WHERE setting_key='software_version'",
        ];

        if ($indexExists(
            $db,
            'cpms_resident_registrations',
            'idx_resident_registration_username'
        )) {
            $sql[] = "ALTER TABLE cpms_resident_registrations
                DROP INDEX idx_resident_registration_username";
        }

        if ($columnExists(
            $db,
            'cpms_resident_registrations',
            'assigned_username'
        )) {
            $sql[] = "ALTER TABLE cpms_resident_registrations
                DROP COLUMN assigned_username";
        }

        if ($columnExists(
            $db,
            'cpms_resident_registrations',
            'requested_username'
        )) {
            $sql[] = "ALTER TABLE cpms_resident_registrations
                DROP COLUMN requested_username";
        }

        return $sql;
    },
];
