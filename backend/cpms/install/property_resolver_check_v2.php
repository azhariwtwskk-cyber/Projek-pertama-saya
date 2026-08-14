<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$user = cpmsV2User();
$property = cpmsV2Property();
$sessionPropertyId = cpmsV2PropertySessionId();
$requestedCode = cpmsV2RequestedPropertyCode();
$hostCode = cpmsV2HostnamePropertyCode($cpmsV2Connection);

$status = $property ? 'PASS' : 'FAIL';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS Smart Property Resolver Check</title>
<style>
body{font-family:Arial,sans-serif;background:#f3f6fb;color:#10213d;margin:0}
main{max-width:900px;margin:48px auto;background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:28px}
h1{margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.item{padding:15px;background:#f7f9fc;border-radius:10px}.pass{color:#07883d}.fail{color:#c22626}
code{background:#eaf0f8;padding:2px 6px;border-radius:5px}
@media(max-width:650px){main{margin:16px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body><main>
<h1>CPMS v2.0.2 Smart Property Resolver</h1>
<p>Status: <strong class="<?= strtolower($status) ?>"><?= e($status) ?></strong></p>

<div class="grid">
<div class="item"><strong>Session property ID</strong><br><?= e($sessionPropertyId ?: 'None') ?></div>
<div class="item"><strong>Requested property code</strong><br><?= e($requestedCode ?: 'None') ?></div>
<div class="item"><strong>Hostname-detected code</strong><br><?= e($hostCode ?: 'None') ?></div>
<div class="item"><strong>Authenticated role</strong><br><?= e($user['role'] ?? 'Not logged in') ?></div>
</div>

<div class="item" style="margin-top:12px">
<strong>Final resolved property</strong><br>
<?php if ($property): ?>
ID: <?= e($property['id'] ?? '') ?><br>
Code: <?= e($property['property_code'] ?? '') ?><br>
Name: <?= e($property['property_name'] ?? '') ?>
<?php else: ?>
No property could be resolved.
<?php endif; ?>
</div>

<p>
Test with your existing login session, then delete or rename
<code>property_resolver_check_v2.php</code>.
</p>
</main></body>
</html>
