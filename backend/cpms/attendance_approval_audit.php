<?php
declare(strict_types=1);
session_start();require_once 'db.php';require_once __DIR__.'/cpms/includes/permission_engine.php';
$propertyId=(int)($_SESSION['cpms_property_id']??0);$role=(string)($_SESSION['cpms_user_role']??'');$isOwner=$role==='system_owner'||isset($_SESSION['system_owner_id']);
if(!$isOwner&&!cpmsCan('attendance.approval.audit',$conn)){http_response_code(403);exit('Akses ditolak.');}
$properties=[];if($isOwner){$result=$conn->query("SELECT id,property_name FROM cpms_properties ORDER BY property_name");while($result&&($row=$result->fetch_assoc())){$properties[]=$row;}$propertyId=(int)($_GET['property_id']??($propertyId>0?$propertyId:($properties[0]['id']??0)));}
$rows=[];$stmt=$conn->prepare("SELECT a.*,u.full_name FROM cpms_attendance_approval_audit a LEFT JOIN system_users u ON u.id=a.actor_system_user_id WHERE a.property_id=? ORDER BY a.created_at DESC LIMIT 300");
$stmt->bind_param('i',$propertyId);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc()){$rows[]=$row;}$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Attendance Approval Audit | CPMS</title>
<style>body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}main{max-width:1100px;margin:auto;padding:22px}.card{background:#fff;border-radius:16px;padding:22px;overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}select{padding:10px}</style></head><body><main><section class="card"><h1>Attendance Approval Audit</h1>
<?php if($isOwner):?><form><select name="property_id" onchange="this.form.submit()"><?php foreach($properties as $p):?><option value="<?=(int)$p['id']?>"<?=(int)$p['id']===$propertyId?' selected':''?>><?=htmlspecialchars($p['property_name'])?></option><?php endforeach;?></select></form><?php endif;?>
<table><tr><th>Masa</th><th>Bulan</th><th>Actor</th><th>Tindakan</th><th>Status</th><th>Catatan</th><th>IP</th></tr><?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['created_at'])?></td><td><?=htmlspecialchars($r['attendance_month'])?></td><td><?=htmlspecialchars((string)($r['full_name']??'-'))?></td><td><?=htmlspecialchars($r['action_type'])?></td><td><?=htmlspecialchars(($r['old_status']??'-').' → '.$r['new_status'])?></td><td><?=htmlspecialchars((string)$r['notes'])?></td><td><?=htmlspecialchars((string)$r['ip_address'])?></td></tr><?php endforeach;?></table></section></main></body></html>
