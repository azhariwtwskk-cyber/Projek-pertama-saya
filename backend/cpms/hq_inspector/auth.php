<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$id = (int) ($_SESSION['hq_inspector_id'] ?? 0);
$lastActivity = (int) ($_SESSION['hq_inspector_last_activity'] ?? 0);

if ($lastActivity > 0 && (time() - $lastActivity) > 1800) {
    unset($_SESSION['hq_inspector_id'], $_SESSION['hqi_csrf'], $_SESSION['hq_inspector_last_activity']);
    session_regenerate_id(true);
    hqiRedirect('login.php?expired=1');
}

if ($id < 1) {
    hqiRedirect('login.php');
}

$stmt = $conn->prepare(
    'SELECT id, inspector_code, full_name, username, email, phone, status
     FROM hq_inspectors
     WHERE id = ?
     LIMIT 1'
);

if (!$stmt) {
    hqiRedirect('login.php?error=account');
}

$stmt->bind_param('i', $id);
$stmt->execute();
$hqInspector = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$hqInspector || $hqInspector['status'] !== 'active') {
    unset($_SESSION['hq_inspector_id'], $_SESSION['hqi_csrf'], $_SESSION['hq_inspector_last_activity']);
    hqiRedirect('login.php?inactive=1');
}

$_SESSION['hq_inspector_last_activity'] = time();
