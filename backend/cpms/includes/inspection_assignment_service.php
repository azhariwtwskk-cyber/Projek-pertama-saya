<?php
declare(strict_types=1);

/**
 * CPMS v3.2.6.6 - Finding Assignment & Rectification Workflow
 * PHP 7.4 compatible.
 */

function cpmsAssignmentTableExists(mysqli $conn, string $table): bool
{
    if (function_exists('cpmsFindingTableExists')) {
        return cpmsFindingTableExists($conn, $table);
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) === 1;
}

function cpmsAssignmentColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ? AND column_name = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) === 1;
}

function cpmsAssignmentPhotoPairingReady(mysqli $conn): bool
{
    return cpmsAssignmentColumnExists(
        $conn,
        'inspection_action_images',
        'source_inspection_image_id'
    );
}

function cpmsAssignmentTablesReady(mysqli $conn): bool
{
    foreach ([
        'inspection_finding_action_links',
        'inspection_action_progress_log',
        'inspection_corrective_actions',
        'inspection_action_images',
    ] as $table) {
        if (!cpmsAssignmentTableExists($conn, $table)) {
            return false;
        }
    }
    return true;
}

function cpmsAssignmentEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsAssignmentNotificationTable(mysqli $conn): string
{
    if (cpmsAssignmentTableExists($conn, 'cpms_user_notifications')) {
        return 'cpms_user_notifications';
    }
    if (cpmsAssignmentTableExists($conn, 'cpms_notifications')) {
        return 'cpms_notifications';
    }
    return '';
}

function cpmsAssignmentNotifyUser(
    mysqli $conn,
    int $propertyId,
    int $recipientId,
    string $key,
    string $type,
    string $title,
    string $message,
    string $targetUrl,
    string $severity,
    int $actionId
): void {
    $table = cpmsAssignmentNotificationTable($conn);
    if ($table === '' || $propertyId < 1 || $recipientId < 1) {
        return;
    }
    $sql = "INSERT INTO {$table} (
                property_id, recipient_system_user_id,
                notification_key, notification_type, title,
                message, target_url, severity, related_type, related_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'corrective_action', ?)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title), message = VALUES(message),
                target_url = VALUES(target_url), severity = VALUES(severity),
                is_read = 0, read_at = NULL,
                updated_at = CURRENT_TIMESTAMP";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iissssssi',
        $propertyId,
        $recipientId,
        $key,
        $type,
        $title,
        $message,
        $targetUrl,
        $severity,
        $actionId
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsAssignmentPropertyReviewers(
    mysqli $conn,
    int $propertyId
): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.id
         FROM system_users u
         INNER JOIN user_roles ur
            ON ur.system_user_id = u.id
           AND ur.property_id = ?
           AND ur.status = 'active'
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.role_code IN ('property_admin', 'manager', 'supervisor')
           AND r.status = 'active'
         WHERE u.status = 'active'
           AND (u.property_id = ? OR u.property_id IS NULL)"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $stmt->close();
    return $ids;
}

function cpmsAssignmentCurrentRole(): string
{
    return trim((string) (
        $_SESSION['cpms_user_role']
        ?? $_SESSION['property_admin_role']
        ?? 'staff'
    ));
}

function cpmsAssignmentLinkByFinding(
    mysqli $conn,
    int $propertyId,
    int $findingId
): ?array {
    if (!cpmsAssignmentTablesReady($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT l.*, a.action_no, a.title, a.description,
                a.assigned_type, a.assigned_system_user_id,
                a.assigned_name, a.priority, a.due_date,
                a.status AS action_status, a.rectification_notes,
                a.rectified_at, a.verified_at, a.created_at AS action_created_at
         FROM inspection_finding_action_links l
         INNER JOIN inspection_corrective_actions a
            ON a.id = l.action_id AND a.property_id = l.property_id
         WHERE l.property_id = ? AND l.finding_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $propertyId, $findingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsAssignmentLinkByAction(
    mysqli $conn,
    int $propertyId,
    int $actionId
): ?array {
    if (!cpmsAssignmentTablesReady($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT l.*, f.category AS finding_category,
                f.finding_name, f.severity AS finding_severity,
                f.location AS finding_location, f.remarks AS finding_remarks,
                f.recommendation AS finding_recommendation,
                f.status AS finding_status
         FROM inspection_finding_action_links l
         INNER JOIN inspection_findings f
            ON f.id = l.finding_id
           AND f.inspection_id = l.inspection_id
           AND f.property_id = l.property_id
         WHERE l.property_id = ? AND l.action_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $propertyId, $actionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsAssignmentFindingMap(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    if (!cpmsAssignmentTablesReady($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT l.*, a.action_no, a.title, a.assigned_name,
                a.assigned_type, a.priority, a.due_date,
                a.status AS action_status, a.updated_at AS action_updated_at,
                (SELECT COUNT(*) FROM inspection_action_images ai
                 WHERE ai.action_id = a.id
                   AND ai.property_id = a.property_id) AS evidence_count
         FROM inspection_finding_action_links l
         INNER JOIN inspection_corrective_actions a
            ON a.id = l.action_id AND a.property_id = l.property_id
         WHERE l.property_id = ? AND l.inspection_id = ?'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $inspectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $map = [];
    while ($row = $result->fetch_assoc()) {
        $map[(int) $row['finding_id']] = $row;
    }
    $stmt->close();
    return $map;
}

function cpmsAssignmentLog(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $actionId,
    string $eventType,
    ?string $oldStatus,
    ?string $newStatus,
    string $notes = '',
    ?int $actorUserId = null,
    string $actorName = '',
    string $actorRole = ''
): void {
    if (!cpmsAssignmentTableExists($conn, 'inspection_action_progress_log')) {
        return;
    }

    if ($actorUserId === null) {
        $actorUserId = function_exists('cpmsActionCurrentUserId')
            ? cpmsActionCurrentUserId()
            : (int) ($_SESSION['cpms_user_id'] ?? 0);
    }
    if ($actorName === '') {
        $actorName = function_exists('cpmsActionCurrentUserName')
            ? cpmsActionCurrentUserName()
            : trim((string) ($_SESSION['staff_name'] ?? 'CPMS User'));
    }
    if ($actorRole === '') {
        $actorRole = cpmsAssignmentCurrentRole();
    }
    $actorName = $actorName !== '' ? $actorName : 'CPMS User';
    $actorRole = $actorRole !== '' ? $actorRole : 'user';
    $oldStatus = trim((string) $oldStatus);
    $newStatus = trim((string) $newStatus);
    $notes = trim($notes);

    $stmt = $conn->prepare(
        'INSERT INTO inspection_action_progress_log (
            action_id, inspection_id, property_id,
            actor_system_user_id, actor_name, actor_role,
            event_type, old_status, new_status, notes
         ) VALUES (
            ?, ?, ?, NULLIF(?, 0), ?, ?, ?,
            NULLIF(?, ""), NULLIF(?, ""), NULLIF(?, "")
         )'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iiiissssss',
        $actionId,
        $inspectionId,
        $propertyId,
        $actorUserId,
        $actorName,
        $actorRole,
        $eventType,
        $oldStatus,
        $newStatus,
        $notes
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsAssignmentProgress(
    mysqli $conn,
    int $propertyId,
    int $actionId
): array {
    if (!cpmsAssignmentTableExists($conn, 'inspection_action_progress_log')) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT * FROM inspection_action_progress_log
         WHERE property_id = ? AND action_id = ?
         ORDER BY created_at DESC, id DESC'
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

function cpmsAssignmentOriginalImages(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId
): array {
    if (function_exists('cpmsFindingImages')) {
        return cpmsFindingImages(
            $conn,
            $propertyId,
            $inspectionId,
            $findingId
        );
    }
    return [];
}

function cpmsAssignmentSourceImage(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    int $sourceImageId
): ?array {
    if ($sourceImageId < 1 || !cpmsAssignmentTablesReady($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT i.*
         FROM inspection_finding_action_links l
         INNER JOIN inspection_finding_images fi
            ON fi.finding_id = l.finding_id
           AND fi.inspection_id = l.inspection_id
           AND fi.property_id = l.property_id
         INNER JOIN inspection_images i
            ON i.id = fi.inspection_image_id
           AND i.inspection_id = fi.inspection_id
           AND i.property_id = fi.property_id
         WHERE l.action_id = ? AND l.property_id = ?
           AND i.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iii', $actionId, $propertyId, $sourceImageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsAssignmentPhotoPairSummary(
    mysqli $conn,
    int $propertyId,
    int $actionId
): array {
    $summary = ['required' => 0, 'completed' => 0, 'remaining' => 0];
    if (!cpmsAssignmentPhotoPairingReady($conn)) {
        return $summary;
    }
    $stmt = $conn->prepare(
        'SELECT COUNT(DISTINCT fi.inspection_image_id) AS required_total,
                COUNT(DISTINCT CASE WHEN ai.id IS NOT NULL
                    THEN fi.inspection_image_id END) AS completed_total
         FROM inspection_finding_action_links l
         INNER JOIN inspection_finding_images fi
            ON fi.finding_id = l.finding_id
           AND fi.inspection_id = l.inspection_id
           AND fi.property_id = l.property_id
         LEFT JOIN inspection_action_images ai
            ON ai.action_id = l.action_id
           AND ai.property_id = l.property_id
           AND ai.source_inspection_image_id = fi.inspection_image_id
           AND ai.image_phase = "After"
         WHERE l.action_id = ? AND l.property_id = ?'
    );
    if (!$stmt) {
        return $summary;
    }
    $stmt->bind_param('ii', $actionId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $required = (int) ($row['required_total'] ?? 0);
    $completed = (int) ($row['completed_total'] ?? 0);
    return [
        'required' => $required,
        'completed' => $completed,
        'remaining' => max(0, $required - $completed),
    ];
}

function cpmsAssignmentAttachSourceImage(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    int $actionImageId,
    int $sourceImageId
): void {
    if (!cpmsAssignmentPhotoPairingReady($conn) || $sourceImageId < 1) {
        return;
    }
    $stmt = $conn->prepare(
        'UPDATE inspection_action_images
         SET source_inspection_image_id = ?
         WHERE id = ? AND action_id = ? AND property_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Gagal menyediakan padanan gambar Before/After.');
    }
    $stmt->bind_param(
        'iiii',
        $sourceImageId,
        $actionImageId,
        $actionId,
        $propertyId
    );
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Gagal memadankan gambar: ' . $message);
    }
    $stmt->close();
}

function cpmsAssignmentPairLegacyAfterImage(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    int $actionImageId,
    int $sourceImageId
): void {
    if (!cpmsAssignmentPhotoPairingReady($conn)) {
        throw new RuntimeException(
            'Jalankan migration 20260810_0062 sebelum memadankan gambar lama.'
        );
    }
    if ($propertyId < 1 || $actionId < 1
        || $actionImageId < 1 || $sourceImageId < 1
    ) {
        throw new InvalidArgumentException('Pilihan gambar tidak sah.');
    }

    $sourceImage = cpmsAssignmentSourceImage(
        $conn,
        $propertyId,
        $actionId,
        $sourceImageId
    );
    if (!$sourceImage) {
        throw new InvalidArgumentException(
            'Gambar Before bukan daripada finding dan property yang sama.'
        );
    }

    $stmt = $conn->prepare(
        'SELECT id, inspection_id, image_phase,
                source_inspection_image_id
         FROM inspection_action_images
         WHERE id = ? AND action_id = ? AND property_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Gambar After tidak dapat disemak.');
    }
    $stmt->bind_param('iii', $actionImageId, $actionId, $propertyId);
    $stmt->execute();
    $actionImage = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$actionImage) {
        throw new InvalidArgumentException(
            'Gambar After tidak dijumpai untuk Corrective Action ini.'
        );
    }
    if ((string) ($actionImage['image_phase'] ?? '') !== 'After') {
        throw new InvalidArgumentException(
            'Hanya gambar berstatus After boleh dipadankan.'
        );
    }
    if ((int) ($actionImage['source_inspection_image_id'] ?? 0) > 0) {
        throw new InvalidArgumentException(
            'Gambar After ini sudah mempunyai padanan Before.'
        );
    }

    $update = $conn->prepare(
        'UPDATE inspection_action_images
         SET source_inspection_image_id = ?
         WHERE id = ? AND action_id = ? AND property_id = ?
           AND image_phase = "After"
           AND source_inspection_image_id IS NULL'
    );
    if (!$update) {
        throw new RuntimeException('Padanan gambar lama tidak dapat disediakan.');
    }
    $update->bind_param(
        'iiii',
        $sourceImageId,
        $actionImageId,
        $actionId,
        $propertyId
    );
    if (!$update->execute() || $update->affected_rows !== 1) {
        $message = $update->error;
        $update->close();
        throw new RuntimeException(
            'Padanan gambar lama gagal disimpan.'
            . ($message !== '' ? ' ' . $message : '')
        );
    }
    $update->close();

    cpmsAssignmentLog(
        $conn,
        $propertyId,
        (int) ($actionImage['inspection_id'] ?? 0),
        $actionId,
        'legacy_after_paired',
        null,
        null,
        'Legacy After image #' . $actionImageId
            . ' paired to Inspector image #' . $sourceImageId . '.'
    );
}

function cpmsAssignmentCreateForFinding(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId,
    array $data
): int {
    if (!cpmsAssignmentTablesReady($conn)) {
        throw new RuntimeException(
            'Jalankan migration CPMS v3.2.6.6 sebelum membuat assignment.'
        );
    }
    if (!function_exists('cpmsFindingFind') || !function_exists('cpmsActionCreate')) {
        throw new RuntimeException('Inspection action service belum lengkap.');
    }

    $finding = cpmsFindingFind($conn, $propertyId, $inspectionId, $findingId);
    if (!$finding) {
        throw new RuntimeException('Finding tidak dijumpai untuk property ini.');
    }
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection || (string) ($inspection['status'] ?? '') === 'Draft') {
        throw new RuntimeException('Hanya report HQ yang telah dihantar boleh diagihkan.');
    }
    $existing = cpmsAssignmentLinkByFinding($conn, $propertyId, $findingId);
    if ($existing) {
        return (int) $existing['action_id'];
    }

    $actionId = cpmsActionCreate($conn, $propertyId, $inspectionId, $data);
    $assignedById = function_exists('cpmsActionCurrentUserId')
        ? cpmsActionCurrentUserId()
        : (int) ($_SESSION['cpms_user_id'] ?? 0);
    $assignedByName = function_exists('cpmsActionCurrentUserName')
        ? cpmsActionCurrentUserName()
        : trim((string) ($_SESSION['property_admin_name'] ?? 'Property User'));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO inspection_finding_action_links (
                finding_id, action_id, inspection_id, property_id,
                assigned_by_system_user_id, assigned_by_name
             ) VALUES (?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, ""))'
        );
        if (!$stmt) {
            throw new RuntimeException('Finding link tidak dapat disediakan.');
        }
        $stmt->bind_param(
            'iiiiis',
            $findingId,
            $actionId,
            $inspectionId,
            $propertyId,
            $assignedById,
            $assignedByName
        );
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Finding link gagal disimpan: ' . $error);
        }
        $stmt->close();

        $update = $conn->prepare(
            'UPDATE inspection_findings
             SET status = "Assigned"
             WHERE id = ? AND inspection_id = ? AND property_id = ?'
        );
        if (!$update) {
            throw new RuntimeException('Status finding tidak dapat disediakan.');
        }
        $update->bind_param('iii', $findingId, $inspectionId, $propertyId);
        if (!$update->execute()) {
            $error = $update->error;
            $update->close();
            throw new RuntimeException('Status finding gagal dikemas kini: ' . $error);
        }
        $update->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        $cleanup = $conn->prepare(
            'DELETE FROM inspection_corrective_actions
             WHERE id = ? AND property_id = ?'
        );
        if ($cleanup) {
            $cleanup->bind_param('ii', $actionId, $propertyId);
            $cleanup->execute();
            $cleanup->close();
        }
        throw $exception;
    }

    cpmsAssignmentLog(
        $conn,
        $propertyId,
        $inspectionId,
        $actionId,
        'assigned',
        null,
        'Open',
        'Finding assigned to ' . (string) ($data['assigned_name'] ?? '')
    );
    $createdAction = cpmsActionFind($conn, $propertyId, $actionId);
    if ($createdAction) {
        cpmsAssignmentNotifyUser(
            $conn,
            $propertyId,
            (int) ($createdAction['assigned_system_user_id'] ?? 0),
            'hq-finding-assigned-' . $actionId,
            'inspection_finding_assigned',
            'Tugasan pembaikan inspection baharu',
            (string) $createdAction['action_no'] . ': '
                . (string) $finding['finding_name'] . ' — '
                . (string) $finding['location'],
            'staff_corrective_action_view.php?id=' . $actionId,
            'info',
            $actionId
        );
    }
    return $actionId;
}

function cpmsAssignmentStaffAction(
    mysqli $conn,
    int $propertyId,
    int $systemUserId,
    int $actionId
): ?array {
    if (!cpmsAssignmentTablesReady($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT a.*, r.inspection_no, r.inspection_date,
                r.location AS inspection_location,
                r.reported_by_name AS inspector_name,
                l.finding_id,
                COALESCE(l.supervisor_status, "Legacy Action")
                    AS supervisor_status,
                l.supervisor_reviewed_by_name, l.supervisor_remarks,
                l.supervisor_reviewed_at,
                COALESCE(f.category, r.category) AS finding_category,
                COALESCE(f.finding_name, a.title) AS finding_name,
                COALESCE(f.severity, a.priority) AS finding_severity,
                COALESCE(f.location, r.location) AS finding_location,
                COALESCE(f.remarks, r.finding) AS finding_remarks,
                COALESCE(f.recommendation, r.recommendation)
                    AS finding_recommendation,
                f.status AS finding_status
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r
            ON r.id = a.inspection_id AND r.property_id = a.property_id
         LEFT JOIN inspection_finding_action_links l
            ON l.action_id = a.id AND l.property_id = a.property_id
         LEFT JOIN inspection_findings f
            ON f.id = l.finding_id
           AND f.inspection_id = l.inspection_id
           AND f.property_id = l.property_id
         WHERE a.id = ? AND a.property_id = ?
           AND a.assigned_system_user_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iii', $actionId, $propertyId, $systemUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsAssignmentEvidenceCounts(
    mysqli $conn,
    int $propertyId,
    int $actionId
): array {
    $counts = ['Before' => 0, 'During' => 0, 'After' => 0, 'Evidence' => 0];
    $stmt = $conn->prepare(
        'SELECT image_phase, COUNT(*) AS total
         FROM inspection_action_images
         WHERE property_id = ? AND action_id = ?
         GROUP BY image_phase'
    );
    if (!$stmt) {
        return $counts;
    }
    $stmt->bind_param('ii', $propertyId, $actionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $phase = (string) $row['image_phase'];
        if (array_key_exists($phase, $counts)) {
            $counts[$phase] = (int) $row['total'];
        }
    }
    $stmt->close();
    return $counts;
}

function cpmsAssignmentNormalizeFiles(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return [$files];
    }
    $rows = [];
    foreach ($files['name'] as $index => $name) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $rows[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $rows;
}

function cpmsAssignmentStoreImages(
    mysqli $conn,
    int $propertyId,
    array $action,
    array $files,
    string $phase,
    string $caption,
    string $uploadRoot,
    int $sourceImageId = 0
): int {
    $normalized = cpmsAssignmentNormalizeFiles($files);
    if (!$normalized) {
        throw new RuntimeException('Sila pilih sekurang-kurangnya satu gambar.');
    }
    if (count($normalized) > 10) {
        throw new RuntimeException('Maksimum 10 gambar bagi setiap upload bukti.');
    }

    $actionId = (int) ($action['id'] ?? 0);
    $link = cpmsAssignmentLinkByAction($conn, $propertyId, $actionId);
    if ($phase === 'After' && $link) {
        if (!cpmsAssignmentPhotoPairingReady($conn)) {
            throw new RuntimeException(
                'Jalankan migration 20260810_0062 sebelum upload gambar After.'
            );
        }
        if (
            $sourceImageId < 1
            || !cpmsAssignmentSourceImage(
                $conn,
                $propertyId,
                $actionId,
                $sourceImageId
            )
        ) {
            throw new RuntimeException(
                'Pilih gambar asal Inspector yang sepadan sebelum upload After.'
            );
        }
    } else {
        $sourceImageId = 0;
    }

    $saved = 0;
    foreach ($normalized as $index => $file) {
        try {
            $actionImageId = cpmsActionStoreImage(
                $conn,
                $propertyId,
                $action,
                $file,
                $phase,
                $caption,
                $uploadRoot
            );
            if ($sourceImageId > 0) {
                cpmsAssignmentAttachSourceImage(
                    $conn,
                    $propertyId,
                    $actionId,
                    $actionImageId,
                    $sourceImageId
                );
            }
            $saved++;
        } catch (Throwable $exception) {
            $prefix = $saved > 0
                ? $saved . ' gambar telah disimpan. '
                : '';
            throw new RuntimeException(
                $prefix . 'Gambar #' . ($index + 1) . ' gagal: '
                . $exception->getMessage()
            );
        }
    }

    cpmsAssignmentLog(
        $conn,
        $propertyId,
        (int) $action['inspection_id'],
        (int) $action['id'],
        'evidence_uploaded',
        (string) $action['status'],
        (string) $action['status'],
        $saved . ' ' . $phase . ' image(s) uploaded.'
            . ($sourceImageId > 0
                ? ' Source image #' . $sourceImageId . '.'
                : '')
    );
    return $saved;
}

function cpmsAssignmentStaffUpdateStatus(
    mysqli $conn,
    int $propertyId,
    int $systemUserId,
    int $actionId,
    string $nextStatus,
    string $notes
): void {
    $action = cpmsAssignmentStaffAction(
        $conn,
        $propertyId,
        $systemUserId,
        $actionId
    );
    if (!$action) {
        throw new RuntimeException('Tugasan tidak dijumpai atau bukan milik anda.');
    }
    if (in_array((string) $action['status'], ['Verified', 'Closed'], true)) {
        throw new RuntimeException('Tugasan yang telah disahkan tidak boleh diubah.');
    }
    if (!in_array($nextStatus, ['Open', 'In Progress', 'Rectified'], true)) {
        throw new InvalidArgumentException('Status tugasan tidak sah.');
    }

    $notes = trim($notes);
    if ($nextStatus === 'Rectified') {
        if ($notes === '') {
            throw new InvalidArgumentException(
                'Catatan kerja diperlukan sebelum Submit for Review.'
            );
        }
        $counts = cpmsAssignmentEvidenceCounts($conn, $propertyId, $actionId);
        if ((int) $counts['After'] < 1) {
            throw new InvalidArgumentException(
                'Sekurang-kurangnya satu gambar After diperlukan.'
            );
        }
        if ((int) ($action['finding_id'] ?? 0) > 0) {
            if (!cpmsAssignmentPhotoPairingReady($conn)) {
                throw new RuntimeException(
                    'Jalankan migration 20260810_0062 sebelum Submit Rectified.'
                );
            }
            $pairSummary = cpmsAssignmentPhotoPairSummary(
                $conn,
                $propertyId,
                $actionId
            );
            if ((int) $pairSummary['remaining'] > 0) {
                throw new InvalidArgumentException(
                    'Masih ada ' . (int) $pairSummary['remaining']
                    . ' gambar Inspector yang belum mempunyai gambar After.'
                );
            }
        }
        if ((string) ($action['supervisor_status'] ?? '') === 'Rejected'
            && !empty($action['supervisor_reviewed_at'])
        ) {
            $afterStmt = $conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM inspection_action_images
                 WHERE property_id = ? AND action_id = ?
                   AND image_phase = "After" AND created_at > ?'
            );
            $newAfter = 0;
            if ($afterStmt) {
                $reviewedAt = (string) $action['supervisor_reviewed_at'];
                $afterStmt->bind_param(
                    'iis',
                    $propertyId,
                    $actionId,
                    $reviewedAt
                );
                $afterStmt->execute();
                $afterRow = $afterStmt->get_result()->fetch_assoc();
                $afterStmt->close();
                $newAfter = (int) ($afterRow['total'] ?? 0);
            }
            if ($newAfter < 1) {
                throw new InvalidArgumentException(
                    'Selepas rejection, upload sekurang-kurangnya satu gambar After baharu.'
                );
            }
        }
    }

    $oldStatus = (string) $action['status'];
    if ((int) ($action['finding_id'] ?? 0) < 1) {
        if (!cpmsActionUpdateStatus(
            $conn,
            $propertyId,
            $actionId,
            $nextStatus,
            $notes
        )) {
            throw new RuntimeException('Status tugasan lama gagal dikemas kini.');
        }
        cpmsAssignmentLog(
            $conn,
            $propertyId,
            (int) $action['inspection_id'],
            $actionId,
            'legacy_staff_status',
            $oldStatus,
            $nextStatus,
            $notes
        );
        return;
    }

    $supervisorStatus = $nextStatus === 'Rectified'
        ? 'Pending Review'
        : 'Not Submitted';
    $findingStatus = $nextStatus === 'Rectified'
        ? 'Rectified'
        : ($nextStatus === 'In Progress' ? 'In Progress' : 'Assigned');

    $conn->begin_transaction();
    try {
        if (!cpmsActionUpdateStatus(
            $conn,
            $propertyId,
            $actionId,
            $nextStatus,
            $notes
        )) {
            throw new RuntimeException('Status tugasan gagal dikemas kini.');
        }

        $link = $conn->prepare(
            'UPDATE inspection_finding_action_links
             SET supervisor_status = ?, supervisor_reviewed_by_user_id = NULL,
                 supervisor_reviewed_by_name = NULL, supervisor_remarks = NULL,
                 supervisor_reviewed_at = NULL
             WHERE action_id = ? AND property_id = ?'
        );
        if (!$link) {
            throw new RuntimeException('Supervisor queue tidak dapat disediakan.');
        }
        $link->bind_param('sii', $supervisorStatus, $actionId, $propertyId);
        if (!$link->execute()) {
            $error = $link->error;
            $link->close();
            throw new RuntimeException('Supervisor queue gagal dikemas kini: ' . $error);
        }
        $link->close();

        $finding = $conn->prepare(
            'UPDATE inspection_findings
             SET status = ?
             WHERE id = ? AND inspection_id = ? AND property_id = ?'
        );
        if (!$finding) {
            throw new RuntimeException('Status finding tidak dapat disediakan.');
        }
        $findingId = (int) $action['finding_id'];
        $inspectionId = (int) $action['inspection_id'];
        $finding->bind_param(
            'siii',
            $findingStatus,
            $findingId,
            $inspectionId,
            $propertyId
        );
        if (!$finding->execute()) {
            $error = $finding->error;
            $finding->close();
            throw new RuntimeException('Status finding gagal dikemas kini: ' . $error);
        }
        $finding->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    cpmsAssignmentLog(
        $conn,
        $propertyId,
        (int) $action['inspection_id'],
        $actionId,
        $nextStatus === 'Rectified' ? 'submitted_to_supervisor' : 'staff_status',
        $oldStatus,
        $nextStatus,
        $notes
    );
    if ($nextStatus === 'Rectified') {
        foreach (cpmsAssignmentPropertyReviewers($conn, $propertyId) as $reviewerId) {
            cpmsAssignmentNotifyUser(
                $conn,
                $propertyId,
                $reviewerId,
                'hq-finding-supervisor-review-' . $actionId,
                'inspection_supervisor_review',
                'Pembaikan menunggu semakan Supervisor',
                (string) $action['action_no'] . ' telah dihantar oleh '
                    . (string) $action['assigned_name'] . '.',
                'inspection_action_view.php?id=' . $actionId,
                'warning',
                $actionId
            );
        }
    }
}

function cpmsAssignmentSupervisorReview(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    string $decision,
    string $remarks
): void {
    $link = cpmsAssignmentLinkByAction($conn, $propertyId, $actionId);
    $action = cpmsActionFind($conn, $propertyId, $actionId);
    if (!$link || !$action) {
        throw new RuntimeException('Finding assignment tidak dijumpai.');
    }
    if ((string) $action['status'] !== 'Rectified') {
        throw new RuntimeException(
            'Hanya pembaikan berstatus Rectified boleh disemak.'
        );
    }
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Keputusan Supervisor tidak sah.');
    }

    $remarks = trim($remarks);
    if ($decision === 'Rejected' && $remarks === '') {
        throw new InvalidArgumentException(
            'Sebab penolakan diperlukan untuk dikembalikan kepada staff.'
        );
    }
    $oldStatus = (string) $action['status'];
    $reviewerId = function_exists('cpmsActionCurrentUserId')
        ? cpmsActionCurrentUserId()
        : (int) ($_SESSION['cpms_user_id'] ?? 0);
    $reviewerName = function_exists('cpmsActionCurrentUserName')
        ? cpmsActionCurrentUserName()
        : trim((string) ($_SESSION['property_admin_name'] ?? 'Supervisor'));
    $findingStatus = $decision === 'Approved' ? 'Rectified' : 'In Progress';

    $conn->begin_transaction();
    try {
        if ($decision === 'Rejected') {
            $returnToStaff = $conn->prepare(
                'UPDATE inspection_corrective_actions
                 SET status = "In Progress", verified_by_user_id = NULL,
                     verified_by_name = NULL, verified_at = NULL
                 WHERE id = ? AND property_id = ? AND status = "Rectified"'
            );
            if (!$returnToStaff) {
                throw new RuntimeException(
                    'Status return-to-staff tidak dapat disediakan.'
                );
            }
            $returnToStaff->bind_param('ii', $actionId, $propertyId);
            if (!$returnToStaff->execute()
                || $returnToStaff->affected_rows !== 1
            ) {
                $error = $returnToStaff->error;
                $returnToStaff->close();
                throw new RuntimeException(
                    'Tugasan gagal dikembalikan kepada staff: ' . $error
                );
            }
            $returnToStaff->close();
        }

        $review = $conn->prepare(
            'UPDATE inspection_finding_action_links
             SET supervisor_status = ?,
                 supervisor_reviewed_by_user_id = NULLIF(?, 0),
                 supervisor_reviewed_by_name = NULLIF(?, ""),
                 supervisor_remarks = NULLIF(?, ""),
                 supervisor_reviewed_at = NOW()
             WHERE action_id = ? AND property_id = ?'
        );
        if (!$review) {
            throw new RuntimeException('Supervisor review tidak dapat disediakan.');
        }
        $review->bind_param(
            'sissii',
            $decision,
            $reviewerId,
            $reviewerName,
            $remarks,
            $actionId,
            $propertyId
        );
        if (!$review->execute()) {
            $error = $review->error;
            $review->close();
            throw new RuntimeException('Supervisor review gagal disimpan: ' . $error);
        }
        $review->close();

        $finding = $conn->prepare(
            'UPDATE inspection_findings SET status = ?
             WHERE id = ? AND inspection_id = ? AND property_id = ?'
        );
        if (!$finding) {
            throw new RuntimeException('Status finding tidak dapat disediakan.');
        }
        $findingId = (int) $link['finding_id'];
        $inspectionId = (int) $link['inspection_id'];
        $finding->bind_param(
            'siii',
            $findingStatus,
            $findingId,
            $inspectionId,
            $propertyId
        );
        if (!$finding->execute()) {
            $error = $finding->error;
            $finding->close();
            throw new RuntimeException('Status finding gagal dikemas kini: ' . $error);
        }
        $finding->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    cpmsAssignmentLog(
        $conn,
        $propertyId,
        (int) $link['inspection_id'],
        $actionId,
        $decision === 'Approved'
            ? 'supervisor_approved'
            : 'supervisor_rejected',
        $oldStatus,
        $decision === 'Approved' ? 'Rectified' : 'In Progress',
        $remarks
    );
    if ($decision === 'Rejected') {
        cpmsAssignmentNotifyUser(
            $conn,
            $propertyId,
            (int) ($action['assigned_system_user_id'] ?? 0),
            'hq-finding-supervisor-rejected-' . $actionId,
            'inspection_supervisor_rejected',
            'Bukti pembaikan ditolak Supervisor',
            (string) $action['action_no'] . ': ' . $remarks,
            'staff_corrective_action_view.php?id=' . $actionId,
            'danger',
            $actionId
        );
    }
}
