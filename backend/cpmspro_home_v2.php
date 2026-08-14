<?php
declare(strict_types=1);

$systemLoginUrl = 'https://app.cpmspro.my/cpms/login.php';
$fallbackLoginUrl = '/cpms/login.php';
$requestDemoUrl = 'mailto:admin@cpmspro.my?subject=CPMS%20Pro%20Demo%20Request';
$siteName = 'CPMS Pro';
$siteSubtitle = 'Property Operations';
$heroTitle = 'Premium control centre for property management teams';
$heroText = 'CPMS Pro brings resident complaints, work orders, inspections, preventive maintenance, attendance, security patrols, visitor QR, announcements and management reporting into one professional property operations platform.';

function cpmsProEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cpmsProAssetUrl(array $paths): string
{
    foreach ($paths as $path) {
        $path = '/' . ltrim($path, '/');

        if (is_file(__DIR__ . $path)) {
            return $path;
        }
    }

    return '';
}

function cpmsProSettingValue(array $settings, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($settings[$key] ?? ''));

        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function cpmsProLoadGlobalSettings(): array
{
    $dbFile = __DIR__ . '/cpms/db.php';
    $settingsFile = __DIR__ . '/cpms/includes/system_settings.php';

    if (!is_file($dbFile) || !is_file($settingsFile)) {
        return [];
    }

    try {
        require_once $dbFile;
        require_once $settingsFile;

        if (isset($conn) && $conn instanceof mysqli && function_exists('loadSystemSettings')) {
            $settings = loadSystemSettings($conn);

            return is_array($settings) ? $settings : [];
        }
    } catch (Throwable $error) {
        return [];
    }

    return [];
}

$globalSettings = cpmsProLoadGlobalSettings();
$siteName = cpmsProSettingValue(
    $globalSettings,
    ['system_name', 'brand_name', 'app_name'],
    $siteName
);
$siteSubtitle = cpmsProSettingValue(
    $globalSettings,
    ['tagline', 'system_tagline', 'company_name'],
    $siteSubtitle
);
$heroTitle = cpmsProSettingValue(
    $globalSettings,
    ['landing_hero_title', 'login_title'],
    $heroTitle
);
$heroText = cpmsProSettingValue(
    $globalSettings,
    ['landing_hero_text', 'login_description', 'description'],
    $heroText
);
$settingsLogo = cpmsProSettingValue(
    $globalSettings,
    ['cpmspro_logo_path', 'system_logo_path', 'logo_path'],
    ''
);
$logoUrl = cpmsProAssetUrl([
    $settingsLogo,
    'images/cpmspro-logo.png',
    'cpms/images/cpmspro-logo.png',
    'cpms/uploads/cpmspro-logo.png',
    'uploads/cpmspro-logo.png',
]);

$features = [
    ['Complaint & Work Order', 'Resident issue intake, assignment, status tracking and evidence records.'],
    ['Inspection & Compliance', 'Before and after photos, corrective action and inspection report control.'],
    ['Preventive Maintenance', 'Asset schedules, downtime, cost variance and monthly maintenance summaries.'],
    ['Staff & Security Attendance', 'Mobile clock-in, shift records, GPS/geofence and task reminders.'],
    ['Visitor QR & Patrol', 'Visitor pass, security verification, watchlist and patrol evidence.'],
    ['Resident Portal', 'Announcements, emergency notices, facility booking and resident services.'],
];

$metrics = [
    ['1', 'Unified Platform'],
    ['6+', 'Core Modules'],
    ['QR', 'Visitor Flow'],
    ['PDF', 'Reports'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPMSPro | Commercial Property Management System</title>
    <meta name="description" content="CPMSPro is a professional property operations platform for managing complaints, work orders, staff, security, residents, inspections, reports and multi-property workflows.">
    <meta property="og:title" content="CPMSPro | Commercial Property Management System">
    <meta property="og:description" content="Professional property operations software for complaints, work orders, staff, security, residents, inspections, reports and multi-property management.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://cpmspro.my/">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="CPMSPro | Commercial Property Management System">
    <meta name="twitter:description" content="Professional property operations software for complaints, work orders, staff, security, residents, inspections, reports and multi-property management.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://cpmspro.my/">
    <meta property="og:title" content="CPMS Pro - Commercial Property Management System Malaysia">
    <meta property="og:description" content="A unified property operations platform for residential and commercial buildings.">
    <meta property="og:url" content="https://cpmspro.my/">
    <meta property="og:type" content="website">
    <style>
        :root {
            --navy: #071a33;
            --navy-2: #0b2f57;
            --blue: #0f63b8;
            --cyan: #2bb4e8;
            --gold: #d8ab45;
            --ink: #10213d;
            --muted: #66758a;
            --line: #dce5ee;
            --soft: #f4f7fb;
            --white: #ffffff;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--ink);
            background: var(--white);
            line-height: 1.6;
        }

        a { color: inherit; text-decoration: none; }

        .shell {
            width: min(1180px, calc(100% - 34px));
            margin: 0 auto;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid rgba(220, 229, 238, 0.8);
            backdrop-filter: blur(14px);
        }

        .nav {
            min-height: 78px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 900;
            color: var(--navy);
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            object-fit: contain;
            background: var(--white);
            border: 1px solid var(--line);
            padding: 5px;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, var(--navy), var(--blue));
            color: var(--gold);
            font-weight: 900;
            box-shadow: 0 12px 26px rgba(7, 26, 51, 0.18);
            animation: pulseGlow 4.2s ease-in-out infinite;
        }

        .brand small {
            display: block;
            margin-top: -3px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 22px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 20px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--white);
            color: var(--ink);
            font-weight: 800;
            box-shadow: 0 10px 22px rgba(7, 26, 51, 0.08);
        }

        .btn.primary {
            border-color: var(--gold);
            background: linear-gradient(135deg, #e6bd58, var(--gold));
            color: #15213a;
        }

        .btn.dark {
            border-color: rgba(255, 255, 255, 0.24);
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
        }

        .hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 78% 16%, rgba(43, 180, 232, 0.34), transparent 28%),
                linear-gradient(125deg, rgba(7, 26, 51, 0.97), rgba(11, 47, 87, 0.94)),
                url("https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1800&q=80");
            background-size: cover;
            background-position: center;
            color: var(--white);
        }

        .hero::before {
            content: "";
            position: absolute;
            width: 520px;
            height: 520px;
            right: -170px;
            top: -180px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(216, 171, 69, 0.24), transparent 62%);
            animation: floatOrb 8s ease-in-out infinite;
            pointer-events: none;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.045) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(90deg, #000, transparent 78%);
            pointer-events: none;
        }

        .hero-layout {
            position: relative;
            z-index: 1;
            min-height: 650px;
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(420px, 1.05fr);
            gap: 48px;
            align-items: center;
            padding: 88px 0 104px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--gold);
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .eyebrow::before {
            content: "";
            width: 38px;
            height: 2px;
            background: var(--gold);
        }

        h1 {
            max-width: 760px;
            margin: 18px 0 18px;
            font-size: clamp(42px, 5.2vw, 74px);
            line-height: 1.02;
            letter-spacing: 0;
        }

        .lead {
            max-width: 700px;
            margin: 0 0 28px;
            color: rgba(255, 255, 255, 0.86);
            font-size: 19px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .trust-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 28px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.09);
            color: rgba(255, 255, 255, 0.86);
            font-size: 13px;
            font-weight: 800;
        }

        .product-visual {
            position: relative;
            min-height: 468px;
        }

        .dashboard-card {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.38);
            color: var(--ink);
            animation: floatDashboard 6.5s ease-in-out infinite;
        }

        .window-bar {
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            background: #eef4fa;
            border-bottom: 1px solid var(--line);
        }

        .dots {
            display: flex;
            gap: 7px;
        }

        .dots span {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #9fb0c2;
        }

        .window-title {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .dashboard-body {
            display: grid;
            grid-template-columns: 168px 1fr;
            min-height: 400px;
        }

        .sidebar {
            padding: 18px;
            background: var(--navy);
            color: var(--white);
        }

        .side-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            font-weight: 900;
        }

        .side-logo span {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            background: var(--gold);
            color: var(--navy);
        }

        .side-item {
            height: 34px;
            margin: 8px 0;
            padding: 0 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.78);
            font-size: 12px;
            font-weight: 800;
        }

        .main-panel {
            padding: 20px;
            background: linear-gradient(180deg, #ffffff, #f7faff);
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .panel-head h3 {
            margin: 0;
            font-size: 20px;
        }

        .status-pill {
            align-self: flex-start;
            padding: 7px 10px;
            border-radius: 999px;
            background: #e8f8ef;
            color: #16643a;
            font-size: 12px;
            font-weight: 900;
        }

        .mini-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }

        .mini-stat {
            padding: 14px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--white);
        }

        .mini-stat strong {
            display: block;
            color: var(--blue);
            font-size: 22px;
        }

        .mini-stat span {
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
        }

        .chart {
            height: 118px;
            display: flex;
            align-items: end;
            gap: 9px;
            padding: 14px;
            border-radius: 10px;
            background: #eef5fc;
            border: 1px solid var(--line);
        }

        .bar {
            flex: 1;
            min-width: 18px;
            border-radius: 7px 7px 0 0;
            background: linear-gradient(180deg, var(--cyan), var(--blue));
            transform-origin: bottom;
            animation: barPulse 3.2s ease-in-out infinite;
        }

        .bar:nth-child(2) { animation-delay: 0.2s; }
        .bar:nth-child(3) { animation-delay: 0.4s; }
        .bar:nth-child(4) { animation-delay: 0.6s; }
        .bar:nth-child(5) { animation-delay: 0.8s; }
        .bar:nth-child(6) { animation-delay: 1s; }
        .bar:nth-child(7) { animation-delay: 1.2s; }

        .chip {
            animation: fadeLift 5.5s ease-in-out infinite;
        }

        .chip:nth-child(2) { animation-delay: 0.25s; }
        .chip:nth-child(3) { animation-delay: 0.5s; }
        .chip:nth-child(4) { animation-delay: 0.75s; }

        .task-list {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .task {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 12px;
            border-radius: 10px;
            background: var(--white);
            border: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800;
            animation: slideSoft 5s ease-in-out infinite;
        }

        .task:nth-child(2) { animation-delay: 0.45s; }
        .task:nth-child(3) { animation-delay: 0.9s; }

        .floating-card {
            position: absolute;
            right: -20px;
            bottom: 26px;
            width: 230px;
            padding: 16px;
            border-radius: 14px;
            background: var(--white);
            color: var(--ink);
            box-shadow: 0 22px 55px rgba(0, 0, 0, 0.26);
            border: 1px solid var(--line);
            animation: floatSmall 5.2s ease-in-out infinite;
        }

        .floating-card strong {
            display: block;
            margin-bottom: 5px;
            font-size: 15px;
        }

        .floating-card span {
            color: var(--muted);
            font-size: 13px;
        }

        .metrics {
            position: relative;
            z-index: 2;
            margin-top: -44px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border-radius: 14px;
            overflow: hidden;
            background: var(--white);
            border: 1px solid var(--line);
            box-shadow: 0 22px 58px rgba(16, 33, 61, 0.12);
        }

        .metric {
            padding: 26px;
            border-right: 1px solid var(--line);
        }

        .metric:last-child { border-right: 0; }

        .metric strong {
            display: block;
            color: var(--blue);
            font-size: 31px;
            line-height: 1;
        }

        .metric span {
            display: block;
            margin-top: 7px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 800;
        }

        section {
            padding: 82px 0;
        }

        .section-head {
            max-width: 780px;
            margin-bottom: 30px;
        }

        h2 {
            margin: 0 0 10px;
            color: var(--navy);
            font-size: 38px;
            line-height: 1.14;
            letter-spacing: 0;
        }

        .section-head p {
            margin: 0;
            color: var(--muted);
            font-size: 17px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .feature {
            position: relative;
            min-height: 190px;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--white);
            box-shadow: 0 12px 32px rgba(16, 33, 61, 0.07);
            overflow: hidden;
        }

        .feature::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--cyan));
        }

        .icon {
            width: 42px;
            height: 42px;
            margin-bottom: 14px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: #eef6ff;
            color: var(--blue);
            font-weight: 900;
        }

        .feature h3 {
            margin: 0 0 8px;
            color: var(--navy);
            font-size: 18px;
        }

        .feature p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .band {
            background:
                linear-gradient(135deg, #f5f8fb, #ffffff);
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 42px;
            align-items: center;
        }

        .steps {
            display: grid;
            gap: 14px;
            margin-top: 24px;
        }

        .step {
            display: grid;
            grid-template-columns: 42px 1fr;
            gap: 14px;
            align-items: start;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--white);
        }

        .step-num {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--navy);
            color: var(--gold);
            font-weight: 900;
        }

        .step strong {
            display: block;
            margin-bottom: 4px;
            color: var(--navy);
        }

        .step span {
            color: var(--muted);
            font-size: 14px;
        }

        .showcase {
            min-height: 430px;
            border-radius: 16px;
            padding: 26px;
            background:
                linear-gradient(135deg, rgba(7, 26, 51, 0.94), rgba(15, 99, 184, 0.86)),
                url("https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1400&q=80");
            background-size: cover;
            background-position: center;
            color: var(--white);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            box-shadow: 0 22px 58px rgba(16, 33, 61, 0.18);
        }

        .showcase h3 {
            margin: 0 0 10px;
            font-size: 30px;
            line-height: 1.1;
        }

        .showcase p {
            margin: 0;
            color: rgba(255, 255, 255, 0.84);
        }

        .cta {
            padding: 78px 0;
            background: var(--navy);
            color: var(--white);
            text-align: center;
        }

        .cta h2 {
            color: var(--white);
        }

        .cta p {
            max-width: 760px;
            margin: 12px auto 28px;
            color: rgba(255, 255, 255, 0.82);
        }

        footer {
            padding: 28px 0;
            color: var(--muted);
            text-align: center;
            font-size: 14px;
        }

        @keyframes floatDashboard {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
        }

        @keyframes floatSmall {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(-8px, -10px); }
        }

        @keyframes floatOrb {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.9; }
            50% { transform: translate(-42px, 36px) scale(1.08); opacity: 0.58; }
        }

        @keyframes barPulse {
            0%, 100% { transform: scaleY(0.9); filter: saturate(1); }
            50% { transform: scaleY(1.08); filter: saturate(1.3); }
        }

        @keyframes slideSoft {
            0%, 100% { transform: translateX(0); }
            50% { transform: translateX(5px); }
        }

        @keyframes fadeLift {
            0%, 100% { transform: translateY(0); opacity: 0.86; }
            50% { transform: translateY(-4px); opacity: 1; }
        }

        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 12px 26px rgba(7, 26, 51, 0.18); }
            50% { box-shadow: 0 14px 34px rgba(15, 99, 184, 0.32); }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
            }
        }

        @media (max-width: 980px) {
            .nav {
                align-items: flex-start;
                flex-direction: column;
                padding: 14px 0;
            }

            .nav-links { flex-wrap: wrap; }

            .hero-layout,
            .split {
                grid-template-columns: 1fr;
            }

            .product-visual {
                min-height: auto;
            }

            .floating-card {
                position: static;
                width: auto;
                margin-top: 14px;
            }

            .metrics,
            .feature-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .metric:nth-child(2) {
                border-right: 0;
            }
        }

        @media (max-width: 640px) {
            .shell { width: min(100% - 24px, 1180px); }

            .nav-links {
                gap: 12px;
                font-size: 13px;
            }

            .hero-layout {
                min-height: auto;
                padding: 64px 0 82px;
            }

            h1 { font-size: 42px; }

            .dashboard-body {
                grid-template-columns: 1fr;
            }

            .sidebar { display: none; }

            .mini-stats,
            .metrics,
            .feature-grid {
                grid-template-columns: 1fr;
            }

            .metric {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .metric:last-child {
                border-bottom: 0;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="shell nav">
            <a class="brand" href="https://cpmspro.my/" aria-label="<?php echo cpmsProEscape($siteName); ?> home">
                <?php if ($logoUrl !== ''): ?>
                    <img class="brand-logo" src="<?php echo cpmsProEscape($logoUrl); ?>" alt="<?php echo cpmsProEscape($siteName); ?> logo">
                <?php else: ?>
                    <span class="brand-mark">CP</span>
                <?php endif; ?>
                <span>
                    <?php echo cpmsProEscape($siteName); ?>
                    <small><?php echo cpmsProEscape($siteSubtitle); ?></small>
                </span>
            </a>
            <nav class="nav-links" aria-label="Main navigation">
                <a href="#features">Features</a>
                <a href="#workflow">Workflow</a>
                <a href="#contact">Contact</a>
                <a class="btn" href="<?php echo cpmsProEscape($systemLoginUrl); ?>">Login System</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="hero">
            <div class="shell hero-layout">
                <div>
                    <div class="eyebrow">Commercial Property Management System</div>
                    <h1><?php echo cpmsProEscape($heroTitle); ?></h1>
                    <p class="lead"><?php echo cpmsProEscape($heroText); ?></p>
                    <div class="hero-actions">
                        <a class="btn primary" href="<?php echo cpmsProEscape($systemLoginUrl); ?>">Login System</a>
                        <a class="btn dark" href="<?php echo cpmsProEscape($requestDemoUrl); ?>">Request Demo</a>
                    </div>
                    <div class="trust-row" aria-label="CPMS Pro modules">
                        <span class="chip">Multi-property</span>
                        <span class="chip">Resident portal</span>
                        <span class="chip">Security workflow</span>
                        <span class="chip">PDF reports</span>
                    </div>
                </div>

                <div class="product-visual" aria-label="<?php echo cpmsProEscape($siteName); ?> dashboard preview">
                    <div class="dashboard-card">
                        <div class="window-bar">
                            <div class="dots"><span></span><span></span><span></span></div>
                            <div class="window-title"><?php echo cpmsProEscape($siteName); ?> Dashboard</div>
                        </div>
                        <div class="dashboard-body">
                            <aside class="sidebar">
                                <div class="side-logo"><span>CP</span> CPMS</div>
                                <div class="side-item">Dashboard</div>
                                <div class="side-item">Complaints</div>
                                <div class="side-item">Inspection</div>
                                <div class="side-item">Maintenance</div>
                                <div class="side-item">Security</div>
                                <div class="side-item">Reports</div>
                            </aside>
                            <section class="main-panel">
                                <div class="panel-head">
                                    <div>
                                        <h3>Operations Overview</h3>
                                        <span style="color:#66758a;font-size:12px;font-weight:800;">Today performance summary</span>
                                    </div>
                                    <span class="status-pill">Live</span>
                                </div>
                                <div class="mini-stats">
                                    <div class="mini-stat"><strong>18</strong><span>Open Tasks</span></div>
                                    <div class="mini-stat"><strong>7</strong><span>Inspections</span></div>
                                    <div class="mini-stat"><strong>92%</strong><span>SLA Health</span></div>
                                </div>
                                <div class="chart" aria-hidden="true">
                                    <div class="bar" style="height:42%;"></div>
                                    <div class="bar" style="height:66%;"></div>
                                    <div class="bar" style="height:52%;"></div>
                                    <div class="bar" style="height:82%;"></div>
                                    <div class="bar" style="height:70%;"></div>
                                    <div class="bar" style="height:92%;"></div>
                                    <div class="bar" style="height:62%;"></div>
                                </div>
                                <div class="task-list">
                                    <div class="task"><span>Water pump inspection</span><span>PM</span></div>
                                    <div class="task"><span>Visitor QR verification</span><span>Security</span></div>
                                    <div class="task"><span>Monthly report draft</span><span>PDF</span></div>
                                </div>
                            </section>
                        </div>
                    </div>
                    <div class="floating-card">
                        <strong>Professional public homepage</strong>
                        <span>Designed for Google indexing, client confidence and direct access to the system login.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="shell metrics" aria-label="CPMS Pro highlights">
            <?php foreach ($metrics as $metric): ?>
                <div class="metric">
                    <strong><?php echo cpmsProEscape($metric[0]); ?></strong>
                    <span><?php echo cpmsProEscape($metric[1]); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <section id="features" class="shell">
            <div class="section-head">
                <h2>Built for real daily property operations</h2>
                <p>Every module is focused on cleaner records, faster follow-up and better visibility for management, staff, security and residents.</p>
            </div>
            <div class="feature-grid">
                <?php foreach ($features as $index => $feature): ?>
                    <article class="feature">
                        <div class="icon"><?php echo (string) ($index + 1); ?></div>
                        <h3><?php echo cpmsProEscape($feature[0]); ?></h3>
                        <p><?php echo cpmsProEscape($feature[1]); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div id="workflow" class="band">
            <section class="shell split">
                <div>
                    <div class="section-head">
                        <h2>From report to action to management record</h2>
                        <p>CPMS Pro helps turn daily site activity into trackable work, proof of action and presentable management reports.</p>
                    </div>
                    <div class="steps">
                        <div class="step">
                            <div class="step-num">1</div>
                            <div><strong>Capture Issue</strong><span>Resident, inspector or management records the issue with photo evidence.</span></div>
                        </div>
                        <div class="step">
                            <div class="step-num">2</div>
                            <div><strong>Assign & Follow Up</strong><span>Tasks are assigned to staff, security, supervisor or contractor.</span></div>
                        </div>
                        <div class="step">
                            <div class="step-num">3</div>
                            <div><strong>Close With Report</strong><span>Completed work becomes structured history for PDF report and review.</span></div>
                        </div>
                    </div>
                </div>
                <div class="showcase">
                    <h3>Designed for apartments, condominiums and commercial buildings</h3>
                    <p>Suitable for property admins, managers, clerks, staff, security teams, inspectors and residents.</p>
                </div>
            </section>
        </div>

        <div id="contact" class="cta">
            <div class="shell">
                <h2>CPMS Pro - one platform for property operations</h2>
                <p>Make daily operations easier to monitor, easier to prove and easier to present to management or clients.</p>
                <div class="hero-actions" style="justify-content:center;">
                    <a class="btn primary" href="<?php echo cpmsProEscape($requestDemoUrl); ?>">Request Demo</a>
                    <a class="btn dark" href="<?php echo cpmsProEscape($fallbackLoginUrl); ?>">Open Login</a>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <div class="shell">&copy; <?php echo date('Y'); ?> CPMS Pro. Commercial Property Management System Malaysia.</div>
    </footer>
</body>
</html>
