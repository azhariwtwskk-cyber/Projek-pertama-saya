<?php
declare(strict_types=1);

/**
 * CPMS Branding v3.1 emergency compatibility loader.
 *
 * Include this file immediately after config.php in legacy pages only
 * when required. The full migration installer normally makes this file
 * unnecessary.
 */

if (!function_exists('cpmsBrandingLegacyColumnMap')) {
    function cpmsBrandingLegacyColumnMap(string $sql): string
    {
        return str_replace(
            ['login_background_path', 'background_image'],
            ['background_path', 'background_path'],
            $sql
        );
    }
}
