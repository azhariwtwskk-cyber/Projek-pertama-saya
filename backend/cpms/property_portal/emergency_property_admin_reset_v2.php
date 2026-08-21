<?php
declare(strict_types=1);

const CPMS_RESET_ACCESS_KEY = 'l9a7skFXj4DmXguLMNoPp2dx';

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['cpms_emergency_reset_csrf'])) {
        $_SESSION['cpms_emergency_reset_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_emergency_reset_csrf'];
}

function verifyCsrf(?string $token): bool
{
    return isset($_SESSION['cpms_emergency_reset_csrf'])
        && is_string($token)
        && hash_equals((string) $_SESSION['cpms_emergency_reset_csrf'], $token);
}

$messages = [];
$errors = [];
$unlocked = !empty($_SESSION['cpms_emergency_reset_unlocked']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock'])) {
    $key = trim((string) ($_POST['access_key'] ?? ''));
    if (hash_equals(CPMS_RESET_ACCESS_KEY, $key)) {
        $_SESSION['cpms_emergency_reset_unlocked'] = true;
        $unlocked = true;
        $messages[] = 'Tool berjaya dibuka.';
    } else {
        $errors[] = 'Access key tidak betul.';
    }
}

$accounts = [];
if ($unlocked) {
    $sql = "SELECT pa.id, pa.property_id, pa.full_name, pa.username, pa.email,
                   pa.role, pa.status, p.property_name, p.property_code
            FROM property_admins pa
            LEFT JOIN cpms_properties p ON p.id = pa.property_id
            ORDER BY p.property_name, pa.full_name, pa.username";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
    } else {
        $errors[] = 'Tidak dapat membaca jadual property_admins: ' . $conn->error;
    }
}

if ($unlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token tidak sah. Refresh halaman dan cuba semula.';
    }

    $adminId = (int) ($_POST['admin_id'] ?? 0);
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $forceChange = isset($_POST['force_change']) ? 1 : 0;

    if ($adminId <= 0) {
        $errors[] = 'Sila pilih akaun Property Admin.';
    }
    if (strlen($newPassword) < 10) {
        $errors[] = 'Kata laluan mesti sekurang-kurangnya 10 aksara.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Pengesahan kata laluan tidak sama.';
    }

    if (!$errors) {
        $check = $conn->prepare(
            "SELECT id, username, email, property_id, role
             FROM property_admins WHERE id = ? LIMIT 1"
        );
        $check->bind_param('i', $adminId);
        $check->execute();
        $account = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$account) {
            $errors[] = 'Akaun tidak dijumpai.';
        } elseif (!in_array((string) $account['role'], ['property_admin', 'manager', 'clerk'], true)) {
            $errors[] = 'Role akaun ini tidak dibenarkan untuk Property Portal.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "UPDATE property_admins
                 SET password_hash = ?,
                     status = 'active',
                     must_change_password = ?,
                     password_changed_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('sii', $hash, $forceChange, $adminId);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                unset($_SESSION['property_login_rate']);
                $messages[] = 'Kata laluan berjaya ditetapkan semula.';
                $messages[] = 'Akaun telah diaktifkan dan sekatan percubaan login pada browser ini telah dibersihkan.';
                $messages[] = 'Login menggunakan username atau email yang dipaparkan di bawah.';
            } else {
                $errors[] = 'Reset gagal: ' . $conn->error;
            }
        }
    }
}

$csrf = csrfToken();
?>
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Emergency Property Admin Reset</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#172033;font-family:Arial,sans-serif;padding:24px}.card{max-width:820px;margin:auto;background:#fff;border-radius:18px;padding:28px;box-shadow:0 18px 55px rgba(15,23,42,.14)}h1{margin:0 0 8px}.muted{color:#64748b}.alert{padding:13px 15px;border-radius:10px;margin:12px 0}.ok{background:#ecfdf5;color:#166534}.err{background:#fef2f2;color:#991b1b}.warn{background:#fffbeb;color:#92400e}label{display:block;font-weight:700;margin-top:15px}input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;margin-top:6px;font-size:16px}button{margin-top:18px;padding:12px 18px;border:0;border-radius:9px;background:#0f4c81;color:#fff;font-weight:700;cursor:pointer}.danger{background:#b91c1c}.small{font-size:13px}code{background:#f1f5f9;padding:2px 5px;border-radius:5px}.account{margin-top:14px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px}
</style>
</head>
<body><main class="card">
<h1>CPMS Emergency Property Admin Reset</h1>
<p class="muted">Gunakan sekali sahaja. Padam fail ini selepas login berjaya.</p>
<?php foreach ($messages as $message): ?><div class="alert ok"><?= e($message) ?></div><?php endforeach; ?>
<?php foreach ($errors as $error): ?><div class="alert err"><?= e($error) ?></div><?php endforeach; ?>

<?php if (!$unlocked): ?>
<form method="post" autocomplete="off">
<label>Emergency access key</label>
<input type="password" name="access_key" required autofocus>
<button type="submit" name="unlock">Buka Tool</button>
</form>
<?php else: ?>
<div class="alert warn"><strong>Penting:</strong> Pilih akaun dan property yang betul. Kata laluan minimum 10 aksara.</div>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<label>Akaun Property Admin</label>
<select name="admin_id" required>
<option value="">-- Pilih akaun --</option>
<?php foreach ($accounts as $a): ?>
<option value="<?= (int) $a['id'] ?>"><?= e((string)($a['property_name'] ?: 'Property #' . $a['property_id'])) ?> [<?= e((string)($a['property_code'] ?? '')) ?>] — <?= e((string)$a['full_name']) ?> — <?= e((string)$a['username']) ?> — <?= e((string)$a['role']) ?> — <?= e((string)$a['status']) ?></option>
<?php endforeach; ?>
</select>
<label>Kata laluan baharu</label>
<input type="password" name="new_password" minlength="10" required>
<label>Ulang kata laluan</label>
<input type="password" name="confirm_password" minlength="10" required>
<label style="font-weight:400"><input style="width:auto" type="checkbox" name="force_change" value="1"> Paksa pengguna menukar kata laluan selepas login</label>
<button type="submit" name="reset_password">Reset Kata Laluan</button>
</form>
<div class="account small"><strong>Selepas reset:</strong><br>1. Buka URL login dengan property code yang betul.<br>2. Login menggunakan username atau email akaun tersebut.<br>3. Selepas berjaya, padam fail <code>emergency_property_admin_reset_v2.php</code>.</div>
<?php endif; ?>
</main></body></html>
