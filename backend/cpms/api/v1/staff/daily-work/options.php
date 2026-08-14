<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

cpmsApiMethod('GET');
cpmsApiRequireRole(['staff']);

cpmsApiRespond([
    'categories' => [
        'Maintenance', 'Cleaning', 'Security', 'Landscape', 'Plumbing', 'Electrical',
        'Civil Work', 'Pest Control', 'Fire Safety', 'Water Tank', 'Playground',
        'Boom Gate', 'Solar CCTV', 'Guard House', 'Drain Cleaning', 'Other',
    ],
    'locations' => [
        'Block A', 'Block B', 'Block C', 'Block D', 'Block E', 'Guard House',
        'Main Entrance / Boom Gate', 'Playground', 'Water Tank', 'Common Area',
        'Car Park', 'Drainage', 'Other',
    ],
    'statuses' => [
        'In Progress', 'Completed', 'Pending Material', 'Pending Contractor', 'Unable to Complete',
    ],
]);
