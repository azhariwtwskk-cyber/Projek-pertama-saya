<?php
declare(strict_types=1);

$bootstrapFile = dirname(__DIR__) . '/includes/cpms_bootstrap.php';
$inspectionServiceFile = dirname(__DIR__) . '/includes/inspection_service.php';
$findingServiceFile = dirname(__DIR__) . '/includes/inspection_finding_service.php';
$assignmentServiceFile = dirname(__DIR__)
    . '/includes/inspection_assignment_service.php';

if (!is_file($bootstrapFile)) {
    http_response_code(500);
    exit('CPMS bootstrap could not be found.');
}
if (!is_file($inspectionServiceFile)) {
    http_response_code(500);
    exit('Inspection service could not be found.');
}
if (!is_file($findingServiceFile)) {
    http_response_code(500);
    exit('Inspection finding service could not be found. Run CPMS v3.2.6 upgrade.');
}

require_once $bootstrapFile;
require_once $inspectionServiceFile;
require_once $findingServiceFile;
if (is_file($assignmentServiceFile)) {
    require_once $assignmentServiceFile;
}

function hqiRedirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function hqiEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hqiCsrf(): string
{
    if (empty($_SESSION['hqi_csrf'])) {
        $_SESSION['hqi_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['hqi_csrf'];
}

function hqiVerify(?string $value): bool
{
    return is_string($value)
        && !empty($_SESSION['hqi_csrf'])
        && hash_equals((string) $_SESSION['hqi_csrf'], $value);
}
