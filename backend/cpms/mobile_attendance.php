<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kuching');
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/gps_attendance_service.php';
require_once __DIR__ . '/cpms/includes/attendance_shift_service.php';

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) ($_SESSION['cpms_property_id']
    ?? $_SESSION['staff_property_id'] ?? 0);
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
if ($userId < 1 || $propertyId < 1
    || !in_array($role, ['staff', 'security'], true)) {
    header('Location: cpms/login.php');
    exit;
}
cpmsRequire('attendance.clock', $conn);

function cpmsAttendanceShiftSchemaReady(mysqli $db): bool
{
    $sql = "SELECT
        (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'cpms_attendance_shifts') AS shifts_ready,
        (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'cpms_attendance_rotation_assignments')
            AS rotation_ready,
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'cpms_attendance_sessions'
           AND column_name = 'resolved_shift_id') AS column_ready";
    $result = $db->query($sql);
    if (!$result) {
        return false;
    }
    $row = $result->fetch_assoc();
    return is_array($row)
        && (int) $row['shifts_ready'] === 1
        && (int) $row['rotation_ready'] === 1
        && (int) $row['column_ready'] === 1;
}

if (!cpmsAttendanceShiftSchemaReady($conn)) {
    http_response_code(503);
    ?><!doctype html><html lang="ms"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Attendance Update Required | CPMS</title><style>
    body{font:16px Arial;background:#eef3f9;color:#10213d;padding:22px}
    main{max-width:680px;margin:80px auto;background:#fff;padding:28px;
    border-radius:16px}h1{color:#b42318}code{background:#eef3f9;padding:3px 6px}
    </style></head><body><main><h1>GPS Attendance belum dikemas kini</h1>
    <p>System Owner perlu menjalankan migration berikut melalui Migration Engine:</p>
    <ol><li><code>20260730_0035_attendance_shift_late_overtime</code></li>
    <li><code>20260730_0036_security_weekly_shift_rotation</code></li></ol>
    <p>Selepas kedua-duanya berstatus <strong>applied</strong>, buka semula halaman ini.</p>
    </main></body></html><?php
    exit;
}

$message = '';
$error = '';
$geofence = cpmsAttendanceGeofence($conn, $propertyId);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['attendance_action'] ?? '');
    $token = $_POST['csrf_token'] ?? null;
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $accuracy = filter_var($_POST['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
    $distance = null;
    $reason = 'invalid_request';

    if (!cpmsAttendanceVerifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Token keselamatan tidak sah. Muat semula halaman.';
        $reason = 'invalid_csrf';
    } elseif (!in_array($action, ['clock_in', 'clock_out'], true)) {
        $error = 'Tindakan kedatangan tidak sah.';
    } elseif ($geofence === null) {
        $error = 'Lokasi geofence property belum ditetapkan oleh pengurusan.';
        $reason = 'geofence_not_configured';
    } elseif ($latitude === false || $longitude === false || $accuracy === false
        || !cpmsAttendanceValidCoordinates(
            (float) $latitude, (float) $longitude, (float) $accuracy
        )) {
        $error = 'Bacaan GPS tidak sah. Sila hidupkan lokasi dan cuba lagi.';
        $reason = 'invalid_gps';
    } else {
        $distance = cpmsAttendanceDistance(
            (float) $latitude,
            (float) $longitude,
            (float) $geofence['latitude'],
            (float) $geofence['longitude']
        );
        if ((float) $accuracy > (int) $geofence['maximum_accuracy_m']) {
            $error = 'Ketepatan GPS terlalu rendah (' . round((float) $accuracy)
                . 'm). Bergerak ke kawasan terbuka dan cuba lagi.';
            $reason = 'poor_accuracy';
        } elseif ((int) $geofence['enforcement_enabled'] === 1
            && $distance > (int) $geofence['radius_m']) {
            $error = 'Anda berada di luar kawasan kerja. Jarak: '
                . round($distance) . 'm; had: '
                . (int) $geofence['radius_m'] . 'm.';
            $reason = 'outside_geofence';
        } else {
            $conn->begin_transaction();
            try {
                $open = $conn->prepare(
                    "SELECT a.id, a.clock_in_at, a.scheduled_start_at,
                            a.scheduled_end_at,
                            COALESCE(s.break_minutes, 0) AS break_minutes,
                            COALESCE(s.minimum_overtime_minutes, 0)
                                AS minimum_overtime_minutes
                     FROM cpms_attendance_sessions a
                     LEFT JOIN cpms_attendance_shifts s
                       ON s.id = a.resolved_shift_id
                     WHERE a.property_id = ? AND a.system_user_id = ?
                       AND a.status = 'Open'
                     ORDER BY a.id DESC LIMIT 1 FOR UPDATE"
                );
                $open->bind_param('ii', $propertyId, $userId);
                $open->execute();
                $openRow = $open->get_result()->fetch_assoc();
                $open->close();
                $agent = substr(
                    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500
                );
                $deviceValue = substr(
                    (string) ($_POST['device_id'] ?? ''), 0, 200
                );
                $deviceHash = $deviceValue !== ''
                    ? hash('sha256', $deviceValue) : null;

                if ($action === 'clock_in') {
                    if (is_array($openRow)) {
                        throw new RuntimeException('already_clocked_in');
                    }
                    $workDate = date('Y-m-d');
                    $clockIn = new DateTimeImmutable();
                    $shift = cpmsShiftResolve(
                        $conn, $propertyId, $userId, $workDate
                    );
                    $shiftAssignmentId = $shift
                        && !empty($shift['assignment_id'])
                        ? (int) $shift['assignment_id'] : null;
                    $rotationAssignmentId = $shift
                        && !empty($shift['rotation_assignment_id'])
                        ? (int) $shift['rotation_assignment_id'] : null;
                    $resolvedShiftId = $shift
                        ? (int) $shift['shift_id'] : null;
                    $scheduledStart = $shift
                        ? (string) $shift['scheduled_start_at'] : null;
                    $scheduledEnd = $shift
                        ? (string) $shift['scheduled_end_at'] : null;
                    $lateMinutes = $shift
                        ? cpmsShiftLateMinutes($clockIn, $shift) : 0;
                    $openSessionKey = $propertyId . ':' . $userId;
                    $stmt = $conn->prepare(
                        "INSERT INTO cpms_attendance_sessions
                         (property_id, system_user_id, user_role,
                          shift_assignment_id, rotation_assignment_id,
                          resolved_shift_id, work_date,
                          scheduled_start_at, scheduled_end_at, late_minutes,
                          clock_in_at, clock_in_latitude, clock_in_longitude,
                          clock_in_accuracy_m, clock_in_distance_m,
                          status, open_session_key,
                          device_fingerprint, user_agent)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?,
                                 'Open', ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        'iisiiisssiddddsss',
                        $propertyId, $userId, $role, $shiftAssignmentId,
                        $rotationAssignmentId, $resolvedShiftId, $workDate,
                        $scheduledStart, $scheduledEnd, $lateMinutes,
                        $latitude, $longitude, $accuracy, $distance,
                        $openSessionKey, $deviceHash, $agent
                    );
                    $stmt->execute();
                    $stmt->close();
                    $message = 'Clock In berjaya direkodkan pada '
                        . date('h:i A') . '.';
                } else {
                    if (!is_array($openRow)) {
                        throw new RuntimeException('not_clocked_in');
                    }
                    $sessionId = (int) $openRow['id'];
                    $clockIn = new DateTimeImmutable(
                        (string) $openRow['clock_in_at']
                    );
                    $clockOut = new DateTimeImmutable();
                    $scheduledStart = $openRow['scheduled_start_at']
                        ? new DateTimeImmutable(
                            (string) $openRow['scheduled_start_at']
                        ) : null;
                    $scheduledEnd = $openRow['scheduled_end_at']
                        ? new DateTimeImmutable(
                            (string) $openRow['scheduled_end_at']
                        ) : null;
                    $metrics = cpmsShiftCompletionMetrics(
                        $clockIn, $clockOut, $scheduledStart, $scheduledEnd,
                        (int) $openRow['break_minutes'],
                        (int) $openRow['minimum_overtime_minutes']
                    );
                    $stmt = $conn->prepare(
                        "UPDATE cpms_attendance_sessions
                         SET clock_out_at = NOW(),
                             clock_out_latitude = ?,
                             clock_out_longitude = ?,
                             clock_out_accuracy_m = ?,
                             clock_out_distance_m = ?,
                             early_departure_minutes = ?,
                             worked_minutes = ?,
                             overtime_minutes = ?,
                             status = 'Completed',
                             open_session_key = NULL
                         WHERE id = ? AND property_id = ?
                           AND system_user_id = ? AND status = 'Open'"
                    );
                    $stmt->bind_param(
                        'ddddiiiiii',
                        $latitude, $longitude, $accuracy, $distance,
                        $metrics['early_departure_minutes'],
                        $metrics['worked_minutes'],
                        $metrics['overtime_minutes'],
                        $sessionId, $propertyId, $userId
                    );
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) {
                        $stmt->close();
                        throw new RuntimeException('clock_out_conflict');
                    }
                    $stmt->close();
                    $message = 'Clock Out berjaya direkodkan pada '
                        . date('h:i A') . '.';
                }
                $conn->commit();
                $reason = 'accepted';
                cpmsAttendanceAudit(
                    $conn, $propertyId, $userId, $action, true, $reason,
                    (float) $latitude, (float) $longitude,
                    (float) $accuracy, $distance
                );
            } catch (Throwable $exception) {
                $conn->rollback();
                $reason = $exception->getMessage();
                if ($reason === 'already_clocked_in') {
                    $error = 'Anda sudah Clock In. Sila Clock Out dahulu.';
                } elseif ($reason === 'not_clocked_in') {
                    $error = 'Tiada rekod Clock In yang masih terbuka.';
                } else {
                    $error = 'Rekod tidak dapat disimpan. Sila cuba semula.';
                }
            }
        }
    }
    if ($error !== '') {
        cpmsAttendanceAudit(
            $conn, $propertyId, $userId, $action ?: 'unknown',
            false, $reason,
            $latitude === false ? null : (float) $latitude,
            $longitude === false ? null : (float) $longitude,
            $accuracy === false ? null : (float) $accuracy,
            $distance
        );
    }
}

$openSession = null;
$stmt = $conn->prepare(
    "SELECT a.id, a.clock_in_at, a.clock_in_distance_m, a.late_minutes,
            s.shift_name
     FROM cpms_attendance_sessions a
     LEFT JOIN cpms_attendance_shifts s ON s.id = a.resolved_shift_id
     WHERE a.property_id = ? AND a.system_user_id = ? AND a.status = 'Open'
     ORDER BY a.id DESC LIMIT 1"
);
$stmt->bind_param('ii', $propertyId, $userId);
$stmt->execute();
$openSession = $stmt->get_result()->fetch_assoc();
$stmt->close();

$history = [];
$stmt = $conn->prepare(
    'SELECT work_date, clock_in_at, clock_out_at, status,
            late_minutes, worked_minutes, overtime_minutes
     FROM cpms_attendance_sessions
     WHERE property_id = ? AND system_user_id = ?
     ORDER BY clock_in_at DESC LIMIT 10'
);
$stmt->bind_param('ii', $propertyId, $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();
$todayShift = cpmsShiftResolve($conn, $propertyId, $userId, date('Y-m-d'));
$back = $role === 'security'
    ? '../security_dashboard.php'
    : '../staff_dashboard.php';
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f2342"><title>GPS Attendance | CPMS</title>
<link rel="stylesheet" href="pwa/cpms-mobile.css?v=345">
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f9;color:#10213d;font:15px Arial}
.top{background:linear-gradient(135deg,#0f2342,#174789);color:#fff;padding:22px 16px 38px}
.wrap{max-width:720px;margin:auto}.top a{color:#fff;text-decoration:none}.top h1{margin:12px 0 5px}
main{max-width:720px;margin:-20px auto 0;padding:0 14px 80px}.card{background:#fff;border-radius:16px;
padding:18px;margin-bottom:14px;box-shadow:0 8px 24px rgba(15,35,66,.1)}
.state{text-align:center}.state strong{display:block;font-size:25px;margin:5px}.gps{padding:12px;
border-radius:11px;background:#eef3f9;margin:12px 0}.ok,.bad{padding:12px;border-radius:10px}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
button{width:100%;border:0;padding:15px;border-radius:11px;background:#174789;color:#fff;
font-weight:800;font-size:16px}button:disabled{opacity:.55}.out{background:#b42318}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:10px 5px;
border-bottom:1px solid #e2e8f0;text-align:left}</style></head>
<body data-cpms-pwa="<?= cpmsAttendanceEscape($role) ?>">
<header class="top"><div class="wrap"><a href="<?= $back ?>">← Dashboard</a>
<h1>GPS Attendance</h1><div>Clock In/Out dalam kawasan property</div></div></header>
<main>
<?php if ($message): ?><p class="ok"><?= cpmsAttendanceEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsAttendanceEscape($error) ?></p><?php endif; ?>
<section class="card state">
<?php if ($openSession): ?><span>Status semasa</span><strong>SUDAH CLOCK IN</strong>
<div>Sejak <?= cpmsAttendanceEscape(date(
    'd/m/Y h:i A', strtotime((string) $openSession['clock_in_at'])
)) ?></div>
<?php else: ?><span>Status semasa</span><strong>BELUM CLOCK IN</strong><?php endif; ?>
</section>
<section class="card">
<h2>Syif Hari Ini</h2>
<?php if ($todayShift): ?>
<strong><?= cpmsAttendanceEscape((string) $todayShift['shift_name']) ?></strong>
<?php if (!empty($todayShift['rotation_name'])): ?><div><?=
cpmsAttendanceEscape((string) $todayShift['rotation_name']) ?> · Minggu rotation <?=
(int) $todayShift['rotation_week'] ?></div><?php endif; ?>
<p><?= cpmsAttendanceEscape(date(
    'h:i A', strtotime((string) $todayShift['scheduled_start_at'])
)) ?> – <?= cpmsAttendanceEscape(date(
    'h:i A', strtotime((string) $todayShift['scheduled_end_at'])
)) ?> · Grace <?= (int) $todayShift['grace_minutes'] ?> minit</p>
<?php else: ?><p>Tiada syif aktif diberikan untuk hari ini.</p><?php endif; ?>
</section>
<section class="card">
<?php if ($geofence === null): ?>
<p class="bad">Pengurusan belum menetapkan lokasi GPS property ini.</p>
<?php else: ?>
<div class="gps" id="gps-status">Tekan butang untuk mendapatkan GPS semasa.</div>
<form method="post" id="attendance-form">
<input type="hidden" name="csrf_token"
value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<input type="hidden" name="attendance_action"
value="<?= $openSession ? 'clock_out' : 'clock_in' ?>">
<input type="hidden" name="latitude"><input type="hidden" name="longitude">
<input type="hidden" name="accuracy"><input type="hidden" name="device_id">
<button id="attendance-button" class="<?= $openSession ? 'out' : '' ?>"
type="button" onclick="cpmsAttendanceLocate()">
<?= $openSession ? 'Dapatkan GPS & Clock Out' : 'Dapatkan GPS & Clock In' ?>
</button></form>
<p style="color:#64748b">Internet dan kebenaran lokasi diperlukan. Masa rekod menggunakan masa server CPMS.</p>
<?php endif; ?></section>
<section class="card"><h2>10 Rekod Terkini</h2>
<div style="overflow:auto"><table><tr><th>Tarikh</th><th>Masuk</th><th>Keluar</th>
<th>Lewat</th><th>Kerja</th><th>OT</th><th>Status</th></tr>
<?php if (!$history): ?><tr><td colspan="7">Belum ada rekod.</td></tr>
<?php else: foreach ($history as $row): ?><tr>
<td><?= cpmsAttendanceEscape(date('d/m/Y', strtotime((string) $row['work_date']))) ?></td>
<td><?= cpmsAttendanceEscape(date('h:i A', strtotime((string) $row['clock_in_at']))) ?></td>
<td><?= $row['clock_out_at'] ? cpmsAttendanceEscape(date(
    'h:i A', strtotime((string) $row['clock_out_at'])
)) : '-' ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['late_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['worked_minutes']) ?></td>
<td><?= cpmsShiftMinutesLabel((int) $row['overtime_minutes']) ?></td>
<td><?= cpmsAttendanceEscape((string) $row['status']) ?></td>
</tr><?php endforeach; endif; ?></table></div></section>
</main>
<script src="pwa/cpms-mobile.js?v=345" defer></script>
<script>
function cpmsAttendanceDeviceId() {
    var key = 'cpms_attendance_device_id';
    var value = '';
    try {
        value = window.localStorage.getItem(key) || '';
        if (!value) {
            value = String(new Date().getTime()) + '-'
                + String(Math.random()).replace('.', '');
            window.localStorage.setItem(key, value);
        }
    } catch (error) {
        value = String(new Date().getTime());
    }
    return value;
}

function cpmsAttendanceLocate() {
    var form = document.getElementById('attendance-form');
    var button = document.getElementById('attendance-button');
    var status = document.getElementById('gps-status');

    if (!form || !button || !status) {
        window.alert('Borang GPS Attendance tidak lengkap.');
        return false;
    }
    if (!window.navigator.geolocation) {
        status.innerHTML = 'Telefon atau browser tidak menyokong GPS.';
        status.style.color = '#b42318';
        return false;
    }

    button.disabled = true;
    status.innerHTML = 'Mencari lokasi GPS berketepatan tinggi...';
    status.style.color = '#10213d';

    window.navigator.geolocation.getCurrentPosition(
        function (position) {
            form.elements['latitude'].value =
                Number(position.coords.latitude).toFixed(7);
            form.elements['longitude'].value =
                Number(position.coords.longitude).toFixed(7);
            form.elements['accuracy'].value =
                Number(position.coords.accuracy).toFixed(2);
            form.elements['device_id'].value =
                cpmsAttendanceDeviceId();
            status.innerHTML = 'GPS ditemui. Ketepatan lebih kurang '
                + Math.round(position.coords.accuracy)
                + ' meter. Menghantar...';
            form.submit();
        },
        function (error) {
            var messages = {
                1: 'Kebenaran lokasi ditolak. Benarkan Location untuk CPMS.',
                2: 'Lokasi gagal dikesan. Hidupkan GPS dan cuba lagi.',
                3: 'GPS mengambil masa terlalu lama. Cuba di kawasan terbuka.'
            };
            status.innerHTML = messages[error.code]
                || 'GPS tidak dapat digunakan.';
            status.style.color = '#b42318';
            button.disabled = false;
        },
        {
            enableHighAccuracy: true,
            timeout: 25000,
            maximumAge: 0
        }
    );
    return false;
}
</script>
</body></html>
