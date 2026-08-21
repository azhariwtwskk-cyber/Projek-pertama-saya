<?php
declare(strict_types=1);

function cpmsThemeValue(
    array $property,
    string $key,
    string $fallback
): string {
    $value = trim((string) ($property[$key] ?? ''));

    return $value !== '' ? $value : $fallback;
}

function cpmsThemeCssVariables(array $property): string
{
    $primary = cpmsThemeValue(
        $property,
        'primary_color',
        '#1d4ed8'
    );
    $secondary = cpmsThemeValue(
        $property,
        'secondary_color',
        '#0f172a'
    );

    return sprintf(
        '--property-primary:%s;--property-secondary:%s;',
        htmlspecialchars($primary, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($secondary, ENT_QUOTES, 'UTF-8')
    );
}
