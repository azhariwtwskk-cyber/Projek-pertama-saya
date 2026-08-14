<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__
    . '/cpms/includes/staff_session_compat.php';
require_once __DIR__
    . '/cpms/includes/permission_engine.php';

$repaired = cpmsStaffSessionRepair($conn);
$canDashboard = $repaired && cpmsCan('dashboard.view', $conn);

http_response_code($canDashboard ? 200 : 403);

function healthEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Session Health | CPMS</title>
    <style>
        body{font-family:Arial;background:#f3f6fb;color:#10213c;padding:24px}
        main{max-width:720px;margin:auto;background:#fff;padding:28px;border-radius:16px}
        .pass{color:#087b35}.fail{color:#b42318}
        table{width:100%;border-collapse:collapse;margin-top:18px}
        td{padding:11px;border-bottom:1px solid #dfe6ef}
        a{display:inline-block;margin-top:20px}
    </style>
</head>
<body>
<main>
    <h1>CPMS v3.3.6.1 — Staff Session Health</h1>
    <h2 class="<?php echo $canDashboard ? 'pass' : 'fail'; ?>">
        <?php echo $canDashboard ? 'PASS' : 'FAIL'; ?>
    </h2>
    <table>
        <tr><td>Legacy Staff ID</td><td><?php echo (int) ($_SESSION['staff_id'] ?? 0); ?></td></tr>
        <tr><td>System User ID</td><td><?php echo (int) ($_SESSION['cpms_user_id'] ?? 0); ?></td></tr>
        <tr><td>Role</td><td><?php echo healthEscape((string) ($_SESSION['cpms_user_role'] ?? '-')); ?></td></tr>
        <tr><td>Property ID</td><td><?php echo (int) ($_SESSION['cpms_property_id'] ?? 0); ?></td></tr>
        <tr><td>dashboard.view</td><td><?php echo $canDashboard ? 'Granted' : 'Denied'; ?></td></tr>
    </table>
    <?php if ($canDashboard): ?>
        <a href="staff_dashboard.php">Buka Staff Dashboard</a>
    <?php else: ?>
        <p>Logout dan masuk semula melalui Unified Login.</p>
        <a href="staff_logout.php">Logout Staff</a>
    <?php endif; ?>
</main>
</body>
</html>
