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

if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if (
    $_SERVER["REQUEST_METHOD"] !== "POST" ||
    !hash_equals(
        $_SESSION["csrf_token"],
        (string) ($_POST["csrf_token"] ?? "")
    )
) {
    header("Location: admin_notifications.php?message=" . rawurlencode("Permintaan tidak sah."));
    exit();
}

$stmt = $conn->prepare(
    "
    UPDATE cpms_notifications
    SET
        is_read = 1,
        read_at = COALESCE(read_at, NOW())
    WHERE property_id = ?
      AND (target_role IS NULL OR target_role = 'Administrator')
      AND (target_user IS NULL OR target_user = ?)
      AND deleted_at IS NULL
      AND archived_at IS NULL
      AND is_read = 0
    "
);

$affected = 0;

if ($stmt) {
    $stmt->bind_param("is", $propertyId, $adminName);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
}

$conn->close();

header(
    "Location: admin_notifications.php?message=" .
    rawurlencode("{$affected} notifikasi ditandakan sebagai dibaca.")
);
exit();
