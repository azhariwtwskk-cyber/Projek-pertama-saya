<?php
declare(strict_types=1);

function cpmsV2ModuleRegistry(): array
{
    return [
        'complaints' => ['label' => 'Complaint Management', 'permission' => 'complaints.manage'],
        'work_orders' => ['label' => 'Work Orders', 'permission' => 'work_orders.manage'],
        'assets' => ['label' => 'Asset Management', 'permission' => 'assets.manage'],
        'inspection' => ['label' => 'Inspection & Compliance', 'permission' => 'inspections.manage'],
        'preventive_maintenance' => ['label' => 'Preventive Maintenance', 'permission' => 'work_orders.manage'],
        'attendance' => ['label' => 'Attendance', 'permission' => 'attendance.manage'],
        'security' => ['label' => 'Security Patrol', 'permission' => 'patrols.create'],
        'visitor' => ['label' => 'Visitor Management', 'permission' => 'visitors.manage'],
        'facility_booking' => ['label' => 'Facility Booking', 'permission' => 'facilities.book'],
        'reports' => ['label' => 'Management Reports', 'permission' => 'reports.view'],
    ];
}

function cpmsV2PropertyModules(mysqli $conn, int $propertyId): array
{
    if ($propertyId < 1 || !cpmsV2TableExists($conn, 'cpms_property_modules')) {
        return array_keys(cpmsV2ModuleRegistry());
    }

    $columns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM cpms_property_modules');
    if ($columnResult instanceof mysqli_result) {
        while ($row = $columnResult->fetch_assoc()) {
            $columns[] = (string) $row['Field'];
        }
    }

    $moduleColumn = in_array('module_key', $columns, true) ? 'module_key' : (in_array('module_code', $columns, true) ? 'module_code' : '');
    $activeColumn = in_array('is_enabled', $columns, true) ? 'is_enabled' : (in_array('is_active', $columns, true) ? 'is_active' : '');
    if ($moduleColumn === '') {
        return array_keys(cpmsV2ModuleRegistry());
    }

    $sql = 'SELECT `' . $moduleColumn . '` AS module_key FROM cpms_property_modules WHERE property_id = ?';
    if ($activeColumn !== '') {
        $sql .= ' AND `' . $activeColumn . '` = 1';
    }

    $statement = $conn->prepare($sql);
    if (!$statement) {
        return array_keys(cpmsV2ModuleRegistry());
    }
    $statement->bind_param('i', $propertyId);
    $statement->execute();
    $result = $statement->get_result();
    $modules = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $modules[] = (string) $row['module_key'];
        }
    }
    $statement->close();

    return $modules ?: array_keys(cpmsV2ModuleRegistry());
}
