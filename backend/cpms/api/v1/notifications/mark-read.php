<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff', 'security']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];

$input = cpmsApiInput();
$id = (int) ($input['id'] ?? 0);

if ($id < 1) {
    cpmsApiError('ID_REQUIRED', 'ID notifikasi diperlukan.', 422);
}

$stmt = $db->prepare('UPDATE cpms_notifications SET is_read = 1 WHERE id = ? AND property_id = ?');
if (!$stmt) {
    throw new RuntimeException('Unable to prepare notification update.');
}
$stmt->bind_param('ii', $id, $propertyId);
$stmt->execute();
$stmt->close();

cpmsApiRespond(['accepted' => true]);
