<?php
declare(strict_types=1);

/*
 * CPMS v3.4.5.3 — Root DB Bridge
 * This file lives at /htdocs/cpms/db.php.
 * The canonical database configuration remains at /htdocs/db.php.
 */
$cpmsRootDatabase = dirname(__DIR__) . '/db.php';

if (!is_file($cpmsRootDatabase)) {
    http_response_code(500);
    exit('Main CPMS database configuration is unavailable at /htdocs/db.php.');
}

require_once $cpmsRootDatabase;

if (!isset($conn) || !$conn instanceof mysqli) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $conn = $mysqli;
    } else {
        http_response_code(500);
        exit('Main CPMS database connection is unavailable.');
    }
}
