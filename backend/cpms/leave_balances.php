<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
$canManage = $isOwner || cpmsCan('leave.balance.manage', $conn);
if (!$isOwner && !cpmsCan('leave.balance.view', $conn)) {
    http_response_code(403);
    exit('Akses ditolak.');
}
$properties = [];
if ($isOwner) {
    $result = $conn->query(
        "SELECT id,property_name FROM cpms_properties ORDER BY property_name"
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $properties[] = $row;
    }
    $propertyId = (int) (
        $_REQUEST['property_id'] ?? ($propertyId > 0
            ? $propertyId : ($properties[0]['id'] ?? 0))
    );
}
$year = (int) ($_REQUEST['leave_year'] ?? date('Y'));
if ($propertyId < 1 || $year < 2020 || $year > 2100) {
    http_response_code(422);
    exit('Property atau tahun tidak sah.');
}
if (empty($_SESSION['cpms_leave_balance_csrf'])) {
    $_SESSION['cpms_leave_balance_csrf'] = bin2hex(random_bytes(24));
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $canManage) {
    if (!hash_equals(
        (string) $_SESSION['cpms_leave_balance_csrf'],
        (string) ($_POST['csrf'] ?? '')
    )) {
        $error = 'Token keselamatan tidak sah.';
    } else {
        $employeeId = (int) ($_POST['system_user_id'] ?? 0);
        $typeId = (int) ($_POST['leave_type_id'] ?? 0);
        $entitlement = (float) ($_POST['entitlement_days'] ?? 0);
        $adjustment = (float) ($_POST['adjustment_days'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($employeeId < 1 || $typeId < 1
            || $entitlement < 0 || $entitlement > 366
            || $adjustment < -366 || $adjustment > 366) {
            $error = 'Maklumat baki cuti tidak sah.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO cpms_leave_balances
                 (property_id, system_user_id, leave_type_id, leave_year,
                  entitlement_days, adjustment_days, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                  entitlement_days=VALUES(entitlement_days),
                  adjustment_days=VALUES(adjustment_days),
                  notes=VALUES(notes)"
            );
            $stmt->bind_param(
                'iiiidds', $propertyId, $employeeId, $typeId, $year,
                $entitlement, $adjustment, $notes
            );
            $stmt->execute();
            $stmt->close();
            $message = 'Baki cuti berjaya dikemas kini.';
        }
    }
}
$employees = [];
$stmt = $conn->prepare(
    "SELECT DISTINCT u.id,u.full_name FROM system_users u
     JOIN user_roles ur ON ur.system_user_id=u.id AND ur.status='active'
     JOIN roles r ON r.id=ur.role_id
       AND r.role_code IN ('staff','security')
     WHERE u.property_id=? AND u.status='active' ORDER BY u.full_name"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {$employees[] = $row;}
$stmt->close();
$types = [];
$stmt = $conn->prepare(
    "SELECT id,leave_name FROM cpms_leave_types
     WHERE property_id=? AND active=1 ORDER BY leave_name"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {$types[] = $row;}
$stmt->close();
$balances = [];
$sql = "SELECT lb.*,u.full_name,lt.leave_name,
        COALESCE(SUM(CASE WHEN lr.request_status='Approved'
          THEN lr.total_days ELSE 0 END),0) used_days
        FROM cpms_leave_balances lb
        JOIN system_users u ON u.id=lb.system_user_id
        JOIN cpms_leave_types lt ON lt.id=lb.leave_type_id
        LEFT JOIN cpms_leave_requests lr
          ON lr.system_user_id=lb.system_user_id
         AND lr.leave_type_id=lb.leave_type_id
         AND YEAR(lr.start_date)=lb.leave_year
        WHERE lb.property_id=? AND lb.leave_year=?";
if (!$canManage) {$sql .= " AND lb.system_user_id=?";}
$sql .= " GROUP BY lb.id,u.full_name,lt.leave_name ORDER BY u.full_name,lt.leave_name";
$stmt = $conn->prepare($sql);
if ($canManage) {$stmt->bind_param('ii', $propertyId, $year);}
else {$stmt->bind_param('iii', $propertyId, $year, $userId);}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {$balances[] = $row;}
$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leave Balance | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1080px;margin:auto;padding:22px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
input,select,textarea{width:100%;padding:10px;border:1px solid #ccd6e3;border-radius:8px}
button,.btn{background:#17457f;color:#fff;border:0;padding:11px 15px;border-radius:8px;font-weight:bold;text-decoration:none}
table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}
.table{overflow:auto}.ok{color:#07852f}.err{color:#b42318}
@media(max-width:700px){.grid{grid-template-columns:1fr}}
</style></head><body><main>
<section class="card"><h1>Leave Balance</h1>
<form><?php if($isOwner):?><label>Property <select name="property_id">
<?php foreach($properties as $p):?><option value="<?=(int)$p['id']?>"<?=(int)$p['id']===$propertyId?' selected':''?>>
<?=htmlspecialchars($p['property_name'])?></option><?php endforeach;?></select></label>
<?php else:?><input type="hidden" name="property_id" value="<?=$propertyId?>"><?php endif;?>
<label>Tahun <input type="number" name="leave_year" value="<?=$year?>" min="2020" max="2100"></label>
<button>Papar</button></form>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="err"><?=htmlspecialchars($error)?></p><?php endif;?></section>
<?php if($canManage):?><section class="card"><h2>Tetapkan Kelayakan</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_leave_balance_csrf'])?>">
<input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="leave_year" value="<?=$year?>">
<div class="grid"><label>Pekerja<select name="system_user_id" required><option value="">-- Pilih --</option>
<?php foreach($employees as $e):?><option value="<?=(int)$e['id']?>"><?=htmlspecialchars($e['full_name'])?></option><?php endforeach;?></select></label>
<label>Jenis cuti<select name="leave_type_id" required><option value="">-- Pilih --</option>
<?php foreach($types as $t):?><option value="<?=(int)$t['id']?>"><?=htmlspecialchars($t['leave_name'])?></option><?php endforeach;?></select></label>
<label>Kelayakan hari<input type="number" step="0.5" min="0" name="entitlement_days" required></label>
<label>Pelarasan (+/-)<input type="number" step="0.5" name="adjustment_days" value="0"></label>
<label>Catatan<textarea name="notes"></textarea></label></div><p><button>Simpan</button></p></form></section><?php endif;?>
<section class="card table"><table><tr><th>Nama</th><th>Jenis</th><th>Kelayakan</th><th>Pelarasan</th><th>Digunakan</th><th>Baki</th></tr>
<?php foreach($balances as $b):$available=(float)$b['entitlement_days']+(float)$b['adjustment_days']-(float)$b['used_days'];?>
<tr><td><?=htmlspecialchars($b['full_name'])?></td><td><?=htmlspecialchars($b['leave_name'])?></td>
<td><?=number_format((float)$b['entitlement_days'],2)?></td><td><?=number_format((float)$b['adjustment_days'],2)?></td>
<td><?=number_format((float)$b['used_days'],2)?></td><td><strong><?=number_format($available,2)?></strong></td></tr><?php endforeach;?></table></section>
</main></body></html>
