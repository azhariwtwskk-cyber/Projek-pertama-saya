<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff_pwa_bootstrap.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/notification_service.php';

if (empty($_SESSION['staff_id'])) {
    header('Location: cpms/login.php');
    exit();
}
cpmsRequire('notifications.view', $conn);

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) (
    $_SESSION['cpms_property_id']
    ?? $_SESSION['staff_property_id']
    ?? 0
);
if ($userId < 1 || $propertyId < 1) {
    http_response_code(403);
    exit('Unified Staff account context is required.');
}
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
            $conn, $propertyId, $userId,
            (int) ($_POST['notification_id'] ?? 0)
        );
    }
    header('Location: staff_notifications.php');
    exit();
}

$stmt = $conn->prepare(
    'SELECT *
     FROM cpms_user_notifications
     WHERE property_id = ?
       AND recipient_system_user_id = ?
     ORDER BY is_read ASC, created_at DESC
     LIMIT 100'
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
$branding = cpmsStaffPwaBranding($conn);

function staffNotificationE(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifikasi | CPMS Staff</title><link rel="stylesheet" href="css/pms.css?v=5">
<style>
.nt-wrap{max-width:900px;margin:auto;padding:22px}.nt-head,.nt-card{background:#fff;border:1px solid #e5ded7;border-radius:14px;padding:18px;margin-bottom:13px}.nt-head h1{margin-top:0;color:#4a2b20}.nt-card.unread{border-left:5px solid #2563eb}.nt-card.danger{border-left-color:#dc2626}.nt-card.warning{border-left-color:#d97706}.nt-card.success{border-left-color:#16a34a}.nt-meta{font-size:13px;color:#735e55}.nt-actions{display:flex;gap:8px;margin-top:12px}.nt-btn{border:0;border-radius:8px;background:#4a2b20;color:#fff;padding:9px 13px;text-decoration:none;font-weight:700}.nt-light{background:#eee8e2;color:#4a2b20}
</style>
<?php echo cpmsStaffPwaHead($branding); ?><?php echo cpmsStaffPwaStyle($branding); ?>
    <link rel="stylesheet" href="css/genesis_workforce_web.css?v=3.1.0">
</head><body class="pms-body"><main class="nt-wrap">
<a class="nt-btn" href="staff_dashboard.php">← Dashboard</a>
<section class="nt-head"><h1>Notifikasi Saya</h1><p>Amaran tugasan, due date dan keputusan pengesahan.</p>
<?php if (cpmsCan('notifications.manage', $conn)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo staffNotificationE(cpmsNotificationCsrfToken()); ?>"><button class="nt-btn nt-light" name="mark_all" value="1">Tandakan Semua Dibaca</button></form><?php endif; ?></section>
<?php if (!$notifications): ?><article class="nt-card">Tiada notifikasi.</article><?php endif; ?>
<?php foreach ($notifications as $notification): ?>
<article class="nt-card <?php echo !(int) $notification['is_read'] ? 'unread ' : ''; ?><?php echo staffNotificationE((string) $notification['severity']); ?>">
<h2><?php echo staffNotificationE((string) $notification['title']); ?></h2>
<p><?php echo staffNotificationE((string) $notification['message']); ?></p>
<div class="nt-meta"><?php echo staffNotificationE((string) $notification['created_at']); ?></div>
<div class="nt-actions">
<?php if (!empty($notification['target_url'])): ?><a class="nt-btn" href="<?php echo staffNotificationE((string) $notification['target_url']); ?>">Buka</a><?php endif; ?>
<?php if (!(int) $notification['is_read'] && cpmsCan('notifications.manage', $conn)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo staffNotificationE(cpmsNotificationCsrfToken()); ?>"><input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>"><button class="nt-btn nt-light">Tandakan Dibaca</button></form><?php endif; ?>
</div></article>
<?php endforeach; ?>
</main></body></html>
