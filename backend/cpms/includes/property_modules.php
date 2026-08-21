<?php
declare(strict_types=1);

function cpmsModuleDefaults(): array
{
    return [
        'complaints' => true,
        'work_orders' => true,
        'residents' => true,
        'staff' => false,
        'assets' => false,
        'reports' => false,
        'facility_booking' => false,
        'preventive_maintenance' => false,
        'visitor_management' => false,
        'staff_work_orders' => true,
        'staff_daily_work' => true,
        'staff_inspection_actions' => true,
        'staff_notifications' => true,
        'staff_task_inbox' => true,
        'staff_attendance' => true,
        'staff_maintenance' => true,
        'security_patrol' => true,
        'security_visitors' => true,
        'security_task_inbox' => true,
        'security_attendance' => true,
    ];
}

function cpmsLoadPropertyModules(
    mysqli $conn,
    int $propertyId
): array {
    $modules = cpmsModuleDefaults();

    $tableCheck = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'cpms_property_modules'"
    );

    if (!$tableCheck) {
        return $modules;
    }

    $tableCheck->execute();
    $exists = (int) (
        $tableCheck->get_result()->fetch_assoc()['total']
        ?? 0
    ) > 0;
    $tableCheck->close();

    if (!$exists) {
        return $modules;
    }

    $stmt = $conn->prepare(
        "SELECT module_key, is_enabled
         FROM cpms_property_modules
         WHERE property_id = ?"
    );

    if (!$stmt) {
        return $modules;
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $modules[(string) $row['module_key']] =
            (bool) $row['is_enabled'];
    }

    $stmt->close();

    return $modules;
}

function cpmsModuleEnabled(
    string $moduleKey,
    ?array $modules = null
): bool {
    $modules = $modules
        ?? ($GLOBALS['currentPropertyModules'] ?? []);

    return (bool) ($modules[$moduleKey] ?? false);
}
