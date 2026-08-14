<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/notification_service.php';

cpmsRequire('notifications.view', $conn);

$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
cpmsNotificationSyncCorrectiveActions($conn, $propertyId);
cpmsNotificationSyncPreventiveMaintenance($conn, $propertyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && cpmsCan('notifications.manage', $conn)
) {
    if (!cpmsNotificationVerifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Security session is invalid.');
    }
    if (isset($_POST['mark_all'])) {
        cpmsNotificationMarkAllRead($conn, $propertyId, $userId);
    } else {
        cpmsNotificationMarkRead(
            $conn,
            $propertyId,
            $userId,
            (int) ($_POST['notification_id'] ?? 0)
        );
    }
    header('Location: notifications.php');
    exit();
}

$stmt = $conn->prepare(
    'SELECT *
     FROM cpms_user_notifications
     WHERE property_id = ?
       AND recipient_system_user_id = ?
     ORDER BY is_read ASC, created_at DESC
     LIMIT 200'
);
$notifications = [];
if ($stmt) {
    $stmt->bind_param('ii', $propertyId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}

$overdueStmt = $conn->prepare(
    "SELECT a.id, a.action_no, a.title, a.assigned_name,
            a.priority, a.due_date, a.status,
            DATEDIFF(CURDATE(), a.due_date) AS overdue_days
     FROM inspection_corrective_actions a
     WHERE a.property_id = ?
       AND a.due_date < CURDATE()
       AND a.status NOT IN ('Verified', 'Closed')
     ORDER BY overdue_days DESC, a.priority DESC"
);
$overdue = [];
if ($overdueStmt) {
    $overdueStmt->bind_param('i', $propertyId);
    $overdueStmt->execute();
    $result = $overdueStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $overdue[] = $row;
    }
    $overdueStmt->close();
}

function notificationE(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Notifications & Overdue';
$activeMenu = 'notifications';
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>
<style>
.nt-wrap{padding:24px}.nt-head{display:flex;align-items:center;justify-content:space-between;gap:15px}.nt-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:17px;margin:12px 0}.nt-card.unread{border-left:5px solid #2563eb}.nt-card.danger{border-left-color:#dc2626}.nt-card.warning{border-left-color:#d97706}.nt-card.success{border-left-color:#16a34a}.nt-meta{color:#64748b;font-size:13px}.nt-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.nt-btn{border:0;background:#173b73;color:#fff;text-decoration:none;padding:9px 13px;border-radius:8px;font-weight:700;cursor:pointer}.nt-btn.light{background:#e2e8f0;color:#334155}.nt-table{width:100%;border-collapse:collapse}.nt-table th,.nt-table td{padding:11px;border-bottom:1px solid #e2e8f0;text-align:left}@media(max-width:700px){.nt-wrap{padding:14px}.nt-head{align-items:start;flex-direction:column}.nt-table{display:block;overflow:auto}}
</style>
<div class="nt-wrap">
    <div class="nt-head">
        <div><h1>Notifications & Overdue</h1><p>Corrective Action alerts for this property.</p></div>
        <?php if (cpmsCan('notifications.manage', $conn)): ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo notificationE(cpmsNotificationCsrfToken()); ?>"><button class="nt-btn light" name="mark_all" value="1">Mark All Read</button></form>
        <?php endif; ?>
    </div>

    <section class="nt-card">
        <h2>Overdue Monitoring (<?php echo count($overdue); ?>)</h2>
        <table class="nt-table">
            <thead><tr><th>Action</th><th>Assigned To</th><th>Due</th><th>Late</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$overdue): ?><tr><td colspan="5">No overdue Corrective Action.</td></tr><?php endif; ?>
            <?php foreach ($overdue as $item): ?>
            <tr>
                <td><a href="inspection_action_view.php?id=<?php echo (int) $item['id']; ?>"><?php echo notificationE((string) $item['action_no']); ?></a><br><?php echo notificationE((string) $item['title']); ?></td>
                <td><?php echo notificationE((string) $item['assigned_name']); ?></td>
                <td><?php echo notificationE((string) $item['due_date']); ?></td>
                <td><strong><?php echo (int) $item['overdue_days']; ?> days</strong></td>
                <td><?php echo notificationE((string) $item['status']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <h2>Notification Inbox</h2>
    <?php if (!$notifications): ?><div class="nt-card">No notification.</div><?php endif; ?>
    <?php foreach ($notifications as $notification): ?>
    <article class="nt-card <?php echo !(int) $notification['is_read'] ? 'unread ' : ''; ?><?php echo notificationE((string) $notification['severity']); ?>">
        <h3><?php echo notificationE((string) $notification['title']); ?></h3>
        <p><?php echo notificationE((string) $notification['message']); ?></p>
        <div class="nt-meta"><?php echo notificationE((string) $notification['created_at']); ?></div>
        <div class="nt-actions">
            <?php if (!empty($notification['target_url'])): ?><a class="nt-btn" href="<?php echo notificationE((string) $notification['target_url']); ?>">Open</a><?php endif; ?>
            <?php if (!(int) $notification['is_read'] && cpmsCan('notifications.manage', $conn)): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo notificationE(cpmsNotificationCsrfToken()); ?>"><input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>"><button class="nt-btn light">Mark Read</button></form>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
