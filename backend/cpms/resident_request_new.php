<?php
declare(strict_types=1);
require_once __DIR__.'/cpms/includes/resident_session.php'; cpmsResidentSessionStart(); require_once __DIR__.'/db.php'; require_once __DIR__.'/cpms/includes/resident_portal_service.php';
$r=cpmsResidentRequire($conn);$ok=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 $type=trim((string)($_POST['request_type']??''));$subject=trim((string)($_POST['subject']??''));$description=trim((string)($_POST['description']??''));
 if($type!==''&&$subject!==''&&$description!==''){$ref=cpmsResidentReference((int)$r['property_id']);$s=$conn->prepare("INSERT INTO cpms_resident_service_requests(property_id,resident_id,request_reference,request_type,subject,description) VALUES(?,?,?,?,?,?)");$pid=(int)$r['property_id'];$rid=(int)$r['id'];$s->bind_param('iissss',$pid,$rid,$ref,$type,$subject,$description);$s->execute();$s->close();$ok=true;}
}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Permohonan Baharu</title><link rel="stylesheet" href="resident_portal.css?v=350">    <link rel="stylesheet" href="assets/genesis/resident-genesis.css?v=4.0.0">
</head><body><header class="rp-head"><div class="rp-wrap"><h1>Permohonan Baharu</h1><p>Hantar permintaan kepada pengurusan</p></div></header><main class="rp-main rp-wrap"><?php if($ok):?><div class="rp-alert">Permohonan berjaya dihantar. <a href="resident_requests.php">Lihat rekod</a></div><?php endif;?><form class="rp-card" method="post"><label>Jenis</label><select class="rp-field" name="request_type" required><option value="">-- Pilih --</option><option>Access Card</option><option>Move In / Move Out</option><option>Common Facility</option><option>Maintenance Enquiry</option><option>Other</option></select><label>Subjek</label><input class="rp-field" name="subject" maxlength="180" required><label>Keterangan</label><textarea class="rp-field" name="description" rows="6" required></textarea><button class="rp-btn">Hantar Permohonan</button> <a href="resident_dashboard.php">Batal</a></form></main></body></html>
