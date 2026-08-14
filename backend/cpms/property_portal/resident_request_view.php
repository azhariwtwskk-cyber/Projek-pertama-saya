<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('resident.request.manage');
require_once dirname(__DIR__) . '/cpms/includes/resident_request_workflow_service.php';
$id=(int)($_GET['id']??$_POST['id']??0);
$actorId=(int)($_SESSION['cpms_user_id']??0);
$message='';$error='';
$load=function()use($conn,$id,$currentPropertyId):?array{
 $s=$conn->prepare("SELECT sr.*,r.full_name,r.phone,r.email,r.block_name,r.unit_no,r.resident_type,l.complaint_reference,l.work_order_id,w.work_order_reference,w.status work_order_status FROM cpms_resident_service_requests sr JOIN cpms_residents r ON r.id=sr.resident_id LEFT JOIN cpms_resident_request_links l ON l.service_request_id=sr.id LEFT JOIN work_orders w ON w.id=l.work_order_id WHERE sr.id=? AND sr.property_id=? LIMIT 1");
 $s->bind_param('ii',$id,$currentPropertyId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return $row?:null;
};
$request=$load();if(!$request){http_response_code(404);exit('Permohonan tidak dijumpai.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $action=(string)($_POST['action']??'');$conn->begin_transaction();
  if($action==='complaint'&&empty($request['complaint_reference'])){
   $ref=cpmsResidentWorkflowReference($conn,'complaints','complaint_id','RES');
   $location=trim((string)(($request['block_name']??'').' Unit '.($request['unit_no']??'')));
   $priority=(string)$request['priority'];$status='Pending';$image='';
   $s=$conn->prepare("INSERT INTO complaints(complaint_id,property_id,name,phone,email,resident_status,unit_no,block,location,category,subject,description,priority,image,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
   $s->bind_param('sisssssssssssss',$ref,$currentPropertyId,$request['full_name'],$request['phone'],$request['email'],$request['resident_type'],$request['unit_no'],$request['block_name'],$location,$request['request_type'],$request['subject'],$request['description'],$priority,$image,$status);$s->execute();$s->close();
   $s=$conn->prepare("INSERT INTO cpms_resident_request_links(property_id,service_request_id,complaint_reference,converted_by_system_user_id,converted_at) VALUES(?,?,?,NULLIF(?,0),NOW()) ON DUPLICATE KEY UPDATE complaint_reference=VALUES(complaint_reference),converted_by_system_user_id=VALUES(converted_by_system_user_id),converted_at=NOW()");
   $s->bind_param('iisi',$currentPropertyId,$id,$ref,$actorId);$s->execute();$s->close();
   $s=$conn->prepare("UPDATE cpms_resident_service_requests SET status='In Review' WHERE id=? AND property_id=?");$s->bind_param('ii',$id,$currentPropertyId);$s->execute();$s->close();
   cpmsResidentWorkflowUpdate($conn,$currentPropertyId,$id,'Complaint',$request['status'],'In Review','Permohonan telah didaftarkan sebagai Complaint '.$ref.'.',$actorId);$message='Complaint berjaya dicipta.';
  }elseif($action==='work_order'&&!empty($request['complaint_reference'])&&empty($request['work_order_id'])){
   $ref=cpmsResidentWorkflowReference($conn,'work_orders','work_order_reference','WO');
   $status='Open';$due=date('Y-m-d',strtotime('+7 days'));$createdBy=(string)($_SESSION['property_admin_name']??'Property Management');$location=trim((string)(($request['block_name']??'').' Unit '.($request['unit_no']??'')));
   $s=$conn->prepare("INSERT INTO work_orders(property_id,work_order_reference,complaint_id,title,description,category,priority,block_location,specific_location,scheduled_date,due_date,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
   $today=date('Y-m-d');$s->bind_param('issssssssssss',$currentPropertyId,$ref,$request['complaint_reference'],$request['subject'],$request['description'],$request['request_type'],$request['priority'],$request['block_name'],$location,$today,$due,$status,$createdBy);$s->execute();$woId=(int)$conn->insert_id;$s->close();
   $s=$conn->prepare("UPDATE cpms_resident_request_links SET work_order_id=? WHERE service_request_id=? AND property_id=?");$s->bind_param('iii',$woId,$id,$currentPropertyId);$s->execute();$s->close();
   $s=$conn->prepare("UPDATE cpms_resident_service_requests SET status='Action Assigned' WHERE id=? AND property_id=?");$s->bind_param('ii',$id,$currentPropertyId);$s->execute();$s->close();
   cpmsResidentWorkflowUpdate($conn,$currentPropertyId,$id,'Work Order',$request['status'],'Action Assigned','Work Order '.$ref.' telah diwujudkan untuk tindakan.',$actorId);$message='Work Order berjaya dicipta.';
  }elseif($action==='status'){
   $new=(string)($_POST['status']??'');$note=trim((string)($_POST['note']??''));$allowed=['Submitted','In Review','Action Assigned','In Progress','Resolved','Closed','Cancelled'];if(!in_array($new,$allowed,true)){throw new RuntimeException('Status tidak sah.');}
   $s=$conn->prepare("UPDATE cpms_resident_service_requests SET status=? WHERE id=? AND property_id=?");$s->bind_param('sii',$new,$id,$currentPropertyId);$s->execute();$s->close();
   cpmsResidentWorkflowUpdate($conn,$currentPropertyId,$id,'Status',$request['status'],$new,$note!==''?$note:'Status dikemas kini kepada '.$new.'.',$actorId);$message='Status berjaya dikemas kini.';
  }else{throw new RuntimeException('Tindakan tidak tersedia atau telah dibuat.');}
  $conn->commit();$request=$load();
 }catch(Throwable $e){$conn->rollback();$error=$e->getMessage();}
}
$updates=[];$s=$conn->prepare("SELECT * FROM cpms_resident_request_updates WHERE property_id=? AND service_request_id=? ORDER BY id DESC");$s->bind_param('ii',$currentPropertyId,$id);$s->execute();$updates=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
function rvEsc($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resident Request | CPMS</title><style>*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}main{max-width:950px;margin:auto;padding:22px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.box{background:#f4f7fb;padding:13px;border-radius:10px}.btn,button{border:0;background:#17457f;color:#fff;padding:11px 14px;border-radius:8px;font-weight:bold;text-decoration:none;cursor:pointer}select,input{padding:11px;border:1px solid #ccd6e3;border-radius:8px}.ok{color:green}.err{color:#b42318}@media(max-width:650px){.grid{grid-template-columns:1fr}}</style></head><body><main><p><a href="resident_requests.php">← Senarai Permohonan</a></p><section class="card"><h1><?=rvEsc($request['request_reference'])?></h1><?php if($message):?><p class="ok"><?=rvEsc($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=rvEsc($error)?></p><?php endif;?><div class="grid"><div class="box"><small>Resident</small><br><strong><?=rvEsc($request['full_name'])?></strong><br><?=rvEsc(($request['block_name']??'').' / '.($request['unit_no']??'-'))?></div><div class="box"><small>Status</small><br><strong><?=rvEsc($request['status'])?></strong></div><div class="box"><small>Complaint</small><br><strong><?=rvEsc($request['complaint_reference']??'-')?></strong></div><div class="box"><small>Work Order</small><br><strong><?=rvEsc($request['work_order_reference']??'-')?></strong></div></div><h3><?=rvEsc($request['subject'])?></h3><p><?=nl2br(rvEsc($request['description']))?></p></section>
<section class="card"><h2>Tindakan</h2><?php if(empty($request['complaint_reference'])):?><form method="post"><input type="hidden" name="id" value="<?=$id?>"><button name="action" value="complaint">Cipta Complaint</button></form><?php elseif(empty($request['work_order_id'])):?><form method="post"><input type="hidden" name="id" value="<?=$id?>"><button name="action" value="work_order">Cipta Work Order</button></form><?php endif;?><hr><form method="post"><input type="hidden" name="id" value="<?=$id?>"><select name="status"><?php foreach(['Submitted','In Review','Action Assigned','In Progress','Resolved','Closed','Cancelled'] as $st):?><option<?=$st===$request['status']?' selected':''?>><?=rvEsc($st)?></option><?php endforeach;?></select> <input name="note" placeholder="Catatan kepada resident"> <button name="action" value="status">Kemaskini</button></form></section>
<section class="card"><h2>Timeline</h2><?php if(!$updates):?><p>Belum ada kemas kini.</p><?php endif;foreach($updates as $u):?><div class="box"><strong><?=rvEsc($u['message'])?></strong><br><small><?=rvEsc($u['created_at'])?></small></div><br><?php endforeach;?></section></main></body></html>
