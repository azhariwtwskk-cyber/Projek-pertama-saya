<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/gps_attendance_service.php';
require_once __DIR__ . '/cpms/includes/attendance_shift_service.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$sessionPropertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
if (!$isOwner && !cpmsCan('attendance.view', $conn)) {
    http_response_code(403);
    exit('Akses laporan kedatangan diperlukan.');
}
$propertyId = $isOwner
    ? (int) ($_GET['property_id'] ?? 0) : $sessionPropertyId;
if ($propertyId < 1) {
    http_response_code(422);
    exit('Pilih property melalui halaman Geofence Setup.');
}
$propertyName = 'Property ' . $propertyId;
$stmt = $conn->prepare(
    'SELECT property_name FROM cpms_properties WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($row)) {
    $propertyName = (string) $row['property_name'];
}
$records = [];
$stmt = $conn->prepare(
    "SELECT a.work_date, a.clock_in_at, a.clock_out_at, a.status,
            a.clock_in_distance_m, a.clock_out_distance_m,
            a.user_role, u.full_name, a.late_minutes,
            a.early_departure_minutes, a.worked_minutes,
            a.overtime_minutes, s.shift_name
     FROM cpms_attendance_sessions a
     JOIN system_users u ON u.id = a.system_user_id
     LEFT JOIN cpms_attendance_shifts s ON s.id = a.resolved_shift_id
     WHERE a.property_id = ?
     ORDER BY a.clock_in_at DESC LIMIT 100"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}
$stmt->close();
$summary = ['sessions' => 0, 'late_count' => 0, 'late_minutes' => 0,
    'worked_minutes' => 0, 'overtime_minutes' => 0];
$stmt = $conn->prepare(
    "SELECT COUNT(*) sessions,
            SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) late_count,
            COALESCE(SUM(late_minutes),0) late_minutes,
            COALESCE(SUM(worked_minutes),0) worked_minutes,
            COALESCE(SUM(overtime_minutes),0) overtime_minutes
     FROM cpms_attendance_sessions
     WHERE property_id = ?
       AND work_date >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$summaryRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($summaryRow)) {
    $summary = $summaryRow;
}
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Report | CPMS</title><style>
body{font:14px Arial;background:#eef3f9;color:#10213d;margin:0;padding:22px}
main{max-width:1100px;margin:auto;background:#fff;padding:24px;border-radius:16px}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;
text-align:left;white-space:nowrap}.scroll{overflow:auto}a{color:#174789}.stats{display:grid;
grid-template-columns:repeat(4,1fr);gap:10px;margin:18px 0}.stat{background:#eef3f9;
border-radius:10px;padding:13px}.stat strong{font-size:21px;display:block}
@media(max-width:700px){.stats{grid-template-columns:repeat(2,1fr)}body{padding:8px}}
</style></head><body><main><a href="attendance_geofence_setup.php?property_id=<?=
$propertyId ?>">← Geofence Setup</a><h1>GPS Attendance Report</h1>
<p><?= cpmsAttendanceEscape($propertyName) ?> · 100 rekod terkini</p>
<p><a href="attendance_shift_setup.php?property_id=<?= $propertyId ?>">Tetapan Syif</a> ·
<a href="attendance_shift_assignments.php?property_id=<?= $propertyId ?>">Assignment Syif</a> ·
<a href="attendance_timesheet.php?property_id=<?= $propertyId ?>">Monthly Timesheet</a></p>
<div class="stats"><div class="stat"><strong><?= (int) $summary['sessions'] ?></strong>Sesi bulan ini</div>
<div class="stat"><strong><?= (int) $summary['late_count'] ?></strong>Rekod lewat ·
<?= cpmsShiftMinutesLabel((int) $summary['late_minutes']) ?></div>
<div class="stat"><strong><?= cpmsShiftMinutesLabel((int) $summary['worked_minutes']) ?></strong>Masa bekerja</div>
<div class="stat"><strong><?= cpmsShiftMinutesLabel((int) $summary['overtime_minutes']) ?></strong>Overtime</div></div>
<div class="scroll"><table><tr><th>Nama</th><th>Peranan</th><th>Tarikh</th><th>Syif</th>
<th>Clock In</th><th>Clock Out</th><th>Lewat</th><th>Keluar Awal</th>
<th>Kerja</th><th>OT</th><th>Status</th></tr>
<?php if (!$records): ?><tr><td colspan="11">Belum ada rekod.</td></tr>
<?php else: foreach ($records as $record): ?><tr>
<td><?= cpmsAttendanceEscape((string) $record['full_name']) ?></td>
<td><?= cpmsAttendanceEscape((string) $record['user_role']) ?></td>
<td><?= cpmsAttendanceEscape(date('d/m/Y', strtotime((string) $record['work_date']))) ?></td>
<td><?= $record['shift_name']
    ? cpmsAttendanceEscape((string) $record['shift_name']) : '-' ?></td>
<td><?= cpmsAttendanceEscape(date('h:i A', strtotime((string) $record['clock_in_at']))) ?></td>
<td><?= $record['clock_out_at'] ? cpmsAttendanceEscape(date(
    'h:i A', strtotime((string) $record['clock_out_at'])
)) : '-' ?></td>
<td><?= cpmsShiftMinutesLabel((int) $record['late_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $record['early_departure_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $record['worked_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $record['overtime_minutes']) ?></td>
<td><?= cpmsAttendanceEscape((string) $record['status']) ?></td>
</tr><?php endforeach; endif; ?></table></div></main></body></html>
