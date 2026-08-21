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

function cpmsResidentRequestRoleCount(
    mysqli $db,
    string $sql
): ?int {
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

$matrix = cpmsPropertyPermissionMatrix();
$matrixRoles = $matrix['resident.request.manage'] ?? [];
$matrixReady = in_array('property_admin', $matrixRoles, true)
    && in_array('manager', $matrixRoles, true);

$permissionCount = cpmsResidentRequestRoleCount(
    $conn,
    "SELECT COUNT(*) AS total FROM permissions
     WHERE permission_code='resident.request.manage'
       AND status='active'"
);

$roleGrantCount = cpmsResidentRequestRoleCount(
    $conn,
    "SELECT COUNT(DISTINCT r.role_code) AS total
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE r.role_code IN ('property_admin','manager')
       AND r.status='active'
       AND p.permission_code='resident.request.manage'
       AND p.status='active'"
);

$pass = $matrixReady
    && $permissionCount === 1
    && $roleGrantCount === 2;
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.6.0.5 Resident Request Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:780px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #dde5ef}.note{padding:13px;border-radius:10px;background:#eff6ff;color:#1e3a8a}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.5 — Resident Request Role Repair</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">
        Property Admin dan Manager boleh mengurus Resident Requests bagi
        property_id mereka sendiri.
    </p>
    <table>
        <tr>
            <td>Static permission fallback</td>
            <td class="<?php echo $matrixReady ? 'ok' : 'bad'; ?>">
                <?php echo $matrixReady ? 'Ready' : 'Missing'; ?>
            </td>
        </tr>
        <tr>
            <td>resident.request.manage aktif</td>
            <td class="<?php echo $permissionCount === 1
                ? 'ok'
                : 'bad'; ?>">
                <?php echo $permissionCount === null
                    ? 'Query gagal'
                    : (int) $permissionCount; ?> / 1
            </td>
        </tr>
        <tr>
            <td>Property Admin + Manager grants</td>
            <td class="<?php echo $roleGrantCount === 2
                ? 'ok'
                : 'bad'; ?>">
                <?php echo $roleGrantCount === null
                    ? 'Query gagal'
                    : (int) $roleGrantCount; ?> / 2
            </td>
        </tr>
    </table>
    <?php if (!$pass): ?>
        <p>Upload semua fail hotfix dan jalankan Migration 0055.</p>
    <?php else: ?>
        <p>Resident Requests sedia dibuka oleh Property Admin dan Manager.</p>
    <?php endif; ?>
</main>
</body>
</html>
