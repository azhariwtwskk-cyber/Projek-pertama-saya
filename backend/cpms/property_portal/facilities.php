<?php
declare(strict_types=1);

/*
 * CPMS v3.5.8 — Property-scoped Facility Booking & Approval.
 * PHP 7.4 compatible; all mutations use CSRF and state-transition checks.
 */

require_once __DIR__ . '/auth.php';
cpmsRequire('facilities.manage', $conn);
require_once dirname(__DIR__) . '/cpms/includes/facility_booking_service.php';
require_once dirname(__DIR__) . '/cpms/includes/resident_notification_service.php';

function cpmsFacilityAdminEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsFacilityAdminExecute(
    mysqli_stmt $stmt,
    string $message
): void {
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsFacilityAdminFlash(string $type, string $message): void
{
    $_SESSION['facility_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsFacilityAdminPullFlash(): ?array
{
    $flash = $_SESSION['facility_admin_flash'] ?? null;
    unset($_SESSION['facility_admin_flash']);
    return is_array($flash) ? $flash : null;
}

function cpmsFacilityAdminRedirect(
    string $status = 'Pending',
    string $anchor = 'bookings'
): void {
    $statuses = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'];
    if (!in_array($status, $statuses, true)) {
        $status = 'Pending';
    }
    $anchors = ['bookings', 'cancellations', 'facility-settings'];
    if (!in_array($anchor, $anchors, true)) {
        $anchor = 'bookings';
    }
    header(
        'Location: facilities.php?status=' . rawurlencode($status)
        . '#' . $anchor
    );
    exit;
}

function cpmsFacilityAdminStatusClass(string $status): string
{
    $allowed = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
    return in_array($status, $allowed, true)
        ? strtolower($status)
        : 'neutral';
}

$allowedStatuses = ['Pending', 'Approved', 'Rejected', 'Cancelled', 'All'];
$selectedStatus = trim((string) ($_GET['status'] ?? 'Pending'));
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'Pending';
}
$actorUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnStatus = trim((string) ($_POST['return_status'] ?? 'Pending'));
    $returnAnchor = trim((string) ($_POST['return_anchor'] ?? 'bookings'));
    if (!propertyPortalVerifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        cpmsFacilityAdminFlash(
            'danger',
            'Token keselamatan tidak sah. Muat semula halaman dan cuba lagi.'
        );
        cpmsFacilityAdminRedirect($returnStatus, $returnAnchor);
    }

    $transactionStarted = false;
    try {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_facility') {
            $facilityId = max(0, (int) ($_POST['facility_id'] ?? 0));
            $name = trim((string) ($_POST['facility_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $capacity = max(0, (int) ($_POST['capacity'] ?? 0));
            $opening = trim((string) ($_POST['opening_time'] ?? '08:00'));
            $closing = trim((string) ($_POST['closing_time'] ?? '22:00'));
            $slotMinutes = (int) ($_POST['slot_minutes'] ?? 60);
            $fee = max(0, (float) ($_POST['booking_fee'] ?? 0));
            $deposit = max(0, (float) ($_POST['deposit_amount'] ?? 0));
            $advanceDays = (int) ($_POST['advance_days'] ?? 30);
            $approvalRequired = isset($_POST['approval_required']) ? 1 : 0;
            $active = isset($_POST['active']) ? 1 : 0;
            $validTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $opening) === 1
                && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $closing) === 1;

            if (
                $name === '' || strlen($name) > 150
                || strlen($description) > 500 || strlen($location) > 180
                || !$validTime || $opening >= $closing
                || $slotMinutes < 5 || $slotMinutes > 480
                || ($slotMinutes % 5) !== 0
                || $advanceDays < 0 || $advanceDays > 365
            ) {
                throw new RuntimeException('Maklumat fasiliti tidak lengkap atau tidak sah.');
            }

            if ($facilityId > 0) {
                $stmt = $conn->prepare(
                    "UPDATE cpms_facilities SET
                        facility_name=?,description=NULLIF(?,''),
                        location=NULLIF(?,''),capacity=?,opening_time=?,
                        closing_time=?,slot_minutes=?,booking_fee=?,
                        deposit_amount=?,advance_days=?,approval_required=?,
                        active=?
                     WHERE id=? AND property_id=?"
                );
                if (!$stmt) {
                    throw new RuntimeException('Facility update failed.');
                }
                $stmt->bind_param(
                    'sssissiddiiiii',
                    $name,
                    $description,
                    $location,
                    $capacity,
                    $opening,
                    $closing,
                    $slotMinutes,
                    $fee,
                    $deposit,
                    $advanceDays,
                    $approvalRequired,
                    $active,
                    $facilityId,
                    $currentPropertyId
                );
                cpmsFacilityAdminExecute($stmt, 'Facility update failed.');
                if ($stmt->affected_rows < 1) {
                    $check = $conn->prepare(
                        'SELECT id FROM cpms_facilities WHERE id=? AND property_id=?'
                    );
                    if (!$check) {
                        $stmt->close();
                        throw new RuntimeException('Facility validation failed.');
                    }
                    $check->bind_param('ii', $facilityId, $currentPropertyId);
                    cpmsFacilityAdminExecute($check, 'Facility validation failed.');
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();
                    if (!$exists) {
                        $stmt->close();
                        throw new RuntimeException('Fasiliti tidak dijumpai.');
                    }
                }
                $stmt->close();
                $message = 'Fasiliti berjaya dikemas kini.';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO cpms_facilities (
                        property_id,facility_name,description,location,capacity,
                        opening_time,closing_time,slot_minutes,booking_fee,
                        deposit_amount,advance_days,approval_required,active
                     ) VALUES (?, ?,NULLIF(?,''),NULLIF(?,''),?,?,?,?,?,?,?,?,?)"
                );
                if (!$stmt) {
                    throw new RuntimeException('Facility insert failed.');
                }
                $stmt->bind_param(
                    'isssissiddiii',
                    $currentPropertyId,
                    $name,
                    $description,
                    $location,
                    $capacity,
                    $opening,
                    $closing,
                    $slotMinutes,
                    $fee,
                    $deposit,
                    $advanceDays,
                    $approvalRequired,
                    $active
                );
                cpmsFacilityAdminExecute($stmt, 'Facility insert failed.');
                $stmt->close();
                $message = 'Fasiliti baharu berjaya ditambah.';
            }
            cpmsFacilityAdminFlash('success', $message);
            cpmsFacilityAdminRedirect($returnStatus, 'facility-settings');
        }

        if ($action === 'review_booking') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $decision = trim((string) ($_POST['decision'] ?? ''));
            $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
            if (
                $bookingId < 1
                || !in_array($decision, ['Approved', 'Rejected'], true)
                || strlen($reviewNotes) > 500
            ) {
                throw new RuntimeException('Keputusan tempahan tidak sah.');
            }
            if ($decision === 'Rejected' && $reviewNotes === '') {
                throw new RuntimeException('Sebab penolakan diperlukan.');
            }

            $stmt = $conn->prepare(
                "SELECT facility_id FROM cpms_facility_bookings
                 WHERE id=? AND property_id=? LIMIT 1"
            );
            if (!$stmt) {
                throw new RuntimeException('Booking validation failed.');
            }
            $stmt->bind_param('ii', $bookingId, $currentPropertyId);
            cpmsFacilityAdminExecute($stmt, 'Booking validation failed.');
            $preflight = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$preflight) {
                throw new RuntimeException('Tempahan tidak dijumpai.');
            }

            $conn->begin_transaction();
            $transactionStarted = true;
            $facilityId = (int) $preflight['facility_id'];
            $stmt = $conn->prepare(
                'SELECT id FROM cpms_facilities WHERE id=? AND property_id=? FOR UPDATE'
            );
            if (!$stmt) {
                throw new RuntimeException('Facility lock failed.');
            }
            $stmt->bind_param('ii', $facilityId, $currentPropertyId);
            cpmsFacilityAdminExecute($stmt, 'Facility lock failed.');
            $lockedFacility = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lockedFacility) {
                throw new RuntimeException('Fasiliti tidak dijumpai.');
            }

            $stmt = $conn->prepare(
                "SELECT * FROM cpms_facility_bookings
                 WHERE id=? AND property_id=? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException('Booking query failed.');
            }
            $stmt->bind_param('ii', $bookingId, $currentPropertyId);
            cpmsFacilityAdminExecute($stmt, 'Booking query failed.');
            $booking = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$booking || (string) $booking['booking_status'] !== 'Pending') {
                throw new RuntimeException('Tempahan sudah diproses atau tidak dijumpai.');
            }

            if (
                $decision === 'Approved'
                && !cpmsFacilitySlotAvailable(
                    $conn,
                    $currentPropertyId,
                    (int) $booking['facility_id'],
                    (string) $booking['booking_date'],
                    (string) $booking['start_time'],
                    (string) $booking['end_time'],
                    $bookingId
                )
            ) {
                throw new RuntimeException(
                    'Slot bertindih dengan tempahan aktif yang lain.'
                );
            }

            $stmt = $conn->prepare(
                "UPDATE cpms_facility_bookings SET
                    booking_status=?,reviewed_by_system_user_id=NULLIF(?,0),
                    reviewed_at=NOW(),review_notes=?
                 WHERE id=? AND property_id=? AND booking_status='Pending'"
            );
            if (!$stmt) {
                throw new RuntimeException('Booking review failed.');
            }
            $stmt->bind_param(
                'sisii',
                $decision,
                $actorUserId,
                $reviewNotes,
                $bookingId,
                $currentPropertyId
            );
            cpmsFacilityAdminExecute($stmt, 'Booking review failed.');
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Tempahan sudah diproses.');
            }
            $stmt->close();
            $auditNote = $reviewNotes !== ''
                ? $reviewNotes
                : 'Tempahan ' . strtolower($decision) . '.';
            cpmsFacilityBookingUpdate(
                $conn,
                $currentPropertyId,
                $bookingId,
                'Pending',
                $decision,
                $auditNote,
                $actorUserId
            );
            cpmsResidentNotify(
                $conn,
                $currentPropertyId,
                (int) $booking['resident_id'],
                'facility',
                'Status tempahan dikemas kini',
                'Tempahan ' . $booking['booking_reference']
                    . ' kini berstatus ' . $decision . '.',
                'facility_bookings.php'
            );
            $conn->commit();
            $transactionStarted = false;
            cpmsFacilityAdminFlash(
                'success',
                $decision === 'Approved'
                    ? 'Tempahan telah diluluskan.'
                    : 'Tempahan telah ditolak.'
            );
            cpmsFacilityAdminRedirect($returnStatus, 'bookings');
        }

        if ($action === 'review_cancellation') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $decision = trim((string) ($_POST['decision'] ?? ''));
            $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
            if (
                $requestId < 1
                || !in_array($decision, ['Approved', 'Rejected'], true)
                || strlen($reviewNotes) > 500
            ) {
                throw new RuntimeException('Keputusan pembatalan tidak sah.');
            }
            if ($decision === 'Rejected' && $reviewNotes === '') {
                throw new RuntimeException('Sebab penolakan diperlukan.');
            }

            $conn->begin_transaction();
            $transactionStarted = true;
            $stmt = $conn->prepare(
                "SELECT c.*,b.booking_status,b.booking_reference
                 FROM cpms_facility_booking_cancellations c
                 INNER JOIN cpms_facility_bookings b
                    ON b.id=c.booking_id AND b.property_id=c.property_id
                 WHERE c.id=? AND c.property_id=?
                   AND c.request_status='Pending'
                 LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException('Cancellation query failed.');
            }
            $stmt->bind_param('ii', $requestId, $currentPropertyId);
            cpmsFacilityAdminExecute($stmt, 'Cancellation query failed.');
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$request) {
                throw new RuntimeException('Permintaan sudah diproses atau tidak dijumpai.');
            }

            $bookingId = (int) $request['booking_id'];
            if ($decision === 'Approved') {
                if ((string) $request['booking_status'] !== 'Approved') {
                    throw new RuntimeException(
                        'Hanya tempahan diluluskan boleh dibatalkan melalui aliran ini.'
                    );
                }
                $stmt = $conn->prepare(
                    "UPDATE cpms_facility_bookings SET
                        booking_status='Cancelled',review_notes=?
                     WHERE id=? AND property_id=? AND booking_status='Approved'"
                );
                if (!$stmt) {
                    throw new RuntimeException('Booking cancellation failed.');
                }
                $stmt->bind_param(
                    'sii',
                    $reviewNotes,
                    $bookingId,
                    $currentPropertyId
                );
                cpmsFacilityAdminExecute($stmt, 'Booking cancellation failed.');
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('Status tempahan telah berubah.');
                }
                $stmt->close();
                cpmsFacilityBookingUpdate(
                    $conn,
                    $currentPropertyId,
                    $bookingId,
                    'Approved',
                    'Cancelled',
                    $reviewNotes !== ''
                        ? $reviewNotes
                        : 'Pembatalan diluluskan.',
                    $actorUserId
                );
            }

            $stmt = $conn->prepare(
                "UPDATE cpms_facility_booking_cancellations SET
                    request_status=?,reviewed_by_system_user_id=NULLIF(?,0),
                    reviewed_at=NOW(),review_notes=?
                 WHERE id=? AND property_id=? AND request_status='Pending'"
            );
            if (!$stmt) {
                throw new RuntimeException('Cancellation review failed.');
            }
            $stmt->bind_param(
                'sisii',
                $decision,
                $actorUserId,
                $reviewNotes,
                $requestId,
                $currentPropertyId
            );
            cpmsFacilityAdminExecute($stmt, 'Cancellation review failed.');
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Permintaan sudah diproses.');
            }
            $stmt->close();
            cpmsResidentNotify(
                $conn,
                $currentPropertyId,
                (int) $request['resident_id'],
                'facility',
                'Keputusan pembatalan',
                'Permintaan pembatalan ' . $request['booking_reference']
                    . ' telah ' . $decision . '.',
                'facility_bookings.php'
            );
            $conn->commit();
            $transactionStarted = false;
            cpmsFacilityAdminFlash(
                'success',
                'Keputusan pembatalan berjaya disimpan.'
            );
            cpmsFacilityAdminRedirect($returnStatus, 'cancellations');
        }

        throw new RuntimeException('Tindakan tidak sah.');
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        error_log('CPMS facility administration: ' . $exception->getMessage());
        $message = $exception->getMessage();
        if ((int) $exception->getCode() === 1062) {
            $message = 'Nama fasiliti ini sudah digunakan untuk hartanah anda.';
        }
        cpmsFacilityAdminFlash('danger', $message);
        cpmsFacilityAdminRedirect($returnStatus, $returnAnchor);
    }
}

$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
$stmt = $conn->prepare(
    "SELECT booking_status,COUNT(*) AS total
     FROM cpms_facility_bookings
     WHERE property_id=? GROUP BY booking_status"
);
if ($stmt) {
    $stmt->bind_param('i', $currentPropertyId);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $status = (string) ($row['booking_status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }
    }
    $stmt->close();
}

$bookings = [];
$bookingSql = "SELECT b.*,f.facility_name,f.location,
                      r.full_name,r.unit_no,r.block_name
               FROM cpms_facility_bookings b
               INNER JOIN cpms_facilities f
                  ON f.id=b.facility_id AND f.property_id=b.property_id
               INNER JOIN cpms_residents r
                  ON r.id=b.resident_id AND r.property_id=b.property_id
               WHERE b.property_id=?";
if ($selectedStatus !== 'All') {
    $bookingSql .= ' AND b.booking_status=?';
}
$bookingSql .= " ORDER BY
    CASE b.booking_status WHEN 'Pending' THEN 0 ELSE 1 END,
    b.booking_date DESC,b.start_time DESC LIMIT 300";
$stmt = $conn->prepare($bookingSql);
if ($stmt) {
    if ($selectedStatus === 'All') {
        $stmt->bind_param('i', $currentPropertyId);
    } else {
        $stmt->bind_param('is', $currentPropertyId, $selectedStatus);
    }
    if ($stmt->execute()) {
        $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$cancellations = [];
$stmt = $conn->prepare(
    "SELECT c.*,b.booking_reference,b.booking_date,b.start_time,b.end_time,
            b.booking_status,f.facility_name,r.full_name,r.unit_no,r.block_name
     FROM cpms_facility_booking_cancellations c
     INNER JOIN cpms_facility_bookings b
        ON b.id=c.booking_id AND b.property_id=c.property_id
     INNER JOIN cpms_facilities f
        ON f.id=b.facility_id AND f.property_id=b.property_id
     INNER JOIN cpms_residents r
        ON r.id=c.resident_id AND r.property_id=c.property_id
     WHERE c.property_id=?
     ORDER BY (c.request_status='Pending') DESC,c.created_at DESC
     LIMIT 300"
);
if ($stmt) {
    $stmt->bind_param('i', $currentPropertyId);
    if ($stmt->execute()) {
        $cancellations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}
$pendingCancellations = 0;
foreach ($cancellations as $cancellation) {
    if ((string) $cancellation['request_status'] === 'Pending') {
        $pendingCancellations++;
    }
}

$facilities = [];
$stmt = $conn->prepare(
    "SELECT * FROM cpms_facilities
     WHERE property_id=? ORDER BY active DESC,facility_name"
);
if ($stmt) {
    $stmt->bind_param('i', $currentPropertyId);
    if ($stmt->execute()) {
        $facilities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

$editFacility = null;
$editFacilityId = max(0, (int) ($_GET['edit'] ?? 0));
if ($editFacilityId > 0) {
    foreach ($facilities as $facility) {
        if ((int) $facility['id'] === $editFacilityId) {
            $editFacility = $facility;
            break;
        }
    }
}

$flash = cpmsFacilityAdminPullFlash();
$pageTitle = 'Facility Management';
$activeMenu = 'facilities';
$pageStyles = ['assets/facility-management.css?v=3580'];
require __DIR__ . '/includes/layout_header.php';
require __DIR__ . '/includes/layout_sidebar.php';
require __DIR__ . '/includes/layout_topbar.php';
?>

<section class="facility-admin-heading">
    <div><span class="facility-admin-label">PROPERTY-SCOPED BOOKING CONTROL</span><h1>Facility Management</h1><p>Urus fasiliti, kelulusan tempahan dan pembatalan untuk <?php echo cpmsFacilityAdminEscape($currentPropertyName); ?> sahaja.</p></div>
    <a class="facility-public-link" href="../facility_calendar.php" target="_blank" rel="noopener">Buka Kalendar Resident ↗</a>
</section>

<?php if ($flash): ?><div class="facility-admin-alert facility-admin-alert--<?php echo cpmsFacilityAdminEscape((string) ($flash['type'] ?? 'success')); ?>" role="alert"><?php echo cpmsFacilityAdminEscape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>

<section class="facility-admin-stats">
    <?php foreach ($counts as $status => $total): ?><a class="facility-admin-stat <?php echo $selectedStatus === $status ? 'is-active' : ''; ?>" href="facilities.php?status=<?php echo rawurlencode($status); ?>#bookings"><span><?php echo cpmsFacilityAdminEscape($status); ?></span><strong><?php echo (int) $total; ?></strong></a><?php endforeach; ?>
    <a class="facility-admin-stat <?php echo $selectedStatus === 'All' ? 'is-active' : ''; ?>" href="facilities.php?status=All#bookings"><span>All Bookings</span><strong><?php echo array_sum($counts); ?></strong></a>
    <a class="facility-admin-stat facility-admin-stat--cancel" href="#cancellations"><span>Pending Cancellations</span><strong><?php echo $pendingCancellations; ?></strong></a>
</section>

<section class="facility-admin-panel" id="bookings">
    <div class="facility-admin-panel-head"><div><span class="facility-admin-label"><?php echo cpmsFacilityAdminEscape(strtoupper($selectedStatus)); ?></span><h2>Booking Approval Queue</h2></div><span><?php echo count($bookings); ?> rekod</span></div>
    <?php if (!$bookings): ?><div class="facility-admin-empty"><span>✓</span><strong>Tiada tempahan <?php echo cpmsFacilityAdminEscape(strtolower($selectedStatus)); ?></strong></div><?php else: ?>
        <div class="facility-admin-booking-list">
        <?php foreach ($bookings as $booking): ?>
            <?php $status = (string) $booking['booking_status']; ?>
            <article class="facility-admin-booking status-<?php echo cpmsFacilityAdminEscape(cpmsFacilityAdminStatusClass($status)); ?>">
                <div class="facility-admin-booking-top"><div><span class="facility-admin-reference"><?php echo cpmsFacilityAdminEscape($booking['booking_reference']); ?></span><h3><?php echo cpmsFacilityAdminEscape($booking['facility_name']); ?></h3><p><?php echo cpmsFacilityAdminEscape($booking['full_name']); ?> · <?php echo cpmsFacilityAdminEscape(($booking['block_name'] ?: '-') . ' / ' . ($booking['unit_no'] ?: '-')); ?></p></div><span class="facility-admin-status status-<?php echo cpmsFacilityAdminEscape(cpmsFacilityAdminStatusClass($status)); ?>"><?php echo cpmsFacilityAdminEscape($status); ?></span></div>
                <dl class="facility-admin-details"><div><dt>Tarikh</dt><dd><?php echo cpmsFacilityAdminEscape(date('d/m/Y', strtotime((string) $booking['booking_date']))); ?></dd></div><div><dt>Slot</dt><dd><?php echo cpmsFacilityAdminEscape(substr((string) $booking['start_time'], 0, 5)); ?>–<?php echo cpmsFacilityAdminEscape(substr((string) $booking['end_time'], 0, 5)); ?></dd></div><div><dt>Tetamu</dt><dd><?php echo (int) $booking['guest_count']; ?></dd></div><div><dt>Jumlah</dt><dd>RM <?php echo number_format((float) $booking['fee_amount'] + (float) $booking['deposit_amount'], 2); ?></dd></div></dl>
                <p class="facility-admin-purpose"><?php echo cpmsFacilityAdminEscape($booking['purpose']); ?></p>
                <?php if ($status === 'Pending'): ?><div class="facility-admin-review-grid"><form method="post" class="facility-admin-review facility-admin-review--approve"><input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityAdminEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="review_booking"><input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>"><input type="hidden" name="decision" value="Approved"><input type="hidden" name="return_status" value="<?php echo cpmsFacilityAdminEscape($selectedStatus); ?>"><input type="hidden" name="return_anchor" value="bookings"><label>Catatan kelulusan (pilihan)<textarea name="review_notes" maxlength="500" placeholder="Contoh: Slot disahkan"></textarea></label><button type="submit">✓ Luluskan Tempahan</button></form><form method="post" class="facility-admin-review facility-admin-review--reject"><input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityAdminEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="review_booking"><input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>"><input type="hidden" name="decision" value="Rejected"><input type="hidden" name="return_status" value="<?php echo cpmsFacilityAdminEscape($selectedStatus); ?>"><input type="hidden" name="return_anchor" value="bookings"><label>Sebab ditolak<textarea name="review_notes" maxlength="500" required></textarea></label><button type="submit">Tolak Tempahan</button></form></div><?php elseif (!empty($booking['review_notes'])): ?><div class="facility-admin-history"><strong>Catatan keputusan</strong><p><?php echo nl2br(cpmsFacilityAdminEscape($booking['review_notes'])); ?></p></div><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="facility-admin-panel" id="cancellations">
    <div class="facility-admin-panel-head"><div><span class="facility-admin-label">CONTROLLED CANCELLATION</span><h2>Cancellation Requests</h2></div><span><?php echo $pendingCancellations; ?> menunggu</span></div>
    <?php if (!$cancellations): ?><div class="facility-admin-empty"><span>✓</span><strong>Tiada permintaan pembatalan</strong></div><?php else: ?><div class="facility-admin-cancellation-list">
    <?php foreach ($cancellations as $cancellation): ?><article class="facility-admin-cancellation"><div class="facility-admin-booking-top"><div><span class="facility-admin-reference"><?php echo cpmsFacilityAdminEscape($cancellation['booking_reference']); ?></span><h3><?php echo cpmsFacilityAdminEscape($cancellation['facility_name']); ?></h3><p><?php echo cpmsFacilityAdminEscape($cancellation['full_name']); ?> · <?php echo cpmsFacilityAdminEscape(($cancellation['block_name'] ?: '-') . ' / ' . ($cancellation['unit_no'] ?: '-')); ?> · <?php echo cpmsFacilityAdminEscape(date('d/m/Y', strtotime((string) $cancellation['booking_date']))); ?> <?php echo cpmsFacilityAdminEscape(substr((string) $cancellation['start_time'], 0, 5)); ?>–<?php echo cpmsFacilityAdminEscape(substr((string) $cancellation['end_time'], 0, 5)); ?></p></div><span class="facility-admin-status status-<?php echo cpmsFacilityAdminEscape(cpmsFacilityAdminStatusClass((string) $cancellation['request_status'])); ?>"><?php echo cpmsFacilityAdminEscape($cancellation['request_status']); ?></span></div><p class="facility-admin-purpose"><strong>Alasan resident:</strong> <?php echo cpmsFacilityAdminEscape($cancellation['reason']); ?></p>
    <?php if ((string) $cancellation['request_status'] === 'Pending'): ?><div class="facility-admin-review-grid"><form method="post" class="facility-admin-review facility-admin-review--approve"><input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityAdminEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="review_cancellation"><input type="hidden" name="request_id" value="<?php echo (int) $cancellation['id']; ?>"><input type="hidden" name="decision" value="Approved"><input type="hidden" name="return_status" value="<?php echo cpmsFacilityAdminEscape($selectedStatus); ?>"><input type="hidden" name="return_anchor" value="cancellations"><label>Catatan (pilihan)<textarea name="review_notes" maxlength="500"></textarea></label><button type="submit">✓ Luluskan Pembatalan</button></form><form method="post" class="facility-admin-review facility-admin-review--reject"><input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityAdminEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="review_cancellation"><input type="hidden" name="request_id" value="<?php echo (int) $cancellation['id']; ?>"><input type="hidden" name="decision" value="Rejected"><input type="hidden" name="return_status" value="<?php echo cpmsFacilityAdminEscape($selectedStatus); ?>"><input type="hidden" name="return_anchor" value="cancellations"><label>Sebab ditolak<textarea name="review_notes" maxlength="500" required></textarea></label><button type="submit">Tolak Pembatalan</button></form></div><?php elseif (!empty($cancellation['review_notes'])): ?><div class="facility-admin-history"><strong>Catatan keputusan</strong><p><?php echo cpmsFacilityAdminEscape($cancellation['review_notes']); ?></p></div><?php endif; ?></article><?php endforeach; ?>
    </div><?php endif; ?>
</section>

<section class="facility-admin-panel" id="facility-settings">
    <div class="facility-admin-panel-head"><div><span class="facility-admin-label">FACILITY DIRECTORY</span><h2><?php echo $editFacility ? 'Edit Facility' : 'Add Facility'; ?></h2></div><?php if ($editFacility): ?><a href="facilities.php?status=<?php echo rawurlencode($selectedStatus); ?>#facility-settings">+ Fasiliti Baharu</a><?php endif; ?></div>
    <form method="post" class="facility-admin-form"><input type="hidden" name="csrf_token" value="<?php echo cpmsFacilityAdminEscape(propertyPortalCsrfToken()); ?>"><input type="hidden" name="action" value="save_facility"><input type="hidden" name="facility_id" value="<?php echo (int) ($editFacility['id'] ?? 0); ?>"><input type="hidden" name="return_status" value="<?php echo cpmsFacilityAdminEscape($selectedStatus); ?>"><input type="hidden" name="return_anchor" value="facility-settings">
        <label>Nama fasiliti *<input name="facility_name" maxlength="150" value="<?php echo cpmsFacilityAdminEscape($editFacility['facility_name'] ?? ''); ?>" required></label><label>Lokasi<input name="location" maxlength="180" value="<?php echo cpmsFacilityAdminEscape($editFacility['location'] ?? ''); ?>"></label><label class="facility-admin-form-wide">Penerangan<textarea name="description" maxlength="500" rows="3"><?php echo cpmsFacilityAdminEscape($editFacility['description'] ?? ''); ?></textarea></label><label>Kapasiti<input type="number" name="capacity" min="0" value="<?php echo (int) ($editFacility['capacity'] ?? 0); ?>"></label><label>Sela slot (minit)<input type="number" name="slot_minutes" min="5" max="480" step="5" value="<?php echo (int) ($editFacility['slot_minutes'] ?? 60); ?>" required></label><label>Waktu buka<input type="time" name="opening_time" value="<?php echo cpmsFacilityAdminEscape(substr((string) ($editFacility['opening_time'] ?? '08:00'), 0, 5)); ?>" required></label><label>Waktu tutup<input type="time" name="closing_time" value="<?php echo cpmsFacilityAdminEscape(substr((string) ($editFacility['closing_time'] ?? '22:00'), 0, 5)); ?>" required></label><label>Fi tempahan (RM)<input type="number" name="booking_fee" min="0" step="0.01" value="<?php echo cpmsFacilityAdminEscape($editFacility['booking_fee'] ?? '0.00'); ?>"></label><label>Deposit (RM)<input type="number" name="deposit_amount" min="0" step="0.01" value="<?php echo cpmsFacilityAdminEscape($editFacility['deposit_amount'] ?? '0.00'); ?>"></label><label>Had tempahan awal (hari)<input type="number" name="advance_days" min="0" max="365" value="<?php echo (int) ($editFacility['advance_days'] ?? 30); ?>"></label><div class="facility-admin-checks"><label><input type="checkbox" name="approval_required" value="1" <?php echo !isset($editFacility['approval_required']) || (int) $editFacility['approval_required'] === 1 ? 'checked' : ''; ?>> Kelulusan pengurusan diperlukan</label><label><input type="checkbox" name="active" value="1" <?php echo !isset($editFacility['active']) || (int) $editFacility['active'] === 1 ? 'checked' : ''; ?>> Fasiliti aktif</label></div><button class="facility-admin-save" type="submit"><?php echo $editFacility ? 'Simpan Perubahan' : 'Tambah Fasiliti'; ?></button>
    </form>
    <div class="facility-admin-directory"><?php foreach ($facilities as $facility): ?><article><div><span class="facility-admin-status <?php echo (int) $facility['active'] === 1 ? 'status-approved' : 'status-cancelled'; ?>"><?php echo (int) $facility['active'] === 1 ? 'Active' : 'Inactive'; ?></span><h3><?php echo cpmsFacilityAdminEscape($facility['facility_name']); ?></h3><p><?php echo cpmsFacilityAdminEscape($facility['location'] ?: '-'); ?> · <?php echo cpmsFacilityAdminEscape(substr((string) $facility['opening_time'], 0, 5)); ?>–<?php echo cpmsFacilityAdminEscape(substr((string) $facility['closing_time'], 0, 5)); ?> · <?php echo (int) $facility['capacity']; ?> orang</p></div><a href="facilities.php?status=<?php echo rawurlencode($selectedStatus); ?>&amp;edit=<?php echo (int) $facility['id']; ?>#facility-settings">Edit</a></article><?php endforeach; ?></div>
</section>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
