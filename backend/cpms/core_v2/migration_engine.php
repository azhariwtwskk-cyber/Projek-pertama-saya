<?php
declare(strict_types=1);

final class CpmsV2MigrationEngine
{
    /** @var mysqli */
    private $db;

    /** @var string */
    private $migrationPath;

    /** @var string */
    private $snapshotPath;

    public function __construct(mysqli $db, string $migrationPath, string $snapshotPath)
    {
        $this->db = $db;
        $this->migrationPath = rtrim($migrationPath, '/\\');
        $this->snapshotPath = rtrim($snapshotPath, '/\\');
    }

    public function ensureRegistry(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS cpms_v2_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_key VARCHAR(190) NOT NULL,
                migration_name VARCHAR(255) NOT NULL,
                batch_no INT UNSIGNED NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                rolled_back_at DATETIME NULL,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
                applied_by VARCHAR(150) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cpms_v2_migration_key (migration_key),
                KEY idx_cpms_v2_migration_batch (batch_no),
                KEY idx_cpms_v2_migration_applied (applied_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$this->db->query($sql)) {
            throw new RuntimeException('Unable to create migration registry: ' . $this->db->error);
        }
    }

    public function discover(): array
    {
        $files = glob($this->migrationPath . '/*.php') ?: [];
        sort($files, SORT_NATURAL);

        $migrations = [];
        foreach ($files as $file) {
            $definition = require $file;
            if (!is_array($definition)) {
                continue;
            }

            $key = (string) ($definition['key'] ?? '');
            $name = (string) ($definition['name'] ?? basename($file));
            if ($key === '' || !isset($definition['up']) || !isset($definition['down'])) {
                continue;
            }

            $migrations[$key] = [
                'key' => $key,
                'name' => $name,
                'file' => $file,
                'checksum' => hash_file('sha256', $file),
                'up' => $definition['up'],
                'down' => $definition['down'],
            ];
        }

        return $migrations;
    }

    public function applied(): array
    {
        $this->ensureRegistry();
        $result = $this->db->query(
            "SELECT migration_key, migration_name, batch_no, checksum,
                    applied_at, execution_ms, applied_by
             FROM cpms_v2_migrations
             WHERE rolled_back_at IS NULL
             ORDER BY id ASC"
        );

        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[(string) $row['migration_key']] = $row;
            }
        }

        return $rows;
    }

    public function status(): array
    {
        $available = $this->discover();
        $applied = $this->applied();
        $status = [];

        foreach ($available as $key => $migration) {
            $status[] = [
                'key' => $key,
                'name' => $migration['name'],
                'checksum' => $migration['checksum'],
                'state' => isset($applied[$key]) ? 'applied' : 'pending',
                'checksum_match' => !isset($applied[$key])
                    || hash_equals((string) $applied[$key]['checksum'], $migration['checksum']),
                'batch_no' => isset($applied[$key]) ? (int) $applied[$key]['batch_no'] : null,
                'applied_at' => $applied[$key]['applied_at'] ?? null,
            ];
        }

        return $status;
    }

    public function migrate(string $appliedBy = 'system_owner'): array
    {
        $this->ensureRegistry();
        $available = $this->discover();
        $applied = $this->applied();
        $pending = array_filter(
            $available,
            static function (array $migration) use ($applied): bool {
                return !isset($applied[$migration['key']]);
            }
        );

        if (!$pending) {
            return ['message' => 'No pending migrations.', 'applied' => []];
        }

        $batchNo = $this->nextBatchNumber();
        $this->createSchemaSnapshot('before_batch_' . $batchNo);

        $completed = [];
        foreach ($pending as $migration) {
            $started = microtime(true);
            $this->db->begin_transaction();

            try {
                $this->executeStatements($migration['up']);

                $elapsed = (int) round((microtime(true) - $started) * 1000);
                $statement = $this->db->prepare(
                    "INSERT INTO cpms_v2_migrations
                     (migration_key, migration_name, batch_no, checksum,
                      execution_ms, applied_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                if (!$statement) {
                    throw new RuntimeException($this->db->error);
                }

                $key = $migration['key'];
                $name = $migration['name'];
                $checksum = $migration['checksum'];
                $statement->bind_param(
                    'ssisis',
                    $key,
                    $name,
                    $batchNo,
                    $checksum,
                    $elapsed,
                    $appliedBy
                );
                $statement->execute();
                $statement->close();

                $this->db->commit();
                $completed[] = $key;
            } catch (Throwable $exception) {
                $this->db->rollback();
                throw new RuntimeException(
                    'Migration failed [' . $migration['key'] . ']: ' .
                    $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        return [
            'message' => 'Migration batch completed.',
            'batch_no' => $batchNo,
            'applied' => $completed,
        ];
    }

    public function rollbackLastBatch(string $appliedBy = 'system_owner'): array
    {
        $this->ensureRegistry();
        $available = $this->discover();

        $result = $this->db->query(
            "SELECT MAX(batch_no) AS batch_no
             FROM cpms_v2_migrations
             WHERE rolled_back_at IS NULL"
        );
        $row = $result ? $result->fetch_assoc() : null;
        $batchNo = (int) ($row['batch_no'] ?? 0);

        if ($batchNo < 1) {
            return ['message' => 'Nothing to rollback.', 'rolled_back' => []];
        }

        $result = $this->db->query(
            "SELECT migration_key
             FROM cpms_v2_migrations
             WHERE batch_no = " . $batchNo . "
               AND rolled_back_at IS NULL
             ORDER BY id DESC"
        );

        $keys = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $keys[] = (string) $row['migration_key'];
            }
        }

        $this->createSchemaSnapshot('before_rollback_batch_' . $batchNo);

        $completed = [];
        foreach ($keys as $key) {
            if (!isset($available[$key])) {
                throw new RuntimeException('Migration file missing for rollback: ' . $key);
            }

            $migration = $available[$key];
            $this->db->begin_transaction();

            try {
                $this->executeStatements($migration['down']);

                $statement = $this->db->prepare(
                    "UPDATE cpms_v2_migrations
                     SET rolled_back_at = NOW(), applied_by = ?
                     WHERE migration_key = ?"
                );
                if (!$statement) {
                    throw new RuntimeException($this->db->error);
                }

                $statement->bind_param('ss', $appliedBy, $key);
                $statement->execute();
                $statement->close();

                $this->db->commit();
                $completed[] = $key;
            } catch (Throwable $exception) {
                $this->db->rollback();
                throw new RuntimeException(
                    'Rollback failed [' . $key . ']: ' .
                    $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        return [
            'message' => 'Last migration batch rolled back.',
            'batch_no' => $batchNo,
            'rolled_back' => $completed,
        ];
    }

    public function health(): array
    {
        $databaseResult = $this->db->query('SELECT DATABASE() AS database_name');
        $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : [];

        return [
            'database' => (string) ($databaseRow['database_name'] ?? ''),
            'server_version' => $this->db->server_info,
            'registry_ready' => $this->tableExists('cpms_v2_migrations'),
            'migration_directory' => is_dir($this->migrationPath),
            'snapshot_directory' => is_dir($this->snapshotPath),
            'snapshot_writable' => is_dir($this->snapshotPath) && is_writable($this->snapshotPath),
            'available_migrations' => count($this->discover()),
            'applied_migrations' => count($this->applied()),
        ];
    }

    public function createSchemaSnapshot(string $label): string
    {
        if (!is_dir($this->snapshotPath)) {
            @mkdir($this->snapshotPath, 0755, true);
        }

        if (!is_dir($this->snapshotPath) || !is_writable($this->snapshotPath)) {
            throw new RuntimeException(
                'Snapshot directory is unavailable or not writable: ' . $this->snapshotPath
            );
        }

        $databaseResult = $this->db->query('SELECT DATABASE() AS database_name');
        $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : [];
        $database = (string) ($databaseRow['database_name'] ?? '');

        $statement = $this->db->prepare(
            "SELECT table_name, engine, table_collation
             FROM information_schema.tables
             WHERE table_schema = ?
             ORDER BY table_name"
        );
        if (!$statement) {
            throw new RuntimeException($this->db->error);
        }

        $statement->bind_param('s', $database);
        $statement->execute();
        $result = $statement->get_result();

        $tables = [];
        if ($result) {
            while ($table = $result->fetch_assoc()) {
                $name = (string) $table['table_name'];
                $createResult = $this->db->query(
                    'SHOW CREATE TABLE `' . str_replace('`', '``', $name) . '`'
                );
                $createRow = $createResult ? $createResult->fetch_assoc() : [];

                $tables[$name] = [
                    'engine' => $table['engine'],
                    'collation' => $table['table_collation'],
                    'create_sql' => $createRow['Create Table'] ?? null,
                ];
            }
        }
        $statement->close();

        $payload = [
            'generated_at' => date(DATE_ATOM),
            'database' => $database,
            'label' => $label,
            'warning' => 'Schema snapshot only. This is not a full data backup.',
            'tables' => $tables,
        ];

        $safeLabel = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $label);
        $file = $this->snapshotPath . '/schema_' .
            date('Ymd_His') . '_' . $safeLabel . '.json';

        $written = file_put_contents(
            $file,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException('Unable to write schema snapshot.');
        }

        return $file;
    }

    private function nextBatchNumber(): int
    {
        $result = $this->db->query(
            "SELECT COALESCE(MAX(batch_no), 0) + 1 AS next_batch
             FROM cpms_v2_migrations"
        );
        $row = $result ? $result->fetch_assoc() : null;
        return max(1, (int) ($row['next_batch'] ?? 1));
    }

    private function executeStatements($definition): void
    {
        $statements = is_callable($definition)
            ? call_user_func($definition, $this->db)
            : $definition;

        if (!is_array($statements)) {
            throw new RuntimeException('Migration definition must return an SQL array.');
        }

        foreach ($statements as $sql) {
            $sql = trim((string) $sql);
            if ($sql === '') {
                continue;
            }

            if (!$this->db->query($sql)) {
                throw new RuntimeException($this->db->error . ' | SQL: ' . $sql);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $databaseResult = $this->db->query('SELECT DATABASE() AS database_name');
        $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : [];
        $database = (string) ($databaseRow['database_name'] ?? '');

        $statement = $this->db->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.tables
             WHERE table_schema = ? AND table_name = ?"
        );
        if (!$statement) {
            return false;
        }

        $statement->bind_param('ss', $database, $table);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $statement->close();

        return (int) ($row['total'] ?? 0) > 0;
    }
}
