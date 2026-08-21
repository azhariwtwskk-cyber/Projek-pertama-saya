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

    return 1;
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

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}

if (!tableExists($conn, 'security_checkpoints')) {
    exit('Jadual security_checkpoints belum dipasang. Import fail SQL Langkah 5.7.');
}

$propertyId = currentPropertyId($conn);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $code = strtoupper(trim((string)($_POST['checkpoint_code'] ?? '')));
            $name = trim((string)($_POST['checkpoint_name'] ?? ''));
            $block = trim((string)($_POST['block_location'] ?? ''));
            $location = trim((string)($_POST['specific_location'] ?? ''));
            $order = max(0, (int)($_POST['scan_order'] ?? 0));

            if ($code === '' || $name === '') {
                throw new RuntimeException('Kod dan nama checkpoint diperlukan.');
            }

            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare(
                "INSERT INTO security_checkpoints
                (property_id, checkpoint_code, checkpoint_name, block_location,
                 specific_location, qr_token, scan_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $stmt->bind_param('isssssi', $propertyId, $code, $name, $block, $location, $token, $order);
            $stmt->execute();
            $stmt->close();
            $message = 'Checkpoint berjaya ditambah.';
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare(
                "UPDATE security_checkpoints
                 SET is_active = IF(is_active = 1, 0, 1)
                 WHERE id = ? AND property_id = ?"
            );
            $stmt->bind_param('ii', $id, $propertyId);
            $stmt->execute();
            $stmt->close();
            $message = 'Status checkpoint dikemas kini.';
        }

        if ($action === 'regenerate') {
            $id = (int)($_POST['id'] ?? 0);
            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare(
                "UPDATE security_checkpoints SET qr_token = ?
                 WHERE id = ? AND property_id = ?"
            );
            $stmt->bind_param('sii', $token, $id, $propertyId);
            $stmt->execute();
            $stmt->close();
            $message = 'QR Token baharu berjaya dijana.';
        }
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}

$stmt = $conn->prepare(
    "SELECT * FROM security_checkpoints
     WHERE property_id = ?
     ORDER BY scan_order ASC, checkpoint_name ASC"
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$checkpoints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR Patrol Checkpoints</title>
<link rel="stylesheet" href="css/security_checkpoint.css?v=1">
</head>
<body>
<main class="shell">
<header class="hero">
<div><p class="eyebrow">LANGKAH 5.7</p><h1>QR Patrol Checkpoints</h1>
<p>Urus lokasi rondaan dan cetak QR unik.</p></div>
<a class="button secondary" href="admin_dashboard.php">Admin Dashboard</a>
</header>

<?php if ($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
<h2>Tambah Checkpoint</h2>
<form method="post" class="form-grid">
<input type="hidden" name="action" value="create">
<label>Kod<input name="checkpoint_code" required placeholder="BLK-A-01"></label>
<label>Nama<input name="checkpoint_name" required placeholder="Block A Ground Floor"></label>
<label>Blok<input name="block_location" placeholder="Block A"></label>
<label>Lokasi khusus<input name="specific_location" placeholder="Ground Floor"></label>
<label>Susunan<input type="number" name="scan_order" min="0" value="0"></label>
<div class="form-action"><button type="submit">Tambah Checkpoint</button></div>
</form>
</section>

<section class="panel">
<div class="panel-head"><h2>Senarai Checkpoint</h2><span><?= count($checkpoints) ?> lokasi</span></div>
<div class="table-wrap"><table>
<thead><tr><th>Susunan</th><th>Kod</th><th>Nama</th><th>Lokasi</th><th>Status</th><th>Tindakan</th></tr></thead>
<tbody>
<?php foreach ($checkpoints as $cp): ?>
<tr>
<td><?= (int)$cp['scan_order'] ?></td>
<td><strong><?= e($cp['checkpoint_code']) ?></strong></td>
<td><?= e($cp['checkpoint_name']) ?></td>
<td><?= e(trim(($cp['block_location'] ?? '') . ' ' . ($cp['specific_location'] ?? ''))) ?></td>
<td><span class="badge <?= (int)$cp['is_active'] === 1 ? 'active' : 'inactive' ?>">
<?= (int)$cp['is_active'] === 1 ? 'Aktif' : 'Tidak Aktif' ?></span></td>
<td class="actions">
<a class="mini" href="security_checkpoint_qr.php?id=<?= (int)$cp['id'] ?>" target="_blank">QR / Print</a>
<form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$cp['id'] ?>"><button class="mini muted">Tukar Status</button></form>
<form method="post" onsubmit="return confirm('Jana QR baharu? QR lama tidak lagi sah.')"><input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="<?= (int)$cp['id'] ?>"><button class="mini danger">QR Baharu</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</section>
</main>
</body></html>
