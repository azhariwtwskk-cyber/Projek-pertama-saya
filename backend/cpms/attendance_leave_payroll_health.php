<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
$role=(string)($_SESSION['cpms_user_role']??'');
if($role!=='system_owner'&&!isset($_SESSION['system_owner_id'])){http_response_code(403);exit('System Owner access required.');}
$checks=[];
foreach(['cpms_leave_types','cpms_leave_requests','cpms_payroll_profiles','cpms_payroll_exports'] as $table){
 $safe=$conn->real_escape_string($table);
 $result=$conn->query("SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$safe'");
 $checks[$table]=$result&&(int)$result->fetch_assoc()['total']===1;
}
$result=$conn->query("SELECT COUNT(*) total FROM permissions WHERE permission_code IN
 ('attendance.dashboard.view','leave.request','leave.manage','payroll.profile.manage','payroll.integration.view')");
$checks['permissions 5/5']=$result&&(int)$result->fetch_assoc()['total']===5;
foreach(['attendance_dashboard.php','leave_requests.php','payroll_integration.php'] as $file){$checks[$file]=is_file(__DIR__.'/'.$file);}
$pass=!in_array(false,$checks,true);
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>v3.4.8 Health | CPMS</title><style>body{font:16px Arial;background:#eef3f9;color:#10213d;padding:24px}
main{max-width:850px;margin:auto}.card{background:#fff;border-radius:16px;padding:25px;margin-bottom:15px}.pass{color:#07852f}.fail{color:#b42318}
table{width:100%;border-collapse:collapse}td{padding:11px;border-bottom:1px solid #e2e8f0}</style></head><body><main>
<section class="card"><h1>CPMS v3.4.8 — Module Health</h1><h2 class="<?=$pass?'pass':'fail'?>"><?=$pass?'PASS':'FAIL'?></h2>
<p><a href="attendance_dashboard.php">Open Attendance Dashboard</a></p></section><section class="card"><table>
<?php foreach($checks as $name=>$ready):?><tr><td><?=htmlspecialchars($name)?></td><td class="<?=$ready?'pass':'fail'?>"><?=$ready?'Ready':'Missing'?></td></tr><?php endforeach;?>
</table></section></main></body></html>
