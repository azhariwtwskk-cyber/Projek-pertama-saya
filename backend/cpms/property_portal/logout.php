<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__)
    . '/includes/unified_auth_audit.php';

cpmsUnifiedAuditLogout($conn);

cpmsPortalClearSessionKeys([
    'property_admin_id',
    'property_admin_property_id',
    'property_admin_role',
    'property_admin_name',
    'property_admin_last_activity',
    'property_admin_session_rotated_at',
    'property_admin_force_password_change',
    'property_portal_csrf',
    'cpms_user_id',
    'cpms_user_role',
    'cpms_property_id',
    'cpms_authenticated_at',
    'cpms_unified_login_csrf',
]);

session_regenerate_id(true);

propertyPortalRedirect('../login.php?logout=1');
