<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
cpmsApiMethod('GET');
$identity = cpmsApiAuth();
cpmsApiRespond([
    'user' => [
        'id' => (int) $identity['system_user_id'],
        'name' => (string) $identity['name'],
        'username' => (string) $identity['username'],
        'role' => (string) $identity['role'],
    ],
    'property' => $identity['property'],
]);

