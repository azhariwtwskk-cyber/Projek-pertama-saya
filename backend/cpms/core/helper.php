<?php
declare(strict_types=1);

function cpmsEscape(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function cpmsUrl(string $path = ''): string
{
    $base = rtrim((string)cpmsConfig('base_url', ''), '/');
    $path = ltrim($path, '/');

    if ($base === '') {
        return $path;
    }

    return $base . ($path !== '' ? '/' . $path : '');
}

function cpmsRedirect(string $path): never
{
    header('Location: ' . cpmsUrl($path));
    exit;
}

function cpmsJson(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function cpmsCsrfToken(): string
{
    if (empty($_SESSION['cpms_csrf_token'])) {
        $_SESSION['cpms_csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['cpms_csrf_token'];
}

function cpmsCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . cpmsEscape(cpmsCsrfToken())
        . '">';
}

function cpmsVerifyCsrf(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['cpms_csrf_token'] ?? '');

    if (
        $submitted === ''
        || $stored === ''
        || !hash_equals($stored, $submitted)
    ) {
        http_response_code(419);
        exit('Token keselamatan tidak sah.');
    }
}
