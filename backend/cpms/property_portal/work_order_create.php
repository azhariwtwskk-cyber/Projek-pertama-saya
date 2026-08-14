<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('work_orders.create');
require_once __DIR__ . '/includes/guards/guard_work_orders_create.php';
require_once __DIR__ . '/includes/workflow_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$reference = trim((string) ($_POST['complaint_id'] ?? ''));
$staffId = (int) ($_POST['assigned_staff_id'] ?? 0);
$scheduledDate = trim((string) ($_POST['scheduled_date'] ?? ''));
$dueDate = trim((string) ($_POST['due_date'] ?? ''));
$adminRemarks = trim((string) ($_POST['work_order_remarks'] ?? ''));
$token = $_POST['csrf_token'] ?? null;

if (
    !propertyPortalVerifyCsrf(is_string($token) ? $token : null)
    || $reference === ''
) {
    cpmsWorkflowFlash('danger', 'Invalid work order request.');
    propertyPortalRedirect(
        'complaint_view.php?ref=' . rawurlencode($reference)
    );
}

$complaint = cpmsWorkflowComplaint(
    $conn,
    $reference,
    $currentPropertyId
);

if (!$complaint) {
    http_response_code(404);
    exit('Complaint not found.');
}

$existing = $conn->prepare(
    "SELECT id, work_order_reference
     FROM work_orders
     WHERE property_id = ?
       AND complaint_id = ?
       AND status <> 'Cancelled'
     ORDER BY id DESC
     LIMIT 1"
);

if ($existing) {
    $existing->bind_param('is', $currentPropertyId, $reference);
    $existing->execute();
    $existingRow = $existing->get_result()->fetch_assoc();
    $existing->close();

    if ($existingRow) {
        cpmsWorkflowFlash(
            'danger',
            'An active work order already exists for this complaint: '
            . $existingRow['work_order_reference']
        );
        propertyPortalRedirect(
            'complaint_view.php?ref=' . rawurlencode($reference)
        );
    }
}

if ($staffId > 0) {
    $staffCheck = $conn->prepare(
        "SELECT id
         FROM staff
         WHERE id = ?
           AND property_id = ?
           AND account_status = 'Active'
         LIMIT 1"
    );

    if (!$staffCheck) {
        cpmsWorkflowFlash(
            'danger',
            'Unable to validate the selected staff member.'
        );
        propertyPortalRedirect(
            'complaint_view.php?ref=' . rawurlencode($reference)
        );
    }

    $staffCheck->bind_param('ii', $staffId, $currentPropertyId);
    $staffCheck->execute();
    $validStaff = $staffCheck->get_result()->fetch_assoc();
    $staffCheck->close();

    if (!$validStaff) {
        cpmsWorkflowFlash(
            'danger',
            'The selected staff member does not belong to this property.'
        );
        propertyPortalRedirect(
            'complaint_view.php?ref=' . rawurlencode($reference)
        );
    }
}

$workOrderReference = cpmsWorkflowReference();
$title = trim((string) ($complaint['subject'] ?? 'Complaint Work Order'));
$description = trim((string) ($complaint['description'] ?? ''));
$category = trim((string) ($complaint['category'] ?? 'General'));
$priority = cpmsWorkflowPriority(
    (string) ($complaint['priority'] ?? 'Medium')
);
$block = trim((string) ($complaint['block'] ?? 'General'));
$location = trim((string) (
    $complaint['location']
    ?? $complaint['unit_no']
    ?? ''
));
$createdBy = (string) $propertyPortalUser['full_name'];
$status = $staffId > 0 ? 'Assigned' : 'Open';

$scheduledDate = $scheduledDate !== '' ? $scheduledDate : date('Y-m-d');
$dueDate = $dueDate !== ''
    ? $dueDate
    : date('Y-m-d', strtotime('+7 days'));

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "INSERT INTO work_orders (
            property_id,
            work_order_reference,
            complaint_id,
            title,
            description,
            category,
            priority,
            block_location,
            specific_location,
            assigned_staff_id,
            scheduled_date,
            due_date,
            status,
            admin_remarks,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare work order.');
    }

    $stmt->bind_param(
        'issssssssisssss',
        $currentPropertyId,
        $workOrderReference,
        $reference,
        $title,
        $description,
        $category,
        $priority,
        $block,
        $location,
        $staffId,
        $scheduledDate,
        $dueDate,
        $status,
        $adminRemarks,
        $createdBy
    );
    $stmt->execute();
    $workOrderId = (int) $conn->insert_id;
    $stmt->close();

    $history = $conn->prepare(
        "INSERT INTO work_order_history (
            work_order_id,
            old_status,
            new_status,
            remarks,
            updated_by
        ) VALUES (?, NULL, ?, ?, ?)"
    );

    if (!$history) {
        throw new RuntimeException('Unable to prepare work order history.');
    }

    $historyRemarks = 'Created from complaint ' . $reference;
    if ($adminRemarks !== '') {
        $historyRemarks .= '. ' . $adminRemarks;
    }

    $history->bind_param(
        'isss',
        $workOrderId,
        $status,
        $historyRemarks,
        $createdBy
    );
    $history->execute();
    $history->close();

    $complaintStatus = 'In Progress';
    $complaintUpdate = $conn->prepare(
        "UPDATE complaints
         SET status = 'In Progress'
         WHERE complaint_id = ?
           AND property_id = ?"
    );

    if (!$complaintUpdate) {
        throw new RuntimeException('Unable to update complaint status.');
    }

    $complaintUpdate->bind_param(
        'si',
        $reference,
        $currentPropertyId
    );
    $complaintUpdate->execute();
    $complaintUpdate->close();

    $complaintHistory = $conn->prepare(
        "INSERT INTO cpms_complaint_history (
            property_id,
            complaint_id,
            old_status,
            new_status,
            remarks,
            action_type,
            work_order_id,
            changed_by_id,
            changed_by_name
        ) VALUES (?, ?, ?, ?, ?, 'WORK_ORDER_CREATED', ?, ?, ?)"
    );

    if (!$complaintHistory) {
        throw new RuntimeException('Unable to prepare complaint history.');
    }

    $oldComplaintStatus = (string) ($complaint['status'] ?? 'Pending');
    $historyNote = 'Work order ' . $workOrderReference . ' was created.';
    $adminId = (int) $propertyPortalUser['id'];

    $complaintHistory->bind_param(
        'issssiis',
        $currentPropertyId,
        $reference,
        $oldComplaintStatus,
        $complaintStatus,
        $historyNote,
        $workOrderId,
        $adminId,
        $createdBy
    );
    $complaintHistory->execute();
    $complaintHistory->close();

    $conn->commit();

    cpmsWorkflowFlash(
        'success',
        'Work order ' . $workOrderReference . ' was created successfully.'
    );
} catch (Throwable $e) {
    $conn->rollback();
    cpmsWorkflowFlash(
        'danger',
        'The work order could not be created. Confirm that Build 004 SQL was run.'
    );
}

propertyPortalRedirect(
    'complaint_view.php?ref=' . rawurlencode($reference)
);
