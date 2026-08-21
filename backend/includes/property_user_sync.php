<?php
declare(strict_types=1);

/* CPMS v3.6.0.2 — Property Portal and Unified Login synchronization. */

function cpmsPropertyUserStatus(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['active', 'inactive', 'suspended'], true)
        ? $status
        : 'inactive';
}

function cpmsPropertyUserRole(string $role): string
{
    $role = strtolower(trim($role));
    return in_array($role, ['property_admin', 'manager', 'clerk'], true)
        ? $role
        : 'property_admin';
}

function cpmsPropertyUserSyncLegacyAccount(
    mysqli $conn,
    int $legacyId,
    int $expectedPropertyId = 0
): int {
    if ($legacyId < 1) {
        throw new RuntimeException('Invalid Property Portal account.');
    }

    $stmt = $conn->prepare(
        "SELECT id,property_id,username,email,password_hash,full_name,phone,
                role,status,must_change_password
         FROM property_admins WHERE id=? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Property Portal account query failed.');
    }
    $stmt->bind_param('i', $legacyId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Property Portal account query failed.');
    }
    $legacy = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$legacy) {
        throw new RuntimeException('Property Portal account was not found.');
    }

    $propertyId = (int) $legacy['property_id'];
    if ($propertyId < 1
        || ($expectedPropertyId > 0 && $propertyId !== $expectedPropertyId)) {
        throw new RuntimeException('Property Portal account scope is invalid.');
    }
    $username = trim((string) $legacy['username']);
    $passwordHash = (string) $legacy['password_hash'];
    if ($username === '' || $passwordHash === '') {
        throw new RuntimeException('Property Portal identity is incomplete.');
    }

    $sourceStmt = $conn->prepare(
        "SELECT id,source_table,source_id FROM system_users
         WHERE source_table='property_admins' AND source_id=? LIMIT 1"
    );
    if (!$sourceStmt) {
        throw new RuntimeException('Unified identity query failed.');
    }
    $sourceStmt->bind_param('i', $legacyId);
    if (!$sourceStmt->execute()) {
        throw new RuntimeException('Unified identity query failed.');
    }
    $sourceUser = $sourceStmt->get_result()->fetch_assoc();
    $sourceStmt->close();

    $nameStmt = $conn->prepare(
        'SELECT id,source_table,source_id FROM system_users
         WHERE username=? LIMIT 1'
    );
    if (!$nameStmt) {
        throw new RuntimeException('Unified username query failed.');
    }
    $nameStmt->bind_param('s', $username);
    if (!$nameStmt->execute()) {
        throw new RuntimeException('Unified username query failed.');
    }
    $nameUser = $nameStmt->get_result()->fetch_assoc();
    $nameStmt->close();

    if ($sourceUser && $nameUser
        && (int) $sourceUser['id'] !== (int) $nameUser['id']) {
        throw new RuntimeException(
            'Unified identity conflict: username belongs to another account.'
        );
    }
    $unified = $sourceUser ?: $nameUser;
    $unifiedId = (int) ($unified['id'] ?? 0);
    if ($nameUser) {
        $sourceTable = trim((string) ($nameUser['source_table'] ?? ''));
        $sourceId = (int) ($nameUser['source_id'] ?? 0);
        if ($sourceTable !== ''
            && ($sourceTable !== 'property_admins' || $sourceId !== $legacyId)) {
            throw new RuntimeException(
                'Unified identity conflict: username is linked elsewhere.'
            );
        }
    }

    $email = trim((string) ($legacy['email'] ?? ''));
    if ($email !== '') {
        $emailStmt = $conn->prepare(
            'SELECT id FROM system_users WHERE email=? AND id<>? LIMIT 1'
        );
        if (!$emailStmt) {
            throw new RuntimeException('Unified email query failed.');
        }
        $emailStmt->bind_param('si', $email, $unifiedId);
        if (!$emailStmt->execute()) {
            throw new RuntimeException('Unified email query failed.');
        }
        $emailConflict = $emailStmt->get_result()->fetch_assoc();
        $emailStmt->close();
        if ($emailConflict) {
            throw new RuntimeException(
                'Unified identity conflict: email is already in use.'
            );
        }
    }

    $fullName = trim((string) $legacy['full_name']);
    if ($fullName === '') {
        $fullName = $username;
    }
    $phone = trim((string) ($legacy['phone'] ?? ''));
    $status = cpmsPropertyUserStatus((string) $legacy['status']);
    $mustChange = (int) ($legacy['must_change_password'] ?? 0) === 1 ? 1 : 0;

    if ($unifiedId > 0) {
        $stmt = $conn->prepare(
            "UPDATE system_users SET property_id=?,username=?,
                email=NULLIF(?,''),password_hash=?,full_name=?,
                phone=NULLIF(?,''),status=?,must_change_password=?,
                source_table='property_admins',source_id=?
             WHERE id=?"
        );
        if (!$stmt) {
            throw new RuntimeException('Unified identity update failed.');
        }
        $stmt->bind_param(
            'issssssiii',
            $propertyId,
            $username,
            $email,
            $passwordHash,
            $fullName,
            $phone,
            $status,
            $mustChange,
            $legacyId,
            $unifiedId
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO system_users (
                property_id,username,email,password_hash,full_name,phone,
                status,must_change_password,source_table,source_id
             ) VALUES (
                ?,?,NULLIF(?,''),?,?,NULLIF(?,''),?,?,'property_admins',?
             )"
        );
        if (!$stmt) {
            throw new RuntimeException('Unified identity creation failed.');
        }
        $stmt->bind_param(
            'issssssii',
            $propertyId,
            $username,
            $email,
            $passwordHash,
            $fullName,
            $phone,
            $status,
            $mustChange,
            $legacyId
        );
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Unified identity synchronization failed.');
    }
    if ($unifiedId < 1) {
        $unifiedId = (int) $conn->insert_id;
    }
    $stmt->close();
    if ($unifiedId < 1) {
        throw new RuntimeException('Unified identity could not be resolved.');
    }

    $roleCode = cpmsPropertyUserRole((string) $legacy['role']);
    $roleStmt = $conn->prepare(
        "SELECT id FROM roles
         WHERE role_code=? AND status='active' LIMIT 1"
    );
    if (!$roleStmt) {
        throw new RuntimeException('Unified role query failed.');
    }
    $roleStmt->bind_param('s', $roleCode);
    if (!$roleStmt->execute()) {
        throw new RuntimeException('Unified role query failed.');
    }
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();
    $roleId = (int) ($roleRow['id'] ?? 0);
    if ($roleId < 1) {
        throw new RuntimeException('Unified role is missing or inactive.');
    }

    $deactivate = $conn->prepare(
        "UPDATE user_roles assignments
         INNER JOIN roles r ON r.id=assignments.role_id
         SET assignments.status='inactive'
         WHERE assignments.system_user_id=?
           AND r.role_code IN ('property_admin','manager','clerk')
           AND (
                assignments.property_id IS NULL
                OR assignments.property_id<>?
                OR r.role_code<>?
           )"
    );
    if (!$deactivate) {
        throw new RuntimeException('Unified role synchronization failed.');
    }
    $deactivate->bind_param('iis', $unifiedId, $propertyId, $roleCode);
    if (!$deactivate->execute()) {
        throw new RuntimeException('Unified role synchronization failed.');
    }
    $deactivate->close();

    $assignment = $conn->prepare(
        "INSERT INTO user_roles
            (system_user_id,role_id,property_id,status)
         VALUES (?,?,?,'active')
         ON DUPLICATE KEY UPDATE status='active',expires_at=NULL"
    );
    if (!$assignment) {
        throw new RuntimeException('Unified role assignment failed.');
    }
    $assignment->bind_param('iii', $unifiedId, $roleId, $propertyId);
    if (!$assignment->execute()) {
        throw new RuntimeException('Unified role assignment failed.');
    }
    $assignment->close();

    return $unifiedId;
}

function cpmsPropertyUserMirrorPasswordFromSystem(
    mysqli $conn,
    int $systemUserId
): void {
    if ($systemUserId < 1) {
        return;
    }
    $stmt = $conn->prepare(
        "SELECT source_table,source_id,password_hash,must_change_password
         FROM system_users WHERE id=? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unified password query failed.');
    }
    $stmt->bind_param('i', $systemUserId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unified password query failed.');
    }
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (string) $user['source_table'] !== 'property_admins') {
        return;
    }
    $legacyId = (int) $user['source_id'];
    if ($legacyId < 1) {
        return;
    }
    $passwordHash = (string) $user['password_hash'];
    $mustChange = (int) $user['must_change_password'] === 1 ? 1 : 0;
    $update = $conn->prepare(
        "UPDATE property_admins SET password_hash=?,must_change_password=?,
            password_changed_at=NOW(),updated_at=NOW()
         WHERE id=?"
    );
    if (!$update) {
        throw new RuntimeException('Property Portal password sync failed.');
    }
    $update->bind_param('sii', $passwordHash, $mustChange, $legacyId);
    if (!$update->execute()) {
        throw new RuntimeException('Property Portal password sync failed.');
    }
    $update->close();
}
