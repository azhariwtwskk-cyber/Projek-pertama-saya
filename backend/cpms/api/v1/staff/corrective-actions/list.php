<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];

$status = trim((string) ($_GET['status'] ?? 'active'));
$whereStatus = "a.status IN ('Open', 'In Progress', 'Rectified')";
if ($status === 'completed') {
    $whereStatus = "a.status IN ('Verified', 'Closed')";
} elseif ($status === 'all') {
    $whereStatus = '1 = 1';
}

$stmt = $db->prepare(
    "SELECT a.id, a.action_no, a.title, a.priority, a.due_date,
            a.status, a.created_at, r.inspection_no, r.location
     FROM inspection_corrective_actions a
     INNER JOIN inspection_reports r ON r.id = a.inspection_id AND r.property_id = a.property_id
     WHERE a.property_id = ? AND a.assigned_system_user_id = ? AND {$whereStatus}
     ORDER BY FIELD(a.status, 'Open','In Progress','Rectified','Verified','Closed'),
              CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END, a.due_date ASC, a.id DESC"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare corrective action list.');
}
$stmt->bind_param('ii', $propertyId, $userId);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'id' => (int) $row['id'],
        'action_no' => (string) $row['action_no'],
        'title' => (string) $row['title'],
        'priority' => (string) $row['priority'],
        'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
        'status' => (string) $row['status'],
        'inspection_no' => (string) $row['inspection_no'],
        'location' => (string) ($row['location'] ?? ''),
    ];
}
$stmt->close();

cpmsApiRespond(['actions' => $rows]);
