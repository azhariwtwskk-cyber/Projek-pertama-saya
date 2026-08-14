<?php
if (!isset($hqInspector)) {
    exit;
}
$documentTitle = isset($pageTitle) && trim((string) $pageTitle) !== ''
    ? (string) $pageTitle . ' | CPMS'
    : 'HQ Inspector | CPMS';
$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$dashboardView = trim((string) ($_GET['view'] ?? ''));
$isDashboard = $currentPage === 'dashboard.php' && $dashboardView === '';
$isProperties = $currentPage === 'dashboard.php' && $dashboardView === 'properties';
$isInspections = in_array(
    $currentPage,
    ['inspections.php', 'inspection_create.php', 'inspection_view.php', 'inspection_report.php'],
    true
);
$isActions = $currentPage === 'action_review.php' || ($currentPage === 'dashboard.php' && $dashboardView === 'actions');
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo hqiEscape($documentTitle); ?></title>
    <style>
        :root{--navy:#111827;--blue:#1d4ed8;--slate:#64748b;--line:#e5eaf1;--surface:#fff;--bg:#f5f7fb;--sidebar:#111827;--radius:12px;--shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px rgba(15,23,42,.045)}*{box-sizing:border-box}html{font-size:14px}body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;margin:0;background:var(--bg);color:#172033;font-size:14px;line-height:1.45}.admin-shell{min-height:100vh;background:var(--bg)}.admin-sidebar{position:fixed;inset:0 auto 0 0;width:244px;background:var(--sidebar);color:#fff;padding:14px 10px;z-index:80;display:flex;flex-direction:column}.sidebar-brand{display:flex;align-items:center;gap:10px;min-height:58px;padding:7px 9px 14px;margin-bottom:8px;border-bottom:1px solid rgba(255,255,255,.08)}.sidebar-logo{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(135deg,#3b82f6,#1d4ed8);font-weight:900;flex:0 0 38px}.sidebar-brand-text{min-width:0}.sidebar-brand-text strong{display:block;font-size:13px;line-height:1.25;color:#fff;white-space:normal}.sidebar-brand-text small{display:block;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#9ca3af;margin-top:2px}.sidebar-menu{flex:1;overflow:auto;padding:3px 0}.sidebar-section-label{display:block;font-size:9px;letter-spacing:.14em;text-transform:uppercase;color:#778197;padding:14px 11px 6px}.sidebar-link{min-height:38px;padding:8px 10px;border-radius:9px;font-size:12.5px;font-weight:600;color:#c8d0dd;display:flex;align-items:center;gap:10px;margin:1px 0;text-decoration:none}.sidebar-link:hover{background:rgba(255,255,255,.07);color:#fff}.sidebar-link.active{background:#1d4ed8;color:#fff;box-shadow:inset 3px 0 0 rgba(255,255,255,.7)}.menu-icon{width:18px;text-align:center;opacity:.9}.sidebar-account{display:flex;align-items:center;gap:8px;padding:10px 8px 2px;border-top:1px solid rgba(255,255,255,.08)}.account-avatar{display:grid;place-items:center;width:32px;height:32px;border-radius:9px;background:#263449;color:#fff;font-weight:800;flex:0 0 32px}.account-copy{min-width:0;flex:1}.account-copy small,.account-copy span{display:block;font-size:9.5px;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.account-copy strong{display:block;font-size:11.5px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sign-out-link{display:grid;place-items:center;width:28px;height:28px;border-radius:8px;background:rgba(255,255,255,.08);color:#fff;text-decoration:none}.admin-main{margin-left:244px;min-width:0;min-height:100vh;display:flex;flex-direction:column}.admin-topbar{height:64px;min-height:64px;padding:0 24px;background:rgba(255,255,255,.94);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:14px;backdrop-filter:blur(10px)}.topbar-left{display:flex;align-items:center;gap:12px;min-width:0}.sidebar-toggle{display:none;width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:#fff;padding:7px}.sidebar-toggle span{display:block;height:2px;width:15px;margin:4px auto;background:#172033}.topbar-title{display:flex;flex-direction:column;gap:1px;min-width:0}.topbar-title strong{font-size:16px;line-height:1.2;font-weight:700;color:#172033;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-context{font-size:10.5px;color:#687386;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-actions{display:flex;align-items:center;gap:10px}.topbar-search{height:34px;width:210px;display:flex;align-items:center;border:1px solid var(--line);border-radius:9px;background:#f8fafc;padding:0 10px}.topbar-search input{width:100%;border:0;outline:0;background:transparent;padding:0;margin:0;font-size:11.5px;color:#172033}.topbar-user-chip{display:flex;align-items:center;gap:7px}.topbar-user-chip>span{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:#111827;color:#fff;font-weight:800;font-size:11px}.topbar-user-chip div{display:flex;flex-direction:column;max-width:150px}.topbar-user-chip strong{font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.topbar-user-chip small{font-size:9px;color:#687386}.admin-content{padding:20px 24px 28px;max-width:1600px;margin:0 auto;width:100%}.wrap{max-width:none;margin:0;padding:0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px}.card{background:#fff;border-radius:var(--radius);padding:16px;box-shadow:var(--shadow);border:1px solid var(--line);margin-bottom:16px}a.btn,button:not(.sidebar-toggle){display:inline-block;background:var(--navy);color:#fff;min-height:34px;padding:7px 12px;border-radius:9px;text-decoration:none;border:0;font-weight:700;font-size:11.5px;cursor:pointer}.muted{color:var(--slate);font-size:11.5px}input,select,textarea{width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid var(--line);border-radius:9px;margin:6px 0 14px;background:#fff;color:#172033;font-size:12.5px}table{width:100%;border-collapse:collapse;background:#fff;font-size:11.5px}th,td{padding:9px 10px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:top}th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#667085;background:#f8fafc}.ok{background:#dcfce7;color:#166534;padding:10px;border-radius:8px;margin-bottom:10px}.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}@media(max-width:1100px){.topbar-search{display:none}}@media(max-width:820px){.admin-sidebar{transform:translateX(-100%);transition:transform .2s ease}.admin-sidebar.open{transform:translateX(0)}.admin-main{margin-left:0}.sidebar-toggle{display:block}.admin-topbar{padding:0 14px}.admin-content{padding:14px}.topbar-user-chip div{display:none}}@media(max-width:480px){.admin-content{padding:12px}.topbar-title strong{font-size:14px}.card{padding:14px}th,td{padding:8px}}
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://api.fontshare.com/v2/css?f[]=general-sans@600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/genesis.css?v=7.0.0">
    <link rel="stylesheet" href="assets/genesis-smooth.css?v=1.0.0">
</head>
<body>
<div class="admin-shell">
<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">HQ</div>
        <div class="sidebar-brand-text">
            <strong>HQ Inspector</strong>
            <small>CPMS Operations</small>
        </div>
    </div>
    <nav class="sidebar-menu" aria-label="HQ Inspector navigation">
        <span class="sidebar-section-label">Operations</span>
        <a class="sidebar-link <?php echo $isDashboard ? 'active' : ''; ?>" href="dashboard.php">
            <span class="menu-icon">DB</span><span>Dashboard</span>
        </a>
        <a class="sidebar-link <?php echo $isProperties ? 'active' : ''; ?>" href="dashboard.php?view=properties#properties">
            <span class="menu-icon">PR</span><span>Properties</span>
        </a>
        <a class="sidebar-link <?php echo $isInspections ? 'active' : ''; ?>" href="inspections.php">
            <span class="menu-icon">IN</span><span>Inspections</span>
        </a>
        <a class="sidebar-link <?php echo $isActions ? 'active' : ''; ?>" href="dashboard.php?view=actions#actions">
            <span class="menu-icon">AR</span><span>Action Review</span>
        </a>
    </nav>
    <div class="sidebar-account">
        <div class="account-avatar"><?php echo hqiEscape(strtoupper(substr((string) $hqInspector['full_name'], 0, 1))); ?></div>
        <div class="account-copy">
            <small>Signed in as</small>
            <strong><?php echo hqiEscape((string) $hqInspector['full_name']); ?></strong>
            <span><?php echo hqiEscape((string) $hqInspector['inspector_code']); ?></span>
        </div>
        <a class="sign-out-link" href="logout.php" title="Logout">X</a>
    </div>
</aside>
<div class="admin-main">
    <header class="admin-topbar">
        <div class="topbar-left">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation" aria-controls="adminSidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="topbar-title">
                <strong><?php echo hqiEscape((string) ($pageTitle ?? 'Dashboard')); ?></strong>
                <span class="topbar-context">HQ Inspector · Inspection & Compliance</span>
            </div>
        </div>
        <div class="topbar-actions">
            <div class="topbar-search" role="search">
                <input type="search" id="cpmsGlobalSearch" placeholder="Quick search" aria-label="Quick search current page">
            </div>
            <div class="topbar-user-chip" title="<?php echo hqiEscape((string) $hqInspector['full_name']); ?>">
                <span><?php echo hqiEscape(strtoupper(substr((string) $hqInspector['full_name'], 0, 1))); ?></span>
                <div>
                    <strong><?php echo hqiEscape((string) $hqInspector['full_name']); ?></strong>
                    <small>HQ Inspector</small>
                </div>
            </div>
        </div>
    </header>
    <main class="admin-content">
