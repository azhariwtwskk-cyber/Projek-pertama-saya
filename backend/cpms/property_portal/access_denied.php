<?php
declare(strict_types=1);

$permission = (string) (
    $GLOBALS['cpmsDeniedPermission']
    ?? 'unknown'
);

$role = function_exists('cpmsPropertyRoleLabel')
    ? cpmsPropertyRoleLabel()
    : 'Property User';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport"
          content="width=device-width,initial-scale=1">
    <title>Access Denied | CPMS</title>
    <link rel="stylesheet" href="assets/portal.css">
    <style>
        body{margin:0;background:#f4f7fb;color:#18212f;
        font-family:Arial,sans-serif;display:grid;place-items:center;
        min-height:100vh;padding:20px;box-sizing:border-box}
        .denied-card{width:min(560px,100%);background:#fff;
        border:1px solid #dfe6ef;border-radius:16px;padding:30px;
        box-shadow:0 16px 38px rgba(15,23,42,.08)}
        .denied-code{display:inline-block;padding:6px 10px;
        border-radius:8px;background:#fee2e2;color:#991b1b;
        font-size:12px;font-weight:900}
        h1{font-size:25px;margin:16px 0 8px}
        p{color:#64748b;line-height:1.6}
        a{display:inline-block;margin-top:12px;padding:11px 15px;
        border-radius:9px;background:#1d4ed8;color:#fff;
        text-decoration:none;font-weight:800}
        small{display:block;margin-top:18px;color:#94a3b8}
    </style>
</head>
<body>
<section class="denied-card">
    <span class="denied-code">HTTP 403</span>
    <h1>Access is not permitted</h1>
    <p>
        Your current role,
        <strong><?php echo propertyPortalEscape($role); ?></strong>,
        does not have permission to open this function.
    </p>
    <a href="dashboard.php">Return to Dashboard</a>
    <small>
        Permission:
        <?php echo propertyPortalEscape($permission); ?>
    </small>
</section>
</body>
</html>
