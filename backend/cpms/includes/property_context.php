<?php

declare(strict_types=1);

function cpmsLoadProperties(
    mysqli $conn,
    bool $activeOnly = false
): array {
    $sql = "
        SELECT
            id,
            property_code,
            property_name,
            company_name,
            address,
            phone,
            email,
            website,
            logo_path,
            primary_color,
            secondary_color,
            default_language,
            currency_code,
            timezone_name,
            is_active,
            created_at,
            updated_at
        FROM cpms_properties
    ";

    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }

    $sql .= " ORDER BY property_name ASC";

    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    $properties = [];

    while ($row = $result->fetch_assoc()) {
        $propertyId = (int) $row["id"];

        $properties[$propertyId] = [
            "id" => $propertyId,
            "code" => (string) $row["property_code"],
            "name" => (string) $row["property_name"],
            "company_name" => (string) ($row["company_name"] ?? ""),
            "address" => (string) ($row["address"] ?? ""),
            "phone" => (string) ($row["phone"] ?? ""),
            "email" => (string) ($row["email"] ?? ""),
            "website" => (string) ($row["website"] ?? ""),
            "logo_path" => (string) ($row["logo_path"] ?? "images/logo.png"),
            "primary_color" => (string) ($row["primary_color"] ?? "#3a2419"),
            "secondary_color" => (string) ($row["secondary_color"] ?? "#b59b20"),
            "default_language" => (string) ($row["default_language"] ?? "ms"),
            "currency_code" => (string) ($row["currency_code"] ?? "MYR"),
            "timezone_name" => (string) ($row["timezone_name"] ?? "Asia/Kuala_Lumpur"),
            "active" => (int) $row["is_active"] === 1,
            "created_at" => (string) ($row["created_at"] ?? ""),
            "updated_at" => (string) ($row["updated_at"] ?? "")
        ];
    }

    return $properties;
}

function cpmsCurrentPropertyId(mysqli $conn): int
{
    $requestedPropertyId =
        (int) ($_SESSION["cpms_current_property_id"] ?? 0);

    if ($requestedPropertyId > 0) {
        $stmt = $conn->prepare(
            "
            SELECT id
            FROM cpms_properties
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
            "
        );

        if ($stmt) {
            $stmt->bind_param("i", $requestedPropertyId);
            $stmt->execute();

            $row =
                $stmt
                    ->get_result()
                    ->fetch_assoc();

            $stmt->close();

            if ($row) {
                return (int) $row["id"];
            }
        }
    }

    $result = $conn->query(
        "
        SELECT id
        FROM cpms_properties
        WHERE is_active = 1
        ORDER BY id ASC
        LIMIT 1
        "
    );

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    $propertyId = (int) ($row["id"] ?? 0);

    if ($propertyId > 0) {
        $_SESSION["cpms_current_property_id"] =
            $propertyId;
    }

    return $propertyId;
}

function cpmsCurrentProperty(mysqli $conn): ?array
{
    $propertyId =
        cpmsCurrentPropertyId($conn);

    if ($propertyId < 1) {
        return null;
    }

    $stmt = $conn->prepare(
        "
        SELECT
            id,
            property_code,
            property_name,
            company_name,
            address,
            phone,
            email,
            website,
            logo_path,
            primary_color,
            secondary_color,
            default_language,
            currency_code,
            timezone_name,
            is_active
        FROM cpms_properties
        WHERE id = ?
        LIMIT 1
        "
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $propertyId);
    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        "id" => (int) $row["id"],
        "code" => (string) $row["property_code"],
        "name" => (string) $row["property_name"],
        "company_name" => (string) ($row["company_name"] ?? ""),
        "address" => (string) ($row["address"] ?? ""),
        "phone" => (string) ($row["phone"] ?? ""),
        "email" => (string) ($row["email"] ?? ""),
        "website" => (string) ($row["website"] ?? ""),
        "logo_path" => (string) ($row["logo_path"] ?? "images/logo.png"),
        "primary_color" => (string) ($row["primary_color"] ?? "#3a2419"),
        "secondary_color" => (string) ($row["secondary_color"] ?? "#b59b20"),
        "default_language" => (string) ($row["default_language"] ?? "ms"),
        "currency_code" => (string) ($row["currency_code"] ?? "MYR"),
        "timezone_name" => (string) ($row["timezone_name"] ?? "Asia/Kuala_Lumpur"),
        "active" => (int) $row["is_active"] === 1
    ];
}

function cpmsSetCurrentProperty(
    mysqli $conn,
    int $propertyId
): bool {
    if ($propertyId < 1) {
        return false;
    }

    $stmt = $conn->prepare(
        "
        SELECT id
        FROM cpms_properties
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
        "
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $propertyId);
    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$row) {
        return false;
    }

    $_SESSION["cpms_current_property_id"] =
        (int) $row["id"];

    return true;
}
