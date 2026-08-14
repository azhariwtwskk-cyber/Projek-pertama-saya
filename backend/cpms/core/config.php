<?php
declare(strict_types=1);

function cpmsConfig(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $file = dirname(__DIR__) . '/config/app.php';

        if (!is_file($file)) {
            throw new RuntimeException('Fail config/app.php tidak ditemui.');
        }

        $config = require $file;

        if (!is_array($config)) {
            throw new RuntimeException('Format config/app.php tidak sah.');
        }
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}
