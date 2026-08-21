<?php
declare(strict_types=1);

session_start();
require_once 'db.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
if ($role !== 'system_owner' && !isset($_SESSION['system_owner_id'])) {
    http_response_code(403);
    exit('System Owner access required.');
}
$requiredTables = [
    'cpms_attendance_shifts',
    'cpms_attendance_shift_assignments',
    'cpms_attendance_sessions',
];
$requiredColumns = [
    'shift_assignment_id', 'scheduled_start_at', 'scheduled_end_at',
    'late_minutes', 'early_departure_minutes', 'worked_minutes',
    'overtime_minutes',
];
$tableStatus = [];
foreach ($requiredTables as $table) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) total FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $tableStatus[$table] = (int) ($row['total'] ?? 0) === 1;
}
$columnStatus = [];
foreach ($requiredColumns as $column) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'cpms_attendance_sessions'
           AND column_name = ?"
    );
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $columnStatus[$column] = (int) ($row['total'] ?? 0) === 1;
}
$permissionCount = 0;
$result = $conn->query(
    "SELECT COUNT(*) total FROM permissions WHERE permission_code IN
     ('attendance.shift.manage','attendance.analytics.view')"
);
if ($result && ($row = $result->fetch_assoc())) {
    $permissionCount = (int) $row['total'];
}
$shiftCount = $assignmentCount = 0;
if ($tableStatus['cpms_attendance_shifts']) {
    $result = $conn->query('SELECT COUNT(*) total FROM cpms_attendance_shifts');
    $shiftCount = $result ? (int) $result->fetch_assoc()['total'] : 0;
}
if ($tableStatus['cpms_attendance_shift_assignments']) {
    $result = $conn->query(
        'SELECT COUNT(*) total FROM cpms_attendance_shift_assignments'
    );
    $assignmentCount = $result ? (int) $result->fetch_assoc()['total'] : 0;
}
$pass = !in_array(false, $tableStatus, true)
    && !in_array(false, $columnStatus, true) && $permissionCount === 2;
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport"
content="width=device-width,initial-scale=1"><title>Attendance Shift Health | CPMS</title>
<style>body{font:16px Arial;background:#eef3f9;color:#10213d;margin:0;padding:24px}
main{max-width:850px;margin:auto}.card{background:#fff;border-radius:16px;padding:25px;margin-bottom:15px}
h1{font-size:31px}.pass{color:#07852f}.fail{color:#b42318}table{width:100%;border-collapse:collapse}
td{padding:11px;border-bottom:1px solid #e2e8f0}a{color:#174789}</style></head><body><main>
<section class="card"><h1>CPMS v3.4.6 — Attendance Shift Health</h1>
<h2 class="<?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? 'PASS' : 'FAIL' ?></h2>
<p>Shifts: <?= $shiftCount ?> · Assignments: <?= $assignmentCount ?> ·
Permissions: <?= $permissionCount ?>/2</p>
<p><a href="attendance_shift_setup.php">Configure Attendance Shifts</a></p></section>
<section class="card"><table>
<?php foreach ($tableStatus as $name => $ready): ?><tr><td><?= htmlspecialchars($name) ?></td>
<td class="<?= $ready ? 'pass' : 'fail' ?>"><?= $ready ? 'Ready' : 'Missing' ?></td></tr>
<?php endforeach; foreach ($columnStatus as $name => $ready): ?><tr>
<td>sessions.<?= htmlspecialchars($name) ?></td><td class="<?= $ready ? 'pass' : 'fail' ?>">
<?= $ready ? 'Ready' : 'Missing' ?></td></tr><?php endforeach; ?>
</table></section></main></body></html>
