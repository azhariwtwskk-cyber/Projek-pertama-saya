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
$propertyCount = (int) ($result->fetch_assoc()['total'] ?? 0);
$files = ['attendance_admin.php','attendance_dashboard.php'];
$ready = true;
foreach ($files as $file) {
    $ready = $ready && is_file(__DIR__ . '/' . $file);
}
$pass = $ready && $propertyCount > 0;
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Attendance Context Health</title><style>body{font-family:Arial;background:#eef3f9;padding:30px}.box{max-width:760px;margin:auto;background:white;border-radius:16px;padding:28px}.ok{color:green}.bad{color:#b42318}</style></head><body><div class="box"><h1>CPMS v3.5.0.1 — Attendance Context Health</h1><h2 class="<?=$pass?'ok':'bad'?>"><?=$pass?'PASS':'ATTENTION REQUIRED'?></h2><p>Property tersedia: <?=$propertyCount?></p><p>Dashboard kini memilih property pertama secara automatik untuk System Owner dan mengekalkan property/bulan pada semua pautan.</p></div></body></html>
