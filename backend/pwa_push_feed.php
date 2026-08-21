<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/push_notification_service.php';
$identity = cpmsPushRequireIdentity();
$userId = (int) $identity['user_id'];
$propertyId = (int) $identity['property_id'];
$stmt = $conn->prepare(
    "SELECT id, title, message, target_url, severity
     FROM cpms_user_notifications
     WHERE recipient_system_user_id = ? AND property_id = ? AND is_read = 0
     ORDER BY updated_at DESC, id DESC LIMIT 1"
);
$stmt->bind_param('ii', $userId, $propertyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    cpmsPushJson([
        'ok' => true, 'title' => 'CPMS',
        'message' => 'Buka CPMS untuk menyemak tugasan terkini.',
        'url' => $identity['role'] === 'security'
            ? './security_dashboard.php' : './staff_dashboard.php',
        'tag' => 'cpms-reminder',
    ]);
}
cpmsPushJson([
    'ok' => true,
    'title' => (string) $row['title'],
    'message' => (string) $row['message'],
    'url' => (string) ($row['target_url'] ?: (
        $identity['role'] === 'security'
            ? './security_dashboard.php' : './staff_dashboard.php'
    )),
    'tag' => 'cpms-notification-' . (int) $row['id'],
    'severity' => (string) $row['severity'],
]);
