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

$permissionReady = false;
$roleChecks = [
    'property_admin' => false,
    'manager' => false,
];
$stmt = $conn->prepare(
    "SELECT status FROM permissions
     WHERE permission_code='resident.request.manage' LIMIT 1"
);
if ($stmt && $stmt->execute()) {
    $row = $stmt->get_result()->fetch_assoc();
    $permissionReady = $row && (string) $row['status'] === 'active';
    $stmt->close();
}

$stmt = $conn->prepare(
    "SELECT r.role_code
     FROM role_permissions rp
     INNER JOIN roles r ON r.id=rp.role_id
     INNER JOIN permissions p ON p.id=rp.permission_id
     WHERE p.permission_code='resident.request.manage'
       AND r.role_code IN ('property_admin','manager')
       AND r.status='active' AND p.status='active'"
);
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $roleCode = (string) $row['role_code'];
        if (array_key_exists($roleCode, $roleChecks)) {
            $roleChecks[$roleCode] = true;
        }
    }
    $stmt->close();
}
$pass = $permissionReady && !in_array(false, $roleChecks, true);

function cpmsResidentRequestHealthEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CPMS v3.6.0.1 Permission Health</title><style>*{box-sizing:border-box}body{margin:0;padding:30px;background:#eef3f9;color:#10213d;font:15px Arial}.box{max-width:760px;margin:auto;padding:28px;border-radius:17px;background:#fff}.ok{color:#15803d}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:11px;border-bottom:1px solid #dde5ef}</style></head><body><main class="box"><small>CPMS RELEASE CHECK</small><h1>v3.6.0.1 — Resident Request Permission</h1><h2 class="<?php echo $pass ? 'ok' : 'bad'; ?>"><?php echo $pass ? 'PASS' : 'ATTENTION REQUIRED'; ?></h2><table><tr><td>resident.request.manage</td><td class="<?php echo $permissionReady ? 'ok' : 'bad'; ?>"><?php echo $permissionReady ? 'Active' : 'Missing / inactive'; ?></td></tr><?php foreach ($roleChecks as $role => $ready): ?><tr><td><?php echo cpmsResidentRequestHealthEscape($role); ?></td><td class="<?php echo $ready ? 'ok' : 'bad'; ?>"><?php echo $ready ? 'Granted' : 'Not granted'; ?></td></tr><?php endforeach; ?></table><?php if (!$pass): ?><p>Run Migration 0051, log out, log in again, then refresh.</p><?php endif; ?></main></body></html>
