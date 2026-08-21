<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/push_notification_service.php';
$identity = cpmsPushRequireIdentity();
cpmsPushVerifyRequest();
$body = json_decode((string) file_get_contents('php://input'), true);
$endpoint = trim((string) ($body['endpoint'] ?? ''));
$keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
if (strlen($endpoint) < 20 || strlen($endpoint) > 4000
    || stripos($endpoint, 'https://') !== 0) {
    cpmsPushJson(['ok' => false, 'error' => 'invalid_subscription'], 422);
}
$hash = hash('sha256', $endpoint);
$p256dh = substr((string) ($keys['p256dh'] ?? ''), 0, 255);
$auth = substr((string) ($keys['auth'] ?? ''), 0, 255);
$agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$userId = (int) $identity['user_id'];
$propertyId = (int) $identity['property_id'];
$role = (string) $identity['role'];
$stmt = $conn->prepare(
    "INSERT INTO cpms_push_subscriptions
        (system_user_id, property_id, role_code, endpoint, endpoint_hash,
         p256dh, auth_secret, user_agent, status, last_seen_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
     ON DUPLICATE KEY UPDATE
        system_user_id = VALUES(system_user_id),
        property_id = VALUES(property_id),
        role_code = VALUES(role_code),
        p256dh = VALUES(p256dh), auth_secret = VALUES(auth_secret),
        user_agent = VALUES(user_agent), status = 'active',
        last_seen_at = NOW(), failure_count = 0"
);
$stmt->bind_param(
    'iissssss',
    $userId, $propertyId, $role,
    $endpoint, $hash, $p256dh, $auth, $agent
);
$stmt->execute();
$stmt->close();
cpmsPushJson(['ok' => true, 'message' => 'notifications_enabled']);
