<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__)
    . '/includes/preventive_maintenance_service.php';

cpmsRequire('maintenance.report', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);

function cpmsPmHealthTableExists(
    mysqli $conn,
    string $tableName
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
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsPmHealthPermissionExists(
    mysqli $conn,
    string $permission
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM permissions
         WHERE permission_code = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $permission);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

$tableChecks = [];
foreach ([
    'cpms_pm_schedules',
    'cpms_pm_work_logs',
    'cpms_pm_evidence',
    'cpms_pm_budgets',
    'cpms_user_notifications',
] as $tableName) {
    $tableChecks[$tableName] = cpmsPmHealthTableExists(
        $conn,
        $tableName
    );
}

$permissionChecks = [];
foreach ([
    'maintenance.view',
    'maintenance.manage',
    'maintenance.complete',
    'maintenance.verify',
    'maintenance.analytics',
    'maintenance.budget',
    'maintenance.report',
] as $permission) {
    $permissionChecks[$permission] = cpmsPmHealthPermissionExists(
        $conn,
        $permission
    );
}

$versionChecks = [];
foreach (['3.3.0','3.3.1','3.3.2','3.3.3','3.3.4','3.3.5','3.3.6'] as $version) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM cpms_v2_schema_versions
         WHERE version_no = ?"
    );
    $exists = false;
    if ($stmt) {
        $stmt->bind_param('s', $version);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $exists = (int) ($row['total'] ?? 0) > 0;
        $stmt->close();
    }
    $versionChecks[$version] = $exists;
}

$uploadDirectory = dirname(__DIR__)
    . '/uploads/preventive_maintenance';
$uploadParent = dirname(__DIR__) . '/uploads';
$uploadReady = is_dir($uploadDirectory)
    ? is_writable($uploadDirectory)
    : (is_dir($uploadParent) && is_writable($uploadParent));

$propertyReady = $propertyId > 0;
$dataChecks = [
    'Schedules' => 0,
    'Work Logs' => 0,
    'Evidence Images' => 0,
    'Budget Records' => 0,
];
$dataTables = [
    'Schedules' => 'cpms_pm_schedules',
    'Work Logs' => 'cpms_pm_work_logs',
    'Evidence Images' => 'cpms_pm_evidence',
    'Budget Records' => 'cpms_pm_budgets',
];
foreach ($dataTables as $label => $tableName) {
    if (!($tableChecks[$tableName] ?? false)) {
        continue;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM {$tableName}
         WHERE property_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('i', $propertyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $dataChecks[$label] = (int) ($row['total'] ?? 0);
        $stmt->close();
    }
}

$failed = 0;
foreach ($tableChecks as $ready) {
    if (!$ready) {
        $failed++;
    }
}
foreach ($permissionChecks as $ready) {
    if (!$ready) {
        $failed++;
    }
}
foreach ($versionChecks as $ready) {
    if (!$ready) {
        $failed++;
    }
}
if (!$propertyReady) {
    $failed++;
}
if (!$uploadReady) {
    $failed++;
}
$overallReady = $failed === 0;

$pageTitle = 'Maintenance Module Health';
$activeMenu = 'preventive_maintenance';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.health-wrap{padding:24px}.health-hero,.health-card{background:#fff;border:1px solid #dbe3ef;border-radius:14px;padding:20px;margin-bottom:14px}.health-hero h1{margin-top:0}.health-pass{color:#15803d}.health-fail{color:#b91c1c}.health-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.health-table{width:100%;border-collapse:collapse}.health-table th,.health-table td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.health-status{font-weight:800}.health-data{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.health-data div{background:#f8fafc;padding:14px;border-radius:9px}.health-data strong{display:block;color:#173b73;font-size:24px}.health-btn{display:inline-block;background:#173b73;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:800}@media(max-width:800px){.health-wrap{padding:14px}.health-grid{grid-template-columns:1fr}.health-data{grid-template-columns:repeat(2,1fr)}}
</style>
<div class="health-wrap">
    <a class="health-btn" href="preventive_maintenance.php">← Preventive Maintenance</a>
    <section class="health-hero">
        <h1>CPMS v3.3.6 — Preventive Maintenance Health</h1>
        <?php if ($overallReady): ?>
            <h2 class="health-pass">PASS — Preventive Maintenance module is healthy.</h2>
        <?php else: ?>
            <h2 class="health-fail">ATTENTION — <?php echo $failed; ?> check(s) require action.</h2>
        <?php endif; ?>
        <p>Property: <?php echo cpmsPmEscape($currentPropertyName); ?>
            (ID <?php echo $propertyId; ?>)</p>
    </section>

    <section class="health-card">
        <h2>Property Data Summary</h2>
        <div class="health-data">
            <?php foreach ($dataChecks as $label => $total): ?>
                <div><strong><?php echo $total; ?></strong><?php echo cpmsPmEscape($label); ?></div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="health-grid">
        <section class="health-card">
            <h2>Required Tables</h2>
            <table class="health-table">
                <?php foreach ($tableChecks as $name => $ready): ?>
                    <tr><td><?php echo cpmsPmEscape($name); ?></td><td class="health-status <?php echo $ready ? 'health-pass' : 'health-fail'; ?>"><?php echo $ready ? 'Ready' : 'Missing'; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </section>
        <section class="health-card">
            <h2>Required Permissions</h2>
            <table class="health-table">
                <?php foreach ($permissionChecks as $name => $ready): ?>
                    <tr><td><?php echo cpmsPmEscape($name); ?></td><td class="health-status <?php echo $ready ? 'health-pass' : 'health-fail'; ?>"><?php echo $ready ? 'Ready' : 'Missing'; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </section>
        <section class="health-card">
            <h2>Release Versions</h2>
            <table class="health-table">
                <?php foreach ($versionChecks as $name => $ready): ?>
                    <tr><td>CPMS v<?php echo cpmsPmEscape($name); ?></td><td class="health-status <?php echo $ready ? 'health-pass' : 'health-fail'; ?>"><?php echo $ready ? 'Applied' : 'Missing'; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </section>
        <section class="health-card">
            <h2>Runtime Checks</h2>
            <table class="health-table">
                <tr><td>Property Context</td><td class="health-status <?php echo $propertyReady ? 'health-pass' : 'health-fail'; ?>"><?php echo $propertyReady ? 'Ready' : 'Missing'; ?></td></tr>
                <tr><td>Evidence Upload Folder</td><td class="health-status <?php echo $uploadReady ? 'health-pass' : 'health-fail'; ?>"><?php echo $uploadReady ? 'Writable' : 'Not Writable'; ?></td></tr>
                <tr><td>PHP Version</td><td><?php echo cpmsPmEscape(PHP_VERSION); ?></td></tr>
                <tr><td>Database</td><td><?php echo cpmsPmEscape((string) $conn->server_info); ?></td></tr>
            </table>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
