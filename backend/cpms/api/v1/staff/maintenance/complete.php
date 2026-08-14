<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/cpms/includes/preventive_maintenance_service.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];

$input = cpmsApiInput(); // multipart -> $_POST
$scheduleId = (int) ($input['schedule_id'] ?? 0);

if ($scheduleId < 1) {
    cpmsApiError('ID_REQUIRED', 'ID schedule diperlukan.', 422);
}

$stmt = $db->prepare(
    "SELECT * FROM cpms_pm_schedules
     WHERE id = ? AND property_id = ? AND assigned_system_user_id = ? AND status = 'active' LIMIT 1"
);
$stmt->bind_param('iii', $scheduleId, $propertyId, $userId);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!is_array($schedule)) {
    cpmsApiError('NOT_FOUND', 'Maintenance schedule tidak dijumpai.', 404);
}

if (empty($input['completed_date']) || empty($input['work_notes'])) {
    cpmsApiError('FIELDS_REQUIRED', 'Tarikh selesai dan catatan kerja diperlukan.', 422);
}

try {
    $workLogId = cpmsPmComplete(
        $db,
        $propertyId,
        $schedule,
        $input,
        $_FILES['evidence'] ?? [],
        dirname(__DIR__, 4) . '/cpms/uploads/preventive_maintenance'
    );
} catch (Throwable $exception) {
    cpmsApiError('SUBMIT_FAILED', $exception->getMessage(), 422);
}

cpmsApiAudit($db, $identity, 'staff.pm_complete', 'success', 'pm_schedule', $scheduleId, [
    'work_log_id' => $workLogId,
]);

cpmsApiRespond([
    'accepted' => true,
    'work_log_id' => $workLogId,
], 201);
