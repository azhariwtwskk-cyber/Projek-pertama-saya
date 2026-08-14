<?php
declare(strict_types=1);

function cpmsVisitorEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsVisitorLanguage(): string
{
    $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
    if (in_array($requested, ['bm', 'en'], true)) {
        $_SESSION['cpms_visitor_language'] = $requested;
    }
    $language = (string) ($_SESSION['cpms_visitor_language'] ?? 'bm');
    return in_array($language, ['bm', 'en'], true) ? $language : 'bm';
}

function cpmsVisitorText(string $key): string
{
    static $copy = [
        'bm' => [
            'portal' => 'PORTAL RESIDENT CPMS',
            'title' => 'Pra-Pendaftaran Pelawat',
            'intro' => 'Daftar pelawat lebih awal untuk mempercepat pemeriksaan di pondok pengawal.',
            'dashboard' => 'Dashboard',
            'register' => 'Daftar Pelawat',
            'passes' => 'Pas Pelawat Saya',
            'notifications' => 'Notifikasi',
            'visitor_name' => 'Nama pelawat',
            'visitor_phone' => 'Nombor telefon',
            'vehicle_no' => 'Nombor kenderaan',
            'visit_date' => 'Tarikh lawatan',
            'start_time' => 'Jangkaan tiba',
            'end_time' => 'Jangkaan keluar',
            'purpose' => 'Tujuan lawatan',
            'notes' => 'Arahan kepada Security',
            'submit' => 'Daftar & Jana Pas',
            'privacy' => 'Jangan masukkan nombor IC penuh. Security akan membuat pengesahan semasa ketibaan.',
            'my_title' => 'Pas Pelawat Saya',
            'my_intro' => 'Tunjukkan kod pas atau QR kepada Security semasa ketibaan.',
            'new_pass' => 'Pas Baharu',
            'no_passes' => 'Belum ada pas pelawat.',
            'pass_code' => 'Kod Pas',
            'qr_pass' => 'QR Pas Pelawat',
            'qr_instruction' => 'Security imbas QR ini untuk membuka rekod. Check-in masih perlu disahkan oleh Security.',
            'status' => 'Status',
            'cancel' => 'Batalkan Pas',
            'cancel_confirm' => 'Batalkan pas pelawat ini?',
            'checked_in' => 'Daftar masuk',
            'checked_out' => 'Daftar keluar',
            'expected' => 'Dijangka',
            'footer' => 'Portal Resident CPMS',
            'logout' => 'Log Keluar Resident',
        ],
        'en' => [
            'portal' => 'CPMS RESIDENT PORTAL',
            'title' => 'Visitor Pre-Registration',
            'intro' => 'Register visitors in advance for faster verification at the guard house.',
            'dashboard' => 'Dashboard',
            'register' => 'Register Visitor',
            'passes' => 'My Visitor Passes',
            'notifications' => 'Notifications',
            'visitor_name' => 'Visitor name',
            'visitor_phone' => 'Phone number',
            'vehicle_no' => 'Vehicle number',
            'visit_date' => 'Visit date',
            'start_time' => 'Expected arrival',
            'end_time' => 'Expected departure',
            'purpose' => 'Visit purpose',
            'notes' => 'Instructions for Security',
            'submit' => 'Register & Generate Pass',
            'privacy' => 'Do not enter a full identity-card number. Security will verify the visitor upon arrival.',
            'my_title' => 'My Visitor Passes',
            'my_intro' => 'Share the pass code or QR with Security upon arrival.',
            'new_pass' => 'New Pass',
            'no_passes' => 'There are no visitor passes yet.',
            'pass_code' => 'Pass Code',
            'qr_pass' => 'Visitor Pass QR',
            'qr_instruction' => 'Security scans this QR to open the record. Check-in still requires Security confirmation.',
            'status' => 'Status',
            'cancel' => 'Cancel Pass',
            'cancel_confirm' => 'Cancel this visitor pass?',
            'checked_in' => 'Checked in',
            'checked_out' => 'Checked out',
            'expected' => 'Expected',
            'footer' => 'CPMS Resident Portal',
            'logout' => 'Resident Logout',
        ],
    ];
    $language = cpmsVisitorLanguage();
    return (string) ($copy[$language][$key] ?? $copy['bm'][$key] ?? $key);
}

function cpmsVisitorUrl(string $path, array $parameters = []): string
{
    $parameters = array_merge(['lang' => cpmsVisitorLanguage()], $parameters);
    return $path . '?' . http_build_query($parameters);
}

function cpmsVisitorCsrfToken(): string
{
    if (empty($_SESSION['cpms_visitor_csrf'])) {
        $_SESSION['cpms_visitor_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_visitor_csrf'];
}

function cpmsVisitorVerifyCsrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(cpmsVisitorCsrfToken(), $token)) {
        throw new RuntimeException(
            cpmsVisitorLanguage() === 'en'
                ? 'The security session is invalid. Refresh and try again.'
                : 'Sesi keselamatan tidak sah. Muat semula dan cuba lagi.'
        );
    }
}

function cpmsVisitorFlash(string $type, string $message): void
{
    $_SESSION['cpms_visitor_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsVisitorPullFlash(): ?array
{
    $flash = $_SESSION['cpms_visitor_flash'] ?? null;
    unset($_SESSION['cpms_visitor_flash']);
    return is_array($flash) ? $flash : null;
}

function cpmsVisitorExecute(mysqli_stmt $stmt, string $message): void
{
    if (!$stmt->execute()) {
        throw new RuntimeException($message);
    }
}

function cpmsVisitorStatusLabel(string $status): string
{
    $labels = [
        'bm' => [
            'Expected' => 'Dijangka',
            'Checked In' => 'Sudah Masuk',
            'Checked Out' => 'Sudah Keluar',
            'Cancelled' => 'Dibatalkan',
            'Denied' => 'Ditolak',
            'Expired' => 'Tamat',
        ],
        'en' => [
            'Expected' => 'Expected',
            'Checked In' => 'Checked In',
            'Checked Out' => 'Checked Out',
            'Cancelled' => 'Cancelled',
            'Denied' => 'Denied',
            'Expired' => 'Expired',
        ],
    ];
    $language = cpmsVisitorLanguage();
    return (string) ($labels[$language][$status] ?? $status);
}
