<?php
declare(strict_types=1);

return [
    'key' => '20260727_0004_foreign_key_enforcement',
    'name' => 'Enforce validated CPMS property foreign keys',

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

        $foreignKeyExists = static function (
            string $table,
            string $column,
            string $parentTable,
            string $parentColumn
        ) use ($db, $database): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.key_column_usage
                 WHERE table_schema = ?
                   AND table_name = ?
                   AND column_name = ?
                   AND referenced_table_name = ?
                   AND referenced_column_name = ?'
            );

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                'sssss',
                $database,
                $table,
                $column,
                $parentTable,
                $parentColumn
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

        $definitions = [
            [
                'table' => 'complaints',
                'column' => 'property_id',
                'constraint' => 'fk_complaints_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
            [
                'table' => 'complaint_images',
                'column' => 'property_id',
                'constraint' => 'fk_complaint_images_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
            [
                'table' => 'work_orders',
                'column' => 'property_id',
                'constraint' => 'fk_work_orders_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
            [
                'table' => 'assets',
                'column' => 'property_id',
                'constraint' => 'fk_assets_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
            [
                'table' => 'notifications',
                'column' => 'property_id',
                'constraint' => 'fk_notifications_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
            [
                'table' => 'security_patrols',
                'column' => 'property_id',
                'constraint' => 'fk_security_patrols_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
            [
                'table' => 'security_guards',
                'column' => 'property_id',
                'constraint' => 'fk_security_guards_property',
                'parent_table' => 'cpms_properties',
                'parent_column' => 'id',
                'delete_rule' => 'RESTRICT',
            ],
        ];

        foreach ($definitions as $definition) {
            if (
                $foreignKeyExists(
                    $definition['table'],
                    $definition['column'],
                    $definition['parent_table'],
                    $definition['parent_column']
                )
            ) {
                continue;
            }

            $sql[] = sprintf(
                'ALTER TABLE `%s`
                 ADD CONSTRAINT `%s`
                 FOREIGN KEY (`%s`)
                 REFERENCES `%s` (`%s`)
                 ON UPDATE CASCADE
                 ON DELETE %s',
                str_replace('`', '``', $definition['table']),
                str_replace('`', '``', $definition['constraint']),
                str_replace('`', '``', $definition['column']),
                str_replace('`', '``', $definition['parent_table']),
                str_replace('`', '``', $definition['parent_column']),
                $definition['delete_rule']
            );
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

        $constraintExists = static function (
            string $table,
            string $constraint
        ) use ($db, $database): bool {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM information_schema.table_constraints
                 WHERE constraint_schema = ?
                   AND table_name = ?
                   AND constraint_name = ?
                   AND constraint_type = ?'
            );

            if (!$stmt) {
                return false;
            }

            $type = 'FOREIGN KEY';
            $stmt->bind_param(
                'ssss',
                $database,
                $table,
                $constraint,
                $type
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

        $definitions = [
            ['complaints', 'fk_complaints_property'],
            ['complaint_images', 'fk_complaint_images_property'],
            ['work_orders', 'fk_work_orders_property'],
            ['assets', 'fk_assets_property'],
            ['notifications', 'fk_notifications_property'],
            ['security_patrols', 'fk_security_patrols_property'],
            ['security_guards', 'fk_security_guards_property'],
        ];

        foreach ($definitions as $definition) {
            if (!$constraintExists($definition[0], $definition[1])) {
                continue;
            }

            $sql[] = sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                str_replace('`', '``', $definition[0]),
                str_replace('`', '``', $definition[1])
            );
        }

        return $sql;
    },
];
