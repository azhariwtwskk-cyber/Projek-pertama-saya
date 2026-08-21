<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
$role=(string)($_SESSION['cpms_user_role']??'');
$isOwner=$role==='system_owner'||isset($_SESSION['system_owner_id']);
$propertyId=(int)($_SESSION['cpms_property_id']??0);
if($isOwner){$propertyId=(int)($_GET['property_id']??$propertyId);}
if(!$isOwner && !cpmsCan('payroll.integration.view',$conn)){http_response_code(403);exit('Akses ditolak.');}
$month=(string)($_GET['month']??date('Y-m'));
if($propertyId<1||!preg_match('/^\d{4}-\d{2}$/',$month)){http_response_code(422);exit('Maklumat tidak sah.');}
$rows=[];
$stmt=$conn->prepare("SELECT u.full_name,p.employee_no,
 COALESCE(SUM(a.worked_minutes),0) worked_minutes,
 COALESCE(SUM(a.overtime_minutes),0) overtime_minutes,
 COALESCE(SUM(a.late_minutes),0) late_minutes,
 p.ordinary_rate,p.overtime_rate
 FROM system_users u
 JOIN user_roles ur ON ur.system_user_id=u.id AND ur.status='active'
 JOIN roles r ON r.id=ur.role_id AND r.role_code IN ('staff','security')
 LEFT JOIN cpms_payroll_profiles p ON p.system_user_id=u.id AND p.property_id=u.property_id
 LEFT JOIN cpms_attendance_sessions a ON a.system_user_id=u.id
   AND a.property_id=u.property_id AND DATE_FORMAT(a.work_date,'%Y-%m')=?
 WHERE u.property_id=? AND u.status='active'
 GROUP BY u.id,u.full_name,p.employee_no,p.ordinary_rate,p.overtime_rate
 ORDER BY u.full_name");
$stmt->bind_param('si',$month,$propertyId);$stmt->execute();$result=$stmt->get_result();
while($row=$result->fetch_assoc()){$rows[]=$row;}$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll Integration | CPMS</title><style>body{background:#eef3f9;color:#10213d;font:15px Arial;margin:0}
main{max-width:1050px;margin:auto;padding:24px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px}
table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}
.table{overflow:auto}.btn{display:inline-block;background:#17457f;color:#fff;padding:11px 15px;border-radius:8px;text-decoration:none;font-weight:bold}</style>
</head><body><main><section class="card"><h1>Payroll Integration Foundation</h1>
<p>Bulan: <strong><?=htmlspecialchars($month)?></strong></p>
<p>Data ini ialah ringkasan kehadiran untuk integrasi payroll. Pengiraan gaji rasmi masih perlu disahkan oleh pengurusan/HR.</p>
<a class="btn" href="attendance_timesheet_export.php?property_id=<?=$propertyId?>&month=<?=urlencode($month)?>">Export Attendance CSV</a></section>
<section class="card table"><table><tr><th>Employee No.</th><th>Nama</th><th>Jam Kerja</th><th>OT</th><th>Lewat</th><th>Ordinary Rate</th><th>OT Rate</th></tr>
<?php foreach($rows as $r):?><tr><td><?=htmlspecialchars((string)($r['employee_no']??'-'))?></td><td><?=htmlspecialchars($r['full_name'])?></td>
<td><?=number_format((int)$r['worked_minutes']/60,2)?></td><td><?=number_format((int)$r['overtime_minutes']/60,2)?></td>
<td><?=(int)$r['late_minutes']?> min</td><td>RM <?=number_format((float)($r['ordinary_rate']??0),2)?></td><td>RM <?=number_format((float)($r['overtime_rate']??0),2)?></td></tr><?php endforeach;?></table></section>
</main></body></html>
