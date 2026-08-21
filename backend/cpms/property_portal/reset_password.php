<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/branding.php';
require_once dirname(__DIR__) . '/includes/property_user_sync.php';

function rpEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rpCsrfToken(): string
{
    if (
        empty($_SESSION['property_reset_password_csrf'])
        || !is_string($_SESSION['property_reset_password_csrf'])
    ) {
        $_SESSION['property_reset_password_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['property_reset_password_csrf'];
}

function rpVerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['property_reset_password_csrf'])
        && is_string($_SESSION['property_reset_password_csrf'])
        && hash_equals($_SESSION['property_reset_password_csrf'], $token);
}

$propertyCode = strtoupper(trim((string) ($_POST['property_code'] ?? $_GET['property'] ?? '')));
$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$errors = [];
$success = false;
$record = null;

if ($tokenHash !== '') {
    $stmt = $conn->prepare(
        "SELECT
            prt.id,
            prt.user_id,
            prt.property_id,
            prt.email,
            p.property_code,
            p.property_name
         FROM password_reset_tokens prt
         INNER JOIN cpms_properties p ON p.id = prt.property_id
         INNER JOIN property_admins pa ON pa.id = prt.user_id
         WHERE prt.user_type = 'property_admin'
           AND prt.token_hash = ?
           AND prt.used_at IS NULL
           AND prt.expires_at > NOW()
           AND pa.status = 'active'
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$record) {
    $errors[] = 'This reset link is invalid, expired or has already been used.';
} else {
    $propertyCode = (string) $record['property_code'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $record) {
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!rpVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'The security session is invalid. Refresh the page and try again.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'The new password must contain at least 8 characters.';
    }

    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Use at least one letter and one number.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'The password confirmation does not match.';
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = (int) $record['user_id'];
        $resetId = (int) $record['id'];

        $conn->begin_transaction();

        try {
            $update = $conn->prepare(
                "UPDATE property_admins
                 SET password_hash = ?
                 WHERE id = ?"
            );
            if (!$update) {
                throw new RuntimeException('Unable to update account.');
            }

            $update->bind_param('si', $passwordHash, $userId);
            $update->execute();
            $update->close();
            cpmsPropertyUserSyncLegacyAccount(
                $conn,
                $userId,
                (int) $record['property_id']
            );

            $useToken = $conn->prepare(
                "UPDATE password_reset_tokens
                 SET used_at = NOW()
                 WHERE id = ?
                   AND used_at IS NULL"
            );
            if (!$useToken) {
                throw new RuntimeException('Unable to close reset request.');
            }

            $useToken->bind_param('i', $resetId);
            $useToken->execute();
            $useToken->close();

            $invalidate = $conn->prepare(
                "UPDATE password_reset_tokens
                 SET used_at = NOW()
                 WHERE user_type = 'property_admin'
                   AND user_id = ?
                   AND used_at IS NULL"
            );
            if ($invalidate) {
                $invalidate->bind_param('i', $userId);
                $invalidate->execute();
                $invalidate->close();
            }

            $conn->commit();
            unset($_SESSION['property_login_rate']);
            unset($_SESSION['property_reset_password_csrf']);
            $success = true;
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = 'The password could not be updated. Please try again.';
        }
    }
}

$csrfToken = rpCsrfToken();
$propertyName = $record ? (string) $record['property_name'] : 'Property Portal';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password | <?php echo rpEscape($propertyName); ?></title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/commercial-login.css">
    <link rel="stylesheet" href="assets/auth-v2.css">
    <link rel="stylesheet" href="assets/auth-v2-1.css">
</head>
<body class="commercial-login-page">
<div class="password-recovery-page">
    <main class="password-recovery-card">
        <span class="commercial-eyebrow login-eyebrow">ACCOUNT RECOVERY</span>
        <h1>Set a new password</h1>

        <?php if ($success): ?>
            <div class="commercial-alert commercial-alert-success" role="status">
                Your password has been updated successfully.
            </div>

            <div class="password-recovery-actions password-recovery-actions-primary">
                <a href="login.php?property=<?php echo rawurlencode($propertyCode); ?>">
                    Continue to Sign In
                </a>
            </div>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="commercial-alert commercial-alert-danger" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo rpEscape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($record): ?>
                <p>Create a password containing at least 8 characters, one letter and one number.</p>

                <form method="post" class="commercial-login-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo rpEscape($csrfToken); ?>">
                    <input type="hidden" name="token" value="<?php echo rpEscape($token); ?>">
                    <input type="hidden" name="property_code" value="<?php echo rpEscape($propertyCode); ?>">

                    <div class="commercial-field">
                        <label for="password">New Password</label>
                        <div class="commercial-input-wrap">
                            <span class="commercial-input-icon" aria-hidden="true">●</span>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                    </div>

                    <div class="commercial-field">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="commercial-input-wrap">
                            <span class="commercial-input-icon" aria-hidden="true">●</span>
                            <input
                                id="confirm_password"
                                name="confirm_password"
                                type="password"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                    </div>

                    <button class="commercial-submit" type="submit">Save New Password</button>
                </form>
            <?php endif; ?>

            <div class="password-recovery-actions">
                <a href="login.php<?php echo $propertyCode !== '' ? '?property=' . rawurlencode($propertyCode) : ''; ?>">
                    Back to Sign In
                </a>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
