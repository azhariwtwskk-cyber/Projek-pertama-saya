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

$tables = [
    'cpms_facilities',
    'cpms_facility_bookings',
    'cpms_facility_booking_updates',
    'cpms_facility_booking_cancellations',
    'cpms_resident_notifications',
];
$checks = [];
foreach ($tables as $table) {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");
    $checks[$table] = $result instanceof mysqli_result
        && $result->num_rows === 1;
}

$permissionCodes = [
    'facilities.view',
    'facilities.book',
    'facilities.manage',
    'facility.booking.approve',
    'facility.booking.cancel',
    'facility.booking.calendar',
    'resident.notifications.view',
];
$escapedCodes = array_map(
    [$conn, 'real_escape_string'],
    $permissionCodes
);
$permissionList = "'" . implode("','", $escapedCodes) . "'";
$result = $conn->query(
    "SELECT COUNT(*) AS total FROM permissions
     WHERE permission_code IN ($permissionList)"
);
$permissionCount = $result instanceof mysqli_result
    ? (int) (($result->fetch_assoc()['total'] ?? 0))
    : 0;
$pass = !in_array(false, $checks, true)
    && $permissionCount === count($permissionCodes);

function cpmsFacilityHealthEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CPMS v3.5.8 Facility Health</title><style>*{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:850px;margin:auto;padding:28px;border-radius:17px;background:#fff}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:11px;border-bottom:1px solid #dde5ef}</style></head>
<body><main class="box"><small>CPMS RELEASE CHECK</small><h1>v3.5.8 — Facility Booking & Approval</h1><h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>"><?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?></h2><p>Permissions: <?php echo $permissionCount; ?>/<?php echo count($permissionCodes); ?></p><table><?php foreach ($checks as $name => $ready): ?><tr><td><?php echo cpmsFacilityHealthEscape($name); ?></td><td class="<?php echo $ready ? 'ok' : 'bad'; ?>"><?php echo $ready ? 'Ready' : 'Missing'; ?></td></tr><?php endforeach; ?></table><?php if (!$pass): ?><p>Run pending migrations, then refresh this page.</p><?php endif; ?></main></body>
</html>
