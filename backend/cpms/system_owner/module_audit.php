<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function moduleAuditEscape(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function moduleAuditTableExists(
    mysqli $conn,
    string $table
): bool {
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

function moduleAuditColumnExists(
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

$cpmsRoot = dirname(__DIR__);

$checks = [
    [
        'group' => 'Files',
        'name' => 'Foundation bootstrap',
        'pass' => is_file(
            $cpmsRoot . '/includes/foundation_bootstrap.php'
        ),
    ],
    [
        'group' => 'Files',
        'name' => 'Shared portal helpers',
        'pass' => is_file(
            $cpmsRoot . '/includes/portal_helpers.php'
        ),
    ],
    [
        'group' => 'Files',
        'name' => 'System Owner config',
        'pass' => is_file(__DIR__ . '/config.php'),
    ],
    [
        'group' => 'Files',
        'name' => 'Property Portal config',
        'pass' => is_file(
            $cpmsRoot . '/property_portal/config.php'
        ),
    ],
];

$tables = [
    'system_users',
    'cpms_properties',
    'property_admins',
    'cpms_residents',
    'complaints',
    'staff',
    'work_orders',
    'cpms_complaint_history',
    'cpms_demo_batches',
    'cpms_system_versions',
];

foreach ($tables as $table) {
    $checks[] = [
        'group' => 'Database',
        'name' => 'Table: ' . $table,
        'pass' => moduleAuditTableExists($conn, $table),
    ];
}

$columns = [
    ['staff', 'property_id'],
    ['complaints', 'property_id'],
    ['work_orders', 'property_id'],
    ['property_admins', 'property_id'],
    ['cpms_system_versions', 'version'],
    ['cpms_system_versions', 'migration_name'],
];

foreach ($columns as [$table, $column]) {
    $checks[] = [
        'group' => 'Database',
        'name' => $table . '.' . $column,
        'pass' => moduleAuditColumnExists(
            $conn,
            $table,
            $column
        ),
    ];
}

$passed = count(array_filter(
    $checks,
    static fn(array $check): bool => $check['pass']
));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Module Audit | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .audit-actions{display:flex;gap:10px;margin-bottom:20px}
        .audit-actions a{padding:10px 14px;background:#e9eef5;
        border-radius:8px;text-decoration:none;color:#334155;
        font-weight:800}
        .audit-list{display:grid;gap:10px;margin-top:18px}
        .audit-item{display:grid;grid-template-columns:90px 65px 1fr;
        gap:12px;align-items:center;padding:12px;border:1px solid #e2e8f0;
        border-radius:10px}
        .audit-group{font-size:12px;color:#64748b;font-weight:800}
        .audit-pass,.audit-fail{padding:5px 8px;border-radius:7px;
        text-align:center;font-size:11px;font-weight:900}
        .audit-pass{background:#dcfce7;color:#166534}
        .audit-fail{background:#fee2e2;color:#991b1b}
    </style>
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>Foundation Phase 2 Audit</strong><br>
            <small>
                <?php echo $passed; ?> /
                <?php echo count($checks); ?> checks passed
            </small>
        </div>
        <a href="logout.php">Log Out</a>
    </header>

    <div class="audit-actions">
        <a href="dashboard.php">← Dashboard</a>
        <a href="foundation_check.php">Foundation Check</a>
    </div>

    <section class="so-panel">
        <h2>Shared Core and Database Contract</h2>

        <div class="audit-list">
            <?php foreach ($checks as $check): ?>
                <article class="audit-item">
                    <span class="audit-group">
                        <?php echo moduleAuditEscape($check['group']); ?>
                    </span>

                    <span class="<?php
                        echo $check['pass']
                            ? 'audit-pass'
                            : 'audit-fail';
                    ?>">
                        <?php echo $check['pass'] ? 'PASS' : 'FAIL'; ?>
                    </span>

                    <strong>
                        <?php echo moduleAuditEscape($check['name']); ?>
                    </strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
</body>
</html>
