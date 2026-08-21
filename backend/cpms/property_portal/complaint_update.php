<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

cpmsPropertyRequire('complaints.update');
require_once __DIR__ . '/includes/guards/guard_complaints_update.php';
require_once __DIR__ . '/includes/workflow_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$reference = trim((string) ($_POST['complaint_id'] ?? ''));
$status = trim((string) ($_POST['status'] ?? ''));
$remarks = trim((string) ($_POST['admin_remarks'] ?? ''));
$token = $_POST['csrf_token'] ?? null;

$allowedStatuses = [
    'Pending',
    'In Progress',
    'Resolved',
    'Closed',
];

if (
    !propertyPortalVerifyCsrf(is_string($token) ? $token : null)
    || $reference === ''
    || !in_array($status, $allowedStatuses, true)
) {
    cpmsWorkflowFlash('danger', 'Invalid status update request.');
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

$oldStatus = (string) ($complaint['status'] ?? 'Pending');
$changedById = (int) $propertyPortalUser['id'];
$changedByName = (string) $propertyPortalUser['full_name'];

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "UPDATE complaints
         SET status = ?,
             admin_remarks = ?
         WHERE complaint_id = ?
           AND property_id = ?"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare complaint update.');
    }

    $stmt->bind_param(
        'sssi',
        $status,
        $remarks,
        $reference,
        $currentPropertyId
    );
    $stmt->execute();

    if ($stmt->affected_rows < 0) {
        throw new RuntimeException('Complaint update failed.');
    }

    $stmt->close();

    $history = $conn->prepare(
        "INSERT INTO cpms_complaint_history (
            property_id,
            complaint_id,
            old_status,
            new_status,
            remarks,
            action_type,
            changed_by_id,
            changed_by_name
        ) VALUES (?, ?, ?, ?, ?, 'STATUS_UPDATE', ?, ?)"
    );

    if (!$history) {
        throw new RuntimeException('Unable to prepare complaint history.');
    }

    $history->bind_param(
        'issssis',
        $currentPropertyId,
        $reference,
        $oldStatus,
        $status,
        $remarks,
        $changedById,
        $changedByName
    );
    $history->execute();
    $history->close();

    $conn->commit();

    cpmsWorkflowFlash(
        'success',
        'Complaint status and administrative remarks were updated.'
    );
} catch (Throwable $e) {
    $conn->rollback();
    cpmsWorkflowFlash(
        'danger',
        'The complaint could not be updated. Confirm that Build 004 SQL was run.'
    );
}

propertyPortalRedirect(
    'complaint_view.php?ref=' . rawurlencode($reference)
);
