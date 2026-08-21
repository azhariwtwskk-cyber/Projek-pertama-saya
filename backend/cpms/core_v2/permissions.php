<?php
declare(strict_types=1);

function cpmsV2DefaultRolePermissions(): array
{
    return [
        'system_owner' => ['*'],
        'managing_director' => ['dashboard.executive', 'reports.view', 'properties.view'],
        'property_admin' => ['dashboard.property', 'complaints.manage', 'work_orders.manage', 'assets.manage', 'reports.view'],
        'manager' => ['dashboard.property', 'complaints.manage', 'work_orders.manage', 'assets.view', 'reports.view'],
        'supervisor' => ['dashboard.supervisor', 'work_orders.manage', 'inspections.manage', 'assets.view'],
        'staff' => ['dashboard.staff', 'work_orders.assigned', 'daily_work.create', 'assets.view'],
        'security' => ['dashboard.security', 'patrols.create', 'incidents.create', 'visitors.manage'],
        'resident' => ['dashboard.resident', 'complaints.own', 'facilities.book'],
        'contractor' => ['dashboard.contractor', 'work_orders.assigned'],
    ];
}

function cpmsV2Can(array $user, string $permission): bool
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $map = cpmsV2DefaultRolePermissions();
    $permissions = $map[$role] ?? [];
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function cpmsV2RequirePermission(string $permission, string $loginUrl = '/login.php'): array
{
    $user = cpmsV2RequireLogin($loginUrl);
    if (!cpmsV2Can($user, $permission)) {
        if (!headers_sent()) {
            http_response_code(403);
        }
        throw new RuntimeException('Permission denied: ' . $permission);
    }
    return $user;
}
