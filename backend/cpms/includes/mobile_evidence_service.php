<?php
declare(strict_types=1);

function cpmsEvidenceTaskTypes(): array
{
    return [
        'work_order' => 'Work Order',
        'preventive_maintenance' => 'Preventive Maintenance',
        'corrective_action' => 'Corrective Action',
        'security_patrol' => 'Security Patrol',
    ];
}

function cpmsEvidenceLegacyIdentity(mysqli $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT source_table, source_id FROM system_users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function cpmsEvidenceTask(mysqli $db, int $propertyId, int $userId,
    string $type, int $taskId): ?array
{
    if ($propertyId < 1 || $userId < 1 || $taskId < 1
        || !isset(cpmsEvidenceTaskTypes()[$type])) {
        return null;
    }

    if ($type === 'preventive_maintenance') {
        $sql = "SELECT id, schedule_name AS title
                FROM cpms_pm_schedules
                WHERE id = ? AND property_id = ?
                  AND assigned_system_user_id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $taskId, $propertyId, $userId);
    } elseif ($type === 'corrective_action') {
        $sql = "SELECT id, action_reference AS title
                FROM inspection_corrective_actions
                WHERE id = ? AND property_id = ?
                  AND assigned_system_user_id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $taskId, $propertyId, $userId);
    } else {
        $legacy = cpmsEvidenceLegacyIdentity($db, $userId);
        $sourceTable = (string) ($legacy['source_table'] ?? '');
        $sourceId = (int) ($legacy['source_id'] ?? 0);
        if ($sourceId < 1) {
            return null;
        }
        if ($type === 'work_order' && $sourceTable === 'staff') {
            $sql = "SELECT id, COALESCE(title, work_order_reference,
                        CONCAT('Work Order #', id)) AS title
                    FROM work_orders
                    WHERE id = ? AND property_id = ?
                      AND assigned_staff_id = ? LIMIT 1";
        } elseif ($type === 'security_patrol'
            && $sourceTable === 'security_guards') {
            $sql = "SELECT id, COALESCE(patrol_reference,
                        CONCAT('Patrol #', id)) AS title
                    FROM security_patrols
                    WHERE id = ? AND property_id = ?
                      AND guard_id = ? LIMIT 1";
        } else {
            return null;
        }
        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $taskId, $propertyId, $sourceId);
    }

    if (!$stmt) {
        return null;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        return null;
    }
    $row['type_label'] = cpmsEvidenceTaskTypes()[$type];
    return $row;
}
