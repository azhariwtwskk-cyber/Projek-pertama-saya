<?php
declare(strict_types=1);

return [
    'key' => '20260727_0002_property_integrity_prepare',
    'name' => 'Prepare property integrity columns and indexes',
    'up' => static function (mysqli $db): array {
        $sql = [];

        $databaseResult = $db->query('SELECT DATABASE() AS database_name');
        $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : [];
        $database = (string) ($databaseRow['database_name'] ?? '');

        $columnExists = static function (string $table, string $column) use ($db, $database): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ? AND column_name = ?"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sss', $database, $table, $column);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : [];
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        $indexExists = static function (string $table, string $index) use ($db, $database): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS total
                 FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sss', $database, $table, $index);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : [];
            $stmt->close();
            return (int) ($row['total'] ?? 0) > 0;
        };

        if (!$columnExists('security_patrols', 'property_id')) {
            $sql[] = "ALTER TABLE security_patrols
                      ADD COLUMN property_id INT UNSIGNED NULL AFTER id";
        }

        if (!$indexExists('security_patrols', 'idx_security_patrols_property')) {
            $sql[] = "ALTER TABLE security_patrols
                      ADD INDEX idx_security_patrols_property (property_id)";
        }

        if ($columnExists('security_guards', 'property_id')) {
            $sql[] = "UPDATE security_patrols sp
                      INNER JOIN security_guards sg ON sg.id = sp.guard_id
                      SET sp.property_id = sg.property_id
                      WHERE sp.property_id IS NULL
                        AND sg.property_id IS NOT NULL";
        }

        return $sql;
    },
    'down' => static function (mysqli $db): array {
        return [
            "ALTER TABLE security_patrols
             DROP INDEX idx_security_patrols_property",
            "ALTER TABLE security_patrols
             DROP COLUMN property_id",
        ];
    },
];
