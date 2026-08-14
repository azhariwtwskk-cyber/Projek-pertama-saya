<?php

declare(strict_types=1);

function cpmsMigrationTableExists(
    mysqli $conn,
    string $tableName
): bool {
    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $tableName);
    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function cpmsMigrationColumnExists(
    mysqli $conn,
    string $tableName,
    string $columnName
): bool {
    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ss",
        $tableName,
        $columnName
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function cpmsMigrationIndexExists(
    mysqli $conn,
    string $tableName,
    string $indexName
): bool {
    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND index_name = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ss",
        $tableName,
        $indexName
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0) > 0;
}

function cpmsEnsureMigrationTable(
    mysqli $conn
): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS cpms_migrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_key VARCHAR(120) NOT NULL,
            migration_name VARCHAR(190) NOT NULL,
            batch_number INT UNSIGNED NOT NULL DEFAULT 1,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_migration_key (migration_key)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException(
            "Jadual cpms_migrations tidak dapat diwujudkan."
        );
    }
}

function cpmsAppliedMigrationKeys(
    mysqli $conn
): array {
    cpmsEnsureMigrationTable($conn);

    $keys = [];

    $result = $conn->query(
        "
        SELECT migration_key
        FROM cpms_migrations
        ORDER BY id ASC
        "
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $keys[] =
            (string) $row["migration_key"];
    }

    return $keys;
}

function cpmsRegisterMigration(
    mysqli $conn,
    string $migrationKey,
    string $migrationName,
    int $batchNumber = 1
): void {
    $stmt = $conn->prepare(
        "
        INSERT INTO cpms_migrations
        (
            migration_key,
            migration_name,
            batch_number
        )
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            migration_name = VALUES(migration_name)
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            "Rekod migrasi tidak dapat disediakan."
        );
    }

    $stmt->bind_param(
        "ssi",
        $migrationKey,
        $migrationName,
        $batchNumber
    );

    if (!$stmt->execute()) {
        $stmt->close();

        throw new RuntimeException(
            "Rekod migrasi tidak dapat disimpan."
        );
    }

    $stmt->close();
}

function cpmsMigrationDefinitions(): array
{
    return [
        [
            "key" => "001_system_settings",
            "name" => "System Settings",
            "detected" => static function (
                mysqli $conn
            ): bool {
                return cpmsMigrationTableExists(
                    $conn,
                    "system_settings"
                );
            },
            "run" => static function (
                mysqli $conn
            ): void {
                $sql = "
                    CREATE TABLE IF NOT EXISTS system_settings (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        setting_key VARCHAR(120) NOT NULL,
                        setting_value TEXT DEFAULT NULL,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY unique_setting_key (setting_key)
                    ) ENGINE=InnoDB
                    DEFAULT CHARSET=utf8mb4
                    COLLATE=utf8mb4_unicode_ci
                ";

                if (!$conn->query($sql)) {
                    throw new RuntimeException(
                        "System Settings gagal diwujudkan."
                    );
                }
            }
        ],
        [
            "key" => "002_module_manager",
            "name" => "Module Manager",
            "detected" => static function (
                mysqli $conn
            ): bool {
                return cpmsMigrationTableExists(
                    $conn,
                    "cpms_modules"
                );
            },
            "run" => static function (
                mysqli $conn
            ): void {
                $sql = "
                    CREATE TABLE IF NOT EXISTS cpms_modules (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        module_key VARCHAR(80) NOT NULL,
                        module_name_ms VARCHAR(150) NOT NULL,
                        module_name_en VARCHAR(150) NOT NULL,
                        description_ms VARCHAR(255) DEFAULT NULL,
                        description_en VARCHAR(255) DEFAULT NULL,
                        module_group VARCHAR(80) NOT NULL DEFAULT 'General',
                        icon VARCHAR(20) DEFAULT '📦',
                        route VARCHAR(190) DEFAULT NULL,
                        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                        sort_order INT NOT NULL DEFAULT 100,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY unique_module_key (module_key)
                    ) ENGINE=InnoDB
                    DEFAULT CHARSET=utf8mb4
                    COLLATE=utf8mb4_unicode_ci
                ";

                if (!$conn->query($sql)) {
                    throw new RuntimeException(
                        "Module Manager gagal diwujudkan."
                    );
                }
            }
        ],
        [
            "key" => "003_property_engine",
            "name" => "Property Engine",
            "detected" => static function (
                mysqli $conn
            ): bool {
                return cpmsMigrationTableExists(
                    $conn,
                    "cpms_properties"
                );
            },
            "run" => static function (
                mysqli $conn
            ): void {
                $sql = "
                    CREATE TABLE IF NOT EXISTS cpms_properties (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        property_code VARCHAR(40) NOT NULL,
                        property_name VARCHAR(180) NOT NULL,
                        company_name VARCHAR(180) DEFAULT NULL,
                        address TEXT DEFAULT NULL,
                        phone VARCHAR(40) DEFAULT NULL,
                        email VARCHAR(180) DEFAULT NULL,
                        website VARCHAR(190) DEFAULT NULL,
                        logo_path VARCHAR(255) NOT NULL DEFAULT 'images/logo.png',
                        primary_color VARCHAR(7) NOT NULL DEFAULT '#3a2419',
                        secondary_color VARCHAR(7) NOT NULL DEFAULT '#b59b20',
                        default_language ENUM('ms','en') NOT NULL DEFAULT 'ms',
                        currency_code VARCHAR(10) NOT NULL DEFAULT 'MYR',
                        timezone_name VARCHAR(80) NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY unique_property_code (property_code)
                    ) ENGINE=InnoDB
                    DEFAULT CHARSET=utf8mb4
                    COLLATE=utf8mb4_unicode_ci
                ";

                if (!$conn->query($sql)) {
                    throw new RuntimeException(
                        "Property Engine gagal diwujudkan."
                    );
                }
            }
        ],
        [
            "key" => "004_complaint_property",
            "name" => "Complaint Property Migration",
            "detected" => static function (
                mysqli $conn
            ): bool {
                return
                    cpmsMigrationColumnExists(
                        $conn,
                        "complaints",
                        "property_id"
                    ) &&
                    cpmsMigrationColumnExists(
                        $conn,
                        "complaint_images",
                        "property_id"
                    );
            },
            "run" => static function (
                mysqli $conn
            ): void {
                if (
                    !cpmsMigrationColumnExists(
                        $conn,
                        "complaints",
                        "property_id"
                    )
                ) {
                    if (
                        !$conn->query(
                            "
                            ALTER TABLE complaints
                            ADD COLUMN property_id
                            INT UNSIGNED NULL
                            AFTER complaint_id
                            "
                        )
                    ) {
                        throw new RuntimeException(
                            "property_id gagal ditambah pada complaints."
                        );
                    }
                }

                if (
                    !cpmsMigrationColumnExists(
                        $conn,
                        "complaint_images",
                        "property_id"
                    )
                ) {
                    if (
                        !$conn->query(
                            "
                            ALTER TABLE complaint_images
                            ADD COLUMN property_id
                            INT UNSIGNED NULL
                            AFTER complaint_id
                            "
                        )
                    ) {
                        throw new RuntimeException(
                            "property_id gagal ditambah pada complaint_images."
                        );
                    }
                }
            }
        ],
        [
            "key" => "005_work_order_property",
            "name" => "Work Order Property Migration",
            "detected" => static function (
                mysqli $conn
            ): bool {
                return cpmsMigrationColumnExists(
                    $conn,
                    "work_orders",
                    "property_id"
                );
            },
            "run" => static function (
                mysqli $conn
            ): void {
                if (
                    !cpmsMigrationColumnExists(
                        $conn,
                        "work_orders",
                        "property_id"
                    )
                ) {
                    if (
                        !$conn->query(
                            "
                            ALTER TABLE work_orders
                            ADD COLUMN property_id
                            INT UNSIGNED NULL
                            AFTER id
                            "
                        )
                    ) {
                        throw new RuntimeException(
                            "property_id gagal ditambah pada work_orders."
                        );
                    }
                }

                if (
                    !cpmsMigrationIndexExists(
                        $conn,
                        "work_orders",
                        "idx_work_orders_property_id"
                    )
                ) {
                    $conn->query(
                        "
                        CREATE INDEX idx_work_orders_property_id
                        ON work_orders(property_id)
                        "
                    );
                }
            }
        ],
        [
            "key" => "006_complaint_sequences",
            "name" => "Property Complaint Sequences",
            "detected" => static function (
                mysqli $conn
            ): bool {
                return cpmsMigrationTableExists(
                    $conn,
                    "cpms_complaint_sequences"
                );
            },
            "run" => static function (
                mysqli $conn
            ): void {
                $sql = "
                    CREATE TABLE IF NOT EXISTS cpms_complaint_sequences (
                        property_id INT UNSIGNED NOT NULL,
                        last_number INT UNSIGNED NOT NULL DEFAULT 0,
                        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (property_id)
                    ) ENGINE=InnoDB
                    DEFAULT CHARSET=utf8mb4
                    COLLATE=utf8mb4_unicode_ci
                ";

                if (!$conn->query($sql)) {
                    throw new RuntimeException(
                        "Sequence aduan gagal diwujudkan."
                    );
                }
            }
        ]
    ];
}

function cpmsSyncDetectedMigrations(
    mysqli $conn
): int {
    cpmsEnsureMigrationTable($conn);

    $applied =
        cpmsAppliedMigrationKeys($conn);

    $synced = 0;

    foreach (
        cpmsMigrationDefinitions() as
        $migration
    ) {
        if (
            in_array(
                $migration["key"],
                $applied,
                true
            )
        ) {
            continue;
        }

        if (
            $migration["detected"]($conn)
        ) {
            cpmsRegisterMigration(
                $conn,
                $migration["key"],
                $migration["name"],
                1
            );

            $synced++;
        }
    }

    return $synced;
}

function cpmsRunPendingMigrations(
    mysqli $conn
): array {
    cpmsEnsureMigrationTable($conn);

    $applied =
        cpmsAppliedMigrationKeys($conn);

    $pending = array_values(
        array_filter(
            cpmsMigrationDefinitions(),
            static fn(array $migration): bool =>
                !in_array(
                    $migration["key"],
                    $applied,
                    true
                )
        )
    );

    if (count($pending) === 0) {
        return [];
    }

    $batchResult = $conn->query(
        "
        SELECT COALESCE(MAX(batch_number), 0) AS last_batch
        FROM cpms_migrations
        "
    );

    $batchRow =
        $batchResult
            ? $batchResult->fetch_assoc()
            : [];

    $batchNumber =
        ((int) ($batchRow["last_batch"] ?? 0)) + 1;

    $completed = [];

    foreach ($pending as $migration) {
        $conn->begin_transaction();

        try {
            $migration["run"]($conn);

            cpmsRegisterMigration(
                $conn,
                $migration["key"],
                $migration["name"],
                $batchNumber
            );

            $conn->commit();

            $completed[] =
                $migration["key"];

        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }

            throw new RuntimeException(
                $migration["name"] .
                ": " .
                $error->getMessage()
            );
        }
    }

    return $completed;
}
