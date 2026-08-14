<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__.'/db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';

if (!isset($_SESSION['security_guard_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: cpms/login.php'); exit();
}
if (isset($_SESSION['security_guard_id'])) {
    cpmsRequire('security.view', $conn);
}
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function dt(?string $v): string { $t=$v?strtotime($v):false; return $t?date('d/m/Y h:i A',$t):'-'; }
function duration(?string $a, ?string $b): string {
    if(!$a||!$b) return '-'; $m=max(0,(int)((strtotime($b)-strtotime($a))/60));
    return $m>=60 ? intdiv($m,60).' jam '.($m%60).' minit' : $m.' minit';
}
function locations(?string $v): array {
    $x=json_decode((string)$v,true); return is_array($x)?$x:array_filter(array_map('trim',explode(',',(string)$v)));
}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);exit('ID patrol tidak sah.');}

$sql='SELECT p.* FROM security_patrols p WHERE p.id=?';
if(isset($_SESSION['security_guard_id'])&&!isset($_SESSION['admin_id'])) $sql.=' AND p.guard_id=?';
$sql.=' LIMIT 1';
$stmt=$conn->prepare($sql);
if(isset($_SESSION['security_guard_id'])&&!isset($_SESSION['admin_id'])){
    $gid=(int)$_SESSION['security_guard_id']; $stmt->bind_param('ii',$id,$gid);
}else{$stmt->bind_param('i',$id);}
$stmt->execute(); $patrol=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$patrol){http_response_code(404);exit('Rekod patrol tidak ditemui.');}

$stmt=$conn->prepare('SELECT id,image_name,location_label,image_caption FROM security_patrol_images WHERE patrol_id=? ORDER BY id ASC');
$stmt->bind_param('i',$id); $stmt->execute(); $images=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$property=['name'=>'V23 Malawa Ria Apartment','company'=>'','address'=>'','logo'=>''];
if(!empty($patrol['property_id'])){
    $q=$conn->prepare("SELECT COUNT(*) total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cpms_properties'");
    $q->execute(); $exists=(int)($q->get_result()->fetch_assoc()['total']??0)>0; $q->close();
    if($exists){
        $q=$conn->prepare('SELECT * FROM cpms_properties WHERE id=? LIMIT 1'); $pid=(int)$patrol['property_id'];
        $q->bind_param('i',$pid); $q->execute(); $p=$q->get_result()->fetch_assoc(); $q->close();
        if($p){$property['name']=$p['property_name']??$p['name']??$property['name'];$property['company']=$p['company_name']??$p['company']??'';$property['address']=$p['address']??'';$property['logo']=$p['logo']??$p['logo_path']??'';}
    }
}
$guard=$_SESSION['security_guard_name']??('Guard #'.(int)$patrol['guard_id']);
$locs=locations($patrol['locations_checked']??'');
$conn->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($patrol['patrol_reference']??'Patrol')?></title><link rel="stylesheet" href="css/security_patrol_report.css?v=1">    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head><body>
<div class="page-shell"><div class="screen-actions no-print"><a class="button secondary" href="security_patrol_history.php">← History</a><a class="button" href="security_patrol_print.php?id=<?=(int)$id?>" target="_blank">Print / PDF</a></div>
<article class="report"><header class="report-header"><div class="brand"><?php if($property['logo']):?><img src="<?=e($property['logo'])?>" class="logo" alt="Logo"><?php endif;?><div><h1><?=e($property['name'])?></h1><?php if($property['company']):?><p class="company"><?=e($property['company'])?></p><?php endif;?><?php if($property['address']):?><p><?=e($property['address'])?></p><?php endif;?></div></div><div class="report-title"><strong>SECURITY PATROL REPORT</strong><span><?=e($patrol['patrol_reference']??'-')?></span></div></header>
<section class="summary-grid">
<div><span>Nama Pengawal</span><strong><?=e($guard)?></strong></div><div><span>Tarikh Patrol</span><strong><?=e(date('d/m/Y',strtotime((string)$patrol['patrol_date'])))?></strong></div><div><span>Jenis Patrol</span><strong><?=e($patrol['patrol_type']??'-')?></strong></div><div><span>Jenis Penugasan</span><strong><?=e($patrol['duty_type']??'-')?></strong></div><div><span>Masa Mula</span><strong><?=e(dt($patrol['started_at']??null))?></strong></div><div><span>Masa Tamat</span><strong><?=e(dt($patrol['completed_at']??null))?></strong></div><div><span>Tempoh</span><strong><?=e(duration($patrol['started_at']??null,$patrol['completed_at']??null))?></strong></div><div><span>Status</span><strong><?=e($patrol['patrol_status']??'Completed')?></strong></div>
</section>
<section class="section"><h2>Lokasi Diperiksa</h2><div class="location-list"><?php if($locs):foreach($locs as $l):?><span>✓ <?=e($l)?></span><?php endforeach;else:?><p class="muted">Tiada lokasi direkodkan.</p><?php endif;?></div></section>
<section class="section"><h2>Gambar Rondaan</h2><?php if($images):?><div class="photo-grid"><?php foreach($images as $i=>$img):?><figure class="photo-card"><a href="uploads/security_patrol/<?=e($img['image_name'])?>" target="_blank"><img src="uploads/security_patrol/<?=e($img['image_name'])?>" alt="Gambar patrol <?=($i+1)?>"></a><figcaption><strong><?=e($img['location_label']?:'Gambar '.($i+1))?></strong><?php if($img['image_caption']):?><span><?=e($img['image_caption'])?></span><?php endif;?></figcaption></figure><?php endforeach;?></div><?php else:?><p class="muted">Tiada gambar rondaan.</p><?php endif;?></section>
<section class="section"><h2>Catatan Patrol</h2><div class="note-box"><?=nl2br(e($patrol['patrol_notes']??'Tiada catatan.'))?></div></section>
<section class="section"><h2>Maklumat Isu</h2><?php if((int)($patrol['issue_found']??0)===1):?><div class="issue-box"><div><span>Isu Ditemui</span><strong>Ya</strong></div><div><span>Kategori</span><strong><?=e($patrol['issue_category']??'-')?></strong></div><div><span>Priority</span><strong><?=e($patrol['issue_priority']??'-')?></strong></div><div><span>Work Order</span><strong><?=((int)($patrol['work_order_required']??0)===1)?'Ya':'Tidak'?></strong></div><div class="full"><span>Penerangan</span><strong><?=nl2br(e($patrol['issue_description']??'-'))?></strong></div></div><?php else:?><div class="no-issue">Tiada isu dilaporkan semasa patrol.</div><?php endif;?></section>
<footer class="report-footer"><div><span>Disediakan oleh</span><strong><?=e($guard)?></strong><small>Security Guard</small></div><div><span>Tarikh laporan</span><strong><?=e(date('d/m/Y h:i A'))?></strong><small>Generated by CPMS</small></div></footer>
</article></div></body></html>
