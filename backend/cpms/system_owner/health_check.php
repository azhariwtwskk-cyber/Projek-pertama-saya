<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function healthEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$requiredTables = [
    'cpms_properties',
    'property_admins',
    'cpms_residents',
    'complaints',
    'staff',
    'work_orders',
    'cpms_complaint_history',
    'cpms_demo_batches',
];

$checks = [];

foreach ($requiredTables as $table) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();

    $checks[] = [
        'name' => 'Table: ' . $table,
        'pass' => $exists,
        'detail' => $exists ? 'Available' : 'Missing',
    ];
}

$staffPropertyColumn = false;
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'staff'
       AND column_name = 'property_id'"
);
$stmt->execute();
$staffPropertyColumn = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$stmt->close();

$checks[] = [
    'name' => 'Staff property isolation',
    'pass' => $staffPropertyColumn,
    'detail' => $staffPropertyColumn
        ? 'staff.property_id is available'
        : 'Run Build 004 migration',
];

$propertyStats = [];
$result = $conn->query(
    "SELECT
        p.id,
        p.property_name,
        COUNT(DISTINCT c.id) AS complaint_total,
        COUNT(DISTINCT r.id) AS resident_total,
        COUNT(DISTINCT wo.id) AS work_order_total
     FROM cpms_properties p
     LEFT JOIN complaints c ON c.property_id = p.id
     LEFT JOIN cpms_residents r ON r.property_id = p.id
     LEFT JOIN work_orders wo ON wo.property_id = p.id
     GROUP BY p.id, p.property_name
     ORDER BY p.id"
);

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $propertyStats[] = $row;
    }
}

$passCount = count(array_filter($checks, fn(array $c): bool => $c['pass']));
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Health | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/build_005.css">
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>CPMS System Health</strong><br>
            <small><?php echo $passCount; ?> / <?php echo count($checks); ?> checks passed</small>
        </div>
        <a href="logout.php">Log Keluar</a>
    </header>

    <div class="build-actions">
        <a href="dashboard.php">← Dashboard</a>
        <a href="demo_data.php">Demo Data</a>
    </div>

    <section class="so-panel">
        <h2>Database & Module Checks</h2>
        <div class="health-list">
            <?php foreach ($checks as $check): ?>
                <div class="health-item">
                    <span class="<?php echo $check['pass'] ? 'health-pass' : 'health-fail'; ?>">
                        <?php echo $check['pass'] ? 'PASS' : 'FAIL'; ?>
                    </span>
                    <div>
                        <strong><?php echo healthEscape($check['name']); ?></strong>
                        <small><?php echo healthEscape($check['detail']); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="so-panel">
        <h2>Property Data Summary</h2>
        <div class="so-table-wrap">
            <table class="so-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Property</th>
                    <th>Residents</th>
                    <th>Complaints</th>
                    <th>Work Orders</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($propertyStats as $row): ?>
                    <tr>
                        <td><?php echo (int) $row['id']; ?></td>
                        <td><?php echo healthEscape($row['property_name']); ?></td>
                        <td><?php echo (int) $row['resident_total']; ?></td>
                        <td><?php echo (int) $row['complaint_total']; ?></td>
                        <td><?php echo (int) $row['work_order_total']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
