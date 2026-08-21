<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff', 'security']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$role = (string) $identity['role'];
$username = (string) $identity['username'];
$name = (string) $identity['name'];
$stmt = $db->prepare(
    "SELECT id,notification_type,category,priority,title,message,
            reference_no,action_url,is_read,created_at
     FROM cpms_notifications
     WHERE property_id=? AND archived_at IS NULL AND deleted_at IS NULL
       AND (target_role IS NULL OR target_role='' OR target_role=?)
       AND (target_user IS NULL OR target_user='' OR target_user=? OR target_user=?)
     ORDER BY created_at DESC LIMIT 50"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare notifications.');
}
$stmt->bind_param('isss', $propertyId, $role, $username, $name);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'id' => (int) $row['id'],
        'type' => (string) $row['notification_type'],
        'category' => (string) ($row['category'] ?? ''),
        'priority' => (string) $row['priority'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'reference' => (string) ($row['reference_no'] ?? ''),
        'action_url' => (string) ($row['action_url'] ?? ''),
        'is_read' => (bool) $row['is_read'],
        'created_at' => (string) $row['created_at'],
    ];
}
$stmt->close();
cpmsApiRespond(['notifications' => $items]);

