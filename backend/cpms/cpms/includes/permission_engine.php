<?php
declare(strict_types=1);

/*
 * CPMS v3.1.1 - Database Permission Engine
 * Upload to: htdocs/cpms/includes/permission_engine.php
 */

function cpmsPermissionUserId(): int
{
    return (int) ($_SESSION['cpms_user_id'] ?? 0);
}

function cpmsPermissionPropertyId(): ?int
{
    $propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
    return $propertyId > 0 ? $propertyId : null;
}

function cpmsPermissionRole(): string
{
    return (string) ($_SESSION['cpms_user_role'] ?? '');
}

function cpmsPermissionLoad(mysqli $db, bool $refresh = false): array
{
    $userId = cpmsPermissionUserId();
    $propertyId = cpmsPermissionPropertyId();
    $role = cpmsPermissionRole();
    $cacheKey = $userId . ':' . (int) $propertyId . ':' . $role;

    if (
        !$refresh
        && isset($_SESSION['cpms_permission_cache'])
        && is_array($_SESSION['cpms_permission_cache'])
        && (string) ($_SESSION['cpms_permission_cache']['key'] ?? '') === $cacheKey
        && (int) ($_SESSION['cpms_permission_cache']['expires_at'] ?? 0) > time()
    ) {
        $cached = $_SESSION['cpms_permission_cache']['permissions'] ?? [];
        return is_array($cached) ? $cached : [];
    }

    if ($userId <= 0 || $role === '') {
        return [];
    }

    $sql = "
        SELECT DISTINCT p.permission_code
        FROM user_roles ur
        INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.status = 'active'
        INNER JOIN role_permissions rp
            ON rp.role_id = r.id
        INNER JOIN permissions p
            ON p.id = rp.permission_id
           AND p.status = 'active'
        WHERE ur.system_user_id = ?
          AND ur.status = 'active'
          AND r.role_code = ?
          AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
          AND (
                r.scope = 'global'
                OR ur.property_id = ?
                OR (ur.property_id IS NULL AND ? IS NULL)
          )
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $propertyValue = $propertyId;
    $stmt->bind_param('isii', $userId, $role, $propertyValue, $propertyValue);
    $stmt->execute();
    $result = $stmt->get_result();
    $permissions = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $code = (string) ($row['permission_code'] ?? '');
            if ($code !== '') {
                $permissions[$code] = true;
            }
        }
        $result->free();
    }
    $stmt->close();

    $_SESSION['cpms_permission_cache'] = [
        'key' => $cacheKey,
        'permissions' => $permissions,
        'expires_at' => time() + 300,
    ];

    return $permissions;
}

function cpmsCan(string $permission, ?mysqli $db = null): bool
{
    if ($permission === '') {
        return false;
    }

    if ($db === null) {
        $candidate = $GLOBALS['conn'] ?? $GLOBALS['mysqli'] ?? null;
        if ($candidate instanceof mysqli) {
            $db = $candidate;
        }
    }

    if (!$db instanceof mysqli) {
        return false;
    }

    $permissions = cpmsPermissionLoad($db);
    return !empty($permissions[$permission]);
}

function cpmsRequire(string $permission, ?mysqli $db = null): void
{
    if ($db === null) {
        $candidate = $GLOBALS['conn'] ?? $GLOBALS['mysqli'] ?? null;
        if ($candidate instanceof mysqli) {
            $db = $candidate;
        }
    }

    $userId = cpmsPermissionUserId();
    if ($db instanceof mysqli && $userId > 0) {
        $passwordCheck = $db->prepare(
            'SELECT must_change_password FROM system_users
             WHERE id = ? LIMIT 1'
        );
        if ($passwordCheck) {
            $passwordCheck->bind_param('i', $userId);
            $passwordCheck->execute();
            $passwordRow = $passwordCheck->get_result()->fetch_assoc();
            $passwordCheck->close();
            if ((int) ($passwordRow['must_change_password'] ?? 0) === 1) {
                header('Location: /cpms/change_password.php?required=1');
                exit;
            }
        }
    }

    if (cpmsCan($permission, $db)) {
        return;
    }

    if ($db instanceof mysqli) {
        cpmsPermissionAuditDenied($db, $permission);
    }

    http_response_code(403);
    $safePermission = htmlspecialchars($permission, ENT_QUOTES, 'UTF-8');
    exit(
        '<!doctype html><html lang="ms"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Akses Ditolak | CPMS</title></head>'
        . '<body style="font-family:Arial;padding:40px;background:#f4f7fb">'
        . '<div style="max-width:650px;margin:auto;background:#fff;padding:28px;'
        . 'border-radius:14px;border:1px solid #dfe6f0">'
        . '<h1>Akses ditolak</h1>'
        . '<p>Akaun anda tidak mempunyai permission '
        . '<strong>' . $safePermission . '</strong>.</p>'
        . '<p><a href="javascript:history.back()">Kembali</a></p>'
        . '</div></body></html>'
    );
}

function cpmsPermissionAuditDenied(
    mysqli $db,
    string $permission
): void {
    $description = json_encode(
        [
            'message' => 'Page-level permission denied.',
            'permission' => $permission,
            'script' => basename(
                (string) ($_SERVER['SCRIPT_NAME'] ?? '')
            ),
            'role' => cpmsPermissionRole(),
        ],
        JSON_UNESCAPED_SLASHES
    );

    if (!is_string($description)) {
        $description = 'Page-level permission denied: '
            . $permission;
    }

    $userId = cpmsPermissionUserId();
    $propertyId = cpmsPermissionPropertyId();
    $ipAddress = substr(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        0,
        45
    );
    $userAgent = substr(
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        0,
        500
    );

    $stmt = $db->prepare(
        "INSERT INTO cpms_auth_audit_logs
            (user_type, user_id, property_id, event_type,
             description, ip_address, user_agent)
         VALUES
            ('system_user', NULLIF(?, 0), ?, 'permission_denied',
             ?, ?, ?)"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iisss',
        $userId,
        $propertyId,
        $description,
        $ipAddress,
        $userAgent
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsPermissionClearCache(): void
{
    unset($_SESSION['cpms_permission_cache']);
}
