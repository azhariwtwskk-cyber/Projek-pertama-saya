<?php
declare(strict_types=1);

/**
 * CPMS Notification Engine
 * Langkah 5.8A
 *
 * Kegunaan:
 * require_once __DIR__ . '/includes/notification_engine.php';
 *
 * notification_create($conn, [
 *     'property_id' => 1,
 *     'type' => 'complaint',
 *     'title' => 'Aduan Baharu',
 *     'message' => 'Aduan baharu telah diterima.',
 *     'priority' => 'info',
 *     'target_role' => 'admin',
 *     'action_url' => 'admin_complaint_details.php?id=123'
 * ]);
 */

function notification_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}


function notification_current_property_id(mysqli $conn): int
{
    if (!empty($_SESSION['property_id'])) {
        return max(1, (int)$_SESSION['property_id']);
    }

    return 1;
}


function notification_current_actor(): array
{
    if (!empty($_SESSION['admin_id'])) {
        return [
            'role' => 'admin',
            'user_id' => (int)$_SESSION['admin_id']
        ];
    }

    if (!empty($_SESSION['staff_id'])) {
        return [
            'role' => 'staff',
            'user_id' => (int)$_SESSION['staff_id']
        ];
    }

    if (!empty($_SESSION['security_guard_id'])) {
        return [
            'role' => 'security',
            'user_id' => (int)$_SESSION['security_guard_id']
        ];
    }

    if (!empty($_SESSION['resident_id'])) {
        return [
            'role' => 'resident',
            'user_id' => (int)$_SESSION['resident_id']
        ];
    }

    return [
        'role' => null,
        'user_id' => null
    ];
}


function notification_create(mysqli $conn, array $data): int
{
    if (!notification_table_exists($conn, 'notifications')) {
        throw new RuntimeException(
            'Jadual notifications belum dipasang. Import SQL Langkah 5.8A.'
        );
    }

    $propertyId = max(
        1,
        (int)($data['property_id'] ?? notification_current_property_id($conn))
    );

    $type = trim((string)($data['type'] ?? 'system'));
    $title = trim((string)($data['title'] ?? 'Notification'));
    $message = trim((string)($data['message'] ?? ''));

    $priority = strtolower(trim((string)($data['priority'] ?? 'info')));
    $allowedPriorities = ['info', 'warning', 'critical'];

    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'info';
    }

    $targetRole = strtolower(trim((string)($data['target_role'] ?? 'admin')));
    $allowedRoles = ['admin', 'staff', 'security', 'resident', 'all'];

    if (!in_array($targetRole, $allowedRoles, true)) {
        $targetRole = 'admin';
    }

    $targetUserId = isset($data['target_user_id'])
        && $data['target_user_id'] !== ''
        ? (int)$data['target_user_id']
        : null;

    $sourceTable = isset($data['source_table'])
        ? trim((string)$data['source_table'])
        : null;

    $sourceId = isset($data['source_id'])
        && $data['source_id'] !== ''
        ? (int)$data['source_id']
        : null;

    $actionUrl = isset($data['action_url'])
        ? trim((string)$data['action_url'])
        : null;

    $icon = isset($data['icon'])
        ? trim((string)$data['icon'])
        : null;

    $expiresAt = isset($data['expires_at'])
        && trim((string)$data['expires_at']) !== ''
        ? trim((string)$data['expires_at'])
        : null;

    $actor = notification_current_actor();

    $createdByRole = isset($data['created_by_role'])
        ? trim((string)$data['created_by_role'])
        : $actor['role'];

    $createdByUserId = isset($data['created_by_user_id'])
        && $data['created_by_user_id'] !== ''
        ? (int)$data['created_by_user_id']
        : $actor['user_id'];

    if ($title === '') {
        throw new InvalidArgumentException('Tajuk notification diperlukan.');
    }

    if ($message === '') {
        throw new InvalidArgumentException('Mesej notification diperlukan.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO notifications (
            property_id,
            notification_type,
            title,
            message,
            priority,
            target_role,
            target_user_id,
            source_table,
            source_id,
            action_url,
            icon,
            created_by_role,
            created_by_user_id,
            expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal menyediakan notification statement: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'isssssisisssis',
        $propertyId,
        $type,
        $title,
        $message,
        $priority,
        $targetRole,
        $targetUserId,
        $sourceTable,
        $sourceId,
        $actionUrl,
        $icon,
        $createdByRole,
        $createdByUserId,
        $expiresAt
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Gagal mencipta notification: ' . $error);
    }

    $notificationId = (int)$stmt->insert_id;
    $stmt->close();

    return $notificationId;
}


function notification_create_for_admins(
    mysqli $conn,
    int $propertyId,
    string $type,
    string $title,
    string $message,
    string $priority = 'info',
    ?string $actionUrl = null,
    ?string $sourceTable = null,
    ?int $sourceId = null
): int {
    return notification_create($conn, [
        'property_id' => $propertyId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'target_role' => 'admin',
        'action_url' => $actionUrl,
        'source_table' => $sourceTable,
        'source_id' => $sourceId
    ]);
}


function notification_create_for_user(
    mysqli $conn,
    int $propertyId,
    string $targetRole,
    int $targetUserId,
    string $type,
    string $title,
    string $message,
    string $priority = 'info',
    ?string $actionUrl = null,
    ?string $sourceTable = null,
    ?int $sourceId = null
): int {
    return notification_create($conn, [
        'property_id' => $propertyId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'target_role' => $targetRole,
        'target_user_id' => $targetUserId,
        'action_url' => $actionUrl,
        'source_table' => $sourceTable,
        'source_id' => $sourceId
    ]);
}


function notification_mark_read(
    mysqli $conn,
    int $notificationId,
    string $userRole,
    int $userId
): bool {
    if (!notification_table_exists($conn, 'notification_reads')) {
        return false;
    }

    $allowedRoles = ['admin', 'staff', 'security', 'resident'];

    if (!in_array($userRole, $allowedRoles, true)) {
        return false;
    }

    $stmt = $conn->prepare(
        "INSERT INTO notification_reads
        (notification_id, user_role, user_id, read_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isi', $notificationId, $userRole, $userId);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}


function notification_unread_count(
    mysqli $conn,
    int $propertyId,
    string $userRole,
    int $userId
): int {
    if (
        !notification_table_exists($conn, 'notifications')
        || !notification_table_exists($conn, 'notification_reads')
    ) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notifications n
         LEFT JOIN notification_reads r
           ON r.notification_id = n.id
          AND r.user_role = ?
          AND r.user_id = ?
         WHERE n.property_id = ?
           AND (
                n.target_role = 'all'
                OR n.target_role = ?
           )
           AND (
                n.target_user_id IS NULL
                OR n.target_user_id = ?
           )
           AND (
                n.expires_at IS NULL
                OR n.expires_at >= NOW()
           )
           AND r.id IS NULL"
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param(
        'siisi',
        $userRole,
        $userId,
        $propertyId,
        $userRole,
        $userId
    );

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}
