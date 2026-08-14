<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
if ($role !== 'system_owner' && !isset($_SESSION['system_owner_id'])) {
    http_response_code(403);
    exit('System Owner access required.');
}
$tables = [
    'cpms_attendance_rotation_plans',
    'cpms_attendance_rotation_items',
    'cpms_attendance_rotation_assignments',
];
$status = [];
foreach ($tables as $table) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) total FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $status[$table] = (int) $stmt->get_result()->fetch_assoc()['total'] === 1;
    $stmt->close();
}
$columns = ['rotation_assignment_id', 'resolved_shift_id'];
foreach ($columns as $column) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'cpms_attendance_sessions'
           AND column_name = ?"
    );
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $status['sessions.' . $column] =
        (int) $stmt->get_result()->fetch_assoc()['total'] === 1;
    $stmt->close();
}
$pass = !in_array(false, $status, true);
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport"
content="width=device-width,initial-scale=1"><title>Rotation Health | CPMS</title>
<style>body{font:16px Arial;background:#eef3f9;color:#10213d;padding:24px}
main{max-width:800px;margin:auto}.card{background:#fff;border-radius:16px;padding:25px;margin-bottom:15px}
.pass{color:#07852f}.fail{color:#b42318}table{width:100%;border-collapse:collapse}
td{padding:11px;border-bottom:1px solid #e2e8f0}</style></head><body><main>
<section class="card"><h1>CPMS v3.4.6.1 — Security Rotation Health</h1>
<h2 class="<?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? 'PASS' : 'FAIL' ?></h2>
<p><a href="attendance_rotation_setup.php">Configure Security Rotation</a></p></section>
<section class="card"><table><?php foreach ($status as $name => $ready): ?>
<tr><td><?= htmlspecialchars($name) ?></td><td class="<?= $ready ? 'pass' : 'fail' ?>">
<?= $ready ? 'Ready' : 'Missing' ?></td></tr><?php endforeach; ?></table></section>
</main></body></html>
