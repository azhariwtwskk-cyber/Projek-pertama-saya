<?php
declare(strict_types=1);

function cpmsShiftResolve(
    mysqli $db,
    int $propertyId,
    int $userId,
    string $workDate
): ?array {
    $dayNumber = (int) date('N', strtotime($workDate));
    $rotation = cpmsShiftResolveRotation(
        $db, $propertyId, $userId, $workDate, $dayNumber
    );
    if ($rotation !== null) {
        if (!empty($rotation['_rotation_rest_day'])) {
            return null;
        }
        return cpmsShiftAddScheduledTimes($rotation, $workDate);
    }
    $stmt = $db->prepare(
        "SELECT a.id AS assignment_id, s.id AS shift_id, s.shift_name,
                s.start_time, s.end_time, s.working_days,
                s.grace_minutes, s.break_minutes,
                s.minimum_overtime_minutes
         FROM cpms_attendance_shift_assignments a
         JOIN cpms_attendance_shifts s
           ON s.id = a.shift_id AND s.property_id = a.property_id
         WHERE a.property_id = ? AND a.system_user_id = ?
           AND a.status = 'active' AND s.status = 'active'
           AND a.effective_from <= ?
           AND (a.effective_until IS NULL OR a.effective_until >= ?)
           AND FIND_IN_SET(?, s.working_days) > 0
         ORDER BY a.effective_from DESC, a.id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $day = (string) $dayNumber;
    $stmt->bind_param(
        'iisss', $propertyId, $userId, $workDate, $workDate, $day
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        return null;
    }

    return cpmsShiftAddScheduledTimes($row, $workDate);
}

function cpmsShiftResolveRotation(
    mysqli $db,
    int $propertyId,
    int $userId,
    string $workDate,
    int $dayNumber
): ?array {
    $stmt = $db->prepare(
        "SELECT ra.id AS rotation_assignment_id,
                rp.id AS rotation_plan_id, rp.rotation_name, rp.anchor_date
         FROM cpms_attendance_rotation_assignments ra
         JOIN cpms_attendance_rotation_plans rp
           ON rp.id = ra.rotation_plan_id
          AND rp.property_id = ra.property_id
         WHERE ra.property_id = ? AND ra.system_user_id = ?
           AND ra.status = 'active' AND rp.status = 'active'
           AND ra.effective_from <= ?
           AND (ra.effective_until IS NULL OR ra.effective_until >= ?)
         ORDER BY ra.effective_from DESC, ra.id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iiss', $propertyId, $userId, $workDate, $workDate);
    $stmt->execute();
    $assignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($assignment)) {
        return null;
    }

    $items = [];
    $planId = (int) $assignment['rotation_plan_id'];
    $stmt = $db->prepare(
        "SELECT ri.id AS rotation_item_id, ri.duration_weeks,
                s.id AS shift_id, s.shift_name, s.start_time, s.end_time,
                s.working_days, s.grace_minutes, s.break_minutes,
                s.minimum_overtime_minutes
         FROM cpms_attendance_rotation_items ri
         JOIN cpms_attendance_shifts s ON s.id = ri.shift_id
         WHERE ri.rotation_plan_id = ? AND s.status = 'active'
         ORDER BY ri.sequence_order"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    if (!$items) {
        return null;
    }

    $anchor = new DateTimeImmutable((string) $assignment['anchor_date']);
    $date = new DateTimeImmutable($workDate);
    $daysSinceAnchor = (int) floor(
        ($date->getTimestamp() - $anchor->getTimestamp()) / 86400
    );
    if ($daysSinceAnchor < 0) {
        return null;
    }
    $weekNumber = intdiv($daysSinceAnchor, 7);
    $cycleWeeks = 0;
    foreach ($items as $item) {
        $cycleWeeks += max(1, (int) $item['duration_weeks']);
    }
    if ($cycleWeeks < 1) {
        return null;
    }
    $cyclePosition = $weekNumber % $cycleWeeks;
    $cursor = 0;
    foreach ($items as $item) {
        $cursor += max(1, (int) $item['duration_weeks']);
        if ($cyclePosition < $cursor) {
            if (strpos(
                ',' . (string) $item['working_days'] . ',',
                ',' . $dayNumber . ','
            ) === false) {
                return ['_rotation_rest_day' => true];
            }
            $item['assignment_id'] = null;
            $item['rotation_assignment_id'] =
                (int) $assignment['rotation_assignment_id'];
            $item['rotation_name'] = (string) $assignment['rotation_name'];
            $item['rotation_week'] = $weekNumber + 1;
            return $item;
        }
    }
    return null;
}

function cpmsShiftAddScheduledTimes(array $row, string $workDate): array
{
    $start = new DateTimeImmutable(
        $workDate . ' ' . (string) $row['start_time']
    );
    $end = new DateTimeImmutable(
        $workDate . ' ' . (string) $row['end_time']
    );
    if ($end <= $start) {
        $end = $end->modify('+1 day');
    }
    $row['scheduled_start_at'] = $start->format('Y-m-d H:i:s');
    $row['scheduled_end_at'] = $end->format('Y-m-d H:i:s');
    return $row;
}

function cpmsShiftLateMinutes(DateTimeImmutable $clockIn, array $shift): int
{
    $start = new DateTimeImmutable((string) $shift['scheduled_start_at']);
    $difference = (int) floor(($clockIn->getTimestamp()
        - $start->getTimestamp()) / 60);
    return max(0, $difference - (int) $shift['grace_minutes']);
}

function cpmsShiftCompletionMetrics(
    DateTimeImmutable $clockIn,
    DateTimeImmutable $clockOut,
    ?DateTimeImmutable $scheduledStart,
    ?DateTimeImmutable $scheduledEnd,
    int $breakMinutes,
    int $minimumOvertimeMinutes
): array {
    $elapsed = max(0, (int) floor(
        ($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60
    ));
    $worked = max(0, $elapsed - max(0, $breakMinutes));
    $early = 0;
    $overtime = 0;
    if ($scheduledStart !== null && $scheduledEnd !== null) {
        $early = max(0, (int) floor(
            ($scheduledEnd->getTimestamp() - $clockOut->getTimestamp()) / 60
        ));
        $scheduledNet = max(0, (int) floor(
            ($scheduledEnd->getTimestamp() - $scheduledStart->getTimestamp())
            / 60
        ) - max(0, $breakMinutes));
        $extra = max(0, $worked - $scheduledNet);
        $overtime = $extra >= max(0, $minimumOvertimeMinutes) ? $extra : 0;
    }
    return [
        'worked_minutes' => $worked,
        'early_departure_minutes' => $early,
        'overtime_minutes' => $overtime,
    ];
}

function cpmsShiftMinutesLabel(int $minutes): string
{
    $hours = intdiv(max(0, $minutes), 60);
    $remaining = max(0, $minutes) % 60;
    return $hours > 0
        ? $hours . 'j ' . $remaining . 'm'
        : $remaining . 'm';
}
