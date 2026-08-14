<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CPMS Service Layer Bootstrap
|--------------------------------------------------------------------------
| Panggil fail ini selepas cpms_bootstrap.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/property_context.php";
require_once __DIR__ . "/property_guard.php";
require_once __DIR__ . "/modules.php";
require_once __DIR__ . "/audit_engine.php";
require_once dirname(__DIR__) . "/services/CPMS.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException(
        "Sambungan database CPMS tidak tersedia."
    );
}

CPMS::boot(
    $conn,
    $cpmsSettings ?? []
);
