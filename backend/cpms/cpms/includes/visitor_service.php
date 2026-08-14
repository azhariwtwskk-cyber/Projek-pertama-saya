<?php
declare(strict_types=1);

/* CPMS v3.6.0 — shared property-scoped visitor workflow service. */

function cpmsVisitorReference(mysqli $conn, int $propertyId): string
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $reference = sprintf(
            'VP-%d-%s-%04d',
            $propertyId,
            date('ymd'),
            random_int(1, 9999)
        );
        $stmt = $conn->prepare(
            'SELECT 1 FROM cpms_visitor_passes WHERE pass_code=? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Visitor reference query failed.');
        }
        $stmt->bind_param('s', $reference);
        if (!$stmt->execute()) {
            throw new RuntimeException('Visitor reference query failed.');
        }
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            return $reference;
        }
    }
    throw new RuntimeException('Visitor reference could not be generated.');
}

function cpmsVisitorToken(): string
{
    return bin2hex(random_bytes(32));
}

function cpmsVisitorEvent(
    mysqli $conn,
    int $propertyId,
    int $passId,
    string $eventType,
    int $actorUserId,
    int $guardId,
    string $notes = ''
): void {
    $stmt = $conn->prepare(
        "INSERT INTO cpms_visitor_events (
            property_id,pass_id,event_type,actor_system_user_id,
            guard_id,event_notes
         ) VALUES (?,?,?,NULLIF(?,0),NULLIF(?,0),NULLIF(?,''))"
    );
    if (!$stmt) {
        throw new RuntimeException('Visitor event insert failed.');
    }
    $stmt->bind_param(
        'iisiis',
        $propertyId,
        $passId,
        $eventType,
        $actorUserId,
        $guardId,
        $notes
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Visitor event insert failed.');
    }
    $stmt->close();
}

function cpmsVisitorStatusClass(string $status): string
{
    $allowed = [
        'Expected',
        'Checked In',
        'Checked Out',
        'Cancelled',
        'Denied',
        'Expired',
    ];
    if (!in_array($status, $allowed, true)) {
        return 'neutral';
    }
    return str_replace(' ', '-', strtolower($status));
}

function cpmsVisitorNormalizePhone(string $value): string
{
    $normalized = preg_replace('/[^0-9]/', '', $value);
    return is_string($normalized) ? $normalized : '';
}

function cpmsVisitorNormalizeVehicle(string $value): string
{
    $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    return is_string($normalized) ? $normalized : '';
}

function cpmsVisitorWatchlistRows(mysqli $conn, int $propertyId): array
{
    $stmt = $conn->prepare(
        "SELECT id,person_name,visitor_phone,vehicle_no,reason,risk_level
         FROM cpms_visitor_watchlist
         WHERE property_id=? AND active=1
         ORDER BY FIELD(risk_level,'High','Medium','Low'),created_at DESC
         LIMIT 1000"
    );
    if (!$stmt) {
        throw new RuntimeException('Visitor watchlist query failed.');
    }
    $stmt->bind_param('i', $propertyId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Visitor watchlist query failed.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cpmsVisitorWatchlistMatch(
    array $rows,
    string $name,
    string $phone,
    string $vehicle
): ?array {
    $nameKey = strtolower(trim($name));
    $phoneKey = cpmsVisitorNormalizePhone($phone);
    $vehicleKey = cpmsVisitorNormalizeVehicle($vehicle);

    foreach ($rows as $row) {
        $rowVehicle = cpmsVisitorNormalizeVehicle(
            (string) ($row['vehicle_no'] ?? '')
        );
        if ($vehicleKey !== '' && $rowVehicle !== '' && $vehicleKey === $rowVehicle) {
            $row['matched_by'] = 'vehicle';
            return $row;
        }
        $rowPhone = cpmsVisitorNormalizePhone(
            (string) ($row['visitor_phone'] ?? '')
        );
        if ($phoneKey !== '' && $rowPhone !== '' && $phoneKey === $rowPhone) {
            $row['matched_by'] = 'phone';
            return $row;
        }
        $rowName = strtolower(trim((string) ($row['person_name'] ?? '')));
        if ($nameKey !== '' && $rowName !== '' && $nameKey === $rowName) {
            $row['matched_by'] = 'name';
            return $row;
        }
    }
    return null;
}
