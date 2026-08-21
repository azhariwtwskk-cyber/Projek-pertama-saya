<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/permission_engine.php';
require_once __DIR__ . '/../includes/dynamic_menu.php';

$permissions = cpmsPermissionLoad($conn, true);
$role = cpmsPermissionRole();
$propertyId = cpmsPermissionPropertyId();
$menuItems = cpmsDynamicMenuItems($conn, 'system_owner');

$roleCounts = [];
$result = $conn->query(
    "SELECT r.role_code, COUNT(rp.id) AS permission_count
     FROM roles r
     LEFT JOIN role_permissions rp ON rp.role_id = r.id
     WHERE r.status = 'active'
     GROUP BY r.id, r.role_code
     ORDER BY r.id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $roleCounts[] = $row;
    }
    $result->free();
}

function cpmsPermissionHealthEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Permission Health | CPMS</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif}
        .wrap{width:min(1100px,94%);margin:28px auto}
        .card{background:#fff;border:1px solid #dfe6f0;border-radius:15px;padding:22px;margin-bottom:18px;box-shadow:0 8px 28px rgba(25,40,72,.06)}
        h1,h2{margin-top:0}.pass{color:#15803d;font-weight:800}.meta{color:#64748b}
        table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5eaf1;text-align:left}
        th{background:#f8fafc}.chips{display:flex;flex-wrap:wrap;gap:8px}
        .chip{padding:7px 10px;border-radius:999px;background:#e8f1ff;color:#173b73;font-size:13px;font-weight:700}
        a{color:#173b73;font-weight:700;text-decoration:none}
    </style>
</head>
<body>
<main class="wrap">
    <p><a href="dashboard.php">← System Owner Dashboard</a></p>
    <section class="card">
        <h1>CPMS v3.1.0 — Permission Engine Health</h1>
        <?php if ($role === 'system_owner' && count($permissions) > 0): ?>
            <p class="pass">PASS — Database permission engine is active.</p>
        <?php else: ?>
            <p style="color:#b91c1c;font-weight:800">FAIL — Permission context is incomplete.</p>
        <?php endif; ?>
        <p class="meta">
            User ID: <?php echo cpmsPermissionUserId(); ?> |
            Role: <?php echo cpmsPermissionHealthEscape($role); ?> |
            Property: <?php echo $propertyId === null ? 'Global' : (int) $propertyId; ?> |
            Granted: <?php echo count($permissions); ?>
        </p>
    </section>

    <section class="card">
        <h2>Role Permission Coverage</h2>
        <table>
            <thead><tr><th>Role</th><th>Permissions</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($roleCounts as $row): ?>
                <tr>
                    <td><?php echo cpmsPermissionHealthEscape((string) $row['role_code']); ?></td>
                    <td><?php echo (int) $row['permission_count']; ?></td>
                    <td><?php echo (int) $row['permission_count'] > 0 ? 'Ready' : 'No permission'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Current System Owner Permissions</h2>
        <div class="chips">
            <?php foreach (array_keys($permissions) as $permission): ?>
                <span class="chip"><?php echo cpmsPermissionHealthEscape((string) $permission); ?></span>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>Dynamic Menu Preview</h2>
        <div class="chips">
            <?php foreach ($menuItems as $item): ?>
                <span class="chip"><?php echo cpmsPermissionHealthEscape((string) $item['label']); ?></span>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
