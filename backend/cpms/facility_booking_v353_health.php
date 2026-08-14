<?php
declare(strict_types=1);
session_start();
require_once __DIR__.'/db.php';
if((string)($_SESSION['cpms_user_role']??'')!=='system_owner'&&empty($_SESSION['system_owner_id'])){
    http_response_code(403);
    exit('System Owner access required.');
}
$tables=['cpms_resident_notifications','cpms_facility_booking_cancellations'];
$checks=[];
foreach($tables as $t){
    $safe=$conn->real_escape_string($t);
    $q=$conn->query("SHOW TABLES LIKE '$safe'");
    $checks[$t]=$q&&$q->num_rows===1;
}
$codes=['resident.notifications.view','facility.booking.calendar'];
$in="'".implode("','",array_map([$conn,'real_escape_string'],$codes))."'";
$q=$conn->query("SELECT COUNT(*) total FROM permissions WHERE permission_code IN ($in)");
$pc=(int)($q->fetch_assoc()['total']??0);
$pass=!in_array(false,$checks,true)&&$pc===2;
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Facility v3.5.3 Health</title><style>body{font-family:Arial;background:#eef3f9;padding:30px}.box{max-width:800px;margin:auto;background:#fff;border-radius:16px;padding:28px}.ok{color:green}.bad{color:#b42318}td{padding:10px;border-bottom:1px solid #ddd}</style></head><body><div class="box"><h1>CPMS v3.5.3 — Facility Workflow Health</h1><h2 class="<?=$pass?'ok':'bad'?>"><?=$pass?'PASS':'ATTENTION REQUIRED'?></h2><p>Permissions: <?=$pc?>/2</p><table><?php foreach($checks as $n=>$v):?><tr><td><?=htmlspecialchars($n)?></td><td class="<?=$v?'ok':'bad'?>"><?=$v?'Ready':'Missing'?></td></tr><?php endforeach;?></table></div></body></html>
