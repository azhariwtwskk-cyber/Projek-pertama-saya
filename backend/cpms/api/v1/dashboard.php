<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff', 'security']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$userId = (int) $identity['system_user_id'];
$role = (string) $identity['role'];

if ($role === 'staff') {
    $staffId = cpmsApiStaffId($identity);
    $tasks = cpmsApiTaskRows($db, $identity, 12);

    $workOrderStmt = $db->prepare(
        "SELECT COUNT(*) AS total FROM work_orders
         WHERE property_id=? AND assigned_staff_id=?
           AND status NOT IN ('Verified','Cancelled')"
    );
    $workOrderStmt->bind_param('ii', $propertyId, $staffId);
    $workOrderStmt->execute();
    $workOrders = (int) ($workOrderStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $workOrderStmt->close();

    $pmStmt = $db->prepare(
        "SELECT COUNT(*) AS total FROM cpms_pm_schedules
         WHERE property_id=? AND assigned_system_user_id=?
           AND status='active' AND next_due_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)"
    );
    $pmStmt->bind_param('ii', $propertyId, $userId);
    $pmStmt->execute();
    $pmTasks = (int) ($pmStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $pmStmt->close();

    $pmListStmt = $db->prepare(
        "SELECT id,schedule_name,next_due_date,status
         FROM cpms_pm_schedules
         WHERE property_id=? AND assigned_system_user_id=?
           AND status='active' AND next_due_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)
         ORDER BY next_due_date,id
         LIMIT 20"
    );
    $pmListStmt->bind_param('ii', $propertyId, $userId);
    $pmListStmt->execute();
    $pmResult = $pmListStmt->get_result();
    $pmTaskRows = [];
    while ($pmRow = $pmResult->fetch_assoc()) {
        $dueDate = trim((string) ($pmRow['next_due_date'] ?? ''));
        $pmTaskRows[] = [
            'database_id' => (int) $pmRow['id'],
            'title' => (string) $pmRow['schedule_name'],
            'due' => $dueDate !== '' ? date('d/m/Y', strtotime($dueDate)) : '-',
            'status' => (string) $pmRow['status'],
        ];
    }
    $pmListStmt->close();

    $attendanceStmt = $db->prepare(
        "SELECT COUNT(DISTINCT work_date) AS total
         FROM cpms_attendance_sessions
         WHERE property_id=? AND system_user_id=?
           AND work_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')
           AND work_date<=CURDATE()"
    );
    $attendanceStmt->bind_param('ii', $propertyId, $userId);
    $attendanceStmt->execute();
    $attendanceDays = (int) ($attendanceStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $attendanceStmt->close();

    $leaveBalanceStmt = $db->prepare(
        "SELECT COALESCE(SUM(entitlement_days+adjustment_days),0) AS total
         FROM cpms_leave_balances
         WHERE property_id=? AND system_user_id=?
           AND leave_year=YEAR(CURDATE())"
    );
    $leaveBalanceStmt->bind_param('ii', $propertyId, $userId);
    $leaveBalanceStmt->execute();
    $leaveEntitlement = (float) (
        $leaveBalanceStmt->get_result()->fetch_assoc()['total'] ?? 0
    );
    $leaveBalanceStmt->close();

    $leaveUsedStmt = $db->prepare(
        "SELECT COALESCE(SUM(total_days),0) AS total
         FROM cpms_leave_requests
         WHERE property_id=? AND system_user_id=?
           AND request_status='Approved'
           AND YEAR(start_date)=YEAR(CURDATE())"
    );
    $leaveUsedStmt->bind_param('ii', $propertyId, $userId);
    $leaveUsedStmt->execute();
    $leaveUsed = (float) (
        $leaveUsedStmt->get_result()->fetch_assoc()['total'] ?? 0
    );
    $leaveUsedStmt->close();
    $leaveDays = $leaveEntitlement - $leaveUsed;

    cpmsApiRespond([
        'shift' => cpmsApiShiftLabel($db, $identity),
        'attendanceState' => cpmsApiAttendanceState($db, $identity),
        'withinLocation' => true,
        'stats' => [
            'workOrders' => $workOrders,
            'pmTasks' => $pmTasks,
            'attendanceDays' => $attendanceDays,
            'leaveDays' => max(0, $leaveDays),
        ],
        'tasks' => $tasks,
        'pm_tasks' => $pmTaskRows,
    ]);
}

$guardId = cpmsApiGuardId($identity);
$activePatrol = cpmsApiActivePatrol($db, $identity);
$totalStmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM security_checkpoints
     WHERE property_id=? AND is_active=1'
);
$totalStmt->bind_param('i', $propertyId);
$totalStmt->execute();
$checkpointTotal = (int) ($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalStmt->close();

$checkpointDone = 0;
if (is_array($activePatrol)) {
    $sessionId = (int) $activePatrol['id'];
    $doneStmt = $db->prepare(
        'SELECT COUNT(DISTINCT checkpoint_id) AS total
         FROM cpms_workforce_checkpoint_events WHERE patrol_session_id=?'
    );
    $doneStmt->bind_param('i', $sessionId);
    $doneStmt->execute();
    $checkpointDone = (int) ($doneStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $doneStmt->close();
}

$incidentStmt = $db->prepare(
    'SELECT COUNT(*) AS total FROM cpms_security_incidents
     WHERE property_id=? AND guard_id=? AND DATE(recorded_at)=CURDATE()'
);
$incidentStmt->bind_param('ii', $propertyId, $guardId);
$incidentStmt->execute();
$incidents = (int) ($incidentStmt->get_result()->fetch_assoc()['total'] ?? 0);
$incidentStmt->close();

$routeStmt = $db->prepare(
    'SELECT id FROM security_checkpoints
     WHERE property_id=? AND is_active=1 ORDER BY scan_order,id LIMIT 20'
);
$routeStmt->bind_param('i', $propertyId);
$routeStmt->execute();
$routeResult = $routeStmt->get_result();
$route = [];
while ($row = $routeResult->fetch_assoc()) {
    $route[] = (int) $row['id'];
}
$routeStmt->close();

$activities = [];
$activityStmt = $db->prepare(
    "SELECT title_text,activity_time FROM (
       SELECT CONCAT('Checkpoint ',c.checkpoint_name,' diimbas') AS title_text,
              e.scanned_at AS activity_time
       FROM cpms_workforce_checkpoint_events e
       JOIN security_checkpoints c ON c.id=e.checkpoint_id
       WHERE e.property_id=? AND e.guard_id=?
       UNION ALL
       SELECT CONCAT('Kejadian dilapor: ',i.location_name) AS title_text,
              i.recorded_at AS activity_time
       FROM cpms_security_incidents i
       WHERE i.property_id=? AND i.guard_id=?
     ) activity
     ORDER BY activity_time DESC LIMIT 8"
);
$activityStmt->bind_param('iiii', $propertyId, $guardId, $propertyId, $guardId);
$activityStmt->execute();
$activityResult = $activityStmt->get_result();
while ($row = $activityResult->fetch_assoc()) {
    $activities[] = [
        'time' => date('g:i A', strtotime((string) $row['activity_time'])),
        'title' => (string) $row['title_text'],
        'status' => 'completed',
    ];
}
$activityStmt->close();

cpmsApiRespond([
    'shift' => cpmsApiShiftLabel($db, $identity),
    'patrolState' => is_array($activePatrol) ? 'active' : 'idle',
    'gpsActive' => true,
    'stats' => [
        'checkpointsDone' => $checkpointDone,
        'checkpointsTotal' => $checkpointTotal,
        'incidents' => $incidents,
        'visitors' => 0,
    ],
    'route' => $route,
    'activities' => $activities,
]);
