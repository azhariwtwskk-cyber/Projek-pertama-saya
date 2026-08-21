<?php
declare(strict_types=1);

function cpmsPortalEscape(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function cpmsPortalRedirect(string $path): void
{
    if (headers_sent()) {
        throw new RuntimeException(
            'Redirect failed because output was already sent.'
        );
    }

    header('Location: ' . $path);
    exit;
}

function cpmsPortalCsrfToken(string $sessionKey): string
{
    if (
        empty($_SESSION[$sessionKey])
        || !is_string($_SESSION[$sessionKey])
    ) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }

    return $_SESSION[$sessionKey];
}

function cpmsPortalVerifyCsrf(
    string $sessionKey,
    ?string $token
): bool {
    return is_string($token)
        && isset($_SESSION[$sessionKey])
        && is_string($_SESSION[$sessionKey])
        && hash_equals($_SESSION[$sessionKey], $token);
}

function cpmsPortalClearSessionKeys(array $keys): void
{
    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}
