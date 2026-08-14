<?php
declare(strict_types=1);

/*
 * CPMS v3.6.0.9 — Public Resident Registration
 * Upload to: /htdocs/resident_register.php
 * PHP 7.4 compatible.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CPMSPUBLICSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/cpms/db.php';
require_once __DIR__ . '/cpms/includes/system_settings.php';
require_once __DIR__ . '/cpms/core/branding.php';

function cpmsRegistrationEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsRegistrationTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsRegistrationColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema=DATABASE()
           AND table_name=? AND column_name=?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsRegistrationPropertyColumns(mysqli $conn): array
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM cpms_properties');

    if (!$result) {
        return $columns;
    }

    while ($row = $result->fetch_assoc()) {
        $name = (string) ($row['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }

    return $columns;
}

function cpmsRegistrationProperties(mysqli $conn): array
{
    if (!cpmsRegistrationTableExists($conn, 'cpms_properties')) {
        return [];
    }

    $columns = cpmsRegistrationPropertyColumns($conn);
    $wanted = [
        'id',
        'property_code',
        'property_name',
        'company_name',
        'website',
        'logo_path',
        'favicon_path',
        'background_path',
        'dashboard_banner_path',
        'primary_color',
        'secondary_color',
        'default_language',
        'is_active',
    ];
    $select = [];

    foreach ($wanted as $column) {
        if (isset($columns[$column])) {
            $select[] = '`' . $column . '`';
        }
    }

    if (!isset($columns['id']) || !isset($columns['property_name'])) {
        return [];
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM cpms_properties';
    if (isset($columns['is_active'])) {
        $sql .= ' WHERE is_active=1';
    }
    $sql .= ' ORDER BY id ASC';
    $result = $conn->query($sql);

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function cpmsRegistrationHost(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return '';
    }
    if (strpos($value, '://') === false) {
        $value = 'https://' . $value;
    }
    $host = (string) parse_url($value, PHP_URL_HOST);
    $host = (string) preg_replace('/:\d+$/', '', $host);
    return (string) preg_replace('/^www\./', '', $host);
}

function cpmsRegistrationResolveProperty(array $properties): array
{
    if (!$properties) {
        return [];
    }

    $requested = trim((string) ($_GET['property'] ?? ''));
    if ($requested !== '') {
        foreach ($properties as $property) {
            if (
                $requested === (string) ($property['id'] ?? '')
                || strcasecmp(
                    $requested,
                    (string) ($property['property_code'] ?? '')
                ) === 0
            ) {
                return $property;
            }
        }
    }

    $host = cpmsRegistrationHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        foreach ($properties as $property) {
            $propertyHost = cpmsRegistrationHost(
                (string) ($property['website'] ?? '')
            );
            if ($propertyHost !== '' && $propertyHost === $host) {
                return $property;
            }
        }
    }

    return $properties[0];
}

function cpmsRegistrationFirst(array $values, string $fallback = ''): string
{
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }
    return $fallback;
}

function cpmsRegistrationHex($value, string $fallback): string
{
    $value = trim((string) $value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
        ? strtolower($value)
        : $fallback;
}

function cpmsRegistrationContrast(string $hex): string
{
    $hex = ltrim(cpmsRegistrationHex($hex, '#ffffff'), '#');
    $red = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue = hexdec(substr($hex, 4, 2));
    $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;
    return $brightness >= 150 ? '#10213d' : '#ffffff';
}

function cpmsRegistrationUrl(string $path, array $parameters = []): string
{
    $parameters = array_filter(
        $parameters,
        static function ($value): bool {
            return $value !== null && $value !== '';
        }
    );
    return $path . ($parameters ? '?' . http_build_query($parameters) : '');
}

function cpmsRegistrationToken(): string
{
    if (empty($_SESSION['resident_registration_csrf'])) {
        $_SESSION['resident_registration_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['resident_registration_csrf'];
}

function cpmsRegistrationExecute(
    mysqli_stmt $stmt,
    string $message
): void {
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsRegistrationReference(string $propertyCode): string
{
    $prefix = strtoupper(
        (string) preg_replace('/[^A-Za-z0-9]/', '', $propertyCode)
    );
    $prefix = substr($prefix !== '' ? $prefix : 'CPMS', 0, 8);
    return 'REG-' . $prefix . '-' . date('ymd') . '-'
        . strtoupper(bin2hex(random_bytes(3)));
}

function cpmsRegistrationUsername(string $value): string
{
    return strtolower(trim($value));
}

function cpmsRegistrationUsernameValid(string $username): bool
{
    return preg_match(
        '/^[a-z0-9][a-z0-9._]{3,28}[a-z0-9]$/',
        $username
    ) === 1;
}

function cpmsRegistrationUsernameReserved(string $username): bool
{
    return in_array($username, [
        'admin',
        'administrator',
        'clerk',
        'cpms',
        'manager',
        'owner',
        'resident',
        'root',
        'security',
        'support',
        'system',
    ], true);
}

$properties = cpmsRegistrationProperties($conn);
$currentProperty = cpmsRegistrationResolveProperty($properties);
$propertyId = (int) ($currentProperty['id'] ?? 0);
$propertyCode = trim((string) ($currentProperty['property_code'] ?? ''));

try {
    $cpmsSettings = loadSystemSettings($conn);
} catch (Throwable $error) {
    $cpmsSettings = cpmsSystemSettingDefaults();
}

$GLOBALS['currentProperty'] = $currentProperty;
$GLOBALS['cpmsSettings'] = $cpmsSettings;

$defaultLanguage = strtolower(
    cpmsRegistrationFirst([
        $currentProperty['default_language'] ?? '',
        $cpmsSettings['default_language'] ?? '',
    ], 'ms')
);
$requestedLanguage = strtolower(trim((string) ($_GET['lang'] ?? '')));
$language = in_array($requestedLanguage, ['ms', 'en'], true)
    ? $requestedLanguage
    : (in_array($defaultLanguage, ['ms', 'en'], true)
        ? $defaultLanguage
        : 'ms');

$copy = [
    'ms' => [
        'title' => 'Daftar Resident',
        'eyebrow' => 'PERMOHONAN AKAUN RESIDENT',
        'description' => 'Isi maklumat di bawah. Akaun hanya boleh digunakan selepas diluluskan oleh pihak pengurusan.',
        'property' => 'Property',
        'full_name' => 'Nama Penuh',
        'phone' => 'Nombor Telefon',
        'email' => 'E-mel (pilihan)',
        'username' => 'Username Pilihan',
        'username_help' => '5–30 aksara: huruf kecil, nombor, titik atau _. Mesti bermula dan berakhir dengan huruf atau nombor.',
        'username_taken' => 'Username ini sudah digunakan. Sila pilih username lain.',
        'username_reserved' => 'Username ini dikhaskan oleh sistem. Sila pilih username lain.',
        'block' => 'Blok',
        'unit' => 'Nombor Unit',
        'type' => 'Jenis Resident',
        'owner' => 'Pemilik',
        'tenant' => 'Penyewa',
        'family' => 'Ahli Keluarga',
        'occupant' => 'Penghuni',
        'password' => 'Kata Laluan',
        'password_confirm' => 'Sahkan Kata Laluan',
        'password_help' => 'Minimum 8 aksara serta mengandungi huruf dan nombor.',
        'consent' => 'Saya mengesahkan maklumat ini benar dan bersetuju pihak pengurusan membuat semakan.',
        'submit' => 'Hantar Permohonan',
        'login' => 'Sudah ada akaun? Resident Login',
        'back' => 'Kembali ke Portal Utama',
        'secure_title' => 'Kelulusan Pengurusan',
        'secure_text' => 'Permohonan berstatus Pending. Property Admin akan menyemak property, blok dan unit sebelum akaun diaktifkan.',
        'duplicate_text' => 'Username, nombor telefon, unit atau e-mel yang sudah berdaftar tidak boleh digunakan semula.',
        'success_title' => 'Permohonan berjaya dihantar',
        'success_text' => 'Simpan nombor rujukan ini. Pihak pengurusan akan menghubungi anda selepas semakan.',
        'reference' => 'Nombor Rujukan',
        'login_username' => 'Username Login Pilihan',
        'unavailable_title' => 'Pendaftaran belum diaktifkan',
        'unavailable_text' => 'Pihak pengurusan perlu menjalankan migration CPMS v3.6.0.9 terlebih dahulu.',
        'invalid' => 'Sila semak semula maklumat yang dimasukkan.',
    ],
    'en' => [
        'title' => 'Resident Registration',
        'eyebrow' => 'RESIDENT ACCOUNT APPLICATION',
        'description' => 'Complete the form below. The account can only be used after management approval.',
        'property' => 'Property',
        'full_name' => 'Full Name',
        'phone' => 'Telephone Number',
        'email' => 'Email (optional)',
        'username' => 'Preferred Username',
        'username_help' => '5–30 characters: lowercase letters, numbers, dots or _. Must start and end with a letter or number.',
        'username_taken' => 'This username is already in use. Please choose another username.',
        'username_reserved' => 'This username is reserved by the system. Please choose another username.',
        'block' => 'Block',
        'unit' => 'Unit Number',
        'type' => 'Resident Type',
        'owner' => 'Owner',
        'tenant' => 'Tenant',
        'family' => 'Family Member',
        'occupant' => 'Occupant',
        'password' => 'Password',
        'password_confirm' => 'Confirm Password',
        'password_help' => 'At least 8 characters containing letters and numbers.',
        'consent' => 'I confirm that this information is correct and consent to management verification.',
        'submit' => 'Submit Application',
        'login' => 'Already registered? Resident Login',
        'back' => 'Back to Main Portal',
        'secure_title' => 'Management Approval',
        'secure_text' => 'Applications remain Pending. The Property Admin will verify the property, block and unit before activation.',
        'duplicate_text' => 'A username, telephone number, unit or email that is already registered cannot be used again.',
        'success_title' => 'Application submitted successfully',
        'success_text' => 'Keep this reference number. Management will contact you after verification.',
        'reference' => 'Reference Number',
        'login_username' => 'Preferred Login Username',
        'unavailable_title' => 'Registration is not active yet',
        'unavailable_text' => 'Management must run the CPMS v3.6.0.9 migration first.',
        'invalid' => 'Please check the information entered.',
    ],
];
$text = $copy[$language];

$propertyName = cpmsRegistrationFirst([
    $currentProperty['property_name'] ?? '',
], 'Property Resident Portal');
$companyName = cpmsRegistrationFirst([
    $currentProperty['company_name'] ?? '',
    $cpmsSettings['company_name'] ?? '',
]);
$primaryColor = cpmsRegistrationHex(
    cpmsRegistrationFirst([
        $currentProperty['primary_color'] ?? '',
        $cpmsSettings['primary_color'] ?? '',
    ]),
    '#0f3563'
);
$secondaryColor = cpmsRegistrationHex(
    cpmsRegistrationFirst([
        $currentProperty['secondary_color'] ?? '',
        $cpmsSettings['secondary_color'] ?? '',
    ]),
    '#d7aa4b'
);
$logoPath = cpmsRegistrationFirst([
    $currentProperty['logo_path'] ?? '',
    $cpmsSettings['logo_path'] ?? '',
]);
$backgroundPath = cpmsRegistrationFirst([
    $currentProperty['background_path'] ?? '',
    $currentProperty['dashboard_banner_path'] ?? '',
    $cpmsSettings['background_path'] ?? '',
]);

if ($propertyId !== 1 && strcasecmp($propertyCode, 'V23') !== 0) {
    $normalisedLogo = strtolower(ltrim(str_replace('\\', '/', $logoPath), '/'));
    $normalisedBackground = strtolower(
        ltrim(str_replace('\\', '/', $backgroundPath), '/')
    );
    if ($normalisedLogo === 'images/logo.png') {
        $logoPath = '';
    }
    if ($normalisedBackground === 'images/bg-premium.jpg') {
        $backgroundPath = '';
    }
}

$logoUrl = $logoPath !== '' ? cpmsAssetUrl($logoPath) : '';
$backgroundUrl = $backgroundPath !== '' ? cpmsAssetUrl($backgroundPath) : '';
$tableReady = $propertyId > 0
    && cpmsRegistrationTableExists($conn, 'cpms_resident_registrations')
    && cpmsRegistrationColumnExists(
        $conn,
        'cpms_resident_registrations',
        'requested_username'
    );
$baseParameters = ['property' => $propertyCode, 'lang' => $language];
$bmParameters = ['property' => $propertyCode, 'lang' => 'ms'];
$enParameters = ['property' => $propertyCode, 'lang' => 'en'];
$residentTypes = [
    'OWNER' => $text['owner'],
    'TENANT' => $text['tenant'],
    'FAMILY' => $text['family'],
    'OCCUPANT' => $text['occupant'],
];
$errors = [];
$successReference = '';
$successUsername = '';
$successFlash = $_SESSION['resident_registration_success'] ?? null;
if (
    isset($_GET['submitted'])
    && is_array($successFlash)
    && (int) ($successFlash['property_id'] ?? 0) === $propertyId
) {
    $successReference = (string) ($successFlash['reference'] ?? '');
    $successUsername = (string) ($successFlash['username'] ?? '');
    unset($_SESSION['resident_registration_success']);
}
$form = [
    'full_name' => '',
    'requested_username' => '',
    'phone' => '',
    'email' => '',
    'block_name' => '',
    'unit_no' => '',
    'resident_type' => 'OWNER',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? $form[$field]));
    }

    $token = (string) ($_POST['csrf_token'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $consent = (string) ($_POST['consent'] ?? '');
    $honeypot = trim((string) ($_POST['company_website'] ?? ''));

    if (
        $token === ''
        || !hash_equals(cpmsRegistrationToken(), $token)
        || $honeypot !== ''
    ) {
        $errors[] = $text['invalid'];
    }

    $form['email'] = strtolower($form['email']);
    $form['requested_username'] = cpmsRegistrationUsername(
        $form['requested_username']
    );
    $phoneDigits = (string) preg_replace('/\D/', '', $form['phone']);
    $form['phone'] = strpos(trim($form['phone']), '+') === 0
        ? '+' . $phoneDigits
        : $phoneDigits;
    $form['block_name'] = strtoupper($form['block_name']);
    $form['unit_no'] = strtoupper($form['unit_no']);

    if (strlen($form['full_name']) < 3 || strlen($form['full_name']) > 180) {
        $errors[] = $text['full_name'] . ': ' . $text['invalid'];
    }
    if (!cpmsRegistrationUsernameValid($form['requested_username'])) {
        $errors[] = $text['username_help'];
    } elseif (cpmsRegistrationUsernameReserved($form['requested_username'])) {
        $errors[] = $text['username_reserved'];
    }
    if (strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
        $errors[] = $text['phone'] . ': ' . $text['invalid'];
    }
    if (
        $form['email'] !== ''
        && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = $text['email'] . ': ' . $text['invalid'];
    }
    if ($form['block_name'] === '' || strlen($form['block_name']) > 50) {
        $errors[] = $text['block'] . ': ' . $text['invalid'];
    }
    if ($form['unit_no'] === '' || strlen($form['unit_no']) > 50) {
        $errors[] = $text['unit'] . ': ' . $text['invalid'];
    }
    if (!isset($residentTypes[$form['resident_type']])) {
        $errors[] = $text['type'] . ': ' . $text['invalid'];
    }
    if (
        strlen($password) < 8
        || preg_match('/[A-Za-z]/', $password) !== 1
        || preg_match('/\d/', $password) !== 1
        || !hash_equals($password, $passwordConfirm)
    ) {
        $errors[] = $text['password_help'];
    }
    if ($consent !== '1') {
        $errors[] = $text['consent'];
    }

    if (!$errors) {
        try {
            $sourceIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM cpms_resident_registrations
                 WHERE property_id=? AND source_ip=?
                   AND submitted_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)"
            );
            if (!$stmt) {
                throw new RuntimeException('Rate limit query failed.');
            }
            $stmt->bind_param('is', $propertyId, $sourceIp);
            cpmsRegistrationExecute($stmt, 'Rate limit query failed.');
            $recent = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int) ($recent['total'] ?? 0) >= 5) {
                throw new RuntimeException($text['invalid']);
            }

            $stmt = $conn->prepare(
                'SELECT id
                 FROM system_users
                 WHERE LOWER(username)=LOWER(?)
                 LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Username check failed.');
            }
            $stmt->bind_param('s', $form['requested_username']);
            cpmsRegistrationExecute($stmt, 'Username check failed.');
            $usernameExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($usernameExists) {
                throw new RuntimeException($text['username_taken']);
            }

            $stmt = $conn->prepare(
                "SELECT id
                 FROM cpms_resident_registrations
                 WHERE status='Pending'
                   AND LOWER(requested_username)=LOWER(?)
                 LIMIT 1"
            );
            if (!$stmt) {
                throw new RuntimeException('Pending username check failed.');
            }
            $stmt->bind_param('s', $form['requested_username']);
            cpmsRegistrationExecute(
                $stmt,
                'Pending username check failed.'
            );
            $pendingUsernameExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($pendingUsernameExists) {
                throw new RuntimeException($text['username_taken']);
            }

            $stmt = $conn->prepare(
                "SELECT id
                 FROM cpms_residents
                 WHERE property_id=?
                   AND (
                        phone=?
                        OR (LOWER(block_name)=LOWER(?)
                            AND LOWER(unit_no)=LOWER(?))
                   )
                 LIMIT 1"
            );
            if (!$stmt) {
                throw new RuntimeException('Resident check failed.');
            }
            $stmt->bind_param(
                'isss',
                $propertyId,
                $form['phone'],
                $form['block_name'],
                $form['unit_no']
            );
            cpmsRegistrationExecute($stmt, 'Resident check failed.');
            $existingResident = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existingResident) {
                throw new RuntimeException($text['duplicate_text']);
            }

            $stmt = $conn->prepare(
                "SELECT id
                 FROM cpms_resident_registrations
                 WHERE property_id=? AND status='Pending'
                   AND (
                        phone=?
                        OR (LOWER(block_name)=LOWER(?)
                            AND LOWER(unit_no)=LOWER(?))
                        OR (?<>'' AND LOWER(COALESCE(email,''))=LOWER(?))
                   )
                 LIMIT 1"
            );
            if (!$stmt) {
                throw new RuntimeException('Application check failed.');
            }
            $stmt->bind_param(
                'isssss',
                $propertyId,
                $form['phone'],
                $form['block_name'],
                $form['unit_no'],
                $form['email'],
                $form['email']
            );
            cpmsRegistrationExecute($stmt, 'Application check failed.');
            $existingApplication = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($existingApplication) {
                throw new RuntimeException($text['duplicate_text']);
            }

            if ($form['email'] !== '') {
                $stmt = $conn->prepare(
                    'SELECT id FROM system_users WHERE LOWER(email)=LOWER(?) LIMIT 1'
                );
                if (!$stmt) {
                    throw new RuntimeException('Email check failed.');
                }
                $stmt->bind_param('s', $form['email']);
                cpmsRegistrationExecute($stmt, 'Email check failed.');
                $emailExists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($emailExists) {
                    throw new RuntimeException($text['duplicate_text']);
                }
            }

            $reference = cpmsRegistrationReference($propertyCode);
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException($text['invalid']);
            }

            $stmt = $conn->prepare(
                "INSERT INTO cpms_resident_registrations (
                    property_id,application_reference,requested_username,
                    full_name,email,
                    phone,block_name,unit_no,resident_type,password_hash,
                    source_ip,user_agent
                 ) VALUES (?,?,?,?,NULLIF(?,''),?,?,?,?,?,?,?)"
            );
            if (!$stmt) {
                throw new RuntimeException('Application insert failed.');
            }
            $stmt->bind_param(
                'isssssssssss',
                $propertyId,
                $reference,
                $form['requested_username'],
                $form['full_name'],
                $form['email'],
                $form['phone'],
                $form['block_name'],
                $form['unit_no'],
                $form['resident_type'],
                $passwordHash,
                $sourceIp,
                $userAgent
            );
            cpmsRegistrationExecute($stmt, 'Application insert failed.');
            $stmt->close();

            $_SESSION['resident_registration_success'] = [
                'property_id' => $propertyId,
                'reference' => $reference,
                'username' => $form['requested_username'],
            ];
            $_SESSION['resident_registration_csrf'] = bin2hex(random_bytes(32));
            $successParameters = $baseParameters;
            $successParameters['submitted'] = '1';
            header(
                'Location: '
                . cpmsRegistrationUrl(
                    'resident_register.php',
                    $successParameters
                )
            );
            exit;
        } catch (Throwable $error) {
            error_log('CPMS resident registration: ' . $error->getMessage());
            $publicErrors = [
                $text['duplicate_text'],
                $text['username_taken'],
                $text['username_reserved'],
            ];
            $errors[] = in_array($error->getMessage(), $publicErrors, true)
                ? $error->getMessage()
                : $text['invalid'];
        }
    }
}
?>
<!doctype html>
<html lang="<?php echo cpmsRegistrationEscape($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="<?php echo cpmsRegistrationEscape($primaryColor); ?>">
    <title><?php echo cpmsRegistrationEscape($text['title'] . ' | ' . $propertyName); ?></title>
    <?php if (!empty($currentProperty['favicon_path'])): ?>
        <link rel="icon" href="<?php echo cpmsRegistrationEscape(cpmsAssetUrl((string) $currentProperty['favicon_path'])); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="css/cpms-resident-hub.css?v=3570">
    <link rel="stylesheet" href="css/cpms-resident-registration.css?v=3570">
    <style>
        :root {
            --hub-primary: <?php echo cpmsRegistrationEscape($primaryColor); ?>;
            --hub-secondary: <?php echo cpmsRegistrationEscape($secondaryColor); ?>;
            --hub-on-primary: <?php echo cpmsRegistrationEscape(cpmsRegistrationContrast($primaryColor)); ?>;
            --hub-on-secondary: <?php echo cpmsRegistrationEscape(cpmsRegistrationContrast($secondaryColor)); ?>;
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="hub-container header-inner">
        <a class="property-brand" href="<?php echo cpmsRegistrationEscape(cpmsRegistrationUrl('index.php', $baseParameters)); ?>">
            <span class="brand-mark">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?php echo cpmsRegistrationEscape($logoUrl); ?>" alt="<?php echo cpmsRegistrationEscape($propertyName); ?>">
                <?php else: ?>
                    <strong>CP</strong>
                <?php endif; ?>
            </span>
            <span class="brand-copy">
                <strong><?php echo cpmsRegistrationEscape($propertyName); ?></strong>
                <?php if ($companyName !== ''): ?><small><?php echo cpmsRegistrationEscape($companyName); ?></small><?php endif; ?>
            </span>
        </a>
        <div class="header-tools">
            <div class="language-switch" role="group" aria-label="Pilihan bahasa / Language selection">
                <a class="<?php echo $language === 'ms' ? 'is-active' : ''; ?>" href="<?php echo cpmsRegistrationEscape(cpmsRegistrationUrl('resident_register.php', $bmParameters)); ?>">BM</a>
                <span aria-hidden="true">|</span>
                <a class="<?php echo $language === 'en' ? 'is-active' : ''; ?>" href="<?php echo cpmsRegistrationEscape(cpmsRegistrationUrl('resident_register.php', $enParameters)); ?>">EN</a>
            </div>
        </div>
    </div>
</header>

<main class="registration-page">
    <section class="registration-hero">
        <?php if ($backgroundUrl !== ''): ?><img src="<?php echo cpmsRegistrationEscape($backgroundUrl); ?>" alt=""><?php endif; ?>
        <div class="registration-hero-overlay"></div>
        <div class="hub-container registration-hero-inner">
            <span class="eyebrow"><?php echo cpmsRegistrationEscape($text['eyebrow']); ?></span>
            <h1><?php echo cpmsRegistrationEscape($text['title']); ?></h1>
            <p><?php echo cpmsRegistrationEscape($text['description']); ?></p>
        </div>
    </section>

    <section class="registration-section">
        <div class="hub-container registration-layout">
            <div class="registration-main-card">
                <?php if (!$tableReady): ?>
                    <div class="registration-unavailable">
                        <span>!</span>
                        <h2><?php echo cpmsRegistrationEscape($text['unavailable_title']); ?></h2>
                        <p><?php echo cpmsRegistrationEscape($text['unavailable_text']); ?></p>
                    </div>
                <?php elseif ($successReference !== ''): ?>
                    <div class="registration-success">
                        <span>✓</span>
                        <h2><?php echo cpmsRegistrationEscape($text['success_title']); ?></h2>
                        <p><?php echo cpmsRegistrationEscape($text['success_text']); ?></p>
                        <div><small><?php echo cpmsRegistrationEscape($text['reference']); ?></small><strong><?php echo cpmsRegistrationEscape($successReference); ?></strong></div>
                        <?php if ($successUsername !== ''): ?><div><small><?php echo cpmsRegistrationEscape($text['login_username']); ?></small><strong><?php echo cpmsRegistrationEscape($successUsername); ?></strong></div><?php endif; ?>
                        <a class="registration-button" href="<?php echo cpmsRegistrationEscape(cpmsRegistrationUrl('index.php', $baseParameters)); ?>"><?php echo cpmsRegistrationEscape($text['back']); ?></a>
                    </div>
                <?php else: ?>
                    <div class="registration-card-heading">
                        <span><?php echo cpmsRegistrationEscape($text['property']); ?></span>
                        <h2><?php echo cpmsRegistrationEscape($propertyName); ?></h2>
                    </div>

                    <?php if ($errors): ?>
                        <div class="registration-errors" role="alert">
                            <?php foreach (array_unique($errors) as $error): ?><p><?php echo cpmsRegistrationEscape($error); ?></p><?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="registration-form" autocomplete="on">
                        <input type="hidden" name="csrf_token" value="<?php echo cpmsRegistrationEscape(cpmsRegistrationToken()); ?>">
                        <label class="registration-honeypot" aria-hidden="true">Website<input name="company_website" tabindex="-1" autocomplete="off"></label>

                        <div class="registration-fields registration-fields--two">
                            <label><span><?php echo cpmsRegistrationEscape($text['full_name']); ?></span><input name="full_name" maxlength="180" value="<?php echo cpmsRegistrationEscape($form['full_name']); ?>" autocomplete="name" required></label>
                            <label><span><?php echo cpmsRegistrationEscape($text['phone']); ?></span><input name="phone" maxlength="40" value="<?php echo cpmsRegistrationEscape($form['phone']); ?>" inputmode="tel" autocomplete="tel" required></label>
                        </div>
                        <label><span><?php echo cpmsRegistrationEscape($text['username']); ?></span><input name="requested_username" minlength="5" maxlength="30" pattern="[a-z0-9][a-z0-9._]{3,28}[a-z0-9]" value="<?php echo cpmsRegistrationEscape($form['requested_username']); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
                        <small class="registration-help"><?php echo cpmsRegistrationEscape($text['username_help']); ?></small>
                        <label><span><?php echo cpmsRegistrationEscape($text['email']); ?></span><input type="email" name="email" maxlength="190" value="<?php echo cpmsRegistrationEscape($form['email']); ?>" autocomplete="email"></label>
                        <div class="registration-fields registration-fields--two">
                            <label><span><?php echo cpmsRegistrationEscape($text['block']); ?></span><input name="block_name" maxlength="50" value="<?php echo cpmsRegistrationEscape($form['block_name']); ?>" required></label>
                            <label><span><?php echo cpmsRegistrationEscape($text['unit']); ?></span><input name="unit_no" maxlength="50" value="<?php echo cpmsRegistrationEscape($form['unit_no']); ?>" required></label>
                        </div>
                        <label><span><?php echo cpmsRegistrationEscape($text['type']); ?></span><select name="resident_type" required><?php foreach ($residentTypes as $value => $label): ?><option value="<?php echo cpmsRegistrationEscape($value); ?>" <?php echo $form['resident_type'] === $value ? 'selected' : ''; ?>><?php echo cpmsRegistrationEscape($label); ?></option><?php endforeach; ?></select></label>
                        <div class="registration-fields registration-fields--two">
                            <label><span><?php echo cpmsRegistrationEscape($text['password']); ?></span><input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
                            <label><span><?php echo cpmsRegistrationEscape($text['password_confirm']); ?></span><input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required></label>
                        </div>
                        <small class="registration-help"><?php echo cpmsRegistrationEscape($text['password_help']); ?></small>
                        <label class="registration-consent"><input type="checkbox" name="consent" value="1" required><span><?php echo cpmsRegistrationEscape($text['consent']); ?></span></label>
                        <button class="registration-button" type="submit"><?php echo cpmsRegistrationEscape($text['submit']); ?> →</button>
                    </form>
                <?php endif; ?>
            </div>

            <aside class="registration-side-card">
                <span class="registration-side-icon">✓</span>
                <h2><?php echo cpmsRegistrationEscape($text['secure_title']); ?></h2>
                <p><?php echo cpmsRegistrationEscape($text['secure_text']); ?></p>
                <div class="registration-divider"></div>
                <p><?php echo cpmsRegistrationEscape($text['duplicate_text']); ?></p>
                <a href="cpms/resident_login.php"><?php echo cpmsRegistrationEscape($text['login']); ?> →</a>
                <a href="<?php echo cpmsRegistrationEscape(cpmsRegistrationUrl('index.php', $baseParameters)); ?>">← <?php echo cpmsRegistrationEscape($text['back']); ?></a>
            </aside>
        </div>
    </section>
</main>
</body>
</html>
