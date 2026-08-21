<?php
declare(strict_types=1);

function cpmsCurrentUserId(): int
{
    return (int)(
        $_SESSION['user_id']
        ?? $_SESSION['admin_id']
        ?? $_SESSION['staff_id']
        ?? $_SESSION['security_guard_id']
        ?? $_SESSION['resident_id']
        ?? 0
    );
}

function cpmsCurrentUserRole(): string
{
    $role = (string)(
        $_SESSION['user_role']
        ?? $_SESSION['role']
        ?? ''
    );

    if ($role !== '') {
        return $role;
    }

    if (!empty($_SESSION['super_admin_id'])) {
        return 'Super Admin';
    }

    if (!empty($_SESSION['admin_id'])) {
        return 'Property Manager';
    }

    if (!empty($_SESSION['staff_id'])) {
        return 'Staff';
    }

    if (!empty($_SESSION['security_guard_id'])) {
        return 'Security';
    }

    if (!empty($_SESSION['resident_id'])) {
        return 'Resident';
    }

    return '';
}

function cpmsCurrentUserName(): string
{
    return (string)(
        $_SESSION['user_name']
        ?? $_SESSION['admin_name']
        ?? $_SESSION['staff_name']
        ?? $_SESSION['security_name']
        ?? $_SESSION['resident_name']
        ?? 'User'
    );
}

function cpmsIsLoggedIn(): bool
{
    return cpmsCurrentUserId() > 0
        && cpmsCurrentUserRole() !== '';
}

function cpmsRequireLogin(?string $redirect = null): void
{
    if (cpmsIsLoggedIn()) {
        return;
    }

    $redirect ??= cpmsUrl('admin_login.php');

    header('Location: ' . $redirect);
    exit;
}

function cpmsRequireRole(array|string $roles): void
{
    cpmsRequireLogin();

    $roles = is_array($roles) ? $roles : [$roles];

    if (!in_array(cpmsCurrentUserRole(), $roles, true)) {
        http_response_code(403);
        exit('Akses tidak dibenarkan.');
    }
}
