<?php
declare(strict_types=1);

/*
 * Stage 1 auth (mobile/docs/BACKEND_INTEGRATION_AUDIT.md): exchanges a
 * still-valid refresh token for a brand new access token + a brand new
 * refresh token (rotation). The old refresh token stops working the
 * moment this succeeds, because its hash is overwritten in the same row
 * — a caller that tries to reuse an already-rotated refresh token simply
 * finds no matching row and gets REFRESH_TOKEN_EXPIRED, which is exactly
 * the desired "reuse rejection" behaviour without needing a separate
 * token-family/blacklist table for a Stage 1 scope.
 *
 * Deliberately does NOT go through cpmsApiAuth()/the bearer access
 * token — the whole point of this endpoint is to work when the access
 * token has already expired. property_id/role/user identity are always
 * re-resolved server-side from the refresh token's own database row and
 * re-verified against user_roles, exactly like login.php and
 * cpmsApiAuth() do; nothing here is ever taken from client input.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');

$db = cpmsApiDatabase();
if (!cpmsApiTablesReady($db)) {
    cpmsApiError(
        'API_NOT_INSTALLED',
        'Jalankan migration CPMS Workforce v4.1.0 terlebih dahulu.',
        503
    );
}

$input = cpmsApiInput();
$refreshToken = trim((string) ($input['refresh_token'] ?? ''));
if ($refreshToken === '' || !preg_match('/^[A-Za-z0-9_-]{40,160}$/', $refreshToken)) {
    cpmsApiError('INVALID_REFRESH_TOKEN', 'Refresh token tidak sah.', 401);
}

$refreshHash = hash('sha256', $refreshToken);

$db->begin_transaction();
try {
    $stmt = $db->prepare(
        "SELECT t.id AS token_id,t.system_user_id,t.property_id,t.role_name,
                su.status AS user_status,p.is_active AS property_active
         FROM cpms_api_tokens t
         JOIN system_users su ON su.id=t.system_user_id
         JOIN cpms_properties p ON p.id=t.property_id
         WHERE t.refresh_token_hash=? AND t.revoked_at IS NULL
           AND t.refresh_expires_at IS NOT NULL AND t.refresh_expires_at>NOW()
         LIMIT 1 FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare refresh token lookup.');
    }
    $stmt->bind_param('s', $refreshHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)
        || (string) $row['user_status'] !== 'active'
        || (int) $row['property_active'] !== 1) {
        $db->rollback();
        cpmsApiAudit($db, null, 'workforce.token_refresh', 'failed');
        cpmsApiError('REFRESH_TOKEN_EXPIRED', 'Sesi telah tamat. Sila log masuk semula.', 401);
    }

    $role = strtolower((string) $row['role_name']);
    if (!in_array($role, ['staff', 'security'], true)) {
        $db->rollback();
        cpmsApiError('ROLE_NOT_ALLOWED', 'Peranan ini tidak dibenarkan.', 403);
    }

    $userId = (int) $row['system_user_id'];
    $propertyId = (int) $row['property_id'];
    $tokenId = (int) $row['token_id'];

    // Same re-verification cpmsApiAuth() does on every normal request:
    // if a Property Admin revoked this staff member's access between
    // login and now, the refresh must fail too, not silently renew it.
    $roleStmt = $db->prepare(
        "SELECT 1
         FROM user_roles ur
         JOIN roles r ON r.id=ur.role_id
         WHERE ur.system_user_id=? AND ur.property_id=?
           AND ur.status='active' AND r.status='active' AND r.role_code=?
           AND (ur.expires_at IS NULL OR ur.expires_at>NOW())
         LIMIT 1"
    );
    if (!$roleStmt) {
        throw new RuntimeException('Unable to verify API role assignment.');
    }
    $roleStmt->bind_param('iis', $userId, $propertyId, $role);
    $roleStmt->execute();
    $roleValid = $roleStmt->get_result()->fetch_row();
    $roleStmt->close();
    if (!$roleValid) {
        $db->rollback();
        cpmsApiError('ACCESS_REVOKED', 'Akses aplikasi telah dibatalkan.', 403);
    }

    $newAccessToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $newAccessHash = hash('sha256', $newAccessToken);
    $newRefreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $newRefreshHash = hash('sha256', $newRefreshToken);
    $ip = cpmsApiClientIp();
    $agent = cpmsApiUserAgent();

    $update = $db->prepare(
        "UPDATE cpms_api_tokens
         SET token_hash=?,refresh_token_hash=?,
             expires_at=DATE_ADD(NOW(),INTERVAL " . CPMS_API_ACCESS_TOKEN_TTL_SECONDS . " SECOND),
             refresh_expires_at=DATE_ADD(NOW(),INTERVAL " . CPMS_API_REFRESH_TOKEN_TTL_DAYS . " DAY),
             last_used_at=NOW(),ip_address=?,user_agent=?
         WHERE id=?"
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare token rotation.');
    }
    $update->bind_param('ssssi', $newAccessHash, $newRefreshHash, $ip, $agent, $tokenId);
    $update->execute();
    $update->close();

    $db->commit();

    $identity = [
        'token_id' => $tokenId,
        'system_user_id' => $userId,
        'property_id' => $propertyId,
        'role' => $role,
    ];
    cpmsApiAudit($db, $identity, 'workforce.token_refresh', 'success', 'api_token', $tokenId);

    cpmsApiRespond([
        'access_token' => $newAccessToken,
        'expires_in' => CPMS_API_ACCESS_TOKEN_TTL_SECONDS,
        'refresh_token' => $newRefreshToken,
        'refresh_expires_in' => CPMS_API_REFRESH_TOKEN_TTL_DAYS * 86400,
    ]);
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}
