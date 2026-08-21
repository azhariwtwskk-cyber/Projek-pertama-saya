<?php
declare(strict_types=1);

function cpmsReportTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0) > 0;
}

function cpmsReportColumns(mysqli $conn, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $cache[$table] = [];
    $stmt = $conn->prepare(
        "SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?"
    );
    if (!$stmt) return $cache[$table];
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $cache[$table][] = (string)$row['column_name'];
    $stmt->close();
    return $cache[$table];
}

function cpmsReportHasColumn(mysqli $conn, string $table, string $column): bool
{
    return in_array($column, cpmsReportColumns($conn, $table), true);
}

function cpmsReportDateColumn(mysqli $conn, string $table, array $preferred): ?string
{
    foreach ($preferred as $column) {
        if (cpmsReportHasColumn($conn, $table, $column)) return $column;
    }
    return null;
}

function cpmsReportScalar(mysqli $conn, string $sql, string $types = '', array $params = []): float
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.0;
    if ($types !== '' && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return (float)($row[0] ?? 0);
}

function cpmsReportFetch(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if ($types !== '' && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function cpmsReportPropertyWhere(mysqli $conn, string $table, int $propertyId, string $alias = ''): array
{
    if (!cpmsReportHasColumn($conn, $table, 'property_id')) return ['', '', []];
    $prefix = $alias !== '' ? $alias . '.' : '';
    return ["{$prefix}property_id = ?", 'i', [$propertyId]];
}

function cpmsReportDateFilter(
    mysqli $conn,
    string $table,
    ?string $dateColumn,
    string $from,
    string $to,
    string $alias = ''
): array {
    if ($dateColumn === null || !cpmsReportHasColumn($conn, $table, $dateColumn)) return ['', '', []];
    $prefix = $alias !== '' ? $alias . '.' : '';
    $parts = []; $types = ''; $params = [];
    if ($from !== '') { $parts[] = "DATE({$prefix}{$dateColumn}) >= ?"; $types .= 's'; $params[] = $from; }
    if ($to !== '') { $parts[] = "DATE({$prefix}{$dateColumn}) <= ?"; $types .= 's'; $params[] = $to; }
    return [implode(' AND ', $parts), $types, $params];
}

function cpmsReportBuildWhere(array ...$pieces): array
{
    $clauses = []; $types = ''; $params = [];
    foreach ($pieces as $piece) {
        if (($piece[0] ?? '') !== '') $clauses[] = '(' . $piece[0] . ')';
        $types .= (string)($piece[1] ?? '');
        foreach (($piece[2] ?? []) as $p) $params[] = $p;
    }
    return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $types, $params];
}

function cpmsReportCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, array_values($row));
    fclose($out);
    exit;
}
