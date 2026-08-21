<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$authFile = __DIR__ . '/auth.php';
$messages = [];
$errors = [];

if (!is_file($authFile)) {
    $errors[] = 'auth.php was not found.';
} else {
    $code = (string) file_get_contents($authFile);

    if (strpos($code, 'p.login_background_path') !== false) {
        $errors[] = 'Legacy SQL reference p.login_background_path is still present.';
    } else {
        $messages[] = 'Legacy SQL reference has been removed.';
    }

    if (strpos($code, 'p.background_path') !== false) {
        $messages[] = 'The query now uses p.background_path.';
    } else {
        $errors[] = 'The query does not contain p.background_path.';
    }

    if (
        strpos(
            $code,
            "$propertyPortalUser['login_background_path']"
        ) !== false
    ) {
        $messages[] = 'Temporary compatibility alias is active.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Property Portal Core v4 Check</title>
<style>
body{font-family:Arial,sans-serif;background:#eef2f7;padding:30px;color:#172033}
.card{max-width:760px;margin:auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 18px 55px rgba(15,23,42,.12)}
.ok,.err{padding:13px 15px;border-radius:10px;margin:10px 0}
.ok{background:#ecfdf3;color:#166534}
.err{background:#fff1f2;color:#be123c}
code{background:#eef2f7;padding:3px 6px;border-radius:5px}
</style>
</head>
<body>
<div class="card">
<h1>CPMS Property Portal Core v4</h1>
<?php foreach ($messages as $message): ?>
<div class="ok"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
<div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endforeach; ?>
<?php if (!$errors): ?>
<div class="ok">File validation passed. Test the Property Portal dashboard now.</div>
<?php endif; ?>
<p>Delete <code>property_portal_v4_check.php</code> after testing.</p>
</div>
</body>
</html>
