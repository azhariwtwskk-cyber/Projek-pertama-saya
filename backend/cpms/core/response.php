<?php
declare(strict_types=1);

function cpmsSuccess(
    string $message,
    array $data = []
): array {
    return [
        'success' => true,
        'message' => $message,
        'data' => $data,
    ];
}

function cpmsFailure(
    string $message,
    array $errors = []
): array {
    return [
        'success' => false,
        'message' => $message,
        'errors' => $errors,
    ];
}
