<?php

declare(strict_types=1);

function cpmsRequireCurrentPropertyId(
    mysqli $conn
): int {
    if (!function_exists("cpmsCurrentPropertyId")) {
        throw new RuntimeException(
            "Property Context belum dimuatkan."
        );
    }

    $propertyId =
        cpmsCurrentPropertyId($conn);

    if ($propertyId < 1) {
        throw new RuntimeException(
            "Tiada property aktif dipilih."
        );
    }

    return $propertyId;
}

function cpmsComplaintBelongsToProperty(
    mysqli $conn,
    string $complaintId,
    int $propertyId
): bool {
    $stmt = $conn->prepare(
        "
        SELECT complaint_id
        FROM complaints
        WHERE complaint_id = ?
          AND property_id = ?
        LIMIT 1
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "si",
        $complaintId,
        $propertyId
    );

    $stmt->execute();

    $exists =
        $stmt
            ->get_result()
            ->num_rows > 0;

    $stmt->close();

    return $exists;
}
