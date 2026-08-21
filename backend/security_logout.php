<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__
    . '/cpms/includes/unified_auth_audit.php';

cpmsUnifiedAuditLogout($conn);

foreach ([
    'security_guard_id',
    'security_guard_name',
    'security_guard_type',
    'security_guard_property_id',
    'security_guard_last_activity',
    'security_patrol_csrf',
    'cpms_user_id',
    'cpms_user_role',
    'cpms_property_id',
    'cpms_authenticated_at',
    'cpms_unified_login_csrf',
] as $key) {
    unset($_SESSION[$key]);
}

session_regenerate_id(true);

header('Location: cpms/login.php?logout=1');
exit;
