<?php
declare(strict_types=1);

$httpsReady = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);

$files = [
    'cpms-mobile-sw.js',
    'cpms-mobile-offline.html',
    'staff_manifest.php',
    'security_manifest.php',
    'pwa/cpms-mobile.js',
    'pwa/cpms-mobile.css',
    'pwa/icons/icon-192.png',
    'pwa/icons/icon-512.png',
];

$checks = [];
foreach ($files as $file) {
    $checks[$file] = is_file(__DIR__ . '/' . $file);
}

$passed = $httpsReady && !in_array(false, $checks, true);
?>
<!doctype html>
<html lang="ms">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS v3.4.0 PWA Health</title>
    <style>
        body{margin:0;padding:22px;background:#eef3f8;color:#10213c;font-family:Arial}
        main{max-width:760px;margin:auto}.card{margin-bottom:16px;padding:22px;border:1px solid #dbe3ec;border-radius:15px;background:#fff}
        .pass{color:#087b35}.fail{color:#b42318}table{width:100%;border-collapse:collapse}td{padding:10px;border-bottom:1px solid #e2e8f0;overflow-wrap:anywhere}
        a{display:inline-block;margin:8px 8px 0 0;padding:11px 14px;border-radius:9px;background:#173b73;color:#fff;text-decoration:none;font-weight:800}
    </style>
</head>
<body>
<main>
    <section class="card">
        <h1>CPMS v3.4.0 — Mobile PWA Health</h1>
        <h2 class="<?php echo $passed ? 'pass' : 'fail'; ?>">
            <?php echo $passed ? 'PASS' : 'FAIL'; ?>
        </h2>
        <p>HTTPS:
            <strong><?php echo $httpsReady ? 'Ready' : 'Required'; ?></strong>
        </p>
    </section>
    <section class="card">
        <table>
            <?php foreach ($checks as $file => $ready): ?>
                <tr>
                    <td><?php echo htmlspecialchars(
                        $file,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?></td>
                    <td class="<?php echo $ready ? 'pass' : 'fail'; ?>">
                        <?php echo $ready ? 'Ready' : 'Missing'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <a href="staff_dashboard.php">Staff PWA</a>
        <a href="security_dashboard.php">Security PWA</a>
    </section>
</main>
</body>
</html>
