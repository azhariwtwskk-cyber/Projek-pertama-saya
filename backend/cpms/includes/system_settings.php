<?php

declare(strict_types=1);

/**
 * Global CPMS settings only. Property-specific branding remains in
 * cpms_properties and must not be added to this list.
 */
function cpmsSystemSettingDefaults(): array
{
    return [
        'system_name' => 'Commercial Property Management System',
        'system_short_name' => 'CPMS',
        'company_name' => 'CPMS Enterprise',
        'system_tagline' => 'Unified Property Operations',
        'primary_color' => '#0f2342',
        'secondary_color' => '#d6a84b',
        'contact_phone' => '',
        'contact_email' => '',
        'address' => '',
        'logo_path' => '',
        'favicon_path' => '',
        'background_path' => '',
        'software_version' => '3.4.11',
        'powered_by' => 'Azhari Technologies',
        'default_language' => 'ms',
        'login_secure_label_ms' => 'Akses selamat CPMS',
        'login_secure_label_en' => 'Secure CPMS access',
        'login_eyebrow_ms' => 'OPERASI HARTANAH BERSEPADU',
        'login_eyebrow_en' => 'UNIFIED PROPERTY OPERATIONS',
        'login_brand_title_ms' => "Satu akaun.\nSemua akses.",
        'login_brand_title_en' => "One account.\nEvery access.",
        'login_brand_description_ms' => 'Akses ruang kerja CPMS anda melalui satu pintu masuk yang selamat dan profesional.',
        'login_brand_description_en' => 'Access your CPMS workspace through one secure and professional entry point.',
        'login_title_ms' => 'Log masuk ke CPMS',
        'login_title_en' => 'Sign in to CPMS',
        'login_description_ms' => 'Masukkan nama pengguna dan kata laluan anda untuk meneruskan.',
        'login_description_en' => 'Enter your username and password to continue.',
        'login_footer_ms' => 'Commercial Property Management System',
        'login_footer_en' => 'Commercial Property Management System',
    ];
}

function loadSystemSettings(mysqli $conn): array
{
    $settings = cpmsSystemSettingDefaults();
    $result = $conn->query(
        'SELECT setting_key, setting_value FROM system_settings'
    );

    if (!$result) {
        return $settings;
    }

    while ($row = $result->fetch_assoc()) {
        $key = (string) ($row['setting_key'] ?? '');

        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }
    }

    return $settings;
}

function setting(
    array $settings,
    string $key,
    string $default = ''
): string {
    return isset($settings[$key])
        ? (string) $settings[$key]
        : $default;
}

function cpmsSystemSettingHex(string $value, string $fallback): string
{
    $value = trim($value);

    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
        ? strtolower($value)
        : $fallback;
}

/**
 * Return a safe CPMS-relative asset path. External URLs and traversal are
 * deliberately rejected because System Owner uploads are stored locally.
 */
function cpmsSystemSettingAsset(string $value): string
{
    $value = trim(str_replace('\\', '/', $value));

    if (
        $value === ''
        || strpos($value, '..') !== false
        || strpos($value, '://') !== false
        || strpos($value, '//') === 0
        || preg_match('/[\x00-\x1F\x7F\'"<>]/', $value) === 1
    ) {
        return '';
    }

    return ltrim($value, '/');
}
