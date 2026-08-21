<?php
declare(strict_types=1);

function cpmsActionEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsActionCsrfToken(): string
{
    if (empty($_SESSION['inspection_action_csrf'])) {
        $_SESSION['inspection_action_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['inspection_action_csrf'];
}

function cpmsActionVerifyCsrf(?string $token): bool
{
    $stored = (string) ($_SESSION['inspection_action_csrf'] ?? '');
    return $stored !== ''
        && $token !== null
        && hash_equals($stored, $token);
}

function cpmsActionCurrentUserId(): int
{
    return (int) ($_SESSION['cpms_user_id'] ?? 0);
}

function cpmsActionCurrentUserName(): string
{
    foreach ([
        $_SESSION['property_admin_name'] ?? null,
        $_SESSION['staff_name'] ?? null,
        $_SESSION['cpms_user_name'] ?? null,
    ] as $candidate) {
        $name = trim((string) $candidate);
        if ($name !== '') {
            return $name;
        }
    }

    return 'CPMS User';
}

function cpmsActionGenerateNumber(
    mysqli $conn,
    int $propertyId
): string {
    $prefix = 'CA-' . $propertyId . '-' . date('ymd');
    $stmt = $conn->prepare(
        "SELECT action_no
         FROM inspection_corrective_actions
         WHERE property_id = ?
           AND action_no LIKE CONCAT(?, '-%')
         ORDER BY id DESC
         LIMIT 1"
    );
    $sequence = 1;

    if ($stmt) {
        $stmt->bind_param('is', $propertyId, $prefix);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['action_no'])) {
            $parts = explode('-', (string) $row['action_no']);
            $sequence = ((int) end($parts)) + 1;
        }
    }

    return $prefix . '-' . str_pad(
        (string) $sequence,
        4,
        '0',
        STR_PAD_LEFT
    );
}

function cpmsActionAssignees(
    mysqli $conn,
    int $propertyId
): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT
            u.id,
            u.full_name,
            u.source_id,
            r.role_code
         FROM system_users u
         INNER JOIN user_roles ur
            ON ur.system_user_id = u.id
           AND ur.property_id = ?
           AND ur.status = 'active'
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.role_code IN ('staff', 'contractor')
           AND r.status = 'active'
         WHERE u.property_id = ?
           AND u.status = 'active'
         ORDER BY r.role_code, u.full_name"
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $propertyId, $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsActionFindAssignee(
    mysqli $conn,
    int $propertyId,
    int $systemUserId
): ?array {
    foreach (cpmsActionAssignees($conn, $propertyId) as $assignee) {
        if ((int) $assignee['id'] === $systemUserId) {
            return $assignee;
        }
    }
    return null;
}

function cpmsActionCreate(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    array $data
): int {
    $inspectionStmt = $conn->prepare(
        'SELECT id, inspection_no, status
         FROM inspection_reports
         WHERE id = ? AND property_id = ?
         LIMIT 1'
    );
    if (!$inspectionStmt) {
        throw new RuntimeException('Unable to validate inspection.');
    }
    $inspectionStmt->bind_param('ii', $inspectionId, $propertyId);
    $inspectionStmt->execute();
    $inspection = $inspectionStmt->get_result()->fetch_assoc();
    $inspectionStmt->close();

    if (!$inspection) {
        throw new RuntimeException('Inspection record not found.');
    }

    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $priority = trim((string) ($data['priority'] ?? 'Medium'));
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    $assignedUserId = (int) ($data['assigned_system_user_id'] ?? 0);

    if ($title === '' || $description === '') {
        throw new InvalidArgumentException(
            'Action title and instructions are required.'
        );
    }

    if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'], true)) {
        $priority = 'Medium';
    }

    $assignee = cpmsActionFindAssignee(
        $conn,
        $propertyId,
        $assignedUserId
    );
    if (!$assignee) {
        throw new InvalidArgumentException(
            'Select a valid Staff or Contractor for this property.'
        );
    }

    $actionNo = cpmsActionGenerateNumber($conn, $propertyId);
    $assignedType = (string) $assignee['role_code'];
    $assignedLegacyId = (int) ($assignee['source_id'] ?? 0);
    $assignedName = (string) $assignee['full_name'];
    $createdById = cpmsActionCurrentUserId();
    $createdByName = cpmsActionCurrentUserName();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO inspection_corrective_actions (
                inspection_id, property_id, action_no, title,
                description, assigned_type, assigned_system_user_id,
                assigned_legacy_id, assigned_name, priority,
                due_date, status, created_by_user_id, created_by_name
             ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?,
                NULLIF(?, ""), "Open", NULLIF(?, 0), ?
             )'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare corrective action.');
        }
        $stmt->bind_param(
            'iissssiisssis',
            $inspectionId,
            $propertyId,
            $actionNo,
            $title,
            $description,
            $assignedType,
            $assignedUserId,
            $assignedLegacyId,
            $assignedName,
            $priority,
            $dueDate,
            $createdById,
            $createdByName
        );
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException(
                'Unable to create corrective action: ' . $message
            );
        }
        $actionId = (int) $stmt->insert_id;
        $stmt->close();

        $update = $conn->prepare(
            'UPDATE inspection_reports
             SET due_date = CASE
                    WHEN NULLIF(?, "") IS NULL THEN due_date
                    WHEN due_date IS NULL OR due_date > ? THEN ?
                    ELSE due_date
                 END,
                 status = "Action Required"
             WHERE id = ? AND property_id = ?'
        );
        if ($update) {
            $update->bind_param(
                'sssii',
                $dueDate,
                $dueDate,
                $dueDate,
                $inspectionId,
                $propertyId
            );
            $update->execute();
            $update->close();
        }

        if (function_exists('cpmsInspectionAddHistory')) {
            cpmsInspectionAddHistory(
                $conn,
                $inspectionId,
                $propertyId,
                (string) $inspection['status'],
                'Action Required',
                'Corrective Action ' . $actionNo
                    . ' assigned to ' . $assignedName . '.',
                $createdById,
                $createdByName
            );
        }

        cpmsActionAudit(
            $conn,
            $propertyId,
            'corrective_action_created',
            $actionNo . ' assigned to ' . $assignedName
        );
        $conn->commit();
        return $actionId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function cpmsActionFind(
    mysqli $conn,
    int $propertyId,
    int $actionId
): ?array {
    $stmt = $conn->prepare(
        'SELECT a.*, r.inspection_no, r.location,
                r.category, r.finding, r.recommendation
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r
            ON r.id = a.inspection_id
           AND r.property_id = a.property_id
         WHERE a.id = ? AND a.property_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $actionId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsActionsByInspection(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_corrective_actions
         WHERE property_id = ? AND inspection_id = ?
         ORDER BY id DESC'
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

function cpmsActionUpdateStatus(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    string $status,
    string $notes
): bool {
    $allowed = ['Open', 'In Progress', 'Rectified', 'Verified', 'Closed'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Invalid action status.');
    }

    $userId = cpmsActionCurrentUserId();
    $userName = cpmsActionCurrentUserName();
    $extra = '';

    if ($status === 'Rectified') {
        $extra = ', rectified_by_user_id = NULLIF(?, 0),
                    rectified_by_name = ?, rectified_at = NOW()';
    } elseif (in_array($status, ['Verified', 'Closed'], true)) {
        $extra = ', verified_by_user_id = NULLIF(?, 0),
                    verified_by_name = ?, verified_at = NOW()';
    }

    $sql = 'UPDATE inspection_corrective_actions
            SET status = ?, rectification_notes = NULLIF(?, "")'
        . $extra
        . ' WHERE id = ? AND property_id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($extra !== '') {
        $stmt->bind_param(
            'ssissi',
            $status,
            $notes,
            $userId,
            $userName,
            $actionId,
            $propertyId
        );
    } else {
        $stmt->bind_param(
            'ssii',
            $status,
            $notes,
            $actionId,
            $propertyId
        );
    }

    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        cpmsActionAudit(
            $conn,
            $propertyId,
            'corrective_action_status',
            'Action ID ' . $actionId . ' changed to ' . $status
        );
    }
    return $ok;
}

function cpmsActionImages(
    mysqli $conn,
    int $propertyId,
    int $actionId
): array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_action_images
         WHERE property_id = ? AND action_id = ?
         ORDER BY id ASC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $actionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsActionStoreImage(
    mysqli $conn,
    int $propertyId,
    array $action,
    array $file,
    string $phase,
    string $caption,
    string $uploadRoot
): int {
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Select a valid image.');
    }
    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('Image exceeds the 8 MB limit.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Uploaded image is invalid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG and WebP are allowed.');
    }
    $phases = ['Before', 'During', 'After', 'Evidence'];
    if (!in_array($phase, $phases, true)) {
        $phase = 'Evidence';
    }

    $relativeDirectory = 'property_' . $propertyId
        . '/action_' . (int) $action['id'];
    $directory = rtrim($uploadRoot, '/\\')
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Unable to create evidence directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Unable to save evidence image.');
    }

    $path = 'uploads/inspection_actions/'
        . $relativeDirectory . '/' . $filename;
    $originalName = basename((string) ($file['name'] ?? 'image'));
    $fileSize = (int) ($file['size'] ?? 0);
    $userId = cpmsActionCurrentUserId();
    $userName = cpmsActionCurrentUserName();
    $actionId = (int) $action['id'];
    $inspectionId = (int) $action['inspection_id'];

    $stmt = $conn->prepare(
        'INSERT INTO inspection_action_images (
            action_id, inspection_id, property_id, image_phase,
            image_path, original_name, mime_type, file_size,
            caption, uploaded_by_user_id, uploaded_by_name
         ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ""),
            NULLIF(?, 0), ?
         )'
    );
    if (!$stmt) {
        @unlink($target);
        throw new RuntimeException('Unable to prepare evidence record.');
    }
    $stmt->bind_param(
        'iiissssisis',
        $actionId,
        $inspectionId,
        $propertyId,
        $phase,
        $path,
        $originalName,
        $mime,
        $fileSize,
        $caption,
        $userId,
        $userName
    );
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        @unlink($target);
        throw new RuntimeException('Unable to save evidence: ' . $message);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    cpmsActionAudit(
        $conn,
        $propertyId,
        'corrective_action_evidence',
        'Evidence uploaded for ' . (string) $action['action_no']
    );
    return $id;
}

function cpmsActionAudit(
    mysqli $conn,
    int $propertyId,
    string $event,
    string $description
): void {
    $userId = cpmsActionCurrentUserId();
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $stmt = $conn->prepare(
        "INSERT INTO cpms_auth_audit_logs (
            user_type, user_id, property_id, event_type,
            description, ip_address, user_agent
         ) VALUES (
            'system_user', NULLIF(?, 0), ?, ?, ?, ?, ?
         )"
    );
    if ($stmt) {
        $stmt->bind_param(
            'iissss',
            $userId,
            $propertyId,
            $event,
            $description,
            $ip,
            $agent
        );
        $stmt->execute();
        $stmt->close();
    }
}
