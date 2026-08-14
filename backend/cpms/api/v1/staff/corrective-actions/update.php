<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/cpms/includes/corrective_action_service.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];

$input = cpmsApiInput();
$actionId = (int) ($input['action_id'] ?? 0);
$status = trim((string) ($input['status'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($actionId < 1 || $status === '') {
    cpmsApiError('FIELDS_REQUIRED', 'ID action dan status diperlukan.', 422);
}

$action = cpmsActionFind($db, $propertyId, $actionId);
if (!is_array($action) || (int) $action['assigned_system_user_id'] !== (int) $identity['system_user_id']) {
    cpmsApiError('NOT_FOUND', 'Corrective action tidak dijumpai atau bukan milik anda.', 404);
}

// Staff hanya boleh tandakan "In Progress" atau "Rectified" (bukan Verified/Closed - itu untuk admin)
if (!in_array($status, ['In Progress', 'Rectified'], true)) {
    cpmsApiError('INVALID_STATUS', 'Staff hanya boleh tetapkan status "In Progress" atau "Rectified".', 422);
}

try {
    $ok = cpmsActionUpdateStatus($db, $propertyId, $actionId, $status, $notes);
} catch (Throwable $exception) {
    cpmsApiError('UPDATE_FAILED', $exception->getMessage(), 422);
}

if (!$ok) {
    cpmsApiError('UPDATE_FAILED', 'Gagal mengemaskini status.', 500);
}

cpmsApiAudit($db, $identity, 'staff.corrective_action_status', 'success', 'corrective_action', $actionId, [
    'status' => $status,
]);

cpmsApiRespond(['accepted' => true, 'status' => $status]);
