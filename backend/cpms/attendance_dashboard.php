<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
$propertyId = (int) ($_SESSION['cpms_property_id']
    ?? $_SESSION['property_admin_property_id'] ?? 0);
if (!$isOwner && !cpmsCan('attendance.dashboard.view', $conn)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$properties = [];
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
if ($propertyId < 1 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(422);
    exit('Tiada property aktif atau bulan tidak sah.');
}

$stats = ['employees'=>0,'sessions'=>0,'late'=>0,'overtime'=>0,'leave_pending'=>0];
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT system_user_id) employees, COUNT(*) sessions,
            COALESCE(SUM(late_minutes),0) late,
            COALESCE(SUM(overtime_minutes),0) overtime
     FROM cpms_attendance_sessions
     WHERE property_id=? AND DATE_FORMAT(work_date,'%Y-%m')=?"
);
$stmt->bind_param('is', $propertyId, $month);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($row) {
    $stats = array_merge($stats, $row);
}
$stmt = $conn->prepare(
    "SELECT COUNT(*) total FROM cpms_leave_requests
     WHERE property_id=? AND request_status='Pending'"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$stats['leave_pending'] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
$query = http_build_query(['property_id'=>$propertyId,'month'=>$month]);
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Dashboard | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1080px;margin:auto;padding:24px}.head,.card{background:#fff;border-radius:16px;padding:24px}
.head{margin-bottom:16px}.grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px}
.card strong{display:block;font-size:28px;color:#164a91;margin-top:10px}
.links,.filter{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;align-items:end}
.btn{border:0;background:#17457f;color:#fff;padding:12px 16px;border-radius:9px;text-decoration:none;font-weight:bold}
input,select{padding:10px;border:1px solid #ccd6e3;border-radius:8px}.filter label{display:grid;gap:5px}
@media(max-width:760px){.grid{grid-template-columns:1fr 1fr}main{padding:14px}}
</style></head><body><main>
<section class="head"><h1>Attendance Dashboard</h1>
<form class="filter" method="get">
<?php if ($isOwner): ?><label>Property<select name="property_id">
<?php foreach ($properties as $property): ?><option value="<?=(int)$property['id']?>"<?=(int)$property['id']===$propertyId?' selected':''?>><?=htmlspecialchars((string)$property['property_name'])?></option><?php endforeach; ?>
</select></label><?php endif; ?>
<label>Bulan<input type="month" name="month" value="<?=htmlspecialchars($month)?>"></label>
<button class="btn" type="submit">Papar</button></form>
<div class="links"><a class="btn" href="attendance_admin.php?<?=$query?>">← Pentadbiran</a>
<a class="btn" href="attendance_timesheet.php?<?=$query?>">Monthly Timesheet</a>
<a class="btn" href="leave_requests.php?property_id=<?=$propertyId?>">Leave Management</a>
<a class="btn" href="payroll_integration.php?<?=$query?>">Payroll Integration</a></div></section>
<section class="grid">
<div class="card">Pekerja Aktif<strong><?=(int)$stats['employees']?></strong></div>
<div class="card">Rekod Kehadiran<strong><?=(int)$stats['sessions']?></strong></div>
<div class="card">Jumlah Lewat<strong><?=(int)$stats['late']?> min</strong></div>
<div class="card">Jumlah OT<strong><?=(int)$stats['overtime']?> min</strong></div>
<div class="card">Cuti Menunggu<strong><?=(int)$stats['leave_pending']?></strong></div>
</section></main></body></html>
