<?php
declare(strict_types=1);

require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cpms/includes/resident_portal_service.php';
require_once __DIR__ . '/cpms/includes/facility_portal_helper.php';

$resident = cpmsResidentRequire($conn);
$propertyId = (int) $resident['property_id'];
$residentId = (int) $resident['id'];
$language = cpmsFacilityLanguage();

$stmt = $conn->prepare(
    "SELECT b.*,f.facility_name,f.location,
            c.request_status AS cancellation_status
     FROM cpms_facility_bookings b
     INNER JOIN cpms_facilities f
        ON f.id=b.facility_id AND f.property_id=b.property_id
     LEFT JOIN cpms_facility_booking_cancellations c
        ON c.booking_id=b.id AND c.property_id=b.property_id
     WHERE b.property_id=? AND b.resident_id=?
     ORDER BY (b.booking_date>=CURDATE()) DESC,
              b.booking_date DESC,b.start_time DESC"
);
if (!$stmt) {
    throw new RuntimeException('Booking query failed.');
}
$stmt->bind_param('ii', $propertyId, $residentId);
cpmsFacilityExecute($stmt, 'Booking query failed.');
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$flash = cpmsFacilityPullFlash();
?>
<!doctype html>
<html lang="<?php echo $language === 'en' ? 'en' : 'ms'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo cpmsFacilityEscape(cpmsFacilityText('my_title')); ?> | CPMS</title>
    <link rel="stylesheet" href="resident_portal.css?v=3580">
    <link rel="stylesheet" href="facility-resident.css?v=3580">
</head>
<body>
<header class="rp-head facility-hero">
    <div class="rp-wrap facility-hero-row">
        <div>
            <small><?php echo cpmsFacilityEscape(cpmsFacilityText('portal')); ?></small>
            <h1><?php echo cpmsFacilityEscape(cpmsFacilityText('my_title')); ?></h1>
            <p><?php echo cpmsFacilityEscape(cpmsFacilityText('my_intro')); ?></p>
        </div>
        <div class="facility-language" aria-label="Language">
            <a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="facility_bookings.php?lang=bm">BM</a>
            <a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="facility_bookings.php?lang=en">EN</a>
        </div>
    </div>
</header>
<nav class="rp-nav facility-nav">
    <a href="resident_dashboard.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('dashboard')); ?></a>
    <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_booking.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('book')); ?></a>
    <a class="active" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_bookings.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('my_bookings')); ?></a>
    <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('calendar')); ?></a>
    <a href="resident_notifications.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('notifications')); ?></a>
</nav>
<main class="rp-main rp-wrap facility-main">
    <?php if ($flash): ?>
        <div class="facility-alert facility-alert--<?php echo cpmsFacilityEscape((string) ($flash['type'] ?? 'success')); ?>" role="alert"><?php echo cpmsFacilityEscape((string) ($flash['message'] ?? '')); ?></div>
    <?php endif; ?>
    <div class="facility-page-actions">
        <span><?php echo count($bookings); ?> <?php echo cpmsFacilityEscape(cpmsFacilityText('my_bookings')); ?></span>
        <a class="rp-btn" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_booking.php')); ?>">+ <?php echo cpmsFacilityEscape(cpmsFacilityText('new_booking')); ?></a>
    </div>

    <?php if (!$bookings): ?>
        <section class="rp-card facility-empty"><span>⌂</span><h2><?php echo cpmsFacilityEscape(cpmsFacilityText('no_bookings')); ?></h2><a class="rp-btn" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_booking.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('book')); ?></a></section>
    <?php else: ?>
        <section class="facility-booking-list">
            <?php foreach ($bookings as $booking): ?>
                <?php
                $status = (string) $booking['booking_status'];
                $cancellationStatus = (string) ($booking['cancellation_status'] ?? '');
                $canCancel = in_array($status, ['Pending', 'Approved'], true)
                    && $cancellationStatus !== 'Pending';
                ?>
                <article class="rp-card facility-booking-card status-<?php echo cpmsFacilityEscape(cpmsFacilityStatusClass($status)); ?>">
                    <div class="facility-booking-top">
                        <div><span class="facility-reference"><?php echo cpmsFacilityEscape($booking['booking_reference']); ?></span><h2><?php echo cpmsFacilityEscape($booking['facility_name']); ?></h2><p><?php echo cpmsFacilityEscape($booking['location'] ?: '-'); ?></p></div>
                        <span class="facility-status status-<?php echo cpmsFacilityEscape(cpmsFacilityStatusClass($status)); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityStatusLabel($status)); ?></span>
                    </div>
                    <div class="facility-booking-details">
                        <div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('date')); ?></small><strong><?php echo cpmsFacilityEscape(date('d/m/Y', strtotime((string) $booking['booking_date']))); ?></strong></div>
                        <div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('slot')); ?></small><strong><?php echo cpmsFacilityEscape(substr((string) $booking['start_time'], 0, 5)); ?>–<?php echo cpmsFacilityEscape(substr((string) $booking['end_time'], 0, 5)); ?></strong></div>
                        <div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('guests')); ?></small><strong><?php echo (int) $booking['guest_count']; ?></strong></div>
                        <div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('payment')); ?></small><strong><?php echo cpmsFacilityEscape($booking['payment_status']); ?></strong></div>
                    </div>
                    <p class="facility-booking-purpose"><?php echo cpmsFacilityEscape($booking['purpose']); ?></p>
                    <div class="facility-price-line"><span><?php echo cpmsFacilityEscape(cpmsFacilityText('fee')); ?>: <strong>RM <?php echo number_format((float) $booking['fee_amount'], 2); ?></strong></span><span><?php echo cpmsFacilityEscape(cpmsFacilityText('deposit')); ?>: <strong>RM <?php echo number_format((float) $booking['deposit_amount'], 2); ?></strong></span></div>
                    <?php if ($cancellationStatus === 'Pending'): ?>
                        <div class="facility-inline-note">● <?php echo cpmsFacilityEscape(cpmsFacilityText('cancellation_pending')); ?></div>
                    <?php elseif ($canCancel): ?>
                        <a class="facility-cancel-link" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_booking_cancel.php', ['id' => (int) $booking['id']])); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('cancel_request')); ?> →</a>
                    <?php endif; ?>
                    <?php if (!empty($booking['review_notes'])): ?><div class="facility-review-note"><strong><?php echo $language === 'en' ? 'Management note' : 'Catatan pengurusan'; ?>:</strong> <?php echo cpmsFacilityEscape($booking['review_notes']); ?></div><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<footer class="rp-footer"><a href="resident_logout.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('logout')); ?></a> · <?php echo cpmsFacilityEscape(cpmsFacilityText('footer')); ?></footer>
</body>
</html>
