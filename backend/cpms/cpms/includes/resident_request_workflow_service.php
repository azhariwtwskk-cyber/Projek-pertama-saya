<?php
declare(strict_types=1);

function cpmsResidentWorkflowReference(
    mysqli $conn,
    string $table,
    string $column,
    string $prefix
): string {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $reference = $prefix . '-' . date('Ymd') . '-'
            . strtoupper(bin2hex(random_bytes(3)));
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = str_replace('`', '``', $column);
        $stmt = $conn->prepare(
            "SELECT 1 FROM `$safeTable` WHERE `$safeColumn`=? LIMIT 1"
        );
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            return $reference;
        }
    }
    throw new RuntimeException('Unique workflow reference could not be generated.');
}

function cpmsResidentWorkflowUpdate(
    mysqli $conn,
    int $propertyId,
    int $requestId,
    string $type,
    ?string $oldStatus,
    ?string $newStatus,
    string $message,
    int $actorId
): void {
    $stmt = $conn->prepare(
        'INSERT INTO cpms_resident_request_updates
         (property_id,service_request_id,update_type,old_status,new_status,
          message,visible_to_resident,created_by_system_user_id)
         VALUES (?,?,?,?,?,?,1,NULLIF(?,0))'
    );
    $stmt->bind_param(
        'iissssi',
        $propertyId,
        $requestId,
        $type,
        $oldStatus,
        $newStatus,
        $message,
        $actorId
    );
    $stmt->execute();
    $stmt->close();
}
