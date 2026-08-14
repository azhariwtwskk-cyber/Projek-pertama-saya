<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');
$identity = cpmsApiRequireRole(['staff', 'security']);
$db = cpmsApiDatabase();
$input = cpmsApiInput();
$action = strtolower(trim((string) ($input['action'] ?? '')));
if (!in_array($action, ['clock_in', 'clock_out'], true)) {
    cpmsApiError('INVALID_ACTION', 'Tindakan kehadiran tidak sah.', 422);
}

$location = cpmsApiValidateWorkLocation(
    $db,
    (int) $identity['property_id'],
    $input,
    true
);
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];
$role = (string) $identity['role'];
$now = new DateTimeImmutable('now');
$recordedAt = $now->format('Y-m-d H:i:s');
$workDate = $now->format('Y-m-d');
$openKey = $propertyId . ':' . $userId;
$latitude = (float) $location['latitude'];
$longitude = (float) $location['longitude'];
$accuracy = (float) $location['accuracy'];
$distance = (float) $location['distance'];
$fingerprint = hash('sha256', cpmsApiUserAgent());
$agent = cpmsApiUserAgent();

$db->begin_transaction();
try {
    $openStmt = $db->prepare(
        "SELECT * FROM cpms_attendance_sessions
         WHERE property_id=? AND system_user_id=? AND status='Open'
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    if (!$openStmt) {
        throw new RuntimeException('Unable to prepare attendance session lookup.');
    }
    $openStmt->bind_param('ii', $propertyId, $userId);
    $openStmt->execute();
    $openSession = $openStmt->get_result()->fetch_assoc();
    $openStmt->close();

    if ($action === 'clock_in') {
        if (is_array($openSession)) {
            $db->rollback();
            cpmsApiError('ALREADY_CLOCKED_IN', 'Anda sudah clock in.', 409);
        }
        $shift = cpmsApiResolveShift($db, $identity);
        $assignmentId = is_array($shift) && isset($shift['assignment_id'])
            ? (int) $shift['assignment_id'] : null;
        $rotationAssignmentId = is_array($shift)
            && isset($shift['rotation_assignment_id'])
            ? (int) $shift['rotation_assignment_id'] : null;
        $resolvedShiftId = is_array($shift) && isset($shift['shift_id'])
            ? (int) $shift['shift_id'] : null;
        $scheduledStart = is_array($shift)
            ? (string) ($shift['scheduled_start_at'] ?? '') : '';
        $scheduledEnd = is_array($shift)
            ? (string) ($shift['scheduled_end_at'] ?? '') : '';
        $scheduledStart = $scheduledStart !== '' ? $scheduledStart : null;
        $scheduledEnd = $scheduledEnd !== '' ? $scheduledEnd : null;
        $lateMinutes = 0;
        if (is_array($shift) && function_exists('cpmsShiftLateMinutes')) {
            $lateMinutes = cpmsShiftLateMinutes($now, $shift);
        }

        $insert = $db->prepare(
            "INSERT INTO cpms_attendance_sessions
             (property_id,system_user_id,user_role,shift_assignment_id,
              rotation_assignment_id,resolved_shift_id,work_date,
              scheduled_start_at,scheduled_end_at,late_minutes,clock_in_at,
              clock_in_latitude,clock_in_longitude,clock_in_accuracy_m,
              clock_in_distance_m,status,open_session_key,device_fingerprint,
              user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Open',?,?,?)"
        );
        if (!$insert) {
            throw new RuntimeException('Unable to prepare clock in record.');
        }
        $insert->bind_param(
            'iisiiisssisddddsss',
            $propertyId,
            $userId,
            $role,
            $assignmentId,
            $rotationAssignmentId,
            $resolvedShiftId,
            $workDate,
            $scheduledStart,
            $scheduledEnd,
            $lateMinutes,
            $recordedAt,
            $latitude,
            $longitude,
            $accuracy,
            $distance,
            $openKey,
            $fingerprint,
            $agent
        );
        $insert->execute();
        $sessionId = (int) $db->insert_id;
        $insert->close();
        cpmsApiAttendanceAudit(
            $db, $identity, $action, true, 'accepted', $location
        );
        $db->commit();
        cpmsApiAudit(
            $db, $identity, 'attendance.clock_in', 'success',
            'attendance_session', $sessionId,
            ['distance_m' => $distance, 'accuracy_m' => $accuracy]
        );
        cpmsApiRespond([
            'accepted' => true,
            'attendance_state' => 'in',
            'session_id' => $sessionId,
            'recorded_at' => $recordedAt,
            'distance_m' => $distance,
        ], 201);
    }

    if (!is_array($openSession)) {
        $db->rollback();
        cpmsApiError('NOT_CLOCKED_IN', 'Tiada rekod clock in yang masih aktif.', 409);
    }

    $clockIn = new DateTimeImmutable((string) $openSession['clock_in_at']);
    $elapsed = max(0, (int) floor(
        ($now->getTimestamp() - $clockIn->getTimestamp()) / 60
    ));
    $breakMinutes = 0;
    $minimumOvertime = 30;
    if (!empty($openSession['resolved_shift_id'])) {
        $shiftId = (int) $openSession['resolved_shift_id'];
        $shiftStmt = $db->prepare(
            'SELECT break_minutes,minimum_overtime_minutes
             FROM cpms_attendance_shifts WHERE id=? LIMIT 1'
        );
        if ($shiftStmt) {
            $shiftStmt->bind_param('i', $shiftId);
            $shiftStmt->execute();
            $shiftRow = $shiftStmt->get_result()->fetch_assoc();
            $shiftStmt->close();
            if (is_array($shiftRow)) {
                $breakMinutes = (int) $shiftRow['break_minutes'];
                $minimumOvertime = (int) $shiftRow['minimum_overtime_minutes'];
            }
        }
    }
    $workedMinutes = max(0, $elapsed - $breakMinutes);
    $earlyMinutes = 0;
    $overtimeMinutes = 0;
    if (!empty($openSession['scheduled_start_at'])
        && !empty($openSession['scheduled_end_at'])) {
        $scheduledStart = new DateTimeImmutable(
            (string) $openSession['scheduled_start_at']
        );
        $scheduledEnd = new DateTimeImmutable(
            (string) $openSession['scheduled_end_at']
        );
        $earlyMinutes = max(0, (int) floor(
            ($scheduledEnd->getTimestamp() - $now->getTimestamp()) / 60
        ));
        $scheduledNet = max(0, (int) floor(
            ($scheduledEnd->getTimestamp() - $scheduledStart->getTimestamp()) / 60
        ) - $breakMinutes);
        $extra = max(0, $workedMinutes - $scheduledNet);
        $overtimeMinutes = $extra >= $minimumOvertime ? $extra : 0;
    }

    $sessionId = (int) $openSession['id'];
    $update = $db->prepare(
        "UPDATE cpms_attendance_sessions
         SET clock_out_at=?,clock_out_latitude=?,clock_out_longitude=?,
             clock_out_accuracy_m=?,clock_out_distance_m=?,worked_minutes=?,
             early_departure_minutes=?,overtime_minutes=?,status='Completed',
             open_session_key=NULL
         WHERE id=?"
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare clock out record.');
    }
    $update->bind_param(
        'sddddiiii',
        $recordedAt,
        $latitude,
        $longitude,
        $accuracy,
        $distance,
        $workedMinutes,
        $earlyMinutes,
        $overtimeMinutes,
        $sessionId
    );
    $update->execute();
    $update->close();
    cpmsApiAttendanceAudit($db, $identity, $action, true, 'accepted', $location);
    $db->commit();
    cpmsApiAudit(
        $db, $identity, 'attendance.clock_out', 'success',
        'attendance_session', $sessionId,
        ['worked_minutes' => $workedMinutes, 'overtime_minutes' => $overtimeMinutes]
    );
    cpmsApiRespond([
        'accepted' => true,
        'attendance_state' => 'out',
        'session_id' => $sessionId,
        'recorded_at' => $recordedAt,
        'worked_minutes' => $workedMinutes,
        'overtime_minutes' => $overtimeMinutes,
    ]);
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}

