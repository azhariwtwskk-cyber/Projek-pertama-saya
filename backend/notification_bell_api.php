<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notification_engine.php';
require_once __DIR__ . '/../includes/notification_bell.php';

function bell_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$user = notification_bell_user();

if (!$user['role'] || $user['user_id'] <= 0) {
    bell_json_response([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
}

if (
    !notification_table_exists($conn, 'notifications')
    || !notification_table_exists($conn, 'notification_reads')
) {
    bell_json_response([
        'success' => false,
        'message' => 'Notification database belum dipasang.'
    ], 500);
}

$propertyId = notification_current_property_id($conn);
$userRole = $user['role'];
$userId = (int)$user['user_id'];
$limit = max(1, min(10, (int)($_GET['limit'] ?? 8)));

$unreadCount = notification_unread_count(
    $conn,
    $propertyId,
    $userRole,
    $userId
);

$sql = "
    SELECT
        n.id,
        n.notification_type,
        n.title,
        n.message,
        n.priority,
        n.action_url,
        n.icon,
        n.created_at,
        CASE WHEN r.id IS NULL THEN 0 ELSE 1 END AS is_read
    FROM notifications n
    LEFT JOIN notification_reads r
        ON r.notification_id = n.id
       AND r.user_role = ?
       AND r.user_id = ?
    WHERE n.property_id = ?
      AND (
            n.target_role = 'all'
            OR n.target_role = ?
      )
      AND (
            n.target_user_id IS NULL
            OR n.target_user_id = ?
      )
      AND (
            n.expires_at IS NULL
            OR n.expires_at >= NOW()
      )
    ORDER BY n.created_at DESC
    LIMIT ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    bell_json_response([
        'success' => false,
        'message' => 'Gagal menyediakan notification query.'
    ], 500);
}

$stmt->bind_param(
    'siisii',
    $userRole,
    $userId,
    $propertyId,
    $userRole,
    $userId,
    $limit
);

$stmt->execute();
$result = $stmt->get_result();
$notifications = [];

while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => (int)$row['id'],
        'type' => (string)$row['notification_type'],
        'title' => (string)$row['title'],
        'message' => (string)$row['message'],
        'priority' => (string)$row['priority'],
        'action_url' => (string)($row['action_url'] ?? ''),
        'icon' => (string)($row['icon'] ?? ''),
        'created_at' => (string)$row['created_at'],
        'created_label' => date(
            'd/m/Y h:i A',
            strtotime((string)$row['created_at'])
        ),
        'is_read' => (bool)$row['is_read']
    ];
}

$stmt->close();

bell_json_response([
    'success' => true,
    'unread_count' => $unreadCount,
    'notifications' => $notifications
]);
