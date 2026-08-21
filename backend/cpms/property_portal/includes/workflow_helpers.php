<?php
declare(strict_types=1);

function cpmsWorkflowEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsWorkflowFlash(string $type, string $message): void
{
    $_SESSION['cpms_workflow_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpmsWorkflowPullFlash(): ?array
{
    $flash = $_SESSION['cpms_workflow_flash'] ?? null;
    unset($_SESSION['cpms_workflow_flash']);

    return is_array($flash) ? $flash : null;
}

function cpmsWorkflowComplaint(
    mysqli $conn,
    string $reference,
    int $propertyId
): ?array {
    $stmt = $conn->prepare(
        "SELECT *
         FROM complaints
         WHERE complaint_id = ?
           AND property_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('si', $reference, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function cpmsWorkflowPriority(string $priority): string
{
    $value = strtolower($priority);

    if (str_contains($value, 'tinggi') || str_contains($value, 'high')) {
        return 'High';
    }

    if (str_contains($value, 'rendah') || str_contains($value, 'low')) {
        return 'Low';
    }

    if (str_contains($value, 'emergency') || str_contains($value, 'kritikal')) {
        return 'Emergency';
    }

    return 'Medium';
}

function cpmsWorkflowReference(): string
{
    return 'WO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}
