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

if (!isset($_SESSION['security_guard_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: cpms/login.php');
    exit();
}

$patrolId = (int)($_GET['patrol_id'] ?? 0);
$propertyId = currentPropertyId($conn);
if ($propertyId < 1) {
    header('Location: cpms/login.php?expired=1');
    exit();
}
$guardOnly = isset($_SESSION['security_guard_id']) && !isset($_SESSION['admin_id']);
$guardId = (int)($_SESSION['security_guard_id'] ?? 0);

$sql = "SELECT * FROM security_patrols WHERE id = ?";
$types = 'i'; $params = [$patrolId];
if ($guardOnly) { $sql .= " AND guard_id = ?"; $types .= 'i'; $params[] = $guardId; }
$sql .= " LIMIT 1";
$stmt = $conn->prepare($sql);
$refs = [$types]; foreach ($params as &$p) $refs[] = &$p;
call_user_func_array([$stmt,'bind_param'],$refs);
$stmt->execute();
$patrol = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$patrol) exit('Patrol tidak dijumpai.');

$stmt = $conn->prepare(
    "SELECT c.id, c.checkpoint_code, c.checkpoint_name, c.block_location,
            c.specific_location, c.scan_order,
            s.scanned_at, s.latitude, s.longitude, s.accuracy_meters, s.remarks
     FROM security_checkpoints c
     LEFT JOIN security_checkpoint_scans s
       ON s.checkpoint_id = c.id AND s.patrol_id = ?
     WHERE c.property_id = ? AND c.is_active = 1
     ORDER BY c.scan_order ASC, c.checkpoint_name ASC"
);
$stmt->bind_param('ii', $patrolId, $propertyId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = count($rows);
$done = count(array_filter($rows, fn($r) => !empty($r['scanned_at'])));
$percent = $total > 0 ? round(($done / $total) * 100) : 0;
?>
<!doctype html><html lang="ms"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Checkpoint Progress</title><link rel="stylesheet" href="css/security_checkpoint.css?v=1">
</head><body><main class="shell">
<header class="hero"><div><p class="eyebrow">PATROL <?= e($patrol['patrol_reference'] ?? '#' . $patrolId) ?></p>
<h1>Checkpoint Progress</h1><p><?= $done ?> / <?= $total ?> checkpoint selesai</p></div>
<div><a class="button" href="security_qr_scanner.php?patrol_id=<?= $patrolId ?>">Scan QR</a>
<a class="button secondary" href="security_dashboard.php">Dashboard</a></div></header>

<?php if (isset($_GET['saved'])): ?><div class="alert success">Checkpoint berjaya direkodkan.</div><?php endif; ?>

<section class="panel">
<div class="progress-meta"><strong><?= $percent ?>%</strong><span><?= $done ?> selesai · <?= max(0,$total-$done) ?> belum</span></div>
<div class="progress"><div style="width:<?= $percent ?>%"></div></div>
</section>

<section class="checkpoint-grid">
<?php foreach ($rows as $row): $scanned = !empty($row['scanned_at']); ?>
<article class="checkpoint-card <?= $scanned ? 'completed' : 'pending' ?>">
<div class="status-icon"><?= $scanned ? '✓' : (int)$row['scan_order'] ?></div>
<div><span class="code"><?= e($row['checkpoint_code']) ?></span><h2><?= e($row['checkpoint_name']) ?></h2>
<p><?= e(trim(($row['block_location'] ?? '') . ' ' . ($row['specific_location'] ?? ''))) ?></p>
<?php if ($scanned): ?><small>Diimbas: <?= e(date('d/m/Y h:i A', strtotime($row['scanned_at']))) ?></small><?php else: ?><small>Belum diimbas</small><?php endif; ?>
</div></article>
<?php endforeach; ?>
</section>
</main></body></html>
