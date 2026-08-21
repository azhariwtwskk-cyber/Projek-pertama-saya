<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/cpms_bootstrap.php';
require_once __DIR__ . '/includes/password_recovery_service.php';
require_once __DIR__ . '/includes/unified_auth_audit.php';

$rawToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$parts = explode(':', $rawToken, 2);
$selector = (string) ($parts[0] ?? '');
$verifier = (string) ($parts[1] ?? '');
$token = cpmsPasswordRecoveryFindToken($conn, $selector, $verifier);
$errors = [];
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $token) {
    $csrf = $_POST['csrf_token'] ?? null;
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (!cpmsPasswordRecoveryVerifyCsrf(
        'cpms_reset_password_csrf',
        is_string($csrf) ? $csrf : null
    )) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'Pengesahan kata laluan tidak sepadan.';
    }
    $errors = array_merge(
        $errors,
        cpmsPasswordRecoveryValidatePassword($password)
    );
    if (!$errors) {
        try {
            cpmsPasswordRecoveryComplete($conn, $token, $password);
            cpmsUnifiedAuditEvent(
                $conn,
                'password_reset_completed',
                (int) $token['system_user_id'],
                null,
                null,
                'Password reset completed; active sessions revoked.'
            );
            unset($_SESSION['cpms_reset_password_csrf']);
            unset($_SESSION['cpms_unified_login_rate']);
            $success = true;
            $token = null;
        } catch (Throwable $exception) {
            $errors[] = 'Reset tidak dapat diselesaikan. Sila cuba semula.';
            if (function_exists('cpmsFoundationLog')) {
                cpmsFoundationLog(
                    'Password reset completion failed: '
                    . $exception->getMessage()
                );
            }
        }
    }
}
$csrfToken = cpmsPasswordRecoveryCsrf('cpms_reset_password_csrf');
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Kata Laluan | CPMS</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;
font:16px Arial;color:#172033;background:linear-gradient(145deg,#eaf1fa,#f8fafc)}
.card{width:min(480px,calc(100% - 28px));background:#fff;padding:34px;border-radius:20px;
box-shadow:0 22px 55px rgba(15,35,66,.14)}h1{margin:0 0 10px;color:#0f2342}
p{line-height:1.55;color:#64748b}.alert{padding:13px;border-radius:9px;margin:16px 0}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}
label{display:block;font-weight:700;margin:16px 0 7px}input{width:100%;padding:13px;
border:1px solid #cbd5e1;border-radius:10px;font-size:16px}
button{width:100%;margin-top:18px;padding:13px;border:0;border-radius:10px;
background:#174789;color:#fff;font-weight:800;font-size:15px}a{color:#174789;font-weight:700}
</style></head><body><main class="card">
<?php if ($success): ?>
<h1>Kata Laluan Berjaya Ditukar</h1>
<div class="alert ok">Semua sesi lama telah dibatalkan. Sila log masuk menggunakan kata laluan baharu.</div>
<p><a href="login.php?reset=1">Log Masuk Sekarang</a></p>
<?php elseif (!$token): ?>
<h1>Pautan Tidak Sah</h1>
<div class="alert bad">Pautan reset telah tamat tempoh, telah digunakan atau tidak sah.</div>
<p><a href="forgot_password.php">Mohon pautan baharu</a></p>
<?php else: ?>
<h1>Tetapkan Kata Laluan Baharu</h1>
<p>Akaun: <strong><?= cpmsPasswordRecoveryEscape((string) $token['username']) ?></strong></p>
<?php if ($errors): ?><div class="alert bad"><?php foreach ($errors as $error): ?>
<div><?= cpmsPasswordRecoveryEscape($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsPasswordRecoveryEscape($csrfToken) ?>">
<input type="hidden" name="token" value="<?= cpmsPasswordRecoveryEscape($rawToken) ?>">
<label for="password">Kata laluan baharu</label>
<input id="password" name="password" type="password" autocomplete="new-password" required>
<label for="confirmation">Sahkan kata laluan</label>
<input id="confirmation" name="password_confirmation" type="password"
autocomplete="new-password" required>
<p>Minimum 10 aksara serta mengandungi huruf besar, huruf kecil dan nombor.</p>
<button type="submit">Simpan Kata Laluan Baharu</button>
</form>
<?php endif; ?>
</main></body></html>
