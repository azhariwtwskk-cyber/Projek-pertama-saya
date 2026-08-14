<?php
declare(strict_types=1);

if (defined('CPMS_V2_BOOTSTRAPPED')) {
    return;
}

define('CPMS_V2_BOOTSTRAPPED', true);

date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/error_handler.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (
        !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/property_context.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/module_registry.php';
require_once __DIR__ . '/router.php';

$cpmsV2Connection = cpmsV2Database();

/*
 * Synchronise the existing portal session before resolving the property.
 * This ensures property_admin_property_id, system-owner property_id and
 * other legacy session values are available to the resolver.
 */
$cpmsV2User = cpmsV2SyncCanonicalSession();

$cpmsV2Property = cpmsV2ResolveProperty($cpmsV2Connection);
$cpmsV2Branding = cpmsV2Branding($cpmsV2Property);

$timezone = trim(
    (string) ($cpmsV2Branding['timezone'] ?? 'Asia/Kuala_Lumpur')
);

if (
    $timezone !== ''
    && in_array($timezone, timezone_identifiers_list(), true)
) {
    date_default_timezone_set($timezone);
}
