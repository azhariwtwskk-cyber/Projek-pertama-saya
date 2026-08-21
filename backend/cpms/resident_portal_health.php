<?php
declare(strict_types=1);
session_start(); require_once __DIR__.'/db.php';
if((string)($_SESSION['cpms_user_role']??'')!=='system_owner' && empty($_SESSION['system_owner_id'])){http_response_code(403);exit('System Owner access required.');}
$tables=['cpms_residents','system_users','roles','user_roles','permissions','role_permissions','cpms_resident_announcements','cpms_resident_service_requests'];
$checks=[];foreach($tables as $t){$safe=$conn->real_escape_string($t);$q=$conn->query("SHOW TABLES LIKE '$safe'");$checks[$t]=$q&&$q->num_rows===1;}
$codes=['resident.dashboard.view','resident.profile.view','resident.announcement.view','resident.request.create','resident.request.view_own','resident.announcement.manage','resident.request.manage'];
$in="'".implode("','",array_map([$conn,'real_escape_string'],$codes))."'";$q=$conn->query("SELECT COUNT(*) total FROM permissions WHERE permission_code IN ($in)");$permissionCount=(int)($q->fetch_assoc()['total']??0);
$pass=!in_array(false,$checks,true)&&$permissionCount===count($codes);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resident Portal Health</title><style>body{font-family:Arial;background:#eef3f9;padding:30px;color:#10233f}.box{max-width:850px;margin:auto;background:#fff;padding:28px;border-radius:18px}.ok{color:green}.bad{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:12px;border-bottom:1px solid #ddd}</style></head><body><div class="box"><h1>CPMS v3.5.0 — Resident Portal Health</h1><h2 class="<?=$pass?'ok':'bad'?>"><?=$pass?'PASS':'ATTENTION REQUIRED'?></h2><p>Permissions: <?=$permissionCount?>/<?=count($codes)?></p><table><?php foreach($checks as $name=>$ready):?><tr><td><?=htmlspecialchars($name)?></td><td class="<?=$ready?'ok':'bad'?>"><?=$ready?'Ready':'Missing'?></td></tr><?php endforeach;?></table></div></body></html>
