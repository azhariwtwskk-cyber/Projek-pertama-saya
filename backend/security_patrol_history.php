<?php
declare(strict_types=1);session_start();require_once __DIR__.'/db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
if(!isset($_SESSION['security_guard_id'])){header('Location: cpms/login.php');exit();}
cpmsRequire('security.view', $conn);
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$securityPropertyId=(int)($_SESSION['cpms_property_id']??$_SESSION['security_guard_property_id']??0);
$securityPrimaryColor='#1f2937';$securitySecondaryColor='#b59b20';$securityPropertyName='CPMS Security Patrol';
if($securityPropertyId>0){$brandStmt=$conn->prepare('SELECT property_name,primary_color,secondary_color FROM cpms_properties WHERE id=? LIMIT 1');if($brandStmt){$brandStmt->bind_param('i',$securityPropertyId);$brandStmt->execute();$brandRow=$brandStmt->get_result()->fetch_assoc();$brandStmt->close();if(is_array($brandRow)){if(!empty($brandRow['property_name']))$securityPropertyName=(string)$brandRow['property_name'];if(!empty($brandRow['primary_color']))$securityPrimaryColor=(string)$brandRow['primary_color'];if(!empty($brandRow['secondary_color']))$securitySecondaryColor=(string)$brandRow['secondary_color'];}}}
$id=(int)$_SESSION['security_guard_id'];$rows=[];$s=$conn->prepare('SELECT * FROM security_patrols WHERE guard_id=? ORDER BY created_at DESC');$s->bind_param('i',$id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$rows[]=$x;$s->close();$conn->close();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Patrol History</title>
<link rel="stylesheet" href="css/security_patrol.css?v=1">
<link rel="stylesheet" href="css/security_patrol_report.css?v=1">
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
<a href="security_patrol_form.php">Start Patrol</a>
<a class="active" href="security_patrol_history.php">History</a>
</nav>
<div class="workforce-account"><a href="security_logout.php">Logout</a></div>
</aside>
<main class="workforce-main">
<header class="workforce-topbar"><div><h1>Patrol History</h1><p><?=e($securityPropertyName)?></p></div><div class="workforce-user-chip"><?=e($_SESSION['security_guard_name']??'Security Guard')?></div></header>
<div class="workforce-content">
<section class="page-heading"><div><span class="section-label">SECURITY OPERATIONS</span><h1>Patrol History</h1><p>Review completed patrol records and print patrol reports.</p></div></section>
<section class="card"><div class="history-table-wrap"><table class="history-table"><thead><tr><th>Reference</th><th>Date</th><th>Type</th><th>Issue</th><th>Status</th><th>Action</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6">No patrol record yet.</td></tr><?php else:foreach($rows as $x):?><tr><td data-label="Reference"><strong><?=e($x['patrol_reference']??'-')?></strong></td><td data-label="Date"><?=e(date('d/m/Y',strtotime((string)$x['patrol_date'])))?></td><td data-label="Type"><?=e($x['patrol_type']??'-')?></td><td data-label="Issue"><?=((int)($x['issue_found']??0)===1)?'Yes':'No'?></td><td data-label="Status"><?=e($x['patrol_status']??'Completed')?></td><td data-label="Action"><div class="table-actions"><a class="mini-button" href="security_patrol_details.php?id=<?=(int)$x['id']?>">View</a><a class="mini-button secondary" href="security_patrol_print.php?id=<?=(int)$x['id']?>" target="_blank">Print</a></div></td></tr><?php endforeach;endif;?></tbody></table></div></section>
</div>
</main>
</div>
</body>
</html>
