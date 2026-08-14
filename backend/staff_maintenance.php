<?php
declare(strict_types=1);
session_start();date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__.'/db.php';require_once __DIR__.'/staff_pwa_bootstrap.php';
require_once __DIR__.'/cpms/includes/permission_engine.php';
require_once __DIR__.'/cpms/includes/preventive_maintenance_service.php';
if(empty($_SESSION['staff_id'])){header('Location: cpms/login.php');exit();}
cpmsRequire('maintenance.view',$conn);
$userId=(int)($_SESSION['cpms_user_id']??0);
$propertyId=(int)($_SESSION['cpms_property_id']??$_SESSION['staff_property_id']??0);
if($userId<1||$propertyId<1){http_response_code(403);exit('Unified Staff account context is required.');}
$stmt=$conn->prepare("SELECT s.*,CASE WHEN s.status='active' AND s.next_due_date<CURDATE() THEN 'Overdue' WHEN s.status='active' AND s.next_due_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) THEN 'Due Soon' ELSE s.status END AS due_status FROM cpms_pm_schedules s WHERE s.property_id=? AND s.assigned_system_user_id=? AND s.status='active' ORDER BY s.next_due_date,s.id DESC");
$schedules=[];if($stmt){$stmt->bind_param('ii',$propertyId,$userId);$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$schedules[]=$row;$stmt->close();}
$branding=cpmsStaffPwaBranding($conn);
function staffPmE(?string $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!DOCTYPE html><html lang="ms"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance Saya | CPMS Staff</title><link rel="stylesheet" href="css/pms.css?v=5">
<style>.pm-wrap{max-width:1050px;margin:auto;padding:22px}.pm-head,.pm-card{background:#fff;border:1px solid #e5ded7;border-radius:14px;padding:18px;margin-bottom:14px}.pm-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.pm-card{display:block;text-decoration:none;color:inherit}.pm-card h2{margin-top:0;color:#4a2b20}.pm-meta{color:#735e55}.pm-badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:800}.pm-overdue{background:#fee2e2;color:#991b1b}.pm-due{background:#fef3c7;color:#92400e}.pm-btn{display:inline-block;background:#4a2b20;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:800}@media(max-width:700px){.pm-wrap{padding:14px}.pm-grid{grid-template-columns:1fr}}</style>
<?php echo cpmsStaffPwaHead($branding);echo cpmsStaffPwaStyle($branding);?>    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head><body class="pms-body"><main class="pm-wrap"><a class="pm-btn" href="staff_dashboard.php">← Dashboard</a>
<section class="pm-head"><h1>Preventive Maintenance Saya</h1><p>Schedule maintenance yang ditugaskan kepada anda.</p></section><section class="pm-grid">
<?php if(!$schedules):?><article class="pm-card">Tiada maintenance aktif ditugaskan kepada anda.</article><?php endif;?>
<?php foreach($schedules as $s):?><a class="pm-card" href="staff_maintenance_view.php?id=<?php echo(int)$s['id'];?>"><span class="pm-badge <?php echo$s['due_status']==='Overdue'?'pm-overdue':($s['due_status']==='Due Soon'?'pm-due':'');?>"><?php echo staffPmE((string)$s['due_status']);?></span><h2><?php echo staffPmE((string)$s['schedule_name']);?></h2><strong><?php echo staffPmE((string)$s['asset_name']);?></strong><p class="pm-meta">Next Due: <?php echo staffPmE((string)$s['next_due_date']);?> · <?php echo staffPmE((string)$s['priority']);?></p></a><?php endforeach;?>
</section></main></body></html>
