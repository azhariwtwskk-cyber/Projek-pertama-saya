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
if (!$isOwner && !cpmsCan('attendance.shift.manage', $conn)) {
    http_response_code(403);
    exit('Akses pengurusan rotation diperlukan.');
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
    $action = (string) ($_POST['action'] ?? '');
    if (!cpmsAttendanceVerifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Token keselamatan tidak sah.';
    } elseif ($action === 'create_plan') {
        $name = trim((string) ($_POST['rotation_name'] ?? ''));
        $anchor = (string) ($_POST['anchor_date'] ?? '');
        $firstShift = (int) ($_POST['first_shift_id'] ?? 0);
        $secondShift = (int) ($_POST['second_shift_id'] ?? 0);
        $firstWeeks = (int) ($_POST['first_duration_weeks'] ?? 1);
        $secondWeeks = (int) ($_POST['second_duration_weeks'] ?? 1);
        if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)
            || $firstShift < 1 || $secondShift < 1
            || $firstShift === $secondShift
            || $firstWeeks < 1 || $firstWeeks > 8
            || $secondWeeks < 1 || $secondWeeks > 8) {
            $error = 'Semak nama, tarikh dan dua syif rotation yang berlainan.';
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) total FROM cpms_attendance_shifts
                 WHERE property_id = ? AND status = 'active'
                   AND id IN (?, ?)"
            );
            $stmt->bind_param(
                'iii', $propertyId, $firstShift, $secondShift
            );
            $stmt->execute();
            $valid = (int) $stmt->get_result()->fetch_assoc()['total'] === 2;
            $stmt->close();
            if (!$valid) {
                $error = 'Syif rotation tidak sah untuk property ini.';
            } else {
                $conn->begin_transaction();
                try {
                    $creator = $actorId > 0 ? $actorId : null;
                    $stmt = $conn->prepare(
                        "INSERT INTO cpms_attendance_rotation_plans
                         (property_id, rotation_name, anchor_date, status,
                          created_by_system_user_id)
                         VALUES (?, ?, ?, 'active', ?)"
                    );
                    $stmt->bind_param(
                        'issi', $propertyId, $name, $anchor, $creator
                    );
                    $stmt->execute();
                    $planId = (int) $stmt->insert_id;
                    $stmt->close();
                    $stmt = $conn->prepare(
                        "INSERT INTO cpms_attendance_rotation_items
                         (rotation_plan_id, shift_id, sequence_order,
                          duration_weeks)
                         VALUES (?, ?, 1, ?), (?, ?, 2, ?)"
                    );
                    $stmt->bind_param(
                        'iiiiii', $planId, $firstShift, $firstWeeks,
                        $planId, $secondShift, $secondWeeks
                    );
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    $message = 'Pelan rotation berjaya dicipta.';
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'Pelan rotation tidak dapat disimpan.';
                }
            }
        }
    } elseif ($action === 'assign_plan') {
        $securityId = (int) ($_POST['system_user_id'] ?? 0);
        $planId = (int) ($_POST['rotation_plan_id'] ?? 0);
        $from = (string) ($_POST['effective_from'] ?? '');
        $until = trim((string) ($_POST['effective_until'] ?? ''));
        if ($securityId < 1 || $planId < 1
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            || ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)
                || $until < $from))) {
            $error = 'Semak pengawal, pelan dan tarikh assignment.';
        } else {
            $stmt = $conn->prepare(
                "SELECT u.id FROM system_users u
                 JOIN user_roles ur ON ur.system_user_id = u.id
                    AND ur.status = 'active'
                 JOIN roles r ON r.id = ur.role_id
                    AND r.role_code = 'security'
                 JOIN cpms_attendance_rotation_plans rp
                    ON rp.id = ? AND rp.property_id = ?
                    AND rp.status = 'active'
                 WHERE u.id = ? AND u.property_id = ?
                   AND u.status = 'active' LIMIT 1"
            );
            $stmt->bind_param(
                'iiii', $planId, $propertyId, $securityId, $propertyId
            );
            $stmt->execute();
            $valid = is_array($stmt->get_result()->fetch_assoc());
            $stmt->close();
            if (!$valid) {
                $error = 'Pengawal atau pelan rotation tidak sah.';
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare(
                        "UPDATE cpms_attendance_rotation_assignments
                         SET status = 'inactive'
                         WHERE property_id = ? AND system_user_id = ?
                           AND status = 'active' AND effective_from >= ?"
                    );
                    $stmt->bind_param(
                        'iis', $propertyId, $securityId, $from
                    );
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare(
                        "UPDATE cpms_attendance_rotation_assignments
                         SET effective_until = DATE_SUB(?, INTERVAL 1 DAY)
                         WHERE property_id = ? AND system_user_id = ?
                           AND status = 'active' AND effective_from < ?
                           AND (effective_until IS NULL
                                OR effective_until >= ?)"
                    );
                    $stmt->bind_param(
                        'siiss', $from, $propertyId, $securityId, $from, $from
                    );
                    $stmt->execute();
                    $stmt->close();
                    $untilValue = $until !== '' ? $until : null;
                    $assignedBy = $actorId > 0 ? $actorId : null;
                    $stmt = $conn->prepare(
                        "INSERT INTO cpms_attendance_rotation_assignments
                         (property_id, system_user_id, rotation_plan_id,
                          effective_from, effective_until, status,
                          assigned_by_system_user_id)
                         VALUES (?, ?, ?, ?, ?, 'active', ?)"
                    );
                    $stmt->bind_param(
                        'iiissi', $propertyId, $securityId, $planId,
                        $from, $untilValue, $assignedBy
                    );
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    $message = 'Rotation berjaya diberikan kepada pengawal.';
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = 'Assignment rotation tidak dapat disimpan.';
                }
            }
        }
    }
}
$shifts = [];
$stmt = $conn->prepare(
    "SELECT id, shift_name, start_time, end_time
     FROM cpms_attendance_shifts
     WHERE property_id = ? AND status = 'active' ORDER BY start_time"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shifts[] = $row;
}
$stmt->close();
$plans = [];
$stmt = $conn->prepare(
    "SELECT rp.id, rp.rotation_name, rp.anchor_date,
            GROUP_CONCAT(CONCAT(s.shift_name, ' (', ri.duration_weeks,
                ' minggu)') ORDER BY ri.sequence_order SEPARATOR ' → ')
                AS sequence_label
     FROM cpms_attendance_rotation_plans rp
     JOIN cpms_attendance_rotation_items ri
       ON ri.rotation_plan_id = rp.id
     JOIN cpms_attendance_shifts s ON s.id = ri.shift_id
     WHERE rp.property_id = ? AND rp.status = 'active'
     GROUP BY rp.id, rp.rotation_name, rp.anchor_date
     ORDER BY rp.rotation_name"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $plans[] = $row;
}
$stmt->close();
$securityUsers = [];
$stmt = $conn->prepare(
    "SELECT DISTINCT u.id, u.full_name, u.username
     FROM system_users u
     JOIN user_roles ur ON ur.system_user_id = u.id
       AND ur.status = 'active'
     JOIN roles r ON r.id = ur.role_id AND r.role_code = 'security'
     WHERE u.property_id = ? AND u.status = 'active'
     ORDER BY u.full_name"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $securityUsers[] = $row;
}
$stmt->close();
$assignments = [];
$stmt = $conn->prepare(
    "SELECT ra.system_user_id, ra.effective_from, ra.effective_until,
            ra.status, u.full_name, rp.rotation_name
     FROM cpms_attendance_rotation_assignments ra
     JOIN system_users u ON u.id = ra.system_user_id
     JOIN cpms_attendance_rotation_plans rp
       ON rp.id = ra.rotation_plan_id
     WHERE ra.property_id = ?
     ORDER BY ra.status, ra.effective_from DESC, u.full_name LIMIT 100"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $current = cpmsShiftResolve(
        $conn, $propertyId, (int) $row['system_user_id'], date('Y-m-d')
    );
    $row['current_shift'] = $current
        ? (string) $current['shift_name'] : '-';
    $assignments[] = $row;
}
$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Security Weekly Rotation | CPMS</title><style>
*{box-sizing:border-box}body{margin:0;padding:20px;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1080px;margin:auto}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:15px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:13px}label{display:block;font-weight:700;margin:9px 0 5px}
input,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
button{border:0;border-radius:8px;background:#174789;color:#fff;padding:12px 15px;font-weight:700}
.ok,.bad{padding:12px;border-radius:9px}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}
.scroll{overflow:auto}@media(max-width:650px){.grid{grid-template-columns:1fr}body{padding:10px}}
</style></head><body><main><section class="card">
<h1>CPMS v3.4.6.1 — Security Weekly Shift Rotation</h1>
<p><a href="attendance_shift_setup.php?property_id=<?= $propertyId ?>">← Tetapan Syif</a> ·
<a href="attendance_report.php?property_id=<?= $propertyId ?>">Laporan Attendance</a></p>
<?php if ($isOwner): ?><label>Property</label><select onchange="location.href='?property_id='+this.value">
<?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"
<?= (int) $property['id'] === $propertyId ? 'selected' : '' ?>><?=
cpmsAttendanceEscape((string) $property['property_name']) ?></option><?php endforeach; ?>
</select><?php endif; ?>
<?php if ($message): ?><p class="ok"><?= cpmsAttendanceEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsAttendanceEscape($error) ?></p><?php endif; ?>
</section><section class="card"><h2>1. Cipta Pelan Rotation</h2>
<p>Contoh: Syif Siang 1 minggu → Syif Malam 1 minggu → ulang automatik.</p>
<form method="post"><input type="hidden" name="csrf_token"
value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="property_id" value="<?= $propertyId ?>">
<input type="hidden" name="action" value="create_plan"><div class="grid">
<div><label>Nama rotation</label><input name="rotation_name"
placeholder="Rotation Security A" required></div>
<div><label>Tarikh mula Minggu 1</label><input type="date" name="anchor_date"
value="<?= date('Y-m-d', strtotime('monday this week')) ?>" required></div>
<div><label>Syif pertama</label><select name="first_shift_id" required>
<option value="">-- Pilih --</option><?php foreach ($shifts as $shift): ?>
<option value="<?= (int) $shift['id'] ?>"><?= cpmsAttendanceEscape(
    (string) $shift['shift_name']
) ?> · <?= substr((string) $shift['start_time'],0,5) ?>–<?=
substr((string) $shift['end_time'],0,5) ?></option><?php endforeach; ?></select></div>
<div><label>Tempoh syif pertama (minggu)</label><input type="number"
name="first_duration_weeks" min="1" max="8" value="1"></div>
<div><label>Syif kedua</label><select name="second_shift_id" required>
<option value="">-- Pilih --</option><?php foreach ($shifts as $shift): ?>
<option value="<?= (int) $shift['id'] ?>"><?= cpmsAttendanceEscape(
    (string) $shift['shift_name']
) ?> · <?= substr((string) $shift['start_time'],0,5) ?>–<?=
substr((string) $shift['end_time'],0,5) ?></option><?php endforeach; ?></select></div>
<div><label>Tempoh syif kedua (minggu)</label><input type="number"
name="second_duration_weeks" min="1" max="8" value="1"></div></div>
<p><button>Cipta Pelan Rotation</button></p></form>
<?php if ($plans): ?><div class="scroll"><table><tr><th>Pelan</th><th>Rotation</th><th>Mula</th></tr>
<?php foreach ($plans as $plan): ?><tr><td><?= cpmsAttendanceEscape(
    (string) $plan['rotation_name']
) ?></td><td><?= cpmsAttendanceEscape((string) $plan['sequence_label']) ?></td>
<td><?= cpmsAttendanceEscape((string) $plan['anchor_date']) ?></td></tr><?php endforeach; ?>
</table></div><?php endif; ?></section>
<section class="card"><h2>2. Assign Kepada Security</h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="property_id" value="<?= $propertyId ?>">
<input type="hidden" name="action" value="assign_plan"><div class="grid">
<div><label>Security Guard</label><select name="system_user_id" required>
<option value="">-- Pilih --</option><?php foreach ($securityUsers as $user): ?>
<option value="<?= (int) $user['id'] ?>"><?= cpmsAttendanceEscape(
    (string) $user['full_name']
) ?></option><?php endforeach; ?></select></div>
<div><label>Pelan rotation</label><select name="rotation_plan_id" required>
<option value="">-- Pilih --</option><?php foreach ($plans as $plan): ?>
<option value="<?= (int) $plan['id'] ?>"><?= cpmsAttendanceEscape(
    (string) $plan['rotation_name']
) ?></option><?php endforeach; ?></select></div>
<div><label>Berkuat kuasa dari</label><input type="date" name="effective_from"
value="<?= date('Y-m-d') ?>" required></div><div><label>Sehingga (pilihan)</label>
<input type="date" name="effective_until"></div></div>
<p><button>Simpan Assignment Rotation</button></p></form></section>
<section class="card"><h2>Rotation Security Semasa</h2><div class="scroll"><table>
<tr><th>Security</th><th>Pelan</th><th>Syif Hari Ini</th><th>Tempoh</th><th>Status</th></tr>
<?php if (!$assignments): ?><tr><td colspan="5">Belum ada assignment rotation.</td></tr>
<?php endif; foreach ($assignments as $row): ?><tr><td><?= cpmsAttendanceEscape(
    (string) $row['full_name']
) ?></td><td><?= cpmsAttendanceEscape((string) $row['rotation_name']) ?></td>
<td><strong><?= cpmsAttendanceEscape((string) $row['current_shift']) ?></strong></td>
<td><?= cpmsAttendanceEscape((string) $row['effective_from']) ?> – <?=
$row['effective_until'] ? cpmsAttendanceEscape((string) $row['effective_until']) : 'Berterusan'
?></td><td><?= cpmsAttendanceEscape((string) $row['status']) ?></td></tr><?php endforeach; ?>
</table></div></section></main></body></html>
