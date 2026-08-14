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
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$propertyId = currentPropertyId($conn);
if ($propertyId < 1) {
    http_response_code(403);
    exit('Property context tidak sah. Sila log masuk semula.');
}

$stmt = $conn->prepare(
    "SELECT * FROM security_checkpoints WHERE id = ? AND property_id = ? LIMIT 1"
);
$stmt->bind_param('ii', $id, $propertyId);
$stmt->execute();
$checkpoint = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$checkpoint) exit('Checkpoint tidak dijumpai.');

$scanUrl = baseUrl() . '/security_qr_scanner.php?token=' . urlencode((string)$checkpoint['qr_token']);
?>
<!doctype html><html lang="ms"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR <?= e($checkpoint['checkpoint_code']) ?></title>
<link rel="stylesheet" href="css/security_checkpoint.css?v=1">
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head><body class="qr-page">
<main class="qr-card">
<p class="eyebrow">CPMS QR PATROL CHECKPOINT</p>
<h1><?= e($checkpoint['checkpoint_name']) ?></h1>
<p class="location"><?= e(trim(($checkpoint['block_location'] ?? '') . ' · ' . ($checkpoint['specific_location'] ?? ''))) ?></p>
<div id="qrcode"></div>
<strong class="checkpoint-code"><?= e($checkpoint['checkpoint_code']) ?></strong>
<p class="instruction">Imbas QR ini semasa rondaan keselamatan.</p>
<div class="no-print"><button onclick="window.print()">Print QR</button>
<a class="button secondary" href="admin_security_checkpoints.php">Kembali</a></div>
</main>
<script>
new QRCode(document.getElementById("qrcode"), {
 text: <?= json_encode($scanUrl, JSON_UNESCAPED_SLASHES) ?>,
 width: 260, height: 260,
 correctLevel: QRCode.CorrectLevel.H
});
</script></body></html>
