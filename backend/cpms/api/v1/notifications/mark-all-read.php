<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff', 'security']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$role = (string) $identity['role'];
$username = (string) $identity['username'];
$name = (string) $identity['name'];

$stmt = $db->prepare(
    "UPDATE cpms_notifications
     SET is_read = 1
     WHERE property_id = ? AND archived_at IS NULL AND deleted_at IS NULL
       AND (target_role IS NULL OR target_role='' OR target_role=?)
       AND (target_user IS NULL OR target_user='' OR target_user=? OR target_user=?)"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare notification update.');
}
$stmt->bind_param('isss', $propertyId, $role, $username, $name);
$stmt->execute();
$stmt->close();

cpmsApiRespond(['accepted' => true]);
