<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff']);
$staffId = cpmsApiStaffId($identity);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];

$input = cpmsApiInput(); // multipart -> $_POST
$token = trim((string) ($input['asset_token'] ?? ''));
$condition = trim((string) ($input['condition_result'] ?? ''));
$findings = trim((string) ($input['findings'] ?? ''));
$action = trim((string) ($input['action_taken'] ?? ''));
$workOrderRequired = (int) ($input['work_order_required'] ?? 0);
$checklist = is_array($input['checklist'] ?? null) ? $input['checklist'] : [];
$allowed = ['Good', 'Satisfactory', 'Attention Required', 'Critical'];

if ($token === '' || !in_array($condition, $allowed, true)) {
    cpmsApiError('FIELDS_REQUIRED', 'Maklumat pemeriksaan tidak lengkap.', 422);
}

$stmt = $db->prepare('SELECT id FROM assets WHERE public_token = ? AND property_id = ? LIMIT 1');
$stmt->bind_param('si', $token, $propertyId);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!is_array($asset)) {
    cpmsApiError('ASSET_NOT_FOUND', 'Aset tidak dijumpai.', 404);
}
$assetId = (int) $asset['id'];

$files = $_FILES['inspection_images'] ?? null;
if (!is_array($files) || !is_array($files['name'] ?? null)) {
    cpmsApiError('IMAGES_REQUIRED', 'Sekurang-kurangnya 1 gambar diperlukan.', 422);
}
$count = count($files['name']);
if ($count < 1 || $count > 5) {
    cpmsApiError('IMAGE_COUNT_INVALID', 'Jumlah gambar mesti 1 hingga 5.', 422);
}

$dir = dirname(__DIR__, 4) . '/uploads/asset_inspections/';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    cpmsApiError('UPLOAD_DIR_FAILED', 'Folder gambar tidak dapat dibuat.', 500);
}

$paths = [];
$names = [];
$allowMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$db->begin_transaction();
try {
    do {
        $ref = 'AI-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $check = $db->prepare('SELECT id FROM asset_inspections WHERE inspection_reference = ?');
        $check->bind_param('s', $ref);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
    } while ($exists);

    for ($i = 0; $i < $count; $i++) {
        if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Gambar gagal dimuat naik.');
        }
        if ((int) $files['size'][$i] > 5 * 1024 * 1024) {
            throw new RuntimeException('Gambar melebihi 5MB.');
        }
        $tmp = (string) $files['tmp_name'][$i];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = @getimagesize($tmp);
        if (!isset($allowMime[$mime]) || !is_array($info) || ($info['mime'] ?? '') !== $mime) {
            throw new RuntimeException('Jenis gambar tidak sah.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $allowMime[$mime];
        $dest = $dir . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Gambar gagal disimpan.');
        }
        $paths[] = $dest;
        $names[] = $name;
    }

    $json = json_encode(array_values(array_map('strval', $checklist)), JSON_UNESCAPED_UNICODE);
    $insert = $db->prepare(
        "INSERT INTO asset_inspections
            (inspection_reference, asset_id, staff_id, inspection_date, condition_result,
             checklist_data, findings, action_taken, work_order_required)
         VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)"
    );
    $insert->bind_param('siissssi', $ref, $assetId, $staffId, $condition, $json, $findings, $action, $workOrderRequired);
    if (!$insert->execute()) {
        throw new RuntimeException('Rekod gagal disimpan.');
    }
    $inspectionId = (int) $db->insert_id;
    $insert->close();

    $imgStmt = $db->prepare('INSERT INTO asset_inspection_images (inspection_id, image_name) VALUES (?, ?)');
    foreach ($names as $name) {
        $imgStmt->bind_param('is', $inspectionId, $name);
        if (!$imgStmt->execute()) {
            throw new RuntimeException('Rekod gambar gagal disimpan.');
        }
    }
    $imgStmt->close();

    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    foreach ($paths as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
    cpmsApiError('SUBMIT_FAILED', $exception->getMessage(), 422);
}

cpmsApiAudit($db, $identity, 'staff.asset_inspection', 'success', 'asset_inspection', $inspectionId, [
    'reference' => $ref,
]);

cpmsApiRespond(['accepted' => true, 'inspection_reference' => $ref], 201);
