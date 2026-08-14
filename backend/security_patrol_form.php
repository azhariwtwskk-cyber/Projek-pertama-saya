<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
if(!isset($_SESSION["security_guard_id"])){header("Location: cpms/login.php");exit();}
cpmsRequire('security.patrol', $conn);
if(!isset($_SESSION["security_patrol_csrf"]))$_SESSION["security_patrol_csrf"]=bin2hex(random_bytes(32));
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8");}
$locations=["Guard House","Main Entrance / Boom Gate","Block A","Block B","Block C","Block D","Block E","Playground","Water Tank","Pump Room","Solar CCTV","Car Park","Drainage","Common Area","Other"];
$securityPropertyId=(int)($_SESSION['cpms_property_id']??$_SESSION['security_guard_property_id']??0);
$securityPrimaryColor='#1f2937';$securitySecondaryColor='#b59b20';$securityPropertyName='CPMS Security Patrol';
if($securityPropertyId>0){$brandStmt=$conn->prepare('SELECT property_name,primary_color,secondary_color FROM cpms_properties WHERE id=? LIMIT 1');if($brandStmt){$brandStmt->bind_param('i',$securityPropertyId);$brandStmt->execute();$brandRow=$brandStmt->get_result()->fetch_assoc();$brandStmt->close();if(is_array($brandRow)){if(!empty($brandRow['property_name']))$securityPropertyName=(string)$brandRow['property_name'];if(!empty($brandRow['primary_color']))$securityPrimaryColor=(string)$brandRow['primary_color'];if(!empty($brandRow['secondary_color']))$securitySecondaryColor=(string)$brandRow['secondary_color'];}}}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Start Patrol</title>
<link rel="stylesheet" href="css/security_patrol.css?v=1">
<link rel="stylesheet" href="css/workforce_portal_shell.css?v=394">
<style>:root{--cpms-primary:<?=e($securityPrimaryColor)?>;--cpms-secondary:<?=e($securitySecondaryColor)?>;--property-primary:<?=e($securityPrimaryColor)?>;--property-secondary:<?=e($securitySecondaryColor)?>}</style>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>
<body class="cpms-security-page">
<div class="workforce-shell">
<aside class="workforce-sidebar">
<div class="workforce-brand"><div class="workforce-logo">SG</div><div><strong><?=e($securityPropertyName)?></strong><span>Security Portal</span></div></div>
<nav class="workforce-nav" aria-label="Security navigation">
<span class="workforce-nav-label">Operations</span>
<a href="security_dashboard.php">Dashboard</a>
<a class="active" href="security_patrol_form.php">Start Patrol</a>
<a href="security_patrol_history.php">History</a>
</nav>
<div class="workforce-account"><a href="security_logout.php">Logout</a></div>
</aside>
<main class="workforce-main">
<header class="workforce-topbar"><div><h1>Start Security Patrol</h1><p><?=e($securityPropertyName)?></p></div><div class="workforce-user-chip"><?=e($_SESSION["security_guard_name"])?></div></header>
<div class="workforce-content">
<section class="page-heading"><div><span class="section-label">SECURITY OPERATIONS</span><h1>Start Patrol</h1><p>Record patrol details and upload up to 10 location photos.</p></div></section>
<form action="security_patrol_submit.php" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e($_SESSION["security_patrol_csrf"])?>">
<section class="card"><h2>Maklumat Patrol</h2><div class="grid"><div class="group"><label>Jenis Patrol</label><select name="patrol_type"><option>Routine Patrol</option><option>Opening Patrol</option><option>Closing Patrol</option><option>Perimeter Patrol</option><option>Block Patrol</option><option>Common Area Patrol</option><option>Asset Inspection Patrol</option><option>Emergency Patrol</option><option>Special Assignment</option></select></div><div class="group"><label>Jenis Penugasan</label><select name="duty_type"><option>Regular Duty</option><option>Buffer Duty</option><option>Replacement Duty</option><option>Special Assignment</option><option>Emergency Duty</option></select></div><div class="group"><label>Masa Mula</label><input type="datetime-local" name="started_at" value="<?=date("Y-m-d\TH:i")?>" required></div><div class="group"><label>Masa Tamat</label><input type="datetime-local" name="completed_at" required></div></div></section>
<section class="card"><h2>Lokasi Diperiksa</h2><div class="locations"><?php foreach($locations as $l):?><label><input type="checkbox" name="locations[]" value="<?=e($l)?>"> <?=e($l)?></label><?php endforeach;?></div></section>
<section class="card"><h2>Gambar Lokasi Rondaan</h2><p>Minimum 1, maksimum 10 gambar. Setiap gambar wajib mempunyai label lokasi.</p><?php for($i=1;$i<=10;$i++):?><div class="photo-row"><div class="group"><label>Gambar <?=$i?></label><input type="file" name="patrol_photos[]" accept="image/jpeg,image/png,image/webp"></div><div class="group"><label>Lokasi</label><select name="photo_locations[]"><option value="">-- Pilih --</option><?php foreach($locations as $l):?><option value="<?=e($l)?>"><?=e($l)?></option><?php endforeach;?></select></div><div class="group"><label>Catatan</label><input name="photo_captions[]" maxlength="255"></div></div><?php endfor;?></section>
<section class="card"><h2>Catatan dan Isu</h2><div class="grid"><div class="group full"><label>Catatan Patrol</label><textarea name="patrol_notes"></textarea></div><div class="group"><label>Ada Masalah?</label><select name="issue_found" id="issue_found" onchange="toggleIssue()"><option value="0">Tidak</option><option value="1">Ya</option></select></div><div class="group issue"><label>Kategori</label><select name="issue_category"><option value="">-- Pilih --</option><option>Lighting</option><option>Water Leak</option><option>Boom Gate</option><option>CCTV</option><option>Vandalism</option><option>Safety Hazard</option><option>Asset Damage</option><option>Others</option></select></div><div class="group issue"><label>Priority</label><select name="issue_priority"><option value="">-- Pilih --</option><option>Low</option><option>Medium</option><option>High</option><option>Emergency</option></select></div><div class="group issue"><label>Work Order Diperlukan?</label><select name="work_order_required"><option value="0">Tidak</option><option value="1">Ya</option></select></div><div class="group full issue"><label>Penerangan Masalah</label><textarea name="issue_description"></textarea></div></div></section><section class="card"><button class="btn">Submit Patrol</button></section></form></div></main></div><script>function toggleIssue(){document.querySelectorAll(".issue").forEach(x=>x.style.display=document.getElementById("issue_found").value==="1"?"flex":"none")}toggleIssue();</script></body></html>
