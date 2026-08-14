<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__)
    . '/includes/property_permissions.php';

$propertyAdminId = (int) (
    $_SESSION['property_admin_id']
    ?? 0
);

$propertyIdleTimeout = 1800;
$propertyLastActivity = (int) (
    $_SESSION['property_admin_last_activity']
    ?? 0
);

if (
    $propertyLastActivity > 0
    && (time() - $propertyLastActivity) > $propertyIdleTimeout
) {
    cpmsPortalClearSessionKeys([
        'property_admin_id',
        'property_admin_property_id',
        'property_admin_role',
        'property_admin_name',
        'property_admin_last_activity',
        'property_admin_session_rotated_at',
        'property_portal_csrf',
    ]);
    session_regenerate_id(true);
    propertyPortalRedirect('login.php?expired=1');
}

$_SESSION['property_admin_last_activity'] = time();

$sessionRotatedAt = (int) (
    $_SESSION['property_admin_session_rotated_at']
    ?? 0
);

if ($sessionRotatedAt <= 0 || (time() - $sessionRotatedAt) >= 900) {
    session_regenerate_id(true);
    $_SESSION['property_admin_session_rotated_at'] = time();
}

if ($propertyAdminId <= 0) {
    propertyPortalRedirect('login.php');
}

$stmt = $conn->prepare(
    "SELECT
        pa.id,
        pa.property_id,
        pa.full_name,
        pa.username,
        pa.email,
        pa.phone,
        pa.position_title,
        pa.role,
        pa.status,
        pa.last_login_at,
        p.property_code,
        p.property_name,
        p.system_name,
        p.company_name,
        p.tagline,
        p.address,
        p.phone AS property_phone,
        p.email AS property_email,
        p.logo_path,
        p.favicon_path,
        p.background_path,
        p.dashboard_banner_path,
        p.footer_text,
        p.operating_hours,
        p.show_cpms_branding,
        p.primary_color,
        p.secondary_color,
        p.default_language
     FROM property_admins pa
     INNER JOIN cpms_properties p
        ON p.id = pa.property_id
     WHERE pa.id = ?
     LIMIT 1"
);

if (!$stmt) {
    cpmsFoundationLog(
        'Property Portal authentication query failed.'
    );
    cpmsFoundationErrorPage(
        'The account could not be verified.'
    );
}

$stmt->bind_param('i', $propertyAdminId);
$stmt->execute();
$result = $stmt->get_result();
$propertyPortalUser = $result->fetch_assoc();
$stmt->close();

/*
 * Branding Engine v4 compatibility:
 * The database now stores only background_path.
 * Keep a temporary array alias for older pages that still read
 * login_background_path, without querying a removed database column.
 */
if (is_array($propertyPortalUser)) {
    $propertyPortalUser['login_background_path'] = (
        (string) ($propertyPortalUser['background_path'] ?? '')
    );
}

$allowedRoles = [
    'property_admin',
    'manager',
    'supervisor',
    'clerk',
];

if (
    !$propertyPortalUser
    || $propertyPortalUser['status'] !== 'active'
    || !in_array(
        $propertyPortalUser['role'],
        $allowedRoles,
        true
    )
) {
    cpmsPortalClearSessionKeys([
        'property_admin_id',
        'property_admin_property_id',
        'property_admin_role',
        'property_admin_name',
        'property_portal_csrf',
    ]);

    propertyPortalRedirect('login.php?inactive=1');
}

$currentPropertyId = (int) $propertyPortalUser['property_id'];
$currentPropertyCode = (string) $propertyPortalUser['property_code'];
$currentPropertyName = (string) $propertyPortalUser['property_name'];
$currentPropertyRole = (string) $propertyPortalUser['role'];

require_once dirname(__DIR__)
    . '/includes/property_modules.php';

$currentPropertyModules = cpmsLoadPropertyModules(
    $conn,
    $currentPropertyId
);

$_SESSION['property_admin_property_id'] = $currentPropertyId;
$_SESSION['property_admin_role'] = $currentPropertyRole;
$_SESSION['property_admin_name'] = (
    (string) $propertyPortalUser['full_name']
);

/*
 * CPMS v3.1.1 page-level permission enforcement.
 * Resolve unified context when the active session began through a
 * compatible legacy Property Portal entry point.
 */
$cpmsSystemUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);

if ($cpmsSystemUserId <= 0) {
    $unifiedStmt = $conn->prepare(
        "SELECT id
         FROM system_users
         WHERE source_table = 'property_admins'
           AND source_id = ?
           AND status = 'active'
         LIMIT 1"
    );

    if ($unifiedStmt) {
        $unifiedStmt->bind_param('i', $propertyAdminId);
        $unifiedStmt->execute();
        $unifiedRow = $unifiedStmt
            ->get_result()
            ->fetch_assoc();
        $unifiedStmt->close();
        $cpmsSystemUserId = (int) ($unifiedRow['id'] ?? 0);
    }
}

$_SESSION['cpms_user_id'] = $cpmsSystemUserId;
$_SESSION['cpms_user_role'] = $currentPropertyRole;
$_SESSION['cpms_property_id'] = $currentPropertyId;

require_once dirname(__DIR__) . '/includes/permission_engine.php';

function cpmsPropertyPageRequiredPermission(string $script): string
{
    $exact = [
        'dashboard.php' => 'dashboard.view',
        'complaints.php' => 'complaints.view',
        'complaint_view.php' => 'complaints.view',
        'work_orders.php' => 'work_orders.view',
        'work_order_view.php' => 'work_orders.view',
        'daily_work_review.php' => 'work_orders.view',
        'inspections.php' => 'inspection.view',
        'inspection_view.php' => 'inspection.view',
        'inspection_create.php' => 'inspection.view',
        'inspection_upload.php' => 'inspection.update',
        'inspection_review.php' => 'inspection.approve',
        'inspection_action_create.php' => 'inspection.action.manage',
        'inspection_action_view.php' => 'inspection.view',
        'inspection_hq_findings.php' => 'inspection.hq_report.manage',
        'hq_inspection_inbox.php' => 'inspection.hq_report.manage',
        'checklist_templates.php' => 'inspection.checklist.view',
        'checklist_template_create.php' => 'inspection.checklist.manage',
        'inspection_checklist.php' => 'inspection.checklist.view',
        'inspection_checklist_actions.php' =>
            'inspection.checklist.create_actions',
        'inspection_report.php' => 'inspection.report.export',
        'preventive_maintenance.php' => 'maintenance.view',
        'pm_schedule_create.php' => 'maintenance.manage',
        'pm_schedule_view.php' => 'maintenance.view',
        'compliance.php' => 'compliance.view',
        'compliance_view.php' => 'compliance.view',
        'compliance_create.php' => 'compliance.create',
        'compliance_complete.php' => 'compliance.update',
        'assets.php' => 'assets.view',
        'preventive_maintenance.php' => 'assets.view',
        'users.php' => 'users.view',
        'security_guards.php' => 'settings.manage',
        'user_module_access.php' => 'clerk.modules.assign',
        'announcements_manage.php' =>
            'resident.announcement.manage',
        'resident_registrations.php' =>
            'resident.registration.manage',
        'resident_requests.php' => 'resident.request.manage',
        'facilities.php' => 'facilities.manage',
        'facility_cancellations.php' =>
            'facility.booking.cancel',
        'visitors.php' => 'visitor.monitor',
        'visitor_watchlist.php' => 'visitor.watchlist.view',
        'visitor_export.php' => 'visitor.export',
        'branding_settings.php' => 'settings.manage',
        'module_manager.php' => 'settings.manage',
        'reports.php' => 'reports.view',
    ];

    if (isset($exact[$script])) {
        return $exact[$script];
    }

    if (
        strpos($script, 'complaint') !== false
        && (
            strpos($script, 'update') !== false
            || strpos($script, 'status') !== false
            || strpos($script, 'assign') !== false
        )
    ) {
        return 'complaints.update';
    }

    if (
        strpos($script, 'work_order') !== false
        && (
            strpos($script, 'create') !== false
            || strpos($script, 'add') !== false
        )
    ) {
        return 'work_orders.create';
    }

    if (
        strpos($script, 'work_order') !== false
        && (
            strpos($script, 'update') !== false
            || strpos($script, 'assign') !== false
            || strpos($script, 'status') !== false
        )
    ) {
        return 'work_orders.update';
    }

    if (
        strpos($script, 'inspection') !== false
        && strpos($script, 'approve') !== false
    ) {
        return 'inspection.approve';
    }

    if (
        strpos($script, 'inspection') !== false
        && (
            strpos($script, 'create') !== false
            || strpos($script, 'add') !== false
        )
    ) {
        return 'inspection.create';
    }

    if (
        strpos($script, 'asset') !== false
        && (
            strpos($script, 'create') !== false
            || strpos($script, 'add') !== false
        )
    ) {
        return 'assets.create';
    }

    if (
        strpos($script, 'asset') !== false
        && (
            strpos($script, 'update') !== false
            || strpos($script, 'edit') !== false
        )
    ) {
        return 'assets.update';
    }

    if (strpos($script, 'user') !== false) {
        return 'users.view';
    }

    return 'dashboard.view';
}

$propertyPortalScript = basename(
    (string) ($_SERVER['SCRIPT_NAME'] ?? '')
);

cpmsRequire(
    cpmsPropertyPageRequiredPermission($propertyPortalScript),
    $conn
);
