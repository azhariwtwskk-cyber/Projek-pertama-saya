<?php
declare(strict_types=1);

function cpmsResidentSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('CPMSRESIDENTSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/cpms/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function cpmsResidentSessionClear(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            (string) ($params['path'] ?? '/cpms/'),
            (string) ($params['domain'] ?? ''),
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true));
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
