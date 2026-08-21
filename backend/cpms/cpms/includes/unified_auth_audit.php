<?php
declare(strict_types=1);

/*
 * CPMS v3.0.7 - Unified Authentication Audit & Security Engine
 * PHP 7.4 compatible.
 */

function cpmsUnifiedAuditClientIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            continue;
        }

        if (strpos($candidate, ',') !== false) {
            $parts = explode(',', $candidate);
            $candidate = trim((string) $parts[0]);
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return substr($candidate, 0, 45);
        }
    }

    return '';
}

function cpmsUnifiedAuditUserAgent(): string
{
    return substr(
        trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        0,
        500
    );
}

function cpmsUnifiedAuditEvent(
    mysqli $conn,
    string $eventType,
    ?int $systemUserId,
    ?int $propertyId,
    ?string $role,
    string $description,
    array $metadata = []
): void {
    $eventType = trim($eventType);

    if ($eventType === '') {
        return;
    }

    if ($metadata) {
        $encoded = json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (is_string($encoded)) {
            $description .= ' | ' . $encoded;
        }
    }

    $description = substr($description, 0, 1000);
    $ip = cpmsUnifiedAuditClientIp();
    $agent = cpmsUnifiedAuditUserAgent();
    $userType = 'system_user';

    $stmt = $conn->prepare(
        "INSERT INTO cpms_auth_audit_logs
            (
                user_type,
                user_id,
                property_id,
                event_type,
                description,
                ip_address,
                user_agent,
                created_at
            )
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'siissss',
        $userType,
        $systemUserId,
        $propertyId,
        $eventType,
        $description,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsUnifiedAuditRecordSession(
    mysqli $conn,
    int $systemUserId,
    ?int $propertyId,
    string $role
): void {
    $sessionHash = hash('sha256', session_id());
    $ip = cpmsUnifiedAuditClientIp();
    $agent = cpmsUnifiedAuditUserAgent();
    $userType = 'system_user';

    $stmt = $conn->prepare(
        "INSERT INTO cpms_auth_sessions
            (
                user_type,
                user_id,
                property_id,
                role_name,
                session_hash,
                ip_address,
                user_agent,
                last_activity_at,
                created_at
            )
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            user_type = VALUES(user_type),
            user_id = VALUES(user_id),
            property_id = VALUES(property_id),
            role_name = VALUES(role_name),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent),
            last_activity_at = NOW(),
            revoked_at = NULL"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'siissss',
        $userType,
        $systemUserId,
        $propertyId,
        $role,
        $sessionHash,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsUnifiedAuditRevokeCurrentSession(mysqli $conn): void
{
    $sessionHash = hash('sha256', session_id());

    $stmt = $conn->prepare(
        "UPDATE cpms_auth_sessions
         SET revoked_at = NOW(),
             last_activity_at = NOW()
         WHERE session_hash = ?
           AND revoked_at IS NULL"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $sessionHash);
    $stmt->execute();
    $stmt->close();
}

function cpmsUnifiedAuditLogout(mysqli $conn): void
{
    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
    $propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
    $role = trim((string) ($_SESSION['cpms_user_role'] ?? ''));

    if ($userId > 0) {
        cpmsUnifiedAuditEvent(
            $conn,
            'logout',
            $userId,
            $propertyId > 0 ? $propertyId : null,
            $role !== '' ? $role : null,
            'Unified user logged out.',
            ['role' => $role]
        );
    }

    cpmsUnifiedAuditRevokeCurrentSession($conn);
}

function cpmsUnifiedLoginAttemptKey(string $login): string
{
    return hash('sha256', strtolower(trim($login)));
}

function cpmsUnifiedLoginLockState(
    mysqli $conn,
    string $login
): array {
    $loginHash = cpmsUnifiedLoginAttemptKey($login);
    $ip = cpmsUnifiedAuditClientIp();

    $stmt = $conn->prepare(
        "SELECT failure_count, locked_until
         FROM cpms_auth_login_attempts
         WHERE login_hash = ?
           AND ip_address = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return [
            'locked' => false,
            'failure_count' => 0,
            'locked_until' => null,
        ];
    }

    $stmt->bind_param('ss', $loginHash, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return [
            'locked' => false,
            'failure_count' => 0,
            'locked_until' => null,
        ];
    }

    $lockedUntil = trim((string) ($row['locked_until'] ?? ''));
    $lockedTimestamp = $lockedUntil !== ''
        ? strtotime($lockedUntil)
        : false;

    return [
        'locked' => is_int($lockedTimestamp)
            && $lockedTimestamp > time(),
        'failure_count' => (int) ($row['failure_count'] ?? 0),
        'locked_until' => $lockedUntil !== '' ? $lockedUntil : null,
    ];
}

function cpmsUnifiedRegisterLoginFailure(
    mysqli $conn,
    string $login
): array {
    $loginHash = cpmsUnifiedLoginAttemptKey($login);
    $ip = cpmsUnifiedAuditClientIp();

    $stmt = $conn->prepare(
        "SELECT failure_count, last_failed_at
         FROM cpms_auth_login_attempts
         WHERE login_hash = ?
           AND ip_address = ?
         LIMIT 1"
    );

    $count = 0;
    $lastFailedAt = null;

    if ($stmt) {
        $stmt->bind_param('ss', $loginHash, $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (is_array($row)) {
            $count = (int) ($row['failure_count'] ?? 0);
            $lastFailedAt = strtotime(
                (string) ($row['last_failed_at'] ?? '')
            );
        }
    }

    if (!is_int($lastFailedAt) || $lastFailedAt < time() - 900) {
        $count = 0;
    }

    $count++;
    $lockedUntil = $count >= 5
        ? date('Y-m-d H:i:s', time() + 900)
        : null;

    $upsert = $conn->prepare(
        "INSERT INTO cpms_auth_login_attempts
            (
                login_hash,
                ip_address,
                failure_count,
                first_failed_at,
                last_failed_at,
                locked_until,
                updated_at
            )
         VALUES (?, ?, ?, NOW(), NOW(), ?, NOW())
         ON DUPLICATE KEY UPDATE
            failure_count = VALUES(failure_count),
            last_failed_at = NOW(),
            locked_until = VALUES(locked_until),
            updated_at = NOW()"
    );

    if ($upsert) {
        $upsert->bind_param(
            'ssis',
            $loginHash,
            $ip,
            $count,
            $lockedUntil
        );
        $upsert->execute();
        $upsert->close();
    }

    return [
        'locked' => $lockedUntil !== null,
        'failure_count' => $count,
        'locked_until' => $lockedUntil,
    ];
}

function cpmsUnifiedClearLoginFailures(
    mysqli $conn,
    string $login
): void {
    $loginHash = cpmsUnifiedLoginAttemptKey($login);
    $ip = cpmsUnifiedAuditClientIp();

    $stmt = $conn->prepare(
        "DELETE FROM cpms_auth_login_attempts
         WHERE login_hash = ?
           AND ip_address = ?"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $loginHash, $ip);
    $stmt->execute();
    $stmt->close();
}
