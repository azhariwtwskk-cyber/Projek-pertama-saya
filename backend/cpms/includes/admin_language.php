<?php
declare(strict_types=1);
function cpmsAdminLanguage(): string { return 'en'; }
function cpmsLoadAdminTranslations(string $language): array {
    $file = dirname(__DIR__) . '/lang/admin_' . $language . '.php';
    if (!is_file($file)) { $file = dirname(__DIR__) . '/lang/admin_en.php'; }
    $data = require $file;
    return is_array($data) ? $data : [];
}
function adminT(string $key, ?string $fallback = null): string {
    static $translations = null;
    if ($translations === null) { $translations = cpmsLoadAdminTranslations(cpmsAdminLanguage()); }
    return isset($translations[$key]) && is_string($translations[$key])
        ? $translations[$key]
        : ($fallback ?? $key);
}
