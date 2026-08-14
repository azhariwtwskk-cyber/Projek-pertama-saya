<?php
declare(strict_types=1);

/*
 * CPMS v3.1.0 - Dynamic Menu
 * Requires permission_engine.php.
 */

function cpmsDynamicMenuRegistry(string $portal): array
{
    $menus = [
        'system_owner' => [
            ['dashboard.view', 'Dashboard', 'dashboard.php'],
            ['properties.manage', 'Properties', 'properties.php'],
            ['users.view', 'Users', 'property_admins.php'],
            ['permissions.manage', 'Permissions', 'permission_health.php'],
            ['audit.view', 'Audit Trail', 'auth_audit.php'],
            ['settings.manage', 'Global Settings', 'settings.php'],
        ],
        'property_portal' => [
            ['dashboard.view', 'Dashboard', 'dashboard.php'],
            ['complaints.view', 'Complaints', 'complaints.php'],
            ['work_orders.view', 'Work Orders', 'work_orders.php'],
            ['inspection.view', 'Inspections', 'inspections.php'],
            ['assets.view', 'Assets', 'assets.php'],
            ['residents.view', 'Residents', 'residents.php'],
            ['staff.view', 'Staff', 'staff.php'],
            ['security.view', 'Security', 'security.php'],
            ['visitor.view', 'Visitors', 'visitors.php'],
            ['facilities.view', 'Facilities', 'facilities.php'],
            ['notices.view', 'Notices', 'notices.php'],
            ['reports.view', 'Reports', 'reports.php'],
            ['settings.manage', 'Settings', 'settings.php'],
        ],
        'staff' => [
            ['dashboard.view', 'Dashboard', 'staff_dashboard.php'],
            ['work_orders.view', 'My Work Orders', 'staff_work_orders.php'],
            ['daily_work.create', 'Daily Work', 'staff_work_form.php'],
            ['inspection.view', 'Inspections', 'staff_inspections.php'],
            ['profile.view', 'Profile', 'staff_profile.php'],
        ],
        'security' => [
            ['dashboard.view', 'Dashboard', 'security_dashboard.php'],
            ['security.patrol', 'Patrol', 'security_patrol_form.php'],
            ['security.view', 'Patrol History', 'security_patrol_history.php'],
            ['visitor.manage', 'Visitors', 'security_visitors.php'],
            ['profile.view', 'Profile', 'security_profile.php'],
        ],
        'resident' => [
            ['dashboard.view', 'Dashboard', 'dashboard.php'],
            ['complaints.create', 'Complaint', 'complaint_form.php'],
            ['facilities.book', 'Facility Booking', 'facility_booking.php'],
            ['notices.view', 'Announcements', 'announcements.php'],
            ['profile.view', 'Profile', 'profile.php'],
        ],
    ];

    return $menus[$portal] ?? [];
}

function cpmsDynamicMenuItems(
    mysqli $db,
    string $portal,
    bool $existingFilesOnly = false,
    ?string $baseDirectory = null
): array {
    $items = [];

    foreach (cpmsDynamicMenuRegistry($portal) as $item) {
        $permission = (string) ($item[0] ?? '');
        $label = (string) ($item[1] ?? '');
        $url = (string) ($item[2] ?? '');

        if (!cpmsCan($permission, $db)) {
            continue;
        }

        if (
            $existingFilesOnly
            && $baseDirectory !== null
            && !is_file(rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR . $url)
        ) {
            continue;
        }

        $items[] = [
            'permission' => $permission,
            'label' => $label,
            'url' => $url,
        ];
    }

    return $items;
}

function cpmsRenderDynamicMenu(
    mysqli $db,
    string $portal,
    string $activeUrl = '',
    bool $existingFilesOnly = false,
    ?string $baseDirectory = null
): void {
    $items = cpmsDynamicMenuItems(
        $db,
        $portal,
        $existingFilesOnly,
        $baseDirectory
    );

    echo '<nav class="cpms-dynamic-menu" aria-label="CPMS menu">';
    foreach ($items as $item) {
        $url = (string) $item['url'];
        $class = $activeUrl === $url ? ' class="active"' : '';
        echo '<a' . $class . ' href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8')
            . '</a>';
    }
    echo '</nav>';
}
