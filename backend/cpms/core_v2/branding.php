<?php
declare(strict_types=1);

function cpmsV2Hex(string $value, string $fallback): string
{
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

function cpmsV2Branding(?array $property): array
{
    $property = $property ?? [];
    $background = trim((string) ($property['background_path'] ?? $property['login_background_path'] ?? ''));

    return [
        'property_id' => (int) ($property['id'] ?? 0),
        'property_code' => (string) ($property['property_code'] ?? ''),
        'property_name' => (string) ($property['property_name'] ?? 'CPMS Property'),
        'company_name' => (string) ($property['company_name'] ?? 'CPMS'),
        'logo_path' => (string) ($property['logo_path'] ?? '/images/logo.png'),
        'background_path' => $background,
        'login_background_path' => $background,
        'primary_color' => cpmsV2Hex((string) ($property['primary_color'] ?? ''), '#3a2419'),
        'secondary_color' => cpmsV2Hex((string) ($property['secondary_color'] ?? ''), '#b59b20'),
        'language' => (string) ($property['default_language'] ?? 'ms'),
        'currency' => (string) ($property['currency_code'] ?? 'MYR'),
        'timezone' => (string) ($property['timezone_name'] ?? 'Asia/Kuala_Lumpur'),
    ];
}
