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
    throw new RuntimeException(
        'Fail db.php tidak ditemui di: ' . $dbFile
    );
}

require_once $dbFile;

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException(
        'db.php tidak menyediakan pembolehubah $conn.'
    );
}

$conn->set_charset('utf8mb4');