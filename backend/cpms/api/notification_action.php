<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/notification_context.php';
require_once dirname(__DIR__).'/includes/notification_helper.php';

$action=(string)($_POST['action']??'');
$id=(int)($_POST['notification_id']??0);
$ok=false;

if($action==='read' && $id>0){
 $ok=markNotificationRead($conn,$id,$notificationUserRole,$notificationUserId);
}
if($action==='archive' && $id>0){
 $ok=archiveNotification($conn,$id,$notificationUserRole,$notificationUserId);
}
echo json_encode(['success'=>$ok],JSON_UNESCAPED_UNICODE);
