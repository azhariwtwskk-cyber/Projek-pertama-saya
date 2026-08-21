<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/property_permissions.php';

if (
    (string) ($_SESSION['cpms_user_role'] ?? '') !== 'system_owner'
    && empty($_SESSION['system_owner_id'])
) {
    http_response_code(403);
    exit('System Owner access required.');
}

$matrix = cpmsPropertyPermissionMatrix();
$matrixReady = isset($matrix['work_orders.view'])
    && in_array('clerk', $matrix['work_orders.view'], true);

$permissionReady = false;
$stmt = $conn->prepare(
    "SELECT status FROM permissions
     WHERE permission_code='work_orders.view' LIMIT 1"
);
if ($stmt && $stmt->execute()) {
    $row = $stmt->get_result()->fetch_assoc();
    $permissionReady = $row && (string) $row['status'] === 'active';
    $stmt->close();
}

$grantReady = false;
$stmt = $conn->prepare(
    "SELECT rp.id
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE r.role_code='clerk'
       AND r.status='active'
       AND p.permission_code='work_orders.view'
       AND p.status='active'
     LIMIT 1"
);
if ($stmt && $stmt->execute()) {
    $grantReady = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pass = $matrixReady && $permissionReady && $grantReady;

function cpmsClerkWorkOrderHealthStatus(bool $ready): string
{
    return $ready ? 'Ready' : 'Missing';
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.6.0.3 Clerk Work Order Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:780px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #dde5ef}.note{padding:13px;border-radius:10px;background:#eff6ff;color:#1e3a8a}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.3 — Clerk Work Order View</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">Kerani diberikan akses lihat sahaja. Cipta, kemas kini, assignment dan approval kekal tidak dibenarkan.</p>
    <table>
        <tr><td>Static permission matrix</td><td class="<?php echo $matrixReady ? 'ok' : 'bad'; ?>"><?php echo cpmsClerkWorkOrderHealthStatus($matrixReady); ?></td></tr>
        <tr><td>work_orders.view</td><td class="<?php echo $permissionReady ? 'ok' : 'bad'; ?>"><?php echo cpmsClerkWorkOrderHealthStatus($permissionReady); ?></td></tr>
        <tr><td>Clerk database grant</td><td class="<?php echo $grantReady ? 'ok' : 'bad'; ?>"><?php echo cpmsClerkWorkOrderHealthStatus($grantReady); ?></td></tr>
        <tr><td>Data scope</td><td class="ok">Current property_id only</td></tr>
    </table>
    <?php if (!$pass): ?>
        <p>Upload semua fail, jalankan Migration 0053, log keluar dan login semula.</p>
    <?php endif; ?>
</main>
</body>
</html>
