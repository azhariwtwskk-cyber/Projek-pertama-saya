<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['security']);
$db = cpmsApiDatabase();
$input = cpmsApiInput();
$propertyId = (int) $identity['property_id'];
$guardId = cpmsApiGuardId($identity);
$location = cpmsApiValidateWorkLocation($db, $propertyId, $input, false);
$notes = substr(trim((string) ($input['notes'] ?? '')), 0, 5000);

$db->begin_transaction();
try {
    $activeStmt = $db->prepare(
        "SELECT * FROM cpms_workforce_patrol_sessions
         WHERE property_id=? AND guard_id=? AND session_status='Active'
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    if (!$activeStmt) {
        throw new RuntimeException('Unable to prepare active patrol lookup.');
    }
    $activeStmt->bind_param('ii', $propertyId, $guardId);
    $activeStmt->execute();
    $active = $activeStmt->get_result()->fetch_assoc();
    $activeStmt->close();
    if (!is_array($active)) {
        $db->rollback();
        cpmsApiError('NO_ACTIVE_PATROL', 'Tiada rondaan aktif untuk ditamatkan.', 409);
    }

    $sessionId = (int) $active['id'];
    $locations = [];
    $locationStmt = $db->prepare(
        'SELECT DISTINCT c.checkpoint_name
         FROM cpms_workforce_checkpoint_events e
         JOIN security_checkpoints c ON c.id=e.checkpoint_id
         WHERE e.patrol_session_id=? ORDER BY c.scan_order,c.id'
    );
    if ($locationStmt) {
        $locationStmt->bind_param('i', $sessionId);
        $locationStmt->execute();
        $locationResult = $locationStmt->get_result();
        while ($row = $locationResult->fetch_assoc()) {
            $locations[] = (string) $row['checkpoint_name'];
        }
        $locationStmt->close();
    }
    if (!$locations) {
        $locations[] = 'Mobile Patrol';
    }
    $locationsJson = json_encode(
        $locations,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $completedAt = date('Y-m-d H:i:s');
    $patrolDate = date('Y-m-d', strtotime((string) $active['started_at']));
    $reference = (string) $active['patrol_reference'];
    $patrolType = 'Routine Patrol';
    $dutyType = 'Regular Duty';
    $startedAt = (string) $active['started_at'];
    $issueFound = 0;
    $issueCategory = null;
    $issuePriority = null;
    $issueDescription = null;
    $workOrderRequired = 0;
    $patrolStatus = 'Submitted';

    $patrolStmt = $db->prepare(
        'INSERT INTO security_patrols
         (property_id,patrol_reference,guard_id,patrol_date,patrol_type,
          duty_type,started_at,completed_at,locations_checked,patrol_notes,
          issue_found,issue_category,issue_priority,issue_description,
          work_order_required,patrol_status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    if (!$patrolStmt) {
        throw new RuntimeException('Unable to prepare completed patrol record.');
    }
    $patrolStmt->bind_param(
        'isisssssssisssis',
        $propertyId,
        $reference,
        $guardId,
        $patrolDate,
        $patrolType,
        $dutyType,
        $startedAt,
        $completedAt,
        $locationsJson,
        $notes,
        $issueFound,
        $issueCategory,
        $issuePriority,
        $issueDescription,
        $workOrderRequired,
        $patrolStatus
    );
    $patrolStmt->execute();
    $patrolId = (int) $db->insert_id;
    $patrolStmt->close();

    $copyStmt = $db->prepare(
        "INSERT INTO security_checkpoint_scans
         (property_id,patrol_id,checkpoint_id,guard_id,scanned_at,
          scan_method,latitude,longitude,accuracy_meters,device_info,remarks)
         SELECT property_id,?,checkpoint_id,guard_id,scanned_at,'QR',
                latitude,longitude,accuracy_m,device_info,remarks
         FROM cpms_workforce_checkpoint_events
         WHERE patrol_session_id=?"
    );
    if ($copyStmt) {
        $copyStmt->bind_param('ii', $patrolId, $sessionId);
        $copyStmt->execute();
        $copyStmt->close();
    }

    $latitude = (float) $location['latitude'];
    $longitude = (float) $location['longitude'];
    $accuracy = (float) $location['accuracy'];
    $updateStmt = $db->prepare(
        "UPDATE cpms_workforce_patrol_sessions
         SET completed_at=?,end_latitude=?,end_longitude=?,end_accuracy_m=?,
             patrol_notes=?,linked_patrol_id=?,session_status='Completed',
             active_session_key=NULL
         WHERE id=?"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare patrol session completion.');
    }
    $updateStmt->bind_param(
        'sdddsii',
        $completedAt,
        $latitude,
        $longitude,
        $accuracy,
        $notes,
        $patrolId,
        $sessionId
    );
    $updateStmt->execute();
    $updateStmt->close();
    $db->commit();
    cpmsApiAudit(
        $db, $identity, 'security.patrol_end', 'success',
        'security_patrol', $patrolId,
        ['session_id' => $sessionId, 'checkpoints' => count($locations)]
    );
    cpmsApiRespond([
        'accepted' => true,
        'patrol_state' => 'idle',
        'patrol_id' => $patrolId,
        'patrol_reference' => $reference,
        'completed_at' => $completedAt,
    ]);
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}

