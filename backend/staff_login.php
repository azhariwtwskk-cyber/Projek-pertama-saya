<?php
declare(strict_types=1);

/*
 * CPMS v3.3.6.1
 * Legacy staff login is retired. All staff must use Unified Login.
 */

$query = [];

if (isset($_GET['expired'])) {
    $query['expired'] = '1';
}

$target = 'cpms/login.php';

if ($query) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit;
