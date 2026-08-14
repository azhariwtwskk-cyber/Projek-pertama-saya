<?php
declare(strict_types=1);

/*
 * CPMSPro one-time Property Domain Guard installer.
 * Upload to: /public_html/cpms_property_domain_guard_once_8b08.php
 * Open once in the browser, then it deletes itself after success.
 */

header('Content-Type: text/plain; charset=utf-8');

$publicRoot = __DIR__;
$authFile = $publicRoot . '/cpms/property_portal/auth.php';
$marker = 'CPMS v4.1.1 property-subdomain binding guard';

if (!is_file($authFile) || !is_readable($authFile) || !is_writable($authFile)) {
    http_response_code(500);
    exit("INSTALL FAILED\nProperty Portal auth.php is unavailable or not writable.\n");
}

$source = file_get_contents($authFile);
if (!is_string($source) || $source === '') {
    http_response_code(500);
    exit("INSTALL FAILED\nUnable to read Property Portal auth.php.\n");
}

if (strpos($source, $marker) !== false) {
    echo "CPMSPRO PROPERTY DOMAIN GUARD ALREADY INSTALLED\n";
    echo "No change required.\n";
    @unlink(__FILE__);
    exit;
}

$anchor = '$currentPropertyRole = (string) $propertyPortalUser[\'role\'];';
$anchorPos = strpos($source, $anchor);
if ($anchorPos === false) {
    http_response_code(500);
    exit("INSTALL FAILED\nVerified auth.php insertion point was not found. No change was made.\n");
}

$guard = <<<'PHP'


/* CPMS v4.1.1 property-subdomain binding guard.
 * A Property Portal session may only operate on a property-specific host
 * when that host belongs to the same property_id as the authenticated user.
 */
if (!function_exists('cpmsPropertyNormaliseHost')) {
    function cpmsPropertyNormaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if (strpos($host, '://') === false) {
            $host = 'https://' . $host;
        }
        $parsed = parse_url($host, PHP_URL_HOST);
        if (!is_string($parsed) || $parsed === '') {
            return '';
        }
        $parsed = strtolower(rtrim($parsed, '.'));
        if (strpos($parsed, 'www.') === 0) {
            $parsed = substr($parsed, 4);
        }
        return $parsed;
    }
}

$requestHost = cpmsPropertyNormaliseHost(
    (string) ($_SERVER['HTTP_HOST'] ?? '')
);
$hostPropertyId = 0;
$currentPropertyWebsite = '';

$domainStmt = $conn->prepare(
    "SELECT id, website
     FROM cpms_properties
     WHERE website IS NOT NULL
       AND TRIM(website) <> ''
       AND is_active = 1"
);

if ($domainStmt) {
    $domainStmt->execute();
    $domainResult = $domainStmt->get_result();
    while ($domainRow = $domainResult->fetch_assoc()) {
        $rowPropertyId = (int) ($domainRow['id'] ?? 0);
        $rowWebsite = trim((string) ($domainRow['website'] ?? ''));
        $rowHost = cpmsPropertyNormaliseHost($rowWebsite);

        if ($rowPropertyId === $currentPropertyId) {
            $currentPropertyWebsite = $rowWebsite;
        }
        if ($requestHost !== '' && $rowHost === $requestHost) {
            $hostPropertyId = $rowPropertyId;
        }
    }
    $domainStmt->close();
}

if ($hostPropertyId > 0 && $hostPropertyId !== $currentPropertyId) {
    $targetHost = cpmsPropertyNormaliseHost($currentPropertyWebsite);
    if ($targetHost !== '') {
        header(
            'Location: https://' . $targetHost
            . '/cpms/property_portal/dashboard.php',
            true,
            302
        );
        exit;
    }

    http_response_code(403);
    exit('Akses property tidak sepadan dengan subdomain.');
}
PHP;

$insertAt = $anchorPos + strlen($anchor);
$patched = substr($source, 0, $insertAt)
    . $guard
    . substr($source, $insertAt);

$homeRoot = dirname($publicRoot);
$backupDir = $homeRoot . '/cpmspro_backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    http_response_code(500);
    exit("INSTALL FAILED\nUnable to create the protected backup directory. No change was made.\n");
}

$backupFile = $backupDir
    . '/property_portal_auth_before_domain_guard_'
    . date('Ymd_His')
    . '.php';
if (!copy($authFile, $backupFile)) {
    http_response_code(500);
    exit("INSTALL FAILED\nUnable to create auth.php backup. No change was made.\n");
}
@chmod($backupFile, 0600);

$tempFile = $authFile . '.domain_guard_tmp_' . bin2hex(random_bytes(4));
if (file_put_contents($tempFile, $patched, LOCK_EX) === false) {
    @unlink($tempFile);
    http_response_code(500);
    exit("INSTALL FAILED\nUnable to write the temporary auth.php file. Original file is unchanged.\n");
}

@chmod($tempFile, 0644);
if (!rename($tempFile, $authFile)) {
    @unlink($tempFile);
    http_response_code(500);
    exit("INSTALL FAILED\nUnable to activate the patched auth.php. Original file is unchanged.\n");
}

$verify = file_get_contents($authFile);
if (!is_string($verify) || strpos($verify, $marker) === false) {
    copy($backupFile, $authFile);
    http_response_code(500);
    exit("INSTALL FAILED\nVerification failed and the original auth.php was restored.\n");
}

$selfDeleted = @unlink(__FILE__);

echo "CPMSPRO PROPERTY DOMAIN GUARD INSTALLED\n";
echo "Property Portal auth guard: ACTIVE\n";
echo "Backup outside public_html: YES\n";
echo "Database/property data changed: NO\n";
echo "Temporary installer deleted: " . ($selfDeleted ? 'YES' : 'NO') . "\n\n";
echo "TEST:\n";
echo "1. Login as V23 Administrator.\n";
echo "2. Open https://tmj.cpmspro.my/cpms/property_portal/newsletter.php\n";
echo "3. Expected: redirected to the V23 property subdomain/dashboard.\n";

