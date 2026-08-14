<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function foundationEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function foundationTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function foundationColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

$checks = [
    [
        'name' => 'Database connection',
        'pass' => $conn instanceof mysqli,
        'detail' => 'mysqli connection is available',
    ],
];

$requiredTables = [
    'system_users',
    'cpms_properties',
    'property_admins',
    'cpms_residents',
    'complaints',
    'staff',
    'work_orders',
    'cpms_system_versions',
];

foreach ($requiredTables as $table) {
    $exists = foundationTableExists($conn, $table);
    $checks[] = [
        'name' => 'Table: ' . $table,
        'pass' => $exists,
        'detail' => $exists ? 'Available' : 'Missing',
    ];
}

$foundationColumns = [
    ['staff', 'property_id'],
    ['complaints', 'property_id'],
    ['work_orders', 'property_id'],
];

foreach ($foundationColumns as [$table, $column]) {
    $exists = foundationColumnExists($conn, $table, $column);
    $checks[] = [
        'name' => $table . '.' . $column,
        'pass' => $exists,
        'detail' => $exists
            ? 'Available'
            : 'Run Foundation migration',
    ];
}

$versionStructureOk =
    foundationColumnExists($conn, 'cpms_system_versions', 'version')
    && foundationColumnExists(
        $conn,
        'cpms_system_versions',
        'migration_name'
    );

$checks[] = [
    'name' => 'Version table structure',
    'pass' => $versionStructureOk,
    'detail' => $versionStructureOk
        ? 'Uses version and migration_name'
        : 'Unexpected version-table structure',
];

$passed = count(array_filter(
    $checks,
    static fn(array $check): bool => $check['pass']
));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Foundation Check | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/foundation.css">
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>CPMS Foundation Check</strong><br>
            <small>
                <?php echo $passed; ?> /
                <?php echo count($checks); ?> checks passed
            </small>
        </div>
        <a href="dashboard.php">Dashboard</a>
    </header>

    <section class="so-panel">
        <h2>Foundation Status</h2>

        <div class="foundation-list">
            <?php foreach ($checks as $check): ?>
                <article class="foundation-item">
                    <span class="<?php
                        echo $check['pass']
                            ? 'foundation-pass'
                            : 'foundation-fail';
                    ?>">
                        <?php echo $check['pass'] ? 'PASS' : 'FAIL'; ?>
                    </span>

                    <div>
                        <strong>
                            <?php echo foundationEscape($check['name']); ?>
                        </strong>
                        <small>
                            <?php echo foundationEscape($check['detail']); ?>
                        </small>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
</body>
</html>
