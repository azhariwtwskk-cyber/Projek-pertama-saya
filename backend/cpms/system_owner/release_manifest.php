<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$manifestFile = dirname(__DIR__)
    . '/release_manifest.json';

$manifest = [];

if (is_file($manifestFile)) {
    $decoded = json_decode(
        (string) file_get_contents($manifestFile),
        true
    );

    if (is_array($decoded)) {
        $manifest = $decoded;
    }
}

function manifestEscape(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport"
          content="width=device-width,initial-scale=1">
    <title>Release Manifest | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        .manifest-grid{display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:14px;margin-top:18px}
        .manifest-card{padding:16px;border:1px solid #e2e8f0;
        border-radius:12px;background:#f8fafc}
        .manifest-card small{display:block;color:#64748b;
        margin-bottom:6px}
        .manifest-list{display:grid;gap:9px;margin-top:16px}
        .manifest-list div{padding:11px;border:1px solid #e2e8f0;
        border-radius:9px}
        @media(max-width:700px){.manifest-grid{
        grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="so-dashboard">
    <header class="so-topbar">
        <div>
            <strong>CPMS Release Manifest</strong><br>
            <small>Foundation release information</small>
        </div>
        <a href="dashboard.php">Dashboard</a>
    </header>

    <section class="so-panel">
        <h2>
            <?php echo manifestEscape(
                $manifest['release_name']
                ?? 'Unknown release'
            ); ?>
        </h2>

        <div class="manifest-grid">
            <div class="manifest-card">
                <small>Version</small>
                <strong>
                    <?php echo manifestEscape(
                        $manifest['version'] ?? '-'
                    ); ?>
                </strong>
            </div>
            <div class="manifest-card">
                <small>Phase</small>
                <strong>
                    <?php echo manifestEscape(
                        $manifest['phase'] ?? '-'
                    ); ?>
                </strong>
            </div>
            <div class="manifest-card">
                <small>Database Contract</small>
                <strong>
                    <?php echo manifestEscape(
                        $manifest['database_contract']
                        ?? '-'
                    ); ?>
                </strong>
            </div>
        </div>

        <h3>Protected Portals</h3>
        <div class="manifest-list">
            <?php foreach (
                ($manifest['protected_portals'] ?? [])
                as $portal
            ): ?>
                <div><?php echo manifestEscape($portal); ?></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
</body>
</html>
