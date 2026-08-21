<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();

if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$db = cpmsV2Database();

function cpmsSghEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$result = $db->query(
    "SELECT
        c.IS_NULLABLE,
        c.COLUMN_TYPE,
        COUNT(sg.id) AS total_guards,
        SUM(CASE WHEN sg.property_id IS NULL THEN 1 ELSE 0 END) AS null_guards,
        SUM(CASE WHEN sg.property_id IS NOT NULL AND p.id IS NULL THEN 1 ELSE 0 END) AS invalid_guards
     FROM information_schema.columns c
     LEFT JOIN security_guards sg ON 1=1
     LEFT JOIN cpms_properties p ON p.id = sg.property_id
     WHERE c.table_schema = DATABASE()
       AND c.table_name = 'security_guards'
       AND c.column_name = 'property_id'
     GROUP BY c.IS_NULLABLE, c.COLUMN_TYPE"
);

$row = $result ? $result->fetch_assoc() : [];

$nullable = strtoupper((string) ($row['IS_NULLABLE'] ?? 'YES'));
$total = (int) ($row['total_guards'] ?? 0);
$nulls = (int) ($row['null_guards'] ?? 0);
$invalid = (int) ($row['invalid_guards'] ?? 0);
$healthy = $nullable === 'NO' && $nulls === 0 && $invalid === 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Security Guard Registration Health</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:850px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
.notice{padding:14px;border-radius:10px;margin:16px 0}
.success{background:#eaf8ef;color:#146c34}.warning{background:#fff7e5;color:#805800}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}.card strong{display:block;font-size:24px;margin-top:6px}
@media(max-width:700px){main{margin:10px}.cards{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<main>
<h1>CPMS v2.1.2 Security Guard Registration Health</h1>
<div class="notice <?= $healthy ? 'success' : 'warning' ?>">
<?= $healthy
    ? 'Security guard property assignment is fully enforced.'
    : 'Security guard property assignment still requires attention.' ?>
</div>
<div class="cards">
<div class="card">Column Nullable<strong><?= cpmsSghEsc($nullable) ?></strong></div>
<div class="card">Guards<strong><?= $total ?></strong></div>
<div class="card">NULL Property<strong><?= $nulls ?></strong></div>
<div class="card">Invalid Property<strong><?= $invalid ?></strong></div>
</div>
<p>
Registration page:
<code>/cpms/security/register_guard_v2.php</code>
</p>
</main>
</body>
</html>
