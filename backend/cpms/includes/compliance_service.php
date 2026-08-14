<?php
declare(strict_types=1);

function cpmsComplianceEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsComplianceTablesReady(mysqli $conn): bool
{
    foreach (['compliance_schedules', 'compliance_completion_history'] as $table) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int) ($row['total'] ?? 0) !== 1) {
            return false;
        }
    }
    return true;
}

function cpmsCompliancePropertyId(array $user = []): int
{
    foreach ([$user['property_id'] ?? null, $_SESSION['property_id'] ?? null, $_SESSION['cpms_property_id'] ?? null, $_SESSION['property_portal_property_id'] ?? null] as $candidate) {
        $id = (int) $candidate;
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

function cpmsComplianceUserId(array $user = []): int
{
    foreach ([$user['id'] ?? null, $user['user_id'] ?? null, $user['admin_id'] ?? null, $_SESSION['user_id'] ?? null, $_SESSION['admin_id'] ?? null] as $candidate) {
        $id = (int) $candidate;
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

function cpmsComplianceUserName(array $user = []): string
{
    foreach ([$user['full_name'] ?? null, $user['name'] ?? null, $user['username'] ?? null, $_SESSION['full_name'] ?? null, $_SESSION['name'] ?? null, $_SESSION['username'] ?? null] as $candidate) {
        $name = trim((string) $candidate);
        if ($name !== '') {
            return $name;
        }
    }
    return 'Property User';
}

function cpmsComplianceCsrfToken(): string
{
    if (empty($_SESSION['compliance_csrf_token'])) {
        $_SESSION['compliance_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['compliance_csrf_token'];
}

function cpmsComplianceVerifyCsrf(?string $token): bool
{
    $stored = (string) ($_SESSION['compliance_csrf_token'] ?? '');
    return $stored !== '' && $token !== null && hash_equals($stored, $token);
}

function cpmsComplianceNextDueDate(string $completedDate, string $frequency): ?string
{
    $map = [
        'monthly' => '+1 month',
        'quarterly' => '+3 months',
        'half_yearly' => '+6 months',
        'yearly' => '+1 year',
    ];
    if (!isset($map[$frequency])) {
        return null;
    }
    $time = strtotime($completedDate . ' ' . $map[$frequency]);
    return $time ? date('Y-m-d', $time) : null;
}

function cpmsComplianceSummary(mysqli $conn, int $propertyId): array
{
    $out = ['total' => 0, 'overdue' => 0, 'due_30' => 0, 'upcoming' => 0, 'inactive' => 0];
    $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status='active' AND next_due_date < CURDATE()) AS overdue_total, SUM(status='active' AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS due_30_total, SUM(status='active' AND next_due_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS upcoming_total, SUM(status='inactive') AS inactive_total FROM compliance_schedules WHERE property_id = ?");
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $out['total'] = (int) ($row['total'] ?? 0);
    $out['overdue'] = (int) ($row['overdue_total'] ?? 0);
    $out['due_30'] = (int) ($row['due_30_total'] ?? 0);
    $out['upcoming'] = (int) ($row['upcoming_total'] ?? 0);
    $out['inactive'] = (int) ($row['inactive_total'] ?? 0);
    return $out;
}

function cpmsComplianceList(mysqli $conn, int $propertyId, string $filter = ''): array
{
    $where = 'property_id = ?';
    if ($filter === 'overdue') {
        $where .= " AND status='active' AND next_due_date < CURDATE()";
    } elseif ($filter === 'due_30') {
        $where .= " AND status='active' AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($filter === 'upcoming') {
        $where .= " AND status='active' AND next_due_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($filter === 'inactive') {
        $where .= " AND status='inactive'";
    }
    $stmt = $conn->prepare("SELECT * FROM compliance_schedules WHERE $where ORDER BY status='active' DESC, next_due_date ASC, id DESC LIMIT 500");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function cpmsComplianceFind(mysqli $conn, int $propertyId, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM compliance_schedules WHERE id = ? AND property_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $id, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsComplianceHistory(mysqli $conn, int $propertyId, int $scheduleId): array
{
    $stmt = $conn->prepare('SELECT * FROM compliance_completion_history WHERE property_id = ? AND schedule_id = ? ORDER BY completed_date DESC, id DESC');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $propertyId, $scheduleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}
