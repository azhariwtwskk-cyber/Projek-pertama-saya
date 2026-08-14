<?php

declare(strict_types=1);

function cpmsSystemBrandingUpload(
    array $file,
    string $fieldName
): ?string {
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Muat naik ' . $fieldName . ' tidak berjaya.');
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    $temporaryPath = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

    if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException($fieldName . ' mesti merupakan fail sah berukuran maksimum 5 MB.');
    }

    $imageInfo = @getimagesize($temporaryPath);
    if ($imageInfo === false) {
        throw new RuntimeException($fieldName . ' bukan fail imej yang sah.');
    }

    $width = isset($imageInfo[0]) ? (int) $imageInfo[0] : 0;
    $height = isset($imageInfo[1]) ? (int) $imageInfo[1] : 0;
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
        throw new RuntimeException('Dimensi ' . $fieldName . ' tidak dibenarkan.');
    }

    $mime = isset($imageInfo['mime']) ? (string) $imageInfo['mime'] : '';
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException($fieldName . ' hanya menerima PNG, JPG, WEBP atau ICO.');
    }

    $safeField = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($fieldName));
    $safeField = trim((string) $safeField, '-');
    if ($safeField === '') {
        $safeField = 'asset';
    }

    $relativeDirectory = 'uploads/branding/system';
    $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;

    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0755, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Folder aset global tidak dapat dicipta.');
    }

    try {
        $randomPart = bin2hex(random_bytes(8));
    } catch (Throwable $error) {
        $randomPart = sha1(uniqid('', true));
    }

    $fileName = $safeField . '-' . $randomPart . '.' . $extensions[$mime];
    $absolutePath = $absoluteDirectory . '/' . $fileName;

    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException($fieldName . ' tidak dapat disimpan pada server.');
    }

    return $relativeDirectory . '/' . $fileName;
}
