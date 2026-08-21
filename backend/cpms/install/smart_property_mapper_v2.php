<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();
if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$db = cpmsV2Database();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['cpms_mapper_csrf'])) {
    $_SESSION['cpms_mapper_csrf'] = bin2hex(random_bytes(24));
}

function cpmsMapEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsMapTableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsMapColumnExists(
    mysqli $db,
    string $table,
    string $column
): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsMapScalar(mysqli $db, string $sql): int
{
    $result = $db->query($sql);
    if (!$result) {
        return -1;
    }

    $row = $result->fetch_row();
    return (int) ($row[0] ?? 0);
}

function cpmsMapGuardNameColumn(mysqli $db): ?string
{
    foreach (['name', 'full_name', 'guard_name', 'username'] as $column) {
        if (cpmsMapColumnExists($db, 'security_guards', $column)) {
            return $column;
        }
    }

    return null;
}

$requirements = [
    'security_patrols table' => cpmsMapTableExists($db, 'security_patrols'),
    'security_guards table' => cpmsMapTableExists($db, 'security_guards'),
    'cpms_properties table' => cpmsMapTableExists($db, 'cpms_properties'),
    'security_patrols.property_id' => cpmsMapColumnExists(
        $db,
        'security_patrols',
        'property_id'
    ),
    'security_patrols.guard_id' => cpmsMapColumnExists(
        $db,
        'security_patrols',
        'guard_id'
    ),
    'security_guards.property_id' => cpmsMapColumnExists(
        $db,
        'security_guards',
        'property_id'
    ),
    'security_guards.id' => cpmsMapColumnExists(
        $db,
        'security_guards',
        'id'
    ),
    'cpms_properties.id' => cpmsMapColumnExists(
        $db,
        'cpms_properties',
        'id'
    ),
];

$requirementsReady = !in_array(false, $requirements, true);
$message = '';
$messageType = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
) {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['cpms_mapper_csrf'], $token)) {
        $message = 'Invalid security token. Refresh the page and try again.';
        $messageType = 'error';
    } elseif (!$requirementsReady) {
        $message = 'Required tables or columns are missing.';
        $messageType = 'error';
    } elseif ($_POST['action'] === 'apply_mapping') {
        $before = cpmsMapScalar(
            $db,
            'SELECT COUNT(*)
             FROM security_patrols
             WHERE property_id IS NULL'
        );

        try {
            $db->begin_transaction();

            $sql = '
                UPDATE security_patrols sp
                INNER JOIN security_guards sg
                    ON sg.id = sp.guard_id
                INNER JOIN cpms_properties p
                    ON p.id = sg.property_id
                SET sp.property_id = sg.property_id
                WHERE sp.property_id IS NULL
                  AND sg.property_id IS NOT NULL
            ';

            if (!$db->query($sql)) {
                throw new RuntimeException($db->error);
            }

            $updated = $db->affected_rows;

            $invalid = cpmsMapScalar(
                $db,
                'SELECT COUNT(*)
                 FROM security_patrols sp
                 LEFT JOIN cpms_properties p
                   ON p.id = sp.property_id
                 WHERE sp.property_id IS NOT NULL
                   AND p.id IS NULL'
            );

            if ($invalid !== 0) {
                throw new RuntimeException(
                    'Mapping produced invalid property references.'
                );
            }

            $db->commit();

            $after = cpmsMapScalar(
                $db,
                'SELECT COUNT(*)
                 FROM security_patrols
                 WHERE property_id IS NULL'
            );

            $message = sprintf(
                'Mapping completed. Updated %d records. NULL property_id reduced from %d to %d.',
                $updated,
                $before,
                $after
            );
            $messageType = 'success';
        } catch (Throwable $exception) {
            $db->rollback();
            $message = 'Mapping failed and was rolled back: '
                . $exception->getMessage();
            $messageType = 'error';
        }
    }
}

$totalPatrols = $requirementsReady
    ? cpmsMapScalar($db, 'SELECT COUNT(*) FROM security_patrols')
    : -1;

$nullProperty = $requirementsReady
    ? cpmsMapScalar(
        $db,
        'SELECT COUNT(*)
         FROM security_patrols
         WHERE property_id IS NULL'
    )
    : -1;

$mappable = $requirementsReady
    ? cpmsMapScalar(
        $db,
        'SELECT COUNT(*)
         FROM security_patrols sp
         INNER JOIN security_guards sg
           ON sg.id = sp.guard_id
         INNER JOIN cpms_properties p
           ON p.id = sg.property_id
         WHERE sp.property_id IS NULL
           AND sg.property_id IS NOT NULL'
    )
    : -1;

$unmappable = $requirementsReady
    ? cpmsMapScalar(
        $db,
        'SELECT COUNT(*)
         FROM security_patrols sp
         LEFT JOIN security_guards sg
           ON sg.id = sp.guard_id
         LEFT JOIN cpms_properties p
           ON p.id = sg.property_id
         WHERE sp.property_id IS NULL
           AND (
                sg.id IS NULL
                OR sg.property_id IS NULL
                OR p.id IS NULL
           )'
    )
    : -1;

$invalidExisting = $requirementsReady
    ? cpmsMapScalar(
        $db,
        'SELECT COUNT(*)
         FROM security_patrols sp
         LEFT JOIN cpms_properties p
           ON p.id = sp.property_id
         WHERE sp.property_id IS NOT NULL
           AND p.id IS NULL'
    )
    : -1;

$guardNameColumn = $requirementsReady
    ? cpmsMapGuardNameColumn($db)
    : null;

$previewRows = [];

if ($requirementsReady) {
    $guardLabel = $guardNameColumn
        ? 'sg.`' . str_replace('`', '``', $guardNameColumn) . '`'
        : "CONCAT('Guard #', sg.id)";

    $previewSql = '
        SELECT
            sp.id AS patrol_id,
            sp.guard_id,
            ' . $guardLabel . ' AS guard_label,
            sg.property_id AS proposed_property_id,
            p.property_name
        FROM security_patrols sp
        INNER JOIN security_guards sg
          ON sg.id = sp.guard_id
        INNER JOIN cpms_properties p
          ON p.id = sg.property_id
        WHERE sp.property_id IS NULL
          AND sg.property_id IS NOT NULL
        ORDER BY sp.id ASC
        LIMIT 100
    ';

    $previewResult = $db->query($previewSql);
    if ($previewResult) {
        while ($row = $previewResult->fetch_assoc()) {
            $previewRows[] = $row;
        }
    }
}

$readyToApply = (
    $requirementsReady
    && $mappable > 0
    && $unmappable === 0
    && $invalidExisting === 0
);

$completed = (
    $requirementsReady
    && $nullProperty === 0
    && $invalidExisting === 0
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Smart Property Mapper</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1180px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}.notice{padding:14px;border-radius:10px;margin:16px 0}
.success{background:#eaf8ef;color:#146c34}.warning{background:#fff7e5;color:#805800}.error{background:#fdecec;color:#a51d18}
.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}.card strong{display:block;font-size:24px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:18px}
th,td{padding:11px;border-bottom:1px solid #e5ebf3;text-align:left;font-size:13px}
.pass{color:#08783b;font-weight:700}.fail{color:#b42318;font-weight:700}
button{border:0;background:#10213d;color:#fff;padding:12px 17px;border-radius:9px;font-weight:700;cursor:pointer}
button:disabled{background:#9aa6b6;cursor:not-allowed}
.req{columns:2;margin:16px 0}.req div{padding:5px}
@media(max-width:800px){main{margin:10px}.cards{grid-template-columns:1fr 1fr}.req{columns:1}table{display:block;overflow-x:auto}}
</style>
</head>
<body>
<main>
<h1>CPMS v2.0.8 Smart Property Mapper</h1>
<p class="muted">
Maps legacy security patrol records through security_guards.property_id.
</p>

<?php if ($message !== ''): ?>
<div class="notice <?= cpmsMapEsc($messageType) ?>">
<?= cpmsMapEsc($message) ?>
</div>
<?php endif; ?>

<?php if ($completed): ?>
<div class="notice success">
All security patrol records now have valid property assignments.
Run CPMS v2.0.7 Relationship Validator again.
</div>
<?php elseif ($readyToApply): ?>
<div class="notice warning">
All remaining NULL records can be mapped safely. Review the preview before applying.
</div>
<?php else: ?>
<div class="notice error">
Automatic mapping is not yet fully safe. Check the requirement and summary sections.
</div>
<?php endif; ?>

<h2>Requirements</h2>
<div class="req">
<?php foreach ($requirements as $label => $status): ?>
<div class="<?= $status ? 'pass' : 'fail' ?>">
<?= $status ? '✓' : '✕' ?> <?= cpmsMapEsc($label) ?>
</div>
<?php endforeach; ?>
</div>

<div class="cards">
<div class="card">Total Patrols<strong><?= (int) $totalPatrols ?></strong></div>
<div class="card">NULL Property<strong><?= (int) $nullProperty ?></strong></div>
<div class="card">Safely Mappable<strong><?= (int) $mappable ?></strong></div>
<div class="card">Unmappable<strong><?= (int) $unmappable ?></strong></div>
<div class="card">Invalid Existing<strong><?= (int) $invalidExisting ?></strong></div>
</div>

<form method="post" onsubmit="return confirm('Apply the proposed property mapping now?');">
<input type="hidden" name="csrf_token" value="<?= cpmsMapEsc($_SESSION['cpms_mapper_csrf']) ?>">
<input type="hidden" name="action" value="apply_mapping">
<button type="submit" <?= $readyToApply ? '' : 'disabled' ?>>
Apply Safe Mapping
</button>
</form>

<h2>Preview</h2>
<p class="muted">Shows up to the first 100 records that will be updated.</p>
<table>
<thead>
<tr>
<th>Patrol ID</th>
<th>Guard ID</th>
<th>Guard</th>
<th>Proposed Property ID</th>
<th>Property</th>
</tr>
</thead>
<tbody>
<?php if (!$previewRows): ?>
<tr><td colspan="5">No mappable records found.</td></tr>
<?php else: ?>
<?php foreach ($previewRows as $row): ?>
<tr>
<td><?= (int) $row['patrol_id'] ?></td>
<td><?= (int) $row['guard_id'] ?></td>
<td><?= cpmsMapEsc($row['guard_label']) ?></td>
<td><?= (int) $row['proposed_property_id'] ?></td>
<td><?= cpmsMapEsc($row['property_name']) ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<p class="muted">
The update runs inside a database transaction. Any invalid result causes an automatic rollback.
</p>
</main>
</body>
</html>
