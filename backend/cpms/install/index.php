<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/foundation_bootstrap.php';

function installEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function installTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int) (
        $stmt->get_result()->fetch_assoc()['total'] ?? 0
    ) > 0;
    $stmt->close();

    return $exists;
}

$checks = [
    'cpms_properties' => installTableExists($conn, 'cpms_properties'),
    'property_admins' => installTableExists($conn, 'property_admins'),
    'cpms_property_modules' => installTableExists($conn, 'cpms_property_modules'),
    'cpms_system_versions' => installTableExists($conn, 'cpms_system_versions'),
    'property_admins' => installTableExists($conn, 'property_admins'),
];

$allPass = !in_array(false, $checks, true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>CPMS Installer Check</title>
    <style>
        body{margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif;padding:24px}
        .wrap{max-width:760px;margin:auto}
        .card{background:#fff;border:1px solid #dfe6ef;border-radius:14px;padding:24px;box-shadow:0 12px 30px rgba(15,23,42,.06)}
        .item{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #eef2f7}
        .pass{color:#166534;font-weight:900}.fail{color:#991b1b;font-weight:900}
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <h1>CPMS Foundation Installer Check</h1>
        <p>This page only checks required components.</p>

        <?php foreach ($checks as $name => $pass): ?>
            <div class="item">
                <strong><?php echo installEscape($name); ?></strong>
                <span class="<?php echo $pass ? 'pass' : 'fail'; ?>">
                    <?php echo $pass ? 'PASS' : 'MISSING'; ?>
                </span>
            </div>
        <?php endforeach; ?>

        <p>
            Overall:
            <strong class="<?php echo $allPass ? 'pass' : 'fail'; ?>">
                <?php echo $allPass ? 'READY' : 'ACTION REQUIRED'; ?>
            </strong>
        </p>
    </section>
</div>
</body>
</html>
