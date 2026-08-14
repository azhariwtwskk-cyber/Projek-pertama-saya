<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff']);
$staffId = cpmsApiStaffId($identity);
$db = cpmsApiDatabase();

const CPMS_MAX_IMAGES_PER_GROUP = 3;
const CPMS_MAX_IMAGE_SIZE = 5242880; // 5MB
const CPMS_ALLOWED_STATUSES = ['In Progress', 'Completed', 'Pending Material', 'Pending Contractor', 'Unable to Complete'];
const CPMS_ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$input = cpmsApiInput(); // multipart -> $_POST
$workOrderId = (int) ($input['work_order_id'] ?? 0);
$workDate = trim((string) ($input['work_date'] ?? ''));
$category = trim((string) ($input['work_category'] ?? ''));
$blockLocation = trim((string) ($input['block_location'] ?? ''));
$specificLocation = trim((string) ($input['specific_location'] ?? ''));
$startTime = trim((string) ($input['start_time'] ?? ''));
$endTime = trim((string) ($input['end_time'] ?? ''));
$description = trim((string) ($input['work_description'] ?? ''));
$materials = trim((string) ($input['materials_used'] ?? ''));
$issues = trim((string) ($input['issue_notes'] ?? ''));
$status = trim((string) ($input['work_status'] ?? ''));

if ($workDate === '' || $category === '' || $blockLocation === '' || $description === '') {
    cpmsApiError('FIELDS_REQUIRED', 'Sila lengkapkan semua medan wajib.', 422);
}
if (!in_array($status, CPMS_ALLOWED_STATUSES, true)) {
    cpmsApiError('INVALID_STATUS', 'Status kerja tidak sah.', 422);
}
if ($startTime !== '' && $endTime !== '' && strtotime($endTime) < strtotime($startTime)) {
    cpmsApiError('INVALID_TIME', 'Masa tamat tidak boleh lebih awal daripada masa mula.', 422);
}

$linkedWorkOrder = null;
if ($workOrderId > 0) {
    $stmt = $db->prepare(
        "SELECT id, work_order_reference, status FROM work_orders
         WHERE id = ? AND assigned_staff_id = ? AND status NOT IN ('Verified','Cancelled') LIMIT 1"
    );
    $stmt->bind_param('ii', $workOrderId, $staffId);
    $stmt->execute();
    $linkedWorkOrder = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$linkedWorkOrder) {
        cpmsApiError('WORK_ORDER_INVALID', 'Work Order tidak sah atau tidak diberikan kepada anda.', 422);
    }
}

// Kumpulan gambar: before / during / after (padan dengan borang asal)
$imageGroups = [
    'Before' => $_FILES['before_images'] ?? null,
    'During' => $_FILES['during_images'] ?? null,
    'After' => $_FILES['after_images'] ?? null,
];

$afterCount = is_array($imageGroups['After']['name'] ?? null) ? count($imageGroups['After']['name']) : (isset($imageGroups['After']['name']) ? 1 : 0);
if ($status === 'Completed' && $afterCount === 0) {
    cpmsApiError('AFTER_IMAGE_REQUIRED', 'Sekurang-kurangnya satu gambar "After" diperlukan untuk kerja Completed.', 422);
}

$uploadDir = dirname(__DIR__, 4) . '/uploads/daily_work/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    cpmsApiError('UPLOAD_DIR_FAILED', 'Folder gambar kerja tidak dapat disediakan.', 500);
}

$storedPaths = [];
$storedImages = [];

$db->begin_transaction();
try {
    foreach ($imageGroups as $type => $files) {
        if (!is_array($files) || !isset($files['name'])) {
            continue;
        }
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $count = count($names);
        if ($count > CPMS_MAX_IMAGES_PER_GROUP) {
            throw new RuntimeException("Maksimum " . CPMS_MAX_IMAGES_PER_GROUP . " gambar untuk kategori {$type}.");
        }
        for ($i = 0; $i < $count; $i++) {
            $error = is_array($files['error']) ? (int) $files['error'][$i] : (int) $files['error'];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Salah satu gambar gagal dimuat naik.');
            }
            $size = is_array($files['size']) ? (int) $files['size'][$i] : (int) $files['size'];
            if ($size > CPMS_MAX_IMAGE_SIZE) {
                throw new RuntimeException('Salah satu gambar melebihi 5MB.');
            }
            $tmp = is_array($files['tmp_name']) ? (string) $files['tmp_name'][$i] : (string) $files['tmp_name'];
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('Fail gambar tidak sah.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $info = @getimagesize($tmp);
            $detected = is_array($info) ? (string) ($info['mime'] ?? '') : '';
            if (!isset(CPMS_ALLOWED_MIME[$mime]) || $mime !== $detected) {
                throw new RuntimeException('Hanya gambar JPG, PNG dan WEBP dibenarkan.');
            }
            $filename = bin2hex(random_bytes(16)) . '.' . CPMS_ALLOWED_MIME[$mime];
            $dest = $uploadDir . $filename;
            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('Gambar gagal disimpan.');
            }
            $storedPaths[] = $dest;
            $storedImages[] = ['name' => $filename, 'type' => $type];
        }
    }

    do {
        $ref = 'DW-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $check = $db->prepare('SELECT id FROM daily_work_logs WHERE work_reference = ?');
        $check->bind_param('s', $ref);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
    } while ($exists);

    $insert = $db->prepare(
        "INSERT INTO daily_work_logs
            (work_reference, staff_id, work_order_id, work_date, work_category, block_location,
             specific_location, start_time, end_time, work_description, materials_used,
             issue_notes, work_status, include_in_newsletter)
         VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, NULLIF(?,''), NULLIF(?,''), ?, ?, ?, ?, 0)"
    );
    $insert->bind_param(
        'siissssssssss',
        $ref, $staffId, $workOrderId, $workDate, $category, $blockLocation,
        $specificLocation, $startTime, $endTime, $description, $materials, $issues, $status
    );
    if (!$insert->execute()) {
        throw new RuntimeException('Rekod kerja gagal disimpan.');
    }
    $logId = (int) $db->insert_id;
    $insert->close();

    if ($storedImages) {
        $imgStmt = $db->prepare('INSERT INTO daily_work_images (daily_work_id, image_name, image_type) VALUES (?, ?, ?)');
        foreach ($storedImages as $img) {
            $imgStmt->bind_param('iss', $logId, $img['name'], $img['type']);
            if (!$imgStmt->execute()) {
                throw new RuntimeException('Rekod gambar gagal disimpan.');
            }
        }
        $imgStmt->close();
    }

    if ($linkedWorkOrder !== null) {
        $newStatus = match ($status) {
            'Completed' => 'Completed',
            'Pending Material' => 'Pending Material',
            'Pending Contractor' => 'Pending Contractor',
            default => 'In Progress',
        };
        $update = $db->prepare(
            "UPDATE work_orders SET status=?, completion_notes=?,
                 completed_at = CASE WHEN ?='Completed' THEN COALESCE(completed_at, NOW()) ELSE completed_at END
             WHERE id=?"
        );
        $update->bind_param('sssi', $newStatus, $description, $newStatus, $workOrderId);
        $update->execute();
        $update->close();

        $history = $db->prepare(
            "INSERT INTO work_order_history (work_order_id, old_status, new_status, remarks, updated_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $oldStatus = (string) $linkedWorkOrder['status'];
        $remarks = "Daily Work {$ref}: {$description}";
        $updatedBy = (string) $identity['name'];
        $history->bind_param('issss', $workOrderId, $oldStatus, $newStatus, $remarks, $updatedBy);
        $history->execute();
        $history->close();
    }

    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    foreach ($storedPaths as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
    cpmsApiError('SUBMIT_FAILED', $exception->getMessage(), 422);
}

cpmsApiAudit($db, $identity, 'staff.daily_work_submit', 'success', 'daily_work_log', $logId, ['reference' => $ref]);

cpmsApiRespond(['accepted' => true, 'reference' => $ref], 201);
