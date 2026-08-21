<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/branding.php';

if (!empty($_SESSION['property_admin_id'])) {
    propertyPortalRedirect('dashboard.php');
}

function cpmsLoginHexColor(
    ?string $value,
    string $fallback
): string {
    $value = trim((string) $value);

    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return $value;
    }

    return $fallback;
}

function cpmsLoginBranding(mysqli $conn): array
{
    $defaults = [
        'property_id' => 0,
        'id' => 0,
        'property_code' => '',
        'property_name' => 'Commercial Property Management System',
        'system_name' => 'Property Management System',
        'company_name' => 'Property Management Portal',
        'tagline' => 'Professional property operations in one secure workspace',
        'favicon_path' => '',
        'login_background_path' => '',
        'footer_text' => '',
        'show_cpms_branding' => 1,
        'logo_path' => '',
        'primary_color' => '#2563eb',
        'secondary_color' => '#0f172a',
        'property_found' => false,
    ];

    $requestedCode = strtoupper(trim((string) (
        $_POST['property_code'] ?? $_GET['property'] ?? ''
    )));

    if ($requestedCode === '') {
        return $defaults;
    }

    /*
     * Use SELECT * so the login page remains compatible with both the
     * original cpms_properties table and the newer commercial-branding
     * schema. Missing optional fields are supplied by $defaults below.
     */
    $stmt = $conn->prepare(
        "SELECT *
         FROM cpms_properties
         WHERE UPPER(TRIM(property_code)) = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return $defaults;
    }

    $stmt->bind_param('s', $requestedCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $defaults['property_code'] = $requestedCode;
        return $defaults;
    }

    $branding = array_merge($defaults, $row);
    $branding['property_id'] = (int) $row['id'];
    $branding['id'] = (int) $row['id'];
    $branding['property_found'] = true;

    foreach ([
        'property_name', 'system_name', 'company_name', 'tagline',
        'logo_path', 'favicon_path', 'login_background_path',
        'footer_text', 'primary_color', 'secondary_color'
    ] as $key) {
        if (trim((string) ($branding[$key] ?? '')) === '') {
            $branding[$key] = $defaults[$key];
        }
    }

    return $branding;
}

function cpmsLoginRateState(): array
{
    $state = $_SESSION['property_login_rate'] ?? [];

    if (!is_array($state)) {
        $state = [];
    }

    $attempts = (int) ($state['attempts'] ?? 0);
    $lockedUntil = (int) ($state['locked_until'] ?? 0);
    $now = time();

    if ($lockedUntil > 0 && $lockedUntil <= $now) {
        $attempts = 0;
        $lockedUntil = 0;
    }

    return [
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
    ];
}

function cpmsLoginRegisterFailure(): array
{
    $state = cpmsLoginRateState();
    $attempts = $state['attempts'] + 1;
    $lockedUntil = 0;

    if ($attempts >= 5) {
        $lockedUntil = time() + 900;
    }

    $_SESSION['property_login_rate'] = [
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
    ];

    return $_SESSION['property_login_rate'];
}

function cpmsLoginResetRate(): void
{
    unset($_SESSION['property_login_rate']);
}

$branding = cpmsLoginBranding($conn);
$primaryColor = cpmsLoginHexColor(
    $branding['primary_color'] ?? null,
    '#2563eb'
);
$secondaryColor = cpmsLoginHexColor(
    $branding['secondary_color'] ?? null,
    '#0f172a'
);

$errors = [];
if (trim((string) ($_GET['property'] ?? '')) !== '' && empty($branding['property_found'])) {
    $errors[] = 'Kod property tidak sah atau property belum tersedia.';
}
$rememberedLogin = (string) (
    $_COOKIE['cpms_property_login_name']
    ?? ''
);
$loginValue = $rememberedLogin;
$rememberLogin = $rememberedLogin !== '';
$rateState = cpmsLoginRateState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $rememberLogin = isset($_POST['remember_login']);
    $csrf = $_POST['csrf_token'] ?? null;
    $rateState = cpmsLoginRateState();

    if ($rateState['locked_until'] > time()) {
        $remainingMinutes = max(
            1,
            (int) ceil(
                ($rateState['locked_until'] - time()) / 60
            )
        );

        $errors[] = sprintf(
            'Too many unsuccessful attempts. Try again in %d minute%s.',
            $remainingMinutes,
            $remainingMinutes === 1 ? '' : 's'
        );
    }

    if (
        !$errors
        && !propertyPortalVerifyCsrf(
            is_string($csrf) ? $csrf : null
        )
    ) {
        $errors[] = (
            'The security session is invalid. '
            . 'Refresh the page and try again.'
        );
    }

    if (
        !$errors
        && ($loginValue === '' || $password === '')
    ) {
        $errors[] = (
            'Enter your username or email and password.'
        );
    }

    if (!$errors) {
        $stmt = $conn->prepare(
            "SELECT
                id,
                property_id,
                full_name,
                username,
                email,
                password_hash,
                role,
                status
             FROM property_admins
             WHERE username = ?
                OR email = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $errors[] = 'Login could not be processed.';
        } else {
            $stmt->bind_param(
                'ss',
                $loginValue,
                $loginValue
            );
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $selectedPropertyId = (int) ($branding['property_id'] ?? 0);

            $valid = (
                $user
                && $selectedPropertyId > 0
                && (int) $user['property_id'] === $selectedPropertyId
                && $user['status'] === 'active'
                && in_array(
                    $user['role'],
                    [
                        'property_admin',
                        'manager',
                        'clerk',
                    ],
                    true
                )
                && password_verify(
                    $password,
                    (string) $user['password_hash']
                )
            );

            if ($valid) {
                cpmsLoginResetRate();
                session_regenerate_id(true);

                $_SESSION['property_admin_id'] = (
                    (int) $user['id']
                );
                $_SESSION['property_admin_property_id'] = (
                    (int) $user['property_id']
                );
                $_SESSION['property_admin_role'] = (
                    (string) $user['role']
                );
                $_SESSION['property_admin_name'] = (
                    (string) $user['full_name']
                );
                $_SESSION['property_admin_last_activity'] = time();

                if ($rememberLogin) {
                    setcookie(
                        'cpms_property_login_name',
                        $loginValue,
                        [
                            'expires' => time() + 2592000,
                            'path' => '/',
                            'secure' => (
                                !empty($_SERVER['HTTPS'])
                                && $_SERVER['HTTPS'] !== 'off'
                            ),
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]
                    );
                } else {
                    setcookie(
                        'cpms_property_login_name',
                        '',
                        [
                            'expires' => time() - 3600,
                            'path' => '/',
                            'secure' => (
                                !empty($_SERVER['HTTPS'])
                                && $_SERVER['HTTPS'] !== 'off'
                            ),
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]
                    );
                }

                $update = $conn->prepare(
                    "UPDATE property_admins
                     SET last_login_at = NOW()
                     WHERE id = ?"
                );

                if ($update) {
                    $id = (int) $user['id'];
                    $update->bind_param('i', $id);
                    $update->execute();
                    $update->close();
                }

                unset($_SESSION['property_portal_csrf']);
                propertyPortalRedirect('dashboard.php');
            }

            $rateState = cpmsLoginRegisterFailure();

            if ($rateState['locked_until'] > time()) {
                $errors[] = (
                    'Too many unsuccessful attempts. '
                    . 'Login has been temporarily locked for '
                    . '15 minutes.'
                );
            } else {
                $remaining = max(
                    0,
                    5 - (int) $rateState['attempts']
                );

                $errors[] = (
                    'Incorrect login details or the account '
                    . 'is inactive.'
                );

                if ($remaining > 0) {
                    $errors[] = sprintf(
                        '%d attempt%s remaining before a temporary lock.',
                        $remaining,
                        $remaining === 1 ? '' : 's'
                    );
                }
            }
        }
    }
}

$csrfToken = propertyPortalCsrfToken();
$logoPath = trim((string) ($branding['logo_path'] ?? ''));
$logoUrl = cpmsBrandingAssetUrl($logoPath);
$propertyName = trim(
    (string) ($branding['property_name'] ?? '')
);
$companyName = trim(
    (string) ($branding['company_name'] ?? '')
);
$systemName = cpmsBrandingSystemName($branding);
$tagline = cpmsBrandingValue(
    $branding,
    'tagline',
    'Professional property operations in one secure workspace'
);
$faviconUrl = cpmsBrandingAssetUrl($branding['favicon_path'] ?? '');
$loginBackgroundUrl = cpmsBrandingAssetUrl($branding['login_background_path'] ?? '');
$showCpmsBranding = cpmsBrandingShowCpms($branding);

$propertyCode = trim(
    (string) ($branding['property_code'] ?? '')
);

if ($propertyName === '') {
    $propertyName = 'Commercial Property Management System';
}

if ($companyName === '') {
    $companyName = 'Property Management Portal';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta name="color-scheme" content="light">

    <title>
        Sign In |
        <?php echo propertyPortalEscape($propertyName); ?>
    </title>

    <link rel="stylesheet" href="assets/portal.css">
    <link rel="stylesheet" href="assets/commercial-login.css">
    <link rel="stylesheet" href="assets/logo-ui-polish.css">

    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" href="<?php echo propertyPortalEscape($faviconUrl); ?>">
    <?php endif; ?>

    <style>
        :root {
            --login-primary:
                <?php echo propertyPortalEscape(
                    $primaryColor
                ); ?>;
            --login-secondary:
                <?php echo propertyPortalEscape(
                    $secondaryColor
                ); ?>;
        }
    </style>
</head>

<body class="commercial-login-page">
<div class="commercial-login-shell">
    <section
        class="commercial-brand-panel"
        <?php if ($loginBackgroundUrl !== ''): ?>
            style="background-image:linear-gradient(145deg,rgba(15,23,42,.86),color-mix(in srgb,var(--login-primary) 76%,transparent)),url('<?php echo propertyPortalEscape($loginBackgroundUrl); ?>');background-size:cover;background-position:center;"
        <?php endif; ?>
    >
        <div class="commercial-brand-overlay"></div>

        <div class="commercial-brand-content">
            <header class="commercial-brand-header">
                <div class="commercial-brand-logo">
                    <?php if ($logoUrl !== ''): ?>
                        <img
                                data-branding-image="1"
                            src="<?php echo propertyPortalEscape(
                                $logoUrl
                            ); ?>"
                            alt=""
                        >
                    <?php else: ?>
                        <span>CP</span>
                    <?php endif; ?>
                </div>

                <div>
                    <strong>
                        <?php echo propertyPortalEscape(
                            $propertyName
                        ); ?>
                    </strong>

                    <span>
                        <?php echo propertyPortalEscape(
                            $companyName
                        ); ?>
                    </span>
                </div>
            </header>

            <div class="commercial-brand-message">
                <span class="commercial-eyebrow">
                    COMMERCIAL PROPERTY MANAGEMENT
                </span>

                <h1>
                    <?php echo propertyPortalEscape($tagline); ?>
                </h1>

                <p>
                    Manage complaints, work orders, staff and property
                    performance through a secure, property-specific
                    portal.
                </p>

                <div class="commercial-feature-row">
                    <div>
                        <strong>Secure</strong>
                        <span>Role-based access</span>
                    </div>

                    <div>
                        <strong>Focused</strong>
                        <span>Property-isolated data</span>
                    </div>

                    <div>
                        <strong>Responsive</strong>
                        <span>Desktop and mobile</span>
                    </div>
                </div>
            </div>

            <footer class="commercial-brand-footer">
                <span>
                    <?php echo propertyPortalEscape($systemName); ?>
                </span>

                <?php if ($propertyCode !== ''): ?>
                    <strong>
                        <?php echo propertyPortalEscape(
                            $propertyCode
                        ); ?>
                    </strong>
                <?php endif; ?>
            </footer>
        </div>
    </section>

    <main class="commercial-login-panel">
        <section class="commercial-login-card">
            <div class="mobile-login-brand">
                <div class="commercial-brand-logo">
                    <?php if ($logoUrl !== ''): ?>
                        <img
                            src="<?php echo propertyPortalEscape(
                                $logoUrl
                            ); ?>"
                            alt=""
                        >
                    <?php else: ?>
                        <span>CP</span>
                    <?php endif; ?>
                </div>

                <div>
                    <strong>
                        <?php echo propertyPortalEscape(
                            $propertyName
                        ); ?>
                    </strong>

                    <span>Property Portal</span>
                </div>
            </div>

            <span class="commercial-eyebrow login-eyebrow">
                PROPERTY PORTAL
            </span>

            <h2>Welcome back</h2>

            <p class="commercial-login-description">
                Sign in with the account provided by your system
                administrator.
            </p>

            <?php if (isset($_GET['logout'])): ?>
                <div
                    class="commercial-alert commercial-alert-success"
                    role="status"
                >
                    You have signed out successfully.
                </div>
            <?php elseif (isset($_GET['inactive'])): ?>
                <div
                    class="commercial-alert commercial-alert-danger"
                    role="alert"
                >
                    The account is inactive or access is not permitted.
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div
                    class="commercial-alert commercial-alert-danger"
                    role="alert"
                >
                    <?php foreach ($errors as $error): ?>
                        <div>
                            <?php echo propertyPortalEscape($error); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form
                method="post"
                id="commercialLoginForm"
                class="commercial-login-form"
                novalidate
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo propertyPortalEscape(
                        $csrfToken
                    ); ?>"
                >
                <input
                    type="hidden"
                    name="property_code"
                    value="<?php echo propertyPortalEscape($propertyCode); ?>"
                >

                <div class="commercial-field">
                    <label for="login">
                        Username or Email
                    </label>

                    <div class="commercial-input-wrap">
                        <span
                            class="commercial-input-icon"
                            aria-hidden="true"
                        >
                            @
                        </span>

                        <input
                            id="login"
                            name="login"
                            type="text"
                            value="<?php echo propertyPortalEscape(
                                $loginValue
                            ); ?>"
                            autocomplete="username"
                            autofocus
                            required
                        >
                    </div>
                </div>

                <div class="commercial-field">
                    <label for="password">
                        Password
                    </label>

                    <div class="commercial-input-wrap">
                        <span
                            class="commercial-input-icon"
                            aria-hidden="true"
                        >
                            ●
                        </span>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            id="passwordToggle"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            Show
                        </button>
                    </div>
                </div>

                <div class="commercial-form-options">
                    <label class="commercial-checkbox">
                        <input
                            type="checkbox"
                            name="remember_login"
                            value="1"
                            <?php echo $rememberLogin
                                ? 'checked'
                                : ''; ?>
                        >

                        <span>Remember username</span>
                    </label>

                    <span class="secure-login-label">
                        Secure sign-in
                    </span>
                </div>

                <button
                    class="commercial-submit"
                    id="commercialLoginButton"
                    type="submit"
                >
                    <span class="button-label">Sign In</span>
                    <span
                        class="button-spinner"
                        aria-hidden="true"
                    ></span>
                </button>
            </form>

            <div class="commercial-login-note">
                <span>Authorised users only</span>
                <span>•</span>
                <span>Protected by CSRF verification</span>
            </div>

            <footer class="commercial-login-footer">
                <span>
                    <?php echo propertyPortalEscape($systemName); ?>
                </span>

                <span>
                    &copy; <?php echo date('Y'); ?>
                    <?php echo propertyPortalEscape(
                        $companyName
                    ); ?>
                </span>
            </footer>
        </section>
    </main>
</div>

<script src="assets/commercial-login.js"></script>
<script src="assets/branding-image-fallback.js"></script>
</body>
</html>
