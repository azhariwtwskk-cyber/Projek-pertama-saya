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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: security_qr_scanner.php');
    exit();
}

$guardId = (int)$_SESSION['security_guard_id'];
$propertyId = currentPropertyId($conn);
if ($propertyId < 1) {
    header('Location: cpms/login.php?expired=1');
    exit();
}
$patrolId = (int)($_POST['patrol_id'] ?? 0);
$token = trim((string)($_POST['token'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$latitude = ($_POST['latitude'] ?? '') !== '' ? (float)$_POST['latitude'] : null;
$longitude = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;
$accuracy = ($_POST['accuracy'] ?? '') !== '' ? (float)$_POST['accuracy'] : null;
$device = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

try {
    $stmt = $conn->prepare(
        "SELECT id FROM security_patrols WHERE id = ? AND guard_id = ? LIMIT 1"
    );
    $stmt->bind_param('ii', $patrolId, $guardId);
    $stmt->execute();
    $patrol = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$patrol) throw new RuntimeException('Patrol tidak sah untuk akaun pengawal ini.');

    $stmt = $conn->prepare(
        "SELECT id FROM security_checkpoints
         WHERE qr_token = ? AND property_id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->bind_param('si', $token, $propertyId);
    $stmt->execute();
    $checkpoint = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$checkpoint) throw new RuntimeException('QR checkpoint tidak sah atau tidak aktif.');

    $checkpointId = (int)$checkpoint['id'];

    $stmt = $conn->prepare(
        "INSERT INTO security_checkpoint_scans
        (property_id, patrol_id, checkpoint_id, guard_id, scanned_at,
         scan_method, latitude, longitude, accuracy_meters, device_info, remarks)
        VALUES (?, ?, ?, ?, NOW(), 'QR', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          scanned_at = NOW(),
          latitude = VALUES(latitude),
          longitude = VALUES(longitude),
          accuracy_meters = VALUES(accuracy_meters),
          device_info = VALUES(device_info),
          remarks = VALUES(remarks)"
    );
    $stmt->bind_param(
        'iiiidddss',
        $propertyId, $patrolId, $checkpointId, $guardId,
        $latitude, $longitude, $accuracy, $device, $remarks
    );
    $stmt->execute();
    $stmt->close();

    header('Location: security_patrol_checkpoint_progress.php?patrol_id=' . $patrolId . '&saved=1');
    exit();
} catch (Throwable $t) {
    http_response_code(400);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="css/security_checkpoint.css"></head><body><main class="shell narrow"><div class="alert error"><strong>Gagal:</strong> ' . e($t->getMessage()) . '</div><a class="button" href="javascript:history.back()">Kembali</a></main></body></html>';
}
