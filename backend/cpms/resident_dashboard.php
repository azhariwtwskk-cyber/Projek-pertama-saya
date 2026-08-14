<?php
declare(strict_types=1);

require_once __DIR__ . '/cpms/includes/resident_session.php';
cpmsResidentSessionStart();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cpms/includes/resident_portal_service.php';

$resident = cpmsResidentRequire($conn);
$propertyId = (int) $resident['property_id'];
$residentId = (int) $resident['id'];

function residentDashboardEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function residentDashboardColour($value, string $fallback): string
{
    $value = trim((string) $value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

function residentDashboardAssetUrl($value): string
{
    $path = trim(str_replace('\\', '/', (string) $value));
    if ($path === '' || strpos($path, '..') !== false || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
        return '';
    }
    return '../' . ltrim($path, '/');
}

function residentDashboardInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : 'R';
}

$requestedLanguage = strtolower(trim((string) ($_GET['lang'] ?? '')));
if (in_array($requestedLanguage, ['ms', 'en'], true)) {
    $_SESSION['resident_dashboard_language'] = $requestedLanguage;
}
$language = (string) ($_SESSION['resident_dashboard_language'] ?? 'ms');
if (!in_array($language, ['ms', 'en'], true)) {
    $language = 'ms';
}

$translations = [
    'ms' => [
        'page_title' => 'Dashboard Resident | CPMS',
        'portal' => 'Portal Resident',
        'dashboard' => 'Dashboard',
        'requests' => 'Permohonan',
        'facilities' => 'Fasiliti',
        'visitors' => 'Pelawat',
        'notifications' => 'Notifikasi',
        'profile' => 'Profil',
        'logout' => 'Log keluar',
        'greeting' => 'Selamat datang kembali',
        'hero_text' => 'Urus servis kediaman, tempahan fasiliti dan pelawat anda dalam satu portal.',
        'active_account' => 'Akaun aktif',
        'block' => 'Blok',
        'unit' => 'Unit',
        'new_request' => 'Permohonan baharu',
        'view_notices' => 'Lihat pengumuman',
        'active_requests' => 'Permohonan aktif',
        'active_notices' => 'Pengumuman aktif',
        'active_passes' => 'Pas pelawat aktif',
        'needs_attention' => 'Perlu perhatian anda',
        'latest_property_info' => 'Maklumat terkini property',
        'expected_visitors' => 'Dijangka atau di dalam premis',
        'quick_actions' => 'Tindakan pantas',
        'quick_actions_text' => 'Akses perkhidmatan yang paling kerap digunakan.',
        'service_request' => 'Mohon servis',
        'service_request_text' => 'Hantar permohonan bantuan atau pembaikan.',
        'book_facility' => 'Tempah fasiliti',
        'book_facility_text' => 'Semak jadual dan buat tempahan baharu.',
        'register_visitor' => 'Daftar pelawat',
        'register_visitor_text' => 'Sediakan pas QR sebelum pelawat tiba.',
        'official_complaint' => 'Aduan rasmi',
        'official_complaint_text' => 'Hantar aduan rasmi kepada pengurusan.',
        'recent_requests' => 'Permohonan terkini',
        'view_all' => 'Lihat semua',
        'no_requests' => 'Belum ada permohonan servis.',
        'create_first_request' => 'Buat permohonan pertama',
        'announcements' => 'Pengumuman',
        'no_announcements' => 'Tiada pengumuman aktif buat masa ini.',
        'open_announcements' => 'Buka pengumuman',
        'resident_help' => 'Perlukan bantuan?',
        'resident_help_text' => 'Hubungi pengurusan property atau hantar permohonan servis melalui portal.',
        'today' => 'Hari ini',
        'footer' => 'Portal Resident CPMS',
        'open' => 'Buka',
        'language' => 'Bahasa',
        'main_navigation' => 'Navigasi utama',
    ],
    'en' => [
        'page_title' => 'Resident Dashboard | CPMS',
        'portal' => 'Resident Portal',
        'dashboard' => 'Dashboard',
        'requests' => 'Requests',
        'facilities' => 'Facilities',
        'visitors' => 'Visitors',
        'notifications' => 'Notifications',
        'profile' => 'Profile',
        'logout' => 'Log out',
        'greeting' => 'Welcome back',
        'hero_text' => 'Manage residential services, facility bookings and visitors from one portal.',
        'active_account' => 'Active account',
        'block' => 'Block',
        'unit' => 'Unit',
        'new_request' => 'New request',
        'view_notices' => 'View announcements',
        'active_requests' => 'Active requests',
        'active_notices' => 'Active announcements',
        'active_passes' => 'Active visitor passes',
        'needs_attention' => 'Requires your attention',
        'latest_property_info' => 'Latest property information',
        'expected_visitors' => 'Expected or currently on site',
        'quick_actions' => 'Quick actions',
        'quick_actions_text' => 'Access your most frequently used services.',
        'service_request' => 'Request service',
        'service_request_text' => 'Submit a maintenance or assistance request.',
        'book_facility' => 'Book a facility',
        'book_facility_text' => 'Check availability and make a booking.',
        'register_visitor' => 'Register visitor',
        'register_visitor_text' => 'Prepare a QR pass before your visitor arrives.',
        'official_complaint' => 'Official complaint',
        'official_complaint_text' => 'Submit an official complaint to management.',
        'recent_requests' => 'Recent requests',
        'view_all' => 'View all',
        'no_requests' => 'No service requests yet.',
        'create_first_request' => 'Create your first request',
        'announcements' => 'Announcements',
        'no_announcements' => 'There are no active announcements at the moment.',
        'open_announcements' => 'Open announcements',
        'resident_help' => 'Need assistance?',
        'resident_help_text' => 'Contact property management or submit a service request through the portal.',
        'today' => 'Today',
        'footer' => 'CPMS Resident Portal',
        'open' => 'Open',
        'language' => 'Language',
        'main_navigation' => 'Main navigation',
    ],
];
$copy = $translations[$language];
$text = static function (string $key) use ($copy): string {
    return (string) ($copy[$key] ?? $key);
};

$property = [
    'property_code' => '',
    'property_name' => 'CPMS Property',
    'tagline' => '',
    'logo_path' => '',
    'primary_color' => '#12345b',
    'secondary_color' => '#d6a84b',
];
$statement = $conn->prepare(
    'SELECT property_code, property_name, tagline, logo_path, primary_color, secondary_color
     FROM cpms_properties
     WHERE id = ? AND is_active = 1
     LIMIT 1'
);
if ($statement) {
    $statement->bind_param('i', $propertyId);
    $statement->execute();
    $propertyRow = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (is_array($propertyRow)) {
        $property = array_merge($property, $propertyRow);
    }
}

$openRequests = 0;
$activeAnnouncements = 0;
$activeVisitors = 0;
$recentRequests = [];
$recentAnnouncements = [];

$statement = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM cpms_resident_service_requests
     WHERE property_id = ? AND resident_id = ?
       AND status NOT IN ('Closed', 'Cancelled')"
);
if ($statement) {
    $statement->bind_param('ii', $propertyId, $residentId);
    $statement->execute();
    $openRequests = (int) ($statement->get_result()->fetch_assoc()['total'] ?? 0);
    $statement->close();
}

$statement = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM cpms_resident_announcements
     WHERE property_id = ? AND status = 'Published'
       AND publish_from <= NOW()
       AND (publish_until IS NULL OR publish_until >= NOW())"
);
if ($statement) {
    $statement->bind_param('i', $propertyId);
    $statement->execute();
    $activeAnnouncements = (int) ($statement->get_result()->fetch_assoc()['total'] ?? 0);
    $statement->close();
}

$statement = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM cpms_visitor_passes
     WHERE property_id = ? AND resident_id = ?
       AND visit_date >= CURDATE()
       AND visitor_status IN ('Expected', 'Checked In')"
);
if ($statement) {
    $statement->bind_param('ii', $propertyId, $residentId);
    $statement->execute();
    $activeVisitors = (int) ($statement->get_result()->fetch_assoc()['total'] ?? 0);
    $statement->close();
}

$statement = $conn->prepare(
    'SELECT id, request_reference, subject, status, submitted_at
     FROM cpms_resident_service_requests
     WHERE property_id = ? AND resident_id = ?
     ORDER BY id DESC
     LIMIT 3'
);
if ($statement) {
    $statement->bind_param('ii', $propertyId, $residentId);
    $statement->execute();
    $recentRequests = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
}

$statement = $conn->prepare(
    "SELECT id, title, notice_type, publish_from
     FROM cpms_resident_announcements
     WHERE property_id = ? AND status = 'Published'
       AND publish_from <= NOW()
       AND (publish_until IS NULL OR publish_until >= NOW())
     ORDER BY CASE WHEN notice_type = 'Emergency' THEN 0 ELSE 1 END, publish_from DESC
     LIMIT 3"
);
if ($statement) {
    $statement->bind_param('i', $propertyId);
    $statement->execute();
    $recentAnnouncements = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
}

$statusLabels = [
    'ms' => [
        'Pending' => 'Menunggu',
        'Approved' => 'Diluluskan',
        'In Progress' => 'Dalam tindakan',
        'Closed' => 'Selesai',
        'Cancelled' => 'Dibatalkan',
    ],
    'en' => [
        'Pending' => 'Pending',
        'Approved' => 'Approved',
        'In Progress' => 'In progress',
        'Closed' => 'Completed',
        'Cancelled' => 'Cancelled',
    ],
];
$months = [
    'ms' => [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'],
    'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
];
$currentDate = date('j') . ' ' . $months[$language][(int) date('n')] . ' ' . date('Y');
$primaryColour = residentDashboardColour($property['primary_color'], '#12345b');
$secondaryColour = residentDashboardColour($property['secondary_color'], '#d6a84b');
$logoUrl = residentDashboardAssetUrl($property['logo_path']);
$residentName = trim((string) ($resident['full_name'] ?? 'Resident'));
$blockName = trim((string) ($resident['block_name'] ?? ''));
$unitNumber = trim((string) ($resident['unit_no'] ?? '-'));
?>
<!doctype html>
<html lang="<?php echo residentDashboardEscape($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?php echo residentDashboardEscape($primaryColour); ?>">
    <title><?php echo residentDashboardEscape($text('page_title')); ?></title>
    <link rel="stylesheet" href="resident-premium-dashboard.css?v=3608">
    <link rel="stylesheet" href="assets/genesis/resident-genesis.css?v=4.0.0">
</head>
<body class="resident-premium-page" style="--property-primary:<?php echo residentDashboardEscape($primaryColour); ?>;--property-secondary:<?php echo residentDashboardEscape($secondaryColour); ?>">
<header class="resident-topbar">
    <div class="resident-shell resident-topbar-inner">
        <a class="resident-brand" href="resident_dashboard.php" aria-label="<?php echo residentDashboardEscape($text('dashboard')); ?>">
            <span class="resident-brand-mark">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?php echo residentDashboardEscape($logoUrl); ?>" alt="">
                <?php else: ?>
                    <?php echo residentDashboardEscape(substr((string) $property['property_code'], 0, 2) ?: 'CP'); ?>
                <?php endif; ?>
            </span>
            <span class="resident-brand-copy">
                <strong><?php echo residentDashboardEscape($property['property_name']); ?></strong>
                <small><?php echo residentDashboardEscape($text('portal')); ?></small>
            </span>
        </a>
        <div class="resident-topbar-actions">
            <div class="resident-language" aria-label="<?php echo residentDashboardEscape($text('language')); ?>">
                <a class="<?php echo $language === 'ms' ? 'is-active' : ''; ?>" href="?lang=ms" lang="ms">BM</a>
                <a class="<?php echo $language === 'en' ? 'is-active' : ''; ?>" href="?lang=en" lang="en">EN</a>
            </div>
            <a class="resident-profile-chip" href="resident_profile.php">
                <span class="resident-avatar"><?php echo residentDashboardEscape(residentDashboardInitials($residentName)); ?></span>
                <span><strong><?php echo residentDashboardEscape($residentName); ?></strong><small><?php echo residentDashboardEscape(($blockName !== '' ? $blockName . ' · ' : '') . $text('unit') . ' ' . $unitNumber); ?></small></span>
            </a>
            <a class="resident-logout" href="resident_logout.php" title="<?php echo residentDashboardEscape($text('logout')); ?>" aria-label="<?php echo residentDashboardEscape($text('logout')); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M21 3v18h-8"/></svg>
            </a>
        </div>
    </div>
    <nav class="resident-main-nav" aria-label="<?php echo residentDashboardEscape($text('main_navigation')); ?>">
        <div class="resident-shell resident-nav-scroll">
            <a class="is-current" href="resident_dashboard.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-8 9 8v10h-6v-6H9v6H3z"/></svg><?php echo residentDashboardEscape($text('dashboard')); ?></a>
            <a href="resident_requests.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h4"/></svg><?php echo residentDashboardEscape($text('requests')); ?></a>
            <a href="facility_calendar.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v16H4zM8 3v4M16 3v4M4 10h16M8 14h2M14 14h2M8 18h2"/></svg><?php echo residentDashboardEscape($text('facilities')); ?></a>
            <a href="visitor_passes.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-3a6 6 0 0 1 12 0v3M17 8h5M19.5 5.5v5"/></svg><?php echo residentDashboardEscape($text('visitors')); ?></a>
            <a href="resident_notifications.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php echo residentDashboardEscape($text('notifications')); ?></a>
            <a href="resident_profile.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM3 22a9 9 0 0 1 18 0"/></svg><?php echo residentDashboardEscape($text('profile')); ?></a>
        </div>
    </nav>
</header>

<main>
    <section class="resident-hero">
        <div class="resident-hero-orb resident-hero-orb-one"></div>
        <div class="resident-hero-orb resident-hero-orb-two"></div>
        <div class="resident-shell resident-hero-inner">
            <div class="resident-hero-copy">
                <div class="resident-property-pill"><span></span><?php echo residentDashboardEscape($property['property_code'] ?: $text('portal')); ?></div>
                <p class="resident-eyebrow"><?php echo residentDashboardEscape($text('greeting')); ?></p>
                <h1><?php echo residentDashboardEscape($residentName); ?></h1>
                <p class="resident-hero-description"><?php echo residentDashboardEscape($text('hero_text')); ?></p>
                <div class="resident-meta-row">
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18M5 21V4h10v17M15 9h4v12M8 8h4M8 12h4M8 16h4"/></svg><?php echo residentDashboardEscape(($blockName !== '' ? $text('block') . ' ' . $blockName . ' · ' : '') . $text('unit') . ' ' . $unitNumber); ?></span>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v16H4zM8 3v4M16 3v4M4 10h16"/></svg><?php echo residentDashboardEscape($currentDate); ?></span>
                </div>
                <div class="resident-hero-actions">
                    <a class="resident-button resident-button-gold" href="resident_request_new.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><?php echo residentDashboardEscape($text('new_request')); ?></a>
                    <a class="resident-button resident-button-ghost" href="resident_announcements.php"><?php echo residentDashboardEscape($text('view_notices')); ?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M14 7l5 5-5 5"/></svg></a>
                </div>
            </div>
            <aside class="resident-welcome-card">
                <div class="resident-welcome-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7zM8.5 12l2.2 2.2 4.8-5"/></svg></div>
                <span class="resident-status-dot"></span>
                <small><?php echo residentDashboardEscape($text('active_account')); ?></small>
                <strong><?php echo residentDashboardEscape(($blockName !== '' ? $blockName . ' / ' : '') . $unitNumber); ?></strong>
                <?php if (trim((string) $property['tagline']) !== ''): ?><p><?php echo residentDashboardEscape($property['tagline']); ?></p><?php endif; ?>
            </aside>
        </div>
    </section>

    <div class="resident-shell resident-dashboard-content">
        <section class="resident-stat-grid" aria-label="Dashboard summary">
            <a class="resident-stat-card resident-stat-blue" href="resident_requests.php">
                <span class="resident-stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h4"/></svg></span>
                <span class="resident-stat-content"><small><?php echo residentDashboardEscape($text('active_requests')); ?></small><strong><?php echo $openRequests; ?></strong><em><?php echo residentDashboardEscape($text('needs_attention')); ?></em></span>
                <span class="resident-card-arrow">→</span>
            </a>
            <a class="resident-stat-card resident-stat-gold" href="resident_announcements.php">
                <span class="resident-stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13V7l14-4v14L4 13zM4 13l2 7h4l-1.5-6M18 8h3M19 4l2-2M19 16l2 2"/></svg></span>
                <span class="resident-stat-content"><small><?php echo residentDashboardEscape($text('active_notices')); ?></small><strong><?php echo $activeAnnouncements; ?></strong><em><?php echo residentDashboardEscape($text('latest_property_info')); ?></em></span>
                <span class="resident-card-arrow">→</span>
            </a>
            <a class="resident-stat-card resident-stat-green" href="visitor_passes.php">
                <span class="resident-stat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-3a6 6 0 0 1 12 0v3M16 12h6v8h-6zM18 12V9h2v3"/></svg></span>
                <span class="resident-stat-content"><small><?php echo residentDashboardEscape($text('active_passes')); ?></small><strong><?php echo $activeVisitors; ?></strong><em><?php echo residentDashboardEscape($text('expected_visitors')); ?></em></span>
                <span class="resident-card-arrow">→</span>
            </a>
        </section>

        <section class="resident-section resident-quick-section">
            <div class="resident-section-heading"><div><p><?php echo residentDashboardEscape($text('portal')); ?></p><h2><?php echo residentDashboardEscape($text('quick_actions')); ?></h2><span><?php echo residentDashboardEscape($text('quick_actions_text')); ?></span></div></div>
            <div class="resident-quick-grid">
                <a class="resident-quick-card" href="resident_request_new.php"><span class="resident-quick-icon resident-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span><span><strong><?php echo residentDashboardEscape($text('service_request')); ?></strong><small><?php echo residentDashboardEscape($text('service_request_text')); ?></small></span><b>→</b></a>
                <a class="resident-quick-card" href="facility_booking.php"><span class="resident-quick-icon resident-icon-purple"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v16H4zM8 3v4M16 3v4M4 10h16M8 15l2 2 5-5"/></svg></span><span><strong><?php echo residentDashboardEscape($text('book_facility')); ?></strong><small><?php echo residentDashboardEscape($text('book_facility_text')); ?></small></span><b>→</b></a>
                <a class="resident-quick-card" href="visitor_pre_register.php"><span class="resident-quick-icon resident-icon-green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-3a6 6 0 0 1 12 0v3M17 8h5M19.5 5.5v5"/></svg></span><span><strong><?php echo residentDashboardEscape($text('register_visitor')); ?></strong><small><?php echo residentDashboardEscape($text('register_visitor_text')); ?></small></span><b>→</b></a>
                <a class="resident-quick-card" href="../complaint_form.php"><span class="resident-quick-icon resident-icon-red"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l10 18H2zM12 9v5M12 18h.01"/></svg></span><span><strong><?php echo residentDashboardEscape($text('official_complaint')); ?></strong><small><?php echo residentDashboardEscape($text('official_complaint_text')); ?></small></span><b>→</b></a>
            </div>
        </section>

        <div class="resident-information-grid">
            <section class="resident-panel">
                <div class="resident-panel-heading"><div><p><?php echo residentDashboardEscape($text('today')); ?></p><h2><?php echo residentDashboardEscape($text('recent_requests')); ?></h2></div><a href="resident_requests.php"><?php echo residentDashboardEscape($text('view_all')); ?> →</a></div>
                <div class="resident-activity-list">
                    <?php if (!$recentRequests): ?>
                        <div class="resident-empty"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6zM9 8h6M9 12h6M9 16h4"/></svg></span><strong><?php echo residentDashboardEscape($text('no_requests')); ?></strong><a href="resident_request_new.php"><?php echo residentDashboardEscape($text('create_first_request')); ?></a></div>
                    <?php endif; ?>
                    <?php foreach ($recentRequests as $request): ?>
                        <?php $status = (string) ($request['status'] ?? 'Pending'); ?>
                        <a class="resident-activity" href="resident_request_view.php?id=<?php echo (int) $request['id']; ?>">
                            <span class="resident-activity-mark"></span>
                            <span class="resident-activity-copy"><strong><?php echo residentDashboardEscape($request['subject']); ?></strong><small><?php echo residentDashboardEscape($request['request_reference']); ?> · <?php echo residentDashboardEscape(date('d/m/Y', strtotime((string) $request['submitted_at']))); ?></small></span>
                            <span class="resident-status resident-status-<?php echo residentDashboardEscape(strtolower(str_replace(' ', '-', $status))); ?>"><?php echo residentDashboardEscape($statusLabels[$language][$status] ?? $status); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="resident-panel">
                <div class="resident-panel-heading"><div><p><?php echo residentDashboardEscape($property['property_name']); ?></p><h2><?php echo residentDashboardEscape($text('announcements')); ?></h2></div><a href="resident_announcements.php"><?php echo residentDashboardEscape($text('view_all')); ?> →</a></div>
                <div class="resident-notice-list">
                    <?php if (!$recentAnnouncements): ?>
                        <div class="resident-empty"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13V7l14-4v14L4 13zM4 13l2 7h4l-1.5-6"/></svg></span><strong><?php echo residentDashboardEscape($text('no_announcements')); ?></strong><a href="resident_announcements.php"><?php echo residentDashboardEscape($text('open_announcements')); ?></a></div>
                    <?php endif; ?>
                    <?php foreach ($recentAnnouncements as $announcement): ?>
                        <?php $isEmergency = (string) $announcement['notice_type'] === 'Emergency'; ?>
                        <a class="resident-notice <?php echo $isEmergency ? 'is-emergency' : ''; ?>" href="resident_announcements.php">
                            <span class="resident-notice-date"><strong><?php echo residentDashboardEscape(date('d', strtotime((string) $announcement['publish_from']))); ?></strong><small><?php echo residentDashboardEscape(substr($months[$language][(int) date('n', strtotime((string) $announcement['publish_from']))], 0, 3)); ?></small></span>
                            <span><em><?php echo residentDashboardEscape($isEmergency ? ($language === 'ms' ? 'Kecemasan' : 'Emergency') : ($language === 'ms' ? 'Makluman' : 'Notice')); ?></em><strong><?php echo residentDashboardEscape($announcement['title']); ?></strong></span>
                            <b>→</b>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="resident-help-banner">
            <span class="resident-help-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM9.5 9a2.5 2.5 0 1 1 3.3 2.4c-.8.3-.8 1.1-.8 1.6M12 17h.01"/></svg></span>
            <div><strong><?php echo residentDashboardEscape($text('resident_help')); ?></strong><p><?php echo residentDashboardEscape($text('resident_help_text')); ?></p></div>
            <a href="resident_request_new.php"><?php echo residentDashboardEscape($text('service_request')); ?> →</a>
        </section>
    </div>
</main>

<footer class="resident-footer">
    <div class="resident-shell"><span>© <?php echo date('Y'); ?> <?php echo residentDashboardEscape($property['property_name']); ?></span><span><?php echo residentDashboardEscape($text('footer')); ?></span><a href="resident_logout.php"><?php echo residentDashboardEscape($text('logout')); ?></a></div>
</footer>
</body>
</html>
