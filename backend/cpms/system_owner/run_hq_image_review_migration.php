<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function hqImageMigrationEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function hqImageMigrationColumnExists(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "inspection_action_images"
           AND column_name = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0) > 0;
}

$messages = [];
$errors = [];
$columns = [
    'hq_review_status' => "ALTER TABLE inspection_action_images
        ADD COLUMN hq_review_status VARCHAR(30) NOT NULL DEFAULT 'Pending'
        AFTER source_inspection_image_id",
    'hq_review_remarks' => 'ALTER TABLE inspection_action_images
        ADD COLUMN hq_review_remarks VARCHAR(2000) NULL
        AFTER hq_review_status',
    'hq_reviewed_by_id' => 'ALTER TABLE inspection_action_images
        ADD COLUMN hq_reviewed_by_id INT UNSIGNED NULL
        AFTER hq_review_remarks',
    'hq_reviewed_by_name' => 'ALTER TABLE inspection_action_images
        ADD COLUMN hq_reviewed_by_name VARCHAR(190) NULL
        AFTER hq_reviewed_by_id',
    'hq_reviewed_at' => 'ALTER TABLE inspection_action_images
        ADD COLUMN hq_reviewed_at DATETIME NULL
        AFTER hq_reviewed_by_name',
];

$tableReady = false;
$tableCheck = $conn->query(
    "SELECT COUNT(*) AS total
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'inspection_action_images'"
);
if ($tableCheck instanceof mysqli_result) {
    $row = $tableCheck->fetch_assoc();
    $tableReady = (int) ($row['total'] ?? 0) === 1;
    $tableCheck->free();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        $errors[] = 'Required table inspection_action_images is missing.';
    } elseif (!systemOwnerVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security session. Please refresh and try again.';
    } else {
        foreach ($columns as $column => $sql) {
            if (hqImageMigrationColumnExists($conn, $column)) {
                $messages[] = $column . ' already exists.';
                continue;
            }
            if ($conn->query($sql)) {
                $messages[] = $column . ' added successfully.';
            } else {
                $errors[] = $column . ' failed: ' . $conn->error;
            }
        }

        if (!$errors) {
            $conn->query(
                "INSERT IGNORE INTO cpms_v2_schema_versions
                    (version_no, release_name, notes)
                 VALUES
                    ('3.2.6.10',
                     'HQ Image-Level Review',
                     'Allows HQ Inspector to accept or reject each After image individually.')"
            );
            $messages[] = 'Migration 3.2.6.10 recorded.';
        }
    }
}

$statusRows = [];
foreach (array_keys($columns) as $column) {
    $statusRows[$column] = $tableReady
        && hqImageMigrationColumnExists($conn, $column);
}
$allReady = $tableReady && !in_array(false, $statusRows, true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>HQ Image Review Migration | CPMSPro</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif}.wrap{width:min(920px,94%);margin:28px auto}.card{background:#fff;border:1px solid #dbe3ee;border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 12px 28px #0f172a0f}.btn,button{display:inline-block;border:0;border-radius:9px;background:#0f172a;color:#fff;padding:10px 14px;text-decoration:none;font-weight:800;cursor:pointer}.btn.secondary{background:#334155}.ok{padding:10px;border-radius:9px;background:#dcfce7;color:#166534;margin:8px 0}.err{padding:10px;border-radius:9px;background:#fee2e2;color:#991b1b;margin:8px 0}.warn{padding:10px;border-radius:9px;background:#fff7ed;color:#9a3412;margin:8px 0}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left}.pass{color:#166534;font-weight:900}.fail{color:#991b1b;font-weight:900}.muted{color:#64748b}
    </style>
</head>
<body>
<main class="wrap">
    <p><a class="btn secondary" href="dashboard.php">Back to System Owner</a></p>
    <section class="card">
        <h1>HQ Image-Level Review Migration</h1>
        <p class="muted">This installer adds the database columns required for HQ Inspector to accept or reject each After photo.</p>
        <?php if ($allReady): ?>
            <div class="ok">Ready. HQ image-level review columns are installed.</div>
        <?php elseif (!$tableReady): ?>
            <div class="err">Required table inspection_action_images is missing. Install the inspection action workflow first.</div>
        <?php else: ?>
            <div class="warn">Some columns are missing. Click Run Migration to install them.</div>
        <?php endif; ?>
        <?php foreach ($messages as $message): ?><div class="ok"><?php echo hqImageMigrationEscape($message); ?></div><?php endforeach; ?>
        <?php foreach ($errors as $error): ?><div class="err"><?php echo hqImageMigrationEscape($error); ?></div><?php endforeach; ?>
    </section>

    <section class="card">
        <h2>Column Status</h2>
        <table>
            <thead><tr><th>Column</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($statusRows as $column => $ready): ?>
                <tr>
                    <td><?php echo hqImageMigrationEscape($column); ?></td>
                    <td class="<?php echo $ready ? 'pass' : 'fail'; ?>"><?php echo $ready ? 'Ready' : 'Missing'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if (!$allReady && $tableReady): ?>
        <section class="card">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo hqImageMigrationEscape(systemOwnerCsrfToken()); ?>">
                <button type="submit" onclick="return confirm('Run HQ image review migration now?');">Run Migration</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
