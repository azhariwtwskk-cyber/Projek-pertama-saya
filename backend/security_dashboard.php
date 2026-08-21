<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/unified_auth_audit.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/property_modules.php';

if (!isset($_SESSION['security_guard_id'])) {
    header('Location: cpms/login.php');
    exit();
}

$securityLastActivity = (int) (
    $_SESSION['security_guard_last_activity'] ?? 0
);

if (
    $securityLastActivity > 0
    && (time() - $securityLastActivity) > 1800
) {
    cpmsUnifiedAuditEvent(
        $conn,
        'session_expired',
        (int) ($_SESSION['cpms_user_id'] ?? 0) ?: null,
        (int) ($_SESSION['cpms_property_id'] ?? 0) ?: null,
        'security',
        'Security session expired after 30 minutes of inactivity.'
    );
    cpmsUnifiedAuditRevokeCurrentSession($conn);
    unset(
        $_SESSION['security_guard_id'],
        $_SESSION['security_guard_name'],
        $_SESSION['security_guard_type'],
        $_SESSION['security_guard_property_id'],
        $_SESSION['security_guard_last_activity'],
        $_SESSION['cpms_user_id'],
        $_SESSION['cpms_user_role'],
        $_SESSION['cpms_property_id'],
        $_SESSION['cpms_authenticated_at'],
        $_SESSION['cpms_permission_cache']
    );
    session_regenerate_id(true);
    header('Location: cpms/login.php?expired=1');
    exit();
}

$_SESSION['security_guard_last_activity'] = time();
cpmsRequire('dashboard.view', $conn);

$canPatrol = cpmsCan('security.patrol', $conn);
$canViewPatrol = cpmsCan('security.view', $conn);
$canManageVisitor = cpmsCan('visitor.manage', $conn);
$canViewMobileInbox = cpmsCan('mobile_inbox.view', $conn);
$canCreateEvidence = cpmsCan('mobile_evidence.create', $conn);
$canClockAttendance = cpmsCan('attendance.clock', $conn);
$securityPropertyId = (int) (
    $_SESSION['cpms_property_id']
    ?? $_SESSION['security_guard_property_id']
    ?? 0
);
$securityDashboardModules = $securityPropertyId > 0
    ? cpmsLoadPropertyModules($conn, $securityPropertyId)
    : cpmsModuleDefaults();
$showSecurityPatrol = $canPatrol
    && cpmsModuleEnabled('security_patrol', $securityDashboardModules);
$showSecurityPatrolHistory = $canViewPatrol
    && cpmsModuleEnabled('security_patrol', $securityDashboardModules);
$showSecurityVisitors = $canManageVisitor
    && cpmsModuleEnabled('security_visitors', $securityDashboardModules);
$showSecurityTaskInbox = $canViewMobileInbox
    && cpmsModuleEnabled('security_task_inbox', $securityDashboardModules);
$showSecurityAttendance = $canClockAttendance
    && cpmsModuleEnabled('security_attendance', $securityDashboardModules);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$id = (int) $_SESSION['security_guard_id'];
$rows = [];

// Ambil warna branding sebenar property untuk tema dashboard
$securityPrimaryColor = '#1f2937';
$securitySecondaryColor = '#b59b20';
$securityPropertyName = 'CPMS Security Patrol';
if ($securityPropertyId > 0) {
    $brandStmt = $conn->prepare(
        'SELECT property_name, primary_color, secondary_color FROM cpms_properties WHERE id = ? LIMIT 1'
    );
    if ($brandStmt) {
        $brandStmt->bind_param('i', $securityPropertyId);
        $brandStmt->execute();
        $brandRow = $brandStmt->get_result()->fetch_assoc();
        $brandStmt->close();
        if (is_array($brandRow)) {
            if (!empty($brandRow['property_name'])) {
                $securityPropertyName = (string) $brandRow['property_name'];
            }
            if (!empty($brandRow['primary_color'])) {
                $securityPrimaryColor = (string) $brandRow['primary_color'];
            }
            if (!empty($brandRow['secondary_color'])) {
                $securitySecondaryColor = (string) $brandRow['secondary_color'];
            }
        }
    }
}

if ($showSecurityPatrolHistory) {
    $stmt = $conn->prepare(
        'SELECT id, patrol_reference, patrol_date, patrol_type,
                issue_found, patrol_status
         FROM security_patrols
         WHERE guard_id = ?
         ORDER BY created_at DESC
         LIMIT 10'
    );

    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Security Dashboard</title>
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="CPMS Security">
    <link rel="manifest" href="security_manifest.php">
    <link rel="apple-touch-icon"
          href="pwa/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="css/security_patrol.css?v=1">
	    <link rel="stylesheet"
	          href="cpms/assets/cpms-responsive-global.css?v=337">
	    <link rel="stylesheet" href="pwa/cpms-mobile.css?v=345">
	    <link rel="stylesheet" href="css/workforce_portal_shell.css?v=393">
	    <style>
	        :root {
	            --cpms-primary: <?php echo e($securityPrimaryColor); ?>;
	            --cpms-secondary: <?php echo e($securitySecondaryColor); ?>;
	            --property-primary: <?php echo e($securityPrimaryColor); ?>;
	            --property-secondary: <?php echo e($securitySecondaryColor); ?>;
	        }
	    </style>
	    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head>
	<body class="cpms-security-page" data-cpms-pwa="security">
	<div class="workforce-shell">
	    <aside class="workforce-sidebar">
	        <div class="workforce-brand">
	            <div class="workforce-logo">SG</div>
	            <div>
	                <strong><?php echo e($securityPropertyName); ?></strong>
	                <span>Security Portal</span>
	            </div>
	        </div>
	
	        <nav class="workforce-nav" aria-label="Security dashboard navigation">
	            <span class="workforce-nav-label">Operations</span>
	            <a class="active" href="security_dashboard.php">Dashboard</a>
	            <?php if ($showSecurityPatrol): ?>
	                <a href="security_patrol_form.php">Start Patrol</a>
	            <?php endif; ?>
	            <?php if ($showSecurityPatrolHistory): ?>
	                <a href="security_patrol_history.php">History</a>
	            <?php endif; ?>
	            <?php if ($showSecurityVisitors && is_file(__DIR__ . '/security_visitors.php')): ?>
	                <a href="security_visitors.php">Visitors</a>
	            <?php endif; ?>
	            <?php if ($showSecurityTaskInbox): ?>
	                <a href="mobile_task_inbox.php">Task Inbox</a>
	            <?php endif; ?>
	            <?php if ($showSecurityAttendance): ?>
	                <a href="/cpms/mobile_attendance.php">GPS Attendance</a>
	            <?php endif; ?>
	        </nav>
	
	        <div class="workforce-account">
	            <a href="security_logout.php">Logout</a>
	        </div>
	    </aside>
	
	    <main class="workforce-main">
	        <header class="workforce-topbar">
	            <div>
	                <h1><?php echo e($securityPropertyName); ?> Security</h1>
	                <p><?php echo e($_SESSION['security_guard_name']); ?></p>
	            </div>
	            <div class="workforce-user-chip">
	                <?php echo e($_SESSION['security_guard_type']); ?>
	            </div>
	        </header>
	
	        <div class="workforce-content">
	            <section class="page-heading">
	                <div>
	                    <span class="section-label">SECURITY OPERATIONS</span>
	                    <h1>Security Dashboard</h1>
	                    <p>Manage patrol activity, visitor movement and assigned mobile tasks.</p>
	                </div>
	            </section>
	
	            <section class="workforce-kpi-grid">
	                <article class="workforce-kpi">
	                    <strong><?php echo count($rows); ?></strong>
	                    <span>Recent Patrols</span>
	                </article>
	                <article class="workforce-kpi">
	                    <strong><?php echo $showSecurityPatrol ? 'Ready' : 'Off'; ?></strong>
	                    <span>Patrol Module</span>
	                </article>
	                <article class="workforce-kpi">
	                    <strong><?php echo $showSecurityTaskInbox ? 'On' : 'Off'; ?></strong>
	                    <span>Task Inbox</span>
	                </article>
	                <article class="workforce-kpi">
	                    <strong><?php echo $showSecurityAttendance ? 'On' : 'Off'; ?></strong>
	                    <span>GPS Attendance</span>
	                </article>
	            </section>
	
	            <?php if ($showSecurityPatrol): ?>
	                <section class="card">
	                    <h2 class="workforce-section-title">Main Action</h2>
	                    <p>Start a new patrol record for the current security duty.</p>
	                    <a class="btn" href="security_patrol_form.php">
	                        Start New Patrol
	                    </a>
	                </section>
	            <?php endif; ?>
	
	            <?php if ($showSecurityPatrolHistory): ?>
	                <section class="card">
	                    <h2 class="workforce-section-title">Recent Patrols</h2>
	                    <div style="overflow:auto">
	                        <table>
	                            <tr>
	                                <th>Reference</th>
	                                <th>Date</th>
	                                <th>Type</th>
	                                <th>Issue</th>
	                                <th>Status</th>
	                                <?php if ($canCreateEvidence): ?><th>Evidence</th><?php endif; ?>
	                            </tr>
	                            <?php if (!$rows): ?>
	                                <tr><td colspan="<?= $canCreateEvidence ? 6 : 5 ?>">
	                                    No patrol record yet.
	                                </td></tr>
	                            <?php else: ?>
	                                <?php foreach ($rows as $row): ?>
	                                    <tr>
	                                        <td><?php echo e($row['patrol_reference']); ?></td>
	                                        <td><?php echo e($row['patrol_date']); ?></td>
	                                        <td><?php echo e($row['patrol_type']); ?></td>
	                                        <td><?php echo $row['issue_found'] ? 'Yes' : 'No'; ?></td>
	                                        <td><?php echo e($row['patrol_status']); ?></td>
	                                        <?php if ($canCreateEvidence): ?><td>
	                                            <a href="mobile_evidence.php?type=security_patrol&amp;id=<?=
	                                                (int) $row['id'] ?>">Photo</a>
	                                        </td><?php endif; ?>
	                                    </tr>
	                                <?php endforeach; ?>
	                            <?php endif; ?>
	                        </table>
	                    </div>
	                </section>
	            <?php endif; ?>
	
	            <?php if (
	                !$showSecurityPatrol
	                && !$showSecurityPatrolHistory
	                && !$showSecurityVisitors
	                && !$showSecurityTaskInbox
	                && !$showSecurityAttendance
	            ): ?>
	                <section class="card">
	                    <h2 class="workforce-section-title">Dashboard Modules Disabled</h2>
	                    <p>Please contact the Property Admin to enable Security modules for this property.</p>
	                </section>
	            <?php endif; ?>
	        </div>
	    </main>
	</div>
	<script src="pwa/cpms-mobile.js?v=345" defer></script>
	</body>
</html>
