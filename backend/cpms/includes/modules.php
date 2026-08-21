<?php

declare(strict_types=1);

function cpmsLoadModules(mysqli $conn): array
{
    $modules = [];

    $result = $conn->query(
        "
        SELECT
            module_key,
            module_name_ms,
            module_name_en,
            description_ms,
            description_en,
            module_group,
            icon,
            route,
            is_enabled,
            sort_order
        FROM cpms_modules
        ORDER BY
            module_group ASC,
            sort_order ASC,
            module_name_en ASC
        "
    );

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $key = (string) $row["module_key"];

        $modules[$key] = [
            "key" => $key,
            "name_ms" => (string) $row["module_name_ms"],
            "name_en" => (string) $row["module_name_en"],
            "description_ms" => (string) ($row["description_ms"] ?? ""),
            "description_en" => (string) ($row["description_en"] ?? ""),
            "group" => (string) $row["module_group"],
            "icon" => (string) ($row["icon"] ?? "📦"),
            "route" => (string) ($row["route"] ?? ""),
            "enabled" => (int) $row["is_enabled"] === 1,
            "sort_order" => (int) $row["sort_order"]
        ];
    }

    return $modules;
}

function cpmsModuleEnabled(
    mysqli $conn,
    string $moduleKey
): bool {
    static $cache = [];

    if (array_key_exists($moduleKey, $cache)) {
        return $cache[$moduleKey];
    }

    $stmt = $conn->prepare(
        "
        SELECT is_enabled
        FROM cpms_modules
        WHERE module_key = ?
        LIMIT 1
        "
    );

    if (!$stmt) {
        $cache[$moduleKey] = false;
        return false;
    }

    $stmt->bind_param("s", $moduleKey);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cache[$moduleKey] =
        (int) ($row["is_enabled"] ?? 0) === 1;

    return $cache[$moduleKey];
}

function cpmsEnabledModules(mysqli $conn): array
{
    return array_filter(
        cpmsLoadModules($conn),
        static fn(array $module): bool =>
            $module["enabled"] === true
    );
}
