<?php
declare(strict_types=1);

$bootstrapFile = dirname(__DIR__) . '/includes/cpms_bootstrap.php';

if (!is_file($bootstrapFile)) {
    http_response_code(500);
    exit('CPMS bootstrap could not be found.');
}

require_once $bootstrapFile;

function systemOwnerEscape(?string $value): string
{
    return cpmsPortalEscape($value);
}

function systemOwnerRedirect(string $path): void
{
    cpmsPortalRedirect($path);
}

function systemOwnerCsrfToken(): string
{
    return cpmsPortalCsrfToken('system_owner_csrf');
}

function systemOwnerVerifyCsrf(?string $token): bool
{
    return cpmsPortalVerifyCsrf(
        'system_owner_csrf',
        $token
    );
}

function systemOwnerAccountExists(mysqli $conn): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM system_users users
         INNER JOIN user_roles assignments
            ON assignments.system_user_id = users.id
           AND assignments.status = 'active'
           AND assignments.property_id IS NULL
           AND (
                assignments.expires_at IS NULL
                OR assignments.expires_at > NOW()
           )
         INNER JOIN roles
            ON roles.id = assignments.role_id
           AND roles.role_code = 'system_owner'
           AND roles.status = 'active'
         WHERE users.status = 'active'
         LIMIT 1"
    );

    if (!$stmt) {
        cpmsFoundationLog(
            'Unable to prepare System Owner account check.'
        );
        return false;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function systemOwnerPropertyCount(mysqli $conn): int
{
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM cpms_properties"
    );

    if (!($result instanceof mysqli_result)) {
        cpmsFoundationLog(
            'Unable to count CPMS properties.'
        );
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}
