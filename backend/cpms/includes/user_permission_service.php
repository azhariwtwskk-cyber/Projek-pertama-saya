<?php
declare(strict_types=1);

/*
 * Safe, property-scoped additional module access for Clerk accounts.
 * High-risk administration permissions are intentionally not exposed.
 */

function cpmsDelegatedModuleCatalog(): array
{
    return [
        'announcements' => [
            'label' => 'Announcement',
            'description' => 'Cipta, terbit dan arkib pengumuman resident.',
            'permissions' => [
                'notices.view',
                'notices.manage',
                'resident.announcement.manage',
            ],
        ],
        'facility_booking' => [
            'label' => 'Facility Booking',
            'description' => 'Urus fasiliti, lulus tempahan dan pembatalan.',
            'permissions' => [
                'facilities.view',
                'facilities.manage',
                'facility.booking.approve',
                'facility.booking.cancel',
                'facility.booking.calendar',
            ],
        ],
        'work_orders' => [
            'label' => 'Work Orders',
            'description' => 'Lihat, cipta dan kemas kini arahan kerja.',
            'permissions' => [
                'work_orders.view',
                'work_orders.create',
                'work_orders.update',
            ],
        ],
        'resident_registrations' => [
            'label' => 'Resident Registration Approval',
            'description' => 'Semak, lulus atau tolak permohonan akaun Resident Portal.',
            'permissions' => [
                'residents.view',
                'resident.registration.manage',
            ],
        ],
        'resident_requests' => [
            'label' => 'Resident Requests',
            'description' => 'Semak dan urus permohonan resident.',
            'permissions' => [
                'residents.view',
                'resident.request.manage',
            ],
        ],
        'inspections' => [
            'label' => 'Inspections',
            'description' => 'Lihat, cipta dan kemas kini pemeriksaan.',
            'permissions' => [
                'inspection.view',
                'inspection.create',
                'inspection.update',
            ],
        ],
        'reports' => [
            'label' => 'Reports',
            'description' => 'Lihat dan eksport laporan operasi.',
            'permissions' => [
                'reports.view',
                'reports.export',
            ],
        ],
    ];
}

function cpmsDelegatedPermissionCodes(): array
{
    $codes = [];
    foreach (cpmsDelegatedModuleCatalog() as $module) {
        foreach ($module['permissions'] as $permission) {
            $codes[(string) $permission] = true;
        }
    }
    return array_keys($codes);
}

function cpmsDelegatedPropertyClerks(
    mysqli $db,
    int $propertyId
): array {
    $stmt = $db->prepare(
        "SELECT pa.id,pa.full_name,pa.username,pa.status,
                su.id AS system_user_id
         FROM property_admins pa
         LEFT JOIN system_users su
           ON su.source_table = 'property_admins'
          AND su.source_id = pa.id
          AND su.property_id = pa.property_id
         WHERE pa.property_id = ?
           AND pa.role = 'clerk'
         ORDER BY pa.full_name,pa.id"
    );
    if (!$stmt) {
        throw new RuntimeException('Senarai Kerani tidak dapat dibuka.');
    }

    $stmt->bind_param('i', $propertyId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Senarai Kerani tidak dapat dibuka.');
    }

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cpmsDelegatedClerk(
    mysqli $db,
    int $propertyId,
    int $legacyUserId
): ?array {
    $stmt = $db->prepare(
        "SELECT pa.id,pa.full_name,pa.username,pa.status,
                su.id AS system_user_id
         FROM property_admins pa
         LEFT JOIN system_users su
           ON su.source_table = 'property_admins'
          AND su.source_id = pa.id
          AND su.property_id = pa.property_id
         WHERE pa.id = ?
           AND pa.property_id = ?
           AND pa.role = 'clerk'
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $legacyUserId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) ? $row : null;
}

function cpmsDelegatedGrantedCodes(
    mysqli $db,
    int $propertyId,
    int $systemUserId
): array {
    $stmt = $db->prepare(
        "SELECT p.permission_code
         FROM cpms_user_permission_grants g
         INNER JOIN permissions p ON p.id = g.permission_id
         WHERE g.system_user_id = ?
           AND g.property_id = ?
           AND g.status = 'active'
           AND g.grant_source = 'clerk_module_assignment'"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $systemUserId, $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $codes = [];
    while ($row = $result->fetch_assoc()) {
        $code = (string) ($row['permission_code'] ?? '');
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    $stmt->close();
    return $codes;
}

function cpmsDelegatedSelectedModules(array $grantedCodes): array
{
    $selected = [];
    foreach (cpmsDelegatedModuleCatalog() as $key => $module) {
        $complete = true;
        foreach ($module['permissions'] as $permission) {
            if (empty($grantedCodes[(string) $permission])) {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            $selected[(string) $key] = true;
        }
    }
    return $selected;
}

function cpmsSaveDelegatedModules(
    mysqli $db,
    int $propertyId,
    int $systemUserId,
    int $assignedBySystemUserId,
    array $selectedModuleKeys
): void {
    if (
        $propertyId <= 0
        || $systemUserId <= 0
        || $assignedBySystemUserId <= 0
    ) {
        throw new RuntimeException(
            'Unified Login Kerani atau pemberi akses tidak lengkap.'
        );
    }

    $catalog = cpmsDelegatedModuleCatalog();
    $selectedCodes = [];
    foreach ($selectedModuleKeys as $key) {
        $key = (string) $key;
        if (!isset($catalog[$key])) {
            throw new RuntimeException('Pilihan modul tidak sah.');
        }
        foreach ($catalog[$key]['permissions'] as $permission) {
            $selectedCodes[(string) $permission] = true;
        }
    }

    $db->begin_transaction();
    try {
        $delete = $db->prepare(
            "DELETE FROM cpms_user_permission_grants
             WHERE system_user_id = ?
               AND property_id = ?
               AND grant_source = 'clerk_module_assignment'"
        );
        if (!$delete) {
            throw new RuntimeException('Akses lama tidak dapat dikemas kini.');
        }
        $delete->bind_param('ii', $systemUserId, $propertyId);
        if (!$delete->execute()) {
            $delete->close();
            throw new RuntimeException('Akses lama tidak dapat dikemas kini.');
        }
        $delete->close();

        if ($selectedCodes) {
            $insert = $db->prepare(
                "INSERT INTO cpms_user_permission_grants (
                    system_user_id,property_id,permission_id,
                    assigned_by_system_user_id,grant_source,status
                 )
                 SELECT ?,?,p.id,?,'clerk_module_assignment','active'
                 FROM permissions p
                 WHERE p.permission_code = ?
                   AND p.status = 'active'
                 ON DUPLICATE KEY UPDATE
                    assigned_by_system_user_id = VALUES(
                        assigned_by_system_user_id
                    ),
                    grant_source = VALUES(grant_source),
                    status = 'active',
                    updated_at = CURRENT_TIMESTAMP"
            );
            if (!$insert) {
                throw new RuntimeException('Akses baharu tidak dapat disediakan.');
            }

            foreach (array_keys($selectedCodes) as $permissionCode) {
                $insert->bind_param(
                    'iiis',
                    $systemUserId,
                    $propertyId,
                    $assignedBySystemUserId,
                    $permissionCode
                );
                if (!$insert->execute() || $insert->affected_rows < 1) {
                    throw new RuntimeException(
                        'Permission belum tersedia: ' . $permissionCode
                    );
                }
            }
            $insert->close();
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}
