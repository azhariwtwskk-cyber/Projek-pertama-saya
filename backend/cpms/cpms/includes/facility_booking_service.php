<?php
declare(strict_types=1);

function cpmsFacilityBookingReference(mysqli $conn, int $propertyId): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $reference = sprintf(
            'FB-%d-%s-%04d',
            $propertyId,
            date('ymd'),
            random_int(1, 9999)
        );
        $stmt = $conn->prepare(
            'SELECT 1 FROM cpms_facility_bookings
             WHERE booking_reference=? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Booking reference query failed.');
        }
        $stmt->bind_param('s', $reference);
        if (!$stmt->execute()) {
            throw new RuntimeException('Booking reference query failed.');
        }
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$exists) {
            return $reference;
        }
    }
    throw new RuntimeException('Booking reference could not be generated.');
}

function cpmsFacilitySlotAvailable(
    mysqli $conn,
    int $propertyId,
    int $facilityId,
    string $date,
    string $start,
    string $end,
    int $excludeId = 0
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM cpms_facility_bookings
         WHERE property_id=? AND facility_id=? AND booking_date=?
           AND booking_status IN ('Pending','Approved')
           AND start_time < ? AND end_time > ?
           AND id<>?"
    );
    if (!$stmt) {
        throw new RuntimeException('Facility slot query failed.');
    }
    $stmt->bind_param(
        'iisssi',
        $propertyId,
        $facilityId,
        $date,
        $end,
        $start,
        $excludeId
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Facility slot query failed.');
    }
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total === 0;
}

function cpmsFacilityBookingUpdate(
    mysqli $conn,
    int $propertyId,
    int $bookingId,
    ?string $oldStatus,
    string $newStatus,
    string $message,
    int $actorId
): void {
    $stmt = $conn->prepare(
        'INSERT INTO cpms_facility_booking_updates
         (property_id,booking_id,old_status,new_status,message,
          created_by_system_user_id)
         VALUES (?,?,?,?,?,NULLIF(?,0))'
    );
    if (!$stmt) {
        throw new RuntimeException('Booking history insert failed.');
    }
    $stmt->bind_param(
        'iisssi',
        $propertyId,
        $bookingId,
        $oldStatus,
        $newStatus,
        $message,
        $actorId
    );
    if (!$stmt->execute()) {
        throw new RuntimeException('Booking history insert failed.');
    }
    $stmt->close();
}
