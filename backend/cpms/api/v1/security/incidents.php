<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['security']);
$db = cpmsApiDatabase();
$input = cpmsApiInput();
$propertyId = (int) $identity['property_id'];
$guardId = cpmsApiGuardId($identity);
$description = trim((string) ($input['description'] ?? ''));
$locationName = trim((string) ($input['location_name'] ?? ''));
$priority = ucfirst(strtolower(trim((string) ($input['priority'] ?? 'Medium'))));
if (!in_array($priority, ['Low', 'Medium', 'High', 'Emergency'], true)) {
    $priority = 'Medium';
}
if ($description === '' || $locationName === '') {
    cpmsApiError('INCIDENT_DETAILS_REQUIRED', 'Lengkapkan lokasi dan butiran kejadian.', 422);
}
if (strlen($description) > 5000 || strlen($locationName) > 190) {
    cpmsApiError('INCIDENT_DETAILS_TOO_LONG', 'Butiran kejadian terlalu panjang.', 422);
}

if (isset($input['location']) && is_string($input['location'])) {
    $decodedLocation = json_decode($input['location'], true);
    if (is_array($decodedLocation)) {
        $input['location'] = $decodedLocation;
    }
}
$location = cpmsApiValidateWorkLocation($db, $propertyId, $input, false);
$active = cpmsApiActivePatrol($db, $identity);
$sessionId = is_array($active) ? (int) $active['id'] : null;
$photoPath = null;
if (isset($_FILES['photo']) && is_array($_FILES['photo'])
    && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $photoPath = cpmsApiSaveImage(
        $_FILES['photo'],
        'uploads/security_incidents/property_' . $propertyId
    );
}
$latitude = (float) $location['latitude'];
$longitude = (float) $location['longitude'];
$accuracy = (float) $location['accuracy'];
$incidentReference = 'INC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$status = 'Open';

$insert = $db->prepare(
    'INSERT INTO cpms_security_incidents
     (property_id,guard_id,patrol_session_id,incident_reference,
      location_name,description,priority,latitude,longitude,accuracy_m,
      photo_path,incident_status,recorded_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
);
if (!$insert) {
    if ($photoPath !== null) {
        @unlink(cpmsApiRoot() . '/' . $photoPath);
    }
    throw new RuntimeException('Unable to prepare incident report.');
}
$insert->bind_param(
    'iiissssdddss',
    $propertyId,
    $guardId,
    $sessionId,
    $incidentReference,
    $locationName,
    $description,
    $priority,
    $latitude,
    $longitude,
    $accuracy,
    $photoPath,
    $status
);
$insert->execute();
$incidentId = (int) $db->insert_id;
$insert->close();

$notificationTitle = 'Laporan kejadian Security';
$notificationMessage = $incidentReference . ' di ' . $locationName . ': '
    . substr($description, 0, 300);
$targetRole = 'property_admin';
$actionUrl = 'security_patrol_history.php';
$createdBy = (string) $identity['name'];
$notification = $db->prepare(
    "INSERT INTO cpms_notifications
     (property_id,notification_type,category,priority,title,message,
      reference_no,target_role,action_url,created_by)
     VALUES (?,'SECURITY_INCIDENT','security',?,?,?,?,?,?,?)"
);
if ($notification) {
    $notification->bind_param(
        'isssssss',
        $propertyId,
        $priority,
        $notificationTitle,
        $notificationMessage,
        $incidentReference,
        $targetRole,
        $actionUrl,
        $createdBy
    );
    $notification->execute();
    $notification->close();
}
cpmsApiAudit(
    $db, $identity, 'security.incident_create', 'success',
    'security_incident', $incidentId,
    ['reference' => $incidentReference, 'priority' => $priority]
);
cpmsApiRespond([
    'accepted' => true,
    'incident_id' => $incidentId,
    'incident_reference' => $incidentReference,
    'recorded_at' => date('Y-m-d H:i:s'),
], 201);
