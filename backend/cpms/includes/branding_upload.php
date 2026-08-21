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

    if ($propertyId <= 0) {
        throw new RuntimeException('Invalid property upload context.');
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            sprintf('%s upload failed.', $fieldName)
        );
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException(
            sprintf('%s must be a valid file not exceeding 5 MB.', $fieldName)
        );
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException(
            sprintf('%s is not a valid HTTP upload.', $fieldName)
        );
    }

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
            sprintf('%s must be PNG, JPG, WEBP or ICO.', $fieldName)
        );
    }

    if ($allowed[$mime] !== 'ico') {
        $imageInfo = @getimagesize($tmp);
        if ($imageInfo === false) {
            throw new RuntimeException(
                sprintf('%s does not contain a valid image.', $fieldName)
            );
        }

        [$width, $height] = $imageInfo;
        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
            throw new RuntimeException(
                sprintf('%s image dimensions are not allowed.', $fieldName)
            );
        }
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

    if (!is_writable($propertyFolder)) {
        throw new RuntimeException(
            'The branding upload directory is not writable.'
        );
    }

    $safeField = preg_replace(
        '/[^a-z0-9_]+/i',
        '_',
        strtolower($fieldName)
    );

    $filename = sprintf(
        '%s_%s.%s',
        trim((string) $safeField, '_') ?: 'branding',
        bin2hex(random_bytes(12)),
        $allowed[$mime]
    );

    $destination = $propertyFolder . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException(
            sprintf('%s could not be saved.', $fieldName)
        );
    }

    @chmod($destination, 0644);

    return sprintf(
        'uploads/branding/property_%d/%s',
        $propertyId,
        $filename
    );
}
