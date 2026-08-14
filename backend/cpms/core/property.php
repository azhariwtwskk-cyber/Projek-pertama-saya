<?php
declare(strict_types=1);

function cpmsCurrentPropertyId(): int
{
    return (int)(
        $_SESSION['property_id']
        ?? $_SESSION['current_property_id']
        ?? 0
    );
}

function cpmsRequireProperty(): void
{
    if (cpmsCurrentPropertyId() > 0) {
        return;
    }

    http_response_code(400);
    exit('Property belum dipilih.');
}

function cpmsProperty(mysqli $conn, ?int $propertyId = null): ?array
{
    $propertyId ??= cpmsCurrentPropertyId();

    if ($propertyId < 1) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT * FROM cpms_properties WHERE id = ? LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();

    $result = $stmt->get_result();
    $property = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return $property ?: null;
}
