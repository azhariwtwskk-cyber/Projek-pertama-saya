<?php
declare(strict_types=1);

function cpmsPasswordRecoveryEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cpmsPasswordRecoveryCsrf(string $key): string
{
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION[$key];
}

function cpmsPasswordRecoveryVerifyCsrf(string $key, ?string $token): bool
{
    $stored = (string) ($_SESSION[$key] ?? '');
    return $stored !== '' && is_string($token)
        && hash_equals($stored, $token);
}

function cpmsPasswordRecoveryClientIp(): string
{
    $value = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($value, FILTER_VALIDATE_IP)
        ? substr($value, 0, 45) : '';
}

function cpmsPasswordRecoveryBaseUrl(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $https = true;
    }
    $host = preg_replace(
        '/[^a-zA-Z0-9.-]/',
        '',
        (string) ($_SERVER['HTTP_HOST'] ?? '')
    );
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $cpmsPosition = strpos($script, '/cpms/');
    $basePath = $cpmsPosition === false
        ? '/cpms' : substr($script, 0, $cpmsPosition) . '/cpms';
    return ($https ? 'https://' : 'http://') . $host . $basePath;
}

function cpmsPasswordRecoverySettings(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT sender_name, sender_email, reset_lifetime_minutes
         FROM cpms_password_reset_settings WHERE id = 1'
    );
    $row = $result ? $result->fetch_assoc() : null;
    return is_array($row) ? $row : [];
}

function cpmsPasswordRecoveryAllowed(mysqli $conn, int $userId): bool
{
    $ip = cpmsPasswordRecoveryClientIp();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM cpms_password_reset_tokens
         WHERE system_user_id = ? AND requested_ip = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('is', $userId, $ip);
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total < 3;
}

function cpmsPasswordRecoveryCreate(
    mysqli $conn,
    int $userId,
    int $lifetimeMinutes
): array {
    $selector = bin2hex(random_bytes(16));
    $verifier = bin2hex(random_bytes(32));
    $hash = hash('sha256', $verifier);
    $ip = cpmsPasswordRecoveryClientIp();
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $lifetimeMinutes = max(15, min(60, $lifetimeMinutes));

    $invalidate = $conn->prepare(
        'UPDATE cpms_password_reset_tokens SET used_at = NOW()
         WHERE system_user_id = ? AND used_at IS NULL'
    );
    if ($invalidate) {
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();
    }

    $stmt = $conn->prepare(
        "INSERT INTO cpms_password_reset_tokens
            (system_user_id, selector, verifier_hash, expires_at,
             requested_ip, requested_user_agent)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to create reset request.');
    }
    $stmt->bind_param(
        'ississ',
        $userId, $selector, $hash, $lifetimeMinutes, $ip, $agent
    );
    $stmt->execute();
    $stmt->close();
    return ['selector' => $selector, 'verifier' => $verifier];
}

function cpmsPasswordRecoverySendMail(
    string $recipient,
    string $name,
    string $url,
    array $settings
): bool {
    $senderName = preg_replace(
        '/[\r\n]+/', '', (string) ($settings['sender_name'] ?? 'CPMS')
    );
    $senderEmail = filter_var(
        (string) ($settings['sender_email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    );
    if (!$senderEmail || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = 'CPMS - Reset kata laluan';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $message = '<p>Salam ' . $safeName . ',</p>'
        . '<p>Kami menerima permintaan untuk menetapkan semula kata laluan '
        . 'akaun CPMS anda.</p><p><a href="' . $safeUrl
        . '">Tetapkan kata laluan baharu</a></p>'
        . '<p>Pautan ini hanya boleh digunakan sekali dan akan tamat tempoh. '
        . 'Jika anda tidak membuat permintaan ini, abaikan e-mel ini.</p>';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $senderName . ' <' . $senderEmail . '>',
        'Reply-To: ' . $senderEmail,
    ];
    return mail($recipient, $subject, $message, implode("\r\n", $headers));
}

function cpmsPasswordRecoveryFindToken(
    mysqli $conn,
    string $selector,
    string $verifier
): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $selector)
        || !preg_match('/^[a-f0-9]{64}$/', $verifier)) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT t.id, t.system_user_id, t.verifier_hash,
                u.username, u.full_name, u.status
         FROM cpms_password_reset_tokens t
         JOIN system_users u ON u.id = t.system_user_id
         WHERE t.selector = ? AND t.used_at IS NULL
           AND t.expires_at > NOW() LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)
        || !hash_equals((string) $row['verifier_hash'], hash('sha256', $verifier))
        || (string) $row['status'] !== 'active') {
        return null;
    }
    return $row;
}

function cpmsPasswordRecoveryValidatePassword(string $password): array
{
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'Kata laluan mesti sekurang-kurangnya 10 aksara.';
    }
    if (!preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Gunakan huruf besar, huruf kecil dan nombor.';
    }
    return $errors;
}

function cpmsPasswordRecoveryComplete(
    mysqli $conn,
    array $token,
    string $password
): void {
    $userId = (int) $token['system_user_id'];
    $tokenId = (int) $token['id'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Password hashing failed.');
    }
    $conn->begin_transaction();
    try {
        $identity = $conn->prepare(
            'SELECT username, email FROM system_users WHERE id = ? LIMIT 1'
        );
        $identity->bind_param('i', $userId);
        $identity->execute();
        $user = $identity->get_result()->fetch_assoc();
        $identity->close();

        $stmt = $conn->prepare(
            'UPDATE system_users SET password_hash = ?,
             must_change_password = 0, updated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $stmt->close();

        $used = $conn->prepare(
            'UPDATE cpms_password_reset_tokens SET used_at = NOW()
             WHERE system_user_id = ? AND used_at IS NULL'
        );
        $used->bind_param('i', $userId);
        $used->execute();
        $used->close();

        $revoke = $conn->prepare(
            'UPDATE cpms_auth_sessions SET revoked_at = NOW()
             WHERE user_type = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $type = 'system_user';
        $revoke->bind_param('si', $type, $userId);
        $revoke->execute();
        $revoke->close();

        if (is_array($user)) {
            foreach ([
                (string) ($user['username'] ?? ''),
                (string) ($user['email'] ?? ''),
            ] as $login) {
                if ($login === '') {
                    continue;
                }
                $loginHash = hash('sha256', strtolower(trim($login)));
                $clear = $conn->prepare(
                    'DELETE FROM cpms_auth_login_attempts
                     WHERE login_hash = ?'
                );
                $clear->bind_param('s', $loginHash);
                $clear->execute();
                $clear->close();
            }
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
