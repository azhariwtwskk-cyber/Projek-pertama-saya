<?php
declare(strict_types=1);

function cpmsAttendanceEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsAttendanceCsrfToken(): string
{
    if (empty($_SESSION['cpms_attendance_csrf'])
        || !is_string($_SESSION['cpms_attendance_csrf'])) {
        $_SESSION['cpms_attendance_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cpms_attendance_csrf'];
}

function cpmsAttendanceVerifyCsrf(?string $token): bool
{
    $stored = $_SESSION['cpms_attendance_csrf'] ?? null;
    return is_string($stored) && is_string($token)
        && hash_equals($stored, $token);
}

function cpmsAttendanceGeofence(mysqli $db, int $propertyId): ?array
{
    $stmt = $db->prepare(
        'SELECT property_id, latitude, longitude, radius_m,
                maximum_accuracy_m, enforcement_enabled
         FROM cpms_property_geofences WHERE property_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function cpmsAttendanceDistance(
    float $latitude1,
    float $longitude1,
    float $latitude2,
    float $longitude2
): float {
    $earthRadius = 6371000.0;
    $latDelta = deg2rad($latitude2 - $latitude1);
    $lngDelta = deg2rad($longitude2 - $longitude1);
    $value = sin($latDelta / 2) * sin($latDelta / 2)
        + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2))
        * sin($lngDelta / 2) * sin($lngDelta / 2);
    return $earthRadius * 2 * atan2(sqrt($value), sqrt(1 - $value));
}

function cpmsAttendanceValidCoordinates(
    float $latitude,
    float $longitude,
    float $accuracy
): bool {
    return $latitude >= -90 && $latitude <= 90
        && $longitude >= -180 && $longitude <= 180
        && $accuracy > 0 && $accuracy <= 5000;
}

function cpmsAttendanceClientIp(): string
{
    $candidate = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '';
}

function cpmsAttendanceAudit(
    mysqli $db,
    int $propertyId,
    int $userId,
    string $action,
    bool $accepted,
    string $reason,
    ?float $latitude,
    ?float $longitude,
    ?float $accuracy,
    ?float $distance
): void {
    $ip = cpmsAttendanceClientIp();
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $acceptedValue = $accepted ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO cpms_attendance_events
         (property_id, system_user_id, action_type, accepted, reason_code,
          latitude, longitude, accuracy_m, distance_m, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param(
        'iisisddddss',
        $propertyId, $userId, $action, $acceptedValue, $reason,
        $latitude, $longitude, $accuracy, $distance, $ip, $agent
    );
    $stmt->execute();
    $stmt->close();
}
