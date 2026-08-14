<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once __DIR__ . '/cpms/includes/permission_engine.php';

$role = (string) ($_SESSION['cpms_user_role'] ?? '');
$sessionPropertyId = (int) ($_SESSION['cpms_property_id'] ?? 0);
$isOwner = $role === 'system_owner' || isset($_SESSION['system_owner_id']);
if (!$isOwner && !cpmsCan('attendance.payroll.export', $conn)) {
    http_response_code(403);
    exit('Akses export payroll diperlukan.');
}
$propertyId = $isOwner
    ? (int) ($_GET['property_id'] ?? 0) : $sessionPropertyId;
$month = (string) ($_GET['month'] ?? date('Y-m'));
$selectedUserId = (int) ($_GET['system_user_id'] ?? 0);
if ($propertyId < 1 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(422);
    exit('Parameter export tidak sah.');
}
$propertyCode = 'property-' . $propertyId;
$stmt = $conn->prepare(
    'SELECT property_code FROM cpms_properties WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($row) && trim((string) $row['property_code']) !== '') {
    $propertyCode = preg_replace(
        '/[^A-Za-z0-9_-]/', '-', (string) $row['property_code']
    );
}
$approvalStatus = 'Draft';
$stmt = $conn->prepare(
    'SELECT approval_status FROM cpms_attendance_monthly_approvals
     WHERE property_id = ? AND attendance_month = ? LIMIT 1'
);
$stmt->bind_param('is', $propertyId, $month);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (is_array($row)) {
    $approvalStatus = (string) $row['approval_status'];
}
$sql = "SELECT a.work_date, u.full_name, u.username, a.user_role,
               s.shift_name, a.scheduled_start_at, a.scheduled_end_at,
               a.clock_in_at, a.clock_out_at, a.late_minutes,
               a.early_departure_minutes, a.worked_minutes,
               a.overtime_minutes, a.status
        FROM cpms_attendance_sessions a
        JOIN system_users u ON u.id = a.system_user_id
        LEFT JOIN cpms_attendance_shifts s ON s.id = a.resolved_shift_id
        WHERE a.property_id = ? AND DATE_FORMAT(a.work_date, '%Y-%m') = ?";
if ($selectedUserId > 0) {
    $sql .= ' AND a.system_user_id = ?';
}
$sql .= ' ORDER BY a.work_date, u.full_name, a.clock_in_at';
$stmt = $conn->prepare($sql);
if ($selectedUserId > 0) {
    $stmt->bind_param('isi', $propertyId, $month, $selectedUserId);
} else {
    $stmt->bind_param('is', $propertyId, $month);
}
$stmt->execute();
$result = $stmt->get_result();
$filename = 'attendance-' . $propertyCode . '-' . $month . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'w');
fputcsv($output, [
    'Month Status', 'Date', 'Name', 'Username', 'Role', 'Shift',
    'Scheduled Start', 'Scheduled End', 'Clock In', 'Clock Out',
    'Late Minutes', 'Early Departure Minutes', 'Worked Minutes',
    'Worked Hours', 'Overtime Minutes', 'Overtime Hours',
    'Session Status',
]);
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $approvalStatus,
        $row['work_date'],
        $row['full_name'],
        $row['username'],
        $row['user_role'],
        $row['shift_name'] ?: '',
        $row['scheduled_start_at'] ?: '',
        $row['scheduled_end_at'] ?: '',
        $row['clock_in_at'],
        $row['clock_out_at'] ?: '',
        (int) $row['late_minutes'],
        (int) $row['early_departure_minutes'],
        (int) $row['worked_minutes'],
        number_format((int) $row['worked_minutes'] / 60, 2, '.', ''),
        (int) $row['overtime_minutes'],
        number_format((int) $row['overtime_minutes'] / 60, 2, '.', ''),
        $row['status'],
    ]);
}
fclose($output);
$stmt->close();
exit;
