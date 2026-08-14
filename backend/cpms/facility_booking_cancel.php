<?php
declare(strict_types=1);

require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cpms/includes/resident_portal_service.php';
require_once __DIR__ . '/cpms/includes/facility_booking_service.php';
require_once __DIR__ . '/cpms/includes/resident_notification_service.php';
require_once __DIR__ . '/cpms/includes/facility_portal_helper.php';

$resident = cpmsResidentRequire($conn);
$propertyId = (int) $resident['property_id'];
$residentId = (int) $resident['id'];
$language = cpmsFacilityLanguage();
$bookingId = (int) ($_POST['booking_id'] ?? $_GET['id'] ?? 0);
$error = '';

function cpmsFacilityCancellationLoad(
    mysqli $conn,
    int $bookingId,
    int $propertyId,
    int $residentId,
    bool $lock = false
): ?array {
    $sql = "SELECT b.*,f.facility_name,f.location,
                   c.request_status AS cancellation_status
            FROM cpms_facility_bookings b
            INNER JOIN cpms_facilities f
               ON f.id=b.facility_id AND f.property_id=b.property_id
            LEFT JOIN cpms_facility_booking_cancellations c
               ON c.booking_id=b.id AND c.property_id=b.property_id
            WHERE b.id=? AND b.property_id=? AND b.resident_id=?
            LIMIT 1";
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Booking query failed.');
    }
    $stmt->bind_param('iii', $bookingId, $propertyId, $residentId);
    cpmsFacilityExecute($stmt, 'Booking query failed.');
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $booking ?: null;
}

$booking = cpmsFacilityCancellationLoad(
    $conn,
    $bookingId,
    $propertyId,
    $residentId
);
if (!$booking) {
    http_response_code(404);
    exit($language === 'en' ? 'Booking not found.' : 'Tempahan tidak dijumpai.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        cpmsFacilityVerifyCsrf();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 500) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'Enter a valid cancellation reason.'
                    : 'Masukkan alasan pembatalan yang sah.'
            );
        }

        $conn->begin_transaction();
        $transactionStarted = true;
        $booking = cpmsFacilityCancellationLoad(
            $conn,
            $bookingId,
            $propertyId,
            $residentId,
            true
        );
        if (!$booking) {
            throw new RuntimeException(
                $language === 'en' ? 'Booking not found.' : 'Tempahan tidak dijumpai.'
            );
        }
        $status = (string) $booking['booking_status'];
        $cancellationStatus = (string) ($booking['cancellation_status'] ?? '');
        if (!in_array($status, ['Pending', 'Approved'], true)) {
            throw new RuntimeException(cpmsFacilityText('not_eligible'));
        }
        if ($cancellationStatus === 'Pending') {
            throw new RuntimeException(
                $language === 'en'
                    ? 'A cancellation request is already pending.'
                    : 'Permintaan pembatalan sedang menunggu keputusan.'
            );
        }

        if ($status === 'Pending') {
            $stmt = $conn->prepare(
                "UPDATE cpms_facility_bookings
                 SET booking_status='Cancelled',review_notes=?
                 WHERE id=? AND property_id=? AND resident_id=?
                   AND booking_status='Pending'"
            );
            if (!$stmt) {
                throw new RuntimeException('Cancellation update failed.');
            }
            $stmt->bind_param(
                'siii',
                $reason,
                $bookingId,
                $propertyId,
                $residentId
            );
            cpmsFacilityExecute($stmt, 'Cancellation update failed.');
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Booking status changed. Refresh and try again.');
            }
            $stmt->close();
            cpmsFacilityBookingUpdate(
                $conn,
                $propertyId,
                $bookingId,
                'Pending',
                'Cancelled',
                $reason,
                (int) ($_SESSION['cpms_user_id'] ?? 0)
            );
            cpmsResidentNotify(
                $conn,
                $propertyId,
                $residentId,
                'facility',
                $language === 'en' ? 'Booking cancelled' : 'Tempahan dibatalkan',
                ($language === 'en' ? 'Booking ' : 'Tempahan ')
                    . $booking['booking_reference']
                    . ($language === 'en' ? ' was cancelled.' : ' telah dibatalkan.'),
                'facility_bookings.php'
            );
            $success = $language === 'en'
                ? 'The pending booking was cancelled.'
                : 'Tempahan yang menunggu telah dibatalkan.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO cpms_facility_booking_cancellations (
                    property_id,booking_id,resident_id,reason,request_status
                 ) VALUES (?,?,?,?,'Pending')
                 ON DUPLICATE KEY UPDATE
                    reason=VALUES(reason),request_status='Pending',
                    reviewed_by_system_user_id=NULL,reviewed_at=NULL,
                    review_notes=NULL"
            );
            if (!$stmt) {
                throw new RuntimeException('Cancellation request failed.');
            }
            $stmt->bind_param(
                'iiis',
                $propertyId,
                $bookingId,
                $residentId,
                $reason
            );
            cpmsFacilityExecute($stmt, 'Cancellation request failed.');
            $stmt->close();
            cpmsResidentNotify(
                $conn,
                $propertyId,
                $residentId,
                'facility',
                $language === 'en'
                    ? 'Cancellation submitted'
                    : 'Pembatalan dihantar',
                ($language === 'en' ? 'Cancellation for ' : 'Pembatalan ')
                    . $booking['booking_reference']
                    . ($language === 'en'
                        ? ' is awaiting management approval.'
                        : ' sedang menunggu kelulusan pengurusan.'),
                'facility_bookings.php'
            );
            $success = $language === 'en'
                ? 'Cancellation request submitted for management approval.'
                : 'Permintaan pembatalan dihantar untuk kelulusan pengurusan.';
        }

        $conn->commit();
        $transactionStarted = false;
        cpmsFacilityFlash('success', $success);
        header('Location: ' . cpmsFacilityUrl('facility_bookings.php'));
        exit;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        $error = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="<?php echo $language === 'en' ? 'en' : 'ms'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo cpmsFacilityEscape(cpmsFacilityText('cancel_title')); ?> | CPMS</title>
    <link rel="stylesheet" href="resident_portal.css?v=3580">
    <link rel="stylesheet" href="facility-resident.css?v=3580">
</head>
<body>
<header class="rp-head facility-hero">
    <div class="rp-wrap facility-hero-row">
        <div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('portal')); ?></small><h1><?php echo cpmsFacilityEscape(cpmsFacilityText('cancel_title')); ?></h1><p><?php echo cpmsFacilityEscape(cpmsFacilityText('cancel_intro')); ?></p></div>
        <div class="facility-language" aria-label="Language"><a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="facility_booking_cancel.php?id=<?php echo $bookingId; ?>&amp;lang=bm">BM</a><a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="facility_booking_cancel.php?id=<?php echo $bookingId; ?>&amp;lang=en">EN</a></div>
    </div>
</header>
<main class="rp-main rp-wrap facility-main facility-cancel-main">
    <?php if ($error !== ''): ?><div class="facility-alert facility-alert--danger" role="alert"><?php echo cpmsFacilityEscape($error); ?></div><?php endif; ?>
    <section class="rp-card facility-cancel-card">
        <div class="facility-booking-top"><div><span class="facility-reference"><?php echo cpmsFacilityEscape($booking['booking_reference']); ?></span><h2><?php echo cpmsFacilityEscape($booking['facility_name']); ?></h2><p><?php echo cpmsFacilityEscape($booking['location'] ?: '-'); ?></p></div><span class="facility-status status-<?php echo cpmsFacilityEscape(cpmsFacilityStatusClass((string) $booking['booking_status'])); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityStatusLabel((string) $booking['booking_status'])); ?></span></div>
        <div class="facility-booking-details"><div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('date')); ?></small><strong><?php echo cpmsFacilityEscape(date('d/m/Y', strtotime((string) $booking['booking_date']))); ?></strong></div><div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('slot')); ?></small><strong><?php echo cpmsFacilityEscape(substr((string) $booking['start_time'], 0, 5)); ?>–<?php echo cpmsFacilityEscape(substr((string) $booking['end_time'], 0, 5)); ?></strong></div></div>
        <?php if ((string) $booking['booking_status'] === 'Approved'): ?><div class="facility-inline-note"><?php echo cpmsFacilityEscape(cpmsFacilityText('approved_cancel_note')); ?></div><?php endif; ?>
        <?php if (in_array((string) $booking['booking_status'], ['Pending', 'Approved'], true) && (string) ($booking['cancellation_status'] ?? '') !== 'Pending'): ?>
            <form method="post" class="facility-cancel-form">
                <input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityEscape(cpmsFacilityCsrfToken()); ?>">
                <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                <label><?php echo cpmsFacilityEscape(cpmsFacilityText('reason')); ?><textarea class="rp-field" name="reason" rows="5" maxlength="500" placeholder="<?php echo cpmsFacilityEscape(cpmsFacilityText('reason_hint')); ?>" required><?php echo cpmsFacilityEscape($_POST['reason'] ?? ''); ?></textarea></label>
                <button class="rp-btn facility-danger-button" type="submit"><?php echo cpmsFacilityEscape(cpmsFacilityText('submit_cancel')); ?></button>
            </form>
        <?php else: ?><div class="facility-inline-note"><?php echo cpmsFacilityEscape((string) ($booking['cancellation_status'] ?? '') === 'Pending' ? cpmsFacilityText('cancellation_pending') : cpmsFacilityText('not_eligible')); ?></div><?php endif; ?>
        <a class="facility-back-link" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_bookings.php')); ?>">← <?php echo cpmsFacilityEscape(cpmsFacilityText('back')); ?></a>
    </section>
</main>
</body>
</html>
