<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__.'/cpms/includes/permission_engine.php';
$actor=(int)($_SESSION['cpms_user_id']??0);$propertyId=(int)($_SESSION['cpms_property_id']??0);
$role=(string)($_SESSION['cpms_user_role']??'');$isOwner=$role==='system_owner'||isset($_SESSION['system_owner_id']);
if(!$isOwner&&!cpmsCan('attendance.approval.delegate',$conn)){http_response_code(403);exit('Akses ditolak.');}
$properties=[];if($isOwner){$result=$conn->query("SELECT id,property_name FROM cpms_properties ORDER BY property_name");
while($result&&($row=$result->fetch_assoc())){$properties[]=$row;}$propertyId=(int)($_REQUEST['property_id']??($propertyId>0?$propertyId:($properties[0]['id']??0)));}
if($propertyId<1){http_response_code(422);exit('Property tidak sah.');}
if(empty($_SESSION['cpms_delegation_csrf'])){$_SESSION['cpms_delegation_csrf']=bin2hex(random_bytes(24));}
$message='';$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 if(!hash_equals((string)$_SESSION['cpms_delegation_csrf'],(string)($_POST['csrf']??''))){$error='Token keselamatan tidak sah.';}
 elseif(isset($_POST['cancel_id'])){$id=(int)$_POST['cancel_id'];$stmt=$conn->prepare("UPDATE cpms_attendance_delegations SET delegation_status='Cancelled' WHERE id=? AND property_id=?");
  $stmt->bind_param('ii',$id,$propertyId);$stmt->execute();$stmt->close();$message='Delegation dibatalkan.';}
 else{$delegate=(int)($_POST['delegate_id']??0);$scope=(string)($_POST['scope']??'Timesheet');$from=(string)($_POST['valid_from']??'');$until=(string)($_POST['valid_until']??'');$reason=trim((string)($_POST['reason']??''));
  if($delegate<1||$from===''||$until===''||$until<$from||!in_array($scope,['Timesheet','All Attendance'],true)){$error='Maklumat delegation tidak sah.';}
  else{$stmt=$conn->prepare("INSERT INTO cpms_attendance_delegations(property_id,delegated_by_system_user_id,delegated_to_system_user_id,delegation_scope,valid_from,valid_until,reason) VALUES(?,?,?,?,?,?,?)");
   $stmt->bind_param('iiissss',$propertyId,$actor,$delegate,$scope,$from,$until,$reason);$stmt->execute();$stmt->close();$message='Delegation berjaya diwujudkan.';}
 }
}
$users=[];$stmt=$conn->prepare("SELECT DISTINCT u.id,u.full_name FROM system_users u JOIN user_roles ur ON ur.system_user_id=u.id AND ur.status='active' JOIN roles r ON r.id=ur.role_id AND r.role_code IN('property_admin','manager','clerk') WHERE u.property_id=? AND u.status='active' AND u.id<>? ORDER BY u.full_name");
$stmt->bind_param('ii',$propertyId,$actor);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc()){$users[]=$row;}$stmt->close();
$rows=[];$stmt=$conn->prepare("SELECT d.*,a.full_name delegated_by,b.full_name delegated_to FROM cpms_attendance_delegations d JOIN system_users a ON a.id=d.delegated_by_system_user_id JOIN system_users b ON b.id=d.delegated_to_system_user_id WHERE d.property_id=? ORDER BY d.created_at DESC LIMIT 100");
$stmt->bind_param('i',$propertyId);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc()){$rows[]=$row;}$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Attendance Delegation | CPMS</title>
<style>body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}main{max-width:1000px;margin:auto;padding:22px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px}
input,select,textarea{padding:10px;border:1px solid #ccd6e3;border-radius:8px;width:100%}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}button{background:#17457f;color:#fff;border:0;padding:10px 14px;border-radius:8px;font-weight:bold}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.table{overflow:auto}.ok{color:#07852f}.err{color:#b42318}@media(max-width:650px){.grid{grid-template-columns:1fr}}</style></head>
<body><main><section class="card"><h1>Delegated Attendance Approval</h1><?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=htmlspecialchars($error)?></p><?php endif;?>
<?php if($isOwner):?><form><select name="property_id" onchange="this.form.submit()"><?php foreach($properties as $p):?><option value="<?=(int)$p['id']?>"<?=(int)$p['id']===$propertyId?' selected':''?>><?=htmlspecialchars($p['property_name'])?></option><?php endforeach;?></select></form><?php endif;?></section>
<section class="card"><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_delegation_csrf'])?>"><input type="hidden" name="property_id" value="<?=$propertyId?>"><div class="grid">
<label>Wakil<select name="delegate_id" required><option value="">-- Pilih --</option><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>"><?=htmlspecialchars($u['full_name'])?></option><?php endforeach;?></select></label>
<label>Skop<select name="scope"><option>Timesheet</option><option>All Attendance</option></select></label><label>Daripada<input type="date" name="valid_from" required></label>
<label>Sehingga<input type="date" name="valid_until" required></label><label>Sebab<textarea name="reason"></textarea></label></div><p><button>Cipta Delegation</button></p></form></section>
<section class="card table"><table><tr><th>Wakil</th><th>Skop</th><th>Tempoh</th><th>Status</th><th></th></tr><?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['delegated_to'])?></td><td><?=htmlspecialchars($r['delegation_scope'])?></td><td><?=htmlspecialchars($r['valid_from'].' – '.$r['valid_until'])?></td><td><?=htmlspecialchars($r['delegation_status'])?></td>
<td><?php if($r['delegation_status']==='Active'):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_delegation_csrf'])?>"><input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="cancel_id" value="<?=(int)$r['id']?>"><button>Batalkan</button></form><?php endif;?></td></tr><?php endforeach;?></table></section></main></body></html>
