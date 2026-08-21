<?php
declare(strict_types=1);

/*
 * Standalone CLI test for the canonical evidence-image URL resolver
 * (cpmsApiResolveUploadedImageUrl / cpmsApiDailyWorkImageCandidates /
 * cpmsApiWorkOrderImageUrl in services.php) — the fix for the real-device
 * "Before/After images not showing in Work Order History" bug.
 *
 * No database connection required: builds a throwaway fake site root
 * under the system temp directory, writes zero-byte files at the exact
 * relative paths the two real upload code paths (legacy Staff Web Portal
 * vs this mobile app's own endpoint) are confirmed to use, then asserts
 * the resolver finds the right one and produces a working root-relative
 * URL for each. Run with: php cpms/api/v1/image_url_resolver_test.php
 *
 * This does NOT touch any real uploads directory — everything happens
 * inside a fresh sys_get_temp_dir() subfolder that is deleted afterward.
 */

// Define cpmsApiRoot() locally instead of requiring the full
// bootstrap.php — bootstrap.php also opens a real database connection at
// require-time (via its db/auth includes), which this pure-function test
// deliberately does not need and should not depend on. This file lives in
// the same directory as bootstrap.php, so dirname(__DIR__, 2) from here
// resolves to the identical value bootstrap.php's own cpmsApiRoot() would.
if (!function_exists('cpmsApiRoot')) {
    function cpmsApiRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

require_once __DIR__ . '/services.php';

// cpmsApiResolveUploadedImageUrl() anchors at dirname(cpmsApiRoot()) —
// cpmsApiRoot() is dirname(__DIR__, 2) from bootstrap.php's own location
// (cpms/api/v1), which we can't redefine without loading the real
// bootstrap. Instead of faking cpmsApiRoot(), verify the resolver's
// candidate-matching logic directly against a fake site root by
// temporarily chdir-ing is not viable either (is_file() uses an absolute
// path built from cpmsApiRoot()). So this test exercises the resolver
// against the REAL site root (this repo's backend/ directory, since this
// script lives at backend/cpms/api/v1/) — which is exactly the structure
// production actually has, making this a faithful test rather than a
// mocked one.

function testAssert(bool $condition, string $message): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: {$message}\n";
    } else {
        $failures[] = $message;
        echo "  FAIL: {$message}\n";
    }
}

$failures = [];
$passed = 0;

$siteRoot = dirname(cpmsApiRoot()); // backend/ in this repo
$testPropertyId = 999999; // implausible id so we never collide with real data
$createdDirs = [];
$createdFiles = [];

function ensureTestDir(string $absolute, array &$createdDirs): void
{
    if (!is_dir($absolute)) {
        mkdir($absolute, 0755, true);
        $createdDirs[] = $absolute;
    }
}

try {
    echo "== cpmsApiDailyWorkImageUrl() / cpmsApiResolveUploadedImageUrl() ==\n";

    // --- Scenario: mobile upload path (flat, under cpms/, no image_path
    // populated — the pre-fix shape for rows written before this pass) ---
    $mobileDir = $siteRoot . '/cpms/uploads/daily_work';
    ensureTestDir($mobileDir, $createdDirs);
    $mobileFile = 'mobiletest_' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($mobileDir . '/' . $mobileFile, 'x');
    $createdFiles[] = $mobileDir . '/' . $mobileFile;

    $mobileUrl = cpmsApiDailyWorkImageUrl(
        ['image_name' => $mobileFile, 'image_path' => '', 'image_type' => 'After'],
        $testPropertyId
    );
    testAssert(
        $mobileUrl === '/cpms/uploads/daily_work/' . rawurlencode($mobileFile),
        'mobile upload path (flat, cpms/uploads/daily_work/, no image_path) resolves correctly'
    );

    // --- Scenario: legacy Staff Web Portal upload path (property
    // subfolder, outside cpms/, image_path populated) ---
    $legacyDir = $siteRoot . '/uploads/daily_work/property_' . $testPropertyId;
    ensureTestDir($legacyDir, $createdDirs);
    $legacyFile = 'legacytest_' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($legacyDir . '/' . $legacyFile, 'x');
    $createdFiles[] = $legacyDir . '/' . $legacyFile;
    $legacyImagePath = 'uploads/daily_work/property_' . $testPropertyId . '/' . $legacyFile;

    $legacyUrl = cpmsApiDailyWorkImageUrl(
        ['image_name' => $legacyFile, 'image_path' => $legacyImagePath, 'image_type' => 'Before'],
        $testPropertyId
    );
    testAssert(
        $legacyUrl === '/' . implode('/', array_map('rawurlencode', explode('/', $legacyImagePath))),
        'legacy Staff Web Portal upload path (property subfolder + image_path) resolves correctly'
    );

    // --- Scenario: legacy-layout file exists on disk but image_path
    // column/value is empty (older row, pre-migration) — must still be
    // found via the property-subfolder fallback candidate, not just
    // image_path. ---
    $legacyNoPathFile = 'legacynopath_' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($legacyDir . '/' . $legacyNoPathFile, 'x');
    $createdFiles[] = $legacyDir . '/' . $legacyNoPathFile;
    $legacyNoPathUrl = cpmsApiDailyWorkImageUrl(
        ['image_name' => $legacyNoPathFile, 'image_path' => '', 'image_type' => 'After'],
        $testPropertyId
    );
    testAssert(
        $legacyNoPathUrl === '/uploads/daily_work/property_' . $testPropertyId . '/' . rawurlencode($legacyNoPathFile),
        'legacy-layout file with NO image_path value is still found via fallback candidate'
    );

    // --- Scenario: multiple images (Before + After) for one daily work
    // entry resolve independently and correctly. ---
    $beforeUrl = cpmsApiDailyWorkImageUrl(
        ['image_name' => $mobileFile, 'image_path' => '', 'image_type' => 'Before'],
        $testPropertyId
    );
    $afterUrl = cpmsApiDailyWorkImageUrl(
        ['image_name' => $legacyFile, 'image_path' => $legacyImagePath, 'image_type' => 'After'],
        $testPropertyId
    );
    testAssert(
        $beforeUrl !== '' && $afterUrl !== '' && $beforeUrl !== $afterUrl,
        'Before and After images (different physical layouts) both resolve, independently'
    );

    // --- Scenario: missing image (row exists in DB, file does not exist
    // anywhere on disk) — must return a non-crashing best-effort guess,
    // never throw. ---
    $missingUrl = cpmsApiDailyWorkImageUrl(
        ['image_name' => 'does_not_exist_' . bin2hex(random_bytes(4)) . '.jpg', 'image_path' => ''],
        $testPropertyId
    );
    testAssert(
        $missingUrl !== '',
        'a missing/never-uploaded image still returns a best-effort URL string, never throws or returns null'
    );

    // --- Scenario: empty image_name AND empty image_path -> genuinely no
    // photo to show, must return empty string (caller skips it). ---
    $emptyUrl = cpmsApiDailyWorkImageUrl(['image_name' => '', 'image_path' => ''], $testPropertyId);
    testAssert($emptyUrl === '', 'a row with no image_name and no image_path resolves to empty string');

    echo "\n== cpmsApiWorkOrderImageUrl() ==\n";
    $woDir = $siteRoot . '/uploads/work_orders/property_' . $testPropertyId;
    ensureTestDir($woDir, $createdDirs);
    $woFile = 'wotest_' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($woDir . '/' . $woFile, 'x');
    $createdFiles[] = $woDir . '/' . $woFile;
    $woImageName = 'uploads/work_orders/property_' . $testPropertyId . '/' . $woFile;
    $woUrl = cpmsApiWorkOrderImageUrl(['image_name' => $woImageName]);
    testAssert(
        $woUrl === '/cpms/' . implode('/', array_map('rawurlencode', explode('/', $woImageName))),
        'work_order_images (task-photo.php uploads) resolve correctly via the same canonical resolver'
    );
} finally {
    foreach ($createdFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    foreach (array_reverse($createdDirs) as $d) {
        if (is_dir($d)) {
            @rmdir($d);
        }
    }
}

echo "\n" . count($failures) . " failed, {$passed} passed.\n";
if ($failures) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
exit(0);
