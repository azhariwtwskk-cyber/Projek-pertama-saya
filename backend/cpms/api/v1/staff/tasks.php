<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
cpmsApiRespond([
    'tasks' => cpmsApiTaskRows(cpmsApiDatabase(), $identity, $limit),
]);

