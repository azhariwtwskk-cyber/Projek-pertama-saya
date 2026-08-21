<?php
declare(strict_types=1);

/**
 * CPMS Branding Engine v3.1 Full Migration Patch
 * PHP 7.4 compatible.
 *
 * Replaces legacy branding column references in PHP files:
 * - login_background_path -> background_path
 * - background_image      -> background_path
 *
 * Creates .bak_v31 backup files before changing each file.
 */

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$baseDirectory = dirname(__DIR__);
$allowedExtensions = ['php'];
$excludedNames = [
    basename(__FILE__),
];

$replacements = [
    'login_background_path' => 'background_path',
    'background_image' => 'background_path',
];

$changedFiles = [];
$unchangedFiles = [];
$failedFiles = [];

function v31Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function v31ShouldScan(string $path, array $allowedExtensions): bool
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    return in_array($extension, $allowedExtensions, true);
}

function v31ScanFiles(string $directory, array $allowedExtensions): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();

        if (v31ShouldScan($path, $allowedExtensions)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = v31ScanFiles($baseDirectory, $allowedExtensions);

    foreach ($files as $path) {
        if (in_array(basename($path), $excludedNames, true)) {
            continue;
        }

        $original = @file_get_contents($path);

        if ($original === false) {
            $failedFiles[] = [
                'file' => $path,
                'reason' => 'Unable to read file.',
            ];
            continue;
        }

        $updated = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $original,
            $replacementCount
        );

        if ($replacementCount < 1 || $updated === $original) {
            $unchangedFiles[] = $path;
            continue;
        }

        $backupPath = $path . '.bak_v31';

        if (!file_exists($backupPath)) {
            if (@file_put_contents($backupPath, $original) === false) {
                $failedFiles[] = [
                    'file' => $path,
                    'reason' => 'Unable to create backup.',
                ];
                continue;
            }
        }

        if (@file_put_contents($path, $updated) === false) {
            $failedFiles[] = [
                'file' => $path,
                'reason' => 'Unable to save updated file.',
            ];
            continue;
        }

        $relative = str_replace(
            str_replace('\\', '/', $baseDirectory) . '/',
            '',
            str_replace('\\', '/', $path)
        );

        $changedFiles[] = [
            'file' => $relative,
            'count' => $replacementCount,
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPMS Branding v3.1 Migration Patch</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:30px;font-family:Arial,sans-serif;background:#eef2f7;color:#172033}
        .card{max-width:900px;margin:auto;background:#fff;border-radius:20px;padding:30px;box-shadow:0 18px 55px rgba(15,23,42,.13)}
        h1{margin-top:0}
        .ok,.err,.info,.warn{padding:13px 15px;border-radius:10px;margin:10px 0}
        .ok{background:#ecfdf3;color:#166534}
        .err{background:#fff1f2;color:#be123c}
        .info{background:#eff6ff;color:#1d4ed8}
        .warn{background:#fff7ed;color:#9a3412}
        button{border:0;border-radius:10px;padding:13px 18px;background:#0f172a;color:#fff;font-weight:700;cursor:pointer}
        code{background:#eef2f7;padding:3px 6px;border-radius:5px}
        table{width:100%;border-collapse:collapse;margin-top:15px}
        th,td{text-align:left;padding:10px;border-bottom:1px solid #e5e7eb}
    </style>
</head>
<body>
<div class="card">
    <h1>CPMS Branding Engine v3.1</h1>
    <p>Full migration patch for legacy branding fields.</p>

    <div class="info">
        This patch scans PHP files under <code>/cpms</code> and replaces:
        <br><code>login_background_path</code> → <code>background_path</code>
        <br><code>background_image</code> → <code>background_path</code>
    </div>

    <div class="warn">
        Every changed file receives a <code>.bak_v31</code> backup first.
    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <form method="post">
            <button type="submit">Run Full Migration Patch</button>
        </form>
    <?php else: ?>
        <?php if ($changedFiles): ?>
            <div class="ok">
                Migration completed. <?php echo count($changedFiles); ?>
                file(s) updated.
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Updated file</th>
                        <th>Replacements</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($changedFiles as $item): ?>
                        <tr>
                            <td><?php echo v31Escape($item['file']); ?></td>
                            <td><?php echo (int) $item['count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="info">
                No legacy branding references were found.
            </div>
        <?php endif; ?>

        <?php foreach ($failedFiles as $item): ?>
            <div class="err">
                <?php echo v31Escape($item['file']); ?>:
                <?php echo v31Escape($item['reason']); ?>
            </div>
        <?php endforeach; ?>

        <div class="warn">
            Test the dashboard now. After success, delete
            <code>branding_v3_1_migrate.php</code>.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
