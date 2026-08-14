<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/gps_attendance_service.php';
require_once __DIR__ . '/cpms/includes/attendance_shift_service.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$actorId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$sessionPropertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
if (!$isOwner && !cpmsCan('attendance.analytics.view', $conn)) {
    http_response_code(403);
    exit('Akses timesheet diperlukan.');
}
$properties = [];
$result = $conn->query(
    'SELECT id, property_name FROM cpms_properties ORDER BY property_name'
);
while ($result && ($row = $result->fetch_assoc())) {
    $properties[] = $row;
}
$propertyId = $isOwner
    ? (int) ($_REQUEST['property_id'] ?? ($properties[0]['id'] ?? 0))
    : $sessionPropertyId;
$month = (string) ($_REQUEST['month'] ?? date('Y-m'));
$selectedUserId = (int) ($_REQUEST['system_user_id'] ?? 0);
if ($propertyId < 1 || (!$isOwner && $propertyId !== $sessionPropertyId)
    || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(422);
    exit('Property atau bulan tidak sah.');
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $newStatus = (string) ($_POST['approval_status'] ?? '');
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if (!cpmsAttendanceVerifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Token keselamatan tidak sah.';
    } elseif (!$isOwner && !cpmsCan('attendance.timesheet.approve', $conn)) {
        $error = 'Akaun ini tidak boleh meluluskan timesheet.';
    } elseif (!in_array($newStatus, ['Draft', 'Approved', 'Locked'], true)) {
        $error = 'Status kelulusan tidak sah.';
    } else {
        $approver = $actorId > 0 ? $actorId : null;
        $approvedAt = $newStatus === 'Draft' ? null : date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "INSERT INTO cpms_attendance_monthly_approvals
             (property_id, attendance_month, approval_status, notes,
              approved_by_system_user_id, approved_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              approval_status = VALUES(approval_status),
              notes = VALUES(notes),
              approved_by_system_user_id =
                VALUES(approved_by_system_user_id),
              approved_at = VALUES(approved_at)"
        );
        $stmt->bind_param(
            'isssis', $propertyId, $month, $newStatus, $notes,
            $approver, $approvedAt
        );
        $stmt->execute();
        $stmt->close();
        $message = 'Status timesheet berjaya dikemas kini.';
    }
}
$approval = [
    'approval_status' => 'Draft', 'notes' => '',
    'approved_at' => null, 'approved_by' => null,
];
$stmt = $conn->prepare(
    "SELECT a.approval_status, a.notes, a.approved_at,
            u.full_name AS approved_by
     FROM cpms_attendance_monthly_approvals a
     LEFT JOIN system_users u ON u.id = a.approved_by_system_user_id
     WHERE a.property_id = ? AND a.attendance_month = ? LIMIT 1"
);
$stmt->bind_param('is', $propertyId, $month);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($row)) {
    $approval = $row;
}
$users = [];
$stmt = $conn->prepare(
    "SELECT DISTINCT u.id, u.full_name
     FROM system_users u
     JOIN user_roles ur ON ur.system_user_id = u.id
       AND ur.status = 'active'
     JOIN roles r ON r.id = ur.role_id
       AND r.role_code IN ('staff','security')
     WHERE u.property_id = ? AND u.status = 'active'
     ORDER BY u.full_name"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();
$summaries = [];
$sql = "SELECT a.system_user_id, u.full_name, a.user_role,
               COUNT(*) AS session_count,
               SUM(CASE WHEN a.late_minutes > 0 THEN 1 ELSE 0 END)
                   AS late_days,
               COALESCE(SUM(a.late_minutes),0) AS late_minutes,
               COALESCE(SUM(a.early_departure_minutes),0)
                   AS early_minutes,
               COALESCE(SUM(a.worked_minutes),0) AS worked_minutes,
               COALESCE(SUM(a.overtime_minutes),0) AS overtime_minutes
        FROM cpms_attendance_sessions a
        JOIN system_users u ON u.id = a.system_user_id
        WHERE a.property_id = ? AND DATE_FORMAT(a.work_date, '%Y-%m') = ?";
if ($selectedUserId > 0) {
    $sql .= ' AND a.system_user_id = ?';
}
$sql .= ' GROUP BY a.system_user_id, u.full_name, a.user_role
          ORDER BY u.full_name';
$stmt = $conn->prepare($sql);
if ($selectedUserId > 0) {
    $stmt->bind_param('isi', $propertyId, $month, $selectedUserId);
} else {
    $stmt->bind_param('is', $propertyId, $month);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $summaries[] = $row;
}
$stmt->close();
$details = [];
$sql = "SELECT a.work_date, a.clock_in_at, a.clock_out_at, a.status,
               a.late_minutes, a.early_departure_minutes,
               a.worked_minutes, a.overtime_minutes,
               u.full_name, a.user_role, s.shift_name
        FROM cpms_attendance_sessions a
        JOIN system_users u ON u.id = a.system_user_id
        LEFT JOIN cpms_attendance_shifts s ON s.id = a.resolved_shift_id
        WHERE a.property_id = ? AND DATE_FORMAT(a.work_date, '%Y-%m') = ?";
if ($selectedUserId > 0) {
    $sql .= ' AND a.system_user_id = ?';
}
$sql .= ' ORDER BY a.work_date DESC, u.full_name, a.clock_in_at DESC LIMIT 500';
$stmt = $conn->prepare($sql);
if ($selectedUserId > 0) {
    $stmt->bind_param('isi', $propertyId, $month, $selectedUserId);
} else {
    $stmt->bind_param('is', $propertyId, $month);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $details[] = $row;
}
$stmt->close();
$canApprove = $isOwner || cpmsCan('attendance.timesheet.approve', $conn);
$canExport = $isOwner || cpmsCan('attendance.payroll.export', $conn);
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Monthly Attendance Timesheet | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;padding:18px;background:#eef3f9;color:#10213d;font:14px Arial}
main{max-width:1200px;margin:auto}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:14px}
.filters{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}label{display:block;font-weight:700;margin:7px 0}
input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
button,.btn{display:inline-block;border:0;border-radius:8px;background:#174789;color:#fff;padding:11px 14px;
font-weight:700;text-decoration:none}.green{background:#07852f}.badge{display:inline-block;padding:7px 11px;
border-radius:20px;background:#e2e8f0;font-weight:700}.ok,.bad{padding:12px;border-radius:9px}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #e2e8f0;
text-align:left;white-space:nowrap}.scroll{overflow:auto}@media(max-width:700px){
.filters{grid-template-columns:1fr}body{padding:7px}}</style></head><body><main>
<section class="card"><h1>CPMS v3.4.7 — Monthly Attendance Timesheet</h1>
<p><a href="attendance_report.php?property_id=<?= $propertyId ?>">← Attendance Report</a></p>
<?php if ($message): ?><p class="ok"><?= cpmsAttendanceEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsAttendanceEscape($error) ?></p><?php endif; ?>
<form method="get"><div class="filters">
<?php if ($isOwner): ?><div><label>Property</label><select name="property_id">
<?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"
<?= (int) $property['id'] === $propertyId ? 'selected' : '' ?>><?=
cpmsAttendanceEscape((string) $property['property_name']) ?></option><?php endforeach; ?>
</select></div><?php else: ?><input type="hidden" name="property_id" value="<?= $propertyId ?>"><?php endif; ?>
<div><label>Bulan</label><input type="month" name="month" value="<?= cpmsAttendanceEscape($month) ?>"></div>
<div><label>Staff/Security</label><select name="system_user_id"><option value="0">Semua</option>
<?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"
<?= (int) $user['id'] === $selectedUserId ? 'selected' : '' ?>><?=
cpmsAttendanceEscape((string) $user['full_name']) ?></option><?php endforeach; ?></select></div>
</div><p><button>Papar Timesheet</button>
<?php if ($canExport): ?><a class="btn green" href="attendance_timesheet_export.php?property_id=<?=
$propertyId ?>&month=<?= urlencode($month) ?>&system_user_id=<?= $selectedUserId ?>">Export CSV</a><?php endif; ?></p>
</form><p>Status bulan: <span class="badge"><?= cpmsAttendanceEscape(
    (string) $approval['approval_status']
) ?></span><?php if ($approval['approved_by']): ?> · <?= cpmsAttendanceEscape(
    (string) $approval['approved_by']
) ?> · <?= cpmsAttendanceEscape((string) $approval['approved_at']) ?><?php endif; ?></p></section>
<?php if ($canApprove): ?><section class="card"><h2>Kelulusan Timesheet</h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="property_id" value="<?= $propertyId ?>"><input type="hidden"
name="month" value="<?= cpmsAttendanceEscape($month) ?>"><input type="hidden"
name="system_user_id" value="<?= $selectedUserId ?>"><div class="filters">
<div><label>Status</label><select name="approval_status">
<?php foreach (['Draft','Approved','Locked'] as $status): ?><option
<?= $approval['approval_status'] === $status ? 'selected' : '' ?>><?= $status ?></option>
<?php endforeach; ?></select></div><div style="grid-column:span 2"><label>Catatan</label>
<textarea name="notes"><?= cpmsAttendanceEscape((string) $approval['notes']) ?></textarea></div>
</div><p><button>Simpan Status</button></p></form></section><?php endif; ?>
<section class="card"><h2>Ringkasan Bulanan</h2><div class="scroll"><table>
<tr><th>Nama</th><th>Peranan</th><th>Sesi</th><th>Hari Lewat</th><th>Jumlah Lewat</th>
<th>Keluar Awal</th><th>Masa Kerja</th><th>Overtime</th></tr>
<?php if (!$summaries): ?><tr><td colspan="8">Tiada rekod untuk bulan ini.</td></tr><?php endif; ?>
<?php foreach ($summaries as $row): ?><tr><td><?= cpmsAttendanceEscape(
    (string) $row['full_name']
) ?></td><td><?= cpmsAttendanceEscape((string) $row['user_role']) ?></td>
<td><?= (int) $row['session_count'] ?></td><td><?= (int) $row['late_days'] ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['late_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['early_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['worked_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['overtime_minutes']) ?></td></tr><?php endforeach; ?>
</table></div></section><section class="card"><h2>Rekod Harian</h2><div class="scroll"><table>
<tr><th>Tarikh</th><th>Nama</th><th>Syif</th><th>Masuk</th><th>Keluar</th><th>Lewat</th>
<th>Keluar Awal</th><th>Kerja</th><th>OT</th><th>Status</th></tr>
<?php if (!$details): ?><tr><td colspan="10">Tiada rekod.</td></tr><?php endif; ?>
<?php foreach ($details as $row): ?><tr><td><?= cpmsAttendanceEscape(
    date('d/m/Y', strtotime((string) $row['work_date']))
) ?></td><td><?= cpmsAttendanceEscape((string) $row['full_name']) ?></td>
<td><?= $row['shift_name'] ? cpmsAttendanceEscape((string) $row['shift_name']) : '-' ?></td>
<td><?= cpmsAttendanceEscape(date('h:i A', strtotime((string) $row['clock_in_at']))) ?></td>
<td><?= $row['clock_out_at'] ? cpmsAttendanceEscape(date(
    'h:i A', strtotime((string) $row['clock_out_at'])
)) : '-' ?></td><td><?= cpmsShiftMinutesLabel((int) $row['late_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['early_departure_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['worked_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['overtime_minutes']) ?></td>
<td><?= cpmsAttendanceEscape((string) $row['status']) ?></td></tr><?php endforeach; ?>
</table></div></section></main></body></html>
