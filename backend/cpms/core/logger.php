<?php
declare(strict_types=1);

function cpmsLog(
    mysqli $conn,
    string $action,
    ?string $module = null,
    string|int|null $referenceId = null,
    ?string $description = null,
    ?array $metadata = null
): void {
    $propertyId = cpmsCurrentPropertyId();
    $userId = cpmsCurrentUserId();
    $userRole = cpmsCurrentUserRole();
    $userName = cpmsCurrentUserName();
    $reference = $referenceId === null
        ? null
        : (string)$referenceId;
    $metadataJson = $metadata
        ? json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        )
        : null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO cpms_activity_logs (
            property_id,
            user_id,
            user_role,
            user_name,
            action,
            module,
            reference_id,
            description,
            metadata_json,
            ip_address,
            user_agent,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iisssssssss',
        $propertyId,
        $userId,
        $userRole,
        $userName,
        $action,
        $module,
        $reference,
        $description,
        $metadataJson,
        $ipAddress,
        $userAgent
    );

    $stmt->execute();
    $stmt->close();
}
