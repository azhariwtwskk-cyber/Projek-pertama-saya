<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CPMS DATABASE LOADER
|--------------------------------------------------------------------------
| Struktur sistem:
|
| /htdocs/db.php
| /htdocs/cpms/core/database.php
|--------------------------------------------------------------------------
*/

$dbFile = dirname(__DIR__, 2) . '/db.php';

if (!file_exists($dbFile)) {
    die(
        'Ralat: Fail database tidak ditemui di /htdocs/db.php'
    );
}

require_once $dbFile;

if (!isset($conn) || !($conn instanceof mysqli)) {
    die(
        'Ralat: Fail db.php tidak menyediakan sambungan $conn.'
    );
}

if ($conn->connect_errno) {
    die(
        'Ralat sambungan database: '
        . htmlspecialchars(
            $conn->connect_error,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

$conn->set_charset('utf8mb4');