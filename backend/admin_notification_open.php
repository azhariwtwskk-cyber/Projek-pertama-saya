<?php

declare(strict_types=1);

require_once __DIR__ . "/cpms/includes/cpms_bootstrap.php";
require_once __DIR__ . "/cpms/includes/property_context.php";
require_once __DIR__ . "/cpms/includes/property_guard.php";

if (!isset($_SESSION["admin"])) {
    header("Location: admin_login.php");
    exit();
}

$propertyId = cpmsRequireCurrentPropertyId($conn);
$adminName = (string) ($_SESSION["admin"] ?? "");
$notificationId = (int) ($_GET["id"] ?? 0);
$redirect = trim(
    (string) (
        $_GET["redirect"] ??
        "admin_notifications.php"
    )
);

if (
    $redirect === "" ||
    str_starts_with($redirect, "//") ||
    preg_match('/^(?:[a-z][a-z0-9+.-]*:|\\\\)/i', $redirect)
) {
    $redirect = "admin_notifications.php";
}

if ($notificationId > 0) {
    $stmt = $conn->prepare(
        "
        UPDATE cpms_notifications
        SET
            is_read = 1,
            read_at = COALESCE(read_at, NOW())
        WHERE id = ?
          AND property_id = ?
          AND (target_role IS NULL OR target_role = 'Administrator')
          AND (target_user IS NULL OR target_user = ?)
          AND deleted_at IS NULL
        LIMIT 1
        "
    );

    if ($stmt) {
        $stmt->bind_param(
            "iis",
            $notificationId,
            $propertyId,
            $adminName
        );
        $stmt->execute();
        $stmt->close();
    }
}

$conn->close();

header("Location: " . $redirect);
exit();
