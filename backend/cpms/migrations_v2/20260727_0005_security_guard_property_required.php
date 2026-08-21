<?php
declare(strict_types=1);

return [
    'key' => '20260727_0005_security_guard_property_required',
    'name' => 'Require property assignment for security guards',

    'up' => static function (mysqli $db): array {
        $nullResult = $db->query(
            'SELECT COUNT(*) AS total
             FROM security_guards
             WHERE property_id IS NULL'
        );

        if (!$nullResult) {
            throw new RuntimeException($db->error);
        }

        $nullRow = $nullResult->fetch_assoc();
        $nullCount = (int) ($nullRow['total'] ?? 0);

        if ($nullCount > 0) {
            throw new RuntimeException(
                'Cannot require property_id: '
                . $nullCount
                . ' security guard record(s) are still unassigned.'
            );
        }

        $columnResult = $db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'security_guards'
               AND column_name = 'property_id'
             LIMIT 1"
        );

        if (!$columnResult) {
            throw new RuntimeException($db->error);
        }

        $column = $columnResult->fetch_assoc();

        if (!$column) {
            throw new RuntimeException(
                'security_guards.property_id does not exist.'
            );
        }

        if (strtoupper((string) $column['IS_NULLABLE']) === 'NO') {
            return [];
        }

        $columnType = (string) ($column['COLUMN_TYPE'] ?? 'int unsigned');

        return [
            'ALTER TABLE security_guards
             MODIFY COLUMN property_id '
             . $columnType
             . ' NOT NULL'
        ];
    },

    'down' => static function (mysqli $db): array {
        $columnResult = $db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'security_guards'
               AND column_name = 'property_id'
             LIMIT 1"
        );

        if (!$columnResult) {
            throw new RuntimeException($db->error);
        }

        $column = $columnResult->fetch_assoc();

        if (!$column) {
            return [];
        }

        if (strtoupper((string) $column['IS_NULLABLE']) === 'YES') {
            return [];
        }

        $columnType = (string) ($column['COLUMN_TYPE'] ?? 'int unsigned');

        return [
            'ALTER TABLE security_guards
             MODIFY COLUMN property_id '
             . $columnType
             . ' NULL'
        ];
    },
];
