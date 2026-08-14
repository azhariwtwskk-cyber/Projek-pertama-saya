<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';
require_once __DIR__ . '/cpms/includes/notification_service.php';
require_once __DIR__ . '/cpms/includes/mobile_evidence_service.php';

header('Content-Type: application/json; charset=utf-8');

function cpmsEvidenceJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) ($_SESSION['cpms_user_id'] ?? 0);
$propertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$role = (string) ($_SESSION['cpms_user_role'] ?? '');
if ($userId < 1 || $propertyId < 1
    || !in_array($role, ['staff', 'security'], true)) {
    cpmsEvidenceJson(401, ['ok' => false, 'error' => 'login_required']);
}
if (!cpmsCan('mobile_evidence.create', $conn)) {
    cpmsEvidenceJson(403, ['ok' => false, 'error' => 'permission_denied']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cpmsEvidenceJson(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
$csrf = $_POST['csrf_token'] ?? null;
if (!cpmsNotificationVerifyCsrf(is_string($csrf) ? $csrf : null)) {
    cpmsEvidenceJson(403, ['ok' => false, 'error' => 'invalid_token']);
}
if ((int) ($_POST['queue_owner_id'] ?? 0) !== $userId) {
    cpmsEvidenceJson(403, ['ok' => false, 'error' => 'queue_owner_mismatch']);
}

$type = trim((string) ($_POST['task_type'] ?? ''));
$taskId = (int) ($_POST['task_id'] ?? 0);
$clientId = strtolower(trim((string) ($_POST['client_upload_id'] ?? '')));
$phase = trim((string) ($_POST['evidence_phase'] ?? 'Progress'));
$caption = trim((string) ($_POST['caption'] ?? ''));
$capturedAt = trim((string) ($_POST['captured_at'] ?? ''));
if (!preg_match('/^[a-f0-9-]{36}$/', $clientId)
    || !in_array($phase, ['Before', 'Progress', 'After', 'Issue', 'Supporting'], true)
    || strlen($caption) > 500) {
    cpmsEvidenceJson(422, ['ok' => false, 'error' => 'invalid_fields']);
}
$task = cpmsEvidenceTask($conn, $propertyId, $userId, $type, $taskId);
if ($task === null) {
    cpmsEvidenceJson(403, ['ok' => false, 'error' => 'task_not_assigned']);
}

$existing = $conn->prepare(
    'SELECT id, file_path FROM cpms_mobile_evidence
     WHERE client_upload_id = ? AND system_user_id = ? LIMIT 1'
);
$existing->bind_param('si', $clientId, $userId);
$existing->execute();
$existingRow = $existing->get_result()->fetch_assoc();
$existing->close();
if (is_array($existingRow)) {
    cpmsEvidenceJson(200, [
        'ok' => true, 'duplicate' => true,
        'evidence_id' => (int) $existingRow['id'],
    ]);
}

$file = $_FILES['evidence'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
    !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) < 1
    || (int) $file['size'] > 10 * 1024 * 1024) {
    cpmsEvidenceJson(422, ['ok' => false, 'error' => 'invalid_file']);
}
$temporary = (string) $file['tmp_name'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($temporary);
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
if (!isset($extensions[$mime]) || @getimagesize($temporary) === false) {
    cpmsEvidenceJson(422, ['ok' => false, 'error' => 'image_required']);
}

$relativeDirectory = 'uploads/mobile_evidence/' . $propertyId
    . '/' . date('Y') . '/' . date('m');
$absoluteDirectory = __DIR__ . '/' . $relativeDirectory;
if (!is_dir($absoluteDirectory)
    && !mkdir($absoluteDirectory, 0755, true)
    && !is_dir($absoluteDirectory)) {
    cpmsEvidenceJson(500, ['ok' => false, 'error' => 'storage_unavailable']);
}
$fileName = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
$relativePath = $relativeDirectory . '/' . $fileName;
if (!move_uploaded_file($temporary, __DIR__ . '/' . $relativePath)) {
    cpmsEvidenceJson(500, ['ok' => false, 'error' => 'upload_failed']);
}

$originalName = substr(basename((string) ($file['name'] ?? 'evidence')), 0, 255);
$fileSize = (int) filesize(__DIR__ . '/' . $relativePath);
$capturedSql = null;
if ($capturedAt !== '') {
    $timestamp = strtotime($capturedAt);
    if ($timestamp !== false) {
        $capturedSql = date('Y-m-d H:i:s', $timestamp);
    }
}
$insert = $conn->prepare(
    'INSERT INTO cpms_mobile_evidence
     (property_id, system_user_id, task_type, task_id, client_upload_id,
      evidence_phase, file_path, original_name, mime_type, file_size,
      caption, captured_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?)'
);
$insert->bind_param(
    'iisisssssiss',
    $propertyId, $userId, $type, $taskId, $clientId, $phase,
    $relativePath, $originalName, $mime, $fileSize, $caption, $capturedSql
);
if (!$insert->execute()) {
    @unlink(__DIR__ . '/' . $relativePath);
    cpmsEvidenceJson(500, ['ok' => false, 'error' => 'database_error']);
}
$evidenceId = (int) $insert->insert_id;
$insert->close();
cpmsEvidenceJson(201, ['ok' => true, 'evidence_id' => $evidenceId]);
