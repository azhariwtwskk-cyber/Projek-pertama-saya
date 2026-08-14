<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (
    (string) ($_SESSION['cpms_user_role'] ?? '') !== 'system_owner'
    && empty($_SESSION['system_owner_id'])
) {
    http_response_code(403);
    exit('System Owner access required.');
}

function cpmsClerkModuleHealthScalar(
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

function cpmsClerkModuleHealthEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$grantTable = cpmsClerkModuleHealthScalar(
    $conn,
    "SELECT COUNT(*) AS total FROM information_schema.tables
     WHERE table_schema=DATABASE()
       AND table_name='cpms_user_permission_grants'"
);

$announcementColumns = cpmsClerkModuleHealthScalar(
    $conn,
    "SELECT COUNT(*) AS total FROM information_schema.columns
     WHERE table_schema=DATABASE()
       AND table_name='cpms_resident_announcements'
       AND column_name IN (
            'notice_type','priority','requires_confirmation'
       )"
);

$assignPermission = cpmsClerkModuleHealthScalar(
    $conn,
    "SELECT COUNT(*) AS total FROM permissions
     WHERE permission_code='clerk.modules.assign'
       AND status='active'"
);

$assignRoles = cpmsClerkModuleHealthScalar(
    $conn,
    "SELECT COUNT(DISTINCT r.role_code) AS total
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE r.role_code IN ('property_admin','manager')
       AND r.status='active'
       AND p.permission_code='clerk.modules.assign'
       AND p.status='active'"
);

$assignedGrants = null;
$invalidGrants = null;
if ($grantTable === 1) {
    $assignedGrants = cpmsClerkModuleHealthScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM cpms_user_permission_grants
         WHERE status='active'
           AND grant_source='clerk_module_assignment'"
    );
    $invalidGrants = cpmsClerkModuleHealthScalar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM cpms_user_permission_grants g
         INNER JOIN system_users u ON u.id=g.system_user_id
         LEFT JOIN property_admins pa
           ON pa.id=u.source_id
          AND u.source_table='property_admins'
         WHERE g.status='active'
           AND g.grant_source='clerk_module_assignment'
           AND (
                u.property_id<>g.property_id
                OR pa.id IS NULL
                OR pa.property_id<>g.property_id
                OR pa.role<>'clerk'
           )"
    );
}

$checks = [
    'Jadual per-user grants' => [
        'value' => $grantTable,
        'expected' => 1,
    ],
    'Kolum announcement lengkap' => [
        'value' => $announcementColumns,
        'expected' => 3,
    ],
    'Permission clerk.modules.assign' => [
        'value' => $assignPermission,
        'expected' => 1,
    ],
    'Property Admin + Manager boleh assign' => [
        'value' => $assignRoles,
        'expected' => 2,
    ],
    'Grant tidak sah / silang property' => [
        'value' => $invalidGrants,
        'expected' => 0,
    ],
];

$pass = true;
foreach ($checks as $check) {
    if (
        $check['value'] === null
        || $check['value'] !== $check['expected']
    ) {
        $pass = false;
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.6.0.4 Clerk Module Access Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:850px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #dde5ef;text-align:left}th:last-child,td:last-child{text-align:right}.note{padding:13px;border-radius:10px;background:#eff6ff;color:#1e3a8a}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.4 — Clerk Module Access</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">
        Assignment dibuat bagi setiap Kerani dan setiap property.
        Kerani tidak menerima akses settings atau property lain.
    </p>
    <table>
        <thead><tr><th>Semakan</th><th>Nilai</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $label => $check): ?>
            <?php $ready = $check['value'] !== null
                && $check['value'] === $check['expected']; ?>
            <tr>
                <td><?php echo cpmsClerkModuleHealthEscape($label); ?></td>
                <td class="<?php echo $ready ? 'ok' : 'bad'; ?>">
                    <?php echo $check['value'] === null
                        ? 'Query gagal'
                        : (string) $check['value']; ?>
                    / <?php echo (int) $check['expected']; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td>Jumlah permission tambahan aktif</td>
            <td><?php echo $assignedGrants === null
                ? '-'
                : (int) $assignedGrants; ?></td>
        </tr>
        </tbody>
    </table>
    <?php if (!$pass): ?>
        <p>Upload semua fail pakej ini dan jalankan Migration 0054.</p>
    <?php else: ?>
        <p>Akses modul Kerani sedia digunakan.</p>
    <?php endif; ?>
</main>
</body>
</html>
