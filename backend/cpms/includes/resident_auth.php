<?php
declare(strict_types=1);

function cpmsResidentLoggedIn(): bool {
    return isset($_SESSION["cpms_resident_id"]) && (int)$_SESSION["cpms_resident_id"] > 0;
}
function cpmsResidentId(): int {
    return (int)($_SESSION["cpms_resident_id"] ?? 0);
}
function cpmsRequireResidentLogin(): void {
    if (!cpmsResidentLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}
function cpmsResidentLogout(): void {
    unset($_SESSION["cpms_resident_id"], $_SESSION["cpms_resident_name"], $_SESSION["cpms_resident_property_id"]);
}
function cpmsCurrentResident(mysqli $conn): ?array {
    $id = cpmsResidentId();
    if ($id < 1) return null;
    $stmt = $conn->prepare("SELECT id, property_id, resident_code, full_name, email, phone, block_name, unit_no, resident_type, is_active, last_login_at FROM cpms_residents WHERE id=? AND is_active=1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
