<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/gps_attendance_service.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$sessionPropertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
if (!$isOwner && !cpmsCan('attendance.shift.manage', $conn)) {
    http_response_code(403);
    exit('Akses pengurusan syif diperlukan.');
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
if ($propertyId < 1 || (!$isOwner && $propertyId !== $sessionPropertyId)) {
    http_response_code(403);
    exit('Property tidak sah.');
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $action = (string) ($_POST['action'] ?? 'save');
    if (!cpmsAttendanceVerifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Token keselamatan tidak sah.';
    } elseif ($action === 'deactivate') {
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE cpms_attendance_shifts SET status = 'inactive'
             WHERE id = ? AND property_id = ?"
        );
        $stmt->bind_param('ii', $shiftId, $propertyId);
        $stmt->execute();
        $stmt->close();
        $message = 'Syif dinyahaktifkan.';
    } else {
        $name = trim((string) ($_POST['shift_name'] ?? ''));
        $start = (string) ($_POST['start_time'] ?? '');
        $end = (string) ($_POST['end_time'] ?? '');
        $days = $_POST['working_days'] ?? [];
        $validDays = [];
        if (is_array($days)) {
            foreach ($days as $day) {
                $number = (int) $day;
                if ($number >= 1 && $number <= 7) {
                    $validDays[$number] = $number;
                }
            }
        }
        ksort($validDays);
        $workingDays = implode(',', $validDays);
        $grace = (int) ($_POST['grace_minutes'] ?? 10);
        $break = (int) ($_POST['break_minutes'] ?? 60);
        $minimumOt = (int) ($_POST['minimum_overtime_minutes'] ?? 30);
        if ($name === '' || !preg_match('/^\d{2}:\d{2}$/', $start)
            || !preg_match('/^\d{2}:\d{2}$/', $end) || $workingDays === ''
            || $grace < 0 || $grace > 180 || $break < 0 || $break > 300
            || $minimumOt < 0 || $minimumOt > 300) {
            $error = 'Semak nama, waktu, hari bekerja dan nilai minit.';
        } else {
            $creator = $userId > 0 ? $userId : null;
            $stmt = $conn->prepare(
                "INSERT INTO cpms_attendance_shifts
                 (property_id, shift_name, start_time, end_time, working_days,
                  grace_minutes, break_minutes, minimum_overtime_minutes,
                  status, created_by_system_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
            );
            $stmt->bind_param(
                'issssiiii', $propertyId, $name, $start, $end, $workingDays,
                $grace, $break, $minimumOt, $creator
            );
            $stmt->execute();
            $stmt->close();
            $message = 'Syif baharu berjaya dicipta.';
        }
    }
}
$shifts = [];
$stmt = $conn->prepare(
    'SELECT * FROM cpms_attendance_shifts
     WHERE property_id = ? ORDER BY status, start_time, shift_name'
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shifts[] = $row;
}
$stmt->close();
$dayNames = [1 => 'Isn', 2 => 'Sel', 3 => 'Rab', 4 => 'Kha',
    5 => 'Jum', 6 => 'Sab', 7 => 'Aha'];
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attendance Shift Setup | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;padding:20px;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1050px;margin:auto}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:15px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:13px}label{display:block;font-weight:700;margin:10px 0 5px}
input,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
.days{display:flex;flex-wrap:wrap;gap:9px}.days label{font-weight:400}.days input{width:auto}
button,.btn{border:0;border-radius:8px;background:#174789;color:#fff;padding:11px 14px;
font-weight:700;text-decoration:none;display:inline-block}.danger{background:#b42318}.ok,.bad{padding:12px;border-radius:9px}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}
.scroll{overflow:auto}@media(max-width:650px){.grid{grid-template-columns:1fr}body{padding:10px}}
</style></head><body><main>
<section class="card"><h1>CPMS v3.4.6 — Attendance Shift Setup</h1>
<p><a href="attendance_geofence_setup.php?property_id=<?= $propertyId ?>">← Geofence</a> ·
<a href="attendance_shift_assignments.php?property_id=<?= $propertyId ?>">Assignment Pekerja</a> ·
<a href="attendance_rotation_setup.php?property_id=<?= $propertyId ?>">Rotation Security</a> ·
<a href="attendance_report.php?property_id=<?= $propertyId ?>">Laporan</a></p>
<?php if ($isOwner): ?><label>Property</label><select onchange="location.href='?property_id='+this.value">
<?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"
<?= (int) $property['id'] === $propertyId ? 'selected' : '' ?>>
<?= cpmsAttendanceEscape((string) $property['property_name']) ?></option><?php endforeach; ?>
</select><?php endif; ?>
<?php if ($message): ?><p class="ok"><?= cpmsAttendanceEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsAttendanceEscape($error) ?></p><?php endif; ?>
</section>
<section class="card"><h2>Cipta Syif</h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="property_id" value="<?= $propertyId ?>">
<div class="grid"><div><label>Nama syif</label><input name="shift_name"
placeholder="Contoh: Syif Pagi" required></div><div><label>Waktu</label>
<div class="grid"><input name="start_time" type="time" required>
<input name="end_time" type="time" required></div></div>
<div><label>Grace lewat (minit)</label><input name="grace_minutes" type="number"
min="0" max="180" value="10"></div><div><label>Waktu rehat (minit)</label>
<input name="break_minutes" type="number" min="0" max="300" value="60"></div>
<div><label>Minimum overtime (minit)</label><input name="minimum_overtime_minutes"
type="number" min="0" max="300" value="30"></div></div>
<label>Hari bekerja</label><div class="days">
<?php foreach ($dayNames as $number => $name): ?><label><input type="checkbox"
name="working_days[]" value="<?= $number ?>" <?= $number <= 5 ? 'checked' : '' ?>>
<?= $name ?></label><?php endforeach; ?></div><p><button>Simpan Syif</button></p>
</form></section>
<section class="card"><h2>Senarai Syif</h2><div class="scroll"><table>
<tr><th>Syif</th><th>Waktu</th><th>Hari</th><th>Grace</th><th>Rehat</th><th>OT Min.</th><th>Status</th><th></th></tr>
<?php if (!$shifts): ?><tr><td colspan="8">Belum ada syif.</td></tr><?php endif; ?>
<?php foreach ($shifts as $shift): ?><tr>
<td><?= cpmsAttendanceEscape((string) $shift['shift_name']) ?></td>
<td><?= cpmsAttendanceEscape(substr((string) $shift['start_time'],0,5)) ?> –
<?= cpmsAttendanceEscape(substr((string) $shift['end_time'],0,5)) ?></td>
<td><?= cpmsAttendanceEscape((string) $shift['working_days']) ?></td>
<td><?= (int) $shift['grace_minutes'] ?>m</td><td><?= (int) $shift['break_minutes'] ?>m</td>
<td><?= (int) $shift['minimum_overtime_minutes'] ?>m</td>
<td><?= cpmsAttendanceEscape((string) $shift['status']) ?></td><td>
<?php if ($shift['status'] === 'active'): ?><form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="property_id" value="<?= $propertyId ?>">
<input type="hidden" name="action" value="deactivate"><input type="hidden"
name="shift_id" value="<?= (int) $shift['id'] ?>"><button class="danger">Nyahaktif</button>
</form><?php endif; ?></td></tr><?php endforeach; ?></table></div></section>
</main></body></html>
