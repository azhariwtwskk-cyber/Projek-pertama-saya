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

if (empty($_SESSION['cpms_guard_property_csrf'])) {
    $_SESSION['cpms_guard_property_csrf'] = bin2hex(random_bytes(24));
}

function cpmsGpEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsGpColumnExists(
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

function cpmsGpNameColumn(mysqli $db): ?string
{
    foreach (['name', 'full_name', 'guard_name', 'username'] as $column) {
        if (cpmsGpColumnExists($db, 'security_guards', $column)) {
            return $column;
        }
    }

    return null;
}

function cpmsGpActiveColumn(mysqli $db): ?string
{
    foreach (['status', 'is_active', 'active'] as $column) {
        if (cpmsGpColumnExists($db, 'security_guards', $column)) {
            return $column;
        }
    }

    return null;
}

$columnReady = cpmsGpColumnExists(
    $db,
    'security_guards',
    'property_id'
);

$message = '';
$messageType = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
) {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['cpms_guard_property_csrf'], $token)) {
        $message = 'Invalid security token. Refresh and try again.';
        $messageType = 'error';
    } elseif (!$columnReady) {
        $message = 'Run migration 20260727_0003 first.';
        $messageType = 'error';
    } elseif ($_POST['action'] === 'assign_selected') {
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        $guardIds = array_values(
            array_filter(
                array_map('intval', (array) ($_POST['guard_ids'] ?? [])),
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        if ($propertyId <= 0 || !$guardIds) {
            $message = 'Select at least one guard and one property.';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM cpms_properties
                 WHERE id = ?'
            );

            if (!$stmt) {
                $message = $db->error;
                $messageType = 'error';
            } else {
                $stmt->bind_param('i', $propertyId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : [];
                $stmt->close();

                if ((int) ($row['total'] ?? 0) !== 1) {
                    $message = 'Selected property does not exist.';
                    $messageType = 'error';
                } else {
                    try {
                        $db->begin_transaction();

                        $update = $db->prepare(
                            'UPDATE security_guards
                             SET property_id = ?
                             WHERE id = ?'
                        );

                        if (!$update) {
                            throw new RuntimeException($db->error);
                        }

                        $updated = 0;

                        foreach ($guardIds as $guardId) {
                            $update->bind_param(
                                'ii',
                                $propertyId,
                                $guardId
                            );

                            if (!$update->execute()) {
                                throw new RuntimeException($update->error);
                            }

                            $updated += $update->affected_rows;
                        }

                        $update->close();
                        $db->commit();

                        $message = sprintf(
                            'Property assigned successfully to %d guard record(s).',
                            $updated
                        );
                        $messageType = 'success';
                    } catch (Throwable $exception) {
                        $db->rollback();

                        $message = 'Assignment failed and was rolled back: '
                            . $exception->getMessage();
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

$properties = [];
$propertyResult = $db->query(
    'SELECT id, property_name
     FROM cpms_properties
     ORDER BY property_name ASC, id ASC'
);

if ($propertyResult) {
    while ($row = $propertyResult->fetch_assoc()) {
        $properties[] = $row;
    }
}

$nameColumn = cpmsGpNameColumn($db);
$activeColumn = cpmsGpActiveColumn($db);
$guards = [];

if ($columnReady) {
    $nameSql = $nameColumn
        ? '`' . str_replace('`', '``', $nameColumn) . '`'
        : "CONCAT('Guard #', id)";

    $activeSql = $activeColumn
        ? '`' . str_replace('`', '``', $activeColumn) . '`'
        : "'N/A'";

    $guardSql = '
        SELECT
            id,
            ' . $nameSql . ' AS guard_name,
            property_id,
            ' . $activeSql . ' AS active_value
        FROM security_guards
        ORDER BY
            CASE WHEN property_id IS NULL THEN 0 ELSE 1 END,
            id ASC
    ';

    $guardResult = $db->query($guardSql);

    if ($guardResult) {
        while ($row = $guardResult->fetch_assoc()) {
            $guards[] = $row;
        }
    }
}

$propertyMap = [];
foreach ($properties as $property) {
    $propertyMap[(int) $property['id']] = (string) $property['property_name'];
}

$unassigned = 0;
foreach ($guards as $guard) {
    if ($guard['property_id'] === null) {
        $unassigned++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Security Guard Property Assignment</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1050px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}.notice{padding:14px;border-radius:10px;margin:16px 0}
.success{background:#eaf8ef;color:#146c34}.warning{background:#fff7e5;color:#805800}.error{background:#fdecec;color:#a51d18}
.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}.card strong{display:block;font-size:24px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:18px}
th,td{padding:11px;border-bottom:1px solid #e5ebf3;text-align:left;font-size:13px}
select{padding:10px;border:1px solid #cbd5e1;border-radius:8px;min-width:260px}
button{border:0;background:#10213d;color:#fff;padding:11px 16px;border-radius:9px;font-weight:700;cursor:pointer}
.toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:18px 0}
.unassigned{color:#b42318;font-weight:700}.assigned{color:#08783b;font-weight:700}
@media(max-width:760px){main{margin:10px}.cards{grid-template-columns:1fr}table{display:block;overflow-x:auto}}
</style>
<script>
function toggleAll(source) {
    document.querySelectorAll('input[name="guard_ids[]"]').forEach(function (box) {
        box.checked = source.checked;
    });
}
</script>
</head>
<body>
<main>
<h1>CPMS v2.0.9 Security Guard Property Assignment</h1>
<p class="muted">
Assign each security guard to the correct property before mapping patrol records.
</p>

<?php if ($message !== ''): ?>
<div class="notice <?= cpmsGpEsc($messageType) ?>">
<?= cpmsGpEsc($message) ?>
</div>
<?php endif; ?>

<?php if (!$columnReady): ?>
<div class="notice error">
The security_guards.property_id column is missing.
Open Migration Manager and run migration 20260727_0003.
</div>
<?php elseif ($unassigned === 0): ?>
<div class="notice success">
All guards have a property assignment. You may now run Smart Property Mapper v2.0.8.
</div>
<?php else: ?>
<div class="notice warning">
<?= (int) $unassigned ?> guard record(s) still require a property assignment.
</div>
<?php endif; ?>

<div class="cards">
<div class="card">Guards<strong><?= count($guards) ?></strong></div>
<div class="card">Unassigned<strong><?= (int) $unassigned ?></strong></div>
<div class="card">Properties<strong><?= count($properties) ?></strong></div>
</div>

<?php if ($columnReady): ?>
<form method="post" onsubmit="return confirm('Assign the selected property to the selected guards?');">
<input type="hidden" name="csrf_token" value="<?= cpmsGpEsc($_SESSION['cpms_guard_property_csrf']) ?>">
<input type="hidden" name="action" value="assign_selected">

<div class="toolbar">
<select name="property_id" required>
<option value="">Select property</option>
<?php foreach ($properties as $property): ?>
<option value="<?= (int) $property['id'] ?>">
<?= cpmsGpEsc($property['property_name']) ?> — ID <?= (int) $property['id'] ?>
</option>
<?php endforeach; ?>
</select>
<button type="submit">Assign Selected Guards</button>
</div>

<table>
<thead>
<tr>
<th><input type="checkbox" onchange="toggleAll(this)"></th>
<th>Guard ID</th>
<th>Guard</th>
<th>Status</th>
<th>Current Property</th>
</tr>
</thead>
<tbody>
<?php if (!$guards): ?>
<tr><td colspan="5">No security guards found.</td></tr>
<?php else: ?>
<?php foreach ($guards as $guard): ?>
<?php
$propertyId = $guard['property_id'] === null
    ? null
    : (int) $guard['property_id'];
?>
<tr>
<td>
<input type="checkbox" name="guard_ids[]" value="<?= (int) $guard['id'] ?>">
</td>
<td><?= (int) $guard['id'] ?></td>
<td><?= cpmsGpEsc($guard['guard_name']) ?></td>
<td><?= cpmsGpEsc($guard['active_value']) ?></td>
<td class="<?= $propertyId === null ? 'unassigned' : 'assigned' ?>">
<?php if ($propertyId === null): ?>
Not assigned
<?php else: ?>
<?= cpmsGpEsc($propertyMap[$propertyId] ?? ('Unknown ID ' . $propertyId)) ?>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</form>
<?php endif; ?>

<p class="muted">
This tool does not guess the property. Select the correct property manually.
Existing assignments can be changed only when you deliberately select those guards.
</p>
</main>
</body>
</html>
