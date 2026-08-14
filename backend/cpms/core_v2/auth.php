<?php
declare(strict_types=1);

/**
 * CPMS Core v2 authentication compatibility layer.
 * Reads existing production sessions without replacing legacy login pages.
 */

function cpmsV2NormalizeRole(string $role): string
{
    $role = strtolower(trim($role));
    $aliases = [
        'admin' => 'property_admin',
        'property manager' => 'manager',
        'property_manager' => 'manager',
        'security' => 'security_guard',
        'guard' => 'security_guard',
        'tenant' => 'resident',
        'owner' => 'resident',
    ];

    return $aliases[$role] ?? ($role !== '' ? $role : 'user');
}

function cpmsV2SessionSources(): array
{
    return [
        [
            'portal' => 'system_owner',
            'id' => ['system_owner_id', 'owner_id'],
            'role' => ['system_owner_role', 'owner_role'],
            'name' => ['system_owner_name', 'owner_name'],
            'property' => ['cpms_current_property_id', 'property_id'],
            'default_role' => 'system_owner',
        ],
        [
            'portal' => 'property_portal',
            'id' => ['property_admin_id'],
            'role' => ['property_admin_role'],
            'name' => ['property_admin_name'],
            'property' => ['property_admin_property_id', 'cpms_current_property_id', 'property_id'],
            'default_role' => 'property_admin',
        ],
        [
            'portal' => 'admin',
            'id' => ['admin_id'],
            'role' => ['admin_role'],
            'name' => ['admin_name', 'full_name'],
            'property' => ['admin_property_id', 'cpms_current_property_id', 'property_id'],
            'default_role' => 'property_admin',
        ],
        [
            'portal' => 'staff',
            'id' => ['staff_id'],
            'role' => ['staff_role'],
            'name' => ['staff_name', 'staff_full_name'],
            'property' => ['staff_property_id', 'cpms_current_property_id', 'property_id'],
            'default_role' => 'staff',
        ],
        [
            'portal' => 'security',
            'id' => ['security_guard_id', 'security_id'],
            'role' => ['security_role'],
            'name' => ['security_name', 'security_guard_name'],
            'property' => ['security_property_id', 'cpms_current_property_id', 'property_id'],
            'default_role' => 'security_guard',
        ],
        [
            'portal' => 'resident',
            'id' => ['resident_id'],
            'role' => ['resident_role'],
            'name' => ['resident_name', 'resident_full_name'],
            'property' => ['resident_property_id', 'cpms_current_property_id', 'property_id'],
            'default_role' => 'resident',
        ],
        [
            'portal' => 'unified',
            'id' => ['cpms_user_id'],
            'role' => ['cpms_user_role'],
            'name' => ['cpms_user_name'],
            'property' => ['cpms_current_property_id', 'property_id'],
            'default_role' => 'user',
        ],
    ];
}

function cpmsV2FirstSessionValue(array $keys, $fallback = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $_SESSION) && $_SESSION[$key] !== '' && $_SESSION[$key] !== null) {
            return $_SESSION[$key];
        }
    }
    return $fallback;
}

function cpmsV2User(): ?array
{
    foreach (cpmsV2SessionSources() as $source) {
        $id = (int) cpmsV2FirstSessionValue($source['id'], 0);
        if ($id <= 0) {
            continue;
        }

        $role = (string) cpmsV2FirstSessionValue($source['role'], $source['default_role']);
        $name = trim((string) cpmsV2FirstSessionValue($source['name'], 'User'));
        $propertyId = (int) cpmsV2FirstSessionValue($source['property'], 0);

        return [
            'id' => $id,
            'role' => cpmsV2NormalizeRole($role),
            'name' => $name !== '' ? $name : 'User',
            'property_id' => $propertyId,
            'portal' => (string) $source['portal'],
            'authenticated' => true,
        ];
    }

    return null;
}

function cpmsV2IsAuthenticated(): bool
{
    return cpmsV2User() !== null;
}

function cpmsV2RequireLogin(string $loginUrl = '/login.php'): array
{
    $user = cpmsV2User();
    if ($user !== null) {
        return $user;
    }

    if (!headers_sent()) {
        header('Location: ' . $loginUrl);
        exit;
    }

    throw new RuntimeException('Login is required.');
}

function cpmsV2HasRole(array $user, array $roles): bool
{
    $currentRole = cpmsV2NormalizeRole((string) ($user['role'] ?? ''));
    foreach ($roles as $role) {
        if ($currentRole === cpmsV2NormalizeRole((string) $role)) {
            return true;
        }
    }
    return false;
}

function cpmsV2RequireRole(array $roles, string $loginUrl = '/login.php'): array
{
    $user = cpmsV2RequireLogin($loginUrl);
    if (!cpmsV2HasRole($user, $roles)) {
        if (!headers_sent()) {
            http_response_code(403);
        }
        throw new RuntimeException('You do not have permission to access this page.');
    }
    return $user;
}

function cpmsV2SyncCanonicalSession(): ?array
{
    $user = cpmsV2User();
    if ($user === null) {
        return null;
    }

    $_SESSION['cpms_user_id'] = (int) $user['id'];
    $_SESSION['cpms_user_role'] = (string) $user['role'];
    $_SESSION['cpms_user_name'] = (string) $user['name'];
    $_SESSION['cpms_current_property_id'] = (int) $user['property_id'];
    $_SESSION['cpms_user_portal'] = (string) $user['portal'];

    return $user;
}

function cpmsV2ClearCanonicalSession(): void
{
    foreach ([
        'cpms_user_id',
        'cpms_user_role',
        'cpms_user_name',
        'cpms_current_property_id',
        'cpms_user_portal',
    ] as $key) {
        unset($_SESSION[$key]);
    }
}
