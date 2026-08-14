<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__
    . '/cpms/includes/staff_session_compat.php';

$auditFile = __DIR__
    . '/cpms/includes/unified_auth_audit.php';

if (is_file($auditFile)) {
    require_once $auditFile;

    if (function_exists('cpmsUnifiedAuditLogout')) {
        cpmsUnifiedAuditLogout($conn);
    }
}

cpmsStaffSessionDestroy();

header('Location: cpms/login.php?logout=1', true, 302);
exit;
