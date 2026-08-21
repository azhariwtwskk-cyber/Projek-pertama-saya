<?php
declare(strict_types=1);

/**
 * CPMS v3.2.6 - HQ Inspection Finding & Submission Service
 * PHP 7.4 compatible.
 */

function cpmsFindingTableExists(mysqli $conn, string $table): bool
{
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

function cpmsFindingColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
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

function cpmsFindingTablesReady(mysqli $conn): bool
{
    foreach ([
        'inspection_finding_master',
        'inspection_findings',
        'inspection_finding_images',
        'inspection_delivery_log',
    ] as $table) {
        if (!cpmsFindingTableExists($conn, $table)) {
            return false;
        }
    }
    return true;
}

function cpmsFindingPhotoLimit(mysqli $conn, int $propertyId): int
{
    $default = 100;
    $hardMaximum = 300;

    if ($propertyId < 1
        || !cpmsFindingTableExists($conn, 'inspection_property_settings')
    ) {
        return $default;
    }

    $stmt = $conn->prepare(
        'SELECT max_photos_per_inspection
         FROM inspection_property_settings
         WHERE property_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $limit = (int) ($row['max_photos_per_inspection'] ?? $default);
    return max(30, min($hardMaximum, $limit));
}

function cpmsFindingPhotoRemaining(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): int {
    $limit = cpmsFindingPhotoLimit($conn, $propertyId);
    $used = cpmsInspectionCountImages($conn, $propertyId, $inspectionId);
    return max(0, $limit - $used);
}

function cpmsFindingMasterList(mysqli $conn, int $propertyId): array
{
    $stmt = $conn->prepare(
        "SELECT id, property_id, finding_code, category, finding_name,
                default_severity, recommendation_template, display_order
         FROM inspection_finding_master
         WHERE status = 'active'
           AND (property_id IS NULL OR property_id = ?)
         ORDER BY category ASC, display_order ASC, finding_name ASC"
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

function cpmsFindingMasterFind(
    mysqli $conn,
    int $propertyId,
    int $masterId
): ?array {
    $stmt = $conn->prepare(
        "SELECT id, property_id, finding_code, category, finding_name,
                default_severity, recommendation_template
         FROM inspection_finding_master
         WHERE id = ?
           AND status = 'active'
           AND (property_id IS NULL OR property_id = ?)
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $masterId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsFindingFind(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId
): ?array {
    $stmt = $conn->prepare(
        'SELECT *
         FROM inspection_findings
         WHERE id = ?
           AND inspection_id = ?
           AND property_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iii', $findingId, $inspectionId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsFindingUpdateDraft(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId,
    int $masterId,
    string $location,
    string $remarks,
    string $recommendation
): bool {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection || (string) ($inspection['status'] ?? '') !== 'Draft') {
        throw new RuntimeException(
            'Finding hanya boleh dikemas kini semasa inspection masih Draft.'
        );
    }

    $finding = cpmsFindingFind(
        $conn,
        $propertyId,
        $inspectionId,
        $findingId
    );
    if (!$finding) {
        throw new RuntimeException('Finding tidak dijumpai.');
    }

    $master = cpmsFindingMasterFind($conn, $propertyId, $masterId);
    if (!$master) {
        throw new InvalidArgumentException('Sila pilih finding yang sah.');
    }

    $location = trim($location);
    if ($location === '') {
        throw new InvalidArgumentException('Lokasi finding diperlukan.');
    }

    $severity = (string) ($master['default_severity'] ?? 'Medium');
    if (!in_array($severity, ['Low', 'Medium', 'High', 'Critical'], true)) {
        $severity = 'Medium';
    }

    $recommendation = trim($recommendation);
    if ($recommendation === '') {
        $recommendation = trim(
            (string) ($master['recommendation_template'] ?? '')
        );
    }

    $category = (string) $master['category'];
    $findingName = (string) $master['finding_name'];
    $remarks = trim($remarks);

    $stmt = $conn->prepare(
        'UPDATE inspection_findings
         SET finding_master_id = ?, category = ?, finding_name = ?,
             severity = ?, location = ?, remarks = NULLIF(?, ""),
             recommendation = NULLIF(?, "")
         WHERE id = ? AND inspection_id = ? AND property_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Finding tidak dapat disediakan untuk update.');
    }
    $stmt->bind_param(
        'issssssiii',
        $masterId,
        $category,
        $findingName,
        $severity,
        $location,
        $remarks,
        $recommendation,
        $findingId,
        $inspectionId,
        $propertyId
    );
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        throw new RuntimeException('Finding tidak dapat dikemas kini: ' . $error);
    }
    return $affected >= 0;
}

function cpmsFindingDeleteImageDraft(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId,
    int $imageId,
    string $cpmsRoot
): bool {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection || (string) ($inspection['status'] ?? '') !== 'Draft') {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT i.image_path
         FROM inspection_finding_images fi
         INNER JOIN inspection_images i
            ON i.id = fi.inspection_image_id
           AND i.inspection_id = fi.inspection_id
           AND i.property_id = fi.property_id
         WHERE fi.finding_id = ?
           AND fi.inspection_id = ?
           AND fi.property_id = ?
           AND fi.inspection_image_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiii', $findingId, $inspectionId, $propertyId, $imageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }

    $delete = $conn->prepare(
        'DELETE FROM inspection_images
         WHERE id = ? AND inspection_id = ? AND property_id = ?'
    );
    if (!$delete) {
        return false;
    }
    $delete->bind_param('iii', $imageId, $inspectionId, $propertyId);
    $ok = $delete->execute();
    $affected = $delete->affected_rows;
    $delete->close();
    if (!$ok || $affected !== 1) {
        return false;
    }

    $relative = ltrim((string) ($row['image_path'] ?? ''), '/\\');
    if ($relative !== '' && strpos($relative, '..') === false) {
        $path = rtrim($cpmsRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    return true;
}

function cpmsFindingCreate(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $masterId,
    string $location,
    string $remarks,
    string $recommendation,
    int $inspectorId,
    string $inspectorName
): int {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection) {
        throw new RuntimeException('Inspection tidak dijumpai.');
    }
    if ((string) ($inspection['status'] ?? '') !== 'Draft') {
        throw new RuntimeException(
            'Finding hanya boleh ditambah semasa inspection masih Draft.'
        );
    }

    $master = cpmsFindingMasterFind($conn, $propertyId, $masterId);
    if (!$master) {
        throw new InvalidArgumentException('Sila pilih finding daripada senarai.');
    }

    $location = trim($location);
    if ($location === '') {
        throw new InvalidArgumentException('Lokasi finding diperlukan.');
    }

    $severity = (string) ($master['default_severity'] ?? 'Medium');
    if (!in_array($severity, ['Low', 'Medium', 'High', 'Critical'], true)) {
        $severity = 'Medium';
    }

    $recommendation = trim($recommendation);
    if ($recommendation === '') {
        $recommendation = trim(
            (string) ($master['recommendation_template'] ?? '')
        );
    }

    $category = (string) $master['category'];
    $findingName = (string) $master['finding_name'];
    $remarks = trim($remarks);
    $inspectorName = trim($inspectorName);

    $stmt = $conn->prepare(
        'INSERT INTO inspection_findings (
            inspection_id, property_id, finding_master_id,
            category, finding_name, severity, location,
            remarks, recommendation, status,
            created_by_hq_inspector_id, created_by_name
         ) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ""), NULLIF(?, ""),
                   "Open", NULLIF(?, 0), NULLIF(?, ""))'
    );
    if (!$stmt) {
        throw new RuntimeException('Tidak dapat menyediakan rekod finding.');
    }
    $stmt->bind_param(
        'iii' . 'ssssss' . 'is',
        $inspectionId,
        $propertyId,
        $masterId,
        $category,
        $findingName,
        $severity,
        $location,
        $remarks,
        $recommendation,
        $inspectorId,
        $inspectorName
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Finding tidak dapat disimpan: ' . $error);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function cpmsFindingLinkImage(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId,
    int $imageId
): void {
    $stmt = $conn->prepare(
        'INSERT INTO inspection_finding_images
            (finding_id, inspection_id, property_id, inspection_image_id)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to link the image to the finding.');
    }
    $stmt->bind_param(
        'iiii',
        $findingId,
        $inspectionId,
        $propertyId,
        $imageId
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Image link failed: ' . $error);
    }
    $stmt->close();
}

function cpmsFindingRemoveUnlinkedImage(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $imageId,
    string $cpmsRoot
): void {
    $stmt = $conn->prepare(
        'SELECT image_path
         FROM inspection_images
         WHERE id = ? AND inspection_id = ? AND property_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iii', $imageId, $inspectionId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return;
    }

    $delete = $conn->prepare(
        'DELETE FROM inspection_images
         WHERE id = ? AND inspection_id = ? AND property_id = ?'
    );
    if ($delete) {
        $delete->bind_param('iii', $imageId, $inspectionId, $propertyId);
        $delete->execute();
        $delete->close();
    }

    $relative = ltrim((string) ($row['image_path'] ?? ''), '/\\');
    if ($relative !== '' && strpos($relative, '..') === false) {
        $path = rtrim($cpmsRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function cpmsFindingList(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    $stmt = $conn->prepare(
        'SELECT f.*,
                COUNT(fi.id) AS image_count
         FROM inspection_findings f
         LEFT JOIN inspection_finding_images fi
           ON fi.finding_id = f.id
          AND fi.inspection_id = f.inspection_id
          AND fi.property_id = f.property_id
         WHERE f.property_id = ?
           AND f.inspection_id = ?
         GROUP BY f.id
         ORDER BY f.id ASC'
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

function cpmsFindingImages(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId
): array {
    $stmt = $conn->prepare(
        'SELECT i.*
         FROM inspection_finding_images fi
         INNER JOIN inspection_images i
           ON i.id = fi.inspection_image_id
          AND i.inspection_id = fi.inspection_id
          AND i.property_id = fi.property_id
         WHERE fi.property_id = ?
           AND fi.inspection_id = ?
           AND fi.finding_id = ?
         ORDER BY i.created_at ASC, i.id ASC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('iii', $propertyId, $inspectionId, $findingId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsFindingDeleteDraft(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $findingId,
    string $cpmsRoot
): bool {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection || (string) $inspection['status'] !== 'Draft') {
        return false;
    }

    $images = cpmsFindingImages($conn, $propertyId, $inspectionId, $findingId);
    $imageIds = [];
    foreach ($images as $image) {
        $imageIds[] = (int) $image['id'];
    }

    $stmt = $conn->prepare(
        'DELETE FROM inspection_findings
         WHERE id = ? AND inspection_id = ? AND property_id = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $findingId, $inspectionId, $propertyId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!$ok || $affected !== 1) {
        return false;
    }

    foreach ($images as $image) {
        $imageId = (int) $image['id'];
        $delete = $conn->prepare(
            'DELETE FROM inspection_images
             WHERE id = ? AND inspection_id = ? AND property_id = ?'
        );
        if ($delete) {
            $delete->bind_param('iii', $imageId, $inspectionId, $propertyId);
            $delete->execute();
            $delete->close();
        }
        $relative = ltrim((string) ($image['image_path'] ?? ''), '/\\');
        if ($relative !== '' && strpos($relative, '..') === false) {
            $path = rtrim($cpmsRoot, '/\\')
                . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    return true;
}

function cpmsInspectionDeleteDraft(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    string $cpmsRoot
): bool {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection || (string) ($inspection['status'] ?? '') !== 'Draft') {
        return false;
    }

    $images = [];
    $imageStmt = $conn->prepare(
        'SELECT id, image_path
         FROM inspection_images
         WHERE inspection_id = ? AND property_id = ?'
    );
    if ($imageStmt) {
        $imageStmt->bind_param('ii', $inspectionId, $propertyId);
        $imageStmt->execute();
        $result = $imageStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $images[] = $row;
        }
        $imageStmt->close();
    }

    $conn->begin_transaction();
    try {
        $deleteTables = [
            'inspection_action_images' => 'inspection_id = ? AND property_id = ?',
            'inspection_action_progress_log' => 'inspection_id = ? AND property_id = ?',
            'inspection_finding_action_links' => 'inspection_id = ? AND property_id = ?',
            'inspection_checklist_action_links' => 'inspection_id = ? AND property_id = ?',
            'inspection_corrective_actions' => 'inspection_id = ? AND property_id = ?',
            'inspection_finding_images' => 'inspection_id = ? AND property_id = ?',
            'inspection_delivery_log' => 'inspection_id = ? AND property_id = ?',
            'inspection_reinspection_links' => '(original_inspection_id = ? OR reinspection_id = ?) AND property_id = ?',
            'inspection_checklist_items' => 'inspection_id = ? AND property_id = ?',
            'inspection_images' => 'inspection_id = ? AND property_id = ?',
            'inspection_findings' => 'inspection_id = ? AND property_id = ?',
        ];

        foreach ($deleteTables as $table => $where) {
            if (!cpmsFindingTableExists($conn, $table)) {
                continue;
            }
            $stmt = $conn->prepare('DELETE FROM ' . $table . ' WHERE ' . $where);
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare cleanup for ' . $table . '.');
            }
            if ($table === 'inspection_reinspection_links') {
                $stmt->bind_param('iii', $inspectionId, $inspectionId, $propertyId);
            } else {
                $stmt->bind_param('ii', $inspectionId, $propertyId);
            }
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Unable to clean ' . $table . ': ' . $error);
            }
            $stmt->close();
        }

        if (cpmsFindingTableExists($conn, 'inspection_checklist_assessments')) {
            $assessmentIds = [];
            $assessmentStmt = $conn->prepare(
                'SELECT id
                 FROM inspection_checklist_assessments
                 WHERE inspection_id = ? AND property_id = ?'
            );
            if ($assessmentStmt) {
                $assessmentStmt->bind_param('ii', $inspectionId, $propertyId);
                $assessmentStmt->execute();
                $result = $assessmentStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $assessmentIds[] = (int) $row['id'];
                }
                $assessmentStmt->close();
            }
            foreach ($assessmentIds as $assessmentId) {
                if (cpmsFindingTableExists($conn, 'inspection_checklist_assessment_items')) {
                    $itemStmt = $conn->prepare(
                        'DELETE FROM inspection_checklist_assessment_items
                         WHERE assessment_id = ?'
                    );
                    if ($itemStmt) {
                        $itemStmt->bind_param('i', $assessmentId);
                        $itemStmt->execute();
                        $itemStmt->close();
                    }
                }
            }
            $deleteAssessments = $conn->prepare(
                'DELETE FROM inspection_checklist_assessments
                 WHERE inspection_id = ? AND property_id = ?'
            );
            if ($deleteAssessments) {
                $deleteAssessments->bind_param('ii', $inspectionId, $propertyId);
                $deleteAssessments->execute();
                $deleteAssessments->close();
            }
        }

        $reportStmt = $conn->prepare(
            'DELETE FROM inspection_reports
             WHERE id = ? AND property_id = ? AND status = "Draft"'
        );
        if (!$reportStmt) {
            throw new RuntimeException('Unable to prepare draft deletion.');
        }
        $reportStmt->bind_param('ii', $inspectionId, $propertyId);
        if (!$reportStmt->execute()) {
            $error = $reportStmt->error;
            $reportStmt->close();
            throw new RuntimeException('Unable to delete draft inspection: ' . $error);
        }
        $affected = $reportStmt->affected_rows;
        $reportStmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('Draft inspection was not deleted.');
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    foreach ($images as $image) {
        $relative = ltrim((string) ($image['image_path'] ?? ''), '/\\');
        if ($relative !== '' && strpos($relative, '..') === false) {
            $path = rtrim($cpmsRoot, '/\\')
                . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    return true;
}

function cpmsFindingFiles(array $files): array
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }
    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $normalized;
}

function cpmsFindingValidateImageBatch(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    array $files,
    int $maximumImages = 0
): void {
    if (!$files) {
        throw new RuntimeException('Please select at least one photo.');
    }

    if ($maximumImages < 1) {
        $maximumImages = cpmsFindingPhotoLimit($conn, $propertyId);
    }
    $maximumImages = min(300, $maximumImages);

    $current = cpmsInspectionCountImages($conn, $propertyId, $inspectionId);
    if (($current + count($files)) > $maximumImages) {
        throw new RuntimeException(
            'The number of photos exceeds the maximum limit of ' . $maximumImages
            . ' bagi satu inspection.'
        );
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files as $file) {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One of the photos failed to upload.');
        }
        if ((int) ($file['size'] ?? 0) > (8 * 1024 * 1024)) {
            throw new RuntimeException('One of the photos exceeds the 8 MB limit.');
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('One of the image files is invalid.');
        }
        $mime = (string) $finfo->file($tmpName);
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Hanya JPG, PNG dan WebP dibenarkan.');
        }
    }
}

function cpmsFindingSeverityRank(string $severity): int
{
    $map = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4];
    return $map[$severity] ?? 2;
}

function cpmsFindingSyncLegacySummary(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): void {
    $findings = cpmsFindingList($conn, $propertyId, $inspectionId);
    $findingLines = [];
    $recommendationLines = [];
    $highest = 'Low';

    foreach ($findings as $index => $finding) {
        $n = $index + 1;
        $severity = (string) $finding['severity'];
        if (cpmsFindingSeverityRank($severity) > cpmsFindingSeverityRank($highest)) {
            $highest = $severity;
        }
        $findingLines[] = $n . '. [' . $severity . '] '
            . (string) $finding['finding_name'] . ' - '
            . (string) $finding['location'];
        $recommendation = trim((string) ($finding['recommendation'] ?? ''));
        if ($recommendation !== '') {
            $recommendationLines[] = $n . '. ' . $recommendation;
        }
    }

    $findingText = implode("\n", $findingLines);
    $recommendationText = implode("\n", $recommendationLines);

    $stmt = $conn->prepare(
        'UPDATE inspection_reports
         SET finding = NULLIF(?, ""),
             recommendation = NULLIF(?, ""),
             priority = ?, risk_level = ?
         WHERE id = ? AND property_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param(
            'ssssii',
            $findingText,
            $recommendationText,
            $highest,
            $highest,
            $inspectionId,
            $propertyId
        );
        $stmt->execute();
        $stmt->close();
    }
}

function cpmsFindingProperty(mysqli $conn, int $propertyId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, property_code, property_name, company_name, email
         FROM cpms_properties
         WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsFindingPropertyTeam(mysqli $conn, int $propertyId): array
{
    $rows = [];
    if (!cpmsFindingTableExists($conn, 'property_admins')) {
        return $rows;
    }
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, role
         FROM property_admins
         WHERE property_id = ?
           AND status = 'active'
           AND role IN ('supervisor','manager','property_admin')
         ORDER BY CASE role
                    WHEN 'supervisor' THEN 1
                    WHEN 'manager' THEN 2
                    ELSE 3
                  END,
                  id ASC"
    );
    if (!$stmt) {
        return $rows;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsFindingPropertyRecipients(mysqli $conn, int $propertyId): array
{
    $rows = [];
    foreach (cpmsFindingPropertyTeam($conn, $propertyId) as $row) {
        if (filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function cpmsFindingComplianceRecipients(mysqli $conn): array
{
    if (!cpmsFindingTableExists($conn, 'system_users')
        || !cpmsFindingTableExists($conn, 'user_roles')
        || !cpmsFindingTableExists($conn, 'roles')
        || !cpmsFindingColumnExists($conn, 'system_users', 'email')
    ) {
        return [];
    }

    $nameColumn = cpmsFindingColumnExists($conn, 'system_users', 'full_name')
        ? 'u.full_name'
        : 'u.username';
    $sql = "SELECT DISTINCT u.id, " . $nameColumn . " AS full_name,
                   u.email, r.role_code AS role
            FROM system_users u
            INNER JOIN user_roles ur
              ON ur.system_user_id = u.id
             AND ur.status = 'active'
            INNER JOIN roles r
              ON r.id = ur.role_id
             AND r.role_code = 'compliance_manager'
             AND r.status = 'active'
            WHERE u.status = 'active'
              AND u.email IS NOT NULL
              AND u.email <> ''";
    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return [];
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        if (filter_var((string) $row['email'], FILTER_VALIDATE_EMAIL)) {
            $rows[] = $row;
        }
    }
    $result->free();
    return $rows;
}

function cpmsFindingEmailRecipients(mysqli $conn, int $propertyId): array
{
    $property = cpmsFindingProperty($conn, $propertyId);
    $propertyUsers = cpmsFindingPropertyRecipients($conn, $propertyId);

    $preferredRole = '';
    foreach (['supervisor', 'manager', 'property_admin'] as $role) {
        foreach ($propertyUsers as $row) {
            if ((string) $row['role'] === $role) {
                $preferredRole = $role;
                break 2;
            }
        }
    }

    $to = [];
    $cc = [];
    foreach ($propertyUsers as $row) {
        $entry = [
            'email' => strtolower(trim((string) $row['email'])),
            'name' => (string) $row['full_name'],
            'role' => (string) $row['role'],
        ];
        if ((string) $row['role'] === $preferredRole) {
            $to[$entry['email']] = $entry;
        } else {
            $cc[$entry['email']] = $entry;
        }
    }

    if (!$to && $property) {
        $propertyEmail = strtolower(trim((string) ($property['email'] ?? '')));
        if (filter_var($propertyEmail, FILTER_VALIDATE_EMAIL)) {
            $to[$propertyEmail] = [
                'email' => $propertyEmail,
                'name' => (string) ($property['property_name'] ?? 'Property'),
                'role' => 'property_email_fallback',
            ];
        }
    }

    foreach (cpmsFindingComplianceRecipients($conn) as $row) {
        $email = strtolower(trim((string) $row['email']));
        if (!isset($to[$email])) {
            $cc[$email] = [
                'email' => $email,
                'name' => (string) ($row['full_name'] ?? 'Compliance Manager'),
                'role' => 'compliance_manager',
            ];
        }
    }

    return [
        'to' => array_values($to),
        'cc' => array_values($cc),
        'property' => $property,
    ];
}

function cpmsFindingAbsoluteInspectionUrl(int $inspectionId): string
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') || $forwarded === 'https'
        ? 'https'
        : 'http';
    $host = preg_replace(
        '/[^A-Za-z0-9.:-]/',
        '',
        (string) ($_SERVER['HTTP_HOST'] ?? 'cpmspro.my')
    );
    if ($host === '') {
        $host = 'cpmspro.my';
    }
    return $scheme . '://' . $host
        . '/cpms/property_portal/inspection_hq_findings.php?id=' . $inspectionId;
}

function cpmsFindingMailFromAddress(): string
{
    if (defined('CPMS_MAIL_FROM')) {
        $configured = strtolower(trim((string) constant('CPMS_MAIL_FROM')));
        if (filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }
    }

    /*
     * Keep the visible From domain aligned with CPMSPro's authenticated
     * SPF/DKIM/DMARC domain. Property/Gmail addresses belong in Reply-To,
     * never in From, because the CPMSPro server is not authorised to send as
     * gmail.com or another third-party property domain.
     */
    return 'no-reply@cpmspro.my';
}

function cpmsFindingInsertDeliveryLog(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    string $channel,
    string $triggerType,
    array $recipient,
    string $status,
    int $attemptNo,
    string $error = ''
): int {
    $email = (string) ($recipient['email'] ?? '');
    $name = (string) ($recipient['name'] ?? '');
    $role = (string) ($recipient['role'] ?? '');
    $stmt = $conn->prepare(
        'INSERT INTO inspection_delivery_log (
            inspection_id, property_id, channel, trigger_type,
            recipient, recipient_name, recipient_role,
            status, attempt_no, last_error
         ) VALUES (?, ?, ?, ?, ?, NULLIF(?, ""), NULLIF(?, ""), ?, ?, NULLIF(?, ""))'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param(
        'ii' . 'ssssss' . 'is',
        $inspectionId,
        $propertyId,
        $channel,
        $triggerType,
        $email,
        $name,
        $role,
        $status,
        $attemptNo,
        $error
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function cpmsFindingDeliveryAttemptNo(
    mysqli $conn,
    int $inspectionId,
    string $recipient
): int {
    $stmt = $conn->prepare(
        'SELECT COALESCE(MAX(attempt_no), 0) + 1 AS next_attempt
         FROM inspection_delivery_log
         WHERE inspection_id = ? AND recipient = ? AND channel = "email"'
    );
    if (!$stmt) {
        return 1;
    }
    $stmt->bind_param('is', $inspectionId, $recipient);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return max(1, (int) ($row['next_attempt'] ?? 1));
}

function cpmsFindingUpdateDeliveryLog(
    mysqli $conn,
    int $logId,
    string $status,
    string $error = ''
): void {
    if ($logId < 1) {
        return;
    }
    $sentSql = $status === 'Sent' ? ', sent_at = NOW()' : '';
    $stmt = $conn->prepare(
        'UPDATE inspection_delivery_log
         SET status = ?, last_error = NULLIF(?, "")' . $sentSql . '
         WHERE id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('ssi', $status, $error, $logId);
        $stmt->execute();
        $stmt->close();
    }
}

function cpmsFindingSendSubmissionEmail(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    bool $retry = false
): array {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    $resolved = cpmsFindingEmailRecipients($conn, $propertyId);
    $to = $resolved['to'];
    $cc = $resolved['cc'];
    $property = $resolved['property'];

    if (!$inspection || !$property) {
        return ['ok' => false, 'message' => 'Inspection/property tidak dijumpai.'];
    }

    if (!$to) {
        cpmsFindingInsertDeliveryLog(
            $conn,
            $propertyId,
            $inspectionId,
            'email',
            $retry ? 'retry' : 'initial',
            ['email' => '(missing)', 'name' => '', 'role' => 'missing_recipient'],
            'Failed',
            1,
            'No Supervisor, Manager, Property Admin or official property email is available.'
        );
        return [
            'ok' => false,
            'message' => 'E-mel tidak dihantar: tiada alamat e-mel penerima yang sah.',
        ];
    }

    $findings = cpmsFindingList($conn, $propertyId, $inspectionId);
    $subject = 'CPMS Inspection Submitted - '
        . (string) $inspection['inspection_no'];
    $lines = [
        'CPMSPro - Inspection Report Submitted',
        '',
        'Property: ' . (string) $property['property_name']
            . ' (' . (string) $property['property_code'] . ')',
        'Inspection No: ' . (string) $inspection['inspection_no'],
        'Inspection Date: ' . (string) $inspection['inspection_date'],
        'Inspector: ' . (string) ($inspection['reported_by_name'] ?? '-'),
        'Location: ' . (string) $inspection['location'],
        'Findings: ' . count($findings),
        'Highest Severity: ' . (string) $inspection['priority'],
        '',
    ];
    foreach ($findings as $index => $finding) {
        $lines[] = ($index + 1) . '. [' . (string) $finding['severity'] . '] '
            . (string) $finding['finding_name']
            . ' - ' . (string) $finding['location'];
    }
    $lines[] = '';
    $lines[] = 'Open the secure CPMS portal to review photos and assign rectification:';
    $lines[] = cpmsFindingAbsoluteInspectionUrl($inspectionId);
    $lines[] = '';
    $lines[] = 'This is an automated CPMSPro notification.';
    $body = implode("\r\n", $lines);

    $toEmails = array_column($to, 'email');
    $ccEmails = array_column($cc, 'email');
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $propertyEmail = strtolower(trim((string) ($property['email'] ?? '')));
    $from = cpmsFindingMailFromAddress();
    $headers[] = 'From: CPMSPro <' . $from . '>';
    if (filter_var($propertyEmail, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $propertyEmail;
    }
    $headers[] = 'Auto-Submitted: auto-generated';
    $headers[] = 'X-Mailer: CPMSPro Inspection Module';
    if ($ccEmails) {
        $headers[] = 'Cc: ' . implode(', ', $ccEmails);
    }

    $allRecipients = array_merge($to, $cc);
    $logIds = [];
    foreach ($allRecipients as $recipient) {
        $attempt = cpmsFindingDeliveryAttemptNo(
            $conn,
            $inspectionId,
            (string) $recipient['email']
        );
        $logIds[] = cpmsFindingInsertDeliveryLog(
            $conn,
            $propertyId,
            $inspectionId,
            'email',
            $retry ? 'retry' : 'initial',
            $recipient,
            $retry ? 'Retry' : 'Pending',
            $attempt
        );
    }

    $accepted = false;
    $mailError = '';
    if (!function_exists('mail')) {
        $mailError = 'PHP mail function is unavailable on this server.';
    } else {
        try {
            $accepted = @mail(
                implode(', ', $toEmails),
                $subject,
                $body,
                implode("\r\n", $headers)
            );
            if (!$accepted) {
                $mailError = 'PHP mail transport rejected the message.';
            }
        } catch (Throwable $exception) {
            $mailError = 'Mail transport error: ' . $exception->getMessage();
        }
    }

    foreach ($logIds as $logId) {
        cpmsFindingUpdateDeliveryLog(
            $conn,
            $logId,
            $accepted ? 'Sent' : 'Failed',
            $accepted ? '' : $mailError
        );
    }

    return [
        'ok' => $accepted,
        'message' => $accepted
            ? 'E-mel telah diterima oleh mail server untuk penghantaran.'
            : 'E-mel gagal dihantar. Rekod Failed telah disimpan untuk Retry.',
        'to' => $toEmails,
        'cc' => $ccEmails,
    ];
}

function cpmsFindingNotifyPropertyTeam(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): int {
    if (!cpmsFindingTableExists($conn, 'cpms_user_notifications')
        || !cpmsFindingTableExists($conn, 'system_users')
    ) {
        return 0;
    }

    $propertyUsers = cpmsFindingPropertyTeam($conn, $propertyId);
    $sourceIds = [];
    foreach ($propertyUsers as $row) {
        $sourceIds[] = (int) $row['id'];
    }
    if (!$sourceIds) {
        return 0;
    }

    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection) {
        return 0;
    }

    $created = 0;
    foreach ($sourceIds as $sourceId) {
        $stmt = $conn->prepare(
            "SELECT id FROM system_users
             WHERE source_table = 'property_admins'
               AND source_id = ?
               AND status = 'active'
             LIMIT 1"
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $userId = (int) ($row['id'] ?? 0);
        if ($userId < 1) {
            continue;
        }

        $key = 'inspection-submitted-' . $inspectionId;
        $title = 'New inspection submitted';
        $message = (string) $inspection['inspection_no']
            . ' oleh ' . (string) ($inspection['reported_by_name'] ?? 'HQ Inspector')
            . '. Please review the findings and assign the required rectification action.';
        $url = 'inspection_hq_findings.php?id=' . $inspectionId;
        $severity = cpmsFindingSeverityRank((string) $inspection['priority']) >= 3
            ? 'warning'
            : 'info';

        $insert = $conn->prepare(
            "INSERT INTO cpms_user_notifications (
                property_id, recipient_system_user_id,
                notification_key, notification_type, title,
                message, target_url, severity, related_type, related_id
             ) VALUES (?, ?, ?, 'inspection_submitted', ?, ?, ?, ?, 'inspection', ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                message = VALUES(message),
                target_url = VALUES(target_url),
                severity = VALUES(severity),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$insert) {
            continue;
        }
        $insert->bind_param(
            'iisssssi',
            $propertyId,
            $userId,
            $key,
            $title,
            $message,
            $url,
            $severity,
            $inspectionId
        );
        if ($insert->execute()) {
            $created++;
        }
        $insert->close();
    }
    return $created;
}

function cpmsFindingSubmitInspection(
    mysqli $conn,
    int $propertyId,
    int $inspectionId,
    int $inspectorId,
    string $inspectorName
): array {
    $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
    if (!$inspection) {
        throw new RuntimeException('Inspection tidak dijumpai.');
    }
    if ((string) $inspection['status'] !== 'Draft') {
        throw new RuntimeException('Inspection ini telah dihantar.');
    }

    $findings = cpmsFindingList($conn, $propertyId, $inspectionId);
    if (!$findings) {
        throw new RuntimeException(
            'Add at least one finding before submitting.'
        );
    }
    foreach ($findings as $finding) {
        if ((int) $finding['image_count'] < 1) {
            throw new RuntimeException(
                'Every finding must include at least one photo.'
            );
        }
    }

    cpmsFindingSyncLegacySummary($conn, $propertyId, $inspectionId);
    $ok = cpmsInspectionUpdateStatus(
        $conn,
        $propertyId,
        $inspectionId,
        'Submitted',
        'Submitted by HQ Inspector. Property team notification triggered.',
        $inspectorId,
        $inspectorName
    );
    if (!$ok) {
        throw new RuntimeException('Status inspection tidak dapat dihantar.');
    }

    $notifications = cpmsFindingNotifyPropertyTeam(
        $conn,
        $propertyId,
        $inspectionId
    );
    $email = cpmsFindingSendSubmissionEmail(
        $conn,
        $propertyId,
        $inspectionId,
        false
    );

    return [
        'notification_count' => $notifications,
        'email' => $email,
    ];
}

function cpmsFindingDeliveryHistory(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    $stmt = $conn->prepare(
        'SELECT * FROM inspection_delivery_log
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

function cpmsHqOperationalTablesReady(mysqli $conn): bool
{
    return cpmsFindingTableExists($conn, 'inspection_hq_action_reviews')
        && cpmsFindingTableExists($conn, 'inspection_reinspection_links');
}

function cpmsHqActionsByInspection(
    mysqli $conn,
    int $propertyId,
    int $inspectionId
): array {
    if (!cpmsFindingTableExists($conn, 'inspection_corrective_actions')) {
        return [];
    }

    $reviewColumn = cpmsFindingTableExists(
        $conn,
        'inspection_hq_action_reviews'
    )
        ? ', (SELECT hr.decision
              FROM inspection_hq_action_reviews hr
              WHERE hr.action_id = a.id AND hr.property_id = a.property_id
              ORDER BY hr.id DESC LIMIT 1) AS latest_hq_decision'
        : ', NULL AS latest_hq_decision';

    $assignmentColumns = cpmsFindingTableExists(
        $conn,
        'inspection_finding_action_links'
    )
        ? ', (SELECT l.finding_id
              FROM inspection_finding_action_links l
              WHERE l.action_id = a.id AND l.property_id = a.property_id
              LIMIT 1) AS source_finding_id,
             (SELECT l.supervisor_status
              FROM inspection_finding_action_links l
              WHERE l.action_id = a.id AND l.property_id = a.property_id
              LIMIT 1) AS supervisor_status'
        : ', NULL AS source_finding_id, NULL AS supervisor_status';

    $stmt = $conn->prepare(
        'SELECT a.*,
                (SELECT COUNT(*) FROM inspection_action_images ai
                 WHERE ai.action_id = a.id
                   AND ai.property_id = a.property_id) AS evidence_count'
        . $reviewColumn . $assignmentColumns . '
         FROM inspection_corrective_actions a
         WHERE a.property_id = ? AND a.inspection_id = ?
         ORDER BY
            CASE WHEN a.status = "Rectified" THEN 0 ELSE 1 END,
            CASE WHEN a.due_date IS NOT NULL AND a.due_date < CURDATE()
                 AND a.status NOT IN ("Verified", "Closed") THEN 0 ELSE 1 END,
            a.id DESC'
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

function cpmsHqActionFind(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    int $hqInspectorId
): ?array {
    if (!cpmsFindingTableExists($conn, 'inspection_corrective_actions')) {
        return null;
    }

    $assignmentReady = cpmsFindingTableExists(
        $conn,
        'inspection_finding_action_links'
    );
    $assignmentSelect = $assignmentReady
        ? ', l.finding_id AS source_finding_id, l.supervisor_status,
             l.supervisor_reviewed_by_name, l.supervisor_remarks,
             l.supervisor_reviewed_at,
             f.finding_name AS source_finding_name,
             f.location AS source_finding_location,
             f.severity AS source_finding_severity'
        : ', NULL AS source_finding_id, NULL AS supervisor_status,
             NULL AS supervisor_reviewed_by_name,
             NULL AS supervisor_remarks, NULL AS supervisor_reviewed_at,
             NULL AS source_finding_name, NULL AS source_finding_location,
             NULL AS source_finding_severity';
    $assignmentJoin = $assignmentReady
        ? ' LEFT JOIN inspection_finding_action_links l
               ON l.action_id = a.id AND l.property_id = a.property_id
            LEFT JOIN inspection_findings f
               ON f.id = l.finding_id
              AND f.inspection_id = l.inspection_id
              AND f.property_id = l.property_id '
        : '';

    $stmt = $conn->prepare(
        'SELECT a.*, r.inspection_no, r.inspection_date,
                r.location AS inspection_location, r.status AS inspection_status,
                p.property_code, p.property_name' . $assignmentSelect . '
         FROM inspection_corrective_actions a
         INNER JOIN inspection_reports r
            ON r.id = a.inspection_id
           AND r.property_id = a.property_id
         INNER JOIN cpms_properties p ON p.id = a.property_id
         ' . $assignmentJoin . '
         WHERE a.id = ? AND a.property_id = ?
           AND r.reported_by_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iii', $actionId, $propertyId, $hqInspectorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsHqActionImages(
    mysqli $conn,
    int $propertyId,
    int $actionId
): array {
    if (!cpmsFindingTableExists($conn, 'inspection_action_images')) {
        return [];
    }
    $reviewSelect = cpmsFindingColumnExists(
        $conn,
        'inspection_action_images',
        'hq_review_status'
    )
        ? ''
        : ', "Pending" AS hq_review_status,
             NULL AS hq_review_remarks,
             NULL AS hq_reviewed_by_id,
             NULL AS hq_reviewed_by_name,
             NULL AS hq_reviewed_at';
    $stmt = $conn->prepare(
        'SELECT inspection_action_images.*' . $reviewSelect . '
         FROM inspection_action_images
         WHERE property_id = ? AND action_id = ?
         ORDER BY created_at ASC, id ASC'
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

function cpmsHqActionImageReviewReady(mysqli $conn): bool
{
    return cpmsFindingColumnExists(
        $conn,
        'inspection_action_images',
        'hq_review_status'
    );
}

function cpmsHqReviewActionImage(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    int $imageId,
    int $hqInspectorId,
    string $inspectorName,
    string $decision,
    string $remarks
): void {
    if (!cpmsHqActionImageReviewReady($conn)) {
        throw new RuntimeException(
            'Run migration 20260812_0063 before reviewing After photos.'
        );
    }
    if (!in_array($decision, ['Accepted', 'Rejected'], true)) {
        throw new InvalidArgumentException('Invalid photo review decision.');
    }
    $remarks = trim($remarks);
    if ($decision === 'Rejected' && $remarks === '') {
        throw new InvalidArgumentException(
            'Remarks are required when an After photo is rejected.'
        );
    }

    $stmt = $conn->prepare(
        'UPDATE inspection_action_images
         SET hq_review_status = ?,
             hq_review_remarks = NULLIF(?, ""),
             hq_reviewed_by_id = ?,
             hq_reviewed_by_name = ?,
             hq_reviewed_at = NOW()
         WHERE id = ? AND property_id = ? AND action_id = ?
           AND image_phase = "After"'
    );
    if (!$stmt) {
        throw new RuntimeException('Photo review could not be prepared.');
    }
    $stmt->bind_param(
        'ssisiii',
        $decision,
        $remarks,
        $hqInspectorId,
        $inspectorName,
        $imageId,
        $propertyId,
        $actionId
    );
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Photo review failed: ' . $error);
    }
    $stmt->close();
}

function cpmsHqActionReviews(
    mysqli $conn,
    int $propertyId,
    int $actionId
): array {
    if (!cpmsFindingTableExists($conn, 'inspection_hq_action_reviews')) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT * FROM inspection_hq_action_reviews
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

function cpmsHqReviewAction(
    mysqli $conn,
    int $propertyId,
    int $actionId,
    int $hqInspectorId,
    string $inspectorName,
    string $decision,
    string $remarks
): void {
    if (!cpmsHqOperationalTablesReady($conn)) {
        throw new RuntimeException(
            'Run CPMS v3.2.6.5 migration before HQ verification.'
        );
    }

    $action = cpmsHqActionFind(
        $conn,
        $propertyId,
        $actionId,
        $hqInspectorId
    );
    if (!$action) {
        throw new RuntimeException('Corrective Action tidak dijumpai.');
    }
    if ((string) $action['status'] !== 'Rectified') {
        throw new RuntimeException(
            'Only Rectified actions can be reviewed by the HQ Inspector.'
        );
    }
    if ((int) ($action['source_finding_id'] ?? 0) > 0
        && (string) ($action['supervisor_status'] ?? '') !== 'Approved'
    ) {
        throw new RuntimeException(
            'The Property Supervisor must approve this rectification before HQ verification.'
        );
    }
    if (!in_array($decision, ['Verified', 'Rejected'], true)) {
        throw new InvalidArgumentException('Keputusan verification tidak sah.');
    }

    $remarks = trim($remarks);
    if ($decision === 'Rejected' && $remarks === '') {
        throw new InvalidArgumentException(
            'Remarks are required when rectification is rejected.'
        );
    }

    $inspectionId = (int) $action['inspection_id'];
    $inspectorName = trim($inspectorName);
    $conn->begin_transaction();
    try {
        $insert = $conn->prepare(
            'INSERT INTO inspection_hq_action_reviews (
                action_id, inspection_id, property_id, hq_inspector_id,
                inspector_name, decision, remarks
             ) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ""))'
        );
        if (!$insert) {
            throw new RuntimeException('Rekod HQ verification tidak dapat disediakan.');
        }
        $insert->bind_param(
            'iiiisss',
            $actionId,
            $inspectionId,
            $propertyId,
            $hqInspectorId,
            $inspectorName,
            $decision,
            $remarks
        );
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            throw new RuntimeException('HQ verification gagal disimpan: ' . $error);
        }
        $insert->close();

        if ($decision === 'Verified') {
            $update = $conn->prepare(
                'UPDATE inspection_corrective_actions
                 SET status = "Verified", verified_by_user_id = NULL,
                     verified_by_name = ?, verified_at = NOW()
                 WHERE id = ? AND property_id = ? AND status = "Rectified"'
            );
            if ($update) {
                $update->bind_param('sii', $inspectorName, $actionId, $propertyId);
            }
        } else {
            $update = $conn->prepare(
                'UPDATE inspection_corrective_actions
                 SET status = "In Progress", verified_by_user_id = NULL,
                     verified_by_name = NULL, verified_at = NULL
                 WHERE id = ? AND property_id = ? AND status = "Rectified"'
            );
            if ($update) {
                $update->bind_param('ii', $actionId, $propertyId);
            }
        }
        if (!$update || !$update->execute() || $update->affected_rows !== 1) {
            $error = $update ? $update->error : 'prepare failed';
            if ($update) {
                $update->close();
            }
            throw new RuntimeException('Rectification status update failed: ' . $error);
        }
        $update->close();

        if ((int) ($action['source_finding_id'] ?? 0) > 0
            && cpmsFindingTableExists($conn, 'inspection_finding_action_links')
        ) {
            $findingStatus = $decision === 'Verified'
                ? 'Verified'
                : 'In Progress';
            $findingId = (int) $action['source_finding_id'];
            $findingUpdate = $conn->prepare(
                'UPDATE inspection_findings
                 SET status = ?
                 WHERE id = ? AND inspection_id = ? AND property_id = ?'
            );
            if (!$findingUpdate) {
                throw new RuntimeException('Status finding tidak dapat disediakan.');
            }
            $findingUpdate->bind_param(
                'siii',
                $findingStatus,
                $findingId,
                $inspectionId,
                $propertyId
            );
            if (!$findingUpdate->execute()) {
                $error = $findingUpdate->error;
                $findingUpdate->close();
                throw new RuntimeException('Status finding gagal dikemas kini: ' . $error);
            }
            $findingUpdate->close();

            if ($decision === 'Rejected') {
                $linkUpdate = $conn->prepare(
                    'UPDATE inspection_finding_action_links
                     SET supervisor_status = "Rejected",
                         supervisor_remarks = ?,
                         supervisor_reviewed_at = NOW()
                     WHERE action_id = ? AND property_id = ?'
                );
                if (!$linkUpdate) {
                    throw new RuntimeException('Assignment link tidak dapat disediakan.');
                }
                $linkUpdate->bind_param('sii', $remarks, $actionId, $propertyId);
                if (!$linkUpdate->execute()) {
                    $error = $linkUpdate->error;
                    $linkUpdate->close();
                    throw new RuntimeException('Assignment link gagal dikemas kini: ' . $error);
                }
                $linkUpdate->close();
            }
        }

        $stats = $conn->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(status NOT IN ("Verified", "Closed")) AS pending
             FROM inspection_corrective_actions
             WHERE inspection_id = ? AND property_id = ?'
        );
        if (!$stats) {
            throw new RuntimeException('Inspection status could not be checked.');
        }
        $stats->bind_param('ii', $inspectionId, $propertyId);
        $stats->execute();
        $row = $stats->get_result()->fetch_assoc();
        $stats->close();

        $newInspectionStatus = (int) ($row['total'] ?? 0) > 0
            && (int) ($row['pending'] ?? 0) === 0
            ? 'Verified'
            : 'Action Required';
        $inspection = cpmsInspectionFind($conn, $propertyId, $inspectionId);
        if ($inspection
            && (string) ($inspection['status'] ?? '') !== $newInspectionStatus
        ) {
            $statusRemarks = $decision === 'Verified'
                ? 'Corrective Action verified by HQ Inspector.'
                : 'Rectification rejected by HQ Inspector: ' . $remarks;
            if (!cpmsInspectionUpdateStatus(
                $conn,
                $propertyId,
                $inspectionId,
                $newInspectionStatus,
                $statusRemarks,
                $hqInspectorId,
                $inspectorName
            )) {
                throw new RuntimeException('Status inspection gagal dikemas kini.');
            }
        }

        $conn->commit();

        if (function_exists('cpmsAssignmentLog')) {
            cpmsAssignmentLog(
                $conn,
                $propertyId,
                $inspectionId,
                $actionId,
                $decision === 'Verified' ? 'hq_verified' : 'hq_rejected',
                'Rectified',
                $decision === 'Verified' ? 'Verified' : 'In Progress',
                $remarks,
                0,
                $inspectorName,
                'hq_inspector'
            );
        }
        if (function_exists('cpmsAssignmentNotifyUser')) {
            cpmsAssignmentNotifyUser(
                $conn,
                $propertyId,
                (int) ($action['assigned_system_user_id'] ?? 0),
                'hq-finding-hq-review-' . $actionId . '-'
                    . strtolower($decision),
                $decision === 'Verified'
                    ? 'inspection_hq_verified'
                    : 'inspection_hq_rejected',
                $decision === 'Verified'
                    ? 'Rectification verified by HQ Inspector'
                    : 'Rectification rejected by HQ Inspector',
                (string) $action['action_no']
                    . ($remarks !== '' ? ': ' . $remarks : ''),
                'staff_corrective_action_view.php?id=' . $actionId,
                $decision === 'Verified' ? 'success' : 'danger',
                $actionId
            );
        }
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function cpmsHqCreateReinspection(
    mysqli $conn,
    int $propertyId,
    int $originalInspectionId,
    int $hqInspectorId,
    string $inspectorName
): int {
    if (!cpmsHqOperationalTablesReady($conn)) {
        throw new RuntimeException(
            'Run CPMS v3.2.6.5 migration before creating a reinspection.'
        );
    }

    $original = cpmsInspectionFind(
        $conn,
        $propertyId,
        $originalInspectionId
    );
    if (!$original
        || (int) ($original['reported_by_id'] ?? 0) !== $hqInspectorId
    ) {
        throw new RuntimeException('Inspection asal tidak dijumpai.');
    }
    if ((string) ($original['status'] ?? '') === 'Draft') {
        throw new RuntimeException('Inspection asal masih Draft.');
    }

    $existing = $conn->prepare(
        'SELECT r.id
         FROM inspection_reinspection_links l
         INNER JOIN inspection_reports r
            ON r.id = l.reinspection_id
           AND r.property_id = l.property_id
         WHERE l.original_inspection_id = ?
           AND l.property_id = ?
           AND r.reported_by_id = ?
           AND r.status = "Draft"
         ORDER BY l.id DESC LIMIT 1'
    );
    if ($existing) {
        $existing->bind_param(
            'iii',
            $originalInspectionId,
            $propertyId,
            $hqInspectorId
        );
        $existing->execute();
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($row) {
            return (int) $row['id'];
        }
    }

    $conn->begin_transaction();
    try {
        $newId = cpmsInspectionCreate(
            $conn,
            $propertyId,
            [
                'inspection_date' => date('Y-m-d'),
                'inspection_type' => 'Reinspection',
                'category' => 'Multiple Findings',
                'location' => (string) ($original['location'] ?? ''),
                'priority' => 'Medium',
                'status' => 'Draft',
                'description' => 'Reinspection for '
                    . (string) ($original['inspection_no'] ?? ''),
                'finding' => '',
                'recommendation' => '',
                'reported_by_id' => $hqInspectorId,
                'reported_by_name' => $inspectorName,
            ]
        );

        $link = $conn->prepare(
            'INSERT INTO inspection_reinspection_links (
                original_inspection_id, reinspection_id, property_id,
                created_by_hq_inspector_id, created_by_name
             ) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$link) {
            throw new RuntimeException(
                'Pautan reinspection tidak dapat disediakan.'
            );
        }
        $link->bind_param(
            'iiiis',
            $originalInspectionId,
            $newId,
            $propertyId,
            $hqInspectorId,
            $inspectorName
        );
        if (!$link->execute()) {
            $error = $link->error;
            $link->close();
            throw new RuntimeException('Pautan reinspection gagal: ' . $error);
        }
        $link->close();
        $conn->commit();
        return $newId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function cpmsHqReinspectionList(
    mysqli $conn,
    int $propertyId,
    int $originalInspectionId
): array {
    if (!cpmsFindingTableExists($conn, 'inspection_reinspection_links')) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT r.id, r.inspection_no, r.inspection_date, r.status,
                l.created_at
         FROM inspection_reinspection_links l
         INNER JOIN inspection_reports r
            ON r.id = l.reinspection_id
           AND r.property_id = l.property_id
         WHERE l.property_id = ? AND l.original_inspection_id = ?
         ORDER BY l.id DESC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $originalInspectionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}
