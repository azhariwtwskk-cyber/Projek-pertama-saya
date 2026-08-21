<?php
declare(strict_types=1);

function cpmsResidentNotify(
    mysqli $conn,
    int $propertyId,
    int $residentId,
    string $type,
    string $title,
    string $message,
    ?string $actionUrl = null
): void {
    $stmt = $conn->prepare(
        'INSERT INTO cpms_resident_notifications
         (property_id,resident_id,notification_type,title,message,action_url)
         VALUES (?,?,?,?,?,?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Resident notification insert failed.');
    }
    $stmt->bind_param(
        'iissss',
        $propertyId,
        $residentId,
        $type,
        $title,
        $message,
        $actionUrl
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Resident notification insert failed.');
    }
    $stmt->close();
}
