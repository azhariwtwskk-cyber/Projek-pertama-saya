<?php
declare(strict_types=1);

function cpmsBrandingUpload(
    array $file,
    string $fieldName,
    int $propertyId
): ?string {
    if (
        !isset($file['error'])
        || (int) $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            sprintf('%s upload failed.', $fieldName)
        );
    }

    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException(
            sprintf('%s must not exceed 5 MB.', $fieldName)
        );
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $mime = '';

    if (function_exists('finfo_open')) {
        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info) {
            $mime = (string) finfo_file($info, $tmp);
            finfo_close($info);
        }
    }

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            sprintf(
                '%s must be PNG, JPG, WEBP or ICO.',
                $fieldName
            )
        );
    }

    $uploadRoot = dirname(__DIR__, 2) . '/uploads/branding';
    $propertyFolder = $uploadRoot . '/property_' . $propertyId;

    if (
        !is_dir($propertyFolder)
        && !mkdir($propertyFolder, 0755, true)
        && !is_dir($propertyFolder)
    ) {
        throw new RuntimeException(
            'The branding upload directory could not be created.'
        );
    }

    $filename = sprintf(
        '%s_%s.%s',
        preg_replace('/[^a-z0-9_]+/i', '_', strtolower($fieldName)),
        bin2hex(random_bytes(6)),
        $allowed[$mime]
    );

    $destination = $propertyFolder . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException(
            sprintf('%s could not be saved.', $fieldName)
        );
    }

    return sprintf(
        'uploads/branding/property_%d/%s',
        $propertyId,
        $filename
    );
}
