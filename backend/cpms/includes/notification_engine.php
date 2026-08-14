<?php

declare(strict_types=1);

function cpmsNotify(
    mysqli $conn,
    int $propertyId,
    string $title,
    string $message,
    string $notificationType = "INFO",
    ?string $referenceNo = null,
    ?string $targetRole = null,
    ?string $targetUser = null,
    ?string $actionUrl = null
): bool {
    $title = trim($title);
    $message = trim($message);
    $notificationType = strtoupper(
        trim($notificationType)
    );

    if (
        $propertyId < 1 ||
        $title === "" ||
        $message === ""
    ) {
        return false;
    }

    $stmt = $conn->prepare(
        "
        INSERT INTO cpms_notifications
        (
            property_id,
            notification_type,
            title,
            message,
            reference_no,
            target_role,
            target_user,
            action_url
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?
        )
        "
    );

    if (!$stmt) {
        error_log(
            "CPMS Notification prepare failed: " .
            $conn->error
        );

        return false;
    }

    $stmt->bind_param(
        "isssssss",
        $propertyId,
        $notificationType,
        $title,
        $message,
        $referenceNo,
        $targetRole,
        $targetUser,
        $actionUrl
    );

    $success =
        $stmt->execute();

    if (!$success) {
        error_log(
            "CPMS Notification execute failed: " .
            $stmt->error
        );
    }

    $stmt->close();

    return $success;
}

function cpmsNotifyAdmin(
    mysqli $conn,
    int $propertyId,
    string $title,
    string $message,
    string $notificationType = "INFO",
    ?string $referenceNo = null,
    ?string $actionUrl = null
): bool {
    return cpmsNotify(
        $conn,
        $propertyId,
        $title,
        $message,
        $notificationType,
        $referenceNo,
        "Administrator",
        null,
        $actionUrl
    );
}

function cpmsUnreadNotificationCount(
    mysqli $conn,
    int $propertyId,
    ?string $targetRole = null,
    ?string $targetUser = null
): int {
    $where = [
        "property_id = ?",
        "is_read = 0"
    ];

    $types = "i";
    $values = [$propertyId];

    if ($targetRole !== null && $targetRole !== "") {
        $where[] =
            "(target_role IS NULL OR target_role = ?)";

        $types .= "s";
        $values[] = $targetRole;
    }

    if ($targetUser !== null && $targetUser !== "") {
        $where[] =
            "(target_user IS NULL OR target_user = ?)";

        $types .= "s";
        $values[] = $targetUser;
    }

    $stmt = $conn->prepare(
        "
        SELECT COUNT(*) AS total
        FROM cpms_notifications
        WHERE " .
        implode(" AND ", $where)
    );

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param(
        $types,
        ...$values
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return (int) ($row["total"] ?? 0);
}

function cpmsMarkNotificationRead(
    mysqli $conn,
    int $notificationId,
    int $propertyId
): bool {
    if (
        $notificationId < 1 ||
        $propertyId < 1
    ) {
        return false;
    }

    $stmt = $conn->prepare(
        "
        UPDATE cpms_notifications
        SET
            is_read = 1,
            read_at = NOW()
        WHERE id = ?
          AND property_id = ?
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ii",
        $notificationId,
        $propertyId
    );

    $success =
        $stmt->execute();

    $stmt->close();

    return $success;
}

function cpmsMarkAllNotificationsRead(
    mysqli $conn,
    int $propertyId,
    ?string $targetRole = null,
    ?string $targetUser = null
): bool {
    $where = [
        "property_id = ?",
        "is_read = 0"
    ];

    $types = "i";
    $values = [$propertyId];

    if ($targetRole !== null && $targetRole !== "") {
        $where[] =
            "(target_role IS NULL OR target_role = ?)";

        $types .= "s";
        $values[] = $targetRole;
    }

    if ($targetUser !== null && $targetUser !== "") {
        $where[] =
            "(target_user IS NULL OR target_user = ?)";

        $types .= "s";
        $values[] = $targetUser;
    }

    $stmt = $conn->prepare(
        "
        UPDATE cpms_notifications
        SET
            is_read = 1,
            read_at = NOW()
        WHERE " .
        implode(" AND ", $where)
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        $types,
        ...$values
    );

    $success =
        $stmt->execute();

    $stmt->close();

    return $success;
}
