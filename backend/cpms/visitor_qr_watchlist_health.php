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
    'cpms_visitor_passes',
    'cpms_visitor_events',
    'cpms_visitor_watchlist',
    'cpms_resident_notifications',
    'cpms_property_modules',
];
$checks = [];
foreach ($tables as $table) {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$safe'");
    $checks[$table] = $result instanceof mysqli_result
        && $result->num_rows === 1;
}

$permissionCodes = [
    'visitor.view',
    'visitor.manage',
    'visitor.pre_register',
    'visitor.checkin',
    'visitor.monitor',
    'visitor.watchlist.view',
    'visitor.watchlist.manage',
    'visitor.export',
];
$escaped = array_map([$conn, 'real_escape_string'], $permissionCodes);
$list = "'" . implode("','", $escaped) . "'";
$result = $conn->query(
    "SELECT COUNT(*) AS total FROM permissions
     WHERE permission_code IN ($list)"
);
$permissionCount = $result instanceof mysqli_result
    ? (int) ($result->fetch_assoc()['total'] ?? 0)
    : 0;

$moduleCount = 0;
if (!empty($checks['cpms_property_modules'])) {
    $result = $conn->query(
        "SELECT COUNT(*) AS total FROM cpms_property_modules
         WHERE module_key='visitor_management' AND is_enabled=1"
    );
    if ($result instanceof mysqli_result) {
        $moduleCount = (int) ($result->fetch_assoc()['total'] ?? 0);
    }
}
$qrAssetReady = is_file(__DIR__ . '/assets/vendor/qrcodejs/qrcode.min.js');
$pass = !in_array(false, $checks, true)
    && $permissionCount === count($permissionCodes)
    && $moduleCount > 0
    && $qrAssetReady;

function cpmsVisitorQrHealthEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CPMS v3.6.0 Visitor Health</title><style>*{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:850px;margin:auto;padding:28px;border-radius:17px;background:#fff}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:11px;border-bottom:1px solid #dde5ef}</style></head><body><main class="box"><small>CPMS RELEASE CHECK</small><h1>v3.6.0 — Visitor QR, Watchlist & Export</h1><h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>"><?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?></h2><p>Permissions: <?php echo $permissionCount; ?>/<?php echo count($permissionCodes); ?> · Enabled properties: <?php echo $moduleCount; ?></p><table><?php foreach ($checks as $name => $ready): ?><tr><td><?php echo cpmsVisitorQrHealthEscape($name); ?></td><td class="<?php echo $ready ? 'ok' : 'bad'; ?>"><?php echo $ready ? 'Ready' : 'Missing'; ?></td></tr><?php endforeach; ?><tr><td>Local QR asset</td><td class="<?php echo $qrAssetReady ? 'ok' : 'bad'; ?>"><?php echo $qrAssetReady ? 'Ready' : 'Missing'; ?></td></tr></table><?php if (!$pass): ?><p>Run pending migrations, upload all files, then refresh this page.</p><?php endif; ?></main></body></html>
