<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

function bv2Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bv2ColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function bv2SettingExists(mysqli $conn, string $key): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM system_settings
         WHERE setting_key = ?"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

$messages = [];
$errors = [];

/*
|--------------------------------------------------------------------------
| 1. Add the new single-source column
|--------------------------------------------------------------------------
*/
if (!bv2ColumnExists($conn, 'cpms_properties', 'background_path')) {
    if ($conn->query(
        "ALTER TABLE cpms_properties
         ADD COLUMN background_path VARCHAR(500) NULL"
    )) {
        $messages[] = 'Column background_path added.';
    } else {
        $errors[] = 'Unable to add background_path: ' . $conn->error;
    }
} else {
    $messages[] = 'Column background_path already exists.';
}

/*
|--------------------------------------------------------------------------
| 2. Migrate existing property images before deleting old columns
|--------------------------------------------------------------------------
*/
if (!$errors) {
    if (bv2ColumnExists($conn, 'cpms_properties', 'login_background_path')) {
        if ($conn->query(
            "UPDATE cpms_properties
             SET background_path = login_background_path
             WHERE (background_path IS NULL OR TRIM(background_path) = '')
               AND login_background_path IS NOT NULL
               AND TRIM(login_background_path) <> ''"
        )) {
            $messages[] = 'Existing login backgrounds migrated.';
        } else {
            $errors[] = 'Unable to migrate login backgrounds: ' . $conn->error;
        }
    }

    if (bv2ColumnExists($conn, 'cpms_properties', 'background_image')) {
        if ($conn->query(
            "UPDATE cpms_properties
             SET background_path = background_image
             WHERE (background_path IS NULL OR TRIM(background_path) = '')
               AND background_image IS NOT NULL
               AND TRIM(background_image) <> ''"
        )) {
            $messages[] = 'Existing background_image values migrated.';
        } else {
            $errors[] = 'Unable to migrate background_image values: ' . $conn->error;
        }
    }
}

/*
|--------------------------------------------------------------------------
| 3. Migrate the legacy system_settings key
|--------------------------------------------------------------------------
*/
if (!$errors) {
    $legacyValue = '';

    if (bv2SettingExists($conn, 'background_image')) {
        $stmt = $conn->prepare(
            "SELECT setting_value
             FROM system_settings
             WHERE setting_key = 'background_image'
             LIMIT 1"
        );

        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $legacyValue = trim((string) ($row['setting_value'] ?? ''));
        }
    }

    if (!bv2SettingExists($conn, 'background_path')) {
        $value = $legacyValue !== ''
            ? $legacyValue
            : 'images/bg-premium.jpg';

        $stmt = $conn->prepare(
            "INSERT INTO system_settings
                (setting_key, setting_value)
             VALUES ('background_path', ?)"
        );

        if ($stmt) {
            $stmt->bind_param('s', $value);

            if ($stmt->execute()) {
                $messages[] = 'System setting background_path created.';
            } else {
                $errors[] = 'Unable to create background_path setting.';
            }

            $stmt->close();
        }
    } else {
        $messages[] = 'System setting background_path already exists.';
    }
}

/*
|--------------------------------------------------------------------------
| 4. Delete obsolete database fields only after successful migration
|--------------------------------------------------------------------------
*/
if (!$errors) {
    foreach (['login_background_path', 'background_image'] as $oldColumn) {
        if (bv2ColumnExists($conn, 'cpms_properties', $oldColumn)) {
            if ($conn->query(
                "ALTER TABLE cpms_properties DROP COLUMN {$oldColumn}"
            )) {
                $messages[] = "Old column {$oldColumn} deleted.";
            } else {
                $errors[] = "Unable to delete {$oldColumn}: " . $conn->error;
            }
        } else {
            $messages[] = "Old column {$oldColumn} is not present.";
        }
    }

    $conn->query(
        "DELETE FROM system_settings
         WHERE setting_key IN ('background_image', 'login_background_path')"
    );

    $messages[] = 'Old system setting keys removed.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>CPMS Branding Manager v2 Installer</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7fb;padding:30px;color:#172033}
        .card{max-width:820px;margin:auto;background:#fff;padding:30px;border-radius:18px;box-shadow:0 18px 55px rgba(15,23,42,.12)}
        .ok,.err{padding:13px 15px;border-radius:10px;margin:9px 0}
        .ok{background:#ecfdf3;color:#166534}
        .err{background:#fff1f2;color:#be123c}
        code{background:#eef2f7;padding:3px 6px;border-radius:5px}
    </style>
</head>
<body>
<div class="card">
    <h1>CPMS Branding Manager v2</h1>
    <p>Unified Background Migration</p>

    <?php foreach ($messages as $message): ?>
        <div class="ok"><?php echo bv2Escape($message); ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $error): ?>
        <div class="err"><?php echo bv2Escape($error); ?></div>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
        <div class="ok">
            Migration completed. Delete
            <code>branding_v2_install.php</code>
            from the server.
        </div>
    <?php else: ?>
        <div class="err">
            Migration stopped. Do not delete the installer until all errors
            are resolved.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
