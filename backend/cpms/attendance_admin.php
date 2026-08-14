<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
if (!$isOwner && !cpmsCan('attendance.admin.manage', $conn)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$properties = [];
$propertyId = (int) ($_SESSION['cpms_property_id']
    ?? $_SESSION['property_admin_property_id'] ?? 0);
if ($isOwner) {
    $result = $conn->query(
        'SELECT id, property_name FROM cpms_properties ORDER BY property_name'
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $properties[] = $row;
    }
    $propertyId = (int) ($_GET['property_id']
        ?? ($propertyId > 0 ? $propertyId : ($properties[0]['id'] ?? 0)));
}
$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$query = http_build_query(['property_id' => $propertyId, 'month' => $month]);
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Administration | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1050px;margin:auto;padding:24px}.head,.card{background:#fff;border-radius:16px;padding:24px}
.head{margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.card{text-decoration:none;color:#10213d}.card h2{color:#17457f}select,input,button{padding:11px;border:1px solid #ccd6e3;border-radius:8px}
.filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:18px}.filter label{display:grid;gap:5px}
button{background:#17457f;color:#fff;font-weight:bold;cursor:pointer}
@media(max-width:700px){.grid{grid-template-columns:1fr}main{padding:14px}}
</style></head><body><main>
<section class="head"><h1>Attendance Administration</h1>
<p>Property Admin/Manager mengendalikan operasi kehadiran harian. System Owner memantau semua property.</p>
<form class="filter" method="get">
<?php if ($isOwner): ?><label>Property<select name="property_id" required>
<?php foreach ($properties as $property): ?><option value="<?=(int)$property['id']?>"<?=(int)$property['id']===$propertyId?' selected':''?>><?=htmlspecialchars((string)$property['property_name'])?></option><?php endforeach; ?>
</select></label><?php endif; ?>
<label>Bulan<input type="month" name="month" value="<?=htmlspecialchars($month)?>"></label>
<button type="submit">Pilih</button></form></section>
<?php if ($propertyId < 1): ?><section class="head"><strong>Tiada property aktif dijumpai.</strong></section>
<?php else: ?><section class="grid">
<a class="card" href="attendance_dashboard.php?<?=$query?>"><h2>Dashboard</h2><p>KPI kehadiran, lewat dan OT.</p></a>
<a class="card" href="attendance_timesheet.php?<?=$query?>"><h2>Timesheet</h2><p>Semak rekod bulanan.</p></a>
<a class="card" href="attendance_approvals.php?<?=$query?>"><h2>Approval</h2><p>Lulus atau kunci timesheet.</p></a>
<a class="card" href="attendance_delegations.php?property_id=<?=$propertyId?>"><h2>Delegation</h2><p>Wakilkan kuasa kelulusan sementara.</p></a>
<a class="card" href="attendance_approval_audit.php?property_id=<?=$propertyId?>"><h2>Audit Trail</h2><p>Sejarah setiap kelulusan.</p></a>
<a class="card" href="attendance_exceptions.php?<?=$query?>"><h2>Exceptions</h2><p>Lewat dan missing clock-out.</p></a>
</section><?php endif; ?></main></body></html>
