<?php

/**
 * Legacy branding helper (root includes/branding.php).
 * Dikemaskini supaya turut semak $GLOBALS['cpms_active_property']
 * (data property sebenar yang diselesaikan ikut ?property=CODE atau
 * session) SEBELUM jatuh balik ke $cpmsSettings sistem / default.
 */

if (!function_exists('cpmsBrandingLegacyValue')) {
    function cpmsBrandingLegacyValue(string $key, string $fallback = ''): string
    {
        $property = $GLOBALS['cpms_active_property'] ?? null;
        if (is_array($property) && !empty($property[$key])) {
            return (string) $property[$key];
        }

        global $cpmsSettings;
        $value = setting($cpmsSettings, $key, '');
        if ($value !== '') {
            return (string) $value;
        }

        return $fallback;
    }
}

if (!function_exists('cpmsPropertyName')) {
    function cpmsPropertyName(): string {
        return cpmsBrandingLegacyValue('property_name', 'Property');
    }
}
if (!function_exists('cpmsSystemName')) {
    function cpmsSystemName(): string {
        return cpmsBrandingLegacyValue('system_name', 'Commercial Property Management System');
    }
}
if (!function_exists('cpmsShortName')) {
    function cpmsShortName(): string {
        global $cpmsSettings;
        return setting($cpmsSettings, "system_short_name", "CPMS");
    }
}
if (!function_exists('cpmsLogo')) {
    function cpmsLogo(): string {
        return cpmsBrandingLegacyValue('logo_path', 'images/logo.png');
    }
}
if (!function_exists('cpmsPrimaryColor')) {
    function cpmsPrimaryColor(): string {
        return cpmsBrandingLegacyValue('primary_color', '#3a2419');
    }
}
if (!function_exists('cpmsSecondaryColor')) {
    function cpmsSecondaryColor(): string {
        return cpmsBrandingLegacyValue('secondary_color', '#b59b20');
    }
}
if (!function_exists('cpmsContactPhone')) {
    function cpmsContactPhone(): string {
        return cpmsBrandingLegacyValue('phone');
    }
}
if (!function_exists('cpmsContactEmail')) {
    function cpmsContactEmail(): string {
        return cpmsBrandingLegacyValue('email');
    }
}
if (!function_exists('cpmsAddress')) {
    function cpmsAddress(): string {
        return cpmsBrandingLegacyValue('address');
    }
}
if (!function_exists('cpmsFooter')) {
    function cpmsFooter(): string {
        global $cpmsSettings;
        return cpmsShortName() . " Version " .
            setting($cpmsSettings, "software_version", "1.0.0") .
            " · Powered by " .
            setting($cpmsSettings, "powered_by", "Azhari Technologies");
    }
}
