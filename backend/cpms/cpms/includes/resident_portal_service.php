<?php
declare(strict_types=1);

function cpmsResidentBridge(mysqli $conn): ?array
{
    $systemUserId = (int) ($_SESSION['cpms_user_id'] ?? 0);
    $propertyId = (int) ($_SESSION['cpms_property_id'] ?? $_SESSION['cpms_current_property_id'] ?? 0);
    $role = (string) ($_SESSION['cpms_user_role'] ?? '');
    $channel = (string) ($_SESSION['cpms_auth_channel'] ?? '');
    if (
        $systemUserId < 1
        || $propertyId < 1
        || $role !== 'resident'
        || $channel !== 'resident'
    ) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT r.*
         FROM system_users u
         JOIN cpms_residents r
           ON u.source_table = 'cpms_residents' AND u.source_id = r.id
         WHERE u.id = ? AND r.property_id = ? AND r.is_active = 1
         LIMIT 1"
    );
    $stmt->bind_param('ii', $systemUserId, $propertyId);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $resident ?: null;
}

function cpmsResidentRequire(mysqli $conn): array
{
    $resident = cpmsResidentBridge($conn);
    if (!$resident) {
        header('Location: resident_login.php?expired=1');
        exit;
    }
    return $resident;
}

function cpmsResidentReference(int $propertyId): string
{
    return sprintf('RS-%d-%s-%04d', $propertyId, date('ymd'), random_int(1, 9999));
}
