<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core_v2/bootstrap.php';

$checks = [];
$checks[] = ['PHP version', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION];
$checks[] = ['MySQLi extension', extension_loaded('mysqli'), extension_loaded('mysqli') ? 'Loaded' : 'Missing'];
$checks[] = ['Database connection', $cpmsV2Connection instanceof mysqli, $cpmsV2Connection instanceof mysqli ? 'Connected' : 'Unavailable'];
$checks[] = ['cpms_properties table', cpmsV2TableExists($cpmsV2Connection, 'cpms_properties'), cpmsV2TableExists($cpmsV2Connection, 'cpms_properties') ? 'Found' : 'Missing'];
$checks[] = ['cpms_modules table', cpmsV2TableExists($cpmsV2Connection, 'cpms_modules'), cpmsV2TableExists($cpmsV2Connection, 'cpms_modules') ? 'Found' : 'Optional / missing'];
$checks[] = ['cpms_property_modules table', cpmsV2TableExists($cpmsV2Connection, 'cpms_property_modules'), cpmsV2TableExists($cpmsV2Connection, 'cpms_property_modules') ? 'Found' : 'Optional / missing'];
$checks[] = ['Resolved property', is_array($cpmsV2Property), is_array($cpmsV2Property) ? (string) ($cpmsV2Property['property_name'] ?? 'Resolved') : 'No active property'];
$checks[] = ['Log directory writable', is_dir(dirname(__DIR__) . '/logs') ? is_writable(dirname(__DIR__) . '/logs') : is_writable(dirname(__DIR__)), 'cpms/logs'];

?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CPMS v2 Health Check</title>
<style>body{margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033}.wrap{max-width:920px;margin:40px auto;padding:0 18px}.card{background:#fff;border:1px solid #dfe7f0;border-radius:16px;padding:24px;box-shadow:0 12px 34px rgba(15,23,42,.07)}h1{margin:0 0 8px}.sub{color:#667085;margin-bottom:22px}.row{display:grid;grid-template-columns:1fr 110px 1fr;gap:12px;padding:13px 0;border-top:1px solid #edf1f5;align-items:center}.ok{color:#087a4b;font-weight:700}.bad{color:#b42318;font-weight:700}code{background:#f3f5f7;padding:3px 6px;border-radius:5px}@media(max-width:650px){.row{grid-template-columns:1fr}.row span:nth-child(2){margin-top:-4px}}</style></head><body><main class="wrap"><section class="card"><h1>CPMS v2.0.0 Foundation</h1><div class="sub">Non-destructive compatibility and environment check.</div>
<?php foreach ($checks as $check): ?><div class="row"><strong><?= htmlspecialchars((string) $check[0], ENT_QUOTES, 'UTF-8') ?></strong><span class="<?= $check[1] ? 'ok' : 'bad' ?>"><?= $check[1] ? 'PASS' : 'CHECK' ?></span><span><?= htmlspecialchars((string) $check[2], ENT_QUOTES, 'UTF-8') ?></span></div><?php endforeach; ?>
<p style="margin-top:22px;color:#667085">Delete or rename this page after verification on production hosting.</p></section></main></body></html>
