<?php
declare(strict_types=1);

function cpmsAssetSchemaReady(mysqli $conn): bool
{
    $required = ['property_id', 'condition_rating', 'purchase_date', 'purchase_cost', 'photo_path'];
    foreach ($required as $column) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "assets" AND COLUMN_NAME = ?');
        if (!$stmt) return false;
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($row['total'] ?? 0) !== 1) return false;
    }
    return true;
}

function cpmsAssetGenerateCode(mysqli $conn, int $propertyId): string
{
    $prefix = 'AST-' . $propertyId . '-';
    $stmt = $conn->prepare('SELECT asset_code FROM assets WHERE property_id = ? AND asset_code LIKE CONCAT(?, "%") ORDER BY id DESC LIMIT 1');
    $next = 1;
    if ($stmt) {
        $stmt->bind_param('is', $propertyId, $prefix);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['asset_code'])) {
            $parts = explode('-', (string)$row['asset_code']);
            $next = ((int)end($parts)) + 1;
        }
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function cpmsAssetFind(mysqli $conn, int $propertyId, int $assetId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM assets WHERE id = ? AND property_id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $assetId, $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function cpmsAssetHistoryAdd(mysqli $conn, int $assetId, int $propertyId, string $type, string $notes, string $user): void
{
    $stmt = $conn->prepare('INSERT INTO asset_history(asset_id, property_id, event_type, event_notes, changed_by) VALUES(?,?,?,?,?)');
    if ($stmt) {
        $stmt->bind_param('iisss', $assetId, $propertyId, $type, $notes, $user);
        $stmt->execute();
        $stmt->close();
    }
}

function cpmsBciRating(float $score): string
{
    if ($score >= 90) return 'Excellent';
    if ($score >= 75) return 'Good';
    if ($score >= 60) return 'Need Improvement';
    return 'Critical';
}

function cpmsAssetPublicUrl(array $asset): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_replace('#/property_portal/[^/]+$#', '/property_portal', $script);
    $token = rawurlencode((string)($asset['public_token'] ?? ''));
    return $scheme . '://' . $host . rtrim((string)$base, '/') . '/asset_scan.php?token=' . $token;
}

function cpmsAssetEnsurePublicToken(mysqli $conn, int $assetId): string
{
    $stmt = $conn->prepare('SELECT public_token FROM assets WHERE id = ? LIMIT 1');
    if (!$stmt) return '';
    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $token = trim((string)($row['public_token'] ?? ''));
    if ($token !== '') return $token;
    $token = bin2hex(random_bytes(24));
    $stmt = $conn->prepare('UPDATE assets SET public_token = ? WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('si', $token, $assetId);
        $stmt->execute();
        $stmt->close();
    }
    return $token;
}
