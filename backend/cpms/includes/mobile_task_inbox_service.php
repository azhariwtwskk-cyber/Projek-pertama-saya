<?php
declare(strict_types=1);

function cpmsMobileInboxTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) total FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}

function cpmsMobileInboxLegacyIdentity(
    mysqli $conn,
    int $systemUserId
): array {
    $stmt = $conn->prepare(
        'SELECT source_table, source_id FROM system_users WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['', 0];
    }
    $stmt->bind_param('i', $systemUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        (string) ($row['source_table'] ?? ''),
        (int) ($row['source_id'] ?? 0),
    ];
}

function cpmsMobileInboxSyncWorkOrders(
    mysqli $conn,
    int $propertyId,
    int $systemUserId,
    int $staffId
): void {
    if ($staffId < 1 || !cpmsMobileInboxTableExists($conn, 'work_orders')) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT id, work_order_reference, title, priority, status, due_date
         FROM work_orders
         WHERE property_id = ? AND assigned_staff_id = ?
           AND status NOT IN ('Verified', 'Cancelled')
         ORDER BY due_date, id"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $propertyId, $staffId);
    $stmt->execute();
    $result = $stmt->get_result();
    $activeIds = [];
    $today = new DateTimeImmutable('today');
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        $activeIds[] = $id;
        $status = (string) $row['status'];
        $dueText = trim((string) ($row['due_date'] ?? ''));
        $severity = 'info';
        $title = 'Work Order: ' . (string) $row['work_order_reference'];
        $message = (string) $row['title'] . ' — ' . $status;
        if ($dueText !== '') {
            try {
                $due = new DateTimeImmutable($dueText);
                $days = (int) $today->diff($due)->format('%r%a');
                if ($days < 0) {
                    $severity = 'danger';
                    $title = 'Work Order lewat';
                    $message .= ' · Lewat ' . abs($days) . ' hari';
                } elseif ($days <= 2) {
                    $severity = 'warning';
                    $message .= ' · Due ' . $dueText;
                }
            } catch (Throwable $exception) {
                // Keep the standard notification.
            }
        }
        $keyStatus = strtolower(preg_replace(
            '/[^a-zA-Z0-9]+/', '-', $status
        ));
        cpmsNotificationUpsert(
            $conn, $propertyId, $systemUserId,
            'work-order-' . $id . '-' . $keyStatus,
            'work_order_assigned', $title, $message,
            'staff_work_orders.php', $severity, $id, 'work_order'
        );
    }
    $stmt->close();

    $close = $conn->prepare(
        "UPDATE cpms_user_notifications n
         LEFT JOIN work_orders w
           ON w.id = n.related_id AND n.related_type = 'work_order'
         SET n.is_read = 1, n.read_at = COALESCE(n.read_at, NOW())
         WHERE n.property_id = ? AND n.recipient_system_user_id = ?
           AND n.related_type = 'work_order'
           AND (w.id IS NULL OR w.status IN ('Verified', 'Cancelled'))"
    );
    if ($close) {
        $close->bind_param('ii', $propertyId, $systemUserId);
        $close->execute();
        $close->close();
    }
}

function cpmsMobileInboxSyncPatrolReminder(
    mysqli $conn,
    int $propertyId,
    int $systemUserId,
    int $guardId
): void {
    if ($guardId < 1 || !cpmsMobileInboxTableExists($conn, 'security_patrols')) {
        return;
    }
    $stmt = $conn->prepare(
        'SELECT COUNT(*) total FROM security_patrols
         WHERE property_id = ? AND guard_id = ? AND patrol_date = CURDATE()'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $propertyId, $guardId);
    $stmt->execute();
    $done = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
    $stmt->close();
    $key = 'security-patrol-' . date('Y-m-d');
    if (!$done) {
        cpmsNotificationUpsert(
            $conn, $propertyId, $systemUserId, $key,
            'security_patrol_reminder', 'Rondaan hari ini belum direkod',
            'Mulakan rondaan dan lengkapkan laporan bagi syif anda.',
            'security_patrol_form.php', 'warning', 0, 'security_patrol'
        );
        return;
    }
    $read = $conn->prepare(
        'UPDATE cpms_user_notifications
         SET is_read = 1, read_at = COALESCE(read_at, NOW())
         WHERE property_id = ? AND recipient_system_user_id = ?
           AND notification_key = ?'
    );
    if ($read) {
        $read->bind_param('iis', $propertyId, $systemUserId, $key);
        $read->execute();
        $read->close();
    }
}

function cpmsMobileInboxSync(
    mysqli $conn,
    int $propertyId,
    int $systemUserId,
    string $role
): void {
    list($sourceTable, $sourceId) = cpmsMobileInboxLegacyIdentity(
        $conn, $systemUserId
    );
    if ($role === 'staff') {
        cpmsMobileInboxSyncWorkOrders(
            $conn, $propertyId, $systemUserId,
            $sourceTable === 'staff' ? $sourceId : 0
        );
        if (cpmsMobileInboxTableExists($conn, 'cpms_pm_schedules')
            && cpmsMobileInboxTableExists($conn, 'cpms_pm_work_logs')) {
            cpmsNotificationSyncPreventiveMaintenance($conn, $propertyId);
        }
        if (cpmsMobileInboxTableExists(
            $conn, 'inspection_corrective_actions'
        )) {
            cpmsNotificationSyncCorrectiveActions($conn, $propertyId);
        }
    } elseif ($role === 'security') {
        cpmsMobileInboxSyncPatrolReminder(
            $conn, $propertyId, $systemUserId,
            $sourceTable === 'security_guards' ? $sourceId : 0
        );
    }
}
