<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/gps_attendance_service.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$actorId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$sessionPropertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
if (!$isOwner && !cpmsCan('attendance.shift.manage', $conn)) {
    http_response_code(403);
    exit('Akses assignment syif diperlukan.');
}
$properties = [];
$result = $conn->query('SELECT id, property_name FROM cpms_properties ORDER BY property_name');
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
    $targetUserId = (int) ($_POST['system_user_id'] ?? 0);
    $shiftId = (int) ($_POST['shift_id'] ?? 0);
    $from = (string) ($_POST['effective_from'] ?? '');
    $until = trim((string) ($_POST['effective_until'] ?? ''));
    if (!cpmsAttendanceVerifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Token keselamatan tidak sah.';
    } elseif ($targetUserId < 1 || $shiftId < 1
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
        || ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)
            || $until < $from))) {
        $error = 'Semak pekerja, syif dan tarikh berkuat kuasa.';
    } else {
        $valid = $conn->prepare(
            "SELECT u.id FROM system_users u
             JOIN cpms_attendance_shifts s ON s.id = ?
             WHERE u.id = ? AND u.property_id = ? AND s.property_id = ?
               AND u.status = 'active' AND s.status = 'active' LIMIT 1"
        );
        $valid->bind_param('iiii', $shiftId, $targetUserId, $propertyId, $propertyId);
        $valid->execute();
        $validRow = $valid->get_result()->fetch_assoc();
        $valid->close();
        if (!$validRow) {
            $error = 'Pekerja atau syif tidak sah untuk property ini.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE cpms_attendance_shift_assignments
                     SET status = 'inactive'
                     WHERE property_id = ? AND system_user_id = ?
                       AND status = 'active' AND effective_from >= ?"
                );
                $stmt->bind_param('iis', $propertyId, $targetUserId, $from);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare(
                    "UPDATE cpms_attendance_shift_assignments
                     SET effective_until = DATE_SUB(?, INTERVAL 1 DAY)
                     WHERE property_id = ? AND system_user_id = ?
                       AND status = 'active' AND effective_from < ?
                       AND (effective_until IS NULL OR effective_until >= ?)"
                );
                $stmt->bind_param('siiss', $from, $propertyId, $targetUserId, $from, $from);
                $stmt->execute();
                $stmt->close();
                $untilValue = $until !== '' ? $until : null;
                $assignedBy = $actorId > 0 ? $actorId : null;
                $stmt = $conn->prepare(
                    "INSERT INTO cpms_attendance_shift_assignments
                     (property_id, system_user_id, shift_id, effective_from,
                      effective_until, status, assigned_by_system_user_id)
                     VALUES (?, ?, ?, ?, ?, 'active', ?)"
                );
                $stmt->bind_param(
                    'iiissi', $propertyId, $targetUserId, $shiftId,
                    $from, $untilValue, $assignedBy
                );
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $message = 'Syif berjaya diberikan kepada pekerja.';
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = 'Assignment tidak dapat disimpan.';
            }
        }
    }
}
$users = [];
$stmt = $conn->prepare(
    "SELECT DISTINCT u.id, u.full_name, u.username, r.role_code
     FROM system_users u
     JOIN user_roles ur ON ur.system_user_id = u.id
     JOIN roles r ON r.id = ur.role_id
     WHERE u.property_id = ? AND u.status = 'active'
       AND r.role_code IN ('staff','security')
     ORDER BY u.full_name"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();
$shifts = [];
$stmt = $conn->prepare(
    "SELECT id, shift_name, start_time, end_time FROM cpms_attendance_shifts
     WHERE property_id = ? AND status = 'active' ORDER BY start_time"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shifts[] = $row;
}
$stmt->close();
$assignments = [];
$stmt = $conn->prepare(
    "SELECT a.*, u.full_name, u.username, s.shift_name, s.start_time, s.end_time
     FROM cpms_attendance_shift_assignments a
     JOIN system_users u ON u.id = a.system_user_id
     JOIN cpms_attendance_shifts s ON s.id = a.shift_id
     WHERE a.property_id = ?
     ORDER BY a.status, a.effective_from DESC, u.full_name LIMIT 100"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}
$stmt->close();
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport"
content="width=device-width,initial-scale=1"><title>Shift Assignment | CPMS</title>
<style>*{box-sizing:border-box}body{margin:0;padding:20px;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:1050px;margin:auto}.card{background:#fff;border-radius:16px;padding:22px;margin-bottom:15px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:13px}label{display:block;font-weight:700;margin:9px 0 5px}
input,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
button{border:0;border-radius:8px;background:#174789;color:#fff;padding:12px 15px;font-weight:700}
.ok,.bad{padding:12px;border-radius:9px}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}
.scroll{overflow:auto}@media(max-width:650px){.grid{grid-template-columns:1fr}body{padding:10px}}</style>
</head><body><main><section class="card"><h1>Assignment Syif Staff & Security</h1>
<p><a href="attendance_shift_setup.php?property_id=<?= $propertyId ?>">← Tetapan Syif</a> ·
<a href="attendance_rotation_setup.php?property_id=<?= $propertyId ?>">Rotation Security</a> ·
<a href="attendance_report.php?property_id=<?= $propertyId ?>">Laporan</a></p>
<?php if ($isOwner): ?><label>Property</label><select onchange="location.href='?property_id='+this.value">
<?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"
<?= (int) $property['id'] === $propertyId ? 'selected' : '' ?>>
<?= cpmsAttendanceEscape((string) $property['property_name']) ?></option><?php endforeach; ?>
</select><?php endif; ?>
<?php if ($message): ?><p class="ok"><?= cpmsAttendanceEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsAttendanceEscape($error) ?></p><?php endif; ?></section>
<section class="card"><h2>Berikan Syif</h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="property_id" value="<?= $propertyId ?>"><div class="grid">
<div><label>Pekerja</label><select name="system_user_id" required><option value="">-- Pilih --</option>
<?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>">
<?= cpmsAttendanceEscape((string) $user['full_name']) ?> (<?=
cpmsAttendanceEscape((string) $user['role_code']) ?>)</option><?php endforeach; ?></select></div>
<div><label>Syif</label><select name="shift_id" required><option value="">-- Pilih --</option>
<?php foreach ($shifts as $shift): ?><option value="<?= (int) $shift['id'] ?>">
<?= cpmsAttendanceEscape((string) $shift['shift_name']) ?> · <?=
cpmsAttendanceEscape(substr((string) $shift['start_time'],0,5)) ?>–<?=
cpmsAttendanceEscape(substr((string) $shift['end_time'],0,5)) ?></option><?php endforeach; ?></select></div>
<div><label>Berkuat kuasa dari</label><input type="date" name="effective_from"
value="<?= date('Y-m-d') ?>" required></div><div><label>Sehingga (pilihan)</label>
<input type="date" name="effective_until"></div></div><p><button>Simpan Assignment</button></p>
</form></section><section class="card"><h2>Assignment Terkini</h2><div class="scroll"><table>
<tr><th>Pekerja</th><th>Syif</th><th>Tempoh</th><th>Status</th></tr>
<?php if (!$assignments): ?><tr><td colspan="4">Belum ada assignment.</td></tr><?php endif; ?>
<?php foreach ($assignments as $row): ?><tr><td><?=
cpmsAttendanceEscape((string) $row['full_name']) ?></td><td><?=
cpmsAttendanceEscape((string) $row['shift_name']) ?></td><td><?=
cpmsAttendanceEscape((string) $row['effective_from']) ?> – <?=
$row['effective_until'] ? cpmsAttendanceEscape((string) $row['effective_until']) : 'Berterusan'
?></td><td><?= cpmsAttendanceEscape((string) $row['status']) ?></td></tr><?php endforeach; ?>
</table></div></section></main></body></html>
