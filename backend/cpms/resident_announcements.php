<?php
declare(strict_types=1);
require_once __DIR__.'/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__.'/db.php';
require_once __DIR__.'/cpms/includes/resident_portal_service.php';
$resident=cpmsResidentRequire($conn);$propertyId=(int)$resident['property_id'];$residentId=(int)$resident['id'];
if(empty($_SESSION['resident_notice_csrf']))$_SESSION['resident_notice_csrf']=bin2hex(random_bytes(24));

if($_SERVER['REQUEST_METHOD']==='POST'){
    $token=(string)($_POST['csrf_token']??'');
    if(!hash_equals((string)$_SESSION['resident_notice_csrf'],$token)){http_response_code(403);exit('Permintaan tidak sah.');}
    $announcementId=(int)($_POST['announcement_id']??0);
    $s=$conn->prepare("INSERT INTO cpms_resident_announcement_reads (property_id,announcement_id,resident_id,viewed_at,confirmed_at) SELECT ?,a.id,?,NOW(),NOW() FROM cpms_resident_announcements a WHERE a.id=? AND a.property_id=? AND a.status='Published' ON DUPLICATE KEY UPDATE confirmed_at=NOW()");
    $s->bind_param('iiii',$propertyId,$residentId,$announcementId,$propertyId);$s->execute();$s->close();
    header('Location: resident_announcements.php?confirmed=1');exit;
}
$s=$conn->prepare("SELECT a.*,ar.viewed_at,ar.confirmed_at FROM cpms_resident_announcements a LEFT JOIN cpms_resident_announcement_reads ar ON ar.announcement_id=a.id AND ar.resident_id=? WHERE a.property_id=? AND a.status='Published' AND a.publish_from<=NOW() AND (a.publish_until IS NULL OR a.publish_until>=NOW()) ORDER BY CASE WHEN a.notice_type='Emergency' THEN 0 ELSE 1 END,a.publish_from DESC");
$s->bind_param('ii',$residentId,$propertyId);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
foreach($rows as $row){if(empty($row['viewed_at'])){$id=(int)$row['id'];$s=$conn->prepare('INSERT IGNORE INTO cpms_resident_announcement_reads (property_id,announcement_id,resident_id,viewed_at) VALUES (?,?,?,NOW())');$s->bind_param('iii',$propertyId,$id,$residentId);$s->execute();$s->close();}}
function residentNoticeEscape($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notis Resident | CPMS</title><link rel="stylesheet" href="resident_portal.css?v=354"><style>.rn-emergency{border:2px solid #dc2626!important;background:#fff5f5!important}.rn-label{display:inline-block;padding:5px 10px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:800}.rn-emergency .rn-label{background:#dc2626;color:#fff}.rn-confirmed{color:#15803d;font-weight:800}.rn-alert{padding:12px;border-radius:10px;background:#dcfce7;color:#166534;margin-bottom:15px}</style>    <link rel="stylesheet" href="assets/genesis/resident-genesis.css?v=4.0.0">
</head><body>
<header class="rp-head"><div class="rp-wrap"><small>CPMS RESIDENT PORTAL</small><h1>Pengumuman & Notis Kecemasan</h1><p>Makluman rasmi property anda</p></div></header>
<nav class="rp-nav"><a href="resident_dashboard.php">Dashboard</a><a href="resident_requests.php">Permohonan</a><a href="resident_announcements.php">Notis</a><a href="resident_notifications.php">Notifikasi</a><a href="resident_profile.php">Profil</a></nav>
<main class="rp-main rp-wrap"><?php if(isset($_GET['confirmed'])):?><div class="rn-alert">Pengesahan dibaca telah direkodkan.</div><?php endif;?><section class="rp-list"><?php if(!$rows):?><div class="rp-card">Tiada pengumuman aktif.</div><?php endif;?><?php foreach($rows as $row):?><article class="rp-card <?php echo $row['notice_type']==='Emergency'?'rn-emergency':'';?>"><div class="rp-row"><div><span class="rn-label"><?php echo residentNoticeEscape($row['notice_type']);?></span> <span class="rn-label"><?php echo residentNoticeEscape($row['priority']);?></span><h3><?php echo residentNoticeEscape($row['title']);?></h3></div><span class="rp-badge"><?php echo residentNoticeEscape(date('d/m/Y',strtotime($row['publish_from'])));?></span></div><p><?php echo nl2br(residentNoticeEscape($row['message']));?></p><?php if((int)$row['requires_confirmation']===1):?><?php if(!empty($row['confirmed_at'])):?><p class="rn-confirmed">&#10003; Telah disahkan dibaca</p><?php else:?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo residentNoticeEscape($_SESSION['resident_notice_csrf']);?>"><input type="hidden" name="announcement_id" value="<?php echo (int)$row['id'];?>"><button class="rp-btn">Sahkan Dibaca</button></form><?php endif;?><?php endif;?></article><?php endforeach;?></section></main>
<footer class="rp-footer"><a href="resident_logout.php">Log Keluar Resident</a> · CPMS Resident Portal</footer></body></html>
