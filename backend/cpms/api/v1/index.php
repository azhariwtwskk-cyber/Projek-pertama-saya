<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
cpmsApiRespond([
    'service' => 'CPMS Workforce API',
    'version' => CPMS_WORKFORCE_API_VERSION,
    'status' => 'online',
]);

