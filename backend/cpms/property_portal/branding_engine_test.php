<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/branding.php';

if (empty($_SESSION['property_admin_id'])) {
    propertyPortalRedirect('login.php');
}

$role = (string) ($_SESSION['property_admin_role'] ?? '');

if (!in_array($role, ['property_admin', 'manager'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

function btestEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$propertyId = (int) (
    $_SESSION['property_admin_property_id']
    ?? 0
);

$stmt = $conn->prepare(
    "SELECT
        property_code,
        property_name,
        logo_path,
        background_path,
        favicon_path
     FROM cpms_properties
     WHERE id = ?
     LIMIT 1"
);

$property = [];

if ($stmt) {
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $property = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

$tests = [
    'Logo' => (string) ($property['logo_path'] ?? ''),
    'Background' => (string) ($property['background_path'] ?? ''),
    'Favicon' => (string) ($property['favicon_path'] ?? ''),
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Branding Engine Test</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7fb;color:#172033;padding:24px}
        .card{max-width:900px;margin:auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 18px 55px rgba(15,23,42,.12)}
        .item{padding:18px 0;border-bottom:1px solid #e5e7eb}
        .preview{max-width:100%;max-height:260px;display:block;margin-top:12px;border-radius:12px;border:1px solid #d7dee8}
        code{display:block;padding:10px;margin-top:8px;background:#eef2f7;border-radius:8px;overflow-wrap:anywhere}
        a{color:#2563eb}
    </style>
</head>
<body>
<div class="card">
    <h1>CPMS Branding Engine v3 Test</h1>

    <p>
        Property:
        <strong><?php echo btestEscape(
            (string) ($property['property_name'] ?? '')
        ); ?></strong>
    </p>

    <?php foreach ($tests as $label => $path): ?>
        <?php $url = cpmsAssetUrl($path); ?>

        <div class="item">
            <h2><?php echo btestEscape($label); ?></h2>

            <strong>Database path</strong>
            <code><?php echo btestEscape($path); ?></code>

            <strong>Generated URL</strong>
            <code><?php echo btestEscape($url); ?></code>

            <?php if ($url !== ''): ?>
                <a
                    href="<?php echo btestEscape($url); ?>"
                    target="_blank"
                    rel="noopener"
                >
                    Open image directly
                </a>

                <img
                    class="preview"
                    src="<?php echo btestEscape($url); ?>"
                    alt=""
                >
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <p>
        <a href="branding_settings.php">Back to Branding Settings</a>
    </p>
</div>
</body>
</html>
