<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
$isOwner = (string) ($_SESSION['cpms_user_role'] ?? '') === 'system_owner'
    || isset($_SESSION['system_owner_id']);
if (!$isOwner) {
    http_response_code(403);
    exit('System Owner access required.');
}
$tables = [
    'cpms_push_settings', 'cpms_push_subscriptions',
    'cpms_push_deliveries', 'cpms_user_notifications',
];
$checks = [];
foreach ($tables as $table) {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    $checks[$table] = $result && $result->num_rows === 1;
}
$configured = false;
if ($checks['cpms_push_settings']) {
    $result = $conn->query('SELECT COUNT(*) total FROM cpms_push_settings');
    $configured = (int) (($result->fetch_assoc()['total'] ?? 0)) === 1;
}
$checks['OpenSSL EC'] = extension_loaded('openssl')
    && defined('OPENSSL_KEYTYPE_EC');
$checks['cURL'] = function_exists('curl_init');
$checks['HTTPS'] = !empty($_SERVER['HTTPS'])
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$ready = !in_array(false, $checks, true) && $configured;
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mobile Push Health</title>
<style>
body{font:16px Arial;background:#eef3f9;color:#10213d;padding:30px}
main{max-width:850px;margin:auto;background:#fff;padding:28px;border-radius:18px}
table{width:100%;border-collapse:collapse}td,th{padding:12px;border-bottom:1px solid #ddd;text-align:left}
.pass{color:#087b32;font-weight:bold}.fail{color:#b91c1c;font-weight:bold}
</style></head><body><main>
<h1>CPMS v3.4.1 — Mobile Push Health</h1>
<h2 class="<?= $ready ? 'pass' : 'fail' ?>">
<?= $ready ? 'PASS — Push module is ready.' : 'ACTION REQUIRED' ?></h2>
<table><tr><th>Check</th><th>Status</th></tr>
<?php foreach ($checks as $name => $ok): ?>
<tr><td><?= htmlspecialchars($name) ?></td>
<td class="<?= $ok ? 'pass' : 'fail' ?>"><?= $ok ? 'Ready' : 'Missing' ?></td></tr>
<?php endforeach; ?>
<tr><td>VAPID configuration</td>
<td class="<?= $configured ? 'pass' : 'fail' ?>"><?= $configured ? 'Ready' : 'Run pwa_push_setup.php' ?></td></tr>
</table></main></body></html>
