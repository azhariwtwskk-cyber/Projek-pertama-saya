<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

cpmsRequireLogin();
cpmsRequireProperty();
cpmsRequirePermission('complaint.manage');

/*
|--------------------------------------------------------------------------
| Contoh penggunaan helper
|--------------------------------------------------------------------------
*/

$propertyId = cpmsCurrentPropertyId();
$userId = cpmsCurrentUserId();
$userRole = cpmsCurrentUserRole();

cpmsLog(
    $conn,
    'complaint_updated',
    'complaints',
    25,
    'Status aduan ditukar kepada In Progress.',
    [
        'old_status' => 'Pending',
        'new_status' => 'In Progress',
    ]
);

$message = cpmsSuccess(
    'Rekod berjaya dikemas kini.',
    [
        'property_id' => $propertyId,
        'user_id' => $userId,
        'role' => $userRole,
    ]
);
