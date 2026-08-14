<?php
declare(strict_types=1);

/*
 * CPMS v3.5.8 — shared resident facility portal helpers.
 * This file is intentionally PHP 7.4 compatible.
 */

function cpmsFacilityEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsFacilityLanguage(): string
{
    $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
    if (in_array($requested, ['bm', 'en'], true)) {
        $_SESSION['cpms_facility_language'] = $requested;
    }

    $language = (string) ($_SESSION['cpms_facility_language'] ?? 'bm');
    return in_array($language, ['bm', 'en'], true) ? $language : 'bm';
}

function cpmsFacilityText(string $key): string
{
    static $copy = [
        'bm' => [
            'portal' => 'PORTAL RESIDENT CPMS',
            'dashboard' => 'Dashboard',
            'book' => 'Tempah',
            'my_bookings' => 'Tempahan Saya',
            'calendar' => 'Kalendar',
            'notifications' => 'Notifikasi',
            'logout' => 'Log Keluar Resident',
            'booking_title' => 'Tempahan Fasiliti',
            'booking_intro' => 'Pilih fasiliti dan slot yang sesuai untuk kediaman anda.',
            'new_booking' => 'Tempahan Baharu',
            'facility' => 'Fasiliti',
            'select_facility' => '-- Pilih fasiliti --',
            'date' => 'Tarikh',
            'guests' => 'Jumlah tetamu',
            'start' => 'Masa mula',
            'end' => 'Masa tamat',
            'purpose' => 'Tujuan tempahan',
            'purpose_hint' => 'Contoh: Majlis keluarga kecil',
            'submit' => 'Hantar Tempahan',
            'availability' => 'Maklumat Fasiliti',
            'hours' => 'Waktu operasi',
            'capacity' => 'Kapasiti',
            'people' => 'orang',
            'slot' => 'Tempoh slot',
            'minutes' => 'minit',
            'advance' => 'Had tempahan awal',
            'days' => 'hari',
            'fee' => 'Fi',
            'deposit' => 'Deposit',
            'approval_needed' => 'Memerlukan kelulusan pengurusan',
            'auto_approval' => 'Disahkan secara automatik jika slot tersedia',
            'none_available' => 'Belum ada fasiliti aktif untuk hartanah anda.',
            'my_title' => 'Tempahan Saya',
            'my_intro' => 'Semak status, bayaran dan permintaan pembatalan anda.',
            'no_bookings' => 'Anda belum mempunyai tempahan fasiliti.',
            'reference' => 'Rujukan',
            'status' => 'Status',
            'payment' => 'Bayaran',
            'cancel_request' => 'Mohon Pembatalan',
            'cancellation_pending' => 'Pembatalan menunggu kelulusan',
            'calendar_title' => 'Kalendar Fasiliti',
            'calendar_intro' => 'Lihat slot yang tidak tersedia tanpa mendedahkan maklumat resident.',
            'month' => 'Bulan',
            'all_facilities' => 'Semua fasiliti',
            'show' => 'Papar',
            'no_slots' => 'Tiada slot ditempah bagi pilihan ini.',
            'occupied' => 'Tidak tersedia',
            'cancel_title' => 'Pembatalan Tempahan',
            'cancel_intro' => 'Semak tempahan sebelum menghantar permintaan.',
            'reason' => 'Alasan pembatalan',
            'reason_hint' => 'Nyatakan sebab pembatalan',
            'submit_cancel' => 'Hantar Pembatalan',
            'back' => 'Kembali',
            'approved_cancel_note' => 'Tempahan yang telah diluluskan memerlukan kelulusan Property Admin untuk dibatalkan.',
            'not_eligible' => 'Tempahan ini tidak lagi boleh dibatalkan.',
            'footer' => 'Portal Resident CPMS',
        ],
        'en' => [
            'portal' => 'CPMS RESIDENT PORTAL',
            'dashboard' => 'Dashboard',
            'book' => 'Book',
            'my_bookings' => 'My Bookings',
            'calendar' => 'Calendar',
            'notifications' => 'Notifications',
            'logout' => 'Resident Logout',
            'booking_title' => 'Facility Booking',
            'booking_intro' => 'Choose a facility and an available slot for your residence.',
            'new_booking' => 'New Booking',
            'facility' => 'Facility',
            'select_facility' => '-- Select facility --',
            'date' => 'Date',
            'guests' => 'Number of guests',
            'start' => 'Start time',
            'end' => 'End time',
            'purpose' => 'Booking purpose',
            'purpose_hint' => 'Example: Small family gathering',
            'submit' => 'Submit Booking',
            'availability' => 'Facility Information',
            'hours' => 'Operating hours',
            'capacity' => 'Capacity',
            'people' => 'people',
            'slot' => 'Slot duration',
            'minutes' => 'minutes',
            'advance' => 'Advance booking limit',
            'days' => 'days',
            'fee' => 'Fee',
            'deposit' => 'Deposit',
            'approval_needed' => 'Management approval required',
            'auto_approval' => 'Automatically confirmed when the slot is available',
            'none_available' => 'There are no active facilities for your property yet.',
            'my_title' => 'My Bookings',
            'my_intro' => 'Review your status, payment and cancellation requests.',
            'no_bookings' => 'You do not have any facility bookings yet.',
            'reference' => 'Reference',
            'status' => 'Status',
            'payment' => 'Payment',
            'cancel_request' => 'Request Cancellation',
            'cancellation_pending' => 'Cancellation awaiting approval',
            'calendar_title' => 'Facility Calendar',
            'calendar_intro' => 'See unavailable slots without exposing resident information.',
            'month' => 'Month',
            'all_facilities' => 'All facilities',
            'show' => 'Show',
            'no_slots' => 'There are no booked slots for this selection.',
            'occupied' => 'Unavailable',
            'cancel_title' => 'Cancel Booking',
            'cancel_intro' => 'Review the booking before submitting your request.',
            'reason' => 'Cancellation reason',
            'reason_hint' => 'State the reason for cancellation',
            'submit_cancel' => 'Submit Cancellation',
            'back' => 'Back',
            'approved_cancel_note' => 'An approved booking requires Property Admin approval before it can be cancelled.',
            'not_eligible' => 'This booking can no longer be cancelled.',
            'footer' => 'CPMS Resident Portal',
        ],
    ];

    $language = cpmsFacilityLanguage();
    return (string) ($copy[$language][$key] ?? $copy['bm'][$key] ?? $key);
}

function cpmsFacilityUrl(string $path, array $parameters = []): string
{
    $parameters = array_merge(['lang' => cpmsFacilityLanguage()], $parameters);
    return $path . '?' . http_build_query($parameters);
}

function cpmsFacilityCsrfToken(): string
{
    if (empty($_SESSION['cpms_facility_csrf'])) {
        $_SESSION['cpms_facility_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_facility_csrf'];
}

function cpmsFacilityVerifyCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(cpmsFacilityCsrfToken(), $token)) {
        throw new RuntimeException(
            cpmsFacilityLanguage() === 'en'
                ? 'The security session is invalid. Refresh the page and try again.'
                : 'Sesi keselamatan tidak sah. Muat semula halaman dan cuba lagi.'
        );
    }
}

function cpmsFacilityFlash(string $type, string $message): void
{
    $_SESSION['cpms_facility_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsFacilityPullFlash(): ?array
{
    $flash = $_SESSION['cpms_facility_flash'] ?? null;
    unset($_SESSION['cpms_facility_flash']);
    return is_array($flash) ? $flash : null;
}

function cpmsFacilityExecute(mysqli_stmt $stmt, string $message): void
{
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsFacilityStatusLabel(string $status): string
{
    $labels = [
        'bm' => [
            'Pending' => 'Menunggu',
            'Approved' => 'Diluluskan',
            'Rejected' => 'Ditolak',
            'Cancelled' => 'Dibatalkan',
        ],
        'en' => [
            'Pending' => 'Pending',
            'Approved' => 'Approved',
            'Rejected' => 'Rejected',
            'Cancelled' => 'Cancelled',
        ],
    ];
    $language = cpmsFacilityLanguage();
    return (string) ($labels[$language][$status] ?? $status);
}

function cpmsFacilityStatusClass(string $status): string
{
    $allowed = ['Pending', 'Approved', 'Rejected', 'Cancelled'];
    return in_array($status, $allowed, true)
        ? strtolower($status)
        : 'neutral';
}
