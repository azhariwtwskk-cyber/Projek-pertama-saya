<?php
declare(strict_types=1);

/**
 * CPMS Core v2 dashboard route resolver.
 *
 * This release is transitional: it sends each authenticated role to the
 * existing production dashboard while providing one common CPMS entry URL.
 */

function cpmsV2DashboardRoutes(): array
{
    return [
        'system_owner' => '/cpms/system_owner/dashboard.php',
        'managing_director' => '/cpms/executive/dashboard.php',
        'property_admin' => '/cpms/property_portal/dashboard.php',
        'manager' => '/cpms/property_portal/dashboard.php',
        'clerk' => '/cpms/property_portal/dashboard.php',
        'supervisor' => '/staff_dashboard.php',
        'staff' => '/staff_dashboard.php',
        'security_guard' => '/security_patrol_form.php',
        'security' => '/security_patrol_form.php',
        'resident' => '/cpms/resident_dashboard.php',
        'contractor' => '/staff_dashboard.php',
    ];
}

function cpmsV2DashboardRoute(string $role): string
{
    $role = cpmsV2NormalizeRole($role);
    $routes = cpmsV2DashboardRoutes();

    return $routes[$role] ?? '/index.php';
}

function cpmsV2DashboardRouteExists(string $route): bool
{
    $routePath = parse_url($route, PHP_URL_PATH);

    if (!is_string($routePath) || $routePath === '') {
        return false;
    }

    $root = dirname(__DIR__, 2);
    $relative = ltrim($routePath, '/');
    $fullPath = $root . '/' . $relative;

    return is_file($fullPath);
}

function cpmsV2ResolvedDashboardRoute(array $user): string
{
    $route = cpmsV2DashboardRoute((string) ($user['role'] ?? ''));

    if (cpmsV2DashboardRouteExists($route)) {
        return $route;
    }

    /*
     * Safe fallbacks for installations whose portal folders use a slightly
     * different layout. These are checked in order and only used when the
     * preferred route is missing.
     */
    $fallbacks = [
        '/cpms/system_owner/dashboard.php',
        '/cpms/property_portal/dashboard.php',
        '/staff_dashboard.php',
        '/cpms/resident_dashboard.php',
        '/index.php',
    ];

    foreach ($fallbacks as $fallback) {
        if (cpmsV2DashboardRouteExists($fallback)) {
            return $fallback;
        }
    }

    return '/index.php';
}

function cpmsV2RedirectToDashboard(array $user): void
{
    $route = cpmsV2ResolvedDashboardRoute($user);

    if (!headers_sent()) {
        header('Location: ' . $route);
        exit;
    }

    throw new RuntimeException(
        'Unable to redirect to the dashboard because output was already sent.'
    );
}
