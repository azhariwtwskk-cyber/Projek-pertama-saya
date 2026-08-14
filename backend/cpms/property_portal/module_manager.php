<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!cpmsPropertyCan('settings.manage')) {
    cpmsPropertyRequire('settings.manage');
}

$availableModules = [
    'complaints' => 'Complaints',
    'work_orders' => 'Work Orders',
    'residents' => 'Residents',
    'staff' => 'Staff',
    'assets' => 'Assets',
    'reports' => 'Reports',
    'facility_booking' => 'Facility Booking',
    'preventive_maintenance' => 'Preventive Maintenance',
    'visitor_management' => 'Visitor Management',
];

$staffDashboardModules = [
    'staff_work_orders' => 'Staff - Work Orders',
    'staff_daily_work' => 'Staff - Daily Work',
    'staff_inspection_actions' => 'Staff - Inspection Corrective Actions',
    'staff_notifications' => 'Staff - Notifications',
    'staff_task_inbox' => 'Staff - Task Inbox',
    'staff_attendance' => 'Staff - GPS Attendance',
    'staff_maintenance' => 'Staff - Preventive Maintenance',
];

$securityDashboardModules = [
    'security_patrol' => 'Security - Patrol',
    'security_visitors' => 'Security - Visitors',
    'security_task_inbox' => 'Security - Task Inbox',
    'security_attendance' => 'Security - GPS Attendance',
];

$allModuleSettings = $availableModules
    + $staffDashboardModules
    + $securityDashboardModules;

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!propertyPortalVerifyCsrf(
        is_string($token) ? $token : null
    )) {
        $message = 'Invalid security token. Refresh the page and try again.';
        $messageType = 'danger';
    }

    if ($message === '') {
        $conn->begin_transaction();

        try {
            foreach ($allModuleSettings as $key => $label) {
            $enabled = isset($_POST['modules'][$key]) ? 1 : 0;

            $stmt = $conn->prepare(
                "INSERT INTO cpms_property_modules
                    (property_id, module_key, is_enabled, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    updated_at = NOW()"
            );

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare module update.'
                );
            }

            $stmt->bind_param(
                'isi',
                $currentPropertyId,
                $key,
                $enabled
            );
            $stmt->execute();
            $stmt->close();
        }

            $conn->commit();
            $currentPropertyModules = cpmsLoadPropertyModules(
                $conn,
                $currentPropertyId
            );
            $message = 'Module settings were updated.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            cpmsFoundationLog('Property module update failed.');
            $message = 'Module settings could not be saved. Review the server error log.';
            $messageType = 'danger';
        }
    }
}

$pageTitle = 'Module Manager';
$activeMenu = 'modules';

require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<section class="page-heading">
    <div>
        <span class="section-label">PROPERTY MODULE CONTROL</span>
        <h1>Module Manager</h1>
        <p>Enable or hide modules for this property, including Staff and Security dashboards.</p>
    </div>
</section>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo propertyPortalEscape($messageType); ?>">
        <?php echo propertyPortalEscape($message); ?>
    </div>
<?php endif; ?>

<section class="panel">
    <form method="post" class="module-manager-form">
        <input type="hidden"
               name="csrf_token"
               value="<?php echo propertyPortalEscape(propertyPortalCsrfToken()); ?>">

        <h2>Property Admin / Clerk Portal</h2>
        <p class="muted">Controls modules shown inside the property portal menu.</p>
        <div class="module-card-grid">
            <?php foreach ($availableModules as $key => $label): ?>
                <label class="module-card">
                    <input
                        type="checkbox"
                        name="modules[<?php echo propertyPortalEscape($key); ?>]"
                        <?php echo cpmsModuleEnabled($key, $currentPropertyModules) ? 'checked' : ''; ?>
                    >
                    <span>
                        <strong><?php echo propertyPortalEscape($label); ?></strong>
                        <small><?php echo propertyPortalEscape($key); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <h2>Staff Dashboard</h2>
        <p class="muted">Controls cards, shortcuts and sections shown to staff after login.</p>
        <div class="module-card-grid">
            <?php foreach ($staffDashboardModules as $key => $label): ?>
                <label class="module-card">
                    <input
                        type="checkbox"
                        name="modules[<?php echo propertyPortalEscape($key); ?>]"
                        <?php echo cpmsModuleEnabled($key, $currentPropertyModules) ? 'checked' : ''; ?>
                    >
                    <span>
                        <strong><?php echo propertyPortalEscape($label); ?></strong>
                        <small><?php echo propertyPortalEscape($key); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <h2>Security Dashboard</h2>
        <p class="muted">Controls cards, shortcuts and sections shown to security guards after login.</p>
        <div class="module-card-grid">
            <?php foreach ($securityDashboardModules as $key => $label): ?>
                <label class="module-card">
                    <input
                        type="checkbox"
                        name="modules[<?php echo propertyPortalEscape($key); ?>]"
                        <?php echo cpmsModuleEnabled($key, $currentPropertyModules) ? 'checked' : ''; ?>
                    >
                    <span>
                        <strong><?php echo propertyPortalEscape($label); ?></strong>
                        <small><?php echo propertyPortalEscape($key); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>

        <button class="button button-primary" type="submit">
            Save Module Settings
        </button>
    </form>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
