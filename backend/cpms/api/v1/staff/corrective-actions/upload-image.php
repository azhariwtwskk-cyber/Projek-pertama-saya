<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/cpms/includes/corrective_action_service.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];

$input = cpmsApiInput(); // multipart -> $_POST
$actionId = (int) ($input['action_id'] ?? 0);
$phase = trim((string) ($input['phase'] ?? 'After'));
$caption = trim((string) ($input['caption'] ?? ''));

if ($actionId < 1) {
    cpmsApiError('ID_REQUIRED', 'ID action diperlukan.', 422);
}

$action = cpmsActionFind($db, $propertyId, $actionId);
if (!is_array($action) || (int) $action['assigned_system_user_id'] !== (int) $identity['system_user_id']) {
    cpmsApiError('NOT_FOUND', 'Corrective action tidak dijumpai atau bukan milik anda.', 404);
}

if (!isset($_FILES['photo'])) {
    cpmsApiError('IMAGE_REQUIRED', 'Gambar diperlukan.', 422);
}

try {
    $imageId = cpmsActionStoreImage(
        $db,
        $propertyId,
        $action,
        $_FILES['photo'],
        $phase,
        $caption,
        dirname(__DIR__, 4) . '/uploads/inspection_actions'
    );
} catch (Throwable $exception) {
    cpmsApiError('UPLOAD_FAILED', $exception->getMessage(), 422);
}

cpmsApiRespond(['accepted' => true, 'image_id' => $imageId], 201);
