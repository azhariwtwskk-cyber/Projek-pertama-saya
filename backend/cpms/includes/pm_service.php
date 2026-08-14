<?php
declare(strict_types=1);

function cpmsPmSchemaReady(mysqli $conn): bool
{
    foreach (['pm_schedules', 'pm_work_orders'] as $table) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        if (!$stmt) return false;
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($row['total'] ?? 0) !== 1) return false;
    }
    return true;
}

function cpmsPmNextDate(string $date, string $unit, int $value): string
{
    $value = max(1, $value);
    $allowed = ['Day', 'Week', 'Month', 'Year'];
    if (!in_array($unit, $allowed, true)) $unit = 'Month';
    $time = strtotime($date . ' +' . $value . ' ' . strtolower($unit));
    return $time ? date('Y-m-d', $time) : $date;
}

function cpmsPmWorkOrderReference(): string
{
    return 'PM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function cpmsPmGenerateDue(mysqli $conn, int $propertyId, string $createdBy): array
{
    $today = date('Y-m-d');
    $generated = 0;
    $skipped = 0;
    $errors = [];

    $stmt = $conn->prepare(
        "SELECT p.*, a.asset_code, a.asset_name, a.asset_category, a.location, a.block_location
         FROM pm_schedules p
         INNER JOIN assets a ON a.id = p.asset_id AND a.property_id = p.property_id
         WHERE p.property_id = ? AND p.is_active = 1 AND p.next_due_date <= ?
         ORDER BY p.next_due_date ASC, p.id ASC"
    );
    if (!$stmt) return ['generated'=>0, 'skipped'=>0, 'errors'=>['Unable to load PM schedules.']];
    $stmt->bind_param('is', $propertyId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = [];
    while ($row = $result->fetch_assoc()) $schedules[] = $row;
    $stmt->close();

    foreach ($schedules as $schedule) {
        $scheduleId = (int)$schedule['id'];
        $due = (string)$schedule['next_due_date'];
        $check = $conn->prepare('SELECT id FROM pm_work_orders WHERE pm_schedule_id = ? AND scheduled_due_date = ? LIMIT 1');
        if (!$check) { $errors[] = 'Unable to check PM schedule #' . $scheduleId; continue; }
        $check->bind_param('is', $scheduleId, $due);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if ($exists) {
            $skipped++;
            $next = cpmsPmNextDate($due, (string)$schedule['frequency_unit'], (int)$schedule['frequency_value']);
            $up = $conn->prepare('UPDATE pm_schedules SET next_due_date = ?, last_generated_date = ? WHERE id = ? AND property_id = ?');
            if ($up) { $up->bind_param('ssii', $next, $today, $scheduleId, $propertyId); $up->execute(); $up->close(); }
            continue;
        }

        $reference = cpmsPmWorkOrderReference();
        $title = 'PM - ' . (string)$schedule['asset_name'] . ': ' . (string)$schedule['task_title'];
        $description = trim((string)$schedule['task_description']);
        if ($description !== '') $description .= "\n\n";
        $description .= 'Preventive Maintenance for asset ' . (string)$schedule['asset_code'] . '. Scheduled due date: ' . $due . '.';
        $category = (string)$schedule['asset_category'];
        $priority = (string)$schedule['priority'];
        $block = trim((string)$schedule['block_location']) !== '' ? (string)$schedule['block_location'] : 'General';
        $location = (string)$schedule['location'];
        $staffId = (int)($schedule['assigned_staff_id'] ?? 0);
        $status = $staffId > 0 ? 'Assigned' : 'Open';
        $estimated = $schedule['estimated_cost'] !== null ? (float)$schedule['estimated_cost'] : 0.0;

        $conn->begin_transaction();
        try {
            $wo = $conn->prepare(
                "INSERT INTO work_orders (property_id, work_order_reference, title, description, category, priority,
                 block_location, specific_location, assigned_staff_id, scheduled_date, due_date, estimated_cost,
                 status, admin_remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, ?, NULLIF(?,0), ?, ?, ?)"
            );
            if (!$wo) throw new RuntimeException('Unable to prepare work order.');
            $remarks = 'Auto-generated from Preventive Maintenance schedule #' . $scheduleId;
            $wo->bind_param('isssssssissdsss', $propertyId, $reference, $title, $description, $category, $priority, $block, $location, $staffId, $today, $due, $estimated, $status, $remarks, $createdBy);
            $wo->execute();
            $workOrderId = (int)$conn->insert_id;
            $wo->close();

            $link = $conn->prepare('INSERT INTO pm_work_orders(property_id, pm_schedule_id, work_order_id, scheduled_due_date) VALUES(?,?,?,?)');
            if (!$link) throw new RuntimeException('Unable to link PM work order.');
            $link->bind_param('iiis', $propertyId, $scheduleId, $workOrderId, $due);
            $link->execute();
            $link->close();

            $next = cpmsPmNextDate($due, (string)$schedule['frequency_unit'], (int)$schedule['frequency_value']);
            $up = $conn->prepare('UPDATE pm_schedules SET next_due_date = ?, last_generated_date = ? WHERE id = ? AND property_id = ?');
            if (!$up) throw new RuntimeException('Unable to update next PM date.');
            $up->bind_param('ssii', $next, $today, $scheduleId, $propertyId);
            $up->execute();
            $up->close();

            $conn->commit();
            $generated++;
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = 'Schedule #' . $scheduleId . ': ' . $e->getMessage();
        }
    }
    return ['generated'=>$generated, 'skipped'=>$skipped, 'errors'=>$errors];
}
