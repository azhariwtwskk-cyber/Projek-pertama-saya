<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();
if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$db = cpmsV2Database();

function cpmsFkEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsFkDatabase(mysqli $db): string
{
    $result = $db->query('SELECT DATABASE() AS database_name');
    $row = $result ? $result->fetch_assoc() : [];

    return (string) ($row['database_name'] ?? '');
}

function cpmsFkFind(
    mysqli $db,
    string $database,
    string $table,
    string $column,
    string $parentTable,
    string $parentColumn
): array {
    $stmt = $db->prepare(
        'SELECT
            rc.constraint_name AS constraint_name,
            rc.update_rule AS update_rule,
            rc.delete_rule AS delete_rule
         FROM information_schema.referential_constraints AS rc
         INNER JOIN information_schema.key_column_usage AS kcu
           ON kcu.constraint_schema = rc.constraint_schema
          AND kcu.constraint_name = rc.constraint_name
          AND kcu.table_name = rc.table_name
         WHERE kcu.table_schema = ?
           AND kcu.table_name = ?
           AND kcu.column_name = ?
           AND kcu.referenced_table_name = ?
           AND kcu.referenced_column_name = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param(
        'sssss',
        $database,
        $table,
        $column,
        $parentTable,
        $parentColumn
    );
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    return is_array($row) ? $row : [];
}

function cpmsFkAll(mysqli $db, string $database): array
{
    $stmt = $db->prepare(
        'SELECT
            kcu.table_name AS child_table,
            kcu.column_name AS child_column,
            kcu.referenced_table_name AS parent_table,
            kcu.referenced_column_name AS parent_column,
            rc.constraint_name AS constraint_name,
            rc.update_rule AS update_rule,
            rc.delete_rule AS delete_rule
         FROM information_schema.referential_constraints AS rc
         INNER JOIN information_schema.key_column_usage AS kcu
           ON kcu.constraint_schema = rc.constraint_schema
          AND kcu.constraint_name = rc.constraint_name
          AND kcu.table_name = rc.table_name
         WHERE kcu.table_schema = ?
         ORDER BY kcu.table_name, rc.constraint_name, kcu.ordinal_position'
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $database);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();

    return $rows;
}

$database = cpmsFkDatabase($db);

$definitions = [
    ['complaints', 'property_id', 'cpms_properties', 'id'],
    ['complaint_images', 'property_id', 'cpms_properties', 'id'],
    ['work_orders', 'property_id', 'cpms_properties', 'id'],
    ['assets', 'property_id', 'cpms_properties', 'id'],
    ['notifications', 'property_id', 'cpms_properties', 'id'],
    ['security_patrols', 'property_id', 'cpms_properties', 'id'],
    ['security_guards', 'property_id', 'cpms_properties', 'id'],
];

$rows = [];
$passed = 0;
$rulePassed = 0;

foreach ($definitions as $definition) {
    $fk = cpmsFkFind(
        $db,
        $database,
        $definition[0],
        $definition[1],
        $definition[2],
        $definition[3]
    );

    $present = !empty($fk);
    $rulesValid = (
        $present
        && strtoupper((string) ($fk['update_rule'] ?? '')) === 'CASCADE'
        && strtoupper((string) ($fk['delete_rule'] ?? '')) === 'RESTRICT'
    );

    if ($present) {
        $passed++;
    }

    if ($rulesValid) {
        $rulePassed++;
    }

    $rows[] = [
        'table' => $definition[0],
        'column' => $definition[1],
        'parent_table' => $definition[2],
        'parent_column' => $definition[3],
        'present' => $present,
        'rules_valid' => $rulesValid,
        'constraint_name' => (string) ($fk['constraint_name'] ?? ''),
        'update_rule' => (string) ($fk['update_rule'] ?? ''),
        'delete_rule' => (string) ($fk['delete_rule'] ?? ''),
    ];
}

$total = count($rows);
$score = $total > 0
    ? (int) round(($passed / $total) * 100)
    : 0;

$ruleScore = $total > 0
    ? (int) round(($rulePassed / $total) * 100)
    : 0;

$allForeignKeys = cpmsFkAll($db, $database);
$totalDatabaseForeignKeys = count($allForeignKeys);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Foreign Key Health</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1180px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}
.notice{padding:14px;border-radius:10px;margin:16px 0}
.success{background:#eaf8ef;color:#146c34}.warning{background:#fff7e5;color:#805800}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}
.card strong{display:block;font-size:24px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:18px}
th,td{padding:11px;border-bottom:1px solid #e5ebf3;text-align:left;font-size:13px;vertical-align:top}
.pass{color:#08783b;font-weight:700}.fail{color:#b42318;font-weight:700}.warn{color:#9a6700;font-weight:700}
.section{margin-top:30px}
@media(max-width:760px){main{margin:10px}.cards{grid-template-columns:1fr 1fr}table{display:block;overflow-x:auto}}
</style>
</head>
<body>
<main>
<h1>CPMS v2.1.1 Foreign Key Health</h1>
<p class="muted">
MariaDB-compatible verification for required and existing foreign keys.
</p>

<div class="notice <?= ($score === 100 && $ruleScore === 100) ? 'success' : 'warning' ?>">
<?= ($score === 100 && $ruleScore === 100)
    ? 'All required property foreign keys and rules are valid.'
    : 'Some required foreign keys or rules still require attention.' ?>
</div>

<div class="cards">
<div class="card">Health Score<strong><?= (int) $score ?>%</strong></div>
<div class="card">Rule Score<strong><?= (int) $ruleScore ?>%</strong></div>
<div class="card">Required Passed<strong><?= (int) $passed ?>/<?= (int) $total ?></strong></div>
<div class="card">All DB Foreign Keys<strong><?= (int) $totalDatabaseForeignKeys ?></strong></div>
</div>

<div class="section">
<h2>Required CPMS Property Foreign Keys</h2>
<table>
<thead>
<tr>
<th>Relationship</th>
<th>Constraint</th>
<th>Update Rule</th>
<th>Delete Rule</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td>
<?= cpmsFkEsc($row['table']) ?>.<?= cpmsFkEsc($row['column']) ?>
→
<?= cpmsFkEsc($row['parent_table']) ?>.<?= cpmsFkEsc($row['parent_column']) ?>
</td>
<td><?= cpmsFkEsc($row['constraint_name'] ?: '—') ?></td>
<td class="<?= strtoupper($row['update_rule']) === 'CASCADE' ? 'pass' : 'warn' ?>">
<?= cpmsFkEsc($row['update_rule'] ?: '—') ?>
</td>
<td class="<?= strtoupper($row['delete_rule']) === 'RESTRICT' ? 'pass' : 'warn' ?>">
<?= cpmsFkEsc($row['delete_rule'] ?: '—') ?>
</td>
<td class="<?= $row['rules_valid'] ? 'pass' : ($row['present'] ? 'warn' : 'fail') ?>">
<?= $row['rules_valid']
    ? 'Valid'
    : ($row['present'] ? 'Present / Rule mismatch' : 'Missing') ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="section">
<h2>All Foreign Keys in Current Database</h2>
<table>
<thead>
<tr>
<th>Child</th>
<th>Parent</th>
<th>Constraint</th>
<th>Update</th>
<th>Delete</th>
</tr>
</thead>
<tbody>
<?php if (!$allForeignKeys): ?>
<tr><td colspan="5">No foreign keys found.</td></tr>
<?php else: ?>
<?php foreach ($allForeignKeys as $foreignKey): ?>
<tr>
<td>
<?= cpmsFkEsc($foreignKey['child_table']) ?>.<?= cpmsFkEsc($foreignKey['child_column']) ?>
</td>
<td>
<?= cpmsFkEsc($foreignKey['parent_table']) ?>.<?= cpmsFkEsc($foreignKey['parent_column']) ?>
</td>
<td><?= cpmsFkEsc($foreignKey['constraint_name']) ?></td>
<td><?= cpmsFkEsc($foreignKey['update_rule']) ?></td>
<td><?= cpmsFkEsc($foreignKey['delete_rule']) ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<p class="muted">
This page is read-only and does not modify database structure or data.
</p>
</main>
</body>
</html>
