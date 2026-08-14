<?php
declare(strict_types=1);
require_once __DIR__.'/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__.'/db.php';
require_once __DIR__.'/cpms/includes/resident_portal_service.php';
$resident=cpmsResidentRequire($conn);
$pid=(int)$resident['property_id'];
$rid=(int)$resident['id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $s=$conn->prepare(
        'UPDATE cpms_resident_notifications SET is_read=1,read_at=NOW()
         WHERE property_id=? AND resident_id=? AND is_read=0'
    );
    $s->bind_param('ii',$pid,$rid);
    $s->execute();
    $s->close();
    header('Location: resident_notifications.php');
    exit;
}
$s=$conn->prepare(
    'SELECT * FROM cpms_resident_notifications
     WHERE property_id=? AND resident_id=?
     ORDER BY created_at DESC LIMIT 200'
);
$s->bind_param('ii',$pid,$rid);
$s->execute();
$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();
function rnEsc($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notifikasi Resident</title><link rel="stylesheet" href="resident_portal.css?v=353">    <link rel="stylesheet" href="assets/genesis/resident-genesis.css?v=4.0.0">
</head><body><header class="rp-head"><div class="rp-wrap"><h1>Notifikasi</h1><p>Makluman tempahan dan operasi property</p></div></header><nav class="rp-nav"><a href="resident_dashboard.php">Dashboard</a><a href="facility_booking.php">Tempah Fasiliti</a><a href="facility_bookings.php">Tempahan Saya</a><a href="resident_profile.php">Profil</a></nav><main class="rp-main rp-wrap"><form method="post"><button class="rp-btn">Tandakan Semua Dibaca</button></form><section class="rp-list"><?php if(!$rows):?><div class="rp-card">Tiada notifikasi.</div><?php endif;foreach($rows as $x):?><article class="rp-card" style="<?=$x['is_read']?'opacity:.72':''?>"><div class="rp-row"><strong><?=rnEsc($x['title'])?></strong><span class="rp-badge"><?=$x['is_read']?'Dibaca':'Baharu'?></span></div><p><?=rnEsc($x['message'])?></p><small><?=rnEsc($x['created_at'])?></small><?php if(!empty($x['action_url'])):?><p><a href="<?=rnEsc($x['action_url'])?>">Buka rekod</a></p><?php endif;?></article><?php endforeach;?></section></main></body></html>
