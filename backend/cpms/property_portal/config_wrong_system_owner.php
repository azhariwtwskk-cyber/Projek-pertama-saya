<?php
declare(strict_types=1);

/**
 * CPMS Property Portal
 * Uses the existing CPMS bootstrap and property_admins table.
 */

$bootstrapFile = dirname(__DIR__) . '/includes/cpms_bootstrap.php';

if (!is_file($bootstrapFile)) {
    http_response_code(500);
    exit('CPMS bootstrap tidak dijumpai.');
}

require_once $bootstrapFile;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Sambungan database CPMS tidak tersedia.');
}

$conn->set_charset('utf8mb4');

function propertyPortalEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function propertyPortalRedirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function propertyPortalCsrfToken(): string
{
    if (
        empty($_SESSION['property_portal_csrf']) ||
        !is_string($_SESSION['property_portal_csrf'])
    ) {
        $_SESSION['property_portal_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['property_portal_csrf'];
}

function propertyPortalVerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['property_portal_csrf'])
        && is_string($_SESSION['property_portal_csrf'])
        && hash_equals($_SESSION['property_portal_csrf'], $token);
}
