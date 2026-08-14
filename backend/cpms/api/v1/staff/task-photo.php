<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$staffId = cpmsApiStaffId($identity);
$reference = trim((string) ($_POST['work_order_reference'] ?? ''));
$imageType = trim((string) ($_POST['image_type'] ?? 'Supporting'));
$allowedTypes = ['Before', 'During', 'After', 'Supporting'];
if (!in_array($imageType, $allowedTypes, true)) {
    $imageType = 'Supporting';
}
if ($reference === '' || !isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    cpmsApiError('PHOTO_REQUIRED', 'Pilih gambar tugasan untuk dimuat naik.', 422);
}

$stmt = $db->prepare(
    'SELECT id FROM work_orders
     WHERE property_id=? AND assigned_staff_id=? AND work_order_reference=?
     LIMIT 1'
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare work order photo lookup.');
}
$stmt->bind_param('iis', $propertyId, $staffId, $reference);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($row)) {
    cpmsApiError('TASK_NOT_FOUND', 'Tugasan tidak dijumpai atau bukan tugasan anda.', 404);
}

$relativePath = cpmsApiSaveImage(
    $_FILES['photo'],
    'uploads/work_orders/property_' . $propertyId
);
$workOrderId = (int) $row['id'];
$uploadedBy = (string) $identity['name'];
$insert = $db->prepare(
    'INSERT INTO work_order_images
     (work_order_id,image_name,image_type,uploaded_by)
     VALUES (?,?,?,?)'
);
if (!$insert) {
    @unlink(cpmsApiRoot() . '/' . $relativePath);
    throw new RuntimeException('Unable to prepare work order image record.');
}
$insert->bind_param('isss', $workOrderId, $relativePath, $imageType, $uploadedBy);
$insert->execute();
$imageId = (int) $db->insert_id;
$insert->close();
cpmsApiAudit($db, $identity, 'staff.task_photo', 'success', 'work_order', $workOrderId, [
    'image_id' => $imageId,
    'image_type' => $imageType,
]);
cpmsApiRespond([
    'uploaded' => true,
    'image_id' => $imageId,
    'work_order_reference' => $reference,
    'image_url' => '/cpms/' . ltrim($relativePath, '/'),
], 201);
