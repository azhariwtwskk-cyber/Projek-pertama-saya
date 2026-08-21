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

function redirectBack(string $message = ""): never
{
    $url = "admin_notifications.php";

    if ($message !== "") {
        $url .= "?message=" . rawurlencode($message);
    }

    header("Location: " . $url);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirectBack();
}

$token = (string) ($_POST["csrf_token"] ?? "");

if (!hash_equals($_SESSION["csrf_token"], $token)) {
    redirectBack("Permintaan tidak sah.");
}

$action = trim((string) ($_POST["action"] ?? ""));
$ids = $_POST["notification_ids"] ?? [];

if (!is_array($ids)) {
    $ids = [];
}

$ids = array_values(
    array_unique(
        array_filter(
            array_map(
                static fn($id): int => (int) $id,
                $ids
            ),
            static fn(int $id): bool => $id > 0
        )
    )
);

$allowedActions = [
    "read",
    "unread",
    "archive",
    "restore",
    "delete"
];

if (!in_array($action, $allowedActions, true)) {
    redirectBack("Tindakan tidak sah.");
}

if ($action !== "read_all" && count($ids) === 0) {
    redirectBack("Sila pilih sekurang-kurangnya satu notifikasi.");
}

$placeholders = implode(",", array_fill(0, count($ids), "?"));
$types = str_repeat("i", count($ids)) . "iss";
$params = [
    ...$ids,
    $propertyId,
    "Administrator",
    $adminName
];

$scopeSql = "
    property_id = ?
    AND (target_role IS NULL OR target_role = ?)
    AND (target_user IS NULL OR target_user = ?)
";

$setSql = match ($action) {
    "read" =>
        "is_read = 1, read_at = COALESCE(read_at, NOW())",
    "unread" =>
        "is_read = 0, read_at = NULL",
    "archive" =>
        "archived_at = NOW()",
    "restore" =>
        "archived_at = NULL",
    "delete" =>
        "deleted_at = NOW()",
    default =>
        ""
};

$sql = "
    UPDATE cpms_notifications
    SET {$setSql}
    WHERE id IN ({$placeholders})
      AND {$scopeSql}
      AND deleted_at IS NULL
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    redirectBack("Tindakan tidak dapat disediakan.");
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

redirectBack("Tindakan berjaya dilaksanakan pada {$affected} notifikasi.");
