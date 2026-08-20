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

// supervisor_remarks/work_order_id were added by a later migration than
// work_status/verified_by/verified_at (see
// mobile/docs/INTEGRATION_REPAIR_REPORT.md, "Work Order History") — guard
// each with cpmsApiColumnExists() so this endpoint still works against an
// older, un-migrated schema instead of a raw SQL error.
$hasSupervisorRemarks = cpmsApiColumnExists($db, 'daily_work_logs', 'supervisor_remarks');
$hasWorkOrderId = cpmsApiColumnExists($db, 'daily_work_logs', 'work_order_id');

$supervisorRemarksSelect = $hasSupervisorRemarks ? 'd.supervisor_remarks,' : "'' AS supervisor_remarks,";
$workOrderJoin = '';
$workOrderRefSelect = "'' AS work_order_reference,";
if ($hasWorkOrderId) {
    $workOrderJoin = ' LEFT JOIN work_orders w ON w.id = d.work_order_id';
    $workOrderRefSelect = 'w.work_order_reference,';
}

$stmt = $db->prepare(
    "SELECT d.id, d.work_reference, d.work_date, d.work_category, d.block_location,
            d.specific_location, d.work_description, d.work_status, d.verified_by, d.verified_at,
            {$supervisorRemarksSelect} {$workOrderRefSelect} d.id AS daily_work_id
     FROM daily_work_logs d
     {$workOrderJoin}
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
$ids = [];
while ($row = $result->fetch_assoc()) {
    $id = (int) $row['id'];
    $ids[] = $id;
    $rows[$id] = [
        'id' => $id,
        'reference' => (string) $row['work_reference'],
        'date' => (string) $row['work_date'],
        'category' => (string) $row['work_category'],
        'location' => trim($row['block_location'] . ' - ' . ($row['specific_location'] ?? '')),
        'description' => (string) $row['work_description'],
        'status' => (string) $row['work_status'],
        'verified' => $row['verified_at'] !== null,
        'verified_by' => $row['verified_by'] !== null ? (string) $row['verified_by'] : null,
        'verified_at' => $row['verified_at'] !== null ? (string) $row['verified_at'] : null,
        'supervisor_remarks' => (string) ($row['supervisor_remarks'] ?? ''),
        'work_order_reference' => (string) ($row['work_order_reference'] ?? ''),
        'images' => [],
    ];
}
$stmt->close();

// Photos are stored flat under cpms/uploads/daily_work/ (confirmed from
// staff/daily-work/submit.php's upload path) with no image_path column
// populated on this schema version — build the real, working URL from
// image_name the same way cpms/property_portal/daily_work_review.php
// (Property Admin's own review screen) already does, instead of guessing.
if ($ids && cpmsApiTableExists($db, 'daily_work_images')) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $imgStmt = $db->prepare(
        "SELECT daily_work_id, image_name, image_type
         FROM daily_work_images
         WHERE daily_work_id IN ({$placeholders})
         ORDER BY id ASC"
    );
    if ($imgStmt) {
        $imgStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $imgStmt->execute();
        $imgResult = $imgStmt->get_result();
        while ($image = $imgResult->fetch_assoc()) {
            $workId = (int) $image['daily_work_id'];
            if (!isset($rows[$workId])) {
                continue;
            }
            $name = basename((string) $image['image_name']);
            if ($name === '') {
                continue;
            }
            $rows[$workId]['images'][] = [
                'type' => (string) ($image['image_type'] ?? 'Supporting'),
                'url' => '/cpms/uploads/daily_work/' . rawurlencode($name),
            ];
        }
        $imgStmt->close();
    }
}

cpmsApiRespond(['logs' => array_values($rows)]);
