<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';
require_once dirname(__DIR__) . '/core_v2/migration_engine.php';

$user = cpmsV2User();
if (!$user || (string) ($user['role'] ?? '') !== 'system_owner') {
    http_response_code(403);
    exit('System Owner access required.');
}

$conn = cpmsV2Database();
$engine = new CpmsV2MigrationEngine(
    $conn,
    dirname(__DIR__) . '/migrations_v2',
    dirname(__DIR__) . '/storage/migration_snapshots'
);

$message = '';
$error = '';
$resultPayload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $confirmation = (string) ($_POST['confirmation'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');

    if (!isset($_SESSION['cpms_migration_csrf'])) {
        $_SESSION['cpms_migration_csrf'] = bin2hex(random_bytes(24));
    }

    if (!hash_equals((string) $_SESSION['cpms_migration_csrf'], $csrf)) {
        $error = 'Invalid security token. Refresh the page and try again.';
    } else {
        try {
            $username = (string) ($user['username'] ?? $user['name'] ?? 'system_owner');

            if ($action === 'initialize') {
                $engine->ensureRegistry();
                $message = 'Migration registry initialized.';
            } elseif ($action === 'snapshot') {
                $file = $engine->createSchemaSnapshot('manual');
                $message = 'Schema snapshot created: ' . basename($file);
            } elseif ($action === 'migrate') {
                if ($confirmation !== 'MIGRATE') {
                    throw new RuntimeException('Type MIGRATE to confirm.');
                }
                $resultPayload = $engine->migrate($username);
                $message = (string) $resultPayload['message'];
            } elseif ($action === 'rollback') {
                if ($confirmation !== 'ROLLBACK') {
                    throw new RuntimeException('Type ROLLBACK to confirm.');
                }
                $resultPayload = $engine->rollbackLastBatch($username);
                $message = (string) $resultPayload['message'];
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

if (!isset($_SESSION['cpms_migration_csrf'])) {
    $_SESSION['cpms_migration_csrf'] = bin2hex(random_bytes(24));
}

try {
    $engine->ensureRegistry();
    $health = $engine->health();
    $status = $engine->status();
} catch (Throwable $exception) {
    $health = [];
    $status = [];
    $error = $error !== '' ? $error : $exception->getMessage();
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Migration Engine</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:1050px;margin:30px auto;background:#fff;border:1px solid #dce4ef;border-radius:18px;padding:26px}
h1{margin:0 0 6px}.muted{color:#61718a}.notice{padding:13px;border-radius:10px;margin:15px 0}
.ok{background:#eaf8ef;color:#146c34}.bad{background:#fff0f0;color:#9b1c1c}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}
.card{background:#f7f9fc;padding:15px;border-radius:12px}.card strong{display:block;font-size:22px;margin-top:6px}
.actions{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin:20px 0}
form{background:#f7f9fc;padding:16px;border-radius:12px}
input{padding:10px;width:calc(100% - 22px);margin:8px 0;border:1px solid #cbd5e1;border-radius:8px}
button{border:0;background:#10213d;color:#fff;padding:11px 15px;border-radius:8px;font-weight:700;cursor:pointer}
.danger{background:#a61b1b}table{width:100%;border-collapse:collapse;margin-top:18px}
th,td{padding:11px;border-bottom:1px solid #e5ebf3;text-align:left;font-size:13px}
.pass{color:#08783b;font-weight:700}.warn{color:#a36b00;font-weight:700}
@media(max-width:760px){main{margin:10px}.cards,.actions{grid-template-columns:1fr 1fr}}
</style>
</head>
<body><main>
<h1>CPMS v2.0.5 Migration Engine</h1>
<p class="muted">Controlled migration, schema snapshots and last-batch rollback.</p>

<?php if ($message !== ''): ?>
<div class="notice ok"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="notice bad"><?= e($error) ?></div>
<?php endif; ?>

<div class="notice bad">
<strong>Important:</strong> Schema snapshots do not contain table data.
Create a full database export in phpMyAdmin before running real migrations.
</div>

<div class="cards">
<div class="card">Database<strong><?= e($health['database'] ?? '-') ?></strong></div>
<div class="card">Available<strong><?= (int) ($health['available_migrations'] ?? 0) ?></strong></div>
<div class="card">Applied<strong><?= (int) ($health['applied_migrations'] ?? 0) ?></strong></div>
<div class="card">Snapshot Writable<strong><?= !empty($health['snapshot_writable']) ? 'Yes' : 'No' ?></strong></div>
</div>

<div class="actions">
<form method="post">
<input type="hidden" name="csrf" value="<?= e($_SESSION['cpms_migration_csrf']) ?>">
<input type="hidden" name="action" value="snapshot">
<h3>Create Schema Snapshot</h3>
<p class="muted">Records all CREATE TABLE definitions before changes.</p>
<button type="submit">Create Snapshot</button>
</form>

<form method="post">
<input type="hidden" name="csrf" value="<?= e($_SESSION['cpms_migration_csrf']) ?>">
<input type="hidden" name="action" value="migrate">
<h3>Run Pending Migrations</h3>
<input name="confirmation" placeholder="Type MIGRATE">
<button type="submit">Run Migration</button>
</form>

<form method="post">
<input type="hidden" name="csrf" value="<?= e($_SESSION['cpms_migration_csrf']) ?>">
<input type="hidden" name="action" value="rollback">
<h3>Rollback Last Batch</h3>
<input name="confirmation" placeholder="Type ROLLBACK">
<button class="danger" type="submit">Rollback</button>
</form>
</div>

<table>
<thead><tr><th>Migration</th><th>Name</th><th>Status</th><th>Checksum</th><th>Applied</th></tr></thead>
<tbody>
<?php if (!$status): ?>
<tr><td colspan="5">No migration files found.</td></tr>
<?php endif; ?>
<?php foreach ($status as $row): ?>
<tr>
<td><?= e($row['key']) ?></td>
<td><?= e($row['name']) ?></td>
<td class="<?= $row['state'] === 'applied' ? 'pass' : 'warn' ?>"><?= e($row['state']) ?></td>
<td><?= $row['checksum_match'] ? 'Valid' : 'Changed' ?></td>
<td><?= e($row['applied_at'] ?? '-') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="muted">After verification, delete or rename this installer page.</p>
</main></body>
</html>
