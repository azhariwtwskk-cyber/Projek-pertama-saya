<?php
declare(strict_types=1);

/**
 * CPMS Property Portal Access Control
 *
 * Roles:
 * - property_admin: full operational access for one property
 * - manager: operations and reporting for one property
 * - clerk: complaint and resident administration for one property
 */

function cpmsPropertyRole(): string
{
    return (string) (
        $GLOBALS['currentPropertyRole']
        ?? $_SESSION['property_admin_role']
        ?? ''
    );
}

function cpmsPropertyPermissionMatrix(): array
{
    return [
        'dashboard.view' => [
            'property_admin',
            'manager',
            'clerk',
        ],
        'complaints.view' => [
            'property_admin',
            'manager',
            'clerk',
        ],
        'complaints.update' => [
            'property_admin',
            'manager',
            'clerk',
        ],
        'work_orders.view' => [
            'property_admin',
            'manager',
            'clerk',
        ],
        'work_orders.create' => [
            'property_admin',
            'manager',
        ],
        'work_orders.update' => [
            'property_admin',
            'manager',
        ],
        'residents.view' => [
            'property_admin',
            'manager',
            'clerk',
        ],
        'residents.manage' => [
            'property_admin',
            'clerk',
        ],
        'staff.view' => [
            'property_admin',
            'manager',
        ],
        'staff.manage' => [
            'property_admin',
        ],
        'assets.view' => [
            'property_admin',
            'manager',
        ],
        'assets.manage' => [
            'property_admin',
        ],
        'reports.view' => [
            'property_admin',
            'manager',
        ],
        'settings.manage' => [
            'property_admin',
        ],
    ];
}

function cpmsPropertyCan(string $permission): bool
{
    $matrix = cpmsPropertyPermissionMatrix();
    $role = cpmsPropertyRole();

    return isset($matrix[$permission])
        && in_array($role, $matrix[$permission], true);
}

function cpmsPropertyRequire(string $permission): void
{
    if (cpmsPropertyCan($permission)) {
        return;
    }

    $GLOBALS['cpmsDeniedPermission'] = $permission;
    http_response_code(403);

    $accessDeniedFile = __DIR__
        . '/../property_portal/access_denied.php';

    if (is_file($accessDeniedFile)) {
        require $accessDeniedFile;
        exit;
    }

    exit('Access denied.');
}

function cpmsPropertyRoleLabel(?string $role = null): string
{
    $role = $role ?? cpmsPropertyRole();

    switch ($role) {
        case 'property_admin':
            return 'Pentadbir Property';
        case 'manager':
            return 'Pengurus';
        case 'clerk':
            return 'Kerani';
        default:
            return 'Pengguna Property';
    }
}
