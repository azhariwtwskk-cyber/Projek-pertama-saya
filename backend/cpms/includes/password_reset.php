<?php
declare(strict_types=1);

require_once __DIR__ . '/property_user_sync.php';

function cpmsPasswordResetEnsureTable(mysqli $conn): bool
{
    return (bool) $conn->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_type VARCHAR(40) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        request_ip VARCHAR(45) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_password_reset_token_hash (token_hash),
        KEY idx_password_reset_user (user_type, user_id),
        KEY idx_password_reset_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function cpmsPasswordResetBaseUrl(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function cpmsPasswordResetCreate(mysqli $conn, string $userType, int $userId, string $email): ?string
{
    if (!cpmsPasswordResetEnsureTable($conn)) return null;
    $delete = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_type=? AND user_id=? AND used_at IS NULL');
    if ($delete) { $delete->bind_param('si', $userType, $userId); $delete->execute(); $delete->close(); }
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 1800);
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $stmt = $conn->prepare('INSERT INTO password_reset_tokens (user_type,user_id,email,token_hash,expires_at,request_ip) VALUES (?,?,?,?,?,?)');
    if (!$stmt) return null;
    $stmt->bind_param('sissss', $userType, $userId, $email, $hash, $expires, $ip);
    $ok = $stmt->execute(); $stmt->close();
    return $ok ? $token : null;
}

function cpmsPasswordResetSend(string $email, string $name, string $url): bool
{
    $subject = 'CPMS - Tetapkan Semula Kata Laluan';
    $body = "Salam " . ($name !== '' ? $name : 'pengguna') . ",\n\n"
        . "Klik pautan berikut untuk menetapkan semula kata laluan CPMS anda:\n"
        . $url . "\n\nPautan ini sah selama 30 minit dan hanya boleh digunakan sekali.\n"
        . "Abaikan e-mel ini jika anda tidak membuat permintaan tersebut.\n";
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "From: CPMS <no-reply@" . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . ">\r\n";
    return @mail($email, $subject, $body, $headers);
}

function cpmsPasswordResetValidate(mysqli $conn, string $userType, string $token): ?array
{
    if ($token === '' || !cpmsPasswordResetEnsureTable($conn)) return null;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id,user_id,email FROM password_reset_tokens WHERE user_type=? AND token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ss', $userType, $hash); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function cpmsPasswordResetComplete(mysqli $conn, int $tokenId, string $table, int $userId, string $password): bool
{
    $allowed = ['system_users', 'property_admins', 'hq_inspectors'];
    if (!in_array($table, $allowed, true)) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE {$table} SET password_hash=? WHERE id=?");
        if (!$update) throw new RuntimeException('Update failed');
        $update->bind_param('si', $hash, $userId); $update->execute();
        if ($update->affected_rows < 0) throw new RuntimeException('Update failed');
        $update->close();
        if ($table === 'system_users') {
            cpmsPropertyUserMirrorPasswordFromSystem($conn, $userId);
        } elseif ($table === 'property_admins') {
            cpmsPropertyUserSyncLegacyAccount($conn, $userId);
        }
        $used = $conn->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL');
        if (!$used) throw new RuntimeException('Token update failed');
        $used->bind_param('i', $tokenId); $used->execute();
        if ($used->affected_rows !== 1) throw new RuntimeException('Token invalid');
        $used->close();
        $conn->commit(); return true;
    } catch (Throwable $e) {
        $conn->rollback(); return false;
    }
}
