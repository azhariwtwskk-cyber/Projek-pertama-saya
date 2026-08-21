<?php
if (defined("CPMS_BOOTSTRAPPED")) return;
define("CPMS_BOOTSTRAPPED", true);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
date_default_timezone_set("Asia/Kuala_Lumpur");

$cpmsRoot = dirname(__DIR__);

if (!isset($conn)) {
    require_once $cpmsRoot . "/db.php";
}

require_once __DIR__ . "/system_settings.php";
require_once __DIR__ . "/branding.php";
require_once __DIR__ . "/theme.php";
require_once __DIR__ . "/language.php";

$cpmsSettings = loadSystemSettings($conn);
$cpmsLanguage = cpmsCurrentLanguage($cpmsSettings);
$cpmsTranslations = cpmsLoadTranslations($cpmsLanguage);
