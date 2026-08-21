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

function cpmsUnifiedPropertyUserCount(mysqli $conn, string $sql): ?int
{
    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

function cpmsUnifiedPropertyUserEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$checks = [
    'Akaun Property Portal' => cpmsUnifiedPropertyUserCount(
        $conn,
        'SELECT COUNT(*) AS total FROM property_admins'
    ),
    'Identiti Unified Login hilang' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         LEFT JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         WHERE users.id IS NULL"
    ),
    'Property ID tidak sepadan' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         INNER JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         WHERE users.property_id<>legacy.property_id
            OR users.property_id IS NULL"
    ),
    'Username tidak sepadan' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         INNER JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         WHERE users.username<>TRIM(legacy.username)"
    ),
    'Kata laluan tidak sepadan' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         INNER JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         WHERE users.password_hash<>legacy.password_hash
            OR users.must_change_password<>
               CASE WHEN legacy.must_change_password=1 THEN 1 ELSE 0 END"
    ),
    'Status tidak sepadan' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         INNER JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         WHERE users.status<>CASE
            WHEN LOWER(TRIM(legacy.status)) IN
                ('active','inactive','suspended')
                THEN LOWER(TRIM(legacy.status))
            ELSE 'inactive'
         END"
    ),
    'Peranan aktif hilang' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         INNER JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         LEFT JOIN roles canonical_role
            ON canonical_role.role_code=CASE
                WHEN LOWER(TRIM(legacy.role))='manager' THEN 'manager'
                WHEN LOWER(TRIM(legacy.role))='clerk' THEN 'clerk'
                ELSE 'property_admin'
            END
           AND canonical_role.status='active'
         LEFT JOIN user_roles assignment
            ON assignment.system_user_id=users.id
           AND assignment.role_id=canonical_role.id
           AND assignment.property_id=legacy.property_id
           AND assignment.status='active'
           AND (assignment.expires_at IS NULL
                OR assignment.expires_at>NOW())
         WHERE canonical_role.id IS NULL OR assignment.id IS NULL"
    ),
    'Peranan/property tambahan tidak sah' => cpmsUnifiedPropertyUserCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM property_admins legacy
         INNER JOIN system_users users
            ON users.source_table='property_admins'
           AND users.source_id=legacy.id
         INNER JOIN user_roles assignment
            ON assignment.system_user_id=users.id
           AND assignment.status='active'
           AND (assignment.expires_at IS NULL
                OR assignment.expires_at>NOW())
         INNER JOIN roles assigned_role
            ON assigned_role.id=assignment.role_id
           AND assigned_role.role_code IN
                ('property_admin','manager','clerk')
         WHERE assignment.property_id IS NULL
            OR assignment.property_id<>legacy.property_id
            OR assigned_role.role_code<>CASE
                WHEN LOWER(TRIM(legacy.role))='manager' THEN 'manager'
                WHEN LOWER(TRIM(legacy.role))='clerk' THEN 'clerk'
                ELSE 'property_admin'
            END"
    ),
];

$problemLabels = array_slice(array_keys($checks), 1);
$pass = $checks['Akaun Property Portal'] !== null;
foreach ($problemLabels as $label) {
    if ($checks[$label] === null || $checks[$label] !== 0) {
        $pass = false;
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.6.0.2 Unified Property User Health</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:880px;margin:auto;padding:28px;border-radius:17px;background:#fff;box-shadow:0 18px 44px #10213d17}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #dde5ef;text-align:left}th:last-child,td:last-child{text-align:right}.note{padding:13px;border-radius:10px;background:#eff6ff;color:#1e3a8a}a{color:#1d4ed8}
    </style>
</head>
<body>
<main class="box">
    <small>CPMS RELEASE CHECK</small>
    <h1>v3.6.0.2 — Unified Property User Sync</h1>
    <h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>">
        <?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?>
    </h2>
    <p class="note">Semua semakan adalah global tetapi setiap akaun kekal terikat kepada <strong>property_id</strong> masing-masing.</p>
    <table>
        <thead><tr><th>Semakan</th><th>Bilangan</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $label => $total): ?>
            <?php $isProblem = in_array($label, $problemLabels, true); ?>
            <?php $ready = $total !== null && (!$isProblem || $total === 0); ?>
            <tr>
                <td><?php echo cpmsUnifiedPropertyUserEscape($label); ?></td>
                <td class="<?php echo $ready ? 'ok' : 'bad'; ?>">
                    <?php echo $total === null ? 'Query gagal' : (string) $total; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (!$pass): ?>
        <p>Jalankan Migration 0052. Kemudian log keluar, log masuk semula dan muat semula halaman ini.</p>
    <?php else: ?>
        <p>Property Admin, Manager dan Clerk sedia digunakan melalui Unified Login.</p>
    <?php endif; ?>
</main>
</body>
</html>
