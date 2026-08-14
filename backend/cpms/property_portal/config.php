<?php
declare(strict_types=1);

$bootstrapFile = dirname(__DIR__) . '/includes/cpms_bootstrap.php';

if (!is_file($bootstrapFile)) {
    http_response_code(500);
    exit('CPMS bootstrap could not be found.');
}

require_once $bootstrapFile;

$brandingFile = dirname(__DIR__) . '/includes/branding.php';

if (!is_file($brandingFile)) {
    if (function_exists('cpmsFoundationLog')) {
        cpmsFoundationLog(
            'Canonical CPMS branding helper could not be found: '
            . $brandingFile
        );
    }

    http_response_code(500);
    exit('CPMS branding helper could not be found.');
}

require_once $brandingFile;

if (!function_exists('cpmsBrandingAssetUrl')) {
    if (function_exists('cpmsFoundationLog')) {
        cpmsFoundationLog(
            'cpmsBrandingAssetUrl() is missing after loading branding.php.'
        );
    }

    http_response_code(500);
    exit('CPMS branding helper is incomplete.');
}

$languageFile = dirname(__DIR__) . '/includes/admin_language.php';

if (is_file($languageFile)) {
    require_once $languageFile;
}

if (!function_exists('propertyPortalEscape')) {
    function propertyPortalEscape(?string $value): string
    {
        return cpmsPortalEscape($value);
    }
}

if (!function_exists('propertyPortalRedirect')) {
    function propertyPortalRedirect(string $path): void
    {
        cpmsPortalRedirect($path);
        exit;
    }
}

if (!function_exists('propertyPortalCsrfToken')) {
    function propertyPortalCsrfToken(): string
    {
        return cpmsPortalCsrfToken('property_portal_csrf');
    }
}

if (!function_exists('propertyPortalVerifyCsrf')) {
    function propertyPortalVerifyCsrf(?string $token): bool
    {
        return cpmsPortalVerifyCsrf(
            'property_portal_csrf',
            $token
        );
    }
}
