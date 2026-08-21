<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/cpms_bootstrap.php';
require_once __DIR__ . '/includes/unified_auth.php';
require_once __DIR__ . '/includes/unified_auth_audit.php';
require_once __DIR__ . '/includes/password_recovery_service.php';

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
if ($userId < 1) {
    cpmsPortalRedirect('login.php');
}
$stmt = $conn->prepare(
    'SELECT username, full_name, password_hash, must_change_password
     FROM system_users WHERE id = ? AND status = ? LIMIT 1'
);
$active = 'active';
$stmt->bind_param('is', $userId, $active);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($user)) {
    cpmsPortalRedirect('login.php');
}

function cpmsPasswordChangeDestination(): string
{
    $role = (string) ($_SESSION['cpms_user_role'] ?? '');
    if ($role === 'system_owner') {
        return 'system_owner/dashboard.php';
    }
    if (in_array($role, ['property_admin', 'manager', 'clerk'], true)) {
        return 'property_portal/dashboard.php';
    }
    if ($role === 'staff') {
        return '../staff_dashboard.php';
    }
    if ($role === 'security') {
        return '../security_dashboard.php';
    }
    return 'login.php';
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    $current = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (!cpmsPasswordRecoveryVerifyCsrf(
        'cpms_change_password_csrf',
        is_string($csrf) ? $csrf : null
    )) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }
    $verification = cpmsUnifiedAuthVerifyPassword(
        $current,
        (string) $user['password_hash']
    );
    if (empty($verification['valid'])) {
        $errors[] = 'Kata laluan sementara/semasa tidak betul.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'Pengesahan kata laluan tidak sepadan.';
    }
    if ($password === $current) {
        $errors[] = 'Kata laluan baharu mesti berbeza.';
    }
    $errors = array_merge(
        $errors,
        cpmsPasswordRecoveryValidatePassword($password)
    );
    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        try {
            $update = $conn->prepare(
                'UPDATE system_users SET password_hash = ?,
                 must_change_password = 0, updated_at = NOW() WHERE id = ?'
            );
            $update->bind_param('si', $hash, $userId);
            $update->execute();
            $update->close();
            cpmsPropertyUserMirrorPasswordFromSystem($conn, $userId);

            $sessionHash = hash('sha256', session_id());
            $type = 'system_user';
            $revoke = $conn->prepare(
                'UPDATE cpms_auth_sessions SET revoked_at = NOW()
                 WHERE user_type = ? AND user_id = ?
                   AND session_hash <> ? AND revoked_at IS NULL'
            );
            $revoke->bind_param('sis', $type, $userId, $sessionHash);
            $revoke->execute();
            $revoke->close();
            $conn->commit();

            cpmsUnifiedAuditEvent(
                $conn,
                'forced_password_change_completed',
                $userId,
                (int) ($_SESSION['cpms_property_id'] ?? 0) ?: null,
                (string) ($_SESSION['cpms_user_role'] ?? ''),
                'User replaced temporary password.'
            );
            unset($_SESSION['cpms_change_password_csrf']);
            cpmsPortalRedirect(cpmsPasswordChangeDestination());
        } catch (Throwable $exception) {
            $conn->rollback();
            $errors[] = 'Kata laluan tidak dapat disimpan. Cuba semula.';
        }
    }
}
$csrfToken = cpmsPasswordRecoveryCsrf('cpms_change_password_csrf');
?>
<!doctype html><html lang="ms"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tukar Kata Laluan | CPMS</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;
font:16px Arial;color:#172033;background:linear-gradient(145deg,#eaf1fa,#f8fafc)}
main{width:min(480px,calc(100% - 28px));background:#fff;padding:34px;border-radius:20px;
box-shadow:0 22px 55px rgba(15,35,66,.14)}h1{margin:0 0 10px;color:#0f2342}
p{line-height:1.5;color:#64748b}.notice{padding:13px;background:#fef3c7;color:#854d0e;
border-radius:9px}.bad{padding:13px;background:#fee2e2;color:#991b1b;border-radius:9px}
label{display:block;font-weight:700;margin:16px 0 7px}input{width:100%;padding:13px;
border:1px solid #cbd5e1;border-radius:10px;font-size:16px}button{width:100%;
margin-top:20px;padding:13px;border:0;border-radius:10px;background:#174789;color:#fff;
font-weight:800;font-size:15px}</style></head><body><main>
<h1>Tukar Kata Laluan</h1>
<?php if ((int) $user['must_change_password'] === 1): ?>
<p class="notice">Untuk keselamatan, anda mesti menukar kata laluan sementara sebelum membuka dashboard.</p>
<?php endif; ?>
<p>Akaun: <strong><?= cpmsPasswordRecoveryEscape((string) $user['username']) ?></strong></p>
<?php if ($errors): ?><div class="bad"><?php foreach ($errors as $error): ?>
<div><?= cpmsPasswordRecoveryEscape($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= cpmsPasswordRecoveryEscape($csrfToken) ?>">
<label>Kata laluan sementara/semasa</label>
<input name="current_password" type="password" autocomplete="current-password" required>
<label>Kata laluan baharu</label>
<input name="password" type="password" autocomplete="new-password" required>
<label>Sahkan kata laluan baharu</label>
<input name="password_confirmation" type="password" autocomplete="new-password" required>
<p>Minimum 10 aksara dengan huruf besar, huruf kecil dan nombor.</p>
<button type="submit">Tukar dan Buka Dashboard</button>
</form></main></body></html>
