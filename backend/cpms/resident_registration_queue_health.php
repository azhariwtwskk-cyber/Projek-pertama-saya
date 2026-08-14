<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/property_permissions.php';
require_once __DIR__ . '/includes/user_permission_service.php';

if (
    (string) ($_SESSION['cpms_user_role'] ?? '') !== 'system_owner'
    && empty($_SESSION['system_owner_id'])
) {
    http_response_code(403);
    exit('System Owner access required.');
}

function cpmsResidentRegistrationHealthCount(
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
$matrixRoles = $matrix['resident.registration.manage'] ?? [];
$matrixReady = in_array('property_admin', $matrixRoles, true)
    && in_array('manager', $matrixRoles, true);

$catalog = cpmsDelegatedModuleCatalog();
$catalogPermissions = $catalog['resident_registrations']['permissions'] ?? [];
$clerkCatalogReady = in_array(
    'resident.registration.manage',
    $catalogPermissions,
    true
);

$tableCount = cpmsResidentRegistrationHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM information_schema.tables
     WHERE table_schema=DATABASE()
       AND table_name='cpms_resident_registrations'"
);

$permissionCount = cpmsResidentRegistrationHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM permissions
     WHERE permission_code='resident.registration.manage'
       AND status='active'"
);

$roleGrantCount = cpmsResidentRegistrationHealthCount(
    $conn,
    "SELECT COUNT(DISTINCT r.role_code) AS total
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE r.role_code IN ('property_admin','manager')
       AND r.status='active'
       AND p.permission_code='resident.registration.manage'
       AND p.status='active'"
);

$crossPropertyApprovalCount = cpmsResidentRegistrationHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_resident_registrations a
     INNER JOIN cpms_residents r ON r.id=a.approved_resident_id
     WHERE a.approved_resident_id IS NOT NULL
       AND a.property_id<>r.property_id"
);

$pendingCount = cpmsResidentRegistrationHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_resident_registrations
     WHERE status='Pending'"
);

$schemaVersionCount = cpmsResidentRegistrationHealthCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM cpms_v2_schema_versions
     WHERE version_no='3.6.0.7'"
);

$checks = [
    ['Jadual resident registrations', $tableCount, 1],
    ['Permission aktif', $permissionCount, 1],
    ['Property Admin + Manager grants', $roleGrantCount, 2],
    ['Static permission fallback', $matrixReady ? 1 : 0, 1],
    ['Pilihan modul Kerani', $clerkCatalogReady ? 1 : 0, 1],
    ['Kelulusan silang property_id', $crossPropertyApprovalCount, 0],
    ['Versi schema 3.6.0.7', $schemaVersionCount, 1],
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
    <title>CPMS v3.6.0.7 Resident Registration Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:820px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #dde5ef}.value{text-align:right;font-weight:900}.note{padding:13px;border-radius:10px;color:#1e3a8a;background:#eff6ff}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.7 — Resident Registration Queue</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">
        Permohonan Pending dalam semua property sekarang:
        <strong><?php echo $pendingCount === null
            ? 'Query gagal'
            : (int) $pendingCount; ?></strong>.
        Setiap portal tetap hanya membaca property_id sendiri.
    </p>
    <table>
        <?php foreach ($checks as $check): ?>
            <?php $ready = $check[1] !== null && $check[1] === $check[2]; ?>
            <tr>
                <td><?php echo htmlspecialchars((string) $check[0], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="value <?php echo $ready ? 'ok' : 'bad'; ?>">
                    <?php echo $check[1] === null
                        ? 'Query gagal'
                        : (int) $check[1]; ?>
                    / <?php echo (int) $check[2]; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p>
        <?php echo $pass
            ? 'Dashboard dan assignment modul Kerani sedia diuji.'
            : 'Upload semua fail patch dan jalankan Migration 0057.'; ?>
    </p>
</main>
</body>
</html>
