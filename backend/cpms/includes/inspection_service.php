<?php
declare(strict_types=1);

/**
 * CPMS Inspection & Compliance Service
 * Sprint 2.2
 * PHP 7.4 compatible.
 */

if (!function_exists('cpmsInspectionEscape')) {
    function cpmsInspectionEscape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function cpmsInspectionTablesReady(mysqli $conn): bool
{
    $required = [
        'inspection_reports',
        'inspection_images',
        'inspection_checklist_items',
        'inspection_status_history',
    ];

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    foreach ($required as $table) {
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ((int) ($row['total'] ?? 0) !== 1) {
            $stmt->close();
            return false;
        }
    }

    $stmt->close();
    return true;
}

function cpmsInspectionPropertyId(array $user = []): int
{
    $candidates = [
        $user['property_id'] ?? null,
        $_SESSION['property_id'] ?? null,
        $_SESSION['cpms_property_id'] ?? null,
        $_SESSION['property_portal_property_id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $id = (int) $candidate;
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function cpmsInspectionCurrentUserId(array $user = []): int
{
    $candidates = [
        $user['id'] ?? null,
        $user['user_id'] ?? null,
        $user['admin_id'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['admin_id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $id = (int) $candidate;
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function cpmsInspectionCurrentUserName(array $user = []): string
{
    $candidates = [
        $user['full_name'] ?? null,
        $user['name'] ?? null,
        $user['username'] ?? null,
        $_SESSION['full_name'] ?? null,
        $_SESSION['name'] ?? null,
        $_SESSION['username'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $name = trim((string) $candidate);
        if ($name !== '') {
            return $name;
        }
    }

    return 'Property User';
}

function cpmsInspectionCsrfToken(): string
{
    if (empty($_SESSION['inspection_csrf_token'])) {
        $_SESSION['inspection_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['inspection_csrf_token'];
}

function cpmsInspectionVerifyCsrf(?string $token): bool
{
    $stored = (string) ($_SESSION['inspection_csrf_token'] ?? '');
    return $stored !== ''
        && $token !== null
        && hash_equals($stored, $token);
}

function cpmsInspectionGenerateNumber(
    mysqli $conn,
    int $propertyId
): string {
    $prefix = 'INS-' . $propertyId . '-' . date('ymd');

    $stmt = $conn->prepare(
        "SELECT inspection_no
         FROM inspection_reports
         WHERE property_id = ?
           AND inspection_no LIKE CONCAT(?, '-%')
         ORDER BY id DESC
         LIMIT 1"
    );

    $sequence = 1;

    if ($stmt) {
        $stmt->bind_param('is', $propertyId, $prefix);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row['inspection_no'])) {
            $parts = explode('-', (string) $row['inspection_no']);
            $last = (int) end($parts);
            $sequence = $last + 1;
        }
    }

    return $prefix . '-' . str_pad(
        (string) $sequence,
        4,
        '0',
        STR_PAD_LEFT
    );
}

function cpmsInspectionCreate(
    mysqli $conn,
    int $propertyId,
    array $data
): int {
    if ($propertyId < 1) {
        throw new InvalidArgumentException('Invalid property ID.');
    }

    $inspectionNo = trim((string) ($data['inspection_no'] ?? ''));
    if ($inspectionNo === '') {
        $inspectionNo = cpmsInspectionGenerateNumber($conn, $propertyId);
    }

    $inspectionDate = trim(
        (string) ($data['inspection_date'] ?? date('Y-m-d'))
    );
    $inspectionType = trim(
        (string) ($data['inspection_type'] ?? 'General Inspection')
    );
    $category = trim((string) ($data['category'] ?? 'General'));
    $location = trim((string) ($data['location'] ?? ''));
    $priority = trim((string) ($data['priority'] ?? 'Medium'));
    $status = trim((string) ($data['status'] ?? 'Draft'));
    $description = trim((string) ($data['description'] ?? ''));
    $finding = trim((string) ($data['finding'] ?? ''));
    $recommendation = trim((string) ($data['recommendation'] ?? ''));
    $reportedById = (int) ($data['reported_by_id'] ?? 0);
    $reportedByName = trim(
        (string) ($data['reported_by_name'] ?? '')
    );

    if ($location === '') {
        throw new InvalidArgumentException('Inspection location is required.');
    }

    $allowedPriorities = ['Low', 'Medium', 'High', 'Critical'];
    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'Medium';
    }

    $allowedStatuses = ['Draft', 'Submitted'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'Draft';
    }

    $stmt = $conn->prepare(
        'INSERT INTO inspection_reports (
            property_id,
            inspection_no,
            inspection_date,
            inspection_type,
            category,
            location,
            priority,
            status,
            description,
            finding,
            recommendation,
            reported_by_id,
            reported_by_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, ""))'
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inspection record.');
    }

    $stmt->bind_param(
        'issssssssssis',
        $propertyId,
        $inspectionNo,
        $inspectionDate,
        $inspectionType,
        $category,
        $location,
        $priority,
        $status,
        $description,
        $finding,
        $recommendation,
        $reportedById,
        $reportedByName
    );

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to create inspection: ' . $message);
    }

    $inspectionId = (int) $stmt->insert_id;
    $stmt->close();

    cpmsInspectionAddHistory(
        $conn,
        $inspectionId,
        $propertyId,
        null,
        $status,
        'Inspection created.',
        $reportedById,
        $reportedByName
    );

    return $inspectionId;
}

function cpmsInspectionFind(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): ?array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_reports
         WHERE id = ?
           AND property_id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $inspectionId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function cpmsInspectionList(
    mysqli $conn,
    int $propertyId,
    string $status = '',
    int $limit = 100
): array {
    $limit = max(1, min(500, $limit));
    $status = trim($status);

    if ($status !== '') {
        $stmt = $conn->prepare(
            'SELECT r.*,
                (SELECT COUNT(*)
                 FROM inspection_images i
                 WHERE i.inspection_id = r.id
                   AND i.property_id = r.property_id) AS image_count
             FROM inspection_reports r
             WHERE r.property_id = ?
               AND r.status = ?
             ORDER BY r.inspection_date DESC, r.id DESC
             LIMIT ?'
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('isi', $propertyId, $status, $limit);
    } else {
        $stmt = $conn->prepare(
            'SELECT r.*,
                (SELECT COUNT(*)
                 FROM inspection_images i
                 WHERE i.inspection_id = r.id
                   AND i.property_id = r.property_id) AS image_count
             FROM inspection_reports r
             WHERE r.property_id = ?
             ORDER BY r.inspection_date DESC, r.id DESC
             LIMIT ?'
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $propertyId, $limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function cpmsInspectionAddHistory(
    mysqli $conn,
    int $inspectionId,
    int $propertyId,
    ?string $oldStatus,
    string $newStatus,
    string $remarks,
    int $changedById,
    string $changedByName
): bool {
    $stmt = $conn->prepare(
        'INSERT INTO inspection_status_history (
            inspection_id,
            property_id,
            old_status,
            new_status,
            remarks,
            changed_by_id,
            changed_by_name
        ) VALUES (?, ?, NULLIF(?, ""), ?, NULLIF(?, ""), NULLIF(?, 0), NULLIF(?, ""))'
    );

    if (!$stmt) {
        return false;
    }

    $oldStatusValue = (string) $oldStatus;

    $stmt->bind_param(
        'iisssis',
        $inspectionId,
        $propertyId,
        $oldStatusValue,
        $newStatus,
        $remarks,
        $changedById,
        $changedByName
    );

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function cpmsInspectionCountImages(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): int {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM inspection_images
         WHERE property_id = ?
           AND inspection_id = ?'
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('ii', $propertyId, $inspectionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0);
}

function cpmsInspectionImages(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_images
         WHERE property_id = ?
           AND inspection_id = ?
         ORDER BY created_at ASC, id ASC'
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

function cpmsInspectionStoreImage(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    array $file,
    string $uploadRoot,
    string $imageType,
    string $caption,
    int $uploadedById,
    string $uploadedByName,
    int $maximumImages = 30
): int {
    if (!cpmsInspectionFind($conn, $propertyId, $inspectionId)) {
        throw new RuntimeException('Inspection record not found.');
    }

    $currentCount = cpmsInspectionCountImages(
        $conn,
        $propertyId,
        $inspectionId
    );

    if ($currentCount >= $maximumImages) {
        throw new RuntimeException(
            'Maximum ' . $maximumImages . ' images per inspection reached.'
        );
    }

    if (
        !isset($file['error']) ||
        (int) $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException('Image upload failed.');
    }

    $maxBytes = 8 * 1024 * 1024;

    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Image exceeds the 8 MB limit.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded image.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            'Only JPG, PNG and WebP images are allowed.'
        );
    }

    $allowedTypes = [
        'Finding',
        'Before Repair',
        'During Repair',
        'After Repair',
        'Evidence',
        'Other',
    ];

    if (!in_array($imageType, $allowedTypes, true)) {
        $imageType = 'Finding';
    }

    $relativeDirectory = 'property_' . $propertyId
        . '/inspection_' . $inspectionId;
    $targetDirectory = rtrim($uploadRoot, '/\\')
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

    if (
        !is_dir($targetDirectory) &&
        !mkdir($targetDirectory, 0755, true) &&
        !is_dir($targetDirectory)
    ) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Unable to save uploaded image.');
    }

    $relativePath = 'uploads/inspections/'
        . $relativeDirectory . '/' . $filename;
    $originalName = basename((string) ($file['name'] ?? 'image'));
    $fileSize = (int) ($file['size'] ?? 0);

    $stmt = $conn->prepare(
        'INSERT INTO inspection_images (
            inspection_id,
            property_id,
            image_type,
            image_path,
            original_name,
            mime_type,
            file_size,
            caption,
            uploaded_by_id,
            uploaded_by_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ""), NULLIF(?, 0), NULLIF(?, ""))'
    );

    if (!$stmt) {
        @unlink($target);
        throw new RuntimeException('Unable to prepare image record.');
    }

    $stmt->bind_param(
        'iissssisis',
        $inspectionId,
        $propertyId,
        $imageType,
        $relativePath,
        $originalName,
        $mime,
        $fileSize,
        $caption,
        $uploadedById,
        $uploadedByName
    );

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        @unlink($target);
        throw new RuntimeException('Unable to save image record: ' . $message);
    }

    $imageId = (int) $stmt->insert_id;
    $stmt->close();

    return $imageId;
}

function cpmsInspectionSummary(
    mysqli $conn,
    int $propertyId
): array {
    $summary = [
        'total' => 0,
        'draft' => 0,
        'submitted' => 0,
        'review' => 0,
        'action_required' => 0,
        'verified' => 0,
        'overdue' => 0,
    ];

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(LOWER(status) = 'draft') AS draft_total,
            SUM(LOWER(status) = 'submitted') AS submitted_total,
            SUM(LOWER(status) IN ('under review', 'review')) AS review_total,
            SUM(LOWER(status) IN ('action required', 'repair required')) AS action_total,
            SUM(LOWER(status) IN ('verified', 'closed', 'completed')) AS verified_total,
            SUM(
                due_date IS NOT NULL
                AND due_date < CURDATE()
                AND LOWER(status) NOT IN ('verified', 'closed', 'completed')
            ) AS overdue_total
         FROM inspection_reports
         WHERE property_id = ?"
    );

    if (!$stmt) {
        return $summary;
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $summary['total'] = (int) ($row['total'] ?? 0);
    $summary['draft'] = (int) ($row['draft_total'] ?? 0);
    $summary['submitted'] = (int) ($row['submitted_total'] ?? 0);
    $summary['review'] = (int) ($row['review_total'] ?? 0);
    $summary['action_required'] = (int) ($row['action_total'] ?? 0);
    $summary['verified'] = (int) ($row['verified_total'] ?? 0);
    $summary['overdue'] = (int) ($row['overdue_total'] ?? 0);

    return $summary;
}

function cpmsInspectionUpdateStatus(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    string $newStatus,
    string $remarks,
    int $changedById,
    string $changedByName
): bool {
    $record = cpmsInspectionFind($conn, $propertyId, $inspectionId);

    if (!$record) {
        return false;
    }

    $allowed = [
        'Draft',
        'Submitted',
        'Under Review',
        'Action Required',
        'Rejected',
        'Verified',
        'Closed',
    ];

    if (!in_array($newStatus, $allowed, true)) {
        throw new InvalidArgumentException('Invalid inspection status.');
    }

    $oldStatus = (string) ($record['status'] ?? '');
    $verifiedSql = '';

    if ($newStatus === 'Verified') {
        $verifiedSql = ', verified_by_id = NULLIF(?, 0),
            verified_by_name = NULLIF(?, ""),
            verified_at = NOW()';
    }

    if ($newStatus === 'Closed') {
        $verifiedSql = ', closed_at = NOW()';
    }

    if ($newStatus === 'Verified') {
        $stmt = $conn->prepare(
            'UPDATE inspection_reports
             SET status = ?' . $verifiedSql . '
             WHERE id = ?
               AND property_id = ?'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'sisii',
            $newStatus,
            $changedById,
            $changedByName,
            $inspectionId,
            $propertyId
        );
    } else {
        $stmt = $conn->prepare(
            'UPDATE inspection_reports
             SET status = ?' . $verifiedSql . '
             WHERE id = ?
               AND property_id = ?'
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'sii',
            $newStatus,
            $inspectionId,
            $propertyId
        );
    }

    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        cpmsInspectionAddHistory(
            $conn,
            $inspectionId,
            $propertyId,
            $oldStatus,
            $newStatus,
            $remarks,
            $changedById,
            $changedByName
        );
    }

    return $ok;
}

function cpmsInspectionHistory(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_status_history
         WHERE property_id = ?
           AND inspection_id = ?
         ORDER BY created_at DESC, id DESC'
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

function cpmsInspectionCanReview(array $user = []): bool
{
    $role = strtolower(trim((string) (
        $user['role']
        ?? $user['role_name']
        ?? $_SESSION['role']
        ?? $_SESSION['role_name']
        ?? ''
    )));

    if ($role === '') {
        return true;
    }

    return in_array(
        $role,
        [
            'system_owner',
            'owner',
            'property_admin',
            'admin',
            'manager',
            'supervisor',
            'compliance_manager',
        ],
        true
    );
}

function cpmsInspectionWorkOrderColumnReady(mysqli $conn): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "work_orders"
           AND COLUMN_NAME = "inspection_id"'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) === 1;
}

function cpmsInspectionGenerateWorkOrderReference(mysqli $conn): string
{
    do {
        $reference = 'WO-' . date('Ymd') . '-'
            . strtoupper(bin2hex(random_bytes(3)));

        $stmt = $conn->prepare(
            'SELECT id
             FROM work_orders
             WHERE work_order_reference = ?
             LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to validate Work Order reference.'
            );
        }

        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);

    return $reference;
}

function cpmsInspectionWorkOrderByInspection(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): ?array {
    if (!cpmsInspectionWorkOrderColumnReady($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT *
         FROM work_orders
         WHERE property_id = ?
           AND inspection_id = ?
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

function cpmsInspectionCreateWorkOrder(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $createdById,
    string $createdByName,
    string $supervisorRemarks = ''
): array {
    if (!cpmsInspectionWorkOrderColumnReady($conn)) {
        throw new RuntimeException(
            'Import inspection_work_order_integration_v1.sql first.'
        );
    }

    $inspection = cpmsInspectionFind(
        $conn,
        $propertyId,
        $inspectionId
    );

    if (!$inspection) {
        throw new RuntimeException('Inspection record not found.');
    }

    $existing = cpmsInspectionWorkOrderByInspection(
        $conn,
        $propertyId,
        $inspectionId
    );

    if ($existing) {
        return $existing;
    }

    $reference = cpmsInspectionGenerateWorkOrderReference($conn);
    $inspectionNo = (string) $inspection['inspection_no'];
    $category = trim((string) $inspection['category']);

    if ($category === '') {
        $category = 'General';
    }

    $inspectionPriority = (string) $inspection['priority'];
    $priorityMap = [
        'Low' => 'Low',
        'Medium' => 'Medium',
        'High' => 'High',
        'Critical' => 'Emergency',
    ];
    $priority = $priorityMap[$inspectionPriority] ?? 'Medium';

    $location = trim((string) $inspection['location']);
    $blockLocation = $location !== '' ? $location : 'Common Area';
    $specificLocation = $location;

    $title = 'Inspection Action - ' . $category;
    $descriptionParts = [
        trim((string) $inspection['finding']),
        trim((string) $inspection['recommendation']),
        'Source: Inspection ' . $inspectionNo,
    ];

    if (trim($supervisorRemarks) !== '') {
        $descriptionParts[] = 'Supervisor instruction: '
            . trim($supervisorRemarks);
    }

    $descriptionParts = array_values(array_filter(
        $descriptionParts,
        static function ($value): bool {
            return trim((string) $value) !== '';
        }
    ));

    $description = implode("\n\n", $descriptionParts);
    $scheduledDate = date('Y-m-d');

    $dueDays = [
        'Low' => 14,
        'Medium' => 7,
        'High' => 3,
        'Emergency' => 1,
    ];
    $dueDate = date(
        'Y-m-d',
        strtotime('+' . ($dueDays[$priority] ?? 7) . ' days')
    );

    $createdBy = trim($createdByName);
    if ($createdBy === '') {
        $createdBy = $createdById > 0
            ? 'User #' . $createdById
            : 'Inspection Module';
    }

    $adminRemarks = trim($supervisorRemarks);

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            'INSERT INTO work_orders (
                property_id,
                work_order_reference,
                complaint_id,
                security_patrol_id,
                inspection_id,
                title,
                description,
                category,
                priority,
                block_location,
                specific_location,
                scheduled_date,
                due_date,
                status,
                admin_remarks,
                created_by
            ) VALUES (
                ?,
                ?,
                NULL,
                NULL,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                "Open",
                NULLIF(?, ""),
                ?
            )'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare Work Order record.'
            );
        }

        $stmt->bind_param(
            'isissssssssss',
            $propertyId,
            $reference,
            $inspectionId,
            $title,
            $description,
            $category,
            $priority,
            $blockLocation,
            $specificLocation,
            $scheduledDate,
            $dueDate,
            $adminRemarks,
            $createdBy
        );

        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException(
                'Unable to create Work Order: ' . $message
            );
        }

        $workOrderId = (int) $stmt->insert_id;
        $stmt->close();

        $linkStmt = $conn->prepare(
            'UPDATE inspection_reports
             SET work_order_id = ?,
                 status = "Action Required"
             WHERE id = ?
               AND property_id = ?'
        );

        if (!$linkStmt) {
            throw new RuntimeException(
                'Unable to link Work Order to inspection.'
            );
        }

        $linkStmt->bind_param(
            'iii',
            $workOrderId,
            $inspectionId,
            $propertyId
        );

        if (!$linkStmt->execute()) {
            $message = $linkStmt->error;
            $linkStmt->close();
            throw new RuntimeException(
                'Unable to link Work Order: ' . $message
            );
        }

        $linkStmt->close();

        cpmsInspectionAddHistory(
            $conn,
            $inspectionId,
            $propertyId,
            (string) $inspection['status'],
            'Action Required',
            'Work Order ' . $reference . ' created. '
                . $adminRemarks,
            $createdById,
            $createdBy
        );

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    $created = cpmsInspectionWorkOrderByInspection(
        $conn,
        $propertyId,
        $inspectionId
    );

    if (!$created) {
        throw new RuntimeException(
            'Work Order was created but could not be retrieved.'
        );
    }

    return $created;
}
