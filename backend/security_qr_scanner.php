<?php
declare(strict_types=1);

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function currentPropertyId(mysqli $conn): int {
    if (!empty($_SESSION['cpms_property_id'])) {
        return max(1, (int)$_SESSION['cpms_property_id']);
    }

    if (!empty($_SESSION['security_guard_property_id'])) {
        return max(1, (int)$_SESSION['security_guard_property_id']);
    }

    if (!empty($_SESSION['property_id'])) {
        return max(1, (int)$_SESSION['property_id']);
    }

    if (!empty($_SESSION['security_guard_id'])
        && tableExists($conn, 'security_guards')
        && columnExists($conn, 'security_guards', 'property_id')) {
        $id = (int)$_SESSION['security_guard_id'];
        $stmt = $conn->prepare("SELECT property_id FROM security_guards WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($row['property_id'] ?? 0) > 0) return (int)$row['property_id'];
    }

    return 0;
}

function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
}

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['security_guard_id'])) {
    header('Location: cpms/login.php');
    exit();
}

$guardId = (int)$_SESSION['security_guard_id'];
$propertyId = currentPropertyId($conn);
if ($propertyId < 1) {
    header('Location: cpms/login.php?expired=1');
    exit();
}
$token = trim((string)($_GET['token'] ?? ''));
$patrolId = max(0, (int)($_GET['patrol_id'] ?? 0));

if ($patrolId === 0) {
    $stmt = $conn->prepare(
        "SELECT id FROM security_patrols
         WHERE guard_id = ? AND patrol_date = CURDATE()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->bind_param('i', $guardId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $patrolId = (int)($row['id'] ?? 0);
}

$checkpoint = null;
if ($token !== '') {
    $stmt = $conn->prepare(
        "SELECT * FROM security_checkpoints
         WHERE qr_token = ? AND property_id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->bind_param('si', $token, $propertyId);
    $stmt->execute();
    $checkpoint = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!doctype html><html lang="ms"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scan QR Patrol</title>
<link rel="stylesheet" href="css/security_checkpoint.css?v=1">
<script src="https://unpkg.com/html5-qrcode"></script>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head><body>
<main class="shell narrow">
<header class="hero compact"><div><p class="eyebrow">SECURITY PATROL</p><h1>Scan QR Checkpoint</h1></div>
<a class="button secondary" href="security_dashboard.php">Dashboard</a></header>

<?php if ($patrolId === 0): ?>
<div class="alert error">Tiada patrol hari ini dijumpai. Hantar atau mulakan rekod patrol dahulu.</div>
<?php elseif ($checkpoint): ?>
<section class="panel center">
<div class="scan-success">✓</div>
<h2><?= e($checkpoint['checkpoint_name']) ?></h2>
<p><?= e(trim(($checkpoint['block_location'] ?? '') . ' ' . ($checkpoint['specific_location'] ?? ''))) ?></p>
<form method="post" action="security_checkpoint_scan_submit.php">
<input type="hidden" name="patrol_id" value="<?= $patrolId ?>">
<input type="hidden" name="token" value="<?= e($token) ?>">
<input type="hidden" name="latitude" id="latitude">
<input type="hidden" name="longitude" id="longitude">
<input type="hidden" name="accuracy" id="accuracy">
<label>Catatan (pilihan)<textarea name="remarks" rows="3" placeholder="Keadaan lokasi semasa rondaan"></textarea></label>
<button type="submit">Sahkan Checkpoint</button>
</form>
</section>
<script>
if (navigator.geolocation) {
 navigator.geolocation.getCurrentPosition(function(p){
  document.getElementById('latitude').value = p.coords.latitude;
  document.getElementById('longitude').value = p.coords.longitude;
  document.getElementById('accuracy').value = p.coords.accuracy;
 }, function(){}, {enableHighAccuracy:true, timeout:8000});
}
</script>
<?php else: ?>
<section class="panel">
<p>Halakan kamera telefon kepada QR checkpoint.</p>
<div id="reader"></div>
<hr>
<label>Atau masukkan kod/URL QR secara manual
<input id="manualCode" placeholder="Tampal URL atau token QR">
</label>
<button type="button" onclick="openManual()">Semak Kod</button>
</section>
<script>
function processResult(text) {
 try {
  const url = new URL(text);
  const token = url.searchParams.get('token');
  if (token) {
   location.href = 'security_qr_scanner.php?patrol_id=<?= $patrolId ?>&token=' + encodeURIComponent(token);
   return;
  }
 } catch(e) {}
 location.href = 'security_qr_scanner.php?patrol_id=<?= $patrolId ?>&token=' + encodeURIComponent(text.trim());
}
function openManual(){ processResult(document.getElementById('manualCode').value); }
const scanner = new Html5QrcodeScanner("reader", {fps:10, qrbox:{width:250,height:250}}, false);
scanner.render(function(decodedText){ scanner.clear().then(()=>processResult(decodedText)); });
</script>
<?php endif; ?>

<?php if ($patrolId > 0): ?>
<p class="center-link"><a href="security_patrol_checkpoint_progress.php?patrol_id=<?= $patrolId ?>">Lihat Progress Checkpoint</a></p>
<?php endif; ?>
</main></body></html>
