<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$errors = [];

$form = [
    'property_code' => '',
    'property_name' => '',
    'company_name' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'website' => '',
    'logo_path' => 'images/logo.png',
    'primary_color' => '#0f172a',
    'secondary_color' => '#1e293b',
    'default_language' => 'ms',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $defaultValue) {
        $form[$key] = trim((string) ($_POST[$key] ?? $defaultValue));
    }

    $csrf = $_POST['csrf_token'] ?? null;

    if (!systemOwnerVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman.';
    }

    $form['property_code'] = strtoupper($form['property_code']);

    if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $form['property_code'])) {
        $errors[] = 'Kod property mesti 2–20 aksara: huruf besar, nombor, _ atau - sahaja.';
    }

    if (
        mb_strlen($form['property_name']) < 3 ||
        mb_strlen($form['property_name']) > 190
    ) {
        $errors[] = 'Nama property mesti antara 3 hingga 190 aksara.';
    }

    if (mb_strlen($form['company_name']) > 190) {
        $errors[] = 'Nama syarikat terlalu panjang.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Alamat e-mel tidak sah.';
    }

    if (
        $form['website'] !== '' &&
        !filter_var($form['website'], FILTER_VALIDATE_URL)
    ) {
        $errors[] = 'Alamat website mesti lengkap, contohnya https://example.com.';
    }

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $form['primary_color'])) {
        $errors[] = 'Primary color tidak sah.';
    }

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $form['secondary_color'])) {
        $errors[] = 'Secondary color tidak sah.';
    }

    if (!in_array($form['default_language'], ['ms', 'en'], true)) {
        $errors[] = 'Bahasa lalai tidak sah.';
    }

    if (!$errors) {
        $check = $conn->prepare("
            SELECT id
            FROM cpms_properties
            WHERE property_code = ?
               OR property_name = ?
            LIMIT 1
        ");

        if (!$check) {
            $errors[] = 'Semakan property tidak dapat dilakukan.';
        } else {
            $check->bind_param(
                'ss',
                $form['property_code'],
                $form['property_name']
            );
            $check->execute();

            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $errors[] = 'Kod atau nama property sudah wujud.';
            }
        }
    }

    if (!$errors) {
        $stmt = $conn->prepare("
            INSERT INTO cpms_properties
                (
                    property_code,
                    property_name,
                    company_name,
                    address,
                    phone,
                    email,
                    website,
                    logo_path,
                    primary_color,
                    secondary_color,
                    default_language
                )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            $errors[] = 'Property tidak dapat disimpan. Semak struktur cpms_properties.';
        } else {
            $stmt->bind_param(
                'sssssssssss',
                $form['property_code'],
                $form['property_name'],
                $form['company_name'],
                $form['address'],
                $form['phone'],
                $form['email'],
                $form['website'],
                $form['logo_path'],
                $form['primary_color'],
                $form['secondary_color'],
                $form['default_language']
            );

            if ($stmt->execute()) {
                $stmt->close();
                unset($_SESSION['system_owner_csrf']);

                systemOwnerRedirect('properties.php?created=1');
            }

            if ($conn->errno === 1062) {
                $errors[] = 'Kod atau nama property sudah digunakan.';
            } else {
                $errors[] = 'Property gagal disimpan ke database.';
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
    <title>Tambah Property | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .so-wide-card {
            width: min(100%, 820px);
        }

        .so-form-columns {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        .so-field-full {
            grid-column: 1 / -1;
        }

        .so-textarea {
            min-height: 90px;
            resize: vertical;
        }

        .so-form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 6px;
        }

        .so-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 47px;
            padding: 10px 16px;
            border-radius: 10px;
            background: #e9eef5;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
        }

        @media (max-width: 680px) {
            .so-form-columns {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="so-form-panel">
    <section class="so-card so-wide-card">
        <h2>Tambah Property Baharu</h2>
        <p>
            Property ini akan menjadi projek berasingan dalam CPMS.
            Jangan masukkan data sebenar dahulu jika tujuan anda hanya untuk ujian.
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
                <div class="so-field">
                    <label for="property_code">Kod Property *</label>
                    <input
                        class="so-input"
                        id="property_code"
                        name="property_code"
                        type="text"
                        maxlength="20"
                        placeholder="CONTOH: SGC"
                        value="<?php echo systemOwnerEscape($form['property_code']); ?>"
                        required
                    >
                </div>

                <div class="so-field">
                    <label for="property_name">Nama Property *</label>
                    <input
                        class="so-input"
                        id="property_name"
                        name="property_name"
                        type="text"
                        maxlength="190"
                        placeholder="Sri Gaya Condominium"
                        value="<?php echo systemOwnerEscape($form['property_name']); ?>"
                        required
                    >
                </div>

                <div class="so-field so-field-full">
                    <label for="company_name">Nama Syarikat / Pengurusan</label>
                    <input
                        class="so-input"
                        id="company_name"
                        name="company_name"
                        type="text"
                        maxlength="190"
                        value="<?php echo systemOwnerEscape($form['company_name']); ?>"
                    >
                </div>

                <div class="so-field so-field-full">
                    <label for="address">Alamat</label>
                    <textarea
                        class="so-input so-textarea"
                        id="address"
                        name="address"
                    ><?php echo systemOwnerEscape($form['address']); ?></textarea>
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

                <div class="so-field so-field-full">
                    <label for="website">Website</label>
                    <input
                        class="so-input"
                        id="website"
                        name="website"
                        type="url"
                        maxlength="255"
                        placeholder="https://example.com"
                        value="<?php echo systemOwnerEscape($form['website']); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="primary_color">Warna Utama</label>
                    <input
                        class="so-input"
                        id="primary_color"
                        name="primary_color"
                        type="color"
                        value="<?php echo systemOwnerEscape($form['primary_color']); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="secondary_color">Warna Kedua</label>
                    <input
                        class="so-input"
                        id="secondary_color"
                        name="secondary_color"
                        type="color"
                        value="<?php echo systemOwnerEscape($form['secondary_color']); ?>"
                    >
                </div>

                <div class="so-field">
                    <label for="default_language">Bahasa Lalai</label>
                    <select
                        class="so-input"
                        id="default_language"
                        name="default_language"
                    >
                        <option
                            value="ms"
                            <?php echo $form['default_language'] === 'ms' ? 'selected' : ''; ?>
                        >
                            Bahasa Melayu
                        </option>
                        <option
                            value="en"
                            <?php echo $form['default_language'] === 'en' ? 'selected' : ''; ?>
                        >
                            English
                        </option>
                    </select>
                </div>

                <input
                    type="hidden"
                    name="logo_path"
                    value="<?php echo systemOwnerEscape($form['logo_path']); ?>"
                >
            </div>

            <div class="so-form-actions">
                <button type="submit" class="so-button">
                    Simpan Property
                </button>

                <a class="so-cancel" href="properties.php">
                    Batal
                </a>
            </div>
        </form>
    </section>
</div>
</body>
</html>
