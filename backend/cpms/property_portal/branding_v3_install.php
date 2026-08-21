<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

function v3Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function v3ColumnExists(
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

$messages = [];
$errors = [];

if (!v3ColumnExists($conn, 'cpms_properties', 'background_path')) {
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

if (!$errors) {
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM system_settings
         WHERE setting_key = 'background_path'"
    );

    $exists = false;

    if ($result) {
        $row = $result->fetch_assoc();
        $exists = (int) ($row['total'] ?? 0) > 0;
    }

    if (!$exists) {
        $value = 'images/bg-premium.jpg';

        $stmt = $conn->prepare(
            "INSERT INTO system_settings
                (setting_key, setting_value)
             VALUES ('background_path', ?)"
        );

        if ($stmt) {
            $stmt->bind_param('s', $value);

            if ($stmt->execute()) {
                $messages[] = 'Default background_path setting created.';
            } else {
                $errors[] = 'Unable to create background_path setting.';
            }

            $stmt->close();
        }
    } else {
        $messages[] = 'System setting background_path already exists.';
    }
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
    <title>Branding Engine v3 Installer</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f7fb;padding:30px;color:#172033}
        .card{max-width:780px;margin:auto;background:#fff;padding:30px;border-radius:18px;box-shadow:0 18px 55px rgba(15,23,42,.12)}
        .ok,.err{padding:13px 15px;border-radius:10px;margin:9px 0}
        .ok{background:#ecfdf3;color:#166534}
        .err{background:#fff1f2;color:#be123c}
        code{background:#eef2f7;padding:3px 6px;border-radius:5px}
    </style>
</head>
<body>
<div class="card">
    <h1>CPMS Core Branding Engine v3</h1>

    <?php foreach ($messages as $message): ?>
        <div class="ok"><?php echo v3Escape($message); ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $error): ?>
        <div class="err"><?php echo v3Escape($error); ?></div>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
        <div class="ok">
            Installation completed. Delete
            <code>branding_v3_install.php</code>
            after testing.
        </div>
    <?php endif; ?>
</div>
</body>
</html>
