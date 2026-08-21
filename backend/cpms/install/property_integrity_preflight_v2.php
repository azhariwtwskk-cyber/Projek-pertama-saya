<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();
if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$db = cpmsV2Database();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $db, string $table): bool
{
    $result = $db->query("SELECT DATABASE() AS db");
    $row = $result ? $result->fetch_assoc() : [];
    $database = (string) ($row['db'] ?? '');

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = ? AND table_name = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_assoc() : [];
    $stmt->close();

    return (int) ($data['total'] ?? 0) > 0;
}

function columnExists(mysqli $db, string $table, string $column): bool
{
    $result = $db->query("SELECT DATABASE() AS db");
    $row = $result ? $result->fetch_assoc() : [];
    $database = (string) ($row['db'] ?? '');

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_assoc() : [];
    $stmt->close();

    return (int) ($data['total'] ?? 0) > 0;
}

function foreignKeyExists(mysqli $db, string $table, string $column): bool
{
    $result = $db->query("SELECT DATABASE() AS db");
    $row = $result ? $result->fetch_assoc() : [];
    $database = (string) ($row['db'] ?? '');

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.key_column_usage
         WHERE table_schema = ?
           AND table_name = ?
           AND column_name = ?
           AND referenced_table_name IS NOT NULL"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_assoc() : [];
    $stmt->close();

    return (int) ($data['total'] ?? 0) > 0;
}

function scalar(mysqli $db, string $sql): int
{
    $result = $db->query($sql);
    if (!$result) {
        return -1;
    }
    $row = $result->fetch_row();
    return (int) ($row[0] ?? 0);
}

$tables = ['complaints', 'complaint_images', 'work_orders', 'assets', 'notifications'];
$checks = [];

foreach ($tables as $table) {
    $exists = tableExists($db, $table);
    $hasProperty = $exists && columnExists($db, $table, 'property_id');
    $orphans = -1;
    $nulls = -1;

    if ($hasProperty) {
        $orphans = scalar(
            $db,
            "SELECT COUNT(*)
             FROM `" . $db->real_escape_string($table) . "` t
             LEFT JOIN cpms_properties p ON p.id = t.property_id
             WHERE t.property_id IS NOT NULL AND p.id IS NULL"
        );
        $nulls = scalar(
            $db,
            "SELECT COUNT(*)
             FROM `" . $db->real_escape_string($table) . "`
             WHERE property_id IS NULL"
        );
    }

    $checks[$table] = [
        'exists' => $exists,
        'has_property_id' => $hasProperty,
        'orphans' => $orphans,
        'nulls' => $nulls,
        'fk_exists' => $hasProperty && foreignKeyExists($db, $table, 'property_id'),
    ];
}

$patrolExists = tableExists($db, 'security_patrols');
$patrolHasProperty = $patrolExists && columnExists($db, 'security_patrols', 'property_id');
$guardHasProperty = tableExists($db, 'security_guards') && columnExists($db, 'security_guards', 'property_id');

$patrolTotal = $patrolExists ? scalar($db, "SELECT COUNT(*) FROM security_patrols") : 0;
$patrolNulls = $patrolHasProperty
    ? scalar($db, "SELECT COUNT(*) FROM security_patrols WHERE property_id IS NULL")
    : $patrolTotal;

$guardMapped = 0;
if ($patrolExists && $guardHasProperty) {
    $guardMapped = scalar(
        $db,
        "SELECT COUNT(*)
         FROM security_patrols sp
         INNER JOIN security_guards sg ON sg.id = sp.guard_id
         WHERE sg.property_id IS NOT NULL"
    );
}

$properties = [];
$result = $db->query(
    "SELECT id, property_code, property_name, is_active
     FROM cpms_properties
     ORDER BY id"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}

$readyForForeignKeys = true;
foreach ($checks as $check) {
    if (
        !$check['exists']
        || !$check['has_property_id']
        || $check['orphans'] !== 0
        || $check['nulls'] !== 0
    ) {
        $readyForForeignKeys = false;
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Property Integrity Preflight</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1100px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}.notice{padding:13px;border-radius:10px;margin:15px 0}
.ok{background:#eaf8ef;color:#146c34}.warnbox{background:#fff7e5;color:#805800}
table{width:100%;border-collapse:collapse;margin-top:18px}
th,td{padding:11px;border-bottom:1px solid #e5ebf3;text-align:left;font-size:13px}
.pass{color:#08783b;font-weight:700}.warn{color:#a36b00;font-weight:700}.fail{color:#b42318;font-weight:700}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}.card strong{display:block;font-size:22px;margin-top:6px}
@media(max-width:760px){main{margin:10px}.cards{grid-template-columns:1fr 1fr}table{display:block;overflow-x:auto}}
</style>
</head>
<body><main>
<h1>CPMS v2.0.6 Property Integrity Preflight</h1>
<p class="muted">Read-only validation before adding property foreign keys.</p>

<?php if ($readyForForeignKeys): ?>
<div class="notice ok">Core property-scoped tables are ready for foreign-key migration.</div>
<?php else: ?>
<div class="notice warnbox">One or more tables require correction before foreign keys can be added.</div>
<?php endif; ?>

<table>
<thead>
<tr>
<th>Table</th>
<th>Exists</th>
<th>property_id</th>
<th>Null Values</th>
<th>Invalid Property</th>
<th>Foreign Key</th>
</tr>
</thead>
<tbody>
<?php foreach ($checks as $table => $check): ?>
<tr>
<td><strong><?= e($table) ?></strong></td>
<td class="<?= $check['exists'] ? 'pass' : 'fail' ?>"><?= $check['exists'] ? 'Yes' : 'No' ?></td>
<td class="<?= $check['has_property_id'] ? 'pass' : 'fail' ?>"><?= $check['has_property_id'] ? 'Yes' : 'No' ?></td>
<td class="<?= $check['nulls'] === 0 ? 'pass' : 'warn' ?>"><?= e($check['nulls']) ?></td>
<td class="<?= $check['orphans'] === 0 ? 'pass' : 'warn' ?>"><?= e($check['orphans']) ?></td>
<td class="<?= $check['fk_exists'] ? 'pass' : 'warn' ?>"><?= $check['fk_exists'] ? 'Present' : 'Missing' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Security Patrol Mapping</h2>
<div class="cards">
<div class="card">Patrol Records<strong><?= (int) $patrolTotal ?></strong></div>
<div class="card">property_id Column<strong><?= $patrolHasProperty ? 'Yes' : 'No' ?></strong></div>
<div class="card">Guard Property Available<strong><?= $guardHasProperty ? 'Yes' : 'No' ?></strong></div>
<div class="card">Can Map From Guards<strong><?= (int) $guardMapped ?></strong></div>
</div>

<p>
Existing patrols without a property assignment:
<strong><?= (int) $patrolNulls ?></strong>
</p>

<h3>Registered Properties</h3>
<table>
<thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Active</th></tr></thead>
<tbody>
<?php foreach ($properties as $property): ?>
<tr>
<td><?= (int) $property['id'] ?></td>
<td><?= e($property['property_code']) ?></td>
<td><?= e($property['property_name']) ?></td>
<td><?= (int) $property['is_active'] === 1 ? 'Yes' : 'No' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="muted">
Send a screenshot of this page before running the next migration.
No database changes are performed by this page.
</p>
</main></body>
</html>
