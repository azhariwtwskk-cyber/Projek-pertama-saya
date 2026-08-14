<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!empty($_SESSION['system_owner_id'])) {
    systemOwnerRedirect('dashboard.php');
}

if (!systemOwnerAccountExists($conn)) {
    systemOwnerRedirect('register.php');
}

function cpmsSystemOwnerRateState(): array
{
    $state = $_SESSION['system_owner_login_rate'] ?? [];
    if (!is_array($state)) {
        $state = [];
    }

    $attempts = (int) ($state['attempts'] ?? 0);
    $lockedUntil = (int) ($state['locked_until'] ?? 0);

    if ($lockedUntil > 0 && $lockedUntil <= time()) {
        $attempts = 0;
        $lockedUntil = 0;
    }

    return [
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
    ];
}

function cpmsSystemOwnerRegisterFailure(): void
{
    $state = cpmsSystemOwnerRateState();
    $attempts = $state['attempts'] + 1;

    $_SESSION['system_owner_login_rate'] = [
        'attempts' => $attempts,
        'locked_until' => $attempts >= 5 ? time() + 900 : 0,
    ];
}

function cpmsSystemOwnerResetRate(): void
{
    unset($_SESSION['system_owner_login_rate']);
}

$errors = [];
$loginValue = '';
$rateState = cpmsSystemOwnerRateState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    $rateState = cpmsSystemOwnerRateState();

    if ($rateState['locked_until'] > time()) {
        $remainingMinutes = max(1, (int) ceil(
            ($rateState['locked_until'] - time()) / 60
        ));
        $errors[] = sprintf(
            'Terlalu banyak percubaan gagal. Cuba lagi dalam %d minit.',
            $remainingMinutes
        );
    }

    if (!systemOwnerVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }

    if ($loginValue === '' || $password === '') {
        $errors[] = 'Masukkan username/e-mel dan kata laluan.';
    }

    if (!$errors) {
        $stmt = $conn->prepare("
            SELECT
                users.id,
                users.full_name,
                users.username,
                users.email,
                users.password_hash,
                roles.role_code AS role,
                users.status
            FROM system_users users
            INNER JOIN user_roles assignments
               ON assignments.system_user_id = users.id
              AND assignments.status = 'active'
              AND assignments.property_id IS NULL
              AND (
                   assignments.expires_at IS NULL
                   OR assignments.expires_at > NOW()
              )
            INNER JOIN roles
               ON roles.id = assignments.role_id
              AND roles.role_code = 'system_owner'
              AND roles.status = 'active'
            WHERE users.username = ?
               OR users.email = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] = 'Login tidak dapat diproses.';
        } else {
            $stmt->bind_param('ss', $loginValue, $loginValue);
            $stmt->execute();

            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $valid =
                $user &&
                $user['status'] === 'active' &&
                $user['role'] === 'system_owner' &&
                password_verify($password, $user['password_hash']);

            if ($valid) {
                cpmsSystemOwnerResetRate();
                session_regenerate_id(true);

                $_SESSION['system_owner_id'] = (int) $user['id'];
                $_SESSION['system_owner_name'] = (string) $user['full_name'];
                $_SESSION['system_owner_role'] = (string) $user['role'];
                $_SESSION['system_owner_last_activity'] = time();

                $update = $conn->prepare("
                    UPDATE system_users
                    SET last_login_at = NOW()
                    WHERE id = ?
                ");

                if ($update) {
                    $id = (int) $user['id'];
                    $update->bind_param('i', $id);
                    $update->execute();
                    $update->close();
                }

                unset($_SESSION['system_owner_csrf']);
                systemOwnerRedirect('dashboard.php');
            }

            cpmsSystemOwnerRegisterFailure();
            $errors[] = 'Maklumat login tidak betul atau akaun tidak aktif.';
        }
    }
}

$csrfToken = systemOwnerCsrfToken();
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Owner Login | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
</head>
<body>
<div class="so-shell">
    <section class="so-brand-panel">
        <div class="so-brand">
            <div class="so-brand-mark">🏢</div>
            <div>
                <strong>CPMS</strong>
                <span>System Owner Portal</span>
            </div>
        </div>

        <div class="so-intro">
            <h1>Kawal semua property dari satu portal.</h1>
            <p>
                Portal ini berasingan daripada dashboard V23 dan digunakan
                untuk mengurus keseluruhan pemasangan CPMS.
            </p>
        </div>

        <div class="so-brand-footer">
            Commercial Property Management System
        </div>
    </section>

    <main class="so-form-panel">
        <section class="so-card">
            <h2>System Owner Login</h2>
            <p>Gunakan username atau e-mel yang telah didaftarkan.</p>

            <?php if (isset($_GET['registered'])): ?>
                <div class="so-alert so-alert-success">
                    Akaun berjaya dicipta. Sila log masuk.
                </div>
            <?php elseif (isset($_GET['logout'])): ?>
                <div class="so-alert so-alert-success">
                    Anda telah log keluar dengan selamat.
                </div>
            <?php elseif (isset($_GET['setup'])): ?>
                <div class="so-alert so-alert-success">
                    Portal telah disediakan. Sila log masuk.
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="so-alert so-alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div>• <?php echo systemOwnerEscape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="so-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo systemOwnerEscape($csrfToken); ?>"
                >

                <div class="so-field">
                    <label for="login">Username atau e-mel</label>
                    <input
                        class="so-input"
                        id="login"
                        name="login"
                        type="text"
                        value="<?php echo systemOwnerEscape($loginValue); ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="so-field">
                    <label for="password">Kata laluan</label>
                    <input
                        class="so-input"
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <p style="text-align:right;margin:4px 0 10px"><a href="forgot_password.php">Lupa kata laluan?</a></p>

                <button type="submit" class="so-button">
                    Log Masuk
                </button>
            </form>
        </section>
    </main>
</div>
</body>
</html>
