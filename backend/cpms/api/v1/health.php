<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
cpmsApiMethod('GET');
$db = cpmsApiDatabase();
$ready = cpmsApiTablesReady($db);
cpmsApiRespond([
    'service' => 'CPMS Workforce API',
    'version' => CPMS_WORKFORCE_API_VERSION,
    'database' => 'connected',
    'migration' => $ready ? 'ready' : 'pending',
    'server_time' => date(DATE_ATOM),
], $ready ? 200 : 503);

