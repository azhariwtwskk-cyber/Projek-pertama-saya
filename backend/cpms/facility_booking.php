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
$error = '';
$selectedFacilityId = (int) ($_POST['facility_id'] ?? $_GET['facility'] ?? 0);

$stmt = $conn->prepare(
    "SELECT * FROM cpms_facilities
     WHERE property_id=? AND active=1
     ORDER BY facility_name"
);
if (!$stmt) {
    throw new RuntimeException('Facility query failed.');
}
$stmt->bind_param('i', $propertyId);
cpmsFacilityExecute($stmt, 'Facility query failed.');
$facilities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        cpmsFacilityVerifyCsrf();
        $facilityId = (int) ($_POST['facility_id'] ?? 0);
        $date = trim((string) ($_POST['booking_date'] ?? ''));
        $start = trim((string) ($_POST['start_time'] ?? ''));
        $end = trim((string) ($_POST['end_time'] ?? ''));
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        $guests = (int) ($_POST['guest_count'] ?? 1);

        $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && $dateObject instanceof DateTime
            && $dateObject->format('Y-m-d') === $date;
        $validStart = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) === 1;
        $validEnd = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) === 1;

        if (
            $facilityId < 1 || !$validDate || !$validStart || !$validEnd
            || $start >= $end || $purpose === '' || strlen($purpose) > 250
            || $guests < 1
        ) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The booking information is incomplete or invalid.'
                    : 'Maklumat tempahan tidak lengkap atau tidak sah.'
            );
        }

        $conn->begin_transaction();
        $transactionStarted = true;

        /* Lock the property facility so concurrent submissions serialize. */
        $stmt = $conn->prepare(
            "SELECT * FROM cpms_facilities
             WHERE id=? AND property_id=? AND active=1
             LIMIT 1 FOR UPDATE"
        );
        if (!$stmt) {
            throw new RuntimeException('Facility validation failed.');
        }
        $stmt->bind_param('ii', $facilityId, $propertyId);
        cpmsFacilityExecute($stmt, 'Facility validation failed.');
        $facility = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$facility) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The selected facility is not available for your property.'
                    : 'Fasiliti yang dipilih tidak tersedia untuk hartanah anda.'
            );
        }

        $today = date('Y-m-d');
        $maxDate = date(
            'Y-m-d',
            strtotime('+' . (int) $facility['advance_days'] . ' days')
        );
        if ($date < $today || $date > $maxDate) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The date is outside the permitted advance booking period.'
                    : 'Tarikh di luar tempoh tempahan awal yang dibenarkan.'
            );
        }
        if (strtotime($date . ' ' . $start) <= time()) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The booking must start in the future.'
                    : 'Masa mula tempahan mestilah pada masa hadapan.'
            );
        }

        $opening = substr((string) $facility['opening_time'], 0, 5);
        $closing = substr((string) $facility['closing_time'], 0, 5);
        if ($start < $opening || $end > $closing) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The selected time is outside the facility operating hours.'
                    : 'Masa dipilih di luar waktu operasi fasiliti.'
            );
        }

        $slotMinutes = max(1, (int) $facility['slot_minutes']);
        $startMinutes = ((int) substr($start, 0, 2) * 60)
            + (int) substr($start, 3, 2);
        $endMinutes = ((int) substr($end, 0, 2) * 60)
            + (int) substr($end, 3, 2);
        $openingMinutes = ((int) substr($opening, 0, 2) * 60)
            + (int) substr($opening, 3, 2);
        if (
            (($startMinutes - $openingMinutes) % $slotMinutes) !== 0
            || (($endMinutes - $startMinutes) % $slotMinutes) !== 0
        ) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'Start time and duration must follow the facility slot interval.'
                    : 'Masa mula dan tempoh mesti mengikut sela slot fasiliti.'
            );
        }

        $capacity = (int) ($facility['capacity'] ?? 0);
        if ($capacity > 0 && $guests > $capacity) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'The number of guests exceeds the facility capacity.'
                    : 'Jumlah tetamu melebihi kapasiti fasiliti.'
            );
        }
        if (!cpmsFacilitySlotAvailable(
            $conn,
            $propertyId,
            $facilityId,
            $date,
            $start,
            $end
        )) {
            throw new RuntimeException(
                $language === 'en'
                    ? 'This slot is unavailable. Choose another time.'
                    : 'Slot ini tidak tersedia. Pilih masa lain.'
            );
        }

        $reference = cpmsFacilityBookingReference($conn, $propertyId);
        $status = (int) $facility['approval_required'] === 1
            ? 'Pending'
            : 'Approved';
        $fee = (float) $facility['booking_fee'];
        $deposit = (float) $facility['deposit_amount'];
        $paymentStatus = ($fee + $deposit) > 0
            ? 'Pending'
            : 'Not Required';

        $stmt = $conn->prepare(
            "INSERT INTO cpms_facility_bookings (
                property_id,facility_id,resident_id,booking_reference,
                booking_date,start_time,end_time,purpose,guest_count,
                booking_status,fee_amount,deposit_amount,payment_status
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Booking insert failed.');
        }
        $stmt->bind_param(
            'iiisssssisdds',
            $propertyId,
            $facilityId,
            $residentId,
            $reference,
            $date,
            $start,
            $end,
            $purpose,
            $guests,
            $status,
            $fee,
            $deposit,
            $paymentStatus
        );
        cpmsFacilityExecute($stmt, 'Booking insert failed.');
        $bookingId = (int) $conn->insert_id;
        $stmt->close();

        $auditMessage = $language === 'en'
            ? 'Booking ' . $reference . ' was submitted.'
            : 'Tempahan ' . $reference . ' telah dihantar.';
        cpmsFacilityBookingUpdate(
            $conn,
            $propertyId,
            $bookingId,
            null,
            $status,
            $auditMessage,
            (int) ($_SESSION['cpms_user_id'] ?? 0)
        );
        cpmsResidentNotify(
            $conn,
            $propertyId,
            $residentId,
            'facility',
            $status === 'Approved'
                ? ($language === 'en' ? 'Booking confirmed' : 'Tempahan disahkan')
                : ($language === 'en' ? 'Booking submitted' : 'Tempahan dihantar'),
            $auditMessage,
            'facility_bookings.php'
        );

        $conn->commit();
        $transactionStarted = false;
        cpmsFacilityFlash(
            'success',
            $language === 'en'
                ? 'Booking submitted successfully: ' . $reference
                : 'Tempahan berjaya dihantar: ' . $reference
        );
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
    <title><?php echo cpmsFacilityEscape(cpmsFacilityText('booking_title')); ?> | CPMS</title>
    <link rel="stylesheet" href="resident_portal.css?v=3580">
    <link rel="stylesheet" href="facility-resident.css?v=3580">
</head>
<body>
<header class="rp-head facility-hero">
    <div class="rp-wrap facility-hero-row">
        <div>
            <small><?php echo cpmsFacilityEscape(cpmsFacilityText('portal')); ?></small>
            <h1><?php echo cpmsFacilityEscape(cpmsFacilityText('booking_title')); ?></h1>
            <p><?php echo cpmsFacilityEscape(cpmsFacilityText('booking_intro')); ?></p>
        </div>
        <div class="facility-language" aria-label="Language">
            <a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="facility_booking.php?lang=bm">BM</a>
            <a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="facility_booking.php?lang=en">EN</a>
        </div>
    </div>
</header>
<nav class="rp-nav facility-nav">
    <a href="resident_dashboard.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('dashboard')); ?></a>
    <a class="active" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_booking.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('book')); ?></a>
    <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_bookings.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('my_bookings')); ?></a>
    <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('calendar')); ?></a>
    <a href="resident_notifications.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('notifications')); ?></a>
</nav>
<main class="rp-main rp-wrap facility-main">
    <?php if ($error !== ''): ?>
        <div class="facility-alert facility-alert--danger" role="alert"><?php echo cpmsFacilityEscape($error); ?></div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <section class="rp-card facility-empty">
            <span>⌂</span>
            <h2><?php echo cpmsFacilityEscape(cpmsFacilityText('none_available')); ?></h2>
        </section>
    <?php else: ?>
        <section class="facility-layout">
            <form class="rp-card facility-form" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityEscape(cpmsFacilityCsrfToken()); ?>">
                <div class="facility-section-title">
                    <span>01</span>
                    <div><small><?php echo cpmsFacilityEscape(cpmsFacilityText('new_booking')); ?></small><h2><?php echo cpmsFacilityEscape(cpmsFacilityText('booking_title')); ?></h2></div>
                </div>
                <label><?php echo cpmsFacilityEscape(cpmsFacilityText('facility')); ?>
                    <select class="rp-field" name="facility_id" id="facilitySelect" required>
                        <option value=""><?php echo cpmsFacilityEscape(cpmsFacilityText('select_facility')); ?></option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?php echo (int) $facility['id']; ?>" <?php echo $selectedFacilityId === (int) $facility['id'] ? 'selected' : ''; ?>><?php echo cpmsFacilityEscape($facility['facility_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="rp-grid facility-field-grid">
                    <label><?php echo cpmsFacilityEscape(cpmsFacilityText('date')); ?><input class="rp-field" type="date" name="booking_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo cpmsFacilityEscape($_POST['booking_date'] ?? ''); ?>" required></label>
                    <label><?php echo cpmsFacilityEscape(cpmsFacilityText('guests')); ?><input class="rp-field" type="number" name="guest_count" min="1" value="<?php echo max(1, (int) ($_POST['guest_count'] ?? 1)); ?>" required></label>
                    <label><?php echo cpmsFacilityEscape(cpmsFacilityText('start')); ?><input class="rp-field" type="time" name="start_time" value="<?php echo cpmsFacilityEscape($_POST['start_time'] ?? ''); ?>" required></label>
                    <label><?php echo cpmsFacilityEscape(cpmsFacilityText('end')); ?><input class="rp-field" type="time" name="end_time" value="<?php echo cpmsFacilityEscape($_POST['end_time'] ?? ''); ?>" required></label>
                </div>
                <label><?php echo cpmsFacilityEscape(cpmsFacilityText('purpose')); ?>
                    <textarea class="rp-field" name="purpose" rows="4" maxlength="250" placeholder="<?php echo cpmsFacilityEscape(cpmsFacilityText('purpose_hint')); ?>" required><?php echo cpmsFacilityEscape($_POST['purpose'] ?? ''); ?></textarea>
                </label>
                <button class="rp-btn facility-primary-button" type="submit"><?php echo cpmsFacilityEscape(cpmsFacilityText('submit')); ?> →</button>
            </form>

            <aside class="facility-information">
                <div class="facility-aside-heading"><small><?php echo cpmsFacilityEscape(cpmsFacilityText('availability')); ?></small><h2><?php echo count($facilities); ?> <?php echo cpmsFacilityEscape(cpmsFacilityText('facility')); ?></h2></div>
                <?php foreach ($facilities as $facility): ?>
                    <article class="facility-summary-card" data-facility-card="<?php echo (int) $facility['id']; ?>">
                        <div class="facility-summary-top"><span class="facility-icon">⌂</span><div><h3><?php echo cpmsFacilityEscape($facility['facility_name']); ?></h3><p><?php echo cpmsFacilityEscape($facility['location'] ?: '-'); ?></p></div></div>
                        <?php if (!empty($facility['description'])): ?><p class="facility-description"><?php echo cpmsFacilityEscape($facility['description']); ?></p><?php endif; ?>
                        <dl class="facility-facts">
                            <div><dt><?php echo cpmsFacilityEscape(cpmsFacilityText('hours')); ?></dt><dd><?php echo cpmsFacilityEscape(substr((string) $facility['opening_time'], 0, 5)); ?>–<?php echo cpmsFacilityEscape(substr((string) $facility['closing_time'], 0, 5)); ?></dd></div>
                            <div><dt><?php echo cpmsFacilityEscape(cpmsFacilityText('capacity')); ?></dt><dd><?php echo (int) $facility['capacity']; ?> <?php echo cpmsFacilityEscape(cpmsFacilityText('people')); ?></dd></div>
                            <div><dt><?php echo cpmsFacilityEscape(cpmsFacilityText('slot')); ?></dt><dd><?php echo (int) $facility['slot_minutes']; ?> <?php echo cpmsFacilityEscape(cpmsFacilityText('minutes')); ?></dd></div>
                            <div><dt><?php echo cpmsFacilityEscape(cpmsFacilityText('advance')); ?></dt><dd><?php echo (int) $facility['advance_days']; ?> <?php echo cpmsFacilityEscape(cpmsFacilityText('days')); ?></dd></div>
                            <div><dt><?php echo cpmsFacilityEscape(cpmsFacilityText('fee')); ?></dt><dd>RM <?php echo number_format((float) $facility['booking_fee'], 2); ?></dd></div>
                            <div><dt><?php echo cpmsFacilityEscape(cpmsFacilityText('deposit')); ?></dt><dd>RM <?php echo number_format((float) $facility['deposit_amount'], 2); ?></dd></div>
                        </dl>
                        <p class="facility-approval-note"><?php echo (int) $facility['approval_required'] === 1 ? '● ' . cpmsFacilityEscape(cpmsFacilityText('approval_needed')) : '✓ ' . cpmsFacilityEscape(cpmsFacilityText('auto_approval')); ?></p>
                    </article>
                <?php endforeach; ?>
            </aside>
        </section>
    <?php endif; ?>
</main>
<footer class="rp-footer"><a href="resident_logout.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('logout')); ?></a> · <?php echo cpmsFacilityEscape(cpmsFacilityText('footer')); ?></footer>
</body>
</html>
