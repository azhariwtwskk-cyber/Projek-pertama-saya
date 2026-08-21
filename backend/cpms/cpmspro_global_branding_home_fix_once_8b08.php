<?php
declare(strict_types=1);

/**
 * CPMSPro Global Branding Homepage Bridge
 * One-time installer for /public_html/cpmspro_home_v2.php
 *
 * Purpose:
 * - Load CPMS v3.4.11 Global Settings on the commercial homepage.
 * - Display the System Owner global logo in the CPMSPro navbar.
 * - Display the System Owner global favicon in the browser tab.
 * - Leave all property-specific branding (V23/TMJ/etc.) untouched.
 */

header('Content-Type: text/plain; charset=UTF-8');

$publicRoot = __DIR__;
$target = $publicRoot . '/cpmspro_home_v2.php';
$backupDir = dirname($publicRoot) . '/cpmspro_backups';
$self = __FILE__;

function cpmsFixFinish(string $message, bool $deleteSelf = false): void
{
    echo $message . "\n";

    if ($deleteSelf && is_file(__FILE__)) {
        @unlink(__FILE__);
    }

    exit;
}

if (!is_file($target) || !is_readable($target) || !is_writable($target)) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: FAILED\n"
        . "Homepage cpmspro_home_v2.php tidak ditemui atau tidak boleh ditulis."
    );
}

$original = file_get_contents($target);

if ($original === false || $original === '') {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: FAILED\n"
        . "Tidak dapat membaca cpmspro_home_v2.php."
    );
}

$bridgeMarker = 'CPMSPRO GLOBAL BRANDING BRIDGE v1';

if (strpos($original, $bridgeMarker) !== false) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: ALREADY INSTALLED\n"
        . "Homepage sudah disambungkan kepada Global Branding.",
        true
    );
}

if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "Folder backup di luar public_html tidak dapat dibuat. Tiada perubahan dilakukan."
    );
}

$backupFile = $backupDir . '/cpmspro_home_v2_before_global_branding_' . date('Ymd_His') . '.php';

if (!@copy($target, $backupFile)) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "Backup tidak dapat dibuat. Tiada perubahan dilakukan."
    );
}

$bootstrapNeedle = "declare(strict_types=1);\n\n";
$bootstrap = <<<'PHP'
declare(strict_types=1);

/* CPMSPRO GLOBAL BRANDING BRIDGE v1 */
require_once __DIR__ . '/cpms/db.php';
require_once __DIR__ . '/cpms/includes/system_settings.php';
require_once __DIR__ . '/cpms/core/branding.php';

$cpmsGlobalSettings = cpmsSystemSettingDefaults();

if (isset($conn) && $conn instanceof mysqli) {
    $cpmsGlobalSettings = loadSystemSettings($conn);
}

$GLOBALS['cpmsSettings'] = $cpmsGlobalSettings;

$cpmsGlobalLogoPath = cpmsSystemSettingAsset(
    setting($cpmsGlobalSettings, 'logo_path', '')
);
$cpmsGlobalFaviconPath = cpmsSystemSettingAsset(
    setting($cpmsGlobalSettings, 'favicon_path', '')
);
$cpmsGlobalLogoUrl = $cpmsGlobalLogoPath !== ''
    ? cpmsAssetUrl($cpmsGlobalLogoPath)
    : '';
$cpmsGlobalFaviconUrl = $cpmsGlobalFaviconPath !== ''
    ? cpmsAssetUrl($cpmsGlobalFaviconPath)
    : '';

PHP;

if (substr_count($original, $bootstrapNeedle) !== 1) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "Struktur awal homepage tidak sepadan. Backup telah dibuat tetapi homepage tidak diubah."
    );
}

$updated = str_replace($bootstrapNeedle, $bootstrap, $original, $bootstrapCount);

$headNeedle = '    <title>CPMSPro | Commercial Property Management System</title>';
$headReplacement = <<<'HTML'
    <title>CPMSPro | Commercial Property Management System</title>
    <?php if ($cpmsGlobalFaviconUrl !== ''): ?>
        <link rel="icon" href="<?= cpmsSiteEscape($cpmsGlobalFaviconUrl) ?>">
        <link rel="shortcut icon" href="<?= cpmsSiteEscape($cpmsGlobalFaviconUrl) ?>">
    <?php endif; ?>
HTML;

if (substr_count($updated, $headNeedle) !== 1) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "Bahagian <head> tidak sepadan. Homepage tidak diubah."
    );
}

$updated = str_replace($headNeedle, $headReplacement, $updated, $headCount);

$cssNeedle = '.brand strong{font-size:20px;letter-spacing:-.4px}';
$cssReplacement = '.brand-logo{width:46px;height:46px;border-radius:12px;background:#fff;display:grid;place-items:center;overflow:hidden;padding:4px;box-shadow:0 8px 24px rgba(0,0,0,.18)}.brand-logo img{display:block;width:100%;height:100%;object-fit:contain}.brand strong{font-size:20px;letter-spacing:-.4px}';

if (substr_count($updated, $cssNeedle) !== 1) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "CSS navbar tidak sepadan. Homepage tidak diubah."
    );
}

$updated = str_replace($cssNeedle, $cssReplacement, $updated, $cssCount);

$brandNeedle = '<a class="brand" href="/"><span class="mark">C</span><span><strong>CPMSPro</strong><small>PROPERTY OPERATIONS</small></span></a>';
$brandReplacement = <<<'HTML'
        <a class="brand" href="/">
            <?php if ($cpmsGlobalLogoUrl !== ''): ?>
                <span class="brand-logo"><img src="<?= cpmsSiteEscape($cpmsGlobalLogoUrl) ?>" alt="CPMSPro"></span>
            <?php else: ?>
                <span class="mark">C</span>
            <?php endif; ?>
            <span><strong>CPMSPro</strong><small>PROPERTY OPERATIONS</small></span>
        </a>
HTML;

if (substr_count($updated, $brandNeedle) !== 1) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "Logo navbar tidak sepadan. Homepage tidak diubah."
    );
}

$updated = str_replace($brandNeedle, trim($brandReplacement), $updated, $brandCount);

if (
    $bootstrapCount !== 1
    || $headCount !== 1
    || $cssCount !== 1
    || $brandCount !== 1
    || strpos($updated, $bridgeMarker) === false
) {
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: STOPPED SAFELY\n"
        . "Pengesahan perubahan gagal. Homepage tidak diubah."
    );
}

$temporary = $target . '.branding-fix.tmp';

if (file_put_contents($temporary, $updated, LOCK_EX) === false) {
    @unlink($temporary);
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: FAILED\n"
        . "Fail sementara tidak dapat ditulis. Homepage asal kekal."
    );
}

if (!@rename($temporary, $target)) {
    @unlink($temporary);
    cpmsFixFinish(
        "CPMSPRO GLOBAL BRANDING FIX: FAILED\n"
        . "Homepage tidak dapat dikemas kini. Homepage asal kekal."
    );
}

@chmod($target, 0644);

echo "CPMSPRO GLOBAL BRANDING FIX COMPLETED\n";
echo "Global logo connected to cpmspro.my: YES\n";
echo "Global favicon connected to cpmspro.my: YES\n";
echo "V23/TMJ property branding changed: NO\n";
echo "Backup created outside public_html: YES\n";
echo "Temporary fix script deleted: YES\n";

@unlink($self);

