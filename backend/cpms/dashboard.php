<?php
declare(strict_types=1);

require_once __DIR__ . '/core_v2/bootstrap.php';

$user = cpmsV2RequireLogin('/index.php');
$property = cpmsV2Property();
$route = cpmsV2ResolvedDashboardRoute($user);

/*
 * Avoid redirect loops if a future route is accidentally pointed back to
 * this unified entry page.
 */
$currentPath = parse_url(
    (string) ($_SERVER['REQUEST_URI'] ?? ''),
    PHP_URL_PATH
);

if ($route === $currentPath || $route === '/cpms/dashboard.php') {
    http_response_code(500);
    echo 'Dashboard route configuration error.';
    exit;
}

cpmsV2RedirectToDashboard($user);
