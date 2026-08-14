<?php
declare(strict_types=1);

/*
 * CPMS v3.0.4 - Unified Authentication Compatibility Engine
 *
 * This engine reads system_users + user_roles while preserving the
 * legacy session identifiers required by existing portals.
 */

function cpmsUnifiedAuthFindUser(
    mysqli $conn,
    string $login
): ?array {
    $login = trim($login);

    if ($login === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            property_id,
            username,
            email,
            password_hash,
            full_name,
            status,
            must_change_password,
            source_table,
            source_id
         FROM system_users
         WHERE username = ?
            OR email = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare unified user lookup.'
        );
    }

    $stmt->bind_param('ss', $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($user) ? $user : null;
}

function cpmsUnifiedAuthRoles(
    mysqli $conn,
    int $systemUserId
): array {
    $stmt = $conn->prepare(
        "SELECT
            roles.role_code,
            assignments.property_id
         FROM user_roles assignments
         INNER JOIN roles
            ON roles.id = assignments.role_id
         WHERE assignments.system_user_id = ?
           AND assignments.status = 'active'
           AND roles.status = 'active'
           AND (
                assignments.expires_at IS NULL
                OR assignments.expires_at > NOW()
           )
         ORDER BY
            CASE roles.role_code
                WHEN 'system_owner' THEN 1
                WHEN 'property_admin' THEN 2
                WHEN 'manager' THEN 3
                WHEN 'clerk' THEN 4
                WHEN 'staff' THEN 5
                WHEN 'security' THEN 6
                WHEN 'resident' THEN 7
                WHEN 'contractor' THEN 8
                WHEN 'vendor' THEN 9
                ELSE 99
            END"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare unified role lookup.'
        );
    }

    $stmt->bind_param('i', $systemUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $roles = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roles[] = [
                'role_code' => (string) $row['role_code'],
                'property_id' => $row['property_id'] === null
                    ? null
                    : (int) $row['property_id'],
            ];
        }
    }

    $stmt->close();

    return $roles;
}

function cpmsUnifiedAuthVerifyPassword(
    string $plainPassword,
    string $storedPassword
): array {
    if ($plainPassword === '' || $storedPassword === '') {
        return [
            'valid' => false,
            'legacy_plaintext' => false,
            'needs_rehash' => false,
        ];
    }

    if (password_verify($plainPassword, $storedPassword)) {
        return [
            'valid' => true,
            'legacy_plaintext' => false,
            'needs_rehash' => password_needs_rehash(
                $storedPassword,
                PASSWORD_DEFAULT
            ),
        ];
    }

    $passwordInfo = password_get_info($storedPassword);
    $isRecognizedHash = (int) ($passwordInfo['algo'] ?? 0) !== 0;
    $legacyValid = !$isRecognizedHash
        && hash_equals($storedPassword, $plainPassword);

    return [
        'valid' => $legacyValid,
        'legacy_plaintext' => $legacyValid,
        'needs_rehash' => $legacyValid,
    ];
}

function cpmsUnifiedAuthSelectContext(
    array $user,
    array $roles,
    ?int $requestedPropertyId = null
): ?array {
    foreach ($roles as $assignment) {
        $role = (string) ($assignment['role_code'] ?? '');
        $propertyId = $assignment['property_id'] ?? null;

        if ($role === 'system_owner') {
            return [
                'role' => $role,
                'property_id' => null,
                'portal' => 'system_owner',
                'redirect' => 'system_owner/dashboard.php',
            ];
        }

        if (
            $requestedPropertyId !== null
            && (int) $propertyId !== $requestedPropertyId
        ) {
            continue;
        }

        if (in_array($role, ['property_admin', 'manager', 'clerk'], true)) {
            return [
                'role' => $role,
                'property_id' => (int) $propertyId,
                'portal' => 'property_portal',
                'redirect' => 'property_portal/dashboard.php',
            ];
        }

        if ($role === 'staff') {
            return [
                'role' => $role,
                'property_id' => (int) $propertyId,
                'portal' => 'staff',
                'redirect' => '../staff_dashboard.php',
            ];
        }

        if ($role === 'security') {
            return [
                'role' => $role,
                'property_id' => (int) $propertyId,
                'portal' => 'security',
                'redirect' => '../security_dashboard.php',
            ];
        }
    }

    return null;
}

function cpmsUnifiedAuthenticate(
    mysqli $conn,
    string $login,
    string $password,
    ?int $requestedPropertyId = null
): array {
    $user = cpmsUnifiedAuthFindUser($conn, $login);

    if (!$user || (string) $user['status'] !== 'active') {
        return [
            'valid' => false,
            'code' => 'invalid_account',
        ];
    }

    $passwordResult = cpmsUnifiedAuthVerifyPassword(
        $password,
        (string) $user['password_hash']
    );

    if (!$passwordResult['valid']) {
        return [
            'valid' => false,
            'code' => 'invalid_credentials',
        ];
    }

    $roles = cpmsUnifiedAuthRoles($conn, (int) $user['id']);
    $context = cpmsUnifiedAuthSelectContext(
        $user,
        $roles,
        $requestedPropertyId
    );

    if (!$context) {
        return [
            'valid' => false,
            'code' => 'no_portal_context',
        ];
    }

    unset($user['password_hash']);

    return [
        'valid' => true,
        'code' => 'authenticated',
        'user' => $user,
        'roles' => $roles,
        'context' => $context,
        'password' => $passwordResult,
    ];
}

function cpmsUnifiedLegacySessionMap(
    array $authentication,
    ?mysqli $conn = null
): array
{
    if (empty($authentication['valid'])) {
        return [];
    }

    $user = $authentication['user'] ?? [];
    $context = $authentication['context'] ?? [];
    $role = (string) ($context['role'] ?? '');
    $propertyId = (int) ($context['property_id'] ?? 0);
    $sourceTable = (string) ($user['source_table'] ?? '');
    $sourceId = (int) ($user['source_id'] ?? 0);
    $systemUserId = (int) ($user['id'] ?? 0);
    $fullName = (string) ($user['full_name'] ?? '');

    $session = [
        'cpms_user_id' => $systemUserId,
        'cpms_user_role' => $role,
        'cpms_property_id' => $propertyId > 0 ? $propertyId : null,
        'cpms_authenticated_at' => time(),
    ];

    if ($role === 'system_owner') {
        $session['system_owner_id'] = $systemUserId;
        $session['system_owner_name'] = $fullName;
        $session['system_owner_role'] = $role;
        $session['system_owner_last_activity'] = time();

        return $session;
    }

    if (
        in_array($role, ['property_admin', 'manager', 'clerk'], true)
        && $sourceTable === 'property_admins'
        && $sourceId > 0
    ) {
        $session['property_admin_id'] = $sourceId;
        $session['property_admin_property_id'] = $propertyId;
        $session['property_admin_role'] = $role;
        $session['property_admin_name'] = $fullName;
        $session['property_admin_last_activity'] = time();

        return $session;
    }

    if (
        $role === 'staff'
        && $sourceTable === 'staff'
        && $sourceId > 0
    ) {
        $legacyRole = 'staff';

        if ($conn instanceof mysqli) {
            $stmt = $conn->prepare(
                'SELECT role
                 FROM staff
                 WHERE id = ?
                 LIMIT 1'
            );

            if ($stmt) {
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    is_array($row)
                    && trim((string) ($row['role'] ?? '')) !== ''
                ) {
                    $legacyRole = (string) $row['role'];
                }
            }
        }

        $session['staff_id'] = $sourceId;
        $session['staff_name'] = $fullName;
        $session['staff_role'] = $legacyRole;
        $session['staff_property_id'] = $propertyId;
        $session['staff_last_activity'] = time();
        $session['staff_pwa_login_at'] = time();

        return $session;
    }

    if (
        $role === 'security'
        && $sourceTable === 'security_guards'
        && $sourceId > 0
    ) {
        $guardType = 'Security Guard';

        if ($conn instanceof mysqli) {
            $stmt = $conn->prepare(
                'SELECT guard_type
                 FROM security_guards
                 WHERE id = ?
                 LIMIT 1'
            );

            if ($stmt) {
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    is_array($row)
                    && trim((string) ($row['guard_type'] ?? '')) !== ''
                ) {
                    $guardType = (string) $row['guard_type'];
                }
            }
        }

        $session['security_guard_id'] = $sourceId;
        $session['security_guard_name'] = $fullName;
        $session['security_guard_type'] = $guardType;
        $session['security_guard_property_id'] = $propertyId;
        $session['security_guard_last_activity'] = time();

        return $session;
    }

    return $session;
}
