<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$user = cpmsV2User();
$property = cpmsV2Property();
$route = $user ? cpmsV2ResolvedDashboardRoute($user) : '';
$routeExists = $route !== '' && cpmsV2DashboardRouteExists($route);

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Unified Dashboard Check</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#10213d;margin:0}
main{max-width:900px;margin:48px auto;background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:28px;box-shadow:0 12px 36px rgba(16,33,61,.08)}
h1{margin:0 0 8px}.muted{color:#60708a}.row{display:grid;grid-template-columns:1.2fr .45fr 1.4fr;gap:16px;padding:15px 0;border-top:1px solid #e5ebf3}
.pass{color:#07883d;font-weight:700}.fail{color:#c22626;font-weight:700}.info{color:#916400;font-weight:700}
.card{margin-top:20px;background:#f7f9fc;border-radius:12px;padding:18px}
a{color:#1459d9;font-weight:700}
@media(max-width:650px){main{margin:16px}.row{grid-template-columns:1fr}}
</style>
</head>
<body><main>
<h1>CPMS v2.0.3 Unified Dashboard Check</h1>
<p class="muted">Non-destructive role-based routing test.</p>

<div class="row">
<strong>Authentication</strong>
<span class="<?= $user ? 'pass' : 'info' ?>"><?= $user ? 'PASS' : 'INFO' ?></span>
<span><?= $user ? 'Active login detected' : 'Login required' ?></span>
</div>

<div class="row">
<strong>Resolved role</strong>
<span class="<?= $user ? 'pass' : 'info' ?>"><?= $user ? 'PASS' : 'INFO' ?></span>
<span><?= h($user['role'] ?? 'Not available') ?></span>
</div>

<div class="row">
<strong>Resolved property</strong>
<span class="<?= $property ? 'pass' : 'info' ?>"><?= $property ? 'PASS' : 'INFO' ?></span>
<span><?= h($property['property_name'] ?? 'Not resolved') ?></span>
</div>

<div class="row">
<strong>Dashboard target</strong>
<span class="<?= $routeExists ? 'pass' : 'fail' ?>"><?= $routeExists ? 'PASS' : 'FAIL' ?></span>
<span><?= h($route ?: 'Not resolved') ?></span>
</div>

<?php if ($user && $routeExists): ?>
<div class="card">
<strong>Unified entry is ready.</strong><br><br>
<a href="/cpms/dashboard.php">Open CPMS Unified Dashboard</a>
</div>
<?php else: ?>
<div class="card">
Login through an existing portal first, then refresh this page.
</div>
<?php endif; ?>

<p class="muted">Delete or rename this diagnostic page after testing.</p>
</main></body>
</html>
