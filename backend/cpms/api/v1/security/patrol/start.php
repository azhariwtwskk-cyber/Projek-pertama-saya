<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['security']);
$db = cpmsApiDatabase();
$input = cpmsApiInput();
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];
$guardId = cpmsApiGuardId($identity);
$location = cpmsApiValidateWorkLocation($db, $propertyId, $input, true);

$active = cpmsApiActivePatrol($db, $identity);
if (is_array($active)) {
    cpmsApiRespond([
        'accepted' => true,
        'already_active' => true,
        'patrol_state' => 'active',
        'session_id' => (int) $active['id'],
        'patrol_reference' => (string) $active['patrol_reference'],
        'started_at' => (string) $active['started_at'],
    ]);
}

$reference = cpmsApiPatrolReference();
$startedAt = date('Y-m-d H:i:s');
$latitude = (float) $location['latitude'];
$longitude = (float) $location['longitude'];
$accuracy = (float) $location['accuracy'];
$distance = (float) $location['distance'];
$activeKey = $propertyId . ':' . $guardId;
$deviceInfo = cpmsApiUserAgent();
$stmt = $db->prepare(
    "INSERT INTO cpms_workforce_patrol_sessions
     (property_id,guard_id,system_user_id,patrol_reference,started_at,
      start_latitude,start_longitude,start_accuracy_m,start_distance_m,
      session_status,active_session_key,device_info)
     VALUES (?,?,?,?,?,?,?,?,?,'Active',?,?)"
);
if (!$stmt) {
    throw new RuntimeException('Unable to prepare patrol start.');
}
$stmt->bind_param(
    'iiissddddss',
    $propertyId,
    $guardId,
    $userId,
    $reference,
    $startedAt,
    $latitude,
    $longitude,
    $accuracy,
    $distance,
    $activeKey,
    $deviceInfo
);
$stmt->execute();
$sessionId = (int) $db->insert_id;
$stmt->close();
cpmsApiAudit(
    $db, $identity, 'security.patrol_start', 'success',
    'patrol_session', $sessionId,
    ['distance_m' => $distance, 'accuracy_m' => $accuracy]
);
cpmsApiRespond([
    'accepted' => true,
    'patrol_state' => 'active',
    'session_id' => $sessionId,
    'patrol_reference' => $reference,
    'started_at' => $startedAt,
], 201);

