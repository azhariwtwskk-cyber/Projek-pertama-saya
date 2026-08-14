<?php
function cpmsCurrentLanguage(array $settings): string
{
    $requested = (string)($_GET["lang"] ?? "");

    if (in_array($requested, ["ms", "en"], true)) {
        $_SESSION["cpms_language"] = $requested;
    }

    $current = (string)($_SESSION["cpms_language"] ?? "");

    if (in_array($current, ["ms", "en"], true)) {
        return $current;
    }

    $default = setting($settings, "default_language", "ms");
    if (!in_array($default, ["ms", "en"], true)) $default = "ms";

    $_SESSION["cpms_language"] = $default;
    return $default;
}

function cpmsLoadTranslations(string $language): array
{
    $file = dirname(__DIR__) . "/lang/" . $language . ".php";
    if (!is_file($file)) $file = dirname(__DIR__) . "/lang/ms.php";

    $translations = require $file;
    return is_array($translations) ? $translations : [];
}

function cpmsT(string $key, ?string $fallback = null): string
{
    global $cpmsTranslations;
    return isset($cpmsTranslations[$key])
        ? (string)$cpmsTranslations[$key]
        : ($fallback ?? $key);
}
