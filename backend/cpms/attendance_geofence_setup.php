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
if (!$isOwner && !cpmsCan('attendance.geofence.manage', $conn)) {
    http_response_code(403);
    exit('Akses pengurusan geofence diperlukan.');
}
$properties = [];
$result = $conn->query(
    'SELECT id, property_name FROM cpms_properties ORDER BY property_name'
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}
$selectedPropertyId = $isOwner
    ? (int) ($_REQUEST['property_id'] ?? ($properties[0]['id'] ?? 0))
    : $sessionPropertyId;
$allowed = false;
foreach ($properties as $property) {
    if ((int) $property['id'] === $selectedPropertyId) {
        $allowed = true;
        break;
    }
}
if (!$allowed || (!$isOwner && $selectedPropertyId !== $sessionPropertyId)) {
    http_response_code(403);
    exit('Property tidak sah.');
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $radius = (int) ($_POST['radius_m'] ?? 200);
    $maximumAccuracy = (int) ($_POST['maximum_accuracy_m'] ?? 100);
    $enabled = isset($_POST['enforcement_enabled']) ? 1 : 0;
    if (!cpmsAttendanceVerifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Token keselamatan tidak sah.';
    } elseif ($latitude === false || $longitude === false
        || $latitude < -90 || $latitude > 90
        || $longitude < -180 || $longitude > 180
        || $radius < 30 || $radius > 3000
        || $maximumAccuracy < 20 || $maximumAccuracy > 500) {
        $error = 'Semak koordinat, radius (30–3000m) dan ketepatan (20–500m).';
    } else {
        $updatedBy = $userId > 0 ? $userId : null;
        $stmt = $conn->prepare(
            'INSERT INTO cpms_property_geofences
             (property_id, latitude, longitude, radius_m,
              maximum_accuracy_m, enforcement_enabled,
              updated_by_system_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              latitude = VALUES(latitude), longitude = VALUES(longitude),
              radius_m = VALUES(radius_m),
              maximum_accuracy_m = VALUES(maximum_accuracy_m),
              enforcement_enabled = VALUES(enforcement_enabled),
              updated_by_system_user_id = VALUES(updated_by_system_user_id)'
        );
        $stmt->bind_param(
            'iddiiii',
            $selectedPropertyId, $latitude, $longitude, $radius,
            $maximumAccuracy, $enabled, $updatedBy
        );
        $stmt->execute();
        $stmt->close();
        $message = 'Tetapan geofence berjaya disimpan.';
    }
}
$geofence = cpmsAttendanceGeofence($conn, $selectedPropertyId) ?: [
    'latitude' => '', 'longitude' => '', 'radius_m' => 200,
    'maximum_accuracy_m' => 100, 'enforcement_enabled' => 1,
];
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GPS Geofence Setup | CPMS</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:22px;background:#eef3f9;color:#10213d;font:15px Arial}
main{max-width:760px;margin:auto}.card{background:#fff;padding:24px;border-radius:17px;margin-bottom:14px}
label{display:block;font-weight:800;margin:15px 0 6px}input,select{width:100%;padding:12px;
border:1px solid #cbd5e1;border-radius:9px;font:inherit}button,.link{display:inline-block;border:0;
padding:12px 16px;border-radius:9px;background:#174789;color:#fff;font-weight:800;
text-decoration:none;margin-top:16px}.secondary{background:#475569}.ok,.bad{padding:12px;border-radius:9px}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:560px){.grid{grid-template-columns:1fr}}
</style></head><body><main>
<section class="card"><h1>CPMS v3.4.5 — GPS Geofence Setup</h1>
<p>Pergi ke titik tengah property, tekan <strong>Gunakan Lokasi Telefon Ini</strong>,
kemudian simpan.</p>
<?php if ($message): ?><p class="ok"><?= cpmsAttendanceEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsAttendanceEscape($error) ?></p><?php endif; ?>
<form method="post" id="geofence-form">
<input type="hidden" name="csrf_token"
value="<?= cpmsAttendanceEscape(cpmsAttendanceCsrfToken()) ?>">
<?php if ($isOwner): ?><label>Property</label><select name="property_id"
onchange="location.href='?property_id='+this.value">
<?php foreach ($properties as $property): ?><option value="<?= (int) $property['id'] ?>"
<?= (int) $property['id'] === $selectedPropertyId ? 'selected' : '' ?>>
<?= cpmsAttendanceEscape((string) $property['property_name']) ?></option>
<?php endforeach; ?></select><?php else: ?>
<input type="hidden" name="property_id" value="<?= $selectedPropertyId ?>">
<?php endif; ?>
<div class="grid"><div><label>Latitude</label><input id="latitude" name="latitude"
type="number" step="0.0000001" value="<?= cpmsAttendanceEscape(
    (string) $geofence['latitude']
) ?>" required></div><div><label>Longitude</label><input id="longitude"
name="longitude" type="number" step="0.0000001" value="<?= cpmsAttendanceEscape(
    (string) $geofence['longitude']
) ?>" required></div></div>
<div class="grid"><div><label>Radius dibenarkan (meter)</label>
<input name="radius_m" type="number" min="30" max="3000"
value="<?= (int) $geofence['radius_m'] ?>" required></div>
<div><label>Ketepatan GPS maksimum (meter)</label>
<input name="maximum_accuracy_m" type="number" min="20" max="500"
value="<?= (int) $geofence['maximum_accuracy_m'] ?>" required></div></div>
<label><input style="width:auto" type="checkbox" name="enforcement_enabled"
<?= (int) $geofence['enforcement_enabled'] === 1 ? 'checked' : '' ?>>
 Sekat Clock In/Out di luar radius</label>
<button type="button" class="secondary" id="use-location">Gunakan Lokasi Telefon Ini</button>
<button type="submit">Simpan Geofence</button>
</form><p id="gps-message"></p>
<a class="link" href="attendance_report.php?property_id=<?= $selectedPropertyId ?>">
Lihat Attendance Report</a>
<a class="link secondary" href="attendance_shift_setup.php?property_id=<?= $selectedPropertyId ?>">
Tetapan Syif</a>
<a class="link secondary" href="attendance_shift_assignments.php?property_id=<?= $selectedPropertyId ?>">
Assignment Pekerja</a></section></main>
<script>
document.getElementById('use-location').addEventListener('click',function(){
 var msg=document.getElementById('gps-message');msg.textContent='Mencari GPS…';
 navigator.geolocation.getCurrentPosition(function(p){
  document.getElementById('latitude').value=p.coords.latitude.toFixed(7);
  document.getElementById('longitude').value=p.coords.longitude.toFixed(7);
  msg.textContent='Lokasi ditemui. Ketepatan ±'+Math.round(p.coords.accuracy)+'m.';
 },function(){msg.textContent='GPS gagal. Benarkan Location dan cuba lagi.';},
 {enableHighAccuracy:true,timeout:20000,maximumAge:0});
});
</script></body></html>
