<?php
declare(strict_types=1);

require_once __DIR__ . '/branding.php';

if (!function_exists('cpmsThemeStyleTag')) {
    function cpmsThemeStyleTag(): string
    {
        $primary = cpmsPrimaryColor();
        $secondary = cpmsSecondaryColor();
        $backgroundUrl = cpmsBrandingBackgroundUrl();

        $css = ':root{'
            . '--cpms-primary:' . $primary . ';'
            . '--cpms-secondary:' . $secondary . ';'
            . '--cpms-background-image:';

        if ($backgroundUrl !== '') {
            $css .= 'url("' . htmlspecialchars(
                $backgroundUrl,
                ENT_QUOTES,
                'UTF-8'
            ) . '")';
        } else {
            $css .= 'none';
        }

        $css .= ';}';

        return '<style>' . $css . '</style>';
    }
}
