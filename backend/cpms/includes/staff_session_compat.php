<?php
declare(strict_types=1);

/*
 * CPMS v3.3.6.1 - Staff Unified Session Compatibility
 * Upload to: htdocs/cpms/includes/staff_session_compat.php
 */

function cpmsStaffSessionClearPermissionCache(): void
{
    unset($_SESSION['cpms_permission_cache']);
}

function cpmsStaffSessionRepair(mysqli $db): bool
{
    $legacyStaffId = (int) ($_SESSION['staff_id'] ?? 0);

    if ($legacyStaffId <= 0) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT
            su.id AS system_user_id,
            su.full_name,
            ur.property_id
         FROM system_users su
         INNER JOIN user_roles ur
            ON ur.system_user_id = su.id
           AND ur.status = 'active'
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.status = 'active'
           AND r.role_code = 'staff'
         WHERE su.source_table = 'staff'
           AND su.source_id = ?
           AND su.status = 'active'
         ORDER BY
            CASE
                WHEN ur.property_id = NULLIF(?, 0) THEN 0
                ELSE 1
            END,
            ur.id
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $legacyPropertyId = (int) (
        $_SESSION['staff_property_id']
        ?? $_SESSION['cpms_property_id']
        ?? 0
    );
    $stmt->bind_param('ii', $legacyStaffId, $legacyPropertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return false;
    }

    $systemUserId = (int) ($row['system_user_id'] ?? 0);
    $propertyId = (int) ($row['property_id'] ?? 0);

    if ($systemUserId <= 0 || $propertyId <= 0) {
        return false;
    }

    $oldSignature = implode(':', [
        (int) ($_SESSION['cpms_user_id'] ?? 0),
        (string) ($_SESSION['cpms_user_role'] ?? ''),
        (int) ($_SESSION['cpms_property_id'] ?? 0),
    ]);
    $newSignature = $systemUserId . ':staff:' . $propertyId;

    $_SESSION['cpms_user_id'] = $systemUserId;
    $_SESSION['cpms_user_role'] = 'staff';
    $_SESSION['cpms_property_id'] = $propertyId;
    $_SESSION['cpms_authenticated_at'] = (int) (
        $_SESSION['cpms_authenticated_at'] ?? time()
    );
    $_SESSION['staff_property_id'] = $propertyId;
    $_SESSION['staff_name'] = (string) (
        $row['full_name']
        ?? $_SESSION['staff_name']
        ?? 'Staff'
    );

    if ($oldSignature !== $newSignature) {
        cpmsStaffSessionClearPermissionCache();
        session_regenerate_id(true);
    }

    return true;
}

function cpmsStaffSessionDestroy(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            (string) $params['path'],
            (string) $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}
