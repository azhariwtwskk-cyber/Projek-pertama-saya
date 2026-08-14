<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['security']);
$db = cpmsApiDatabase();
$input = cpmsApiInput();
$propertyId = (int) $identity['property_id'];
$guardId = cpmsApiGuardId($identity);
$active = cpmsApiActivePatrol($db, $identity);
if (!is_array($active)) {
    cpmsApiError('NO_ACTIVE_PATROL', 'Mulakan rondaan sebelum mengimbas checkpoint.', 409);
}
$qrToken = trim((string) ($input['qr_token'] ?? ''));
$checkpointCode = trim((string) ($input['checkpoint_code'] ?? ''));
if ($qrToken === '' && $checkpointCode === '') {
    cpmsApiError('CHECKPOINT_REQUIRED', 'Kod checkpoint tidak diterima.', 422);
}
$location = cpmsApiValidateWorkLocation($db, $propertyId, $input, false);
$checkpointStmt = $db->prepare(
    'SELECT id,checkpoint_code,checkpoint_name
     FROM security_checkpoints
     WHERE property_id=? AND is_active=1 AND (qr_token=? OR checkpoint_code=?)
     LIMIT 1'
);
if (!$checkpointStmt) {
    throw new RuntimeException('Unable to prepare checkpoint lookup.');
}
$checkpointStmt->bind_param('iss', $propertyId, $qrToken, $checkpointCode);
$checkpointStmt->execute();
$checkpoint = $checkpointStmt->get_result()->fetch_assoc();
$checkpointStmt->close();
if (!is_array($checkpoint)) {
    cpmsApiError('CHECKPOINT_NOT_FOUND', 'Checkpoint tidak sah untuk property ini.', 404);
}
$sessionId = (int) $active['id'];
$checkpointId = (int) $checkpoint['id'];
$latitude = (float) $location['latitude'];
$longitude = (float) $location['longitude'];
$accuracy = (float) $location['accuracy'];
$deviceInfo = cpmsApiUserAgent();
$remarks = substr(trim((string) ($input['remarks'] ?? '')), 0, 1000);
$insert = $db->prepare(
    'INSERT INTO cpms_workforce_checkpoint_events
     (property_id,patrol_session_id,checkpoint_id,guard_id,latitude,
      longitude,accuracy_m,device_info,remarks)
     VALUES (?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE scanned_at=NOW(),latitude=VALUES(latitude),
       longitude=VALUES(longitude),accuracy_m=VALUES(accuracy_m),
       device_info=VALUES(device_info),remarks=VALUES(remarks)'
);
if (!$insert) {
    throw new RuntimeException('Unable to prepare checkpoint scan.');
}
$insert->bind_param(
    'iiiidddss',
    $propertyId,
    $sessionId,
    $checkpointId,
    $guardId,
    $latitude,
    $longitude,
    $accuracy,
    $deviceInfo,
    $remarks
);
$insert->execute();
$eventId = (int) ($db->insert_id ?: 0);
$insert->close();
cpmsApiAudit(
    $db, $identity, 'security.checkpoint_scan', 'success',
    'security_checkpoint', $checkpointId,
    ['patrol_session_id' => $sessionId]
);
cpmsApiRespond([
    'accepted' => true,
    'event_id' => $eventId,
    'checkpoint' => [
        'id' => $checkpointId,
        'code' => (string) $checkpoint['checkpoint_code'],
        'name' => (string) $checkpoint['checkpoint_name'],
    ],
    'scanned_at' => date('Y-m-d H:i:s'),
], 201);

