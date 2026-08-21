<?php
declare(strict_types=1);

function cpmsChecklistEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsChecklistCsrfToken(): string
{
    if (empty($_SESSION['cpms_checklist_csrf'])) {
        $_SESSION['cpms_checklist_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_checklist_csrf'];
}

function cpmsChecklistVerifyCsrf(?string $token): bool
{
    $stored = (string) ($_SESSION['cpms_checklist_csrf'] ?? '');
    return $stored !== '' && $token !== null
        && hash_equals($stored, $token);
}

function cpmsChecklistTemplates(mysqli $conn, int $propertyId): array
{
    $stmt = $conn->prepare(
        "SELECT t.*,
                COUNT(i.id) AS item_count,
                COALESCE(SUM(i.weight), 0) AS total_weight
         FROM inspection_checklist_templates t
         LEFT JOIN inspection_checklist_template_items i
            ON i.template_id = t.id
         WHERE t.property_id = ?
         GROUP BY t.id
         ORDER BY t.status = 'active' DESC, t.template_name"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsChecklistCreateTemplate(
    mysqli $conn,
    int $propertyId,
    array $data,
    array $items
): int {
    $name = trim((string) ($data['template_name'] ?? ''));
    $category = trim((string) ($data['category'] ?? 'General'));
    $description = trim((string) ($data['description'] ?? ''));
    $passing = (float) ($data['passing_score'] ?? 80);
    if ($name === '' || !$items) {
        throw new InvalidArgumentException(
            'Template name and at least one checklist item are required.'
        );
    }
    if ($passing < 1 || $passing > 100) {
        throw new InvalidArgumentException(
            'Passing score must be between 1 and 100.'
        );
    }
    $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
    $userName = trim((string) (
        $_SESSION['property_admin_name']
        ?? $_SESSION['cpms_user_name']
        ?? 'CPMS User'
    ));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO inspection_checklist_templates (
                property_id, template_name, category, description,
                passing_score, status, created_by_user_id,
                created_by_name
             ) VALUES (?, ?, ?, NULLIF(?, ''), ?, 'active',
                       NULLIF(?, 0), ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare template.');
        }
        $stmt->bind_param(
            'isssdis',
            $propertyId,
            $name,
            $category,
            $description,
            $passing,
            $userId,
            $userName
        );
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $templateId = (int) $stmt->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare(
            'INSERT INTO inspection_checklist_template_items (
                template_id, property_id, item_order, item_name,
                weight, is_required
             ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$itemStmt) {
            throw new RuntimeException('Unable to prepare template items.');
        }
        $order = 0;
        foreach ($items as $item) {
            $order++;
            $itemName = trim((string) ($item['name'] ?? ''));
            $weight = (float) ($item['weight'] ?? 1);
            $required = !empty($item['required']) ? 1 : 0;
            if ($itemName === '' || $weight <= 0) {
                continue;
            }
            $itemStmt->bind_param(
                'iiisdi',
                $templateId,
                $propertyId,
                $order,
                $itemName,
                $weight,
                $required
            );
            if (!$itemStmt->execute()) {
                throw new RuntimeException($itemStmt->error);
            }
        }
        $itemStmt->close();
        $conn->commit();
        return $templateId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function cpmsChecklistAssessment(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): ?array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_checklist_assessments
         WHERE property_id = ? AND inspection_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $propertyId, $inspectionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsChecklistAssessmentItems(
    mysqli $conn,
    int $propertyId,
    int $assessmentId
): array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_checklist_assessment_items
         WHERE property_id = ? AND assessment_id = ?
         ORDER BY item_order, id'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $assessmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsChecklistApply(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $templateId
): int {
    $inspectionStmt = $conn->prepare(
        'SELECT id FROM inspection_reports
         WHERE id = ? AND property_id = ? LIMIT 1'
    );
    $inspectionStmt->bind_param('ii', $inspectionId, $propertyId);
    $inspectionStmt->execute();
    $inspection = $inspectionStmt->get_result()->fetch_assoc();
    $inspectionStmt->close();
    if (!$inspection) {
        throw new RuntimeException('Inspection record not found.');
    }

    $templateStmt = $conn->prepare(
        "SELECT * FROM inspection_checklist_templates
         WHERE id = ? AND property_id = ? AND status = 'active'
         LIMIT 1"
    );
    $templateStmt->bind_param('ii', $templateId, $propertyId);
    $templateStmt->execute();
    $template = $templateStmt->get_result()->fetch_assoc();
    $templateStmt->close();
    if (!$template) {
        throw new RuntimeException('Checklist template not found.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO inspection_checklist_assessments (
                inspection_id, property_id, template_id,
                template_name, passing_score
             ) VALUES (?, ?, ?, ?, ?)'
        );
        $passing = (float) $template['passing_score'];
        $name = (string) $template['template_name'];
        $stmt->bind_param(
            'iiisd',
            $inspectionId,
            $propertyId,
            $templateId,
            $name,
            $passing
        );
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $assessmentId = (int) $stmt->insert_id;
        $stmt->close();

        $copy = $conn->prepare(
            "INSERT INTO inspection_checklist_assessment_items (
                assessment_id, property_id, template_item_id,
                item_order, item_name, weight, is_required
             )
             SELECT ?, property_id, id, item_order, item_name,
                    weight, is_required
             FROM inspection_checklist_template_items
             WHERE template_id = ? AND property_id = ?
             ORDER BY item_order"
        );
        $copy->bind_param(
            'iii',
            $assessmentId,
            $templateId,
            $propertyId
        );
        if (!$copy->execute() || $copy->affected_rows < 1) {
            throw new RuntimeException('Template has no checklist item.');
        }
        $copy->close();
        $conn->commit();
        return $assessmentId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function cpmsChecklistScore(
    mysqli $conn,
    int $propertyId,
    array $assessment,
    array $results,
    array $remarks
): array {
    $items = cpmsChecklistAssessmentItems(
        $conn,
        $propertyId,
        (int) $assessment['id']
    );
    if (!$items) {
        throw new RuntimeException('Checklist items are unavailable.');
    }
    $earned = 0.0;
    $maximum = 0.0;
    $requiredFail = false;
    $allowed = ['Pass', 'Fail', 'N/A'];

    $conn->begin_transaction();
    try {
        $update = $conn->prepare(
            "UPDATE inspection_checklist_assessment_items
             SET result = ?, remarks = NULLIF(?, ''),
                 score_awarded = ?
             WHERE id = ? AND property_id = ? AND assessment_id = ?"
        );
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $result = (string) ($results[$id] ?? 'Pending');
            if (!in_array($result, $allowed, true)) {
                throw new RuntimeException(
                    'Complete every checklist item before saving.'
                );
            }
            $weight = (float) $item['weight'];
            $score = $result === 'Pass' ? $weight : 0.0;
            if ($result !== 'N/A') {
                $maximum += $weight;
                $earned += $score;
            }
            if ($result === 'Fail' && (int) $item['is_required'] === 1) {
                $requiredFail = true;
            }
            $note = trim((string) ($remarks[$id] ?? ''));
            $assessmentId = (int) $assessment['id'];
            $update->bind_param(
                'ssdiii',
                $result,
                $note,
                $score,
                $id,
                $propertyId,
                $assessmentId
            );
            if (!$update->execute()) {
                throw new RuntimeException($update->error);
            }
        }
        $update->close();

        $percent = $maximum > 0
            ? round(($earned / $maximum) * 100, 2)
            : 0.0;
        $overall = !$requiredFail
            && $percent >= (float) $assessment['passing_score']
            ? 'Compliant'
            : 'Non-Compliant';
        $userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
        $userName = trim((string) (
            $_SESSION['property_admin_name']
            ?? $_SESSION['cpms_user_name']
            ?? 'CPMS User'
        ));
        $assessmentId = (int) $assessment['id'];
        $save = $conn->prepare(
            "UPDATE inspection_checklist_assessments
             SET earned_score = ?, maximum_score = ?,
                 score_percent = ?, overall_result = ?,
                 assessed_by_user_id = NULLIF(?, 0),
                 assessed_by_name = ?, assessed_at = NOW()
             WHERE id = ? AND property_id = ?"
        );
        $save->bind_param(
            'dddsisii',
            $earned,
            $maximum,
            $percent,
            $overall,
            $userId,
            $userName,
            $assessmentId,
            $propertyId
        );
        if (!$save->execute()) {
            throw new RuntimeException($save->error);
        }
        $save->close();
        $conn->commit();
        return ['percent' => $percent, 'result' => $overall];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
