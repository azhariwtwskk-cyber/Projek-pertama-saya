<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
$userId=(int)($_SESSION['cpms_user_id']??0);$propertyId=(int)($_SESSION['cpms_property_id']??0);
$role=(string)($_SESSION['cpms_user_role']??'');$isOwner=$role==='system_owner'||isset($_SESSION['system_owner_id']);
$canManage=$isOwner||cpmsCan('attendance.exception.manage',$conn);
if(!$isOwner&&!cpmsCan('attendance.exception.view',$conn)){http_response_code(403);exit('Akses ditolak.');}
$properties=[];
if($isOwner){
 $result=$conn->query("SELECT id,property_name FROM cpms_properties ORDER BY property_name");
 while($result&&($row=$result->fetch_assoc())){$properties[]=$row;}
 $propertyId=(int)($_REQUEST['property_id']??($propertyId>0?$propertyId:($properties[0]['id']??0)));
}
$month=(string)($_REQUEST['month']??date('Y-m'));
if($propertyId<1||!preg_match('/^\d{4}-\d{2}$/',$month)){http_response_code(422);exit('Maklumat tidak sah.');}
if(empty($_SESSION['cpms_exception_csrf'])){$_SESSION['cpms_exception_csrf']=bin2hex(random_bytes(24));}
$message='';$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&$canManage){
 if(!hash_equals((string)$_SESSION['cpms_exception_csrf'],(string)($_POST['csrf']??''))){$error='Token keselamatan tidak sah.';}
 elseif(isset($_POST['sync'])){
  $stmt=$conn->prepare("INSERT IGNORE INTO cpms_attendance_exceptions
   (property_id,system_user_id,attendance_session_id,exception_date,exception_type,exception_minutes)
   SELECT property_id,system_user_id,id,work_date,'Late Arrival',late_minutes
   FROM cpms_attendance_sessions WHERE property_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? AND late_minutes>0");
  $stmt->bind_param('is',$propertyId,$month);$stmt->execute();$stmt->close();
  $stmt=$conn->prepare("INSERT IGNORE INTO cpms_attendance_exceptions
   (property_id,system_user_id,attendance_session_id,exception_date,exception_type,exception_minutes)
   SELECT property_id,system_user_id,id,work_date,'Missing Clock Out',0
   FROM cpms_attendance_sessions WHERE property_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? AND clock_out_at IS NULL");
  $stmt->bind_param('is',$propertyId,$month);$stmt->execute();$stmt->close();$message='Exception kehadiran telah diselaraskan.';
 }else{
  $id=(int)($_POST['exception_id']??0);$status=(string)($_POST['exception_status']??'');
  $notes=trim((string)($_POST['resolution_notes']??''));
  if(!in_array($status,['Resolved','Excused','Rejected'],true)){$error='Status tidak sah.';}
  else{$stmt=$conn->prepare("UPDATE cpms_attendance_exceptions SET exception_status=?,resolution_notes=?,
   resolved_by_system_user_id=?,resolved_at=NOW() WHERE id=? AND property_id=?");
   $stmt->bind_param('ssiii',$status,$notes,$userId,$id,$propertyId);$stmt->execute();$stmt->close();$message='Exception berjaya dikemas kini.';}
 }
}
$rows=[];$sql="SELECT e.*,u.full_name FROM cpms_attendance_exceptions e JOIN system_users u ON u.id=e.system_user_id
 WHERE e.property_id=? AND DATE_FORMAT(e.exception_date,'%Y-%m')=?";
if(!$canManage){$sql.=" AND e.system_user_id=?";}$sql.=" ORDER BY e.exception_date DESC,e.created_at DESC LIMIT 300";
$stmt=$conn->prepare($sql);if($canManage){$stmt->bind_param('is',$propertyId,$month);}else{$stmt->bind_param('isi',$propertyId,$month,$userId);}
$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc()){$rows[]=$row;}$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Exceptions | CPMS</title><style>body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1100px;margin:auto;padding:22px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px}
button{background:#17457f;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:bold}input,select{padding:9px;border:1px solid #ccd6e3;border-radius:8px}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.table{overflow:auto}.ok{color:#07852f}.err{color:#b42318}</style>
</head><body><main><section class="card"><h1>Attendance Exception Engine</h1>
<form><?php if($isOwner):?><select name="property_id">
<?php foreach($properties as $p):?><option value="<?=(int)$p['id']?>"<?=(int)$p['id']===$propertyId?' selected':''?>><?=htmlspecialchars($p['property_name'])?></option><?php endforeach;?>
</select><?php else:?><input type="hidden" name="property_id" value="<?=$propertyId?>"><?php endif;?>
<input type="month" name="month" value="<?=htmlspecialchars($month)?>"><button>Papar</button></form>
<?php if($canManage):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_exception_csrf'])?>">
<input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="month" value="<?=htmlspecialchars($month)?>">
<input type="hidden" name="sync" value="1"><p><button>Sync Late & Missing Clock Out</button></p></form><?php endif;?>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=htmlspecialchars($error)?></p><?php endif;?></section>
<section class="card table"><table><tr><th>Tarikh</th><th>Nama</th><th>Jenis</th><th>Minit</th><th>Status</th><?php if($canManage):?><th>Tindakan</th><?php endif;?></tr>
<?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['exception_date'])?></td><td><?=htmlspecialchars($r['full_name'])?></td>
<td><?=htmlspecialchars($r['exception_type'])?></td><td><?=(int)$r['exception_minutes']?></td><td><?=htmlspecialchars($r['exception_status'])?></td>
<?php if($canManage):?><td><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_exception_csrf'])?>">
<input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="month" value="<?=htmlspecialchars($month)?>">
<input type="hidden" name="exception_id" value="<?=(int)$r['id']?>"><select name="exception_status"><option>Resolved</option><option>Excused</option><option>Rejected</option></select>
<input name="resolution_notes" placeholder="Catatan"><button>Simpan</button></form></td><?php endif;?></tr><?php endforeach;?></table></section>
</main></body></html>
