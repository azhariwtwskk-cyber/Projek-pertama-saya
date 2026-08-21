<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/property_user_sync.php';

$errors = [];
$properties = [];

$result = $conn->query("
    SELECT id, property_code, property_name
    FROM cpms_properties
    ORDER BY property_name ASC
");

if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }
}

$form = [
    'property_id' => '',
    'full_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'position_title' => 'Property Admin',
    'role' => 'property_admin',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $defaultValue) {
        $form[$key] = trim((string) ($_POST[$key] ?? $defaultValue));
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!systemOwnerVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }

    $propertyId = (int) $form['property_id'];

    if ($propertyId <= 0) {
        $errors[] = 'Sila pilih property.';
    }

    if (mb_strlen($form['full_name']) < 3 || mb_strlen($form['full_name']) > 150) {
        $errors[] = 'Nama penuh mesti antara 3 hingga 150 aksara.';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{4,80}$/', $form['username'])) {
        $errors[] = 'Username mesti 4–80 aksara tanpa ruang.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Alamat e-mel tidak sah.';
    }

    if (!in_array($form['role'], ['property_admin', 'manager', 'clerk'], true)) {
        $errors[] = 'Peranan tidak sah.';
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
        $propertyCheck = $conn->prepare("
            SELECT id
            FROM cpms_properties
            WHERE id = ?
            LIMIT 1
        ");

        if (!$propertyCheck) {
            $errors[] = 'Property tidak dapat disahkan.';
        } else {
            $propertyCheck->bind_param('i', $propertyId);
            $propertyCheck->execute();

            $propertyExists = $propertyCheck
                ->get_result()
                ->fetch_assoc();

            $propertyCheck->close();

            if (!$propertyExists) {
                $errors[] = 'Property yang dipilih tidak wujud.';
            }
        }
    }

    if (!$errors) {
        $duplicate = $conn->prepare("
            SELECT id
            FROM property_admins
            WHERE username = ?
               OR (
                    email IS NOT NULL
                    AND email <> ''
                    AND email = ?
               )
            LIMIT 1
        ");

        if (!$duplicate) {
            $errors[] = 'Semakan akaun tidak dapat dilakukan.';
        } else {
            $duplicate->bind_param(
                'ss',
                $form['username'],
                $form['email']
            );
            $duplicate->execute();

            $existing = $duplicate
                ->get_result()
                ->fetch_assoc();

            $duplicate->close();

            if ($existing) {
                $errors[] = 'Username atau e-mel sudah digunakan.';
            }
        }
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $status = 'active';
        $emailValue = $form['email'] !== '' ? $form['email'] : null;
        $phoneValue = $form['phone'] !== '' ? $form['phone'] : null;

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                INSERT INTO property_admins
                    (
                        property_id,
                        full_name,
                        username,
                        email,
                        phone,
                        position_title,
                        password_hash,
                        role,
                        status
                    )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new RuntimeException('Akaun Property Admin tidak dapat disediakan.');
            }
            $stmt->bind_param(
                'issssssss',
                $propertyId,
                $form['full_name'],
                $form['username'],
                $emailValue,
                $phoneValue,
                $form['position_title'],
                $passwordHash,
                $form['role'],
                $status
            );
            if (!$stmt->execute()) {
                $duplicate = $stmt->errno === 1062;
                $stmt->close();
                throw new RuntimeException(
                    $duplicate
                        ? 'Username atau e-mel sudah digunakan.'
                        : 'Akaun Property Admin gagal disimpan.'
                );
            }
            $legacyId = (int) $conn->insert_id;
            $stmt->close();
            cpmsPropertyUserSyncLegacyAccount(
                $conn,
                $legacyId,
                $propertyId
            );
            $conn->commit();
            unset($_SESSION['system_owner_csrf']);
            systemOwnerRedirect('property_admins.php?created=1');
        } catch (Throwable $exception) {
            $conn->rollback();
            $errors[] = $exception->getMessage();
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
    <title>Cipta Property Admin | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .so-wide-card {
            width:min(100%,820px);
        }

        .so-form-columns {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:17px;
        }

        .so-field-full {
            grid-column:1 / -1;
        }

        .so-form-actions {
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top:8px;
        }

        .so-cancel {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:47px;
            padding:10px 16px;
            border-radius:10px;
            background:#e9eef5;
            color:#334155;
            text-decoration:none;
            font-size:13px;
            font-weight:800;
        }

        @media (max-width:680px) {
            .so-form-columns {
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body>
<div class="so-form-panel">
    <section class="so-card so-wide-card">
        <h2>Cipta Property Admin</h2>
        <p>
            Akaun ini akan dipautkan terus kepada property yang dipilih.
        </p>

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

            <div class="so-form-columns">
                <div class="so-field so-field-full">
                    <label for="property_id">Property *</label>
                    <select
                        class="so-input"
                        id="property_id"
                        name="property_id"
                        required
                    >
                        <option value="">-- Pilih Property --</option>

                        <?php foreach ($properties as $property): ?>
                            <option
                                value="<?php echo (int) $property['id']; ?>"
                                <?php echo (string) $property['id'] === $form['property_id'] ? 'selected' : ''; ?>
                            >
                                <?php
                                echo systemOwnerEscape(
                                    $property['property_code'] .
                                    ' - ' .
                                    $property['property_name']
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="so-field">
                    <label for="full_name">Nama penuh *</label>
                    <input
                        class="so-input"
                        id="full_name"
                        name="full_name"
                        type="text"
                        maxlength="150"
                        value="<?php echo systemOwnerEscape($form['full_name']); ?>"
                        required
                    >
                </div>

                <div class="so-field">
                    <label for="position_title">Jawatan</label>
                    <input
                        class="so-input"
                        id="position_title"
                        name="position_title"
                        type="text"
                        maxlength="100"
                        value="<?php echo systemOwnerEscape($form['position_title']); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="username">Username *</label>
                    <input
                        class="so-input"
                        id="username"
                        name="username"
                        type="text"
                        maxlength="80"
                        autocomplete="username"
                        value="<?php echo systemOwnerEscape($form['username']); ?>"
                        required
                    >
                </div>

                <div class="so-field">
                    <label for="role">Peranan *</label>
                    <select
                        class="so-input"
                        id="role"
                        name="role"
                        required
                    >
                        <option value="property_admin" <?php echo $form['role'] === 'property_admin' ? 'selected' : ''; ?>>
                            Property Admin
                        </option>
                        <option value="manager" <?php echo $form['role'] === 'manager' ? 'selected' : ''; ?>>
                            Manager
                        </option>
                        <option value="clerk" <?php echo $form['role'] === 'clerk' ? 'selected' : ''; ?>>
                            Clerk
                        </option>
                    </select>
                </div>

                <div class="so-field">
                    <label for="email">E-mel</label>
                    <input
                        class="so-input"
                        id="email"
                        name="email"
                        type="email"
                        maxlength="190"
                        value="<?php echo systemOwnerEscape($form['email']); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="phone">Telefon</label>
                    <input
                        class="so-input"
                        id="phone"
                        name="phone"
                        type="text"
                        maxlength="50"
                        value="<?php echo systemOwnerEscape($form['phone']); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="password">Kata laluan *</label>
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
                    <label for="confirm_password">Sahkan kata laluan *</label>
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
            </div>

            <div class="so-form-actions">
                <button type="submit" class="so-button">
                    Cipta Akaun
                </button>

                <a class="so-cancel" href="property_admins.php">
                    Batal
                </a>
            </div>
        </form>
    </section>
</div>
</body>
</html>
