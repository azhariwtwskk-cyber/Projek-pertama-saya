<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/cpms_bootstrap.php';
require_once __DIR__ . '/../includes/password_recovery_service.php';

$isOwner = (string) ($_SESSION['cpms_user_role'] ?? '') === 'system_owner'
    || isset($_SESSION['system_owner_id']);
if (!$isOwner) {
    http_response_code(403);
    exit('System Owner access required.');
}

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!cpmsPasswordRecoveryVerifyCsrf(
        'cpms_password_setup_csrf',
        is_string($csrf) ? $csrf : null
    )) {
        $error = 'Invalid security token.';
    } else {
        $name = trim((string) ($_POST['sender_name'] ?? ''));
        $email = trim((string) ($_POST['sender_email'] ?? ''));
        $minutes = (int) ($_POST['lifetime'] ?? 30);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Masukkan nama dan alamat e-mel penghantar yang sah.';
        } else {
            $minutes = max(15, min(60, $minutes));
            $stmt = $conn->prepare(
                "INSERT INTO cpms_password_reset_settings
                    (id, sender_name, sender_email, reset_lifetime_minutes)
                 VALUES (1, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    sender_name = VALUES(sender_name),
                    sender_email = VALUES(sender_email),
                    reset_lifetime_minutes = VALUES(reset_lifetime_minutes)"
            );
            $stmt->bind_param('ssi', $name, $email, $minutes);
            $stmt->execute();
            $stmt->close();
            $message = 'Tetapan password recovery berjaya disimpan.';
        }
    }
}
$settings = cpmsPasswordRecoverySettings($conn);
$csrfToken = cpmsPasswordRecoveryCsrf('cpms_password_setup_csrf');
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password Recovery Setup | CPMS</title>
<style>
body{font:16px Arial;background:#eef3f9;color:#10213d;margin:0;padding:28px}
main{max-width:760px;margin:auto;background:#fff;padding:30px;border-radius:18px}
label{display:block;font-weight:700;margin:18px 0 7px}input{width:100%;
box-sizing:border-box;padding:12px;border:1px solid #cbd5e1;border-radius:9px}
button{margin-top:20px;padding:12px 18px;border:0;border-radius:9px;background:#174789;
color:#fff;font-weight:800}.ok,.bad{padding:12px;border-radius:8px}.ok{background:#dcfce7;
color:#166534}.bad{background:#fee2e2;color:#991b1b}code{overflow-wrap:anywhere}</style>
</head><body><main><h1>CPMS v3.4.2 — Password Recovery Setup</h1>
<?php if ($message): ?><p class="ok"><?= cpmsPasswordRecoveryEscape($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="bad"><?= cpmsPasswordRecoveryEscape($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsPasswordRecoveryEscape($csrfToken) ?>">
<label>Nama penghantar</label>
<input name="sender_name" value="<?= cpmsPasswordRecoveryEscape((string) ($settings['sender_name'] ?? 'CPMS Enterprise')) ?>" required>
<label>E-mel penghantar</label>
<input name="sender_email" type="email"
value="<?= cpmsPasswordRecoveryEscape((string) ($settings['sender_email'] ?? '')) ?>" required>
<label>Tempoh pautan reset (15–60 minit)</label>
<input name="lifetime" type="number" min="15" max="60"
value="<?= (int) ($settings['reset_lifetime_minutes'] ?? 30) ?>" required>
<button type="submit">Simpan Tetapan</button>
</form>
<p>Halaman pengguna: <code><?= cpmsPasswordRecoveryEscape(
    cpmsPasswordRecoveryBaseUrl() . '/forgot_password.php'
) ?></code></p>
</main></body></html>
