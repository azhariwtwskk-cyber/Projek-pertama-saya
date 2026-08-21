<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();
if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$conn = cpmsV2Database();
$dbResult = $conn->query('SELECT DATABASE() AS database_name');
$dbRow = $dbResult ? $dbResult->fetch_assoc() : [];
$databaseName = (string) ($dbRow['database_name'] ?? '');

$targetTables = [
    'users',
    'roles',
    'permissions',
    'role_permissions',
    'user_roles',
    'cpms_properties',
    'properties',
    'property_admins',
    'complaints',
    'complaint_images',
    'work_orders',
    'assets',
    'inspections',
    'inspection',
    'patrols',
    'security_patrols',
    'attendance',
    'notifications',
    'audit_logs',
];

function cpmsAuditEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsAuditRows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function cpmsAuditTableExists(mysqli $conn, string $database, string $table): bool
{
    $statement = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = ?
           AND table_name = ?'
    );
    if (!$statement) {
        return false;
    }

    $statement->bind_param('ss', $database, $table);
    $statement->execute();
    $result = $statement->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $statement->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsAuditColumns(mysqli $conn, string $database, string $table): array
{
    $statement = $conn->prepare(
        'SELECT column_name, column_type, is_nullable, column_default,
                column_key, extra, ordinal_position
         FROM information_schema.columns
         WHERE table_schema = ?
           AND table_name = ?
         ORDER BY ordinal_position'
    );
    if (!$statement) {
        return [];
    }

    $statement->bind_param('ss', $database, $table);
    $statement->execute();
    $result = $statement->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $statement->close();

    return $rows;
}

function cpmsAuditIndexes(mysqli $conn, string $database, string $table): array
{
    $statement = $conn->prepare(
        'SELECT index_name, non_unique, seq_in_index, column_name
         FROM information_schema.statistics
         WHERE table_schema = ?
           AND table_name = ?
         ORDER BY index_name, seq_in_index'
    );
    if (!$statement) {
        return [];
    }

    $statement->bind_param('ss', $database, $table);
    $statement->execute();
    $result = $statement->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $statement->close();

    return $rows;
}

function cpmsAuditForeignKeys(mysqli $conn, string $database, string $table): array
{
    $statement = $conn->prepare(
        'SELECT constraint_name, column_name,
                referenced_table_name, referenced_column_name
         FROM information_schema.key_column_usage
         WHERE table_schema = ?
           AND table_name = ?
           AND referenced_table_name IS NOT NULL
         ORDER BY constraint_name, ordinal_position'
    );
    if (!$statement) {
        return [];
    }

    $statement->bind_param('ss', $database, $table);
    $statement->execute();
    $result = $statement->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $statement->close();

    return $rows;
}

$allTables = cpmsAuditRows(
    $conn,
    "SELECT table_name, engine, table_rows
     FROM information_schema.tables
     WHERE table_schema = '" . $conn->real_escape_string($databaseName) . "'
     ORDER BY table_name"
);

$report = [
    'generated_at' => date(DATE_ATOM),
    'database' => $databaseName,
    'mysql_version' => $conn->server_info,
    'target_tables' => [],
    'all_tables' => $allTables,
];

$summary = [
    'present' => 0,
    'missing' => 0,
    'missing_property_id' => 0,
    'without_foreign_keys' => 0,
];

foreach ($targetTables as $table) {
    $exists = cpmsAuditTableExists($conn, $databaseName, $table);
    $columns = $exists
        ? cpmsAuditColumns($conn, $databaseName, $table)
        : [];
    $indexes = $exists
        ? cpmsAuditIndexes($conn, $databaseName, $table)
        : [];
    $foreignKeys = $exists
        ? cpmsAuditForeignKeys($conn, $databaseName, $table)
        : [];

    $columnNames = array_map(
        static function (array $column): string {
            return (string) $column['column_name'];
        },
        $columns
    );

    $propertyScoped = in_array(
        $table,
        [
            'property_admins',
            'complaints',
            'complaint_images',
            'work_orders',
            'assets',
            'inspections',
            'inspection',
            'patrols',
            'security_patrols',
            'attendance',
            'notifications',
            'audit_logs',
        ],
        true
    );

    $status = 'missing';
    if ($exists) {
        $status = 'present';
        $summary['present']++;
    } else {
        $summary['missing']++;
    }

    if ($exists && $propertyScoped && !in_array('property_id', $columnNames, true)) {
        $status = 'missing_property_id';
        $summary['missing_property_id']++;
    }

    if ($exists && $propertyScoped && count($foreignKeys) === 0) {
        $summary['without_foreign_keys']++;
    }

    $report['target_tables'][$table] = [
        'status' => $status,
        'property_scoped' => $propertyScoped,
        'columns' => $columns,
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
    ];
}

if (isset($_GET['download']) && $_GET['download'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="cpms_database_audit_' .
        date('Ymd_His') . '.json"'
    );
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    exit;
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Database Audit</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1100px;margin:35px auto;background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#60708a}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:22px 0}
.card{background:#f7f9fc;padding:16px;border-radius:12px}.card strong{display:block;font-size:25px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:16px}th,td{text-align:left;padding:12px;border-bottom:1px solid #e5ebf3;font-size:13px}
.pass{color:#07883d;font-weight:700}.warn{color:#a36b00;font-weight:700}.fail{color:#c22626;font-weight:700}
.button{display:inline-block;background:#10213d;color:#fff;text-decoration:none;padding:11px 15px;border-radius:9px;font-weight:700}
@media(max-width:760px){main{margin:12px}.cards{grid-template-columns:1fr 1fr}table{display:block;overflow-x:auto}}
</style>
</head>
<body><main>
<h1>CPMS v2.0.4 Database Audit</h1>
<p class="muted">
Read-only inspection. No table or data is modified.
Database: <?= cpmsAuditEscape($databaseName) ?>
</p>

<div class="cards">
<div class="card">Target tables present<strong><?= (int) $summary['present'] ?></strong></div>
<div class="card">Target tables missing<strong><?= (int) $summary['missing'] ?></strong></div>
<div class="card">Missing property_id<strong><?= (int) $summary['missing_property_id'] ?></strong></div>
<div class="card">No foreign key detected<strong><?= (int) $summary['without_foreign_keys'] ?></strong></div>
</div>

<a class="button" href="?download=json">Download Audit JSON</a>

<table>
<thead>
<tr>
<th>Table</th>
<th>Status</th>
<th>property_id</th>
<th>Columns</th>
<th>Indexes</th>
<th>Foreign Keys</th>
</tr>
</thead>
<tbody>
<?php foreach ($report['target_tables'] as $table => $details): ?>
<?php
$columnNames = array_map(
    static function (array $column): string {
        return (string) $column['column_name'];
    },
    $details['columns']
);
$hasPropertyId = in_array('property_id', $columnNames, true);
$statusClass = $details['status'] === 'present'
    ? 'pass'
    : ($details['status'] === 'missing_property_id' ? 'warn' : 'fail');
?>
<tr>
<td><strong><?= cpmsAuditEscape($table) ?></strong></td>
<td class="<?= $statusClass ?>"><?= cpmsAuditEscape($details['status']) ?></td>
<td>
<?php if (!$details['property_scoped']): ?>
N/A
<?php else: ?>
<?= $hasPropertyId ? 'Yes' : 'No' ?>
<?php endif; ?>
</td>
<td><?= count($details['columns']) ?></td>
<td><?= count($details['indexes']) ?></td>
<td><?= count($details['foreign_keys']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="muted">
Download the JSON report and provide it for the migration package.
Delete or rename this page after the audit.
</p>
</main></body>
</html>
