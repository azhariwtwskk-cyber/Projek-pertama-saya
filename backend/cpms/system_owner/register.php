<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (systemOwnerAccountExists($conn)) {
    systemOwnerRedirect('login.php?setup=complete');
}

$errors = [];
$fullName = '';
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!systemOwnerVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }

    if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 150) {
        $errors[] = 'Nama penuh mesti antara 3 hingga 150 aksara.';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{4,80}$/', $username)) {
        $errors[] = 'Username mesti 4–80 aksara tanpa ruang.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Alamat e-mel tidak sah.';
    }

    if (strlen($password) < 10) {
        $errors[] = 'Kata laluan mesti sekurang-kurangnya 10 aksara.';
    }

    if (
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    ) {
        $errors[] = 'Kata laluan mesti mempunyai huruf besar, huruf kecil dan nombor.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Pengesahan kata laluan tidak sepadan.';
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'system_owner';
        $status = 'active';
        $emailValue = $email !== '' ? $email : null;

        $stmt = $conn->prepare("
            INSERT INTO system_users
                (
                    full_name,
                    username,
                    email,
                    password_hash,
                    role,
                    status
                )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            $errors[] = 'Pendaftaran tidak dapat diproses.';
        } else {
            $stmt->bind_param(
                'ssssss',
                $fullName,
                $username,
                $emailValue,
                $passwordHash,
                $role,
                $status
            );

            if ($stmt->execute()) {
                $stmt->close();
                unset($_SESSION['system_owner_csrf']);
                systemOwnerRedirect('login.php?registered=1');
            }

            if ($conn->errno === 1062) {
                $errors[] = 'Username atau e-mel sudah digunakan.';
            } else {
                $errors[] = 'Akaun tidak dapat dicipta.';
            }

            $stmt->close();
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
    <title>Daftar System Owner | CPMS</title>
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
            <h1>Sediakan pemilik sistem pertama.</h1>
            <p>
                Akaun ini mempunyai akses tertinggi untuk mengurus semua
                property dan konfigurasi global CPMS.
            </p>
        </div>

        <div class="so-brand-footer">
            Commercial Property Management System
        </div>
    </section>

    <main class="so-form-panel">
        <section class="so-card">
            <h2>Daftar System Owner</h2>
            <p>Pendaftaran ini hanya boleh digunakan sekali.</p>

            <?php if ($errors): ?>
                <div class="so-alert so-alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div>• <?php echo systemOwnerEscape($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="so-form" autocomplete="off">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo systemOwnerEscape($csrfToken); ?>"
                >

                <div class="so-field">
                    <label for="full_name">Nama penuh</label>
                    <input
                        class="so-input"
                        id="full_name"
                        name="full_name"
                        type="text"
                        maxlength="150"
                        value="<?php echo systemOwnerEscape($fullName); ?>"
                        required
                    >
                </div>

                <div class="so-field">
                    <label for="username">Username</label>
                    <input
                        class="so-input"
                        id="username"
                        name="username"
                        type="text"
                        maxlength="80"
                        value="<?php echo systemOwnerEscape($username); ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="so-field">
                    <label for="email">E-mel</label>
                    <input
                        class="so-input"
                        id="email"
                        name="email"
                        type="email"
                        maxlength="190"
                        value="<?php echo systemOwnerEscape($email); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="password">Kata laluan</label>
                    <input
                        class="so-input"
                        id="password"
                        name="password"
                        type="password"
                        minlength="10"
                        autocomplete="new-password"
                        required
                    >
                    <span class="so-note">
                        Minimum 10 aksara, huruf besar, huruf kecil dan nombor.
                    </span>
                </div>

                <div class="so-field">
                    <label for="confirm_password">Sahkan kata laluan</label>
                    <input
                        class="so-input"
                        id="confirm_password"
                        name="confirm_password"
                        type="password"
                        minlength="10"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="so-button">
                    Cipta Akaun System Owner
                </button>
            </form>
        </section>
    </main>
</div>
</body>
</html>
