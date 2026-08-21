<?php
declare(strict_types=1);

/*
 * CPMS v3.5.6.2 — Public Announcement Access
 * Upload to: /htdocs/announcements.php
 * Reading is public. Resident authentication remains required only for
 * resident-specific read confirmation.
 */

require_once __DIR__ . '/cpms/db.php';
require_once __DIR__ . '/cpms/includes/system_settings.php';
require_once __DIR__ . '/cpms/core/branding.php';

function cpmsPublicNoticeEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsPublicNoticeTableExists(mysqli $conn, string $table): bool
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

function cpmsPublicNoticePropertyColumns(mysqli $conn): array
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

function cpmsPublicNoticeProperties(mysqli $conn): array
{
    if (!cpmsPublicNoticeTableExists($conn, 'cpms_properties')) {
        return [];
    }

    $columns = cpmsPublicNoticePropertyColumns($conn);
    $wanted = [
        'id',
        'property_code',
        'property_name',
        'company_name',
        'address',
        'phone',
        'website',
        'logo_path',
        'favicon_path',
        'background_path',
        'dashboard_banner_path',
        'footer_text',
        'primary_color',
        'secondary_color',
        'default_language',
        'is_active',
    ];
    $select = [];

    foreach ($wanted as $column) {
        if (isset($columns[$column])) {
            $select[] = '`' . $column . '`';
        }
    }

    if (!isset($columns['id']) || !isset($columns['property_name'])) {
        return [];
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM cpms_properties';

    if (isset($columns['is_active'])) {
        $sql .= ' WHERE is_active = 1';
    }

    $sql .= ' ORDER BY id ASC';
    $result = $conn->query($sql);

    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function cpmsPublicNoticeHost(string $value): string
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

function cpmsPublicNoticeResolveProperty(array $properties): array
{
    if (!$properties) {
        return [];
    }

    $requested = trim((string) ($_GET['property'] ?? ''));

    if ($requested !== '') {
        foreach ($properties as $property) {
            if (
                $requested === (string) ($property['id'] ?? '')
                || strcasecmp(
                    $requested,
                    (string) ($property['property_code'] ?? '')
                ) === 0
            ) {
                return $property;
            }
        }
    }

    $host = cpmsPublicNoticeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host !== '') {
        foreach ($properties as $property) {
            $propertyHost = cpmsPublicNoticeHost(
                (string) ($property['website'] ?? '')
            );

            if ($propertyHost !== '' && $propertyHost === $host) {
                return $property;
            }
        }
    }

    return $properties[0];
}

function cpmsPublicNoticeFirst(array $values, string $fallback = ''): string
{
    foreach ($values as $value) {
        $value = trim((string) $value);

        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function cpmsPublicNoticeHex($value, string $fallback): string
{
    $value = trim((string) $value);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
        ? strtolower($value)
        : $fallback;
}

function cpmsPublicNoticeContrast(string $hex): string
{
    $hex = ltrim(cpmsPublicNoticeHex($hex, '#ffffff'), '#');
    $red = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue = hexdec(substr($hex, 4, 2));
    $brightness = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

    return $brightness >= 150 ? '#10213d' : '#ffffff';
}

function cpmsPublicNoticeUrl(string $path, array $parameters = []): string
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

function cpmsPublicNoticeLoad(mysqli $conn, int $propertyId): array
{
    if (
        $propertyId < 1
        || !cpmsPublicNoticeTableExists(
            $conn,
            'cpms_resident_announcements'
        )
    ) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT
            id,
            notice_type,
            priority,
            title,
            message,
            requires_confirmation,
            publish_from,
            publish_until
         FROM cpms_resident_announcements
         WHERE property_id = ?
           AND status = 'Published'
           AND publish_from <= NOW()
           AND (publish_until IS NULL OR publish_until >= NOW())
         ORDER BY
            CASE WHEN notice_type = 'Emergency' THEN 0 ELSE 1 END,
            CASE
                WHEN priority = 'Critical' THEN 0
                WHEN priority = 'High' THEN 1
                ELSE 2
            END,
            publish_from DESC"
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

$properties = cpmsPublicNoticeProperties($conn);
$currentProperty = cpmsPublicNoticeResolveProperty($properties);
$propertyId = (int) ($currentProperty['id'] ?? 0);
$propertyCode = trim((string) ($currentProperty['property_code'] ?? ''));

try {
    $cpmsSettings = loadSystemSettings($conn);
} catch (Throwable $error) {
    $cpmsSettings = cpmsSystemSettingDefaults();
}

$GLOBALS['currentProperty'] = $currentProperty;
$GLOBALS['cpmsSettings'] = $cpmsSettings;

$defaultLanguage = strtolower(
    cpmsPublicNoticeFirst([
        $currentProperty['default_language'] ?? '',
        $cpmsSettings['default_language'] ?? '',
    ], 'ms')
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
        'eyebrow' => 'MAKLUMAN RASMI PENGURUSAN',
        'title' => 'Pengumuman & Notis Kecemasan',
        'description' => 'Baca makluman terkini daripada pihak pengurusan tanpa perlu log masuk.',
        'public_label' => 'AKSES AWAM',
        'count' => '%d notis aktif',
        'empty_title' => 'Tiada pengumuman aktif',
        'empty_text' => 'Belum ada makluman baharu daripada pihak pengurusan.',
        'published' => 'Diterbitkan',
        'until' => 'Aktif sehingga',
        'announcement' => 'Pengumuman',
        'emergency' => 'Notis Kecemasan',
        'confirmation' => 'Notis ini memerlukan pengesahan dibaca.',
        'login_confirm' => 'Log masuk untuk sahkan dibaca',
        'back' => 'Kembali ke Portal Utama',
    ],
    'en' => [
        'home' => 'Home',
        'services' => 'Services',
        'announcements' => 'Announcements',
        'contact' => 'Contact Us',
        'eyebrow' => 'OFFICIAL MANAGEMENT UPDATES',
        'title' => 'Announcements & Emergency Notices',
        'description' => 'Read the latest management updates without signing in.',
        'public_label' => 'PUBLIC ACCESS',
        'count' => '%d active notices',
        'empty_title' => 'No active announcements',
        'empty_text' => 'There are no new management updates at this time.',
        'published' => 'Published',
        'until' => 'Active until',
        'announcement' => 'Announcement',
        'emergency' => 'Emergency Notice',
        'confirmation' => 'This notice requires read confirmation.',
        'login_confirm' => 'Sign in to confirm as read',
        'back' => 'Back to Main Portal',
    ],
];
$text = $copy[$language];
$propertyName = cpmsPublicNoticeFirst([
    $currentProperty['property_name'] ?? '',
], 'Property Resident Portal');
$companyName = cpmsPublicNoticeFirst([
    $currentProperty['company_name'] ?? '',
    $cpmsSettings['company_name'] ?? '',
]);
$primaryColor = cpmsPublicNoticeHex(
    cpmsPublicNoticeFirst([
        $currentProperty['primary_color'] ?? '',
        $cpmsSettings['primary_color'] ?? '',
    ]),
    '#0f3563'
);
$secondaryColor = cpmsPublicNoticeHex(
    cpmsPublicNoticeFirst([
        $currentProperty['secondary_color'] ?? '',
        $cpmsSettings['secondary_color'] ?? '',
    ]),
    '#d7aa4b'
);
$logoPath = cpmsPublicNoticeFirst([
    $currentProperty['logo_path'] ?? '',
    $cpmsSettings['logo_path'] ?? '',
]);
$backgroundPath = cpmsPublicNoticeFirst([
    $currentProperty['background_path'] ?? '',
    $currentProperty['dashboard_banner_path'] ?? '',
    $cpmsSettings['background_path'] ?? '',
]);

if ($propertyId !== 1 && strcasecmp($propertyCode, 'V23') !== 0) {
    $normalisedLogo = strtolower(ltrim(str_replace('\\', '/', $logoPath), '/'));
    $normalisedBackground = strtolower(
        ltrim(str_replace('\\', '/', $backgroundPath), '/')
    );

    if ($normalisedLogo === 'images/logo.png') {
        $logoPath = '';
    }

    if ($normalisedBackground === 'images/bg-premium.jpg') {
        $backgroundPath = '';
    }
}

$logoUrl = $logoPath !== '' ? cpmsAssetUrl($logoPath) : '';
$backgroundUrl = $backgroundPath !== ''
    ? cpmsAssetUrl($backgroundPath)
    : '';
$notices = cpmsPublicNoticeLoad($conn, $propertyId);
$baseParameters = ['property' => $propertyCode, 'lang' => $language];
$bmParameters = ['property' => $propertyCode, 'lang' => 'ms'];
$enParameters = ['property' => $propertyCode, 'lang' => 'en'];
$footerText = cpmsPublicNoticeFirst([
    $currentProperty['footer_text'] ?? '',
], cpmsFooter());
?>
<!doctype html>
<html lang="<?php echo cpmsPublicNoticeEscape($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?php echo cpmsPublicNoticeEscape($primaryColor); ?>">
    <title><?php echo cpmsPublicNoticeEscape($text['title'] . ' | ' . $propertyName); ?></title>
    <?php if (!empty($currentProperty['favicon_path'])): ?>
        <link rel="icon" href="<?php echo cpmsPublicNoticeEscape(cpmsAssetUrl((string) $currentProperty['favicon_path'])); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="css/cpms-resident-hub.css?v=3562">
    <link rel="stylesheet" href="css/cpms-public-announcements.css?v=3562">
    <style>
        :root {
            --hub-primary: <?php echo cpmsPublicNoticeEscape($primaryColor); ?>;
            --hub-secondary: <?php echo cpmsPublicNoticeEscape($secondaryColor); ?>;
            --hub-on-primary: <?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeContrast($primaryColor)); ?>;
            --hub-on-secondary: <?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeContrast($secondaryColor)); ?>;
        }
    </style>
</head>
<body>
<header class="site-header">
    <div class="hub-container header-inner">
        <a class="property-brand" href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('index.php', $baseParameters)); ?>">
            <span class="brand-mark">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?php echo cpmsPublicNoticeEscape($logoUrl); ?>" alt="<?php echo cpmsPublicNoticeEscape($propertyName); ?>">
                <?php else: ?>
                    <strong>CP</strong>
                <?php endif; ?>
            </span>
            <span class="brand-copy">
                <strong><?php echo cpmsPublicNoticeEscape($propertyName); ?></strong>
                <?php if ($companyName !== ''): ?><small><?php echo cpmsPublicNoticeEscape($companyName); ?></small><?php endif; ?>
            </span>
        </a>

        <nav id="main-navigation" class="main-navigation" aria-label="Main navigation">
            <a href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('index.php', $baseParameters)); ?>"><?php echo cpmsPublicNoticeEscape($text['home']); ?></a>
            <a href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('index.php', $baseParameters)); ?>#perkhidmatan"><?php echo cpmsPublicNoticeEscape($text['services']); ?></a>
            <a href="#notices"><?php echo cpmsPublicNoticeEscape($text['announcements']); ?></a>
            <a href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('index.php', $baseParameters)); ?>#hubungi"><?php echo cpmsPublicNoticeEscape($text['contact']); ?></a>
        </nav>

        <div class="header-tools">
            <div class="language-switch" role="group" aria-label="Pilihan bahasa / Language selection">
                <a class="<?php echo $language === 'ms' ? 'is-active' : ''; ?>" href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('announcements.php', $bmParameters)); ?>" <?php echo $language === 'ms' ? 'aria-current="page"' : ''; ?>>BM</a>
                <span aria-hidden="true">|</span>
                <a class="<?php echo $language === 'en' ? 'is-active' : ''; ?>" href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('announcements.php', $enParameters)); ?>" <?php echo $language === 'en' ? 'aria-current="page"' : ''; ?>>EN</a>
            </div>
            <button class="nav-toggle" type="button" aria-controls="main-navigation" aria-expanded="false">
                <span></span><span></span><span></span><span class="sr-only">Menu</span>
            </button>
        </div>
    </div>
</header>

<main>
    <section class="public-notice-hero">
        <?php if ($backgroundUrl !== ''): ?><img src="<?php echo cpmsPublicNoticeEscape($backgroundUrl); ?>" alt=""><?php endif; ?>
        <div class="public-notice-overlay"></div>
        <div class="hub-container public-notice-hero-inner">
            <span class="public-access-label"><?php echo cpmsPublicNoticeEscape($text['public_label']); ?></span>
            <span class="eyebrow"><?php echo cpmsPublicNoticeEscape($text['eyebrow']); ?></span>
            <h1><?php echo cpmsPublicNoticeEscape($text['title']); ?></h1>
            <p><?php echo cpmsPublicNoticeEscape($text['description']); ?></p>
            <div class="public-notice-count"><?php echo cpmsPublicNoticeEscape(sprintf($text['count'], count($notices))); ?></div>
        </div>
    </section>

    <section id="notices" class="public-notice-section">
        <div class="hub-container">
            <?php if (!$notices): ?>
                <div class="public-notice-empty">
                    <span>✓</span>
                    <h2><?php echo cpmsPublicNoticeEscape($text['empty_title']); ?></h2>
                    <p><?php echo cpmsPublicNoticeEscape($text['empty_text']); ?></p>
                </div>
            <?php else: ?>
                <div class="public-notice-list">
                    <?php foreach ($notices as $notice): ?>
                        <?php $isEmergency = (string) ($notice['notice_type'] ?? '') === 'Emergency'; ?>
                        <article class="public-notice-card <?php echo $isEmergency ? 'is-emergency' : ''; ?>">
                            <div class="public-notice-card-head">
                                <div class="public-notice-badges">
                                    <span class="notice-type"><?php echo cpmsPublicNoticeEscape($isEmergency ? $text['emergency'] : $text['announcement']); ?></span>
                                    <?php if (!empty($notice['priority'])): ?><span class="notice-priority"><?php echo cpmsPublicNoticeEscape($notice['priority']); ?></span><?php endif; ?>
                                </div>
                                <time datetime="<?php echo cpmsPublicNoticeEscape((string) ($notice['publish_from'] ?? '')); ?>">
                                    <?php echo cpmsPublicNoticeEscape($text['published']); ?>:
                                    <?php echo cpmsPublicNoticeEscape(date('d/m/Y, g:i A', strtotime((string) $notice['publish_from']))); ?>
                                </time>
                            </div>
                            <h2><?php echo cpmsPublicNoticeEscape($notice['title'] ?? ''); ?></h2>
                            <div class="public-notice-message"><?php echo nl2br(cpmsPublicNoticeEscape($notice['message'] ?? '')); ?></div>
                            <?php if (!empty($notice['publish_until'])): ?>
                                <p class="public-notice-until"><?php echo cpmsPublicNoticeEscape($text['until']); ?>: <?php echo cpmsPublicNoticeEscape(date('d/m/Y, g:i A', strtotime((string) $notice['publish_until']))); ?></p>
                            <?php endif; ?>
                            <?php if ((int) ($notice['requires_confirmation'] ?? 0) === 1): ?>
                                <div class="public-confirmation-note">
                                    <p><?php echo cpmsPublicNoticeEscape($text['confirmation']); ?></p>
                                    <a href="cpms/resident_login.php"><?php echo cpmsPublicNoticeEscape($text['login_confirm']); ?> →</a>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <a class="public-back-link" href="<?php echo cpmsPublicNoticeEscape(cpmsPublicNoticeUrl('index.php', $baseParameters)); ?>">← <?php echo cpmsPublicNoticeEscape($text['back']); ?></a>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="hub-container footer-inner">
        <div><strong><?php echo cpmsPublicNoticeEscape($propertyName); ?></strong><span><?php echo cpmsPublicNoticeEscape($footerText); ?></span></div>
        <p>© <?php echo date('Y'); ?> <?php echo cpmsPublicNoticeEscape($propertyName); ?></p>
    </div>
</footer>

<script>
(function () {
    var button = document.querySelector('.nav-toggle');
    var navigation = document.getElementById('main-navigation');
    if (!button || !navigation) return;
    button.addEventListener('click', function () {
        var expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        navigation.classList.toggle('is-open', !expanded);
    });
}());
</script>
</body>
</html>
