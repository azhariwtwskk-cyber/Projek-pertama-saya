<?php
declare(strict_types=1);

$dbHost = 'localhost';
$dbName = 'YOUR_DATABASE_NAME';
$dbUser = 'YOUR_DATABASE_USER';
$dbPassword = 'YOUR_DATABASE_PASSWORD';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli(
    $dbHost,
    $dbUser,
    $dbPassword,
    $dbName
);

if ($conn->connect_errno) {
    error_log('CPMS database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    exit('Sambungan database tidak tersedia.');
}

$conn->set_charset('utf8mb4');

$mysqli = $conn;