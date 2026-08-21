<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
if ((string) ($_SESSION['cpms_user_role'] ?? '') !== 'system_owner'
    && empty($_SESSION['system_owner_id'])) {
    http_response_code(403);
    exit('System Owner access required.');
}
$result = $conn->query('SELECT COUNT(*) total FROM cpms_properties');
$properties = (int) ($result->fetch_assoc()['total'] ?? 0);
$ready = is_file(__DIR__ . '/leave_requests.php');
$pass = $ready && $properties > 0;
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Leave Context Health</title><style>body{font-family:Arial;background:#eef3f9;padding:30px}.box{max-width:760px;margin:auto;background:#fff;border-radius:16px;padding:28px}.ok{color:green}.bad{color:#b42318}</style></head><body><div class="box"><h1>CPMS v3.5.0.2 — Leave Context Health</h1><h2 class="<?=$pass?'ok':'bad'?>"><?=$pass?'PASS':'ATTENTION REQUIRED'?></h2><p>Property tersedia: <?=$properties?></p><p>System Owner boleh memilih property. Property Admin/Manager menggunakan property session sendiri.</p></div></body></html>
