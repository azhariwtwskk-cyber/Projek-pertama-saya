<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CPMS V24 COMMERCIAL CORE BOOTSTRAP
|--------------------------------------------------------------------------
| Gunakan satu baris ini pada setiap halaman:
|
| require_once __DIR__ . '/core/bootstrap.php';
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helper.php';

cpmsStartSession();

date_default_timezone_set(
    (string)cpmsConfig(
        'timezone',
        'Asia/Kuala_Lumpur'
    )
);

require_once __DIR__ . '/error_handler.php';
cpmsRegisterErrorHandler();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/property.php';
require_once __DIR__ . '/permission.php';
require_once __DIR__ . '/language.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/notification.php';

/*
|--------------------------------------------------------------------------
| Global context
|--------------------------------------------------------------------------
*/

$cpmsUser = [
    'id' => cpmsCurrentUserId(),
    'name' => cpmsCurrentUserName(),
    'role' => cpmsCurrentUserRole(),
];

$cpmsPropertyId = cpmsCurrentPropertyId();
$cpmsTheme = cpmsTheme($conn);
