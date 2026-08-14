<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';

if (
    (string) ($_SESSION['cpms_user_role'] ?? '') !== 'system_owner'
    && empty($_SESSION['system_owner_id'])
) {
    http_response_code(403);
    exit('System Owner access required.');
}

function cpmsVisitorHealthCount(mysqli $db, string $sql): ?int
{
    try {
        $result = $db->query($sql);
    } catch (Throwable $exception) {
        return null;
    }
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

$tableCount = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM information_schema.tables
     WHERE table_schema=DATABASE()
       AND table_name IN (
            'cpms_visitor_passes',
            'cpms_visitor_events',
            'cpms_visitor_watchlist'
       )"
);

$permissionCount = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM permissions
     WHERE status='active'
       AND permission_code IN (
            'visitor.view','visitor.manage','visitor.pre_register',
            'visitor.checkin','visitor.monitor',
            'visitor.watchlist.view','visitor.watchlist.manage',
            'visitor.export'
       )"
);

$securityGrantCount = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(DISTINCT p.permission_code) AS total
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE r.role_code='security' AND r.status='active'
       AND p.status='active'
       AND p.permission_code IN (
            'visitor.view','visitor.manage','visitor.checkin',
            'visitor.watchlist.view'
       )"
);

$residentGrantCount = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(DISTINCT p.permission_code) AS total
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE r.role_code='resident' AND r.status='active'
       AND p.status='active'
       AND p.permission_code IN (
            'visitor.view','visitor.pre_register'
       )"
);

$managementRoleCount = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(DISTINCT r.role_code) AS total
     FROM roles r
     WHERE r.role_code IN ('system_owner','property_admin','manager')
       AND r.status='active'
       AND NOT EXISTS (
            SELECT 1
            FROM permissions p
            WHERE p.status='active'
              AND p.permission_code IN (
                    'visitor.view','visitor.manage','visitor.monitor',
                    'visitor.watchlist.view','visitor.watchlist.manage',
                    'visitor.export'
              )
              AND NOT EXISTS (
                    SELECT 1 FROM role_permissions rp
                    WHERE rp.role_id=r.id AND rp.permission_id=p.id
              )
       )"
);

$missingPropertyModules = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_properties p
     LEFT JOIN cpms_property_modules m
       ON m.property_id=p.id
      AND m.module_key='visitor_management'
      AND m.is_enabled=1
     WHERE m.property_id IS NULL"
);

$crossPropertyPasses = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_visitor_passes v
     INNER JOIN cpms_residents r ON r.id=v.resident_id
     WHERE r.property_id<>v.property_id"
);

$schemaVersionCount = cpmsVisitorHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_v2_schema_versions
     WHERE version_no='3.6.0.6'"
);

$checks = [
    ['Tiga jadual Visitor', $tableCount, 3],
    ['Lapan permission Visitor aktif', $permissionCount, 8],
    ['Permission Security', $securityGrantCount, 4],
    ['Permission Resident', $residentGrantCount, 2],
    ['Role pengurusan lengkap', $managementRoleCount, 3],
    ['Property tanpa modul Visitor aktif', $missingPropertyModules, 0],
    ['Pas silang property_id', $crossPropertyPasses, 0],
    ['Versi schema 3.6.0.6', $schemaVersionCount, 1],
];

$pass = true;
foreach ($checks as $check) {
    if ($check[1] === null || $check[1] !== $check[2]) {
        $pass = false;
        break;
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.6.0.6 Visitor Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:850px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #dde5ef}.note{padding:13px;border-radius:10px;background:#eff6ff;color:#1e3a8a}.value{font-weight:900;text-align:right}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.6 — Security Visitor Operations</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">
        Semakan ini tidak mengubah data. Semua semakan meliputi jadual,
        permission dan pemisahan property_id untuk modul Visitor.
    </p>
    <table>
        <?php foreach ($checks as $check): ?>
            <?php $ready = $check[1] !== null && $check[1] === $check[2]; ?>
            <tr>
                <td><?php echo htmlspecialchars((string) $check[0], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="value <?php echo $ready ? 'ok' : 'bad'; ?>">
                    <?php echo $check[1] === null ? 'Query gagal' : (int) $check[1]; ?>
                    / <?php echo (int) $check[2]; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p>
        <?php echo $pass
            ? 'Modul Visitor sedia untuk ujian aliran Resident, Security dan Property Portal.'
            : 'Pastikan semua fail dimuat naik dan Migration 0056 telah dijalankan.'; ?>
    </p>
</main>
</body>
</html>
