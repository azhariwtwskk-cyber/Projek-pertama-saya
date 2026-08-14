<?php
declare(strict_types=1);

/**
 * CPMS Core v2 Smart Property Resolver.
 *
 * Resolution priority:
 * 1. Explicit property id set in the canonical or legacy session.
 * 2. Property code supplied through POST/GET.
 * 3. Property code detected from the hostname.
 * 4. First active property as a backward-compatible fallback.
 */

function cpmsV2PropertyActiveCondition(mysqli $conn): string
{
    if (cpmsV2ColumnExists($conn, 'cpms_properties', 'is_active')) {
        return 'is_active = 1';
    }

    if (cpmsV2ColumnExists($conn, 'cpms_properties', 'status')) {
        return "LOWER(TRIM(status)) = 'active'";
    }

    return '1 = 1';
}

function cpmsV2FindPropertyById(mysqli $conn, int $propertyId): ?array
{
    if ($propertyId < 1 || !cpmsV2TableExists($conn, 'cpms_properties')) {
        return null;
    }

    $activeCondition = cpmsV2PropertyActiveCondition($conn);
    $statement = $conn->prepare(
        "SELECT *
         FROM cpms_properties
         WHERE id = ?
           AND {$activeCondition}
         LIMIT 1"
    );

    if (!$statement) {
        throw new RuntimeException(
            'Unable to prepare property query: ' . $conn->error
        );
    }

    $statement->bind_param('i', $propertyId);
    $statement->execute();
    $result = $statement->get_result();
    $property = $result ? $result->fetch_assoc() : null;
    $statement->close();

    return is_array($property) ? $property : null;
}

function cpmsV2FindPropertyByCode(
    mysqli $conn,
    string $propertyCode
): ?array {
    $propertyCode = strtoupper(trim($propertyCode));

    if (
        $propertyCode === ''
        || !cpmsV2TableExists($conn, 'cpms_properties')
        || !cpmsV2ColumnExists(
            $conn,
            'cpms_properties',
            'property_code'
        )
    ) {
        return null;
    }

    $activeCondition = cpmsV2PropertyActiveCondition($conn);
    $statement = $conn->prepare(
        "SELECT *
         FROM cpms_properties
         WHERE UPPER(TRIM(property_code)) = ?
           AND {$activeCondition}
         LIMIT 1"
    );

    if (!$statement) {
        throw new RuntimeException(
            'Unable to prepare property-code query: ' . $conn->error
        );
    }

    $statement->bind_param('s', $propertyCode);
    $statement->execute();
    $result = $statement->get_result();
    $property = $result ? $result->fetch_assoc() : null;
    $statement->close();

    return is_array($property) ? $property : null;
}

function cpmsV2PropertySessionId(): int
{
    $keys = [
        'cpms_current_property_id',
        'property_admin_property_id',
        'admin_property_id',
        'staff_property_id',
        'security_property_id',
        'resident_property_id',
        'property_id',
    ];

    foreach ($keys as $key) {
        $value = (int) ($_SESSION[$key] ?? 0);
        if ($value > 0) {
            return $value;
        }
    }

    return 0;
}

function cpmsV2RequestedPropertyCode(): string
{
    $values = [
        $_POST['property_code'] ?? '',
        $_GET['property'] ?? '',
        $_GET['property_code'] ?? '',
    ];

    foreach ($values as $value) {
        $code = strtoupper(trim((string) $value));
        if ($code !== '') {
            return $code;
        }
    }

    return '';
}

function cpmsV2HostnamePropertyCode(mysqli $conn): string
{
    if (
        !cpmsV2TableExists($conn, 'cpms_properties')
        || !cpmsV2ColumnExists(
            $conn,
            'cpms_properties',
            'property_code'
        )
    ) {
        return '';
    }

    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host);

    if ($host === '') {
        return '';
    }

    $activeCondition = cpmsV2PropertyActiveCondition($conn);
    $result = $conn->query(
        "SELECT property_code
         FROM cpms_properties
         WHERE {$activeCondition}
         ORDER BY CHAR_LENGTH(property_code) DESC, id ASC"
    );

    if (!($result instanceof mysqli_result)) {
        return '';
    }

    while ($row = $result->fetch_assoc()) {
        $code = strtolower(trim((string) ($row['property_code'] ?? '')));
        if ($code === '') {
            continue;
        }

        if (
            strpos($host, $code . '.') === 0
            || strpos($host, '-' . $code . '.') !== false
            || strpos($host, $code) !== false
        ) {
            return strtoupper($code);
        }
    }

    return '';
}

function cpmsV2ResolveProperty(mysqli $conn): ?array
{
    $propertyId = cpmsV2PropertySessionId();

    if ($propertyId > 0) {
        $property = cpmsV2FindPropertyById($conn, $propertyId);

        if ($property !== null) {
            $_SESSION['cpms_current_property_id'] = (int) $property['id'];
            return $property;
        }
    }

    $propertyCode = cpmsV2RequestedPropertyCode();

    if ($propertyCode === '') {
        $propertyCode = cpmsV2HostnamePropertyCode($conn);
    }

    if ($propertyCode !== '') {
        $property = cpmsV2FindPropertyByCode($conn, $propertyCode);

        if ($property !== null) {
            $_SESSION['cpms_current_property_id'] = (int) $property['id'];
            $_SESSION['cpms_current_property_code'] = (
                (string) ($property['property_code'] ?? '')
            );
            return $property;
        }
    }

    if (!cpmsV2TableExists($conn, 'cpms_properties')) {
        return null;
    }

    $activeCondition = cpmsV2PropertyActiveCondition($conn);
    $result = $conn->query(
        "SELECT *
         FROM cpms_properties
         WHERE {$activeCondition}
         ORDER BY id ASC
         LIMIT 1"
    );

    $property = (
        $result instanceof mysqli_result
        ? $result->fetch_assoc()
        : null
    );

    if (is_array($property)) {
        $_SESSION['cpms_current_property_id'] = (int) $property['id'];
        $_SESSION['cpms_current_property_code'] = (
            (string) ($property['property_code'] ?? '')
        );
        return $property;
    }

    return null;
}

function cpmsV2Property(): ?array
{
    global $cpmsV2Property;

    return is_array($cpmsV2Property)
        ? $cpmsV2Property
        : null;
}

function cpmsV2SetCurrentProperty(
    mysqli $conn,
    int $propertyId
): ?array {
    $property = cpmsV2FindPropertyById($conn, $propertyId);

    if ($property === null) {
        return null;
    }

    $_SESSION['cpms_current_property_id'] = (int) $property['id'];
    $_SESSION['cpms_current_property_code'] = (
        (string) ($property['property_code'] ?? '')
    );

    global $cpmsV2Property;
    $cpmsV2Property = $property;

    return $property;
}
