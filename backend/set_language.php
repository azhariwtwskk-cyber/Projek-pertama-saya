<?php

declare(strict_types=1);

session_start();

$language =
    (string) ($_GET["lang"] ?? "ms");

if (!in_array($language, ["ms", "en"], true)) {
    $language = "ms";
}

$_SESSION["language"] =
    $language;

$redirect =
    (string) ($_GET["redirect"] ?? "admin_dashboard.php");

$allowedRedirect =
    preg_match(
        '/^[a-zA-Z0-9_\-\.]+(?:\?[a-zA-Z0-9_\-=&%]*)?$/',
        $redirect
    ) === 1;

if (!$allowedRedirect) {
    $redirect = "admin_dashboard.php";
}

header("Location: " . $redirect);
exit();
