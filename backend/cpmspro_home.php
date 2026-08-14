<?php
declare(strict_types=1);

$lang = strtolower(trim((string) ($_GET['lang'] ?? 'ms')));
$lang = $lang === 'en' ? 'en' : 'ms';
$isEn = $lang === 'en';

function cpmsSiteEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$copy = [
    'ms' => [
        'nav_about' => 'Tentang',
        'nav_modules' => 'Modul',
        'nav_solution' => 'Penyelesaian',
        'nav_contact' => 'Hubungi',
        'login' => 'Log Masuk',
        'eyebrow' => 'COMMERCIAL PROPERTY MANAGEMENT SYSTEM',
        'hero_title' => 'Urus hartanah dengan lebih tersusun.',
        'hero_text' => 'CPMSPro menyatukan operasi pengurusan, staf, keselamatan dan resident dalam satu platform multi-property yang moden.',
        'explore' => 'Lihat Keupayaan',
        'open_app' => 'Buka CPMS',
        'trusted' => 'DIBINA UNTUK OPERASI HARTANAH SEBENAR',
        'about_kicker' => 'SATU PLATFORM, SEMUA OPERASI',
        'about_title' => 'Daripada aduan sehingga laporan pengurusan.',
        'about_text' => 'Kurangkan rekod berasingan dan proses manual. CPMSPro menghubungkan tugasan harian, aset, pematuhan, penyelenggaraan, rondaan, kehadiran dan komunikasi resident dalam ekosistem yang sama.',
        'modules_kicker' => 'MODUL UTAMA',
        'modules_title' => 'Pilih modul mengikut keperluan property.',
        'solution_kicker' => 'DIREKA UNTUK MULTI-PROPERTY',
        'solution_title' => 'Satu sistem. Property kekal berasingan.',
        'solution_text' => 'Setiap property mempunyai identiti, pengguna dan data sendiri, manakala pihak pengurusan boleh membina operasi yang konsisten merentasi projek.',
        'point1' => 'Subdomain khusus bagi setiap property',
        'point2' => 'Akses berdasarkan peranan pengguna',
        'point3' => 'Branding dan tetapan mengikut property',
        'point4' => 'Portal web dan Workforce mobile-ready',
        'cta_kicker' => 'CPMSPRO',
        'cta_title' => 'Bawa operasi property anda ke satu ruang kerja digital.',
        'cta_text' => 'Mulakan dengan modul yang diperlukan dan kembangkan sistem apabila operasi anda berkembang.',
        'cta_login' => 'Log Masuk Sistem',
        'cta_contact' => 'Hubungi Kami',
        'contact_title' => 'Berminat menggunakan CPMSPro?',
        'contact_text' => 'Untuk demo, cadangan modul atau pelaksanaan bagi property anda, hubungi pasukan CPMSPro.',
        'contact_note' => 'Maklumat hubungan rasmi boleh dikemas kini apabila akaun e-mel dan saluran jualan CPMSPro telah disediakan.',
        'footer' => 'Commercial Property Management System',
    ],
    'en' => [
        'nav_about' => 'About',
        'nav_modules' => 'Modules',
        'nav_solution' => 'Solutions',
        'nav_contact' => 'Contact',
        'login' => 'Sign In',
        'eyebrow' => 'COMMERCIAL PROPERTY MANAGEMENT SYSTEM',
        'hero_title' => 'Run property operations with clarity.',
        'hero_text' => 'CPMSPro unifies management, workforce, security and resident operations in one modern multi-property platform.',
        'explore' => 'Explore Capabilities',
        'open_app' => 'Open CPMS',
        'trusted' => 'BUILT FOR REAL PROPERTY OPERATIONS',
        'about_kicker' => 'ONE PLATFORM, EVERY OPERATION',
        'about_title' => 'From complaints to management reporting.',
        'about_text' => 'Reduce fragmented records and manual processes. CPMSPro connects daily tasks, assets, compliance, maintenance, patrols, attendance and resident communication in one ecosystem.',
        'modules_kicker' => 'CORE MODULES',
        'modules_title' => 'Choose modules around each property\'s needs.',
        'solution_kicker' => 'BUILT FOR MULTI-PROPERTY',
        'solution_title' => 'One system. Properties stay isolated.',
        'solution_text' => 'Each property keeps its own identity, users and data while management can standardise operations across projects.',
        'point1' => 'Dedicated subdomain for every property',
        'point2' => 'Role-based user access',
        'point3' => 'Property-specific branding and settings',
        'point4' => 'Web portal and mobile-ready Workforce',
        'cta_kicker' => 'CPMSPRO',
        'cta_title' => 'Bring your property operations into one digital workspace.',
        'cta_text' => 'Start with the modules you need and expand as your operations grow.',
        'cta_login' => 'System Login',
        'cta_contact' => 'Contact Us',
        'contact_title' => 'Interested in CPMSPro?',
        'contact_text' => 'For a demo, module recommendation or property deployment, contact the CPMSPro team.',
        'contact_note' => 'Official contact details can be updated once CPMSPro email and sales channels are configured.',
        'footer' => 'Commercial Property Management System',
    ],
];

$t = $copy[$lang];
$modules = $isEn ? [
    ['01', 'Complaints & Residents', 'Complaint tracking, resident accounts, announcements and service access.'],
    ['02', 'Work Orders', 'Assign, track and verify operational tasks with evidence and history.'],
    ['03', 'Assets & Preventive Maintenance', 'Asset registers, planned maintenance, cost and downtime monitoring.'],
    ['04', 'Inspection & Compliance', 'Digital inspections, scoring, non-compliance and corrective actions.'],
    ['05', 'Workforce & GPS Attendance', 'Staff workspace, geofenced attendance, shifts and mobile evidence.'],
    ['06', 'Security & Visitor', 'Patrol records, checkpoints, visitor QR, watchlist and incident reporting.'],
    ['07', 'Facility & Communication', 'Facility booking, notices, notifications and resident engagement.'],
    ['08', 'Management Intelligence', 'Property health, operational summaries and management reporting.'],
] : [
    ['01', 'Aduan & Resident', 'Jejak aduan, akaun resident, pengumuman dan akses perkhidmatan.'],
    ['02', 'Work Order', 'Agih, pantau dan sahkan tugasan operasi bersama bukti serta sejarah kerja.'],
    ['03', 'Aset & Preventive Maintenance', 'Daftar aset, penyelenggaraan berjadual, kos dan pemantauan downtime.'],
    ['04', 'Inspection & Compliance', 'Pemeriksaan digital, scoring, ketidakpatuhan dan corrective action.'],
    ['05', 'Workforce & GPS Attendance', 'Portal staf, kehadiran geofence, syif dan bukti kerja melalui telefon.'],
    ['06', 'Security & Visitor', 'Rondaan, checkpoint, QR pelawat, watchlist dan laporan kejadian.'],
    ['07', 'Facility & Communication', 'Tempahan fasiliti, notis, notifikasi dan komunikasi resident.'],
    ['08', 'Management Intelligence', 'Status property, ringkasan operasi dan laporan pengurusan.'],
];
?>
<!doctype html>
<html lang="<?= cpmsSiteEscape($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="CPMSPro - Commercial Property Management System for multi-property operations, workforce, security and residents.">
    <title>CPMSPro | Commercial Property Management System</title>
    <style>
        :root{--navy:#07162f;--navy2:#0d2347;--blue:#1769ff;--cyan:#34c6e8;--gold:#d7aa54;--ink:#12203a;--muted:#65738c;--line:#dce4ef;--soft:#f4f7fb;--white:#fff;--green:#10a57a}
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff}.wrap{width:min(1180px,calc(100% - 40px));margin:auto}a{text-decoration:none;color:inherit}
        .nav{position:sticky;top:0;z-index:20;background:rgba(7,22,47,.94);backdrop-filter:blur(16px);border-bottom:1px solid rgba(255,255,255,.1)}.nav-inner{height:78px;display:flex;align-items:center;justify-content:space-between;gap:24px}.brand{display:flex;align-items:center;gap:12px;color:#fff}.mark{width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,#36c6ee,#1769ff);display:grid;place-items:center;font-weight:900;box-shadow:0 12px 32px rgba(23,105,255,.28)}.brand strong{font-size:20px;letter-spacing:-.4px}.brand small{display:block;color:#aab9d3;font-size:10px;letter-spacing:1.5px;font-weight:800;margin-top:2px}.nav-links{display:flex;align-items:center;gap:26px;color:#c7d2e6;font-size:14px;font-weight:700}.nav-links a:hover{color:#fff}.nav-actions{display:flex;align-items:center;gap:10px}.lang{border:1px solid rgba(255,255,255,.2);color:#d7e0ef;border-radius:12px;padding:9px 11px;font-size:12px;font-weight:800}.login{background:#fff;color:var(--navy);border-radius:12px;padding:10px 16px;font-size:13px;font-weight:850}
        .hero{position:relative;overflow:hidden;background:radial-gradient(circle at 78% 24%,rgba(23,105,255,.30),transparent 30%),radial-gradient(circle at 10% 95%,rgba(52,198,232,.13),transparent 28%),linear-gradient(135deg,#06142b,#0a1c3c 62%,#102b57);color:#fff;padding:96px 0 78px}.hero:after{content:"";position:absolute;width:520px;height:520px;border:78px solid rgba(255,255,255,.035);border-radius:50%;right:-190px;bottom:-260px}.hero-grid{position:relative;z-index:2;display:grid;grid-template-columns:1.2fr .8fr;gap:60px;align-items:center}.eyebrow,.kicker{font-size:12px;letter-spacing:2px;font-weight:900;color:#6fdbf1}.hero h1{font-size:clamp(48px,7vw,84px);line-height:.98;letter-spacing:-4px;margin:22px 0 25px;max-width:860px}.hero p{font-size:19px;line-height:1.7;color:#c5d1e5;max-width:720px;margin:0}.hero-cta{display:flex;gap:12px;flex-wrap:wrap;margin-top:34px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:50px;padding:0 20px;border-radius:14px;font-size:14px;font-weight:850}.btn-primary{background:linear-gradient(135deg,#1c7cff,#1760e8);color:#fff;box-shadow:0 15px 40px rgba(23,105,255,.3)}.btn-ghost{border:1px solid rgba(255,255,255,.25);color:#fff;background:rgba(255,255,255,.05)}.hero-card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:26px;padding:26px;box-shadow:0 30px 80px rgba(0,0,0,.22)}.mini-top{display:flex;justify-content:space-between;align-items:center;color:#aebbd0;font-size:12px;font-weight:800}.status{color:#75e2bf}.metric{margin:28px 0 18px}.metric strong{display:block;font-size:42px;letter-spacing:-2px}.metric span{font-size:12px;color:#aebbd0}.bar{height:9px;background:rgba(255,255,255,.1);border-radius:99px;overflow:hidden}.bar i{display:block;width:78%;height:100%;background:linear-gradient(90deg,#36c6ee,#1769ff);border-radius:inherit}.mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}.mini{background:rgba(255,255,255,.055);border-radius:16px;padding:16px}.mini b{display:block;font-size:22px}.mini span{font-size:11px;color:#aebbd0}.trust{background:#fff;border-bottom:1px solid var(--line);padding:22px 0;text-align:center;color:#738098;font-size:11px;letter-spacing:2px;font-weight:900}
        section{padding:88px 0}.section-head{max-width:760px}.kicker{color:#1769ff}.section-head h2,.solution-copy h2,.cta h2{font-size:clamp(34px,5vw,58px);line-height:1.06;letter-spacing:-2.3px;margin:14px 0 18px}.section-head p,.solution-copy p{color:var(--muted);font-size:17px;line-height:1.75}.about-grid{display:grid;grid-template-columns:.95fr 1.05fr;gap:70px;align-items:start}.about-stat{display:grid;grid-template-columns:1fr 1fr;gap:14px}.stat-card{border:1px solid var(--line);background:var(--soft);border-radius:20px;padding:24px}.stat-card b{font-size:28px;display:block;letter-spacing:-1px;color:#0b387a}.stat-card span{color:var(--muted);font-size:12px;line-height:1.5;display:block;margin-top:6px}.modules{background:var(--soft)}.module-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-top:42px}.module{min-height:240px;background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px;transition:.2s ease}.module:hover{transform:translateY(-4px);box-shadow:0 18px 45px rgba(21,48,89,.09);border-color:#bfd0e8}.module-num{font-size:11px;color:#1769ff;font-weight:900;letter-spacing:1px}.module h3{font-size:20px;line-height:1.2;margin:38px 0 12px}.module p{font-size:13px;line-height:1.65;color:var(--muted);margin:0}
        .solution{background:#fff}.solution-grid{display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}.solution-visual{background:linear-gradient(145deg,#081a38,#123a75);border-radius:30px;padding:28px;color:#fff;box-shadow:0 30px 70px rgba(10,34,70,.18)}.domain{display:flex;justify-content:space-between;align-items:center;gap:18px;background:rgba(255,255,255,.075);border:1px solid rgba(255,255,255,.13);padding:18px;border-radius:17px;margin:10px 0}.domain strong{font-size:14px}.domain span{color:#91a9ca;font-size:11px}.domain em{font-style:normal;background:#173e79;color:#a9ddff;border-radius:9px;padding:7px 9px;font-size:10px;font-weight:900}.points{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-top:28px}.point{display:flex;gap:10px;align-items:flex-start;font-size:13px;font-weight:750;color:#3f506a}.check{width:22px;height:22px;flex:0 0 22px;border-radius:7px;background:#e7f9f3;color:var(--green);display:grid;place-items:center;font-size:11px;font-weight:900}
        .cta-wrap{padding-top:0}.cta{position:relative;overflow:hidden;background:linear-gradient(135deg,#0a1a37,#12366e);color:#fff;border-radius:32px;padding:60px}.cta:after{content:"";position:absolute;width:300px;height:300px;border:55px solid rgba(255,255,255,.04);border-radius:50%;right:-80px;top:-120px}.cta .kicker{color:#73dcf4}.cta p{color:#bdcae0;max-width:650px;line-height:1.7}.contact{border-top:1px solid var(--line)}.contact-box{display:flex;justify-content:space-between;align-items:center;gap:32px;background:#f6f8fc;border:1px solid var(--line);border-radius:25px;padding:34px}.contact-box h3{font-size:26px;margin:0 0 8px}.contact-box p{margin:0;color:var(--muted);line-height:1.6}.note{font-size:11px!important;margin-top:9px!important}.footer{background:#06142b;color:#94a5c0;padding:34px 0}.footer-inner{display:flex;align-items:center;justify-content:space-between;gap:24px}.footer strong{color:#fff}.footer small{font-size:11px}
        @media(max-width:960px){.nav-links{display:none}.hero-grid,.about-grid,.solution-grid{grid-template-columns:1fr}.hero-card{max-width:600px}.module-grid{grid-template-columns:1fr 1fr}.hero{padding-top:72px}.hero h1{letter-spacing:-2.5px}.solution-grid{gap:42px}}
        @media(max-width:640px){.wrap{width:min(100% - 28px,1180px)}.nav-inner{height:68px}.brand small{display:none}.login{display:none}.hero{padding:64px 0}.hero-grid{gap:40px}.hero h1{font-size:48px}.hero p{font-size:16px}.module-grid,.about-stat,.points{grid-template-columns:1fr}.module{min-height:auto}.module h3{margin-top:25px}.cta{padding:36px 24px;border-radius:24px}.contact-box,.footer-inner{align-items:flex-start;flex-direction:column}.section-head h2,.solution-copy h2,.cta h2{letter-spacing:-1.6px}section{padding:68px 0}}
    </style>
</head>
<body>
<nav class="nav">
    <div class="wrap nav-inner">
        <a class="brand" href="/"><span class="mark">C</span><span><strong>CPMSPro</strong><small>PROPERTY OPERATIONS</small></span></a>
        <div class="nav-links"><a href="#about"><?= cpmsSiteEscape($t['nav_about']) ?></a><a href="#modules"><?= cpmsSiteEscape($t['nav_modules']) ?></a><a href="#solution"><?= cpmsSiteEscape($t['nav_solution']) ?></a><a href="#contact"><?= cpmsSiteEscape($t['nav_contact']) ?></a></div>
        <div class="nav-actions"><a class="lang" href="/?lang=<?= $isEn ? 'ms' : 'en' ?>"><?= $isEn ? 'BM' : 'EN' ?></a><a class="login" href="https://app.cpmspro.my"><?= cpmsSiteEscape($t['login']) ?> →</a></div>
    </div>
</nav>
<header class="hero">
    <div class="wrap hero-grid">
        <div><div class="eyebrow"><?= cpmsSiteEscape($t['eyebrow']) ?></div><h1><?= cpmsSiteEscape($t['hero_title']) ?></h1><p><?= cpmsSiteEscape($t['hero_text']) ?></p><div class="hero-cta"><a class="btn btn-primary" href="#modules"><?= cpmsSiteEscape($t['explore']) ?> →</a><a class="btn btn-ghost" href="https://app.cpmspro.my"><?= cpmsSiteEscape($t['open_app']) ?></a></div></div>
        <div class="hero-card"><div class="mini-top"><span>CPMSPro Operations</span><span class="status">● Online</span></div><div class="metric"><strong>Multi-Property</strong><span>Unified operational workspace</span></div><div class="bar"><i></i></div><div class="mini-grid"><div class="mini"><b>360°</b><span>Operational visibility</span></div><div class="mini"><b>1</b><span>Unified platform</span></div><div class="mini"><b>Web</b><span>Management & resident</span></div><div class="mini"><b>Mobile</b><span>Workforce & security</span></div></div></div>
    </div>
</header>
<div class="trust"><?= cpmsSiteEscape($t['trusted']) ?></div>
<section id="about"><div class="wrap about-grid"><div class="section-head"><div class="kicker"><?= cpmsSiteEscape($t['about_kicker']) ?></div><h2><?= cpmsSiteEscape($t['about_title']) ?></h2><p><?= cpmsSiteEscape($t['about_text']) ?></p></div><div class="about-stat"><div class="stat-card"><b>Multi-Property</b><span>Separate data and branding for every managed property.</span></div><div class="stat-card"><b>Role-Based</b><span>System Owner, Property Admin, Staff, Security and Resident.</span></div><div class="stat-card"><b>Evidence</b><span>Photos, records, timestamps and operational history.</span></div><div class="stat-card"><b>BM / EN</b><span>Bilingual experience for management and workforce.</span></div></div></div></section>
<section class="modules" id="modules"><div class="wrap"><div class="section-head"><div class="kicker"><?= cpmsSiteEscape($t['modules_kicker']) ?></div><h2><?= cpmsSiteEscape($t['modules_title']) ?></h2></div><div class="module-grid"><?php foreach ($modules as $module): ?><article class="module"><div class="module-num"><?= cpmsSiteEscape($module[0]) ?></div><h3><?= cpmsSiteEscape($module[1]) ?></h3><p><?= cpmsSiteEscape($module[2]) ?></p></article><?php endforeach; ?></div></div></section>
<section class="solution" id="solution"><div class="wrap solution-grid"><div class="solution-visual"><div class="mini-top"><span>CPMSPro Domain Architecture</span><span class="status">● Active</span></div><div class="domain"><div><strong>app.cpmspro.my</strong><span>Unified system login</span></div><em>APP</em></div><div class="domain"><div><strong>v23.cpmspro.my</strong><span>Property portal</span></div><em>V23</em></div><div class="domain"><div><strong>tmj.cpmspro.my</strong><span>Property portal</span></div><em>TMJ</em></div></div><div class="solution-copy"><div class="kicker"><?= cpmsSiteEscape($t['solution_kicker']) ?></div><h2><?= cpmsSiteEscape($t['solution_title']) ?></h2><p><?= cpmsSiteEscape($t['solution_text']) ?></p><div class="points"><?php foreach (['point1','point2','point3','point4'] as $point): ?><div class="point"><span class="check">✓</span><span><?= cpmsSiteEscape($t[$point]) ?></span></div><?php endforeach; ?></div></div></div></section>
<section class="cta-wrap"><div class="wrap"><div class="cta"><div class="kicker"><?= cpmsSiteEscape($t['cta_kicker']) ?></div><h2><?= cpmsSiteEscape($t['cta_title']) ?></h2><p><?= cpmsSiteEscape($t['cta_text']) ?></p><div class="hero-cta"><a class="btn btn-primary" href="https://app.cpmspro.my"><?= cpmsSiteEscape($t['cta_login']) ?> →</a><a class="btn btn-ghost" href="#contact"><?= cpmsSiteEscape($t['cta_contact']) ?></a></div></div></div></section>
<section class="contact" id="contact"><div class="wrap"><div class="contact-box"><div><h3><?= cpmsSiteEscape($t['contact_title']) ?></h3><p><?= cpmsSiteEscape($t['contact_text']) ?></p><p class="note"><?= cpmsSiteEscape($t['contact_note']) ?></p></div><a class="btn btn-primary" href="https://app.cpmspro.my"><?= cpmsSiteEscape($t['cta_login']) ?> →</a></div></div></section>
<footer class="footer"><div class="wrap footer-inner"><div><strong>CPMSPro</strong><br><small><?= cpmsSiteEscape($t['footer']) ?></small></div><small>© <?= date('Y') ?> CPMSPro. All rights reserved.</small></div></footer>
</body>
</html>
