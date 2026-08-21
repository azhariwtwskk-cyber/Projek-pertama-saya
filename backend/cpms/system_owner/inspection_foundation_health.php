<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$requiredTables = [
    'inspection_reports',
    'inspection_images',
    'inspection_checklist_items',
    'inspection_status_history',
    'compliance_schedules',
    'compliance_completion_history',
    'inspection_corrective_actions',
    'inspection_action_images',
];

$tableResults = [];
$allTablesReady = true;

foreach ($requiredTables as $table) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $exists = false;

    if ($stmt) {
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $exists = (int) ($row['total'] ?? 0) === 1;
    }

    $tableResults[$table] = $exists;
    if (!$exists) {
        $allTablesReady = false;
    }
}

$orphanChecks = [
    'Inspection without property' => (
        'SELECT COUNT(*) AS total
         FROM inspection_reports r
         LEFT JOIN cpms_properties p ON p.id = r.property_id
         WHERE p.id IS NULL'
    ),
    'Corrective action property mismatch' => (
        'SELECT COUNT(*) AS total
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r ON r.id = a.inspection_id
         WHERE a.property_id <> r.property_id'
    ),
    'Action image property mismatch' => (
        'SELECT COUNT(*) AS total
         FROM inspection_action_images i
         INNER JOIN inspection_corrective_actions a ON a.id = i.action_id
         WHERE i.property_id <> a.property_id
            OR i.inspection_id <> a.inspection_id'
    ),
];

$orphanResults = [];
$integrityReady = $allTablesReady;

if ($allTablesReady) {
    foreach ($orphanChecks as $label => $sql) {
        $result = $conn->query($sql);
        $total = -1;
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $total = (int) ($row['total'] ?? 0);
            $result->free();
        }
        $orphanResults[$label] = $total;
        if ($total !== 0) {
            $integrityReady = false;
        }
    }
}

$permissionCount = 0;
$permissionResult = $conn->query(
    "SELECT COUNT(*) AS total
     FROM permissions
     WHERE permission_code IN (
        'inspection.action.manage',
        'inspection.action.rectify',
        'inspection.action.verify',
        'compliance.view',
        'compliance.create',
        'compliance.update',
        'compliance.approve'
     )"
);
if ($permissionResult instanceof mysqli_result) {
    $row = $permissionResult->fetch_assoc();
    $permissionCount = (int) ($row['total'] ?? 0);
    $permissionResult->free();
}

$healthPass = $allTablesReady
    && $integrityReady
    && $permissionCount === 7;

function cpmsInspectionHealthEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inspection Foundation Health | CPMS</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif}
        .wrap{width:min(1050px,94%);margin:28px auto}
        .card{background:#fff;border:1px solid #dfe6f0;border-radius:15px;padding:22px;margin-bottom:18px;box-shadow:0 8px 28px rgba(25,40,72,.06)}
        h1,h2{margin-top:0}.pass{color:#15803d;font-weight:800}.fail{color:#b91c1c;font-weight:800}
        table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e5eaf1;text-align:left}th{background:#f8fafc}
        a{color:#173b73;font-weight:700;text-decoration:none}
    </style>
</head>
<body>
<main class="wrap">
    <p><a href="dashboard.php">← System Owner Dashboard</a></p>
    <section class="card">
        <h1>CPMS v3.2.0 — Inspection Foundation Health</h1>
        <p class="<?php echo $healthPass ? 'pass' : 'fail'; ?>">
            <?php echo $healthPass
                ? 'PASS — Inspection & Compliance foundation is healthy.'
                : 'FAIL — Foundation requires attention.'; ?>
        </p>
        <p>Permissions installed: <?php echo $permissionCount; ?>/7</p>
    </section>

    <section class="card">
        <h2>Required Tables</h2>
        <table>
            <thead><tr><th>Table</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($tableResults as $table => $exists): ?>
                <tr>
                    <td><?php echo cpmsInspectionHealthEscape($table); ?></td>
                    <td class="<?php echo $exists ? 'pass' : 'fail'; ?>">
                        <?php echo $exists ? 'Ready' : 'Missing'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($allTablesReady): ?>
        <section class="card">
            <h2>Property Integrity</h2>
            <table>
                <thead><tr><th>Check</th><th>Issues</th></tr></thead>
                <tbody>
                <?php foreach ($orphanResults as $label => $total): ?>
                    <tr>
                        <td><?php echo cpmsInspectionHealthEscape($label); ?></td>
                        <td class="<?php echo $total === 0 ? 'pass' : 'fail'; ?>">
                            <?php echo $total; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
