<?php
declare(strict_types=1);

function cpmsAuthV2ClientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function cpmsAuthV2UserAgent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

function cpmsAuthV2Audit(
    mysqli $conn,
    string $userType,
    ?int $userId,
    ?int $propertyId,
    string $eventType,
    string $description = ''
): void {
    $stmt = $conn->prepare(
        "INSERT INTO cpms_auth_audit_logs
            (user_type, user_id, property_id, event_type, description,
             ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    if (!$stmt) {
        return;
    }

    $ip = cpmsAuthV2ClientIp();
    $agent = cpmsAuthV2UserAgent();

    $stmt->bind_param(
        'siissss',
        $userType,
        $userId,
        $propertyId,
        $eventType,
        $description,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsAuthV2RecordLogin(
    mysqli $conn,
    int $userId,
    int $propertyId,
    string $role
): void {
    $sessionHash = hash('sha256', session_id());
    $ip = cpmsAuthV2ClientIp();
    $agent = cpmsAuthV2UserAgent();

    $stmt = $conn->prepare(
        "INSERT INTO cpms_auth_sessions
            (user_type, user_id, property_id, role_name, session_hash,
             ip_address, user_agent, last_activity_at, created_at)
         VALUES
            ('property_admin', ?, ?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
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
        'iissss',
        $userId,
        $propertyId,
        $role,
        $sessionHash,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsAuthV2TouchSession(mysqli $conn): void
{
    $sessionHash = hash('sha256', session_id());

    $stmt = $conn->prepare(
        "UPDATE cpms_auth_sessions
         SET last_activity_at = NOW()
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

function cpmsAuthV2RevokeCurrentSession(mysqli $conn): void
{
    $sessionHash = hash('sha256', session_id());

    $stmt = $conn->prepare(
        "UPDATE cpms_auth_sessions
         SET revoked_at = NOW()
         WHERE session_hash = ?"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $sessionHash);
    $stmt->execute();
    $stmt->close();
}

function cpmsAuthV2PasswordStrong(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

function cpmsAuthV2PasswordRecentlyUsed(
    mysqli $conn,
    string $userType,
    int $userId,
    string $password,
    int $historyLimit = 5
): bool {
    $stmt = $conn->prepare(
        "SELECT password_hash
         FROM cpms_password_history
         WHERE user_type = ?
           AND user_id = ?
         ORDER BY id DESC
         LIMIT ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sii', $userType, $userId, $historyLimit);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (password_verify($password, (string) $row['password_hash'])) {
            $stmt->close();
            return true;
        }
    }

    $stmt->close();
    return false;
}

function cpmsAuthV2StorePasswordHistory(
    mysqli $conn,
    string $userType,
    int $userId,
    string $passwordHash
): void {
    $stmt = $conn->prepare(
        "INSERT INTO cpms_password_history
            (user_type, user_id, password_hash, created_at)
         VALUES (?, ?, ?, NOW())"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('sis', $userType, $userId, $passwordHash);
    $stmt->execute();
    $stmt->close();
}
