<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/cpms_bootstrap.php';
require_once __DIR__ . '/../includes/password_recovery_service.php';

$isOwner = (string) ($_SESSION['cpms_user_role'] ?? '') === 'system_owner'
    || isset($_SESSION['system_owner_id']);
if (!$isOwner) {
    http_response_code(403);
    exit('System Owner access required.');
}
$checks = [];
foreach ([
    'system_users', 'cpms_password_reset_tokens',
    'cpms_password_reset_settings', 'cpms_auth_sessions',
    'cpms_auth_audit_logs',
] as $table) {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    $checks[$table] = $result && $result->num_rows === 1;
}
$settings = $checks['cpms_password_reset_settings']
    ? cpmsPasswordRecoverySettings($conn) : [];
$checks['Sender e-mail configured'] = !empty($settings['sender_email'])
    && filter_var((string) $settings['sender_email'], FILTER_VALIDATE_EMAIL);
$checks['PHP mail available'] = function_exists('mail');
$checks['HTTPS'] = strpos(cpmsPasswordRecoveryBaseUrl(), 'https://') === 0;
$ready = !in_array(false, $checks, true);
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password Recovery Health | CPMS</title>
<style>
body{font:16px Arial;background:#eef3f9;color:#10213d;padding:28px}
main{max-width:850px;margin:auto;background:#fff;padding:30px;border-radius:18px}
table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #ddd;
text-align:left}.pass{color:#087b32;font-weight:bold}.fail{color:#b91c1c;font-weight:bold}
a{color:#174789;font-weight:700}</style></head><body><main>
<h1>CPMS v3.4.2 — Password Recovery Health</h1>
<h2 class="<?= $ready ? 'pass' : 'fail' ?>">
<?= $ready ? 'PASS — Password recovery is ready.' : 'ACTION REQUIRED' ?></h2>
<table><tr><th>Check</th><th>Status</th></tr>
<?php foreach ($checks as $name => $ok): ?><tr>
<td><?= cpmsPasswordRecoveryEscape($name) ?></td>
<td class="<?= $ok ? 'pass' : 'fail' ?>"><?= $ok ? 'Ready' : 'Missing' ?></td>
</tr><?php endforeach; ?></table>
<p><a href="password_recovery_setup.php">Buka tetapan e-mel</a></p>
</main></body></html>
