<?php
declare(strict_types=1);

/*
 * CPMS v3.4.11 - Unified Global Branding Settings
 * Upload to: htdocs/cpms/login.php
 */

$bootstrapFile = __DIR__ . '/includes/cpms_bootstrap.php';
$unifiedAuthFile = __DIR__ . '/includes/unified_auth.php';
$unifiedAuditFile = __DIR__
    . '/includes/unified_auth_audit.php';

if (
    !is_file($bootstrapFile)
    || !is_file($unifiedAuthFile)
    || !is_file($unifiedAuditFile)
) {
    http_response_code(500);
    exit('CPMS Unified Login dependencies are unavailable.');
}

require_once $bootstrapFile;
require_once $unifiedAuthFile;
require_once $unifiedAuditFile;
require_once __DIR__ . '/includes/property_user_sync.php';

function cpmsUnifiedLoginText(string $key): string
{
    global $cpmsLanguage, $cpmsSettings;

    $translations = [
        'ms' => [
            'page_title' => 'Log Masuk Bersepadu',
            'secure_access' => 'Akses selamat CPMS',
            'brand_eyebrow' => 'OPERASI PROPERTY BERSEPADU',
            'brand_title' => "Satu akaun.\nSemua akses.",
            'brand_description' => (
                'CPMS mengesan peranan dan property anda secara automatik, '
                . 'kemudian membawa anda terus ke ruang kerja yang betul.'
            ),
            'login_title' => 'Log masuk ke CPMS',
            'login_description' => (
                'Gunakan username atau e-mel dan kata laluan anda.'
            ),
            'logout_success' => 'Anda telah log keluar dengan selamat.',
            'login_label' => 'Username atau e-mel',
            'password_label' => 'Kata laluan',
            'remember_label' => 'Ingat username saya',
            'forgot_password' => 'Lupa kata laluan?',
            'button' => 'Log Masuk',
            'locked_minutes' => (
                'Terlalu banyak percubaan gagal. Cuba lagi dalam %d minit.'
            ),
            'invalid_csrf' => (
                'Sesi keselamatan tidak sah. Muat semula halaman.'
            ),
            'required' => 'Masukkan username/e-mel dan kata laluan.',
            'unsupported_portal' => (
                'Akaun sah tetapi portal untuk role ini belum diaktifkan '
                . 'dalam Unified Login Pilot.'
            ),
            'locked' => (
                'Terlalu banyak percubaan gagal. '
                . 'Login dikunci selama 15 minit.'
            ),
            'invalid_login' => (
                'Maklumat login tidak betul atau akaun tidak aktif.'
            ),
        ],
        'en' => [
            'page_title' => 'Unified Login',
            'secure_access' => 'Secure CPMS access',
            'brand_eyebrow' => 'UNIFIED PROPERTY OPERATIONS',
            'brand_title' => "One account.\nEvery access.",
            'brand_description' => (
                'CPMS automatically detects your role and property, '
                . 'then takes you directly to the correct workspace.'
            ),
            'login_title' => 'Sign in to CPMS',
            'login_description' => (
                'Use your username or email and password.'
            ),
            'logout_success' => 'You have signed out successfully.',
            'login_label' => 'Username or email',
            'password_label' => 'Password',
            'remember_label' => 'Remember my username',
            'forgot_password' => 'Forgot your password?',
            'button' => 'Sign In',
            'locked_minutes' => (
                'Too many unsuccessful attempts. Try again in %d minutes.'
            ),
            'invalid_csrf' => (
                'The security session is invalid. Refresh the page.'
            ),
            'required' => 'Enter your username/email and password.',
            'unsupported_portal' => (
                'The account is valid, but this role is not yet enabled '
                . 'in the Unified Login Pilot.'
            ),
            'locked' => (
                'Too many unsuccessful attempts. '
                . 'Login is locked for 15 minutes.'
            ),
            'invalid_login' => (
                'The login details are incorrect or the account is inactive.'
            ),
        ],
    ];

    $language = in_array($cpmsLanguage, ['ms', 'en'], true)
        ? $cpmsLanguage
        : 'ms';

    $settingKeys = [
        'secure_access' => 'login_secure_label_' . $language,
        'brand_eyebrow' => 'login_eyebrow_' . $language,
        'brand_title' => 'login_brand_title_' . $language,
        'brand_description' => 'login_brand_description_' . $language,
        'login_title' => 'login_title_' . $language,
        'login_description' => 'login_description_' . $language,
        'login_footer' => 'login_footer_' . $language,
    ];

    if (isset($settingKeys[$key])) {
        $configured = trim(setting($cpmsSettings, $settingKeys[$key]));
        if ($configured !== '') {
            return $configured;
        }
    }

    if ($key === 'page_title') {
        $title = trim(setting(
            $cpmsSettings,
            'login_title_' . $language,
            (string) $translations[$language]['page_title']
        ));
        $shortName = trim(setting($cpmsSettings, 'system_short_name', 'CPMS'));

        return $title . ($shortName !== '' ? ' | ' . $shortName : '');
    }

    if ($key === 'login_footer') {
        return setting(
            $cpmsSettings,
            'system_name',
            'Commercial Property Management System'
        );
    }

    return (string) (
        $translations[$language][$key]
        ?? $translations['ms'][$key]
        ?? $key
    );
}

function cpmsUnifiedMustChangePassword(mysqli $conn): bool
{
    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
    if ($userId < 1) {
        return false;
    }
    $stmt = $conn->prepare(
        'SELECT must_change_password FROM system_users WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['must_change_password'] ?? 0) === 1;
}

if (cpmsUnifiedMustChangePassword($conn)) {
    cpmsPortalRedirect('change_password.php?required=1');
}

if (!empty($_SESSION['system_owner_id'])) {
    cpmsPortalRedirect('system_owner/dashboard.php');
}

if (!empty($_SESSION['property_admin_id'])) {
    cpmsPortalRedirect('property_portal/dashboard.php');
}

if (!empty($_SESSION['staff_id'])) {
    cpmsPortalRedirect('../staff_dashboard.php');
}

if (!empty($_SESSION['security_guard_id'])) {
    cpmsPortalRedirect('../security_dashboard.php');
}

function cpmsUnifiedLoginRateState(): array
{
    $state = $_SESSION['cpms_unified_login_rate'] ?? [];

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

function cpmsUnifiedLoginRegisterFailure(): array
{
    $state = cpmsUnifiedLoginRateState();
    $attempts = $state['attempts'] + 1;
    $lockedUntil = $attempts >= 5 ? time() + 900 : 0;

    $_SESSION['cpms_unified_login_rate'] = [
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
    ];

    return $_SESSION['cpms_unified_login_rate'];
}

function cpmsUnifiedLoginResetRate(): void
{
    unset($_SESSION['cpms_unified_login_rate']);
}

function cpmsUnifiedLoginCsrfToken(): string
{
    return cpmsPortalCsrfToken('cpms_unified_login_csrf');
}

function cpmsUnifiedLoginVerifyCsrf(?string $token): bool
{
    return cpmsPortalVerifyCsrf(
        'cpms_unified_login_csrf',
        $token
    );
}

function cpmsUnifiedLoginUpdateLastLogin(
    mysqli $conn,
    array $authentication
): void {
    $user = $authentication['user'] ?? [];
    $systemUserId = (int) ($user['id'] ?? 0);

    if ($systemUserId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE system_users
         SET last_login_at = NOW()
         WHERE id = ?'
    );

    if ($stmt) {
        $stmt->bind_param('i', $systemUserId);
        $stmt->execute();
        $stmt->close();
    }

    if (
        (string) ($user['source_table'] ?? '') === 'property_admins'
        && (int) ($user['source_id'] ?? 0) > 0
    ) {
        $legacyId = (int) $user['source_id'];
        $legacyUpdate = $conn->prepare(
            'UPDATE property_admins
             SET last_login_at = NOW()
             WHERE id = ?'
        );

        if ($legacyUpdate) {
            $legacyUpdate->bind_param('i', $legacyId);
            $legacyUpdate->execute();
            $legacyUpdate->close();
        }
    }
}

function cpmsUnifiedLoginUpgradePassword(
    mysqli $conn,
    array $authentication,
    string $plainPassword
): void {
    if (empty($authentication['password']['needs_rehash'])) {
        return;
    }

    $systemUserId = (int) (
        $authentication['user']['id']
        ?? 0
    );

    if ($systemUserId <= 0) {
        return;
    }

    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    if (!is_string($newHash) || $newHash === '') {
        return;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE system_users
             SET password_hash = ?
             WHERE id = ?'
        );

        if (!$stmt) {
            throw new RuntimeException('Password rehash update failed.');
        }

        $stmt->bind_param('si', $newHash, $systemUserId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Password rehash update failed.');
        }
        $stmt->close();
        cpmsPropertyUserMirrorPasswordFromSystem($conn, $systemUserId);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
    }
}

$errors = [];
$loginValue = (string) (
    $_COOKIE['cpms_unified_login_name']
    ?? ''
);
$rememberLogin = $loginValue !== '';
$rateState = cpmsUnifiedLoginRateState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginValue = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $rememberLogin = isset($_POST['remember_login']);
    $csrf = $_POST['csrf_token'] ?? null;
    $rateState = cpmsUnifiedLoginRateState();

    if ($rateState['locked_until'] > time()) {
        $remainingMinutes = max(
            1,
            (int) ceil(
                ($rateState['locked_until'] - time()) / 60
            )
        );

        $errors[] = sprintf(
            cpmsUnifiedLoginText('locked_minutes'),
            $remainingMinutes
        );
    }

    if (
        !$errors
        && !cpmsUnifiedLoginVerifyCsrf(
            is_string($csrf) ? $csrf : null
        )
    ) {
        $errors[] = cpmsUnifiedLoginText('invalid_csrf');
    }

    if (!$errors && ($loginValue === '' || $password === '')) {
        $errors[] = cpmsUnifiedLoginText('required');
    }

    if (!$errors) {
        $databaseLock = cpmsUnifiedLoginLockState(
            $conn,
            $loginValue
        );

        if (!empty($databaseLock['locked'])) {
            $errors[] = cpmsUnifiedLoginText('locked');

            cpmsUnifiedAuditEvent(
                $conn,
                'login_blocked',
                null,
                null,
                null,
                'Login blocked by database throttle.'
            );
        }
    }

    if (!$errors) {
        try {
            $authentication = cpmsUnifiedAuthenticate(
                $conn,
                $loginValue,
                $password
            );
        } catch (Throwable $exception) {
            if (function_exists('cpmsFoundationLog')) {
                cpmsFoundationLog(
                    'Unified login failed: '
                    . $exception->getMessage()
                );
            }

            $authentication = [
                'valid' => false,
                'code' => 'system_error',
            ];
        }

        $redirect = (string) (
            $authentication['context']['redirect']
            ?? ''
        );

        if (!empty($authentication['valid']) && $redirect !== '') {
            cpmsUnifiedLoginResetRate();
            session_regenerate_id(true);

            cpmsPortalClearSessionKeys([
                'system_owner_id',
                'system_owner_name',
                'system_owner_role',
                'system_owner_last_activity',
                'property_admin_id',
                'property_admin_property_id',
                'property_admin_role',
                'property_admin_name',
                'property_admin_last_activity',
                'property_admin_session_rotated_at',
                'staff_id',
                'staff_name',
                'staff_role',
                'staff_property_id',
                'staff_last_activity',
                'staff_pwa_login_at',
                'security_guard_id',
                'security_guard_name',
                'security_guard_type',
                'security_guard_property_id',
                'security_guard_last_activity',
            ]);

            $sessionMap = cpmsUnifiedLegacySessionMap(
                $authentication,
                $conn
            );

            foreach ($sessionMap as $key => $value) {
                $_SESSION[$key] = $value;
            }

            $authenticatedUserId = (int) (
                $authentication['user']['id']
                ?? 0
            );
            $authenticatedPropertyId = (int) (
                $authentication['context']['property_id']
                ?? 0
            );
            $authenticatedRole = (string) (
                $authentication['context']['role']
                ?? ''
            );

            cpmsUnifiedClearLoginFailures(
                $conn,
                $loginValue
            );
            cpmsUnifiedAuditRecordSession(
                $conn,
                $authenticatedUserId,
                $authenticatedPropertyId > 0
                    ? $authenticatedPropertyId
                    : null,
                $authenticatedRole
            );
            cpmsUnifiedAuditEvent(
                $conn,
                'login_success',
                $authenticatedUserId,
                $authenticatedPropertyId > 0
                    ? $authenticatedPropertyId
                    : null,
                $authenticatedRole,
                'Unified login successful.',
                [
                    'portal' => (
                        (string) (
                            $authentication['context']['portal']
                            ?? ''
                        )
                    ),
                ]
            );

            cpmsUnifiedLoginUpgradePassword(
                $conn,
                $authentication,
                $password
            );
            cpmsUnifiedLoginUpdateLastLogin(
                $conn,
                $authentication
            );

            if ($rememberLogin) {
                setcookie(
                    'cpms_unified_login_name',
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
                    'cpms_unified_login_name',
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

            unset($_SESSION['cpms_unified_login_csrf']);
            if ((int) (
                $authentication['user']['must_change_password'] ?? 0
            ) === 1) {
                cpmsPortalRedirect('change_password.php?required=1');
            }
            cpmsPortalRedirect($redirect);
        }

        $rateState = cpmsUnifiedLoginRegisterFailure();
        $databaseFailure = cpmsUnifiedRegisterLoginFailure(
            $conn,
            $loginValue
        );
        $candidateUser = cpmsUnifiedAuthFindUser(
            $conn,
            $loginValue
        );
        $candidateUserId = is_array($candidateUser)
            ? (int) ($candidateUser['id'] ?? 0)
            : 0;
        $candidatePropertyId = is_array($candidateUser)
            ? (int) ($candidateUser['property_id'] ?? 0)
            : 0;
        $eventType = (
            ($authentication['code'] ?? '') === 'no_portal_context'
        )
            ? 'login_role_denied'
            : 'login_failed';

        cpmsUnifiedAuditEvent(
            $conn,
            $eventType,
            $candidateUserId > 0 ? $candidateUserId : null,
            $candidatePropertyId > 0
                ? $candidatePropertyId
                : null,
            null,
            'Unified login was not completed.',
            [
                'reason' => (
                    (string) (
                        $authentication['code']
                        ?? 'unknown'
                    )
                ),
                'failure_count' => (
                    (int) (
                        $databaseFailure['failure_count']
                        ?? 0
                    )
                ),
            ]
        );

        if (
            ($authentication['code'] ?? '') === 'no_portal_context'
        ) {
            $errors[] = cpmsUnifiedLoginText('unsupported_portal');
        } elseif ($rateState['locked_until'] > time()) {
            $errors[] = cpmsUnifiedLoginText('locked');
        } else {
            $errors[] = cpmsUnifiedLoginText('invalid_login');
        }
    }
}

$csrfToken = cpmsUnifiedLoginCsrfToken();
$loginPrimaryColor = cpmsSystemSettingHex(
    setting($cpmsSettings, 'primary_color'),
    '#0f2342'
);
$loginSecondaryColor = cpmsSystemSettingHex(
    setting($cpmsSettings, 'secondary_color'),
    '#d6a84b'
);
$loginLogoPath = cpmsSystemSettingAsset(
    setting($cpmsSettings, 'logo_path')
);
$loginFaviconPath = cpmsSystemSettingAsset(
    setting($cpmsSettings, 'favicon_path')
);
$loginBackgroundPath = cpmsSystemSettingAsset(
    setting($cpmsSettings, 'background_path')
);
$loginSystemName = trim(setting(
    $cpmsSettings,
    'system_name',
    'Commercial Property Management System'
));
$loginShortName = trim(setting($cpmsSettings, 'system_short_name', 'CPMS'));
$loginCompanyName = trim(setting($cpmsSettings, 'company_name', 'CPMS Enterprise'));
$loginTagline = trim(setting($cpmsSettings, 'system_tagline', ''));
$loginVersion = trim(setting($cpmsSettings, 'software_version', '3.4.11'));
$loginLogoInitials = strtoupper(substr($loginShortName !== '' ? $loginShortName : 'CP', 0, 2));
?>
<!doctype html>
<html lang="<?php echo cpmsPortalEscape($cpmsLanguage); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?php echo cpmsPortalEscape(cpmsUnifiedLoginText('page_title')); ?></title>
    <?php if ($loginFaviconPath !== ''): ?>
        <link rel="icon" href="<?php echo cpmsPortalEscape($loginFaviconPath); ?>">
    <?php endif; ?>
    <style>
        :root {
            --navy: <?php echo cpmsPortalEscape($loginPrimaryColor); ?>;
            --blue: <?php echo cpmsPortalEscape($loginPrimaryColor); ?>;
            --gold: <?php echo cpmsPortalEscape($loginSecondaryColor); ?>;
            --surface: #ffffff;
            --muted: #64748b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: #172033;
            background:
                radial-gradient(circle at 10% 10%, #dbeafe 0, transparent 34%),
                linear-gradient(145deg, #eef4fb, #f8fafc);
            font-family: Arial, sans-serif;
        }
        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(320px, 1.1fr) minmax(380px, .9fr);
        }
        .brand {
            position: relative;
            overflow: hidden;
            padding: clamp(36px, 7vw, 92px);
            color: #fff;
            background-color: var(--navy);
            background-image: var(--login-background, none);
            background-size: cover;
            background-position: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .brand::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background: var(--navy);
            opacity: .86;
        }
        .brand::after {
            content: "";
            position: absolute;
            width: 420px;
            height: 420px;
            right: -180px;
            bottom: -170px;
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 50%;
            box-shadow:
                0 0 0 60px rgba(255,255,255,.04),
                0 0 0 120px rgba(255,255,255,.03);
            z-index: 0;
        }
        .brand > * { position: relative; z-index: 1; }
        .logo {
            display: flex;
            align-items: center;
            gap: 13px;
            font-weight: 800;
            letter-spacing: .04em;
        }
        .logo-mark {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: linear-gradient(145deg, var(--gold), #f3d28d);
            color: var(--navy);
            font-size: 22px;
            overflow: hidden;
        }
        .logo-mark img { width: 100%; height: 100%; object-fit: contain; }
        .logo-copy { display: grid; gap: 3px; }
        .logo-copy strong { font-size: 14px; }
        .logo-copy small { color: rgba(255,255,255,.7); font-size: 10px; letter-spacing: .1em; }
        .brand-copy { max-width: 650px; }
        .eyebrow {
            display: block;
            margin-bottom: 14px;
            color: #bfdbfe;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .16em;
        }
        .brand h1 {
            margin: 0;
            font-size: clamp(38px, 5vw, 66px);
            line-height: 1.04;
        }
        .brand p {
            margin: 22px 0 0;
            max-width: 570px;
            color: #dbeafe;
            font-size: 17px;
            line-height: 1.7;
        }
        .brand-footer { color: #bfdbfe; font-size: 13px; }
        .panel {
            display: grid;
            place-items: center;
            padding: 34px;
        }
        .card {
            position: relative;
            width: min(460px, 100%);
            padding: clamp(28px, 5vw, 46px);
            background: rgba(255,255,255,.96);
            border: 1px solid #dbe3ef;
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(26,43,73,.12);
        }
        .card h2 { margin: 0 0 9px; font-size: 29px; }
        .language-switch {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 4px;
            padding: 4px;
            border-radius: 9px;
            background: #eef2f7;
        }
        .language-switch a {
            padding: 6px 9px;
            border-radius: 7px;
            color: #475569;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
        }
        .language-switch a.active {
            background: #fff;
            color: var(--navy);
            box-shadow: 0 1px 4px rgba(15,35,66,.12);
        }
        .description { margin: 0 0 24px; color: var(--muted); line-height: 1.55; }
        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 9px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 14px;
        }
        label { display: block; margin: 15px 0 7px; font-weight: 700; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 15px;
            outline: none;
        }
        input:focus {
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(15,35,66,.12);
        }
        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 16px 0;
            color: #475569;
            font-size: 14px;
        }
        .forgot {
            margin: -4px 0 16px;
            text-align: right;
            font-size: 14px;
        }
        .forgot a {
            color: var(--navy);
            font-weight: 700;
            text-decoration: none;
        }
        button {
            width: 100%;
            padding: 13px 16px;
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--navy), var(--gold));
            color: #fff;
            font-weight: 800;
            font-size: 15px;
            cursor: pointer;
        }
        .pilot {
            margin-top: 18px;
            color: #64748b;
            font-size: 12px;
            text-align: center;
        }
        @media (max-width: 820px) {
            .shell { grid-template-columns: 1fr; }
            .brand { min-height: 300px; padding: 34px 28px; }
            .brand h1 { font-size: 38px; }
            .brand-footer { display: none; }
            .panel { padding: 24px 16px; }
        }
    </style>
    <link rel="stylesheet" href="assets/genesis/unified-login-genesis.css?v=5.0.0">
</head>
<body>
<div class="shell">
    <section class="brand"<?php if ($loginBackgroundPath !== ''): ?> style="--login-background:url('<?php echo cpmsPortalEscape($loginBackgroundPath); ?>')"<?php endif; ?>>
        <div class="logo">
            <span class="logo-mark">
                <?php if ($loginLogoPath !== ''): ?>
                    <img src="<?php echo cpmsPortalEscape($loginLogoPath); ?>" alt="<?php echo cpmsPortalEscape($loginSystemName); ?>">
                <?php else: ?>
                    <?php echo cpmsPortalEscape($loginLogoInitials); ?>
                <?php endif; ?>
            </span>
            <span class="logo-copy">
                <strong><?php echo cpmsPortalEscape($loginCompanyName); ?></strong>
                <?php if ($loginTagline !== ''): ?><small><?php echo cpmsPortalEscape($loginTagline); ?></small><?php endif; ?>
            </span>
        </div>

        <div class="brand-copy">
            <span class="eyebrow">
                <?php echo cpmsPortalEscape(
                    cpmsUnifiedLoginText('brand_eyebrow')
                ); ?>
            </span>
            <h1><?php echo nl2br(cpmsPortalEscape(cpmsUnifiedLoginText('brand_title'))); ?></h1>
            <p>
                <?php echo cpmsPortalEscape(
                    cpmsUnifiedLoginText('brand_description')
                ); ?>
            </p>
        </div>

        <div class="brand-footer">
            <?php echo cpmsPortalEscape(cpmsUnifiedLoginText('login_footer')); ?>
        </div>
    </section>

    <main class="panel">
        <section class="card">
            <nav class="language-switch" aria-label="Language">
                <a
                    href="?lang=ms"
                    class="<?php echo $cpmsLanguage === 'ms' ? 'active' : ''; ?>"
                >BM</a>
                <a
                    href="?lang=en"
                    class="<?php echo $cpmsLanguage === 'en' ? 'active' : ''; ?>"
                >EN</a>
            </nav>

            <span class="eyebrow" style="color:var(--navy)">
                <?php echo cpmsPortalEscape(
                    cpmsUnifiedLoginText('secure_access')
                ); ?>
            </span>
            <h2>
                <?php echo cpmsPortalEscape(
                    cpmsUnifiedLoginText('login_title')
                ); ?>
            </h2>
            <p class="description">
                <?php echo cpmsPortalEscape(
                    cpmsUnifiedLoginText('login_description')
                ); ?>
            </p>

            <?php if (isset($_GET['logout'])): ?>
                <div class="alert" style="background:#dcfce7;color:#166534">
                    <?php echo cpmsPortalEscape(
                        cpmsUnifiedLoginText('logout_success')
                    ); ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert">
                    <?php foreach ($errors as $error): ?>
                        <div>
                            <?php echo cpmsPortalEscape($error); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo cpmsPortalEscape($csrfToken); ?>"
                >

                <label for="login">
                    <?php echo cpmsPortalEscape(
                        cpmsUnifiedLoginText('login_label')
                    ); ?>
                </label>
                <input
                    id="login"
                    name="login"
                    type="text"
                    value="<?php echo cpmsPortalEscape($loginValue); ?>"
                    autocomplete="username"
                    required
                >

                <label for="password">
                    <?php echo cpmsPortalEscape(
                        cpmsUnifiedLoginText('password_label')
                    ); ?>
                </label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >

                <label class="remember">
                    <input
                        type="checkbox"
                        name="remember_login"
                        value="1"
                        <?php echo $rememberLogin ? 'checked' : ''; ?>
                    >
                    <?php echo cpmsPortalEscape(
                        cpmsUnifiedLoginText('remember_label')
                    ); ?>
                </label>

                <p class="forgot">
                    <a href="forgot_password.php">
                        <?php echo cpmsPortalEscape(
                            cpmsUnifiedLoginText('forgot_password')
                        ); ?>
                    </a>
                </p>

                <button type="submit">
                    <?php echo cpmsPortalEscape(
                        cpmsUnifiedLoginText('button')
                    ); ?>
                </button>
            </form>

            <p class="pilot">
                <?php echo cpmsPortalEscape($loginShortName); ?> v<?php echo cpmsPortalEscape($loginVersion); ?>
            </p>
        </section>
    </main>
</div>
</body>
</html>
