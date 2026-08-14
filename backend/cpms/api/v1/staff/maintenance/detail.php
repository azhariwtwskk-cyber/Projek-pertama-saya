<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/cpms/includes/preventive_maintenance_service.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];
$scheduleId = (int) ($_GET['id'] ?? 0);

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
    cpmsApiError('NOT_FOUND', 'Maintenance schedule tidak dijumpai atau bukan milik anda.', 404);
}

$history = cpmsPmHistory($db, $propertyId, $scheduleId);
$historyOut = array_map(static function ($log) {
    return [
        'id' => (int) $log['id'],
        'completed_date' => (string) $log['completed_date'],
        'result' => (string) $log['result'],
        'work_notes' => (string) $log['work_notes'],
        'verified' => $log['verified_at'] !== null,
        'verified_by' => $log['verified_by_name'] !== null ? (string) $log['verified_by_name'] : null,
        'evidence_path' => $log['evidence_path'] !== null ? (string) $log['evidence_path'] : null,
    ];
}, $history);

cpmsApiRespond([
    'schedule' => [
        'id' => (int) $schedule['id'],
        'schedule_name' => (string) $schedule['schedule_name'],
        'asset_name' => (string) $schedule['asset_name'],
        'next_due_date' => (string) $schedule['next_due_date'],
        'priority' => (string) $schedule['priority'],
        'instructions' => (string) ($schedule['instructions'] ?? ''),
        'frequency_unit' => (string) $schedule['frequency_unit'],
        'frequency_interval' => (int) $schedule['frequency_interval'],
    ],
    'history' => $historyOut,
]);
