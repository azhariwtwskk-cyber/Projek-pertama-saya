<?php
require_once __DIR__ . '/../../includes/permission_engine.php';

function cpmsSidebarIcon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>',
        'complaints' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 1 21h22L12 2Zm1 15h-2v-2h2v2Zm0-4h-2V9h2v4Z"/></svg>',
        'work_orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a5 5 0 0 0-6.4 6.4L2 19l3 3 6.3-6.3a5 5 0 0 0 6.4-6.4l-3.2 3.2-2-2 3.2-3.2Z"/></svg>',
        'inspection' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 2h6l1 2h4v18H4V4h4l1-2Zm1.2 2-.5 1H6v15h12V6h-3.7l-.5-1h-3.6ZM8 9h8v2H8V9Zm0 4h8v2H8v-2Zm0 4h5v2H8v-2Z"/></svg>',
        'compliance' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM8 12h3v3H8v-3Zm5 0h3v3h-3v-3Z"/></svg>',
        'residents' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 11h3v9h5v-6h4v6h5v-9h3L12 3Z"/></svg>',
        'staff' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3 1.3 3 3 3ZM8 11c1.7 0 3-1.3 3-3S9.7 5 8 5 5 6.3 5 8s1.3 3 3 3Zm8 2c-2 0-6 1-6 3v3h12v-3c0-2-4-3-6-3ZM8 13c-2.3 0-6 1.1-6 3v3h6v-3c0-.8.3-1.6.9-2.3-.3-.4-.6-.7-.9-.7Z"/></svg>',
        'pm' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10h-2a8 8 0 1 1-2.3-5.7L15 9h7V2l-2.9 2.9A9.9 9.9 0 0 0 12 2Zm-1 5h2v6h-2V7Zm0 8h2v2h-2v-2Z"/></svg>',
        'assets' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v6H4V4Zm0 10h16v6H4v-6Zm3-8v2h2V6H7Zm0 10v2h2v-2H7Z"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16v2H4v-2Zm2-3h3V9H6v8Zm5 0h3V3h-3v14Zm5 0h3v-6h-3v6Z"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12c2.8 0 5-2.2 5-5s-2.2-5-5-5-5 2.2-5 5 2.2 5 5 5Zm0 2c-3.3 0-8 1.7-8 5v3h16v-3c0-3.3-4.7-5-8-5Z"/></svg>',
        'branding' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20h1.5a2.5 2.5 0 0 0 0-5H12a1 1 0 0 1 0-2h4a6 6 0 0 0 0-12h-4Zm-4 8a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4-3a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Zm-7 7a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z"/></svg>',
        'modules' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h8v8H3V3Zm10 0h8v8h-8V3ZM3 13h8v8H3v-8Zm10 0h8v8h-8v-8Z"/></svg>',
        'notifications' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2Zm7-6V11a7 7 0 0 0-5-6.7V3a2 2 0 0 0-4 0v1.3A7 7 0 0 0 5 11v5l-2 2h18l-2-2Z"/></svg>',
        'attendance' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10Zm-7 2h2v4h3v2h-5v-6Z"/></svg>',
        'facility' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V7l9-5 9 5v14h-7v-6h-4v6H3Zm2-2h3v-6h8v6h3V8.2l-7-3.9-7 3.9V19Z"/></svg>',
        'announcements' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11v2h3l4 4V7l-4 4H3Zm9-5v12l7 3V3l-7 3Zm8 2v8h2V8h-2Z"/></svg>',
        'visitor' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V21h14v-2.5C19 16 16 14 12 14Zm8-8h2v5h-2V6Zm-3 2h5v2h-5V8Z"/></svg>',
        'access' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a5 5 0 0 0-3 9v2H6v9h12v-9h-3v-2a5 5 0 0 0-3-9Zm0 2a3 3 0 0 1 3 3v6H9V7a3 3 0 0 1 3-3Zm-4 11h8v5H8v-5Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}
?>
<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <?php if (!empty($propertyPortalUser['logo_path'])): ?>
                <img
                                data-branding-image="1"
                    src="<?php echo propertyPortalEscape(
                        cpmsBrandingAssetUrl(
                            $propertyPortalUser['logo_path']
                        )
                    ); ?>"
                    alt="<?php echo propertyPortalEscape(
                        $currentPropertyName
                    ); ?>"
                >
            <?php else: ?>
                <span>CP</span>
            <?php endif; ?>
        </div>

        <div class="sidebar-brand-text">
            <strong><?php echo propertyPortalEscape(
                $currentPropertyName
            ); ?></strong>
            <small><?php echo propertyPortalEscape(
                $currentPropertyCode
            ); ?></small>
        </div>
    </div>

    <nav class="sidebar-menu"
         aria-label="Property Portal navigation">
        <span class="sidebar-section-label">Operations</span>

        <?php if (cpmsCan('dashboard.view', $conn)): ?>
            <a href="dashboard.php"
               class="sidebar-link <?php echo $activeMenu === 'dashboard' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('dashboard'); ?></span>
                <span>Dashboard</span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('complaints')
            && cpmsCan('complaints.view', $conn)
        ): ?>
            <a href="complaints.php"
               class="sidebar-link <?php echo $activeMenu === 'complaints' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('complaints'); ?></span>
                <span>Complaints</span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('work_orders')
            && cpmsCan('work_orders.view', $conn)
        ): ?>
            <a href="work_orders.php"
               class="sidebar-link <?php echo $activeMenu === 'work_orders' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('work_orders'); ?></span>
                <span>Work Orders</span>
            </a>
            <a href="daily_work_review.php"
               class="sidebar-link <?php echo $activeMenu === 'daily_work' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('staff'); ?></span>
                <span>Daily Work Review</span>
            </a>
        <?php endif; ?>

        <?php if (cpmsCan('inspection.view', $conn)): ?>
            <a href="inspections.php"
               class="sidebar-link <?php echo $activeMenu === 'inspection' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('inspection'); ?></span>
                <span>Inspections</span>
            </a>
        <?php endif; ?>

        <?php if (cpmsCan('inspection.hq_report.manage', $conn)): ?>
            <a href="hq_inspection_inbox.php"
               class="sidebar-link <?php echo $activeMenu === 'hq_inspection' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('inspection'); ?></span>
                <span>HQ Inspection Inbox</span>
            </a>
        <?php endif; ?>

        <?php if (cpmsCan('compliance.view', $conn)): ?>
            <a href="compliance.php"
               class="sidebar-link <?php echo $activeMenu === 'compliance' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('compliance'); ?></span>
                <span>Compliance</span>
            </a>
        <?php endif; ?>

        <?php if (cpmsCan('notifications.view', $conn)): ?>
            <?php
            require_once __DIR__ . '/../../includes/notification_service.php';
            $notificationPropertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
            $notificationUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
            cpmsNotificationSyncCorrectiveActions($conn, $notificationPropertyId);
            cpmsNotificationSyncPreventiveMaintenance(
                $conn,
                $notificationPropertyId
            );
            $notificationUnread = cpmsNotificationUnreadCount(
                $conn,
                $notificationPropertyId,
                $notificationUserId
            );
            ?>
            <a href="notifications.php"
               class="sidebar-link <?php echo $activeMenu === 'notifications' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('notifications'); ?></span>
                <span>Notifications<?php echo $notificationUnread > 0 ? ' (' . $notificationUnread . ')' : ''; ?></span>
            </a>
        <?php endif; ?>

        <?php if (in_array(
            (string) $currentPropertyRole,
            ['property_admin', 'manager'],
            true
        )): ?>
            <a href="../attendance_dashboard.php?property_id=<?php echo (int) $currentPropertyId; ?>&amp;month=<?php echo date('Y-m'); ?>"
               class="sidebar-link <?php echo $activeMenu === 'attendance' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('attendance'); ?></span>
                <span>Staff Attendance</span>
            </a>
        <?php endif; ?>

        <?php if (cpmsCan('facilities.manage', $conn)): ?>
            <a href="facilities.php"
               class="sidebar-link <?php echo $activeMenu === 'facilities' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('facility'); ?></span>
                <span>Facility Management</span>
            </a>
        <?php endif; ?>

        <?php if (cpmsCan('resident.announcement.manage', $conn)): ?>
            <a href="announcements_manage.php"
               class="sidebar-link <?php echo $activeMenu === 'announcements' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('announcements'); ?></span>
                <span>Announcements</span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('visitor_management')
            && cpmsCan('visitor.monitor', $conn)
        ): ?>
            <?php
            $visitorInsideCount = 0;
            $visitorTable = false;
            try {
                $visitorTable = $conn->query(
                    "SHOW TABLES LIKE 'cpms_visitor_passes'"
                );
            } catch (Throwable $ignored) {
                $visitorTable = false;
            }
            if (
                $visitorTable instanceof mysqli_result
                && $visitorTable->num_rows > 0
            ) {
                $visitorStmt = $conn->prepare(
                    "SELECT COUNT(*) AS total
                     FROM cpms_visitor_passes
                     WHERE property_id=? AND visitor_status='Checked In'"
                );
                if ($visitorStmt) {
                    $visitorStmt->bind_param('i', $currentPropertyId);
                    if ($visitorStmt->execute()) {
                        $visitorRow = $visitorStmt
                            ->get_result()
                            ->fetch_assoc();
                        $visitorInsideCount = (int) (
                            $visitorRow['total'] ?? 0
                        );
                    }
                    $visitorStmt->close();
                }
            }
            ?>
            <a href="visitors.php"
               class="sidebar-link <?php echo $activeMenu === 'visitors' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('visitor'); ?></span>
                <span>Visitor Management<?php echo $visitorInsideCount > 0 ? ' (' . $visitorInsideCount . ')' : ''; ?></span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('residents')
            && cpmsCan('resident.request.manage', $conn)
        ): ?>
            <a href="resident_requests.php"
               class="sidebar-link <?php echo $activeMenu === 'resident_requests' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('residents'); ?></span>
                <span>Resident Requests</span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('residents')
            && cpmsCan('resident.registration.manage', $conn)
        ): ?>
            <?php
            $pendingRegistrationCount = 0;
            $registrationTable = false;
            try {
                $registrationTable = $conn->query(
                    "SHOW TABLES LIKE 'cpms_resident_registrations'"
                );
            } catch (Throwable $ignored) {
                $registrationTable = false;
            }
            if (
                $registrationTable instanceof mysqli_result
                && $registrationTable->num_rows > 0
            ) {
                $registrationStmt = $conn->prepare(
                    "SELECT COUNT(*) AS total
                     FROM cpms_resident_registrations
                     WHERE property_id=? AND status='Pending'"
                );
                if ($registrationStmt) {
                    $registrationStmt->bind_param(
                        'i',
                        $currentPropertyId
                    );
                    if ($registrationStmt->execute()) {
                        $registrationRow = $registrationStmt
                            ->get_result()
                            ->fetch_assoc();
                        $pendingRegistrationCount = (int) (
                            $registrationRow['total'] ?? 0
                        );
                    }
                    $registrationStmt->close();
                }
            }
            ?>
            <a href="resident_registrations.php?status=Pending"
               class="sidebar-link <?php echo $activeMenu === 'resident_registrations' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('residents'); ?></span>
                <span>Resident Registrations<?php echo $pendingRegistrationCount > 0 ? ' (' . $pendingRegistrationCount . ')' : ''; ?></span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('assets')
            && cpmsCan('assets.view', $conn)
        ): ?>
            <a href="assets.php"
               class="sidebar-link <?php echo $activeMenu === 'assets' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('assets'); ?></span>
                <span>Assets</span>
            </a>
            <a href="preventive_maintenance.php"
               class="sidebar-link <?php echo $activeMenu === 'preventive_maintenance' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('pm'); ?></span>
                <span>Preventive Maintenance</span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsModuleEnabled('reports')
            && (
                cpmsCan('reports.view', $conn)
                || cpmsPropertyCan('reports.view')
            )
        ): ?>
            <a href="management_report.php"
               class="sidebar-link <?php echo $activeMenu === 'reports' ? 'active' : ''; ?>">
                <span class="menu-icon"><?php echo cpmsSidebarIcon('reports'); ?></span>
                <span>Management Reports</span>
            </a>
        <?php endif; ?>

        <?php if (
            cpmsCan('settings.manage', $conn)
            || cpmsCan('clerk.modules.assign', $conn)
        ): ?>
            <span class="sidebar-section-label">Administration</span>

            <?php if (cpmsCan('clerk.modules.assign', $conn)): ?>
                <a href="user_module_access.php"
                   class="sidebar-link <?php echo $activeMenu === 'clerk_access' ? 'active' : ''; ?>">
                    <span class="menu-icon"><?php echo cpmsSidebarIcon('access'); ?></span>
                    <span>Clerk Module Access</span>
                </a>
            <?php endif; ?>

            <?php if (cpmsCan('settings.manage', $conn)): ?>
                <a href="users.php"
                   class="sidebar-link <?php echo $activeMenu === 'users' ? 'active' : ''; ?>">
                    <span class="menu-icon"><?php echo cpmsSidebarIcon('users'); ?></span>
                    <span>Property Users</span>
                </a>

                <a href="security_guards.php"
                   class="sidebar-link <?php echo $activeMenu === 'security_guards' ? 'active' : ''; ?>">
                    <span class="menu-icon"><?php echo cpmsSidebarIcon('staff'); ?></span>
                    <span>Security Guards</span>
                </a>

                <a href="branding_settings.php"
                   class="sidebar-link <?php echo $activeMenu === 'branding' ? 'active' : ''; ?>">
                    <span class="menu-icon"><?php echo cpmsSidebarIcon('branding'); ?></span>
                    <span>Branding Settings</span>
                </a>

                <a href="module_manager.php"
                   class="sidebar-link <?php echo $activeMenu === 'modules' ? 'active' : ''; ?>">
                    <span class="menu-icon"><?php echo cpmsSidebarIcon('modules'); ?></span>
                    <span>Module Manager</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-account">
        <div class="account-avatar">
            <?php echo strtoupper(
                substr((string) $propertyPortalUser['full_name'], 0, 1)
            ); ?>
        </div>
        <div class="account-copy">
            <small>Signed in as</small>
            <strong><?php echo propertyPortalEscape(
                $propertyPortalUser['full_name']
            ); ?></strong>
            <span><?php echo propertyPortalEscape(
                cpmsPropertyRoleLabel()
            ); ?></span>
        </div>
        <a href="logout.php"
           class="sign-out-link"
           aria-label="Sign out" title="Sign out">↗</a>
    </div>
</aside>
