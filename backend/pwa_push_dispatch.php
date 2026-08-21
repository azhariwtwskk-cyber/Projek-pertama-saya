<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/push_notification_service.php';
$token = (string) ($_GET['token'] ?? '');
$isOwner = (string) ($_SESSION['cpms_user_role'] ?? '') === 'system_owner'
    || isset($_SESSION['system_owner_id']);
$settings = $conn->query(
    'SELECT public_key, private_key_pem, subject, dispatch_token
     FROM cpms_push_settings WHERE id = 1'
);
$config = $settings ? $settings->fetch_assoc() : null;
if (!$config) {
    cpmsPushJson(['ok' => false, 'error' => 'push_not_configured'], 503);
}
if (!$isOwner && ($token === ''
    || !hash_equals((string) $config['dispatch_token'], $token))) {
    cpmsPushJson(['ok' => false, 'error' => 'access_denied'], 403);
}
$conn->query(
    "INSERT IGNORE INTO cpms_push_deliveries
        (notification_id, subscription_id)
     SELECT n.id, s.id
     FROM cpms_user_notifications n
     JOIN cpms_push_subscriptions s
       ON s.system_user_id = n.recipient_system_user_id
      AND s.property_id = n.property_id
      AND s.status = 'active'
     WHERE n.is_read = 0
     ORDER BY n.updated_at DESC LIMIT 100"
);
$result = $conn->query(
    "SELECT d.id, d.subscription_id, s.endpoint
     FROM cpms_push_deliveries d
     JOIN cpms_push_subscriptions s ON s.id = d.subscription_id
     WHERE d.delivery_status = 'pending' AND s.status = 'active'
     ORDER BY d.id LIMIT 25"
);
$sent = 0;
$failed = 0;
while ($result && ($row = $result->fetch_assoc())) {
    $status = cpmsPushSendEmpty(
        (string) $row['endpoint'],
        (string) $config['public_key'],
        (string) $config['private_key_pem'],
        (string) $config['subject']
    );
    $ok = in_array($status, [201, 202], true);
    $delivery = $ok ? 'delivered' : 'failed';
    $stmt = $conn->prepare(
        "UPDATE cpms_push_deliveries SET delivery_status = ?,
         http_status = ?, attempted_at = NOW(),
         delivered_at = IF(? = 'delivered', NOW(), NULL) WHERE id = ?"
    );
    $deliveryId = (int) $row['id'];
    $stmt->bind_param('sisi', $delivery, $status, $delivery, $deliveryId);
    $stmt->execute();
    $stmt->close();
    if ($ok) {
        $sent++;
        $conn->query(
            'UPDATE cpms_push_subscriptions SET last_push_at = NOW(),
             failure_count = 0 WHERE id = ' . (int) $row['subscription_id']
        );
    } else {
        $failed++;
        $revoke = in_array($status, [404, 410], true);
        $conn->query(
            "UPDATE cpms_push_subscriptions SET failure_count = failure_count + 1"
            . ($revoke ? ", status = 'revoked'" : '')
            . ' WHERE id = ' . (int) $row['subscription_id']
        );
    }
}
cpmsPushJson(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
