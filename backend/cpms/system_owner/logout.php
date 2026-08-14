<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__)
    . '/includes/unified_auth_audit.php';

cpmsUnifiedAuditLogout($conn);

cpmsPortalClearSessionKeys([
    'system_owner_id',
    'system_owner_name',
    'system_owner_role',
    'system_owner_last_activity',
    'system_owner_login_rate',
    'system_owner_csrf',
    'cpms_user_id',
    'cpms_user_role',
    'cpms_property_id',
    'cpms_authenticated_at',
    'cpms_unified_login_csrf',
]);

session_regenerate_id(true);

systemOwnerRedirect('../login.php?logout=1');
