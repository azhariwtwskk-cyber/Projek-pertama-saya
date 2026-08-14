<?php
declare(strict_types=1);

// CPMSPro App Subdomain: use app.cpmspro.my as the unified system login.
$cpmsHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$cpmsHost = preg_replace('/:\d+$/', '', $cpmsHost);

if ($cpmsHost === 'app.cpmspro.my') {
    header('Location: /cpms/login.php');
    exit;
}

// Main commercial website. Property subdomains continue to the portal below.
if ($cpmsHost === 'cpmspro.my' || $cpmsHost === 'www.cpmspro.my') {
    require __DIR__ . '/cpmspro_home_v2.php';
    exit;
}

/*
 * CPMS v3.5.7 — Resident Registration & Account Approval
 * Upload to: /htdocs/index.php
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/cpms/db.php';
require_once __DIR__ . '/cpms/includes/system_settings.php';
require_once __DIR__ . '/cpms/core/branding.php';
require_once __DIR__ . '/cpms/includes/property_modules.php';

function cpmsHubEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsHubTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cpmsHubPropertyColumns(mysqli $conn): array
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM cpms_properties');

    if (!$result) {
        return $columns;
    }

    while ($row = $result->fetch_assoc()) {
        $name = (string) ($row['Field'] ?? '');

        if ($name !== '') {
            $columns[$name] = true;
        }
    }

    return $columns;
}

function cpmsHubLoadProperties(mysqli $conn): array
{
    if (!cpmsHubTableExists($conn, 'cpms_properties')) {
        return [];
    }

    $available = cpmsHubPropertyColumns($conn);
    $wanted = [
        'id',
        'property_code',
        'property_name',
        'system_name',
        'company_name',
        'tagline',
        'address',
        'phone',
        'email',
        'website',
        'logo_path',
        'favicon_path',
        'background_path',
        'dashboard_banner_path',
        'footer_text',
        'operating_hours',
        'show_cpms_branding',
        'primary_color',
        'secondary_color',
        'default_language',
        'is_active',
    ];
    $select = [];

    foreach ($wanted as $column) {
        if (isset($available[$column])) {
            $select[] = '`' . $column . '`';
        }
    }

    if (!isset($available['id']) || !isset($available['property_name'])) {
        return [];
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM cpms_properties';

    if (isset($available['is_active'])) {
        $sql .= ' WHERE is_active = 1';
    }

    $sql .= ' ORDER BY id ASC';
    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    $properties = [];

    while ($row = $result->fetch_assoc()) {
        $properties[] = $row;
    }

    return $properties;
}

function cpmsHubNormaliseHost(string $value): string
{
    $value = trim(strtolower($value));

    if ($value === '') {
        return '';
    }

    if (strpos($value, '://') === false) {
        $value = 'https://' . $value;
    }

    $host = (string) parse_url($value, PHP_URL_HOST);
    $host = preg_replace('/:\d+$/', '', $host);

    return preg_replace('/^www\./', '', (string) $host);
}

function cpmsHubResolveProperty(array $properties): array
{
    if (!$properties) {
        return [];
    }

    $requested = trim((string) ($_GET['property'] ?? ''));

    if ($requested !== '') {
        foreach ($properties as $property) {
            $id = (string) ($property['id'] ?? '');
            $code = (string) ($property['property_code'] ?? '');

            if (
                $requested === $id
                || strcasecmp($requested, $code) === 0
            ) {
                return $property;
            }
        }
    }

    $currentHost = cpmsHubNormaliseHost(
        (string) ($_SERVER['HTTP_HOST'] ?? '')
    );

    if ($currentHost !== '') {
        foreach ($properties as $property) {
            $propertyHost = cpmsHubNormaliseHost(
                (string) ($property['website'] ?? '')
            );

            if ($propertyHost !== '' && $propertyHost === $currentHost) {
                return $property;
            }
        }
    }

    return $properties[0];
}

function cpmsHubHex($value, string $fallback): string
{
    $value = trim((string) $value);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
        ? strtolower($value)
        : $fallback;
}

function cpmsHubFirstValue(array $values, string $fallback = ''): string
{
    foreach ($values as $value) {
        $value = trim((string) $value);

        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function cpmsHubContrastText(string $hex): string
{
    $hex = ltrim(cpmsHubHex($hex, '#ffffff'), '#');
    $red = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue = hexdec(substr($hex, 4, 2));
    $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

    return $brightness >= 150 ? '#10213d' : '#ffffff';
}

function cpmsHubUrl(string $path, array $parameters = []): string
{
    $parameters = array_filter(
        $parameters,
        static function ($value): bool {
            return $value !== null && $value !== '';
        }
    );

    return $path
        . ($parameters ? '?' . http_build_query($parameters) : '');
}

function cpmsHubNoticeSummary(mysqli $conn, int $propertyId): array
{
    $summary = ['total' => 0, 'emergency' => 0];

    if (
        $propertyId < 1
        || !cpmsHubTableExists($conn, 'cpms_resident_announcements')
    ) {
        return $summary;
    }

    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN notice_type = 'Emergency' THEN 1 ELSE 0 END)
                AS emergency
         FROM cpms_resident_announcements
         WHERE property_id = ?
           AND status = 'Published'
           AND publish_from <= NOW()
           AND (publish_until IS NULL OR publish_until >= NOW())"
    );

    if (!$stmt) {
        return $summary;
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'total' => (int) ($row['total'] ?? 0),
        'emergency' => (int) ($row['emergency'] ?? 0),
    ];
}

function cpmsHubIcon(string $name): string
{
    $icons = [
        'complaint' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/>',
        'track' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/><path d="M8 11h6"/><path d="m11 8 3 3-3 3"/>',
        'resident' => '<circle cx="12" cy="8" r="4"/><path d="M4 22a8 8 0 0 1 16 0"/>',
        'facility' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/><path d="M8 15h.01M12 15h.01M16 15h.01"/>',
        'visitor' => '<circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 14 0M19 8v6M16 11h6"/>',
        'announcement' => '<path d="m3 11 18-5v12L3 14z"/><path d="M11.6 16.4 13 21H8l-1.7-6"/>',
        'emergency' => '<path d="M12 3a5 5 0 0 0-5 5v3l-2 4h14l-2-4V8a5 5 0 0 0-5-5z"/><path d="M10 19h4"/><path d="M12 6v4"/>',
        'management' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/>',
        'contact' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15M15 6v15"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    $paths = $icons[$name] ?? $icons['shield'];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" '
        . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
        . 'stroke-linejoin="round">' . $paths . '</svg>';
}

$properties = cpmsHubLoadProperties($conn);
$currentProperty = cpmsHubResolveProperty($properties);
$currentPropertyId = (int) ($currentProperty['id'] ?? 0);
$currentPropertyCode = trim(
    (string) ($currentProperty['property_code'] ?? '')
);

try {
    $cpmsSettings = loadSystemSettings($conn);
} catch (Throwable $error) {
    $cpmsSettings = cpmsSystemSettingDefaults();
}

$GLOBALS['currentProperty'] = $currentProperty;
$GLOBALS['cpmsSettings'] = $cpmsSettings;

$defaultLanguage = strtolower(
    trim(
        (string) (
            $currentProperty['default_language']
            ?? $cpmsSettings['default_language']
            ?? 'ms'
        )
    )
);
$requestedLanguage = strtolower(trim((string) ($_GET['lang'] ?? '')));
$language = in_array($requestedLanguage, ['ms', 'en'], true)
    ? $requestedLanguage
    : (in_array($defaultLanguage, ['ms', 'en'], true)
        ? $defaultLanguage
        : 'ms');

$copy = [
    'ms' => [
        'home' => 'Utama',
        'services' => 'Perkhidmatan',
        'announcements' => 'Pengumuman',
        'contact' => 'Hubungi Kami',
        'eyebrow' => 'PORTAL PERKHIDMATAN RESIDENT',
        'hero_title' => 'Urusan Resident Kini Lebih Mudah',
        'hero_text' => 'Hantar aduan, semak status dan akses perkhidmatan pengurusan dalam satu portal.',
        'submit' => 'Hantar Aduan',
        'track' => 'Semak Status',
        'notice_emergency' => 'Terdapat notis kecemasan aktif untuk resident property ini.',
        'notice_regular' => 'Sila semak pengumuman terkini daripada pihak pengurusan.',
        'notice_emergency_label' => 'NOTIS KECEMASAN',
        'notice_important_label' => 'NOTIS PENTING',
        'login_to_read' => 'Log masuk untuk membaca',
        'services_title' => 'Perkhidmatan Utama',
        'services_text' => 'Pilih perkhidmatan yang anda perlukan.',
        'complaint_title' => 'Hantar Aduan',
        'complaint_text' => 'Laporkan kerosakan atau isu kepada pengurusan.',
        'track_title' => 'Track Aduan',
        'track_text' => 'Semak perkembangan menggunakan nombor rujukan.',
        'resident_title' => 'Resident Login',
        'resident_text' => 'Akses dashboard dan rekod resident anda.',
        'resident_register' => 'Daftar Resident',
        'resident_register_text' => 'Mohon akaun resident baharu untuk kelulusan pengurusan.',
        'facility_title' => 'Facility Booking',
        'facility_text' => 'Semak kalendar dan buat tempahan fasiliti.',
        'visitor_title' => 'Daftar Pelawat',
        'visitor_text' => 'Pra-daftar pelawat dan jana kod pas untuk Security.',
        'announcement_title' => 'Pengumuman',
        'announcement_text' => 'Baca makluman rasmi pihak pengurusan.',
        'emergency_title' => 'Notis Kecemasan',
        'emergency_text' => 'Semak notis penting dan sahkan telah dibaca.',
        'management_title' => 'Admin & Staff',
        'management_text' => 'Akses berasingan untuk pengurusan, staf dan security.',
        'contact_title' => 'Hubungi Kami',
        'contact_text' => 'Dapatkan nombor telefon dan alamat pejabat.',
        'active_notices' => 'Pengumuman Resident',
        'no_notice' => 'Tiada pengumuman aktif pada masa ini.',
        'notice_count' => '%d pengumuman aktif tersedia untuk resident.',
        'view_notices' => 'Lihat Pengumuman',
        'management_contact' => 'Maklumat Hubungan Pengurusan',
        'phone' => 'Telefon / WhatsApp',
        'address' => 'Alamat Pejabat',
        'hours' => 'Waktu Operasi',
        'not_set' => 'Belum ditetapkan',
        'resident_access' => 'Resident menggunakan portal login khas yang berasingan.',
        'management_access' => 'Pengurusan, staf dan security menggunakan Unified Login.',
    ],
    'en' => [
        'home' => 'Home',
        'services' => 'Services',
        'announcements' => 'Announcements',
        'contact' => 'Contact Us',
        'eyebrow' => 'RESIDENT SERVICE PORTAL',
        'hero_title' => 'Resident Services Made Easier',
        'hero_text' => 'Submit complaints, track progress and access management services through one portal.',
        'submit' => 'Submit Complaint',
        'track' => 'Track Status',
        'notice_emergency' => 'There is an active emergency notice for residents of this property.',
        'notice_regular' => 'Please check the latest announcement from the management office.',
        'notice_emergency_label' => 'EMERGENCY NOTICE',
        'notice_important_label' => 'IMPORTANT NOTICE',
        'login_to_read' => 'Sign in to read',
        'services_title' => 'Main Services',
        'services_text' => 'Choose the service you need.',
        'complaint_title' => 'Submit Complaint',
        'complaint_text' => 'Report a defect or issue to the management team.',
        'track_title' => 'Track Complaint',
        'track_text' => 'Check progress using your reference number.',
        'resident_title' => 'Resident Login',
        'resident_text' => 'Access your resident dashboard and records.',
        'resident_register' => 'Resident Registration',
        'resident_register_text' => 'Apply for a new resident account for management approval.',
        'facility_title' => 'Facility Booking',
        'facility_text' => 'View the calendar and book a facility.',
        'visitor_title' => 'Visitor Registration',
        'visitor_text' => 'Pre-register a visitor and generate a pass code for Security.',
        'announcement_title' => 'Announcements',
        'announcement_text' => 'Read official updates from management.',
        'emergency_title' => 'Emergency Notice',
        'emergency_text' => 'Review important notices and confirm they were read.',
        'management_title' => 'Admin & Staff',
        'management_text' => 'Separate access for management, staff and security.',
        'contact_title' => 'Contact Us',
        'contact_text' => 'Find the office telephone number and address.',
        'active_notices' => 'Resident Announcements',
        'no_notice' => 'There are no active announcements at this time.',
        'notice_count' => '%d active announcements are available to residents.',
        'view_notices' => 'View Announcements',
        'management_contact' => 'Management Contact Information',
        'phone' => 'Telephone / WhatsApp',
        'address' => 'Management Office',
        'hours' => 'Operating Hours',
        'not_set' => 'Not provided',
        'resident_access' => 'Residents use a dedicated and separate login portal.',
        'management_access' => 'Management, staff and security use Unified Login.',
    ],
];
$text = $copy[$language];

$propertyName = trim(
    (string) ($currentProperty['property_name'] ?? '')
);

if ($propertyName === '') {
    $propertyName = 'Property Resident Portal';
}

$companyName = cpmsHubFirstValue([
    $currentProperty['company_name'] ?? '',
    $cpmsSettings['company_name'] ?? '',
]);
$tagline = trim((string) ($currentProperty['tagline'] ?? ''));
$phone = cpmsHubFirstValue([
    $currentProperty['phone'] ?? '',
    $cpmsSettings['contact_phone'] ?? '',
]);
$address = cpmsHubFirstValue([
    $currentProperty['address'] ?? '',
    $cpmsSettings['address'] ?? '',
]);
$operatingHours = trim(
    (string) ($currentProperty['operating_hours'] ?? '')
);
$primaryColor = cpmsHubHex(
    cpmsHubFirstValue([
        $currentProperty['primary_color'] ?? '',
        $cpmsSettings['primary_color'] ?? '',
    ]),
    '#0f3563'
);
$secondaryColor = cpmsHubHex(
    cpmsHubFirstValue([
        $currentProperty['secondary_color'] ?? '',
        $cpmsSettings['secondary_color'] ?? '',
    ]),
    '#d7aa4b'
);
$onPrimaryColor = cpmsHubContrastText($primaryColor);
$onSecondaryColor = cpmsHubContrastText($secondaryColor);
$logoPath = cpmsHubFirstValue([
    $currentProperty['logo_path'] ?? '',
    $cpmsSettings['logo_path'] ?? '',
]);
$backgroundPath = cpmsHubFirstValue([
    $currentProperty['background_path'] ?? '',
    $currentProperty['dashboard_banner_path'] ?? '',
    $cpmsSettings['background_path'] ?? '',
]);

/*
 * The old single-property portal stored the V23 assets in these generic
 * paths. Never reuse them for another property when its own asset is empty.
 */
if (
    $currentPropertyId !== 1
    && strcasecmp($currentPropertyCode, 'V23') !== 0
) {
    $normalisedLogoPath = strtolower(ltrim(str_replace('\\', '/', $logoPath), '/'));
    $normalisedBackgroundPath = strtolower(
        ltrim(str_replace('\\', '/', $backgroundPath), '/')
    );

    if ($normalisedLogoPath === 'images/logo.png') {
        $logoPath = '';
    }

    if ($normalisedBackgroundPath === 'images/bg-premium.jpg') {
        $backgroundPath = '';
    }
}

$logoUrl = $logoPath !== '' ? cpmsAssetUrl($logoPath) : '';
$backgroundUrl = $backgroundPath !== ''
    ? cpmsAssetUrl($backgroundPath)
    : '';
$propertyParameters = [
    'property' => $currentPropertyCode,
    'lang' => $language,
];

$currentPropertyModules = $currentPropertyId > 0
    ? cpmsLoadPropertyModules($conn, $currentPropertyId)
    : cpmsModuleDefaults();
$complaintsEnabled = (bool) (
    $currentPropertyModules['complaints'] ?? true
);
$facilityEnabled = (bool) (
    $currentPropertyModules['facility_booking'] ?? false
);
$visitorEnabled = (bool) (
    $currentPropertyModules['visitor_management'] ?? false
);
$residentsEnabled = (bool) (
    $currentPropertyModules['residents'] ?? true
);
$noticeSummary = cpmsHubNoticeSummary($conn, $currentPropertyId);

$services = [
    [
        'visible' => $complaintsEnabled,
        'icon' => 'complaint',
        'title' => $text['complaint_title'],
        'description' => $text['complaint_text'],
        'url' => cpmsHubUrl('complaint_form.php', $propertyParameters),
        'class' => 'service-card--primary',
    ],
    [
        'visible' => $complaintsEnabled,
        'icon' => 'track',
        'title' => $text['track_title'],
        'description' => $text['track_text'],
        'url' => cpmsHubUrl('track_complaint.php', $propertyParameters),
        'class' => '',
    ],
    [
        'visible' => true,
        'icon' => 'resident',
        'title' => $text['resident_title'],
        'description' => $text['resident_text'],
        'url' => 'cpms/resident_login.php',
        'class' => '',
    ],
    [
        'visible' => $residentsEnabled,
        'icon' => 'resident',
        'title' => $text['resident_register'],
        'description' => $text['resident_register_text'],
        'url' => cpmsHubUrl('resident_register.php', $propertyParameters),
        'class' => 'service-card--primary',
    ],
    [
        'visible' => $facilityEnabled,
        'icon' => 'facility',
        'title' => $text['facility_title'],
        'description' => $text['facility_text'],
        'url' => 'cpms/facility_calendar.php',
        'class' => '',
    ],
    [
        'visible' => $visitorEnabled,
        'icon' => 'visitor',
        'title' => $text['visitor_title'],
        'description' => $text['visitor_text'],
        'url' => 'cpms/visitor_pre_register.php',
        'class' => '',
    ],
    [
        'visible' => true,
        'icon' => 'announcement',
        'title' => $text['announcement_title'],
        'description' => $text['announcement_text'],
        'url' => cpmsHubUrl('announcements.php', $propertyParameters),
        'class' => '',
    ],
    [
        'visible' => true,
        'icon' => 'emergency',
        'title' => $text['emergency_title'],
        'description' => $text['emergency_text'],
        'url' => cpmsHubUrl('announcements.php', $propertyParameters),
        'class' => 'service-card--emergency',
    ],
    [
        'visible' => true,
        'icon' => 'management',
        'title' => $text['management_title'],
        'description' => $text['management_text'],
        'url' => 'cpms/login.php',
        'class' => '',
    ],
    [
        'visible' => true,
        'icon' => 'contact',
        'title' => $text['contact_title'],
        'description' => $text['contact_text'],
        'url' => '#hubungi',
        'class' => '',
    ],
];

$bmLanguageParameters = [
    'property' => $currentPropertyCode,
    'lang' => 'ms',
];
$enLanguageParameters = [
    'property' => $currentPropertyCode,
    'lang' => 'en',
];
$pageTitle = $propertyName . ' | ' . $text['eyebrow'];
$pageDescription = $language === 'en'
    ? $propertyName . ' resident service portal for complaints, status tracking, announcements, visitor registration, facility booking and management contact information.'
    : $propertyName . ' resident service portal for complaints, status tracking, announcements, visitor registration, facility booking and management contact information.';
$footerText = trim((string) ($currentProperty['footer_text'] ?? ''));

if ($footerText === '') {
    $footerText = cpmsFooter();
}
?>
<!doctype html>
<html lang="<?php echo cpmsHubEscape($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo cpmsHubEscape($pageDescription); ?>">
    <meta name="theme-color" content="<?php echo cpmsHubEscape($primaryColor); ?>">
    <meta property="og:title" content="<?php echo cpmsHubEscape($pageTitle); ?>">
    <meta property="og:description" content="<?php echo cpmsHubEscape($pageDescription); ?>">
    <meta property="og:type" content="website">
    <title><?php echo cpmsHubEscape($pageTitle); ?></title>
    <?php if (!empty($currentProperty['favicon_path'])): ?>
        <link rel="icon" href="<?php echo cpmsHubEscape(cpmsAssetUrl((string) $currentProperty['favicon_path'])); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="css/cpms-resident-hub.css?v=3570">
    <style>
        :root {
            --hub-primary: <?php echo cpmsHubEscape($primaryColor); ?>;
            --hub-secondary: <?php echo cpmsHubEscape($secondaryColor); ?>;
            --hub-on-primary: <?php echo cpmsHubEscape($onPrimaryColor); ?>;
            --hub-on-secondary: <?php echo cpmsHubEscape($onSecondaryColor); ?>;
        }
    </style>
</head>
<body>
<a class="skip-link" href="#perkhidmatan">Skip to services</a>

<header class="site-header">
    <div class="hub-container header-inner">
        <a class="property-brand" href="<?php echo cpmsHubEscape(cpmsHubUrl('index.php', $propertyParameters)); ?>">
            <span class="brand-mark">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?php echo cpmsHubEscape($logoUrl); ?>" alt="<?php echo cpmsHubEscape($propertyName); ?>">
                <?php else: ?>
                    <strong>CP</strong>
                <?php endif; ?>
            </span>
            <span class="brand-copy">
                <strong><?php echo cpmsHubEscape($propertyName); ?></strong>
                <?php if ($companyName !== ''): ?>
                    <small><?php echo cpmsHubEscape($companyName); ?></small>
                <?php endif; ?>
            </span>
        </a>

        <nav id="main-navigation" class="main-navigation" aria-label="Main navigation">
            <a href="#utama"><?php echo cpmsHubEscape($text['home']); ?></a>
            <a href="#perkhidmatan"><?php echo cpmsHubEscape($text['services']); ?></a>
            <a href="#pengumuman"><?php echo cpmsHubEscape($text['announcements']); ?></a>
            <a href="#hubungi"><?php echo cpmsHubEscape($text['contact']); ?></a>
        </nav>

        <div class="header-tools">
            <div class="language-switch" role="group" aria-label="Pilihan bahasa / Language selection">
                <a class="<?php echo $language === 'ms' ? 'is-active' : ''; ?>"
                   href="<?php echo cpmsHubEscape(cpmsHubUrl('index.php', $bmLanguageParameters)); ?>"
                   <?php echo $language === 'ms' ? 'aria-current="page"' : ''; ?>>BM</a>
                <span aria-hidden="true">|</span>
                <a class="<?php echo $language === 'en' ? 'is-active' : ''; ?>"
                   href="<?php echo cpmsHubEscape(cpmsHubUrl('index.php', $enLanguageParameters)); ?>"
                   <?php echo $language === 'en' ? 'aria-current="page"' : ''; ?>>EN</a>
            </div>

            <button class="nav-toggle" type="button" aria-controls="main-navigation" aria-expanded="false">
                <span></span><span></span><span></span>
                <span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>

<main>
    <section id="utama" class="hero">
        <?php if ($backgroundUrl !== ''): ?>
            <img class="hero-background" src="<?php echo cpmsHubEscape($backgroundUrl); ?>" alt="">
        <?php endif; ?>
        <div class="hero-overlay"></div>
        <div class="hub-container hero-inner">
            <div class="hero-copy">
                <span class="eyebrow"><?php echo cpmsHubEscape($text['eyebrow']); ?></span>
                <h1><?php echo cpmsHubEscape($text['hero_title']); ?></h1>
                <p><?php echo cpmsHubEscape($text['hero_text']); ?></p>
                <?php if ($tagline !== ''): ?>
                    <p class="property-tagline"><?php echo cpmsHubEscape($tagline); ?></p>
                <?php endif; ?>
                <div class="hero-actions">
                    <?php if ($complaintsEnabled): ?>
                        <a class="hub-button hub-button--gold" href="<?php echo cpmsHubEscape(cpmsHubUrl('complaint_form.php', $propertyParameters)); ?>">
                            <?php echo cpmsHubIcon('complaint'); ?>
                            <?php echo cpmsHubEscape($text['submit']); ?>
                        </a>
                        <a class="hub-button hub-button--outline" href="<?php echo cpmsHubEscape(cpmsHubUrl('track_complaint.php', $propertyParameters)); ?>">
                            <?php echo cpmsHubIcon('track'); ?>
                            <?php echo cpmsHubEscape($text['track']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="access-panel" aria-label="Portal access information">
                <span class="access-icon"><?php echo cpmsHubIcon('shield'); ?></span>
                <h2><?php echo cpmsHubEscape($text['resident_title']); ?></h2>
                <p><?php echo cpmsHubEscape($text['resident_access']); ?></p>
                <a class="access-link" href="cpms/resident_login.php">
                    <?php echo cpmsHubEscape($text['resident_title']); ?>
                    <span aria-hidden="true">→</span>
                </a>
                <?php if ($residentsEnabled): ?>
                    <a class="access-link access-link--secondary"
                       href="<?php echo cpmsHubEscape(cpmsHubUrl('resident_register.php', $propertyParameters)); ?>">
                        <?php echo cpmsHubEscape($text['resident_register']); ?>
                        <span aria-hidden="true">＋</span>
                    </a>
                <?php endif; ?>
                <div class="access-divider"></div>
                <p class="access-small"><?php echo cpmsHubEscape($text['management_access']); ?></p>
            </aside>
        </div>
    </section>

    <section class="notice-strip <?php echo $noticeSummary['emergency'] > 0 ? 'notice-strip--emergency' : ''; ?>">
        <div class="hub-container notice-inner">
            <span class="notice-icon"><?php echo cpmsHubIcon($noticeSummary['emergency'] > 0 ? 'emergency' : 'announcement'); ?></span>
            <strong><?php echo cpmsHubEscape($noticeSummary['emergency'] > 0 ? $text['notice_emergency_label'] : $text['notice_important_label']); ?></strong>
            <p><?php echo cpmsHubEscape($noticeSummary['emergency'] > 0 ? $text['notice_emergency'] : $text['notice_regular']); ?></p>
            <a href="<?php echo cpmsHubEscape(cpmsHubUrl('announcements.php', $propertyParameters)); ?>"><?php echo cpmsHubEscape($text['view_notices']); ?> →</a>
        </div>
    </section>

    <section id="perkhidmatan" class="services-section">
        <div class="hub-container">
            <div class="section-heading">
                <span><?php echo cpmsHubEscape($text['eyebrow']); ?></span>
                <h2><?php echo cpmsHubEscape($text['services_title']); ?></h2>
                <p><?php echo cpmsHubEscape($text['services_text']); ?></p>
            </div>

            <div class="services-grid">
                <?php foreach ($services as $service): ?>
                    <?php if (!$service['visible']) { continue; } ?>
                    <a class="service-card <?php echo cpmsHubEscape($service['class']); ?>" href="<?php echo cpmsHubEscape($service['url']); ?>">
                        <span class="service-icon"><?php echo cpmsHubIcon($service['icon']); ?></span>
                        <span class="service-content">
                            <strong><?php echo cpmsHubEscape($service['title']); ?></strong>
                            <small><?php echo cpmsHubEscape($service['description']); ?></small>
                        </span>
                        <span class="service-arrow" aria-hidden="true">→</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="information-section">
        <div class="hub-container information-grid">
            <article id="pengumuman" class="information-card">
                <div class="information-title">
                    <span><?php echo cpmsHubIcon('announcement'); ?></span>
                    <h2><?php echo cpmsHubEscape($text['active_notices']); ?></h2>
                </div>
                <p>
                    <?php
                    echo cpmsHubEscape(
                        $noticeSummary['total'] > 0
                            ? sprintf($text['notice_count'], $noticeSummary['total'])
                            : $text['no_notice']
                    );
                    ?>
                </p>
                <a class="text-link" href="<?php echo cpmsHubEscape(cpmsHubUrl('announcements.php', $propertyParameters)); ?>">
                    <?php echo cpmsHubEscape($text['view_notices']); ?> →
                </a>
            </article>

            <article id="hubungi" class="information-card information-card--contact">
                <div class="information-title">
                    <span><?php echo cpmsHubIcon('contact'); ?></span>
                    <h2><?php echo cpmsHubEscape($text['management_contact']); ?></h2>
                </div>
                <div class="contact-list">
                    <div>
                        <span><?php echo cpmsHubIcon('phone'); ?></span>
                        <p><small><?php echo cpmsHubEscape($text['phone']); ?></small><strong><?php echo cpmsHubEscape($phone !== '' ? $phone : $text['not_set']); ?></strong></p>
                    </div>
                    <div>
                        <span><?php echo cpmsHubIcon('map'); ?></span>
                        <p><small><?php echo cpmsHubEscape($text['address']); ?></small><strong><?php echo cpmsHubEscape($address !== '' ? $address : $text['not_set']); ?></strong></p>
                    </div>
                    <div>
                        <span><?php echo cpmsHubIcon('clock'); ?></span>
                        <p><small><?php echo cpmsHubEscape($text['hours']); ?></small><strong><?php echo cpmsHubEscape($operatingHours !== '' ? $operatingHours : $text['not_set']); ?></strong></p>
                    </div>
                </div>
            </article>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="hub-container footer-inner">
        <div>
            <strong><?php echo cpmsHubEscape($propertyName); ?></strong>
            <span><?php echo cpmsHubEscape($footerText); ?></span>
        </div>
        <p>© <?php echo date('Y'); ?> <?php echo cpmsHubEscape($propertyName); ?></p>
    </div>
</footer>

<script>
(function () {
    var button = document.querySelector('.nav-toggle');
    var navigation = document.getElementById('main-navigation');

    if (!button || !navigation) {
        return;
    }

    button.addEventListener('click', function () {
        var expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        navigation.classList.toggle('is-open', !expanded);
    });

    navigation.addEventListener('click', function (event) {
        if (event.target.tagName === 'A') {
            button.setAttribute('aria-expanded', 'false');
            navigation.classList.remove('is-open');
        }
    });
}());
</script>
</body>
</html>
