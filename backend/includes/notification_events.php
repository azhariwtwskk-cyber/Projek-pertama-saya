<?php
declare(strict_types=1);

require_once __DIR__ . '/notification_engine.php';

/**
 * Event helpers untuk integrasi cepat.
 */

function notify_new_complaint(
    mysqli $conn,
    int $propertyId,
    int $complaintId,
    string $complaintReference,
    string $category = 'General'
): int {
    return notification_create_for_admins(
        $conn,
        $propertyId,
        'complaint',
        'Aduan Baharu Diterima',
        "Aduan {$complaintReference} bagi kategori {$category} telah diterima.",
        'info',
        'admin_complaint_details.php?id=' . $complaintId,
        'complaints',
        $complaintId
    );
}


function notify_complaint_assigned_to_staff(
    mysqli $conn,
    int $propertyId,
    int $staffId,
    int $complaintId,
    string $complaintReference
): int {
    return notification_create_for_user(
        $conn,
        $propertyId,
        'staff',
        $staffId,
        'complaint',
        'Aduan Ditugaskan Kepada Anda',
        "Aduan {$complaintReference} telah ditugaskan kepada anda.",
        'warning',
        'staff_complaint_details.php?id=' . $complaintId,
        'complaints',
        $complaintId
    );
}


function notify_new_work_order(
    mysqli $conn,
    int $propertyId,
    int $workOrderId,
    string $workOrderReference,
    string $priority = 'info'
): int {
    return notification_create_for_admins(
        $conn,
        $propertyId,
        'work_order',
        'Work Order Baharu',
        "Work Order {$workOrderReference} telah diwujudkan.",
        $priority,
        'work_order_details.php?id=' . $workOrderId,
        'work_orders',
        $workOrderId
    );
}


function notify_work_order_assigned_to_staff(
    mysqli $conn,
    int $propertyId,
    int $staffId,
    int $workOrderId,
    string $workOrderReference
): int {
    return notification_create_for_user(
        $conn,
        $propertyId,
        'staff',
        $staffId,
        'work_order',
        'Work Order Ditugaskan',
        "Work Order {$workOrderReference} telah ditugaskan kepada anda.",
        'warning',
        'staff_work_order_details.php?id=' . $workOrderId,
        'work_orders',
        $workOrderId
    );
}


function notify_patrol_issue(
    mysqli $conn,
    int $propertyId,
    int $patrolId,
    string $patrolReference,
    string $priority = 'warning'
): int {
    return notification_create_for_admins(
        $conn,
        $propertyId,
        'patrol',
        'Isu Ditemui Semasa Patrol',
        "Isu telah direkodkan dalam patrol {$patrolReference}.",
        $priority,
        'security_patrol_details.php?id=' . $patrolId,
        'security_patrols',
        $patrolId
    );
}


function notify_checkpoint_completed(
    mysqli $conn,
    int $propertyId,
    int $patrolId,
    int $guardId,
    string $checkpointName
): int {
    return notification_create_for_user(
        $conn,
        $propertyId,
        'security',
        $guardId,
        'patrol',
        'Checkpoint Direkodkan',
        "Checkpoint {$checkpointName} berjaya direkodkan.",
        'info',
        'security_patrol_checkpoint_progress.php?patrol_id=' . $patrolId,
        'security_patrols',
        $patrolId
    );
}


function notify_asset_issue(
    mysqli $conn,
    int $propertyId,
    int $assetId,
    string $assetName
): int {
    return notification_create_for_admins(
        $conn,
        $propertyId,
        'asset',
        'Isu Aset Dikesan',
        "Aset {$assetName} memerlukan pemeriksaan atau tindakan.",
        'warning',
        'asset_details.php?id=' . $assetId,
        'assets',
        $assetId
    );
}


function notify_pm_due(
    mysqli $conn,
    int $propertyId,
    int $maintenanceId,
    string $maintenanceTitle
): int {
    return notification_create_for_admins(
        $conn,
        $propertyId,
        'maintenance',
        'Preventive Maintenance Akan Tamat',
        "{$maintenanceTitle} perlu dilaksanakan atau diperbaharui.",
        'warning',
        'preventive_maintenance_details.php?id=' . $maintenanceId,
        'preventive_maintenance',
        $maintenanceId
    );
}
