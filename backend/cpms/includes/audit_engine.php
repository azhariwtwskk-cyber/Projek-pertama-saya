<?php

declare(strict_types=1);

function cpmsAuditClientIp(): string
{
    $candidates = [
        $_SERVER["HTTP_CF_CONNECTING_IP"] ?? "",
        $_SERVER["HTTP_X_FORWARDED_FOR"] ?? "",
        $_SERVER["REMOTE_ADDR"] ?? ""
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);

        if ($candidate === "") {
            continue;
        }

        if (str_contains($candidate, ",")) {
            $parts = explode(",", $candidate);
            $candidate = trim((string) $parts[0]);
        }

        if (
            filter_var(
                $candidate,
                FILTER_VALIDATE_IP
            )
        ) {
            return $candidate;
        }
    }

    return "";
}

function cpmsAuditEncodeValues(
    array|string|null $values
): ?string {
    if ($values === null) {
        return null;
    }

    if (is_string($values)) {
        return $values;
    }

    $json = json_encode(
        $values,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return $json === false
        ? null
        : $json;
}

function cpmsAudit(
    mysqli $conn,
    ?int $propertyId,
    string $moduleName,
    string $actionName,
    string|int|null $recordId = null,
    ?string $referenceNo = null,
    ?string $userName = null,
    ?string $userRole = null,
    ?string $remarks = null,
    array|string|null $oldValues = null,
    array|string|null $newValues = null
): bool {
    $moduleName = trim($moduleName);
    $actionName = strtoupper(
        trim($actionName)
    );

    if (
        $moduleName === "" ||
        $actionName === ""
    ) {
        return false;
    }

    $recordIdString =
        $recordId === null
            ? null
            : trim((string) $recordId);

    $referenceNo =
        $referenceNo !== null
            ? trim($referenceNo)
            : null;

    $userName =
        $userName !== null
            ? trim($userName)
            : null;

    $userRole =
        $userRole !== null
            ? trim($userRole)
            : null;

    $remarks =
        $remarks !== null
            ? trim($remarks)
            : null;

    $ipAddress =
        cpmsAuditClientIp();

    $userAgent =
        substr(
            trim(
                (string) (
                    $_SERVER["HTTP_USER_AGENT"] ??
                    ""
                )
            ),
            0,
            255
        );

    $oldValuesJson =
        cpmsAuditEncodeValues($oldValues);

    $newValuesJson =
        cpmsAuditEncodeValues($newValues);

    $stmt = $conn->prepare(
        "
        INSERT INTO cpms_audit_logs
        (
            property_id,
            module_name,
            action_name,
            record_id,
            reference_no,
            user_name,
            user_role,
            ip_address,
            user_agent,
            remarks,
            old_values,
            new_values
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        "
    );

    if (!$stmt) {
        error_log(
            "CPMS Audit prepare failed: " .
            $conn->error
        );

        return false;
    }

    $stmt->bind_param(
        "isssssssssss",
        $propertyId,
        $moduleName,
        $actionName,
        $recordIdString,
        $referenceNo,
        $userName,
        $userRole,
        $ipAddress,
        $userAgent,
        $remarks,
        $oldValuesJson,
        $newValuesJson
    );

    $success =
        $stmt->execute();

    if (!$success) {
        error_log(
            "CPMS Audit execute failed: " .
            $stmt->error
        );
    }

    $stmt->close();

    return $success;
}

function cpmsAuditAdmin(
    mysqli $conn,
    ?int $propertyId,
    string $moduleName,
    string $actionName,
    string|int|null $recordId = null,
    ?string $referenceNo = null,
    ?string $remarks = null,
    array|string|null $oldValues = null,
    array|string|null $newValues = null
): bool {
    $userName =
        (string) (
            $_SESSION["admin"] ??
            "Admin"
        );

    return cpmsAudit(
        $conn,
        $propertyId,
        $moduleName,
        $actionName,
        $recordId,
        $referenceNo,
        $userName,
        "Administrator",
        $remarks,
        $oldValues,
        $newValues
    );
}
