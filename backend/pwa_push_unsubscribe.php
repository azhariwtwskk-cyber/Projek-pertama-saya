<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/push_notification_service.php';
$identity = cpmsPushRequireIdentity();
cpmsPushVerifyRequest();
$body = json_decode((string) file_get_contents('php://input'), true);
$endpoint = trim((string) ($body['endpoint'] ?? ''));
$hash = hash('sha256', $endpoint);
$userId = (int) $identity['user_id'];
$stmt = $conn->prepare(
    "UPDATE cpms_push_subscriptions SET status = 'revoked'
     WHERE endpoint_hash = ? AND system_user_id = ?"
);
$stmt->bind_param('si', $hash, $userId);
$stmt->execute();
$stmt->close();
cpmsPushJson(['ok' => true]);
