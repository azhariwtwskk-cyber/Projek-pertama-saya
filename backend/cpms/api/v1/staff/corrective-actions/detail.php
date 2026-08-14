<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/cpms/includes/corrective_action_service.php';

cpmsApiMethod('GET');
$identity = cpmsApiRequireRole(['staff']);
$db = cpmsApiDatabase();
$propertyId = (int) $identity['property_id'];
$actionId = (int) ($_GET['id'] ?? 0);

if ($actionId < 1) {
    cpmsApiError('ID_REQUIRED', 'ID corrective action diperlukan.', 422);
}

$action = cpmsActionFind($db, $propertyId, $actionId);
if (!is_array($action) || (int) $action['assigned_system_user_id'] !== (int) $identity['system_user_id']) {
    cpmsApiError('NOT_FOUND', 'Corrective action tidak dijumpai atau bukan milik anda.', 404);
}

$images = cpmsActionImages($db, $propertyId, $actionId);
$imagesOut = array_map(static fn ($img) => [
    'id' => (int) $img['id'],
    'phase' => (string) $img['image_phase'],
    'path' => (string) $img['image_path'],
    'caption' => (string) ($img['caption'] ?? ''),
], $images);

cpmsApiRespond([
    'action' => [
        'id' => (int) $action['id'],
        'action_no' => (string) $action['action_no'],
        'title' => (string) $action['title'],
        'description' => (string) $action['description'],
        'priority' => (string) $action['priority'],
        'due_date' => $action['due_date'] !== null ? (string) $action['due_date'] : null,
        'status' => (string) $action['status'],
        'rectification_notes' => (string) ($action['rectification_notes'] ?? ''),
        'inspection_no' => (string) $action['inspection_no'],
        'location' => (string) ($action['location'] ?? ''),
        'finding' => (string) ($action['finding'] ?? ''),
        'recommendation' => (string) ($action['recommendation'] ?? ''),
    ],
    'images' => $imagesOut,
]);
