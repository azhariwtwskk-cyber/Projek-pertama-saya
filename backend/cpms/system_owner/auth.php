<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$systemOwnerId = (int) ($_SESSION['system_owner_id'] ?? 0);

$systemOwnerIdleTimeout = 1800;
$systemOwnerLastActivity = (int) (
    $_SESSION['system_owner_last_activity']
    ?? 0
);

if (
    $systemOwnerLastActivity > 0
    && (time() - $systemOwnerLastActivity) > $systemOwnerIdleTimeout
) {
    cpmsPortalClearSessionKeys([
        'system_owner_id',
        'system_owner_name',
        'system_owner_role',
        'system_owner_last_activity',
        'system_owner_csrf',
    ]);
    session_regenerate_id(true);
    systemOwnerRedirect('login.php?expired=1');
}

$_SESSION['system_owner_last_activity'] = time();

if ($systemOwnerId <= 0) {
    systemOwnerRedirect('login.php');
}

$stmt = $conn->prepare("
    SELECT
        users.id,
        users.full_name,
        users.username,
        users.email,
        roles.role_code AS role,
        users.status,
        users.last_login_at
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
    WHERE users.id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Tidak dapat mengesahkan akaun System Owner.');
}

$stmt->bind_param('i', $systemOwnerId);
$stmt->execute();

$result = $stmt->get_result();
$systemOwnerUser = $result->fetch_assoc();

$stmt->close();

if (
    !$systemOwnerUser ||
    $systemOwnerUser['status'] !== 'active' ||
    $systemOwnerUser['role'] !== 'system_owner'
) {
    $_SESSION = [];
    session_destroy();
    systemOwnerRedirect('login.php');
}

/*
 * CPMS v3.1.1 page-level permission enforcement.
 * Also restores unified context for an older valid owner session.
 */
$_SESSION['cpms_user_id'] = $systemOwnerId;
$_SESSION['cpms_user_role'] = 'system_owner';
$_SESSION['cpms_property_id'] = null;

require_once dirname(__DIR__) . '/includes/permission_engine.php';

$systemOwnerPagePermissions = [
    'dashboard.php' => 'dashboard.view',
    'add_property.php' => 'properties.manage',
    'properties.php' => 'properties.view',
    'add_property_admin.php' => 'users.create',
    'property_admins.php' => 'users.view',
    'hq_inspectors.php' => 'inspection.view',
    'demo_data.php' => 'settings.manage',
    'health_check.php' => 'audit.view',
    'modules.php' => 'settings.manage',
    'system_settings.php' => 'settings.manage',
    'permission_health.php' => 'permissions.manage',
    'auth_audit.php' => 'audit.view',
];

$systemOwnerScript = basename(
    (string) ($_SERVER['SCRIPT_NAME'] ?? '')
);
$systemOwnerRequiredPermission = (
    $systemOwnerPagePermissions[$systemOwnerScript]
    ?? 'dashboard.view'
);

cpmsRequire($systemOwnerRequiredPermission, $conn);
