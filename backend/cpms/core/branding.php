<?php
declare(strict_types=1);

/**
 * CPMS Core Branding Engine v3
 * Single source for branding values and asset URLs.
 * PHP 7.4 compatible.
 */

if (!function_exists('cpmsBrandingStartsWith')) {
    function cpmsBrandingStartsWith(string $value, string $prefix): bool
    {
        return $prefix === ''
            || strncmp($value, $prefix, strlen($prefix)) === 0;
    }
}

if (!function_exists('cpmsBrandingHex')) {
    function cpmsBrandingHex(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value)
            ? $value
            : $fallback;
    }
}

if (!function_exists('cpmsBrandingValue')) {
    function cpmsBrandingValue(
        array $branding,
        string $key,
        string $fallback = ''
    ): string {
        $value = trim((string) ($branding[$key] ?? ''));

        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('cpmsBrandingSiteBaseUrl')) {
    function cpmsBrandingSiteBaseUrl(): string
    {
        $scriptName = str_replace(
            '\\',
            '/',
            (string) ($_SERVER['SCRIPT_NAME'] ?? '')
        );

        $position = strpos($scriptName, '/cpms/');

        if ($position !== false) {
            $before = rtrim(
                (string) substr($scriptName, 0, $position),
                '/'
            );

            return $before . '/';
        }

        return '/';
    }
}

if (!function_exists('cpmsBrandingBaseUrl')) {
    function cpmsBrandingBaseUrl(): string
    {
        return rtrim(cpmsBrandingSiteBaseUrl(), '/') . '/cpms/';
    }
}

if (!function_exists('cpmsAssetUrl')) {
    function cpmsAssetUrl(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        if (
            preg_match('#^(https?:)?//#i', $path)
            || cpmsBrandingStartsWith($path, 'data:')
            || cpmsBrandingStartsWith($path, 'blob:')
        ) {
            return $path;
        }

        $normalised = str_replace('\\', '/', $path);
        $normalised = preg_replace('#^\./+#', '', $normalised);
        $normalised = ltrim((string) $normalised, '/');

        if (cpmsBrandingStartsWith($normalised, 'htdocs/')) {
            $normalised = substr($normalised, 7);
        }

        if (cpmsBrandingStartsWith($normalised, 'cpms/')) {
            $normalised = substr($normalised, 5);
        }

        /*
         * Public legacy assets physically stored under /htdocs/images.
         */
        if (cpmsBrandingStartsWith($normalised, 'images/')) {
            return cpmsBrandingSiteBaseUrl() . $normalised;
        }

        /*
         * CPMS assets physically stored under /htdocs/cpms.
         */
        $cpmsPrefixes = [
            'uploads/',
            'property_portal/',
            'core/',
            'includes/',
        ];

        foreach ($cpmsPrefixes as $prefix) {
            if (cpmsBrandingStartsWith($normalised, $prefix)) {
                return cpmsBrandingBaseUrl() . $normalised;
            }
        }

        /*
         * Relative "assets/" paths belong to Property Portal.
         */
        if (cpmsBrandingStartsWith($normalised, 'assets/')) {
            return cpmsBrandingBaseUrl()
                . 'property_portal/'
                . $normalised;
        }

        /*
         * Unknown relative branding files are treated as CPMS files.
         */
        return cpmsBrandingBaseUrl() . $normalised;
    }
}

if (!function_exists('cpmsBrandingAssetUrl')) {
    function cpmsBrandingAssetUrl(
        ?string $path,
        string $prefix = ''
    ): string {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        if ($prefix !== '') {
            $normalised = ltrim(
                str_replace('\\', '/', $path),
                '/'
            );

            if (
                !cpmsBrandingStartsWith($normalised, 'images/')
                && !cpmsBrandingStartsWith($normalised, 'uploads/')
                && !cpmsBrandingStartsWith($normalised, 'cpms/')
                && !preg_match('#^(https?:)?//#i', $normalised)
            ) {
                $path = trim($prefix, '/') . '/' . $normalised;
            }
        }

        return cpmsAssetUrl($path);
    }
}

if (!function_exists('cpmsBrandingCurrentProperty')) {
    function cpmsBrandingCurrentProperty(): array
    {
        foreach (
            [
                'cpms_active_property',
                'propertyPortalUser',
                'currentProperty',
                'cpmsCurrentPropertyData',
            ] as $key
        ) {
            if (
                isset($GLOBALS[$key])
                && is_array($GLOBALS[$key])
            ) {
                return $GLOBALS[$key];
            }
        }

        return [];
    }
}

if (!function_exists('cpmsBrandingSettings')) {
    function cpmsBrandingSettings(): array
    {
        return (
            isset($GLOBALS['cpmsSettings'])
            && is_array($GLOBALS['cpmsSettings'])
        )
            ? $GLOBALS['cpmsSettings']
            : [];
    }
}

if (!function_exists('cpmsBrandingPropertySetting')) {
    function cpmsBrandingPropertySetting(
        string $propertyKey,
        string $systemKey = '',
        string $fallback = ''
    ): string {
        $property = cpmsBrandingCurrentProperty();
        $settings = cpmsBrandingSettings();

        $propertyValue = trim(
            (string) ($property[$propertyKey] ?? '')
        );

        if ($propertyValue !== '') {
            return $propertyValue;
        }

        if ($systemKey !== '') {
            $systemValue = trim(
                (string) ($settings[$systemKey] ?? '')
            );

            if ($systemValue !== '') {
                return $systemValue;
            }
        }

        return $fallback;
    }
}

if (!function_exists('cpmsPrimaryColor')) {
    function cpmsPrimaryColor(): string
    {
        return cpmsBrandingHex(
            cpmsBrandingPropertySetting(
                'primary_color',
                'primary_color',
                '#2563eb'
            ),
            '#2563eb'
        );
    }
}

if (!function_exists('cpmsSecondaryColor')) {
    function cpmsSecondaryColor(): string
    {
        return cpmsBrandingHex(
            cpmsBrandingPropertySetting(
                'secondary_color',
                'secondary_color',
                '#0f172a'
            ),
            '#0f172a'
        );
    }
}

if (!function_exists('cpmsBrandingBackgroundPath')) {
    function cpmsBrandingBackgroundPath(): string
    {
        return cpmsBrandingPropertySetting(
            'background_path',
            'background_path',
            'images/bg-premium.jpg'
        );
    }
}

if (!function_exists('cpmsBrandingBackgroundUrl')) {
    function cpmsBrandingBackgroundUrl(): string
    {
        return cpmsAssetUrl(cpmsBrandingBackgroundPath());
    }
}

if (!function_exists('cpmsBrandingSystemName')) {
    function cpmsBrandingSystemName(array $branding): string
    {
        return cpmsBrandingValue(
            $branding,
            'system_name',
            cpmsBrandingValue(
                $branding,
                'property_name',
                'Property Management System'
            )
        );
    }
}

if (!function_exists('cpmsBrandingFooter')) {
    function cpmsBrandingFooter(array $branding): string
    {
        return cpmsBrandingValue(
            $branding,
            'footer_text',
            cpmsBrandingValue(
                $branding,
                'company_name',
                cpmsBrandingSystemName($branding)
            )
        );
    }
}

if (!function_exists('cpmsBrandingShowCpms')) {
    function cpmsBrandingShowCpms(array $branding): bool
    {
        return (int) ($branding['show_cpms_branding'] ?? 1) === 1;
    }
}

if (!function_exists('cpmsLegacyValue')) {
    function cpmsLegacyValue(
        array $propertyKeys,
        array $systemKeys,
        string $fallback = ''
    ): string {
        $property = cpmsBrandingCurrentProperty();
        $settings = cpmsBrandingSettings();

        foreach ($propertyKeys as $key) {
            $value = trim((string) ($property[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        foreach ($systemKeys as $key) {
            $value = trim((string) ($settings[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }
}

if (!function_exists('cpmsSystemName')) {
    function cpmsSystemName(): string
    {
        return cpmsLegacyValue(
            ['system_name'],
            ['system_name'],
            'Commercial Property Management System'
        );
    }
}

if (!function_exists('cpmsPropertyName')) {
    function cpmsPropertyName(): string
    {
        return cpmsLegacyValue(
            ['property_name', 'name'],
            ['property_name'],
            'V23 Malawa Ria Apartment'
        );
    }
}

if (!function_exists('cpmsCompanyName')) {
    function cpmsCompanyName(): string
    {
        return cpmsLegacyValue(
            ['company_name'],
            ['company_name'],
            'Property Management'
        );
    }
}

if (!function_exists('cpmsContactPhone')) {
    function cpmsContactPhone(): string
    {
        return cpmsLegacyValue(
            ['phone', 'contact_phone'],
            ['contact_phone', 'phone'],
            ''
        );
    }
}

if (!function_exists('cpmsContactEmail')) {
    function cpmsContactEmail(): string
    {
        return cpmsLegacyValue(
            ['email', 'contact_email'],
            ['contact_email', 'email'],
            ''
        );
    }
}

if (!function_exists('cpmsAddress')) {
    function cpmsAddress(): string
    {
        return cpmsLegacyValue(
            ['address'],
            ['address'],
            ''
        );
    }
}

if (!function_exists('cpmsLogo')) {
    function cpmsLogo(): string
    {
        return cpmsAssetUrl(
            cpmsLegacyValue(
                ['logo_path'],
                ['logo_path'],
                'images/logo.png'
            )
        );
    }
}

if (!function_exists('cpmsFooter')) {
    function cpmsFooter(): string
    {
        return cpmsBrandingFooter(
            array_merge(
                cpmsBrandingSettings(),
                cpmsBrandingCurrentProperty()
            )
        );
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(
        string $haystack,
        string $needle
    ): bool {
        return $needle === ''
            || strncmp(
                $haystack,
                $needle,
                strlen($needle)
            ) === 0;
    }
}
