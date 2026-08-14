<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/auth_v2.php';
require_once dirname(__DIR__) . '/includes/property_user_sync.php';

if (empty($_SESSION['property_admin_id'])) {
    propertyPortalRedirect('login.php');
}

function cpv2Escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpv2CsrfToken(): string
{
    if (
        empty($_SESSION['property_change_password_csrf'])
        || !is_string($_SESSION['property_change_password_csrf'])
    ) {
        $_SESSION['property_change_password_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['property_change_password_csrf'];
}

function cpv2VerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['property_change_password_csrf'])
        && is_string($_SESSION['property_change_password_csrf'])
        && hash_equals($_SESSION['property_change_password_csrf'], $token);
}

$userId = (int) $_SESSION['property_admin_id'];
$propertyId = (int) ($_SESSION['property_admin_property_id'] ?? 0);
$required = isset($_GET['required']) || !empty($_SESSION['property_admin_force_password_change']);
$errors = [];
$success = false;

$stmt = $conn->prepare(
    "SELECT id, full_name, password_hash, status
     FROM property_admins
     WHERE id = ?
       AND property_id = ?
     LIMIT 1"
);

$user = null;
if ($stmt) {
    $stmt->bind_param('ii', $userId, $propertyId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$user || (string) $user['status'] !== 'active') {
    session_destroy();
    propertyPortalRedirect('login.php?inactive=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!cpv2VerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'The security session is invalid. Refresh the page and try again.';
    }

    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        $errors[] = 'The current password is incorrect.';
    }

    if (!cpmsAuthV2PasswordStrong($newPassword)) {
        $errors[] = 'Use at least 8 characters with at least one letter and one number.';
    }

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'The new password confirmation does not match.';
    }

    if (
        !$errors
        && cpmsAuthV2PasswordRecentlyUsed(
            $conn,
            'property_admin',
            $userId,
            $newPassword,
            5
        )
    ) {
        $errors[] = 'This password was used recently. Choose a different password.';
    }

    if (!$errors) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $conn->begin_transaction();

        try {
            cpmsAuthV2StorePasswordHistory(
                $conn,
                'property_admin',
                $userId,
                (string) $user['password_hash']
            );

            $update = $conn->prepare(
                "UPDATE property_admins
                 SET password_hash = ?,
                     must_change_password = 0,
                     password_changed_at = NOW()
                 WHERE id = ?
                   AND property_id = ?"
            );

            if (!$update) {
                throw new RuntimeException('Unable to update password.');
            }

            $update->bind_param('sii', $newHash, $userId, $propertyId);
            $update->execute();
            $update->close();
            cpmsPropertyUserSyncLegacyAccount(
                $conn,
                $userId,
                $propertyId
            );

            $conn->commit();

            unset($_SESSION['property_admin_force_password_change']);
            unset($_SESSION['property_change_password_csrf']);

            cpmsAuthV2Audit(
                $conn,
                'property_admin',
                $userId,
                $propertyId,
                'password_changed',
                'Password changed from the authenticated property portal.'
            );

            $success = true;
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = 'The password could not be changed. Please try again.';
        }
    }
}

$csrfToken = cpv2CsrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password | Property Portal</title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/commercial-login.css">
    <link rel="stylesheet" href="assets/auth-v2.css">
</head>
<body class="commercial-login-page">
<div class="password-recovery-page">
    <main class="password-recovery-card">
        <span class="commercial-eyebrow login-eyebrow">ACCOUNT SECURITY</span>
        <h1><?php echo $required ? 'Create a new password' : 'Change password'; ?></h1>
        <p>
            <?php echo $required
                ? 'You must change the temporary password before continuing.'
                : 'Use a strong password that you have not used recently.'; ?>
        </p>

        <?php if ($success): ?>
            <div class="commercial-alert commercial-alert-success" role="status">
                Your password has been changed successfully.
            </div>
            <div class="password-recovery-actions password-recovery-actions-primary">
                <a href="dashboard.php">Continue to Dashboard</a>
            </div>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="commercial-alert commercial-alert-danger" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo cpv2Escape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="commercial-login-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo cpv2Escape($csrfToken); ?>">

                <div class="commercial-field">
                    <label for="current_password">Current Password</label>
                    <div class="commercial-input-wrap">
                        <span class="commercial-input-icon" aria-hidden="true">●</span>
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                </div>

                <div class="commercial-field">
                    <label for="new_password">New Password</label>
                    <div class="commercial-input-wrap">
                        <span class="commercial-input-icon" aria-hidden="true">●</span>
                        <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="commercial-field">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="commercial-input-wrap">
                        <span class="commercial-input-icon" aria-hidden="true">●</span>
                        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                    </div>
                </div>

                <button class="commercial-submit" type="submit">Save New Password</button>
            </form>

            <?php if (!$required): ?>
                <div class="password-recovery-actions">
                    <a href="dashboard.php">Back to Dashboard</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
