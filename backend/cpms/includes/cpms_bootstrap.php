<?php
declare(strict_types=1);

if (defined('CPMS_BOOTSTRAPPED')) {
    return;
}

define('CPMS_BOOTSTRAPPED', true);

require_once __DIR__ . '/foundation_bootstrap.php';
require_once __DIR__ . '/portal_helpers.php';
require_once __DIR__ . '/system_settings.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/language.php';

$cpmsSettings = loadSystemSettings($conn);

$cpmsLanguage = cpmsCurrentLanguage(
    $cpmsSettings
);

$cpmsTranslations = cpmsLoadTranslations(
    $cpmsLanguage
);
