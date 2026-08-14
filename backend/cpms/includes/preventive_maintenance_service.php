<?php
declare(strict_types=1);

function cpmsPmEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsPmCsrfToken(): string
{
    if (empty($_SESSION['cpms_pm_csrf'])) {
        $_SESSION['cpms_pm_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_pm_csrf'];
}

function cpmsPmVerifyCsrf(?string $token): bool
{
    $stored = (string) ($_SESSION['cpms_pm_csrf'] ?? '');
    return $stored !== '' && $token !== null
        && hash_equals($stored, $token);
}

function cpmsPmCurrentUserId(): int
{
    return (int) ($_SESSION['cpms_user_id'] ?? 0);
}

function cpmsPmCurrentUserName(): string
{
    return trim((string) (
        $_SESSION['property_admin_name']
        ?? $_SESSION['staff_name']
        ?? $_SESSION['cpms_user_name']
        ?? 'CPMS User'
    ));
}

function cpmsPmAssignees(mysqli $conn, int $propertyId): array
{
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.id, u.full_name, r.role_code
         FROM system_users u
         INNER JOIN user_roles ur
            ON ur.system_user_id = u.id
           AND ur.property_id = ?
           AND ur.status = 'active'
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.role_code IN ('staff','contractor')
           AND r.status = 'active'
         WHERE u.status = 'active'
           AND u.property_id = ?
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

function cpmsPmFindAssignee(
    mysqli $conn,
    int $propertyId,
    int $userId
): ?array {
    foreach (cpmsPmAssignees($conn, $propertyId) as $assignee) {
        if ((int) $assignee['id'] === $userId) {
            return $assignee;
        }
    }
    return null;
}

function cpmsPmNextDueDate(
    string $fromDate,
    string $unit,
    int $interval
): string {
    $interval = max(1, $interval);
    $date = new DateTimeImmutable($fromDate);
    $units = [
        'daily' => 'days',
        'weekly' => 'weeks',
        'monthly' => 'months',
        'yearly' => 'years',
    ];
    $plural = $units[$unit] ?? 'days';
    return $date->modify('+' . $interval . ' ' . $plural)
        ->format('Y-m-d');
}

function cpmsPmCreateSchedule(
    mysqli $conn,
    int $propertyId,
    array $data
): int {
    $assetName = trim((string) ($data['asset_name'] ?? ''));
    $scheduleName = trim((string) ($data['schedule_name'] ?? ''));
    $type = trim((string) ($data['maintenance_type'] ?? 'Preventive'));
    $unit = trim((string) ($data['frequency_unit'] ?? 'monthly'));
    $interval = max(1, (int) ($data['frequency_interval'] ?? 1));
    $nextDue = trim((string) ($data['next_due_date'] ?? ''));
    $priority = trim((string) ($data['priority'] ?? 'Medium'));
    $assigneeId = (int) ($data['assigned_system_user_id'] ?? 0);
    $vendor = trim((string) ($data['vendor_name'] ?? ''));
    $instructions = trim((string) ($data['instructions'] ?? ''));
    $estimatedCost = (float) ($data['estimated_cost'] ?? 0);
    $assetId = (int) ($data['asset_id'] ?? 0);

    if ($assetName === '' || $scheduleName === '' || $nextDue === '') {
        throw new InvalidArgumentException(
            'Asset, schedule name and first due date are required.'
        );
    }
    if (!in_array($unit, ['daily','weekly','monthly','yearly'], true)) {
        $unit = 'monthly';
    }
    if (!in_array($priority, ['Low','Medium','High','Critical'], true)) {
        $priority = 'Medium';
    }
    $assigneeName = '';
    if ($assigneeId > 0) {
        $assignee = cpmsPmFindAssignee(
            $conn,
            $propertyId,
            $assigneeId
        );
        if (!$assignee) {
            throw new InvalidArgumentException(
                'Select a valid Staff or Contractor.'
            );
        }
        $assigneeName = (string) $assignee['full_name'];
    }
    $userId = cpmsPmCurrentUserId();
    $userName = cpmsPmCurrentUserName();

    $stmt = $conn->prepare(
        "INSERT INTO cpms_pm_schedules (
            property_id, asset_id, asset_name, schedule_name,
            maintenance_type, frequency_unit, frequency_interval,
            next_due_date, priority, assigned_system_user_id,
            assigned_name, vendor_name, estimated_cost,
            instructions, status, created_by_user_id, created_by_name
         ) VALUES (
            ?, NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?,
            NULLIF(?,0), NULLIF(?,''), NULLIF(?,''), NULLIF(?,0),
            NULLIF(?,''), 'active', NULLIF(?,0), ?
         )"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare maintenance schedule.');
    }
    $stmt->bind_param(
        'iissssississdsis',
        $propertyId,
        $assetId,
        $assetName,
        $scheduleName,
        $type,
        $unit,
        $interval,
        $nextDue,
        $priority,
        $assigneeId,
        $assigneeName,
        $vendor,
        $estimatedCost,
        $instructions,
        $userId,
        $userName
    );
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException($message);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function cpmsPmFindSchedule(
    mysqli $conn,
    int $propertyId,
    int $scheduleId
): ?array {
    $stmt = $conn->prepare(
        "SELECT s.*,
            CASE
                WHEN s.status = 'active'
                 AND s.next_due_date < CURDATE() THEN 'Overdue'
                WHEN s.status = 'active'
                 AND s.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    THEN 'Due Soon'
                ELSE s.status
            END AS due_status
         FROM cpms_pm_schedules s
         WHERE s.id = ? AND s.property_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $scheduleId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsPmSchedules(
    mysqli $conn,
    int $propertyId,
    string $filter = ''
): array {
    $condition = '';
    if ($filter === 'overdue') {
        $condition = " AND s.status = 'active'
                       AND s.next_due_date < CURDATE()";
    } elseif ($filter === 'due_soon') {
        $condition = " AND s.status = 'active'
                       AND s.next_due_date BETWEEN CURDATE()
                       AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($filter === 'active') {
        $condition = " AND s.status = 'active'";
    }
    $stmt = $conn->prepare(
        "SELECT s.*,
            CASE
                WHEN s.status = 'active'
                 AND s.next_due_date < CURDATE() THEN 'Overdue'
                WHEN s.status = 'active'
                 AND s.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                    THEN 'Due Soon'
                ELSE s.status
            END AS due_status
         FROM cpms_pm_schedules s
         WHERE s.property_id = ? {$condition}
         ORDER BY
            s.status = 'active' DESC,
            s.next_due_date ASC, s.id DESC"
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

function cpmsPmSummary(mysqli $conn, int $propertyId): array
{
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'active') AS active_count,
            SUM(status = 'active' AND next_due_date < CURDATE())
                AS overdue_count,
            SUM(status = 'active'
                AND next_due_date BETWEEN CURDATE()
                AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                AS due_soon_count
         FROM cpms_pm_schedules
         WHERE property_id = ?"
    );
    if (!$stmt) {
        return ['total'=>0,'active'=>0,'overdue'=>0,'due_soon'=>0];
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'total' => (int) ($row['total'] ?? 0),
        'active' => (int) ($row['active_count'] ?? 0),
        'overdue' => (int) ($row['overdue_count'] ?? 0),
        'due_soon' => (int) ($row['due_soon_count'] ?? 0),
    ];
}

function cpmsPmComplete(
    mysqli $conn,
    int $propertyId,
    array $schedule,
    array $data,
    array $file,
    string $uploadRoot
): int {
    $completedDate = trim((string) ($data['completed_date'] ?? ''));
    $result = trim((string) ($data['result'] ?? 'Completed'));
    $notes = trim((string) ($data['work_notes'] ?? ''));
    $meter = trim((string) ($data['meter_reading'] ?? ''));
    $actualCost = (float) ($data['actual_cost'] ?? 0);
    $downtime = max(0, (int) ($data['downtime_minutes'] ?? 0));
    if ($completedDate === '' || $notes === '') {
        throw new InvalidArgumentException(
            'Completion date and work notes are required.'
        );
    }
    if (!in_array(
        $result,
        ['Completed','Completed with Finding','Failed'],
        true
    )) {
        $result = 'Completed';
    }
    $nextDue = cpmsPmNextDueDate(
        $completedDate,
        (string) $schedule['frequency_unit'],
        (int) $schedule['frequency_interval']
    );
    $userId = cpmsPmCurrentUserId();
    $userName = cpmsPmCurrentUserName();
    $scheduleId = (int) $schedule['id'];

    $conn->begin_transaction();
    $target = '';
    try {
        $stmt = $conn->prepare(
            "INSERT INTO cpms_pm_work_logs (
                schedule_id, property_id, completed_date, result,
                work_notes, meter_reading, actual_cost,
                downtime_minutes, next_due_date,
                performed_by_user_id, performed_by_name
             ) VALUES (
                ?, ?, ?, ?, ?, NULLIF(?,''), NULLIF(?,0),
                NULLIF(?,0), ?, NULLIF(?,0), ?
             )"
        );
        $stmt->bind_param(
            'iissssdisis',
            $scheduleId,
            $propertyId,
            $completedDate,
            $result,
            $notes,
            $meter,
            $actualCost,
            $downtime,
            $nextDue,
            $userId,
            $userName
        );
        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }
        $workLogId = (int) $stmt->insert_id;
        $stmt->close();

        $update = $conn->prepare(
            'UPDATE cpms_pm_schedules
             SET last_completed_date = ?, next_due_date = ?
             WHERE id = ? AND property_id = ?'
        );
        $update->bind_param(
            'ssii',
            $completedDate,
            $nextDue,
            $scheduleId,
            $propertyId
        );
        if (!$update->execute()) {
            throw new RuntimeException($update->error);
        }
        $update->close();

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            === UPLOAD_ERR_OK
        ) {
            if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
                throw new RuntimeException('Evidence exceeds 8 MB.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmp);
            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!isset($allowed[$mime]) || !is_uploaded_file($tmp)) {
                throw new RuntimeException(
                    'Only valid JPG, PNG or WebP evidence is allowed.'
                );
            }
            $relativeDirectory = 'property_' . $propertyId
                . '/schedule_' . $scheduleId
                . '/work_' . $workLogId;
            $directory = rtrim($uploadRoot, '/\\')
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relativeDirectory
                );
            if (!is_dir($directory)
                && !mkdir($directory, 0755, true)
            ) {
                throw new RuntimeException(
                    'Unable to create evidence directory.'
                );
            }
            $filename = bin2hex(random_bytes(16))
                . '.' . $allowed[$mime];
            $target = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($tmp, $target)) {
                throw new RuntimeException('Unable to save evidence.');
            }
            $path = 'uploads/preventive_maintenance/'
                . $relativeDirectory . '/' . $filename;
            $original = basename((string) ($file['name'] ?? 'image'));
            $size = (int) ($file['size'] ?? 0);
            $caption = trim((string) ($data['caption'] ?? ''));
            $evidence = $conn->prepare(
                "INSERT INTO cpms_pm_evidence (
                    work_log_id, schedule_id, property_id,
                    image_path, original_name, mime_type,
                    file_size, caption, uploaded_by_user_id,
                    uploaded_by_name
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''),
                    NULLIF(?,0), ?
                 )"
            );
            $evidence->bind_param(
                'iiisssisis',
                $workLogId,
                $scheduleId,
                $propertyId,
                $path,
                $original,
                $mime,
                $size,
                $caption,
                $userId,
                $userName
            );
            if (!$evidence->execute()) {
                throw new RuntimeException($evidence->error);
            }
            $evidence->close();
        }
        $conn->commit();
        return $workLogId;
    } catch (Throwable $exception) {
        $conn->rollback();
        if ($target !== '' && is_file($target)) {
            @unlink($target);
        }
        throw $exception;
    }
}

function cpmsPmHistory(
    mysqli $conn,
    int $propertyId,
    int $scheduleId
): array {
    $stmt = $conn->prepare(
        'SELECT l.*,
            (SELECT image_path FROM cpms_pm_evidence e
             WHERE e.work_log_id = l.id
             ORDER BY e.id LIMIT 1) AS evidence_path
         FROM cpms_pm_work_logs l
         WHERE l.property_id = ? AND l.schedule_id = ?
         ORDER BY l.completed_date DESC, l.id DESC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $scheduleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsPmVerifyWorkLog(
    mysqli $conn,
    int $propertyId,
    int $scheduleId,
    int $workLogId
): bool {
    $userId = cpmsPmCurrentUserId();
    $userName = cpmsPmCurrentUserName();
    $stmt = $conn->prepare(
        'UPDATE cpms_pm_work_logs
         SET verified_by_user_id = NULLIF(?, 0),
             verified_by_name = ?,
             verified_at = NOW()
         WHERE id = ?
           AND schedule_id = ?
           AND property_id = ?
           AND verified_at IS NULL'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'isiii',
        $userId,
        $userName,
        $workLogId,
        $scheduleId,
        $propertyId
    );
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}
