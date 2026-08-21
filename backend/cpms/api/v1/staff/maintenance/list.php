<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/cpms/includes/preventive_maintenance_service.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];

$stmt = $db->prepare(
    "SELECT s.id, s.schedule_name, s.asset_name, s.next_due_date, s.priority,
            s.frequency_unit, s.frequency_interval, s.last_completed_date,
            CASE
                WHEN s.status = 'active' AND s.next_due_date < CURDATE() THEN 'Overdue'
                WHEN s.status = 'active' AND s.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Due Soon'
                ELSE s.status
            END AS due_status
     FROM cpms_pm_schedules s
     WHERE s.property_id = ? AND s.assigned_system_user_id = ? AND s.status = 'active'
     ORDER BY s.next_due_date, s.id DESC"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare maintenance list.');
}
$stmt->bind_param('ii', $propertyId, $userId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'id' => (int) $row['id'],
        'schedule_name' => (string) $row['schedule_name'],
        'asset_name' => (string) $row['asset_name'],
        'next_due_date' => (string) $row['next_due_date'],
        'last_completed_date' => $row['last_completed_date'] !== null ? (string) $row['last_completed_date'] : null,
        'priority' => (string) $row['priority'],
        'frequency' => $row['frequency_interval'] . ' ' . $row['frequency_unit'],
        'due_status' => (string) $row['due_status'],
    ];
}
$stmt->close();

cpmsApiRespond(['schedules' => $rows]);
