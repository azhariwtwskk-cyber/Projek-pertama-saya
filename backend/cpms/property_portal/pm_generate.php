<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('assets.view');
require_once dirname(__DIR__) . '/includes/pm_service.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed.');}
if(!propertyPortalVerifyCsrf(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)){$_SESSION['pm_flash']=['type'=>'danger','message'=>'Invalid request token.'];header('Location: preventive_maintenance.php');exit;}
if(!cpmsPmSchemaReady($conn)){$_SESSION['pm_flash']=['type'=>'danger','message'=>'Preventive Maintenance migration not imported.'];header('Location: preventive_maintenance.php');exit;}
$result=cpmsPmGenerateDue($conn,$currentPropertyId,(string)$propertyPortalUser['full_name']);
$message=$result['generated'].' work order(s) generated'; if($result['skipped']>0)$message.=', '.$result['skipped'].' skipped'; if($result['errors'])$message.='. Error: '.implode(' | ',$result['errors']);
$_SESSION['pm_flash']=['type'=>$result['errors']?'danger':'success','message'=>$message.'.'];header('Location: preventive_maintenance.php');exit;
