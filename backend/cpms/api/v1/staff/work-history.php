<?php
declare(strict_types=1);

/*
 * Work Order History (see mobile/docs/INTEGRATION_REPAIR_REPORT.md,
 * "Work Order History").
 *
 * This did not exist before this repair — `staff/tasks.php` only ever
 * returns *active* work orders (status NOT IN Verified/Cancelled), so
 * staff had no way to see what happened to a work order after they
 * submitted their Daily Work log against it. That decision genuinely
 * lives on `daily_work_logs` (`work_status`/`supervisor_remarks`/
 * `verified_by`/`verified_at`, set by the Property Admin's Daily Work
 * Review page — cpms/property_portal/daily_work_review.php), NOT on
 * `work_orders.status`, which only ever reaches `Completed` from the
 * staff side and is never flipped to `Verified`/`Rejected` by that
 * review page. This endpoint reads both tables and reconciles them so
 * Flutter can show the real management decision instead of guessing
 * from work_orders.status alone.
 *
 * Additive and read-only: no existing endpoint, table, or column is
 * changed by this file.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$staffId = cpmsApiStaffId($identity);

$hasWorkOrderId = cpmsApiColumnExists($db, 'daily_work_logs', 'work_order_id');
$hasSupervisorRemarks = cpmsApiColumnExists($db, 'daily_work_logs', 'supervisor_remarks');

// A work order belongs in History once it has actually been worked on —
// either its own status has moved past "just assigned", or the staff has
// submitted at least one Daily Work log against it (which is how
// "Completed" is actually reached — see staff/daily-work/submit.php).
$activityClause = "w.status IN ('Completed','Verified','Cancelled')";
if ($hasWorkOrderId) {
    $activityClause .= ' OR EXISTS (SELECT 1 FROM daily_work_logs dwx WHERE dwx.work_order_id = w.id)';
}

$stmt = $db->prepare(
    "SELECT w.id, w.work_order_reference, w.title, w.block_location, w.specific_location,
            w.priority, w.status, w.completion_notes, w.completed_at, w.due_date
     FROM work_orders w
     WHERE w.property_id = ? AND w.assigned_staff_id = ? AND ({$activityClause})
     ORDER BY COALESCE(w.completed_at, w.due_date) DESC, w.id DESC
     LIMIT 100"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare work order history.');
}
$stmt->bind_param('ii', $propertyId, $staffId);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
$orderIds = [];
while ($row = $result->fetch_assoc()) {
    $id = (int) $row['id'];
    $orderIds[] = $id;
    $location = trim((string) $row['block_location']);
    $specific = trim((string) ($row['specific_location'] ?? ''));
    if ($specific !== '' && strcasecmp($specific, $location) !== 0) {
        $location .= ' – ' . $specific;
    }
    $orders[$id] = [
        'id' => $id,
        'reference' => (string) $row['work_order_reference'],
        'title' => (string) $row['title'],
        'location' => $location,
        'priority' => (string) $row['priority'],
        'status' => (string) $row['status'],
        'completion_notes' => (string) ($row['completion_notes'] ?? ''),
        'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
        // Reconciled below from daily_work_logs — this is the field
        // Flutter should actually trust for Pending/Verified/Rejected.
        'verification_status' => (string) $row['status'] === 'Completed' ? 'pending_verification' : 'in_progress',
        'verified_by' => null,
        'verified_at' => null,
        'rejection_reason' => null,
        'daily_work_entries' => [],
        'images' => [],
    ];
}
$stmt->close();

if ($orderIds && $hasWorkOrderId) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = str_repeat('i', count($orderIds));
    $remarksSelect = $hasSupervisorRemarks ? 'd.supervisor_remarks,' : "'' AS supervisor_remarks,";
    $dwStmt = $db->prepare(
        "SELECT d.id, d.work_order_id, d.work_reference, d.work_date, d.work_description,
                d.work_status, d.verified_by, d.verified_at, {$remarksSelect} d.created_at
         FROM daily_work_logs d
         WHERE d.work_order_id IN ({$placeholders})
         ORDER BY d.id DESC"
    );
    if ($dwStmt) {
        $dwStmt->bind_param($types, ...$orderIds);
        $dwStmt->execute();
        $dwResult = $dwStmt->get_result();
        $dailyWorkIds = [];
        $entriesByOrder = [];
        while ($dw = $dwResult->fetch_assoc()) {
            $workOrderId = (int) $dw['work_order_id'];
            if (!isset($orders[$workOrderId])) {
                continue;
            }
            $dwId = (int) $dw['id'];
            $dailyWorkIds[] = $dwId;
            $entry = [
                'id' => $dwId,
                'reference' => (string) $dw['work_reference'],
                'date' => (string) $dw['work_date'],
                'description' => (string) $dw['work_description'],
                'status' => (string) $dw['work_status'],
                'verified_by' => $dw['verified_by'] !== null ? (string) $dw['verified_by'] : null,
                'verified_at' => $dw['verified_at'] !== null ? (string) $dw['verified_at'] : null,
                'supervisor_remarks' => (string) ($dw['supervisor_remarks'] ?? ''),
            ];
            $orders[$workOrderId]['daily_work_entries'][] = $entry;
            $entriesByOrder[$workOrderId] ??= [];
            $entriesByOrder[$workOrderId][] = $entry;
        }
        $dwStmt->close();

        // The most recent Daily Work entry's decision is authoritative
        // for the work order as a whole — if staff resubmitted after a
        // rejection, the newer entry's Verified/Rejected/pending state
        // is what actually reflects reality today.
        foreach ($entriesByOrder as $workOrderId => $entries) {
            $latest = $entries[0];
            if ($latest['status'] === 'Verified') {
                $orders[$workOrderId]['verification_status'] = 'verified';
                $orders[$workOrderId]['verified_by'] = $latest['verified_by'];
                $orders[$workOrderId]['verified_at'] = $latest['verified_at'];
            } elseif ($latest['status'] === 'Rejected') {
                $orders[$workOrderId]['verification_status'] = 'rejected';
                $orders[$workOrderId]['rejection_reason'] = $latest['supervisor_remarks'];
            } elseif ($latest['status'] === 'Completed') {
                $orders[$workOrderId]['verification_status'] = 'pending_verification';
            }
        }

        if ($dailyWorkIds && cpmsApiTableExists($db, 'daily_work_images')) {
            $imgPlaceholders = implode(',', array_fill(0, count($dailyWorkIds), '?'));
            $imgStmt = $db->prepare(
                "SELECT daily_work_id, image_name, image_type
                 FROM daily_work_images
                 WHERE daily_work_id IN ({$imgPlaceholders})
                 ORDER BY id ASC"
            );
            if ($imgStmt) {
                $imgStmt->bind_param(str_repeat('i', count($dailyWorkIds)), ...$dailyWorkIds);
                $imgStmt->execute();
                $imgResult = $imgStmt->get_result();
                // Map daily_work_id -> work_order_id so an image can be
                // filed under the right order's photo grid.
                $dwToOrder = [];
                foreach ($entriesByOrder as $workOrderId => $entries) {
                    foreach ($entries as $entry) {
                        $dwToOrder[$entry['id']] = $workOrderId;
                    }
                }
                while ($image = $imgResult->fetch_assoc()) {
                    $dwId = (int) $image['daily_work_id'];
                    $workOrderId = $dwToOrder[$dwId] ?? null;
                    if ($workOrderId === null || !isset($orders[$workOrderId])) {
                        continue;
                    }
                    $name = basename((string) $image['image_name']);
                    if ($name === '') {
                        continue;
                    }
                    $orders[$workOrderId]['images'][] = [
                        'type' => (string) ($image['image_type'] ?? 'Supporting'),
                        'url' => '/cpms/uploads/daily_work/' . rawurlencode($name),
                    ];
                }
                $imgStmt->close();
            }
        }
    }
}

// work_order_images (staff/task-photo.php uploads) are a separate,
// append-only evidence bucket keyed directly by work_order_id — always
// merge them in alongside any Daily Work photos above.
if ($orderIds && cpmsApiTableExists($db, 'work_order_images')) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $woImgStmt = $db->prepare(
        "SELECT work_order_id, image_name, image_type
         FROM work_order_images
         WHERE work_order_id IN ({$placeholders})
         ORDER BY id ASC"
    );
    if ($woImgStmt) {
        $woImgStmt->bind_param(str_repeat('i', count($orderIds)), ...$orderIds);
        $woImgStmt->execute();
        $woImgResult = $woImgStmt->get_result();
        while ($image = $woImgResult->fetch_assoc()) {
            $workOrderId = (int) $image['work_order_id'];
            if (!isset($orders[$workOrderId])) {
                continue;
            }
            $path = ltrim((string) $image['image_name'], '/');
            if ($path === '') {
                continue;
            }
            $orders[$workOrderId]['images'][] = [
                'type' => (string) ($image['image_type'] ?? 'Supporting'),
                'url' => '/cpms/' . implode('/', array_map('rawurlencode', explode('/', $path))),
            ];
        }
        $woImgStmt->close();
    }
}

cpmsApiRespond(['work_orders' => array_values($orders)]);
