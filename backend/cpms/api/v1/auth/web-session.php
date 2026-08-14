<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');

$identity = cpmsApiRequireRole(['staff', 'security']);
$db = cpmsApiDatabase();
$role = (string) $identity['role'];
$propertyId = (int) $identity['property_id'];
$systemUserId = (int) $identity['system_user_id'];
$sourceId = (int) $identity['source_id'];
$sourceTable = (string) $identity['source_table'];

if ($sourceId < 1
    || ($role === 'staff' && $sourceTable !== 'staff')
    || ($role === 'security' && $sourceTable !== 'security_guards')) {
    cpmsApiError(
        'PROFILE_NOT_LINKED',
        'Profil Workforce tidak dipadankan dengan portal web.',
        409
    );
}

$sessionKeys = [
    'system_owner_id', 'system_owner_name', 'system_owner_role',
    'property_admin_id', 'property_admin_property_id',
    'property_admin_role', 'property_admin_name',
    'staff_id', 'staff_name', 'staff_role', 'staff_property_id',
    'staff_last_activity', 'staff_pwa_login_at',
    'security_guard_id', 'security_guard_name', 'security_guard_type',
    'security_guard_property_id', 'security_guard_last_activity',
    'cpms_user_id', 'cpms_user_role', 'cpms_property_id',
    'cpms_authenticated_at', 'cpms_permission_cache',
];
foreach ($sessionKeys as $key) {
    unset($_SESSION[$key]);
}

$userStmt = $db->prepare(
    'SELECT * FROM system_users WHERE id=? LIMIT 1'
);
if (!$userStmt) {
    throw new RuntimeException('Unable to prepare web session user lookup.');
}
$userStmt->bind_param('i', $systemUserId);
$userStmt->execute();
$userRow = $userStmt->get_result()->fetch_assoc();
$userStmt->close();
if (!is_array($userRow)) {
    cpmsApiError('USER_NOT_FOUND', 'Akaun pengguna tidak dijumpai.', 404);
}

$authentication = [
    'valid' => true,
    'user' => $userRow,
    'context' => [
        'role' => $role,
        'property_id' => $propertyId,
        'portal' => $role,
        'redirect' => $role === 'staff'
            ? '../staff_dashboard.php'
            : '../security_dashboard.php',
    ],
];

$sessionMap = function_exists('cpmsUnifiedLegacySessionMap')
    ? cpmsUnifiedLegacySessionMap($authentication, $db)
    : [];
if (is_array($sessionMap)) {
    foreach ($sessionMap as $key => $value) {
        $_SESSION[(string) $key] = $value;
    }
}

$_SESSION['cpms_user_id'] = $systemUserId;
$_SESSION['cpms_user_role'] = $role;
$_SESSION['cpms_property_id'] = $propertyId;
$_SESSION['cpms_authenticated_at'] = time();
$_SESSION['cpms_workforce_bridge'] = true;

if ($role === 'staff') {
    $_SESSION['staff_id'] = $sourceId;
    $_SESSION['staff_name'] = (string) $identity['name'];
    $_SESSION['staff_role'] = (string) (
        $_SESSION['staff_role'] ?? 'Staff'
    );
    $_SESSION['staff_property_id'] = $propertyId;
    $_SESSION['staff_last_activity'] = time();
    $_SESSION['staff_pwa_login_at'] = time();
    $redirect = '/staff_dashboard.php?source=workforce';
} else {
    $_SESSION['security_guard_id'] = $sourceId;
    $_SESSION['security_guard_name'] = (string) $identity['name'];
    $_SESSION['security_guard_type'] = (string) (
        $_SESSION['security_guard_type'] ?? 'Security'
    );
    $_SESSION['security_guard_property_id'] = $propertyId;
    $_SESSION['security_guard_last_activity'] = time();
    $redirect = '/security_dashboard.php?source=workforce';
}

session_regenerate_id(true);

$auditFile = cpmsApiRoot() . '/includes/unified_auth_audit.php';
if (is_file($auditFile)) {
    require_once $auditFile;
    if (function_exists('cpmsUnifiedAuditRecordSession')) {
        cpmsUnifiedAuditRecordSession(
            $db,
            $systemUserId,
            $propertyId,
            $role
        );
    }
    if (function_exists('cpmsUnifiedAuditEvent')) {
        cpmsUnifiedAuditEvent(
            $db,
            'workforce_web_session',
            $systemUserId,
            $propertyId,
            $role,
            'Workforce API session bridged to the web portal.'
        );
    }
}

cpmsApiAudit(
    $db,
    $identity,
    'workforce.web_session',
    'success',
    'system_user',
    $systemUserId,
    ['redirect' => $redirect]
);

cpmsApiRespond([
    'redirect_url' => $redirect,
    'role' => $role,
    'property_id' => $propertyId,
]);
