<?php
declare(strict_types=1);

function cpmsFailedChecklistItems(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    $stmt = $conn->prepare(
        "SELECT i.*, a.id AS assessment_id,
                l.corrective_action_id,
                ca.action_no, ca.status AS action_status
         FROM inspection_checklist_assessments a
         INNER JOIN inspection_checklist_assessment_items i
            ON i.assessment_id = a.id
           AND i.property_id = a.property_id
         LEFT JOIN inspection_checklist_action_links l
            ON l.assessment_item_id = i.id
           AND l.property_id = i.property_id
         LEFT JOIN inspection_corrective_actions ca
            ON ca.id = l.corrective_action_id
           AND ca.property_id = l.property_id
         WHERE a.property_id = ?
           AND a.inspection_id = ?
           AND i.result = 'Fail'
         ORDER BY i.item_order, i.id"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $inspectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsCreateActionsFromFailedItems(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    array $selectedItemIds,
    array $settings
): array {
    $available = cpmsFailedChecklistItems(
        $conn,
        $propertyId,
        $inspectionId
    );
    $allowed = [];
    foreach ($available as $item) {
        if (empty($item['corrective_action_id'])) {
            $allowed[(int) $item['id']] = $item;
        }
    }

    $selected = [];
    foreach ($selectedItemIds as $itemId) {
        $id = (int) $itemId;
        if (isset($allowed[$id])) {
            $selected[$id] = $allowed[$id];
        }
    }
    if (!$selected) {
        throw new InvalidArgumentException(
            'Select at least one failed item without an existing action.'
        );
    }

    $assigneeId = (int) (
        $settings['assigned_system_user_id']
        ?? 0
    );
    if (!cpmsActionFindAssignee($conn, $propertyId, $assigneeId)) {
        throw new InvalidArgumentException(
            'Select a valid Staff or Contractor for this property.'
        );
    }
    $priority = trim((string) ($settings['priority'] ?? 'Medium'));
    $dueDate = trim((string) ($settings['due_date'] ?? ''));
    if (!in_array(
        $priority,
        ['Low', 'Medium', 'High', 'Critical'],
        true
    )) {
        $priority = 'Medium';
    }
    if ($dueDate === '') {
        throw new InvalidArgumentException('Due date is required.');
    }

    $created = [];
    foreach ($selected as $item) {
        $remarks = trim((string) ($item['remarks'] ?? ''));
        $description = 'Rectify failed inspection checklist item: '
            . (string) $item['item_name'];
        if ($remarks !== '') {
            $description .= "\n\nInspection remarks: " . $remarks;
        }
        $description .= "\n\nGenerated automatically from checklist assessment.";

        $actionId = cpmsActionCreate(
            $conn,
            $propertyId,
            $inspectionId,
            [
                'title' => 'Rectify: ' . (string) $item['item_name'],
                'description' => $description,
                'assigned_system_user_id' => $assigneeId,
                'priority' => $priority,
                'due_date' => $dueDate,
            ]
        );

        $link = $conn->prepare(
            'INSERT INTO inspection_checklist_action_links (
                property_id, inspection_id, assessment_id,
                assessment_item_id, corrective_action_id,
                created_by_user_id, created_by_name
             ) VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?)'
        );
        if (!$link) {
            throw new RuntimeException(
                'Corrective Action created but checklist link failed.'
            );
        }
        $assessmentId = (int) $item['assessment_id'];
        $assessmentItemId = (int) $item['id'];
        $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
        $userName = trim((string) (
            $_SESSION['property_admin_name']
            ?? $_SESSION['cpms_user_name']
            ?? 'CPMS User'
        ));
        $link->bind_param(
            'iiiiiis',
            $propertyId,
            $inspectionId,
            $assessmentId,
            $assessmentItemId,
            $actionId,
            $userId,
            $userName
        );
        if (!$link->execute()) {
            $message = $link->error;
            $link->close();
            throw new RuntimeException(
                'Corrective Action created but linking failed: ' . $message
            );
        }
        $link->close();
        $created[] = $actionId;
    }
    return $created;
}
