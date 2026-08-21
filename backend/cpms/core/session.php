<?php
declare(strict_types=1);

function cpmsStartSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function cpmsSession(string $key, mixed $default = null): mixed
{
    return $_SESSION[$key] ?? $default;
}

function cpmsSetSession(string $key, mixed $value): void
{
    $_SESSION[$key] = $value;
}

function cpmsForgetSession(string $key): void
{
    unset($_SESSION[$key]);
}
