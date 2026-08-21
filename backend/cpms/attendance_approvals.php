<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__.'/cpms/includes/permission_engine.php';
require_once __DIR__.'/cpms/includes/attendance_approval_service.php';
$actor=(int)($_SESSION['cpms_user_id']??0);$propertyId=(int)($_SESSION['cpms_property_id']??0);
$role=(string)($_SESSION['cpms_user_role']??'');$isOwner=$role==='system_owner'||isset($_SESSION['system_owner_id']);
$properties=[];if($isOwner){$result=$conn->query("SELECT id,property_name FROM cpms_properties ORDER BY property_name");while($result&&($row=$result->fetch_assoc())){$properties[]=$row;}
$propertyId=(int)($_REQUEST['property_id']??($propertyId>0?$propertyId:($properties[0]['id']??0)));}
$month=(string)($_REQUEST['month']??date('Y-m'));$access=cpmsAttendanceApprovalAccess($conn,$propertyId,$actor,$isOwner);
if(!$access['allowed']){http_response_code(403);exit('Akaun ini tidak mempunyai kuasa approval attendance.');}
if(empty($_SESSION['cpms_approval_csrf'])){$_SESSION['cpms_approval_csrf']=bin2hex(random_bytes(24));}
$message='';$old='Draft';$stmt=$conn->prepare("SELECT approval_status FROM cpms_attendance_monthly_approvals WHERE property_id=? AND attendance_month=? LIMIT 1");
$stmt->bind_param('is',$propertyId,$month);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();if($row){$old=$row['approval_status'];}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&hash_equals((string)$_SESSION['cpms_approval_csrf'],(string)($_POST['csrf']??''))){
 $new=(string)($_POST['approval_status']??'');$notes=trim((string)($_POST['notes']??''));
 if(in_array($new,['Draft','Approved','Locked'],true)){$approvedAt=$new==='Draft'?null:date('Y-m-d H:i:s');
  $stmt=$conn->prepare("INSERT INTO cpms_attendance_monthly_approvals(property_id,attendance_month,approval_status,notes,approved_by_system_user_id,approved_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE approval_status=VALUES(approval_status),notes=VALUES(notes),approved_by_system_user_id=VALUES(approved_by_system_user_id),approved_at=VALUES(approved_at)");
  $stmt->bind_param('isssis',$propertyId,$month,$new,$notes,$actor,$approvedAt);$stmt->execute();$stmt->close();
  cpmsAttendanceApprovalAudit($conn,$propertyId,$month,$old,$new,$actor,$access['delegation_id'],$notes);$old=$new;$message='Status timesheet berjaya dikemas kini.';
 }}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Attendance Approval | CPMS</title>
<style>body{background:#eef3f9;color:#10213d;font:16px Arial;padding:22px}.card{max-width:800px;margin:0 auto 16px;background:#fff;border-radius:16px;padding:24px}input,select,textarea{width:100%;padding:11px;margin:6px 0 14px;border:1px solid #ccd6e3;border-radius:8px}button{background:#17457f;color:white;border:0;padding:12px 16px;border-radius:8px;font-weight:bold}.ok{color:#07852f}</style></head>
<body><section class="card"><h1>Monthly Attendance Approval</h1><?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<form method="get"><?php if($isOwner):?><select name="property_id"><?php foreach($properties as $p):?><option value="<?=(int)$p['id']?>"<?=(int)$p['id']===$propertyId?' selected':''?>><?=htmlspecialchars($p['property_name'])?></option><?php endforeach;?></select><?php endif;?><input type="month" name="month" value="<?=htmlspecialchars($month)?>"><button>Papar</button></form></section>
<section class="card"><p>Status semasa: <strong><?=htmlspecialchars($old)?></strong></p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_approval_csrf'])?>"><input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="month" value="<?=htmlspecialchars($month)?>">
<select name="approval_status"><option>Draft</option><option>Approved</option><option>Locked</option></select><textarea name="notes" placeholder="Catatan kelulusan"></textarea><button>Kemas Kini Status</button></form></section></body></html>
