<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$user = cpmsV2User();
$property = cpmsV2Property();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$rows = [
    ['Core bootstrap', 'PASS', 'Loaded'],
    [
        'Session active',
        session_status() === PHP_SESSION_ACTIVE ? 'PASS' : 'FAIL',
        session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive',
    ],
    [
        'Legacy login detected',
        $user ? 'PASS' : 'INFO',
        $user ? 'Yes' : 'No active login detected',
    ],
    [
        'Canonical session',
        isset($_SESSION['cpms_user_id']) ? 'PASS' : 'INFO',
        isset($_SESSION['cpms_user_id'])
            ? 'Synchronized'
            : 'Waiting for login',
    ],
    [
        'Resolved property',
        $property ? 'PASS' : 'INFO',
        $property['property_name'] ?? 'Not resolved',
    ],
];

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPMS v2 Authentication Check</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;color:#10213d}
.box{max-width:900px;margin:48px auto;background:#fff;border:1px solid #dbe3ee;border-radius:18px;padding:28px;box-shadow:0 12px 36px rgba(16,33,61,.08)}
h1{margin:0 0 8px}.muted{color:#60708a}
.row{display:grid;grid-template-columns:1.2fr .45fr 1.4fr;gap:16px;padding:15px 0;border-top:1px solid #e5ebf3}
.pass{color:#07883d;font-weight:700}.fail{color:#c22626;font-weight:700}.info{color:#916400;font-weight:700}
.card{margin-top:22px;padding:18px;background:#f7f9fc;border-radius:12px}
code{background:#eaf0f8;padding:2px 6px;border-radius:5px}
@media(max-width:650px){.box{margin:16px}.row{grid-template-columns:1fr}.row div{margin:0}}
</style>
</head>
<body><main class="box">
<h1>CPMS v2.0.2 Authentication Check</h1>
<p class="muted">Smart property resolution and session synchronisation test.</p>

<?php foreach ($rows as $row): ?>
<div class="row">
<strong><?= h($row[0]) ?></strong>
<span class="<?= strtolower(h($row[1])) ?>"><?= h($row[1]) ?></span>
<span><?= h($row[2]) ?></span>
</div>
<?php endforeach; ?>

<div class="card">
<?php if ($user): ?>
<strong>Detected user</strong><br>
Name: <?= h($user['name']) ?><br>
Role: <?= h($user['role']) ?><br>
Portal: <?= h($user['portal']) ?><br>
Property ID: <?= h($user['property_id']) ?>
<?php else: ?>
<strong>No active login detected.</strong><br>
Login through an existing portal in this same browser, then reopen this page.
<?php endif; ?>
</div>

<?php if ($property): ?>
<div class="card">
<strong>Resolved property</strong><br>
ID: <?= h($property['id'] ?? '') ?><br>
Code: <?= h($property['property_code'] ?? '') ?><br>
Name: <?= h($property['property_name'] ?? '') ?>
</div>
<?php endif; ?>

<p class="muted">
After verification, rename or delete <code>auth_check_v2.php</code>.
</p>
</main></body>
</html>
