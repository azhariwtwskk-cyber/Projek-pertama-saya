<?php
declare(strict_types=1);

require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cpms/includes/resident_portal_service.php';
require_once __DIR__ . '/cpms/includes/facility_portal_helper.php';

$resident = cpmsResidentRequire($conn);
$propertyId = (int) $resident['property_id'];
$language = cpmsFacilityLanguage();
$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    $month = date('Y-m');
}
$facilityId = max(0, (int) ($_GET['facility'] ?? 0));
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

$stmt = $conn->prepare(
    "SELECT id,facility_name FROM cpms_facilities
     WHERE property_id=? AND active=1 ORDER BY facility_name"
);
if (!$stmt) {
    throw new RuntimeException('Facility query failed.');
}
$stmt->bind_param('i', $propertyId);
cpmsFacilityExecute($stmt, 'Facility query failed.');
$facilities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$allowedFacilityIds = array_map(
    static function (array $facility): int {
        return (int) $facility['id'];
    },
    $facilities
);
if ($facilityId > 0 && !in_array($facilityId, $allowedFacilityIds, true)) {
    $facilityId = 0;
}

if ($facilityId > 0) {
    $stmt = $conn->prepare(
        "SELECT b.booking_date,b.start_time,b.end_time,f.facility_name
         FROM cpms_facility_bookings b
         INNER JOIN cpms_facilities f
            ON f.id=b.facility_id AND f.property_id=b.property_id
         WHERE b.property_id=? AND b.facility_id=?
           AND b.booking_date BETWEEN ? AND ?
           AND b.booking_status IN ('Pending','Approved')
         ORDER BY b.booking_date,b.start_time"
    );
    if (!$stmt) {
        throw new RuntimeException('Calendar query failed.');
    }
    $stmt->bind_param('iiss', $propertyId, $facilityId, $from, $to);
} else {
    $stmt = $conn->prepare(
        "SELECT b.booking_date,b.start_time,b.end_time,f.facility_name
         FROM cpms_facility_bookings b
         INNER JOIN cpms_facilities f
            ON f.id=b.facility_id AND f.property_id=b.property_id
         WHERE b.property_id=? AND b.booking_date BETWEEN ? AND ?
           AND b.booking_status IN ('Pending','Approved')
         ORDER BY b.booking_date,b.start_time"
    );
    if (!$stmt) {
        throw new RuntimeException('Calendar query failed.');
    }
    $stmt->bind_param('iss', $propertyId, $from, $to);
}
cpmsFacilityExecute($stmt, 'Calendar query failed.');
$slots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$previousMonth = date('Y-m', strtotime($from . ' -1 month'));
$nextMonth = date('Y-m', strtotime($from . ' +1 month'));
$malayMonths = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Mac',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Jun',
    '07' => 'Julai',
    '08' => 'Ogos',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Disember',
];
$malayDays = [
    1 => 'Isn',
    2 => 'Sel',
    3 => 'Rab',
    4 => 'Kha',
    5 => 'Jum',
    6 => 'Sab',
    7 => 'Aha',
];
$monthLabel = $language === 'en'
    ? date('F Y', strtotime($from))
    : ($malayMonths[date('m', strtotime($from))] ?? $month)
        . ' ' . date('Y', strtotime($from));
?>
<!doctype html>
<html lang="<?php echo $language === 'en' ? 'en' : 'ms'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo cpmsFacilityEscape(cpmsFacilityText('calendar_title')); ?> | CPMS</title>
    <link rel="stylesheet" href="resident_portal.css?v=3580">
    <link rel="stylesheet" href="facility-resident.css?v=3580">
</head>
<body>
<header class="rp-head facility-hero">
    <div class="rp-wrap facility-hero-row">
        <div>
            <small><?php echo cpmsFacilityEscape(cpmsFacilityText('portal')); ?></small>
            <h1><?php echo cpmsFacilityEscape(cpmsFacilityText('calendar_title')); ?></h1>
            <p><?php echo cpmsFacilityEscape(cpmsFacilityText('calendar_intro')); ?></p>
        </div>
        <div class="facility-language" aria-label="Language">
            <a class="<?php echo $language === 'bm' ? 'active' : ''; ?>" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php', ['month' => $month, 'facility' => $facilityId, 'lang' => 'bm'])); ?>">BM</a>
            <a class="<?php echo $language === 'en' ? 'active' : ''; ?>" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php', ['month' => $month, 'facility' => $facilityId, 'lang' => 'en'])); ?>">EN</a>
        </div>
    </div>
</header>
<nav class="rp-nav facility-nav">
    <a href="resident_dashboard.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('dashboard')); ?></a>
    <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_booking.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('book')); ?></a>
    <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_bookings.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('my_bookings')); ?></a>
    <a class="active" href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php')); ?>"><?php echo cpmsFacilityEscape(cpmsFacilityText('calendar')); ?></a>
    <a href="resident_notifications.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('notifications')); ?></a>
</nav>
<main class="rp-main rp-wrap facility-main">
    <form class="rp-card facility-calendar-filter" method="get">
        <input type="hidden" name="lang" value="<?php echo cpmsFacilityEscape($language); ?>">
        <label><?php echo cpmsFacilityEscape(cpmsFacilityText('month')); ?><input class="rp-field" type="month" name="month" value="<?php echo cpmsFacilityEscape($month); ?>"></label>
        <label><?php echo cpmsFacilityEscape(cpmsFacilityText('facility')); ?><select class="rp-field" name="facility"><option value="0"><?php echo cpmsFacilityEscape(cpmsFacilityText('all_facilities')); ?></option><?php foreach ($facilities as $facility): ?><option value="<?php echo (int) $facility['id']; ?>" <?php echo $facilityId === (int) $facility['id'] ? 'selected' : ''; ?>><?php echo cpmsFacilityEscape($facility['facility_name']); ?></option><?php endforeach; ?></select></label>
        <button class="rp-btn" type="submit"><?php echo cpmsFacilityEscape(cpmsFacilityText('show')); ?></button>
    </form>
    <div class="facility-month-switcher">
        <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php', ['month' => $previousMonth, 'facility' => $facilityId])); ?>" aria-label="Previous month">←</a>
        <strong><?php echo cpmsFacilityEscape($monthLabel); ?></strong>
        <a href="<?php echo cpmsFacilityEscape(cpmsFacilityUrl('facility_calendar.php', ['month' => $nextMonth, 'facility' => $facilityId])); ?>" aria-label="Next month">→</a>
    </div>
    <?php if (!$slots): ?>
        <section class="rp-card facility-empty"><span>✓</span><h2><?php echo cpmsFacilityEscape(cpmsFacilityText('no_slots')); ?></h2></section>
    <?php else: ?>
        <section class="facility-slot-list">
            <?php $lastDate = ''; ?>
            <?php foreach ($slots as $slot): ?>
                <?php if ($lastDate !== (string) $slot['booking_date']): ?>
                    <?php if ($lastDate !== ''): ?></div></article><?php endif; ?>
                    <?php $lastDate = (string) $slot['booking_date']; ?>
                    <article class="rp-card facility-day-card"><div class="facility-day-heading"><span><?php echo cpmsFacilityEscape($language === 'en' ? date('D', strtotime($lastDate)) : ($malayDays[(int) date('N', strtotime($lastDate))] ?? '')); ?></span><strong><?php echo cpmsFacilityEscape(date('d/m/Y', strtotime($lastDate))); ?></strong></div><div class="facility-day-slots">
                <?php endif; ?>
                <div class="facility-slot"><div><strong><?php echo cpmsFacilityEscape($slot['facility_name']); ?></strong><small><?php echo cpmsFacilityEscape(cpmsFacilityText('occupied')); ?></small></div><span><?php echo cpmsFacilityEscape(substr((string) $slot['start_time'], 0, 5)); ?>–<?php echo cpmsFacilityEscape(substr((string) $slot['end_time'], 0, 5)); ?></span></div>
            <?php endforeach; ?>
            </div></article>
        </section>
    <?php endif; ?>
</main>
<footer class="rp-footer"><a href="resident_logout.php"><?php echo cpmsFacilityEscape(cpmsFacilityText('logout')); ?></a> · <?php echo cpmsFacilityEscape(cpmsFacilityText('footer')); ?></footer>
</body>
</html>
