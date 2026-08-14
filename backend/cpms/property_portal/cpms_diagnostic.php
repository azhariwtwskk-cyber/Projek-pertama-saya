<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once __DIR__ . '/auth.php';
cpmsPropertyRequire('settings.manage');

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cpmsDiagnosticEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cpmsDiagnosticCheck(
    string $label,
    bool $passed,
    string $help = ''
): array {
    return [
        'label' => $label,
        'passed' => $passed,
        'help' => $help,
    ];
}

$checks = [];

try {
    $checks[] = cpmsDiagnosticCheck(
        'PHP version is supported',
        version_compare(PHP_VERSION, '8.1.0', '>=')
    );

    $checks[] = cpmsDiagnosticCheck(
        'Database connection is available',
        isset($conn) && $conn instanceof mysqli
    );

    $checks[] = cpmsDiagnosticCheck(
        'Canonical branding helper is loaded',
        function_exists('cpmsBrandingHex')
            && function_exists('cpmsBrandingAssetUrl')
    );

    $requiredTables = [
        'cpms_properties',
        'property_admins',
        'cpms_property_modules',
        'complaints',
        'work_orders',
    ];

    foreach ($requiredTables as $table) {
        $safeTable = str_replace('`', '', $table);
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($safeTable) . "'");
        $checks[] = cpmsDiagnosticCheck(
            'Database table: ' . $safeTable,
            $result instanceof mysqli_result && $result->num_rows > 0
        );
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    $propertyStmt = $conn->prepare(
        'SELECT id FROM cpms_properties WHERE id = ? LIMIT 1'
    );
    $propertyFound = false;

    if ($propertyStmt) {
        $propertyStmt->bind_param('i', $currentPropertyId);
        $propertyStmt->execute();
        $propertyFound = (bool) $propertyStmt->get_result()->fetch_assoc();
        $propertyStmt->close();
    }

    $checks[] = cpmsDiagnosticCheck(
        'Current property context is valid',
        $currentPropertyId > 0 && $propertyFound
    );

    $uploadRoot = dirname(__DIR__) . '/uploads/branding';
    $uploadParent = is_dir($uploadRoot)
        ? $uploadRoot
        : dirname($uploadRoot);

    $checks[] = cpmsDiagnosticCheck(
        'Branding upload location is writable',
        is_dir($uploadParent) && is_writable($uploadParent),
        'Check folder permission for cpms/uploads when this test fails.'
    );
} catch (Throwable $exception) {
    cpmsFoundationLog('Property Portal diagnostic failed.');
    $checks[] = cpmsDiagnosticCheck(
        'Diagnostic completed without an internal exception',
        false,
        'Review the server PHP error log.'
    );
}

$passedCount = count(array_filter(
    $checks,
    static fn(array $check): bool => $check['passed'] === true
));
$totalCount = count($checks);
$allPassed = $totalCount > 0 && $passedCount === $totalCount;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>CPMS Diagnostic</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:32px 16px}
        main{max-width:900px;margin:auto}
        .summary,.check{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:14px}
        .pass{border-left:6px solid #16a34a}.fail{border-left:6px solid #dc2626}
        h1{margin-top:0}.muted{color:#64748b}.badge{font-weight:700}
        a{color:#2563eb;text-decoration:none}
    </style>
</head>
<body>
<main>
    <div class="summary <?php echo $allPassed ? 'pass' : 'fail'; ?>">
        <h1>CPMS Property Portal Diagnostic</h1>
        <p class="badge">
            <?php echo $allPassed ? 'PASS' : 'ACTION REQUIRED'; ?> —
            <?php echo $passedCount; ?>/<?php echo $totalCount; ?> checks passed
        </p>
        <p class="muted">
            This page is restricted to authorised property administrators and
            does not display server paths, SQL errors or stack traces.
        </p>
    </div>

    <?php foreach ($checks as $check): ?>
        <div class="check <?php echo $check['passed'] ? 'pass' : 'fail'; ?>">
            <strong><?php echo $check['passed'] ? 'PASS' : 'FAIL'; ?></strong>
            — <?php echo cpmsDiagnosticEscape($check['label']); ?>
            <?php if (!$check['passed'] && $check['help'] !== ''): ?>
                <p class="muted"><?php echo cpmsDiagnosticEscape($check['help']); ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <p><a href="dashboard.php">← Return to dashboard</a></p>
</main>
</body>
</html>
