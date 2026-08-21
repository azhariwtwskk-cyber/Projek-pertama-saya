<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) ($_SESSION['cpms_property_id']
    ?? $_SESSION['property_admin_property_id'] ?? 0);
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
$canManage = $isOwner || cpmsCan('leave.manage', $conn);
$canRequest = $canManage || cpmsCan('leave.request', $conn);
$properties = [];
if ($isOwner) {
    $result = $conn->query(
        'SELECT id, property_name FROM cpms_properties ORDER BY property_name'
    );
    while ($result && ($row = $result->fetch_assoc())) {
        $properties[] = $row;
    }
    $propertyId = (int) ($_GET['property_id']
        ?? $_POST['property_id']
        ?? ($propertyId > 0 ? $propertyId : ($properties[0]['id'] ?? 0)));
}
if (!$canRequest || $propertyId < 1) {
    http_response_code(403);
    exit('Akses ditolak.');
}
if (empty($_SESSION['cpms_leave_csrf'])) {
    $_SESSION['cpms_leave_csrf'] = bin2hex(random_bytes(24));
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals((string) $_SESSION['cpms_leave_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Token keselamatan tidak sah.';
    } elseif (isset($_POST['review_id']) && $canManage) {
        $id = (int) $_POST['review_id'];
        $status = (string) ($_POST['request_status'] ?? '');
        if (!in_array($status, ['Approved','Rejected','Cancelled'], true)) {
            $error = 'Status tidak sah.';
        } else {
            $stmt=$conn->prepare("UPDATE cpms_leave_requests SET request_status=?,
                reviewed_by_system_user_id=?,reviewed_at=NOW(),review_notes=?
                WHERE id=? AND property_id=?");
            $notes=trim((string)($_POST['review_notes']??''));
            $stmt->bind_param('sisii',$status,$userId,$notes,$id,$propertyId);
            $stmt->execute();$stmt->close();$message='Permohonan telah dikemas kini.';
        }
    } else {
        $typeId=(int)($_POST['leave_type_id']??0);
        $start=(string)($_POST['start_date']??'');
        $end=(string)($_POST['end_date']??'');
        $reason=trim((string)($_POST['reason']??''));
        $days=(strtotime($end)-strtotime($start))/86400+1;
        if ($typeId<1 || $start==='' || $end==='' || $days<1 || $days>366) {
            $error='Maklumat cuti tidak sah.';
        } else {
            $stmt=$conn->prepare("INSERT INTO cpms_leave_requests
                (property_id,system_user_id,leave_type_id,start_date,end_date,total_days,reason)
                VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('iiissds',$propertyId,$userId,$typeId,$start,$end,$days,$reason);
            $stmt->execute();$stmt->close();$message='Permohonan cuti berjaya dihantar.';
        }
    }
}
$types=[];
$stmt=$conn->prepare("SELECT id,leave_name,paid_leave FROM cpms_leave_types
    WHERE property_id=? AND active=1 ORDER BY leave_name");
$stmt->bind_param('i',$propertyId);$stmt->execute();$result=$stmt->get_result();
while($row=$result->fetch_assoc()){$types[]=$row;}$stmt->close();
$requests=[];
$sql="SELECT lr.*,lt.leave_name,u.full_name FROM cpms_leave_requests lr
 JOIN cpms_leave_types lt ON lt.id=lr.leave_type_id
 JOIN system_users u ON u.id=lr.system_user_id WHERE lr.property_id=?";
if(!$canManage){$sql.=" AND lr.system_user_id=?";}
$sql.=" ORDER BY lr.created_at DESC LIMIT 200";
$stmt=$conn->prepare($sql);
if($canManage){$stmt->bind_param('i',$propertyId);}else{$stmt->bind_param('ii',$propertyId,$userId);}
$stmt->execute();$result=$stmt->get_result();
while($row=$result->fetch_assoc()){$requests[]=$row;}$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leave Management | CPMS</title><style>
*{box-sizing:border-box}body{background:#eef3f9;color:#10213d;font:15px Arial;margin:0}
main{max-width:1050px;margin:auto;padding:22px}.card{background:#fff;border-radius:15px;padding:22px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}input,select,textarea{width:100%;padding:11px;border:1px solid #ccd6e3;border-radius:8px}
label{font-weight:bold}button{background:#17457f;color:#fff;border:0;padding:11px 15px;border-radius:8px;font-weight:bold}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.ok{color:#087b31}.err{color:#b42318}
@media(max-width:700px){.grid{grid-template-columns:1fr}.table{overflow:auto}}
</style></head><body><main>
<section class="card"><h1><?= $canManage?'Leave Management':'Permohonan Cuti' ?></h1>
<?php if($isOwner):?><form method="get"><label>Property<select name="property_id" onchange="this.form.submit()">
<?php foreach($properties as $property):?><option value="<?=(int)$property['id']?>"<?=(int)$property['id']===$propertyId?' selected':''?>><?=htmlspecialchars((string)$property['property_name'])?></option><?php endforeach;?>
</select></label></form><hr><?php endif;?>
<?php if($message):?><p class="ok"><?=htmlspecialchars($message)?></p><?php endif;?>
<?php if($error):?><p class="err"><?=htmlspecialchars($error)?></p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_leave_csrf'])?>">
<input type="hidden" name="property_id" value="<?=$propertyId?>">
<div class="grid"><label>Jenis cuti<select name="leave_type_id" required><option value="">-- Pilih --</option>
<?php foreach($types as $t):?><option value="<?=(int)$t['id']?>"><?=htmlspecialchars($t['leave_name'])?></option><?php endforeach;?></select></label>
<label>Sebab<textarea name="reason"></textarea></label><label>Tarikh mula<input type="date" name="start_date" required></label>
<label>Tarikh akhir<input type="date" name="end_date" required></label></div><p><button>Hantar Permohonan</button></p></form></section>
<section class="card table"><h2>Rekod Cuti</h2><table><tr><th>Nama</th><th>Jenis</th><th>Tarikh</th><th>Hari</th><th>Status</th><?php if($canManage):?><th>Tindakan</th><?php endif;?></tr>
<?php foreach($requests as $r):?><tr><td><?=htmlspecialchars($r['full_name'])?></td><td><?=htmlspecialchars($r['leave_name'])?></td>
<td><?=htmlspecialchars($r['start_date'].' – '.$r['end_date'])?></td><td><?=number_format((float)$r['total_days'],2)?></td><td><?=htmlspecialchars($r['request_status'])?></td>
<?php if($canManage):?><td><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['cpms_leave_csrf'])?>">
<input type="hidden" name="review_id" value="<?=(int)$r['id']?>"><select name="request_status"><option>Approved</option><option>Rejected</option><option>Cancelled</option></select>
<input name="review_notes" placeholder="Catatan"><button>Kemas Kini</button></form></td><?php endif;?></tr><?php endforeach;?></table></section>
</main></body></html>
