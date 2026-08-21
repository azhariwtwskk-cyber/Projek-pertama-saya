<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function getDefaultLanguage(mysqli $conn): string
{
    $default = "ms";

    $check = $conn->query(
        "SHOW TABLES LIKE 'system_settings'"
    );

    if (!$check || $check->num_rows === 0) {
        return $default;
    }

    $stmt = $conn->prepare(
        "SELECT setting_value
         FROM system_settings
         WHERE setting_key = 'default_language'
         LIMIT 1"
    );

    if (!$stmt) {
        return $default;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $value = (string) ($row["setting_value"] ?? "");

    return in_array($value, ["ms", "en"], true)
        ? $value
        : $default;
}

function currentLanguage(mysqli $conn): string
{
    $sessionLanguage =
        (string) ($_SESSION["language"] ?? "");

    if (in_array($sessionLanguage, ["ms", "en"], true)) {
        return $sessionLanguage;
    }

    $defaultLanguage =
        getDefaultLanguage($conn);

    $_SESSION["language"] =
        $defaultLanguage;

    return $defaultLanguage;
}

function loadTranslations(mysqli $conn): array
{
    $language =
        currentLanguage($conn);

    $file =
        __DIR__ . "/../lang/" .
        $language . ".php";

    if (!is_file($file)) {
        $file =
            __DIR__ . "/../lang/ms.php";
    }

    $translations = require $file;

    return is_array($translations)
        ? $translations
        : [];
}

function t(
    array $translations,
    string $key,
    ?string $fallback = null
): string {
    if (isset($translations[$key])) {
        return (string) $translations[$key];
    }

    return $fallback ?? $key;
}
