<?php
declare(strict_types=1);

function cpmsAttendanceApprovalAccess(
    mysqli $db,
    int $propertyId,
    int $userId,
    bool $isOwner
): array {
    if ($isOwner || cpmsCan('attendance.timesheet.approve', $db)) {
        return ['allowed' => true, 'delegation_id' => null];
    }
    if ($propertyId < 1 || $userId < 1) {
        return ['allowed' => false, 'delegation_id' => null];
    }
    $stmt = $db->prepare(
        "SELECT id FROM cpms_attendance_delegations
         WHERE property_id=? AND delegated_to_system_user_id=?
           AND delegation_scope IN ('Timesheet','All Attendance')
           AND delegation_status='Active'
           AND CURDATE() BETWEEN valid_from AND valid_until
         ORDER BY valid_until DESC LIMIT 1"
    );
    $stmt->bind_param('ii', $propertyId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'allowed' => is_array($row),
        'delegation_id' => is_array($row) ? (int) $row['id'] : null,
    ];
}

function cpmsAttendanceApprovalAudit(
    mysqli $db,
    int $propertyId,
    string $month,
    string $oldStatus,
    string $newStatus,
    int $actorId,
    ?int $delegationId,
    string $notes
): void {
    $action = $delegationId ? 'Delegated Approval' : 'Direct Approval';
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $stmt = $db->prepare(
        "INSERT INTO cpms_attendance_approval_audit
         (property_id,attendance_month,action_type,old_status,new_status,
          actor_system_user_id,delegation_id,notes,ip_address)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param(
        'issssiiss',
        $propertyId, $month, $action, $oldStatus, $newStatus,
        $actorId, $delegationId, $notes, $ip
    );
    $stmt->execute();
    $stmt->close();
}
