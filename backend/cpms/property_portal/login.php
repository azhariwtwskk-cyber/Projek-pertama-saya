<?php
declare(strict_types=1);

/*
 * CPMS Unified Login Redirect Hotfix
 *
 * The legacy Property Portal login is no longer allowed to authenticate
 * independently. All users must use the Unified Login at /cpms/login.php.
 */

$parameters = [];

if (isset($_GET['logout']) && (string) $_GET['logout'] === '1') {
    $parameters['logout'] = '1';
}

if (isset($_GET['lang']) && in_array((string) $_GET['lang'], ['bm', 'en'], true)) {
    $parameters['lang'] = (string) $_GET['lang'];
}

$target = '../login.php';

if ($parameters !== []) {
    $target .= '?' . http_build_query($parameters);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $target, true, 302);
exit;
