<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2)
    . '/includes/property_theme.php';
require_once dirname(__DIR__, 2)
    . '/includes/genesis_ui.php';
// Branding helpers are already loaded by config.php/auth.php.
// Do not include property_portal/includes/branding.php again.

$pageTitle = $pageTitle ?? 'Dashboard';
$activeMenu = $activeMenu ?? 'dashboard';
$pageStyles = isset($pageStyles) && is_array($pageStyles)
    ? $pageStyles
    : [];

$primaryColor = $propertyPortalUser['primary_color'] ?? '#2563eb';
$secondaryColor = $propertyPortalUser['secondary_color'] ?? '#0f172a';
$primaryHex = ltrim((string) $primaryColor, '#');
$propertyOnPrimary = '#ffffff';
if (preg_match('/^[0-9a-fA-F]{6}$/', $primaryHex) === 1) {
    $primaryBrightness = (
        (hexdec(substr($primaryHex, 0, 2)) * 299)
        + (hexdec(substr($primaryHex, 2, 2)) * 587)
        + (hexdec(substr($primaryHex, 4, 2)) * 114)
    ) / 1000;
    $propertyOnPrimary = $primaryBrightness >= 150
        ? '#10213d'
        : '#ffffff';
}
$systemName = cpmsBrandingSystemName($propertyPortalUser);
$faviconUrl = cpmsBrandingAssetUrl($propertyPortalUser['favicon_path'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>
        <?php echo propertyPortalEscape($pageTitle); ?>
        | <?php echo propertyPortalEscape($systemName); ?>
    </title>

    <style>
        :root {
            --property-primary:
                <?php echo propertyPortalEscape($primaryColor); ?>;
            --property-secondary:
                <?php echo propertyPortalEscape($secondaryColor); ?>;
            --property-on-primary:
                <?php echo propertyPortalEscape($propertyOnPrimary); ?>;
            /* Genesis follows each property's own branding colour. */
            --cpms-primary: var(--property-primary);
            --cpms-primary-hover: var(--property-primary);
        }
    </style>

    <?php cpmsGenesisHead(); ?>

    <link rel="stylesheet" href="assets/ui-foundation-6.css">
    <link rel="stylesheet" href="assets/smart-table.css">
    <link rel="stylesheet" href="assets/branding.css">
    <link rel="stylesheet" href="assets/logo-ui-polish.css">
    <link rel="stylesheet" href="assets/rc1-responsive.css">
    <link rel="stylesheet" href="assets/enterprise-ui-v2-1.css?v=2101">
    <link rel="stylesheet"
          href="../assets/cpms-responsive-global.css?v=337">

    <?php foreach ($pageStyles as $pageStyle): ?>
        <link rel="stylesheet"
              href="<?php echo propertyPortalEscape((string) $pageStyle); ?>">
    <?php endforeach; ?>

    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" href="<?php echo propertyPortalEscape($faviconUrl); ?>">
    <?php endif; ?>
</head>

<body style="<?php echo cpmsThemeCssVariables($propertyPortalUser); ?>">
<div class="admin-shell">
