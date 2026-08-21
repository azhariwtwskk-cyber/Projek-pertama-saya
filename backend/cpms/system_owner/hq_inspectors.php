<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$errors = [];
$success = '';

$tableReady = false;
$tableResult = $conn->query("SHOW TABLES LIKE 'hq_inspectors'");
if ($tableResult instanceof mysqli_result) {
    $tableReady = $tableResult->num_rows === 1;
    $tableResult->free();
}

if (!$tableReady) {
    $errors[] = 'Jadual hq_inspectors belum wujud. Import fail SQL Sprint 2.5.1 dahulu.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    if (!systemOwnerVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Sila muat semula halaman.';
    } else {
        $action = trim((string) ($_POST['action'] ?? 'create'));

        if ($action === 'create') {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($name === '') {
                $errors[] = 'Nama penuh diperlukan.';
            }
            if ($username === '') {
                $errors[] = 'Username diperlukan.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Kata laluan mesti sekurang-kurangnya 8 aksara.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Format e-mel tidak sah.';
            }

            if (!$errors) {
                $nextId = 1;
                $nextResult = $conn->query(
                    'SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM hq_inspectors'
                );
                if ($nextResult instanceof mysqli_result) {
                    $nextRow = $nextResult->fetch_assoc();
                    $nextId = (int) ($nextRow['next_id'] ?? 1);
                    $nextResult->free();
                }

                $code = 'HQI-' . str_pad((string) $nextId, 4, '0', STR_PAD_LEFT);
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO hq_inspectors (
                        inspector_code,
                        full_name,
                        username,
                        email,
                        phone,
                        password_hash,
                        status
                    ) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, 'active')"
                );

                if (!$stmt) {
                    $errors[] = 'Tidak dapat menyediakan rekod HQ Inspector.';
                } else {
                    $stmt->bind_param(
                        'ssssss',
                        $code,
                        $name,
                        $username,
                        $email,
                        $phone,
                        $hash
                    );

                    if ($stmt->execute()) {
                        $success = 'HQ Inspector berjaya ditambah: ' . $code;
                    } else {
                        if ((int) $stmt->errno === 1062) {
                            $errors[] = 'Username, e-mel atau Inspector ID sudah digunakan.';
                        } else {
                            $errors[] = 'HQ Inspector tidak dapat ditambah: ' . $stmt->error;
                        }
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'toggle_status') {
            $inspectorId = (int) ($_POST['inspector_id'] ?? 0);
            $newStatus = (string) ($_POST['new_status'] ?? 'inactive');
            if (!in_array($newStatus, ['active', 'inactive'], true)) {
                $newStatus = 'inactive';
            }

            $stmt = $conn->prepare(
                'UPDATE hq_inspectors SET status = ? WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('si', $newStatus, $inspectorId);
                if ($stmt->execute()) {
                    $success = 'Status HQ Inspector berjaya dikemas kini.';
                } else {
                    $errors[] = 'Status tidak dapat dikemas kini.';
                }
                $stmt->close();
            }
        } elseif ($action === 'reset_password') {
            $inspectorId = (int) ($_POST['inspector_id'] ?? 0);
            $newPassword = (string) ($_POST['new_password'] ?? '');

            if (strlen($newPassword) < 8) {
                $errors[] = 'Kata laluan baharu mesti sekurang-kurangnya 8 aksara.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    'UPDATE hq_inspectors SET password_hash = ? WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('si', $hash, $inspectorId);
                    if ($stmt->execute()) {
                        $success = 'Kata laluan HQ Inspector berjaya ditetapkan semula.';
                    } else {
                        $errors[] = 'Kata laluan tidak dapat ditetapkan semula.';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$rows = [];
if ($tableReady) {
    $result = $conn->query(
        'SELECT id, inspector_code, full_name, username, email, phone, status, last_login_at, created_at
         FROM hq_inspectors
         ORDER BY id DESC'
    );
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HQ Inspectors | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .page-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border:0;border-radius:9px;font-weight:800;text-decoration:none;cursor:pointer}
        .btn-primary{background:#0f172a;color:#fff}.btn-secondary{background:#e2e8f0;color:#334155}.btn-danger{background:#fee2e2;color:#991b1b}.btn-success{background:#dcfce7;color:#166534}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form-grid .full{grid-column:1/-1}
        label{display:block;font-size:13px;font-weight:800;margin-bottom:6px}input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px}
        .alert{padding:12px 14px;border-radius:10px;margin-bottom:14px}.alert-error{background:#fee2e2;color:#991b1b}.alert-success{background:#dcfce7;color:#166534}
        .status{display:inline-block;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:800}.status-active{background:#dcfce7;color:#166534}.status-inactive{background:#e2e8f0;color:#475569}
        .inline-form{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.inline-form input{width:180px;padding:8px}.small{font-size:12px;color:#64748b}
        @media(max-width:720px){.form-grid{grid-template-columns:1fr}.so-table{min-width:900px}}
    </style>
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>CPMS System Owner</strong><br>
            <small>Pengurusan HQ Inspector</small>
        </div>
        <a href="dashboard.php">Kembali ke Dashboard</a>
    </header>

    <div class="page-actions">
        <a class="btn btn-secondary" href="dashboard.php">← Dashboard</a>
        <a class="btn btn-primary" href="../hq_inspector/login.php" target="_blank" rel="noopener">Buka Login Inspector</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?php echo systemOwnerEscape($error); ?></div>
    <?php endforeach; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo systemOwnerEscape($success); ?></div>
    <?php endif; ?>

    <section class="so-panel">
        <h2>Tambah HQ Inspector</h2>
        <p class="small">Setiap HQ Inspector mempunyai akses automatik kepada semua property aktif.</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo systemOwnerEscape(systemOwnerCsrfToken()); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div><label>Nama penuh</label><input name="full_name" required></div>
                <div><label>Username</label><input name="username" required></div>
                <div><label>E-mel</label><input type="email" name="email"></div>
                <div><label>Telefon</label><input name="phone"></div>
                <div class="full"><label>Kata laluan (minimum 8 aksara)</label><input type="password" name="password" minlength="8" required></div>
            </div>
            <br><button class="btn btn-primary" type="submit">+ Tambah HQ Inspector</button>
        </form>
    </section>

    <section class="so-panel">
        <h2>Senarai HQ Inspector</h2>
        <div class="so-table-wrap">
            <table class="so-table">
                <thead><tr><th>ID</th><th>Nama</th><th>Username</th><th>Hubungan</th><th>Status</th><th>Last Login</th><th>Tindakan</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7">Tiada HQ Inspector ditemui.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?php echo systemOwnerEscape($row['inspector_code']); ?></strong></td>
                            <td><?php echo systemOwnerEscape($row['full_name']); ?></td>
                            <td><?php echo systemOwnerEscape($row['username']); ?></td>
                            <td><?php echo systemOwnerEscape($row['email'] ?: $row['phone'] ?: '-'); ?></td>
                            <td><span class="status status-<?php echo systemOwnerEscape($row['status']); ?>"><?php echo systemOwnerEscape(ucfirst($row['status'])); ?></span></td>
                            <td><?php echo systemOwnerEscape($row['last_login_at'] ?: 'Belum login'); ?></td>
                            <td>
                                <form class="inline-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo systemOwnerEscape(systemOwnerCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="inspector_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $row['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn <?php echo $row['status'] === 'active' ? 'btn-danger' : 'btn-success'; ?>" type="submit"><?php echo $row['status'] === 'active' ? 'Nyahaktif' : 'Aktifkan'; ?></button>
                                </form>
                                <form class="inline-form" method="post" style="margin-top:7px">
                                    <input type="hidden" name="csrf_token" value="<?php echo systemOwnerEscape(systemOwnerCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="inspector_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="password" name="new_password" minlength="8" placeholder="Kata laluan baharu" required>
                                    <button class="btn btn-secondary" type="submit">Reset</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</body>
</html>
