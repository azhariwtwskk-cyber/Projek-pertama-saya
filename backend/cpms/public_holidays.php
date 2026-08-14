<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
$propertyId=(int)($_SESSION['cpms_property_id']??0);
$userId=(int)($_SESSION['cpms_user_id']??0);
$role=(string)($_SESSION['cpms_user_role']??'');
$isOwner=$role==='system_owner'||isset($_SESSION['system_owner_id']);
if(!$isOwner&&!cpmsCan('attendance.holiday.manage',$conn)){http_response_code(403);exit('Akses ditolak.');}
$properties=[];
if($isOwner){
 $result=$conn->query("SELECT id,property_name FROM cpms_properties ORDER BY property_name");
 while($result&&($row=$result->fetch_assoc())){$properties[]=$row;}
 $propertyId=(int)($_REQUEST['property_id']??($propertyId>0?$propertyId:($properties[0]['id']??0)));
}
$year=(int)($_REQUEST['holiday_year']??date('Y'));
if($propertyId<1||$year<2020||$year>2100){http_response_code(422);exit('Maklumat tidak sah.');}
if(empty($_SESSION['cpms_holiday_csrf'])){$_SESSION['cpms_holiday_csrf']=bin2hex(random_bytes(24));}
$message='';$error='';
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 if(!hash_equals((string)$_SESSION['cpms_holiday_csrf'],(string)($_POST['csrf']??''))){$error='Token keselamatan tidak sah.';}
 elseif(isset($_POST['delete_id'])){
  $id=(int)$_POST['delete_id'];$stmt=$conn->prepare("DELETE FROM cpms_public_holidays WHERE id=? AND property_id=?");
  $stmt->bind_param('ii',$id,$propertyId);$stmt->execute();$stmt->close();$message='Cuti umum dipadam.';
 }else{
  $date=(string)($_POST['holiday_date']??'');$name=trim((string)($_POST['holiday_name']??''));
  $scope=(string)($_POST['holiday_scope']??'Property');$paid=isset($_POST['paid_holiday'])?1:0;
  if($date===''||$name===''||!in_array($scope,['Federal','State','Property'],true)){$error='Maklumat cuti umum tidak sah.';}
  else{$stmt=$conn->prepare("INSERT INTO cpms_public_holidays
   (property_id,holiday_date,holiday_name,holiday_scope,paid_holiday,created_by_system_user_id)
   VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE holiday_scope=VALUES(holiday_scope),
   paid_holiday=VALUES(paid_holiday),active=1");
   $stmt->bind_param('isssii',$propertyId,$date,$name,$scope,$paid,$userId);$stmt->execute();$stmt->close();$message='Cuti umum berjaya disimpan.';}
 }
}
$holidays=[];$stmt=$conn->prepare("SELECT * FROM cpms_public_holidays WHERE property_id=? AND YEAR(holiday_date)=? AND active=1 ORDER BY holiday_date");
$stmt->bind_param('ii',$propertyId,$year);$stmt->execute();$result=$stmt->get_result();
while($row=$result->fetch_assoc()){$holidays[]=$row;}$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Public Holidays | CPMS</title><style>*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:900px;margin:auto;padding:22px}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}input,select{width:100%;padding:10px;border:1px solid #ccd6e3;border-radius:8px}
button{background:#17457f;color:#fff;border:0;padding:11px 15px;border-radius:8px;font-weight:bold}table{width:100%;border-collapse:collapse}
th,td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}.ok{color:#07852f}.err{color:#b42318}@media(max-width:650px){.grid{grid-template-columns:1fr}}</style>
</head><body><main><section class="card"><h1>Public Holiday Calendar</h1>
<form><?php if($isOwner):?><label>Property <select name="property_id">
<?php foreach($properties as $p):?><option value="<?=(int)$p['id']?>"<?=(int)$p['id']===$propertyId?' selected':''?>><?=htmlspecialchars($p['property_name'])?></option><?php endforeach;?>
</select></label><?php else:?><input type="hidden" name="property_id" value="<?=$propertyId?>"><?php endif;?>
<label>Tahun <input type="number" name="holiday_year" value="<?=$year?>"></label><button>Papar</button></form>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=htmlspecialchars($error)?></p><?php endif;?></section>
<section class="card"><h2>Tambah Cuti Umum</h2><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_holiday_csrf'])?>">
<input type="hidden" name="property_id" value="<?=$propertyId?>"><input type="hidden" name="holiday_year" value="<?=$year?>"><div class="grid">
<label>Tarikh<input type="date" name="holiday_date" required></label><label>Nama<input name="holiday_name" required></label>
<label>Skop<select name="holiday_scope"><option>Federal</option><option>State</option><option>Property</option></select></label>
<label><input style="width:auto" type="checkbox" name="paid_holiday" checked> Cuti berbayar</label></div><p><button>Simpan</button></p></form></section>
<section class="card"><table><tr><th>Tarikh</th><th>Nama</th><th>Skop</th><th>Berbayar</th><th></th></tr>
<?php foreach($holidays as $h):?><tr><td><?=htmlspecialchars($h['holiday_date'])?></td><td><?=htmlspecialchars($h['holiday_name'])?></td>
<td><?=htmlspecialchars($h['holiday_scope'])?></td><td><?=(int)$h['paid_holiday']?'Ya':'Tidak'?></td><td><form method="post">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_holiday_csrf'])?>"><input type="hidden" name="property_id" value="<?=$propertyId?>">
<input type="hidden" name="holiday_year" value="<?=$year?>"><input type="hidden" name="delete_id" value="<?=(int)$h['id']?>"><button>Padam</button></form></td></tr><?php endforeach;?></table></section>
</main></body></html>
