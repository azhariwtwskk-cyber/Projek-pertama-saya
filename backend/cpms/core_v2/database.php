<?php
declare(strict_types=1);

function cpmsV2Database(): mysqli
{
    global $conn;

    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }

    $databaseFile = dirname(__DIR__, 2) . '/db.php';
    if (!is_file($databaseFile)) {
        throw new RuntimeException('Database configuration file was not found.');
    }

    require_once $databaseFile;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException('Unable to set database character set.');
    }

    return $conn;
}

function cpmsV2TableExists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function cpmsV2ColumnExists(mysqli $conn, string $table, string $column): bool
{
    if (!cpmsV2TableExists($conn, $table)) {
        return false;
    }

    $safeTable = str_replace('`', '``', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}
