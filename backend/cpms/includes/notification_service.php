<?php
declare(strict_types=1);

function cpmsNotificationCsrfToken(): string
{
    if (empty($_SESSION['cpms_notification_csrf'])) {
        $_SESSION['cpms_notification_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_notification_csrf'];
}

function cpmsNotificationVerifyCsrf(?string $token): bool
{
    $stored = (string) ($_SESSION['cpms_notification_csrf'] ?? '');
    return $stored !== ''
        && $token !== null
        && hash_equals($stored, $token);
}

function cpmsNotificationUpsert(
    mysqli $conn,
    int $propertyId,
    int $recipientId,
    string $key,
    string $type,
    string $title,
    string $message,
    string $targetUrl,
    string $severity,
    int $relatedId,
    string $relatedType = 'corrective_action'
): void {
    if ($propertyId < 1 || $recipientId < 1) {
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO cpms_user_notifications (
            property_id, recipient_system_user_id,
            notification_key, notification_type, title,
            message, target_url, severity, related_type,
            related_id
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            message = VALUES(message),
            target_url = VALUES(target_url),
            severity = VALUES(severity),
            updated_at = CURRENT_TIMESTAMP"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iisssssssi',
        $propertyId,
        $recipientId,
        $key,
        $type,
        $title,
        $message,
        $targetUrl,
        $severity,
        $relatedType,
        $relatedId
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsNotificationResolveMaintenanceReminders(
    mysqli $conn,
    int $propertyId,
    int $scheduleId,
    string $activeKey
): void {
    $like = 'pm-reminder-' . $scheduleId . '-%';
    $stmt = $conn->prepare(
        "UPDATE cpms_user_notifications
         SET is_read = 1,
             read_at = COALESCE(read_at, NOW())
         WHERE property_id = ?
           AND related_type = 'preventive_maintenance'
           AND related_id = ?
           AND notification_key LIKE ?
           AND notification_key <> ?
           AND is_read = 0"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iiss',
        $propertyId,
        $scheduleId,
        $like,
        $activeKey
    );
    $stmt->execute();
    $stmt->close();
}

function cpmsNotificationSyncPreventiveMaintenance(
    mysqli $conn,
    int $propertyId
): void {
    if ($propertyId < 1) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT s.id, s.schedule_name, s.asset_name,
                s.next_due_date, s.assigned_system_user_id,
                s.assigned_name, s.priority,
                latest.id AS latest_log_id,
                latest.completed_date,
                latest.verified_at,
                latest.verified_by_name
         FROM cpms_pm_schedules s
         LEFT JOIN cpms_pm_work_logs latest
           ON latest.id = (
                SELECT l.id
                FROM cpms_pm_work_logs l
                WHERE l.schedule_id = s.id
                  AND l.property_id = s.property_id
                ORDER BY l.id DESC
                LIMIT 1
           )
         WHERE s.property_id = ?
           AND s.status = 'active'
         ORDER BY s.next_due_date, s.id"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $schedules = [];
    while ($row = $result->fetch_assoc()) {
        $schedules[] = $row;
    }
    $stmt->close();

    $managers = cpmsNotificationPropertyManagers($conn, $propertyId);
    $today = new DateTimeImmutable('today');

    foreach ($schedules as $schedule) {
        $scheduleId = (int) $schedule['id'];
        $assigneeId = (int) (
            $schedule['assigned_system_user_id'] ?? 0
        );
        $dueDate = trim((string) $schedule['next_due_date']);
        $name = (string) $schedule['schedule_name'];
        $asset = (string) $schedule['asset_name'];
        $staffUrl = 'staff_maintenance_view.php?id=' . $scheduleId;
        $portalUrl = 'pm_schedule_view.php?id=' . $scheduleId;

        if ($assigneeId > 0) {
            cpmsNotificationUpsert(
                $conn,
                $propertyId,
                $assigneeId,
                'pm-assigned-' . $scheduleId,
                'maintenance_assigned',
                'Preventive Maintenance diberikan',
                $name . ' untuk ' . $asset
                    . '. Due date: ' . $dueDate . '.',
                $staffUrl,
                'info',
                $scheduleId,
                'preventive_maintenance'
            );
        }

        $latestLogId = (int) ($schedule['latest_log_id'] ?? 0);
        $verifiedAt = trim((string) (
            $schedule['verified_at'] ?? ''
        ));
        if ($latestLogId > 0 && $verifiedAt === '') {
            foreach ($managers as $managerId) {
                cpmsNotificationUpsert(
                    $conn,
                    $propertyId,
                    $managerId,
                    'pm-pending-verification-' . $latestLogId,
                    'maintenance_pending_verification',
                    'Maintenance menunggu pengesahan',
                    $name . ' telah dihantar oleh '
                        . (string) $schedule['assigned_name'] . '.',
                    $portalUrl,
                    'warning',
                    $scheduleId,
                    'preventive_maintenance'
                );
            }
        } elseif (
            $latestLogId > 0
            && $verifiedAt !== ''
            && $assigneeId > 0
        ) {
            cpmsNotificationUpsert(
                $conn,
                $propertyId,
                $assigneeId,
                'pm-verified-' . $latestLogId,
                'maintenance_verified',
                'Maintenance telah disahkan',
                $name . ' telah disahkan oleh pihak pengurusan.',
                $staffUrl,
                'success',
                $scheduleId,
                'preventive_maintenance'
            );
        }

        try {
            $due = new DateTimeImmutable($dueDate);
        } catch (Throwable $exception) {
            continue;
        }
        $days = (int) $today->diff($due)->format('%r%a');
        $dateKey = str_replace('-', '', $dueDate);
        $activeKey = '';
        if ($days < 0) {
            $activeKey = 'pm-reminder-' . $scheduleId
                . '-overdue-' . $dateKey;
            $message = $name . ' untuk ' . $asset
                . ' telah lewat ' . abs($days)
                . ' hari. Due date: ' . $dueDate . '.';
            if ($assigneeId > 0) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $assigneeId,
                    $activeKey, 'maintenance_overdue',
                    'Preventive Maintenance overdue',
                    $message, $staffUrl, 'danger', $scheduleId,
                    'preventive_maintenance'
                );
            }
            foreach ($managers as $managerId) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $managerId,
                    $activeKey, 'maintenance_overdue',
                    'Preventive Maintenance overdue',
                    $message, $portalUrl, 'danger', $scheduleId,
                    'preventive_maintenance'
                );
            }
        } elseif ($days <= 7) {
            $activeKey = 'pm-reminder-' . $scheduleId
                . '-due-' . $dateKey;
            $message = $name . ' untuk ' . $asset
                . ' perlu dibuat dalam ' . $days
                . ' hari. Due date: ' . $dueDate . '.';
            if ($assigneeId > 0) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $assigneeId,
                    $activeKey, 'maintenance_due_soon',
                    'Maintenance semakin hampir',
                    $message, $staffUrl, 'warning', $scheduleId,
                    'preventive_maintenance'
                );
            }
            foreach ($managers as $managerId) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $managerId,
                    $activeKey, 'maintenance_due_soon',
                    'Maintenance semakin hampir',
                    $message, $portalUrl, 'warning', $scheduleId,
                    'preventive_maintenance'
                );
            }
        }

        cpmsNotificationResolveMaintenanceReminders(
            $conn,
            $propertyId,
            $scheduleId,
            $activeKey
        );
    }
}

function cpmsNotificationPropertyManagers(
    mysqli $conn,
    int $propertyId
): array {
    $stmt = $conn->prepare(
        "SELECT DISTINCT u.id
         FROM system_users u
         INNER JOIN user_roles ur
            ON ur.system_user_id = u.id
           AND ur.property_id = ?
           AND ur.status = 'active'
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.role_code IN ('property_admin', 'manager')
           AND r.status = 'active'
         WHERE u.status = 'active'
           AND (u.property_id = ? OR u.property_id IS NULL)"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $stmt->close();
    return $ids;
}

function cpmsNotificationSyncCorrectiveActions(
    mysqli $conn,
    int $propertyId
): void {
    $stmt = $conn->prepare(
        "SELECT id, action_no, title, assigned_system_user_id,
                assigned_name, due_date, status, priority
         FROM inspection_corrective_actions
         WHERE property_id = ?
           AND status NOT IN ('Closed')
         ORDER BY id DESC"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $actions = [];
    while ($row = $result->fetch_assoc()) {
        $actions[] = $row;
    }
    $stmt->close();

    $managers = cpmsNotificationPropertyManagers($conn, $propertyId);
    $today = new DateTimeImmutable('today');

    foreach ($actions as $action) {
        $actionId = (int) $action['id'];
        $actionNo = (string) $action['action_no'];
        $assigneeId = (int) ($action['assigned_system_user_id'] ?? 0);
        $status = (string) $action['status'];
        $dueDate = trim((string) ($action['due_date'] ?? ''));
        $staffUrl = 'staff_corrective_action_view.php?id=' . $actionId;
        $portalUrl = 'inspection_action_view.php?id=' . $actionId;

        if ($assigneeId > 0 && in_array(
            $status,
            ['Open', 'In Progress'],
            true
        )) {
            cpmsNotificationUpsert(
                $conn,
                $propertyId,
                $assigneeId,
                'ca-assigned-' . $actionId,
                'corrective_action_assigned',
                'Corrective Action diberikan',
                $actionNo . ': ' . (string) $action['title'],
                $staffUrl,
                'info',
                $actionId
            );
        }

        if ($status === 'Rectified') {
            foreach ($managers as $managerId) {
                cpmsNotificationUpsert(
                    $conn,
                    $propertyId,
                    $managerId,
                    'ca-rectified-' . $actionId,
                    'corrective_action_rectified',
                    'Menunggu pengesahan',
                    $actionNo . ' telah dihantar oleh '
                        . (string) $action['assigned_name'] . '.',
                    $portalUrl,
                    'warning',
                    $actionId
                );
            }
        }

        if (in_array($status, ['Verified', 'Closed'], true)
            && $assigneeId > 0
        ) {
            cpmsNotificationUpsert(
                $conn,
                $propertyId,
                $assigneeId,
                'ca-verified-' . $actionId,
                'corrective_action_verified',
                'Corrective Action disahkan',
                $actionNo . ' telah disahkan oleh pihak pengurusan.',
                $staffUrl,
                'success',
                $actionId
            );
        }

        if ($dueDate === ''
            || in_array($status, ['Verified', 'Closed'], true)
        ) {
            continue;
        }

        try {
            $due = new DateTimeImmutable($dueDate);
        } catch (Throwable $exception) {
            continue;
        }
        $days = (int) $today->diff($due)->format('%r%a');
        if ($days < 0) {
            $lateDays = abs($days);
            $message = $actionNo . ' lewat ' . $lateDays
                . ' hari. Due date: ' . $dueDate . '.';
            if ($assigneeId > 0) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $assigneeId,
                    'ca-overdue-' . $actionId,
                    'corrective_action_overdue',
                    'Corrective Action overdue',
                    $message, $staffUrl, 'danger', $actionId
                );
            }
            foreach ($managers as $managerId) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $managerId,
                    'ca-overdue-' . $actionId,
                    'corrective_action_overdue',
                    'Corrective Action overdue',
                    $message, $portalUrl, 'danger', $actionId
                );
            }
        } elseif ($days <= 3) {
            $message = $actionNo . ' perlu diselesaikan dalam '
                . $days . ' hari. Due date: ' . $dueDate . '.';
            if ($assigneeId > 0) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $assigneeId,
                    'ca-due-soon-' . $actionId,
                    'corrective_action_due_soon',
                    'Tarikh akhir semakin hampir',
                    $message, $staffUrl, 'warning', $actionId
                );
            }
            foreach ($managers as $managerId) {
                cpmsNotificationUpsert(
                    $conn, $propertyId, $managerId,
                    'ca-due-soon-' . $actionId,
                    'corrective_action_due_soon',
                    'Tarikh akhir semakin hampir',
                    $message, $portalUrl, 'warning', $actionId
                );
            }
        }
    }
}

function cpmsNotificationUnreadCount(
    mysqli $conn,
    int $propertyId,
    int $recipientId
): int {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM cpms_user_notifications
         WHERE property_id = ?
           AND recipient_system_user_id = ?
           AND is_read = 0'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('ii', $propertyId, $recipientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function cpmsNotificationMarkRead(
    mysqli $conn,
    int $propertyId,
    int $recipientId,
    int $notificationId
): void {
    $stmt = $conn->prepare(
        'UPDATE cpms_user_notifications
         SET is_read = 1, read_at = NOW()
         WHERE id = ?
           AND property_id = ?
           AND recipient_system_user_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param(
            'iii',
            $notificationId,
            $propertyId,
            $recipientId
        );
        $stmt->execute();
        $stmt->close();
    }
}

function cpmsNotificationMarkAllRead(
    mysqli $conn,
    int $propertyId,
    int $recipientId
): void {
    $stmt = $conn->prepare(
        'UPDATE cpms_user_notifications
         SET is_read = 1, read_at = NOW()
         WHERE property_id = ?
           AND recipient_system_user_id = ?
           AND is_read = 0'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $propertyId, $recipientId);
        $stmt->execute();
        $stmt->close();
    }
}
