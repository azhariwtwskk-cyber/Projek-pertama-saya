<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
cpmsRequire('visitor.export', $conn);
if (!cpmsModuleEnabled('visitor_management', $currentPropertyModules)) {
    http_response_code(403);
    exit('Visitor Management is not enabled for this property.');
}

function cpmsVisitorCsvCell($value): string
{
    $cell = (string) $value;
    $trimmed = ltrim($cell);
    if ($trimmed !== '' && in_array(substr($trimmed, 0, 1), ['=', '+', '-', '@'], true)) {
        return "'" . $cell;
    }
    return $cell;
}

$selectedDate = trim((string) ($_GET['date'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) !== 1) {
    http_response_code(400);
    exit('Invalid export date.');
}
$selectedStatus = trim((string) ($_GET['status'] ?? 'All'));
$allowedStatuses = [
    'All',
    'Expected',
    'Checked In',
    'Checked Out',
    'Cancelled',
    'Denied',
];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    http_response_code(400);
    exit('Invalid export status.');
}

$sql = "SELECT v.pass_code,v.visitor_name,v.visitor_phone,v.vehicle_no,
               v.host_block,v.host_unit,r.full_name AS resident_name,
               v.visit_date,v.expected_start_time,v.expected_end_time,
               v.visit_purpose,v.registration_source,v.visitor_status,
               v.checkin_at,v.checkout_at,v.security_notes,
               g1.full_name AS checkin_guard,g2.full_name AS checkout_guard,
               (SELECT e.event_notes
                FROM cpms_visitor_events e
                WHERE e.property_id=v.property_id
                  AND e.pass_id=v.id
                  AND e.event_type='Entry Denied'
                ORDER BY e.event_at DESC,e.id DESC LIMIT 1
               ) AS denial_reason
        FROM cpms_visitor_passes v
        INNER JOIN cpms_residents r
           ON r.id=v.resident_id AND r.property_id=v.property_id
        LEFT JOIN security_guards g1
           ON g1.id=v.checked_in_by_guard_id AND g1.property_id=v.property_id
        LEFT JOIN security_guards g2
           ON g2.id=v.checked_out_by_guard_id AND g2.property_id=v.property_id
        WHERE v.property_id=? AND v.visit_date=?";
if ($selectedStatus !== 'All') {
    $sql .= ' AND v.visitor_status=?';
}
$sql .= ' ORDER BY v.expected_start_time,v.pass_code';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Visitor export query failed.');
}
if ($selectedStatus !== 'All') {
    $stmt->bind_param(
        'iss',
        $currentPropertyId,
        $selectedDate,
        $selectedStatus
    );
} else {
    $stmt->bind_param('is', $currentPropertyId, $selectedDate);
}
if (!$stmt->execute()) {
    http_response_code(500);
    exit('Visitor export query failed.');
}
$result = $stmt->get_result();

$safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', $currentPropertyCode);
if (!is_string($safeCode) || $safeCode === '') {
    $safeCode = 'property';
}
$filename = 'visitor-register-' . $safeCode . '-' . $selectedDate . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
$output = fopen('php://output', 'wb');
if ($output === false) {
    $stmt->close();
    exit;
}
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'Kod Pas',
    'Nama Pelawat',
    'Telefon',
    'No. Kenderaan',
    'Blok Host',
    'Unit Host',
    'Resident Host',
    'Tarikh Lawatan',
    'Jangkaan Tiba',
    'Jangkaan Keluar',
    'Tujuan',
    'Sumber',
    'Status',
    'Check-In',
    'Security Check-In',
    'Check-Out',
    'Security Check-Out',
    'Alasan Penolakan',
    'Catatan Security',
]);
while ($row = $result->fetch_assoc()) {
    fputcsv($output, array_map('cpmsVisitorCsvCell', [
        $row['pass_code'],
        $row['visitor_name'],
        $row['visitor_phone'],
        $row['vehicle_no'],
        $row['host_block'],
        $row['host_unit'],
        $row['resident_name'],
        $row['visit_date'],
        $row['expected_start_time'],
        $row['expected_end_time'],
        $row['visit_purpose'],
        $row['registration_source'],
        $row['visitor_status'],
        $row['checkin_at'],
        $row['checkin_guard'],
        $row['checkout_at'],
        $row['checkout_guard'],
        $row['denial_reason'],
        $row['security_notes'],
    ]));
}
fclose($output);
$stmt->close();
exit;
