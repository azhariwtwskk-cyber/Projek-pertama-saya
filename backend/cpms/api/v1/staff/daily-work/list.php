<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$staffId = cpmsApiStaffId($identity);
$db = cpmsApiDatabase();

$status = trim((string) ($_GET['status'] ?? ''));
$allowed = ['In Progress', 'Completed', 'Pending Material', 'Pending Contractor', 'Unable to Complete', 'Verified', 'Rejected'];

$where = 'WHERE d.staff_id = ?';
$types = 'i';
$params = [$staffId];
if ($status !== '' && in_array($status, $allowed, true)) {
    $where .= ' AND d.work_status = ?';
    $types .= 's';
    $params[] = $status;
}

$stmt = $db->prepare(
    "SELECT d.id, d.work_reference, d.work_date, d.work_category, d.block_location,
            d.specific_location, d.work_description, d.work_status, d.verified_by, d.verified_at
     FROM daily_work_logs d
     {$where}
     ORDER BY d.work_date DESC, d.id DESC
     LIMIT 50"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare daily work list.');
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'id' => (int) $row['id'],
        'reference' => (string) $row['work_reference'],
        'date' => (string) $row['work_date'],
        'category' => (string) $row['work_category'],
        'location' => trim($row['block_location'] . ' - ' . ($row['specific_location'] ?? '')),
        'description' => (string) $row['work_description'],
        'status' => (string) $row['work_status'],
        'verified' => $row['verified_at'] !== null,
    ];
}
$stmt->close();

cpmsApiRespond(['logs' => $rows]);
