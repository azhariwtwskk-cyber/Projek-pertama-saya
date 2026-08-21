<?php
declare(strict_types=1);

return [
    'key' => '20260727_0003_security_guard_property_assignment',
    'name' => 'Add property assignment to security guards',

    'up' => static function (mysqli $db): array {
        $sql = [];

        $databaseResult = $db->query(
            'SELECT DATABASE() AS database_name'
        );
        $databaseRow = $databaseResult
            ? $databaseResult->fetch_assoc()
            : [];
        $database = (string) (
            $databaseRow['database_name'] ?? ''
        );

        $columnExists = static function (
            string $table,
            string $column
        ) use ($db, $database): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = ?
                   AND table_name = ?
                   AND column_name = ?'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                'sss',
                $database,
                $table,
                $column
            );
            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result
                ? $result->fetch_assoc()
                : [];

            $stmt->close();

            return (int) (
                $row['total'] ?? 0
            ) > 0;
        };

        $indexExists = static function (
            string $table,
            string $index
        ) use ($db, $database): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.statistics
                 WHERE table_schema = ?
                   AND table_name = ?
                   AND index_name = ?'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                'sss',
                $database,
                $table,
                $index
            );
            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result
                ? $result->fetch_assoc()
                : [];

            $stmt->close();

            return (int) (
                $row['total'] ?? 0
            ) > 0;
        };

        if (
            !$columnExists(
                'security_guards',
                'property_id'
            )
        ) {
            $sql[] = '
                ALTER TABLE security_guards
                ADD COLUMN property_id
                INT UNSIGNED NULL AFTER id
            ';
        }

        if (
            !$indexExists(
                'security_guards',
                'idx_security_guards_property_id'
            )
        ) {
            $sql[] = '
                ALTER TABLE security_guards
                ADD INDEX
                idx_security_guards_property_id
                (property_id)
            ';
        }

        return $sql;
    },

    'down' => static function (mysqli $db): array {
        $sql = [];

        $databaseResult = $db->query(
            'SELECT DATABASE() AS database_name'
        );
        $databaseRow = $databaseResult
            ? $databaseResult->fetch_assoc()
            : [];
        $database = (string) (
            $databaseRow['database_name'] ?? ''
        );

        $columnExists = static function (
            string $table,
            string $column
        ) use ($db, $database): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = ?
                   AND table_name = ?
                   AND column_name = ?'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                'sss',
                $database,
                $table,
                $column
            );
            $stmt->execute();

            $result = $stmt->get_result();
            $row = $result
                ? $result->fetch_assoc()
                : [];

            $stmt->close();

            return (int) (
                $row['total'] ?? 0
            ) > 0;
        };

        if (
            $columnExists(
                'security_guards',
                'property_id'
            )
        ) {
            $sql[] = '
                ALTER TABLE security_guards
                DROP COLUMN property_id
            ';
        }

        return $sql;
    },
];
