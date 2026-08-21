<?php
declare(strict_types=1);

function cpmsRolePermissions(): array
{
    return [
        'Super Admin' => ['*'],

        'Property Manager' => [
            'dashboard.view',
            'complaint.view',
            'complaint.manage',
            'work_order.view',
            'work_order.manage',
            'asset.view',
            'asset.manage',
            'maintenance.view',
            'maintenance.manage',
            'patrol.view',
            'resident.view',
            'resident.manage',
            'staff.view',
            'staff.manage',
            'notification.view',
            'announcement.manage',
            'report.view',
        ],

        'Staff' => [
            'dashboard.view',
            'complaint.view',
            'work_order.view',
            'work_order.update',
            'asset.view',
            'maintenance.view',
            'daily_work.create',
            'daily_work.view',
            'notification.view',
        ],

        'Security' => [
            'dashboard.view',
            'patrol.create',
            'patrol.view_own',
            'notification.view',
        ],

        'Resident' => [
            'dashboard.view',
            'complaint.create',
            'complaint.view_own',
            'announcement.view',
            'notification.view',
        ],
    ];
}

function can(string $permission): bool
{
    $role = cpmsCurrentUserRole();
    $permissions = cpmsRolePermissions()[$role] ?? [];

    return in_array('*', $permissions, true)
        || in_array($permission, $permissions, true);
}

function cpmsRequirePermission(string $permission): void
{
    cpmsRequireLogin();

    if (!can($permission)) {
        http_response_code(403);
        exit('Anda tidak mempunyai kebenaran untuk tindakan ini.');
    }
}
