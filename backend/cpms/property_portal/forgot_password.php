<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/branding.php';

function cpmsForgotEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsForgotCsrfToken(): string
{
    if (
        empty($_SESSION['property_forgot_password_csrf'])
        || !is_string($_SESSION['property_forgot_password_csrf'])
    ) {
        $_SESSION['property_forgot_password_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['property_forgot_password_csrf'];
}

function cpmsForgotVerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['property_forgot_password_csrf'])
        && is_string($_SESSION['property_forgot_password_csrf'])
        && hash_equals($_SESSION['property_forgot_password_csrf'], $token);
}

function cpmsForgotBaseUrl(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $scheme . '://' . $host . ($directory === '/' ? '' : $directory);
}

function cpmsForgotPropertyCodeFromReferrer(): string
{
    $referrer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

    if ($referrer === '') {
        return '';
    }

    $query = parse_url($referrer, PHP_URL_QUERY);

    if (!is_string($query) || $query === '') {
        return '';
    }

    parse_str($query, $parameters);

    return strtoupper(trim((string) ($parameters['property'] ?? '')));
}

function cpmsForgotLoadProperties(mysqli $conn): array
{
    $properties = [];
    $result = $conn->query(
        "SELECT id, property_code, property_name
         FROM cpms_properties
         WHERE TRIM(property_code) <> ''
         ORDER BY property_name ASC"
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $properties[] = [
            'id' => (int) ($row['id'] ?? 0),
            'property_code' => strtoupper(trim((string) ($row['property_code'] ?? ''))),
            'property_name' => trim((string) ($row['property_name'] ?? '')),
        ];
    }

    return $properties;
}

function cpmsForgotFindProperty(array $properties, string $code): ?array
{
    $code = strtoupper(trim($code));

    foreach ($properties as $property) {
        if ((string) $property['property_code'] === $code) {
            return $property;
        }
    }

    return null;
}

$properties = cpmsForgotLoadProperties($conn);

$requestedCode = strtoupper(trim((string) (
    $_POST['property_code']
    ?? $_GET['property']
    ?? $_SESSION['cpms_forgot_property_code']
    ?? ''
)));

if ($requestedCode === '') {
    $requestedCode = cpmsForgotPropertyCodeFromReferrer();
}

if ($requestedCode === '' && count($properties) === 1) {
    $requestedCode = (string) $properties[0]['property_code'];
}

$selectedProperty = cpmsForgotFindProperty($properties, $requestedCode);

if ($selectedProperty) {
    $_SESSION['cpms_forgot_property_code'] = $requestedCode;
}

$errors = [];
$success = false;
$identity = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string) ($_POST['identity'] ?? ''));
    $csrf = $_POST['csrf_token'] ?? null;

    if (!cpmsForgotVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Sesi keselamatan tidak sah. Muat semula halaman dan cuba lagi.';
    }

    if (!$selectedProperty) {
        $errors[] = 'Sila pilih property yang betul.';
    }

    if ($identity === '') {
        $errors[] = 'Masukkan username atau alamat e-mel.';
    }

    if (!$errors && $selectedProperty) {
        $propertyId = (int) $selectedProperty['id'];

        $stmt = $conn->prepare(
            "SELECT id, full_name, username, email
             FROM property_admins
             WHERE property_id = ?
               AND status = 'active'
               AND (username = ? OR email = ?)
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->bind_param('iss', $propertyId, $identity, $identity);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (
                $user
                && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)
            ) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $userId = (int) $user['id'];
                $email = (string) $user['email'];
                $ipAddress = substr(
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    0,
                    45
                );

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

                $insert = $conn->prepare(
                    "INSERT INTO password_reset_tokens
                        (
                            user_type,
                            user_id,
                            property_id,
                            email,
                            token_hash,
                            expires_at,
                            requested_ip,
                            created_at
                        )
                     VALUES
                        (
                            'property_admin',
                            ?,
                            ?,
                            ?,
                            ?,
                            DATE_ADD(NOW(), INTERVAL 30 MINUTE),
                            ?,
                            NOW()
                        )"
                );

                if ($insert) {
                    $insert->bind_param(
                        'iisss',
                        $userId,
                        $propertyId,
                        $email,
                        $tokenHash,
                        $ipAddress
                    );
                    $insert->execute();
                    $insert->close();

                    $resetUrl = cpmsForgotBaseUrl()
                        . '/reset_password.php?token='
                        . rawurlencode($token);

                    $subject = 'Reset Kata Laluan - '
                        . (string) $selectedProperty['property_name'];

                    $message = "Hai "
                        . (string) $user['full_name']
                        . ",\n\n"
                        . "Permintaan reset kata laluan telah diterima.\n\n"
                        . "Klik pautan ini untuk menetapkan kata laluan baharu:\n"
                        . $resetUrl
                        . "\n\nPautan ini sah selama 30 minit dan hanya boleh digunakan sekali."
                        . "\nJika anda tidak membuat permintaan ini, abaikan e-mel ini.\n";

                    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";

                    @mail($email, $subject, $message, $headers);
                }
            }
        }

        // Respons sentiasa sama bagi mengelakkan pendedahan kewujudan akaun.
        $success = true;
        $identity = '';
    }
}

$csrfToken = cpmsForgotCsrfToken();
$propertyName = $selectedProperty
    ? (string) $selectedProperty['property_name']
    : 'Property Portal';
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Kata Laluan | <?php echo cpmsForgotEscape($propertyName); ?></title>
    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/commercial-login.css">
    <link rel="stylesheet" href="assets/auth-v2.css">
    <link rel="stylesheet" href="assets/auth-v2-1.css">
</head>
<body class="commercial-login-page">
<div class="password-recovery-page">
    <main class="password-recovery-card">
        <span class="commercial-eyebrow login-eyebrow">PEMULIHAN AKAUN</span>

        <h1>Lupa Kata Laluan</h1>

        <p>
            Pilih property dan masukkan username atau e-mel yang didaftarkan.
        </p>

        <?php if ($success): ?>
            <div class="commercial-alert commercial-alert-success" role="status">
                Sekiranya akaun tersebut wujud dan mempunyai e-mel yang sah,
                pautan reset telah dihantar. Pautan sah selama 30 minit.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="commercial-alert commercial-alert-danger" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo cpmsForgotEscape($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="commercial-login-form" novalidate>
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo cpmsForgotEscape($csrfToken); ?>"
            >

            <div class="commercial-field">
                <label for="property_code">Property</label>

                <div class="commercial-input-wrap commercial-select-wrap">
                    <span class="commercial-input-icon" aria-hidden="true">⌂</span>

                    <select
                        id="property_code"
                        name="property_code"
                        required
                    >
                        <option value="">-- Pilih Property --</option>

                        <?php foreach ($properties as $property): ?>
                            <option
                                value="<?php echo cpmsForgotEscape(
                                    (string) $property['property_code']
                                ); ?>"
                                <?php echo (
                                    (string) $property['property_code']
                                    === $requestedCode
                                ) ? 'selected' : ''; ?>
                            >
                                <?php echo cpmsForgotEscape(
                                    (string) $property['property_name']
                                    . ' (' . (string) $property['property_code'] . ')'
                                ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="commercial-field">
                <label for="identity">Username atau E-mel</label>

                <div class="commercial-input-wrap">
                    <span class="commercial-input-icon" aria-hidden="true">@</span>

                    <input
                        id="identity"
                        name="identity"
                        type="text"
                        value="<?php echo cpmsForgotEscape($identity); ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>
            </div>

            <button class="commercial-submit" type="submit">
                Hantar Pautan Reset
            </button>
        </form>

        <div class="password-recovery-actions">
            <a href="login.php<?php
                echo $requestedCode !== ''
                    ? '?property=' . rawurlencode($requestedCode)
                    : '';
            ?>">
                Kembali ke halaman login
            </a>
        </div>
    </main>
</div>
</body>
</html>
