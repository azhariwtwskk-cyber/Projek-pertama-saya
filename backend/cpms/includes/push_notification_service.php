<?php
declare(strict_types=1);

function cpmsPushJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cpmsPushCsrfToken(): string
{
    if (empty($_SESSION['cpms_push_csrf'])) {
        $_SESSION['cpms_push_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cpms_push_csrf'];
}

function cpmsPushIdentity(): array
{
    return [
        'user_id' => (int) ($_SESSION['cpms_user_id'] ?? 0),
        'property_id' => (int) ($_SESSION['cpms_property_id']
            ?? $_SESSION['staff_property_id']
            ?? $_SESSION['security_guard_property_id']
            ?? 0),
        'role' => (string) ($_SESSION['cpms_user_role'] ?? ''),
    ];
}

function cpmsPushRequireIdentity(): array
{
    $identity = cpmsPushIdentity();
    if ($identity['user_id'] < 1 || $identity['property_id'] < 1) {
        cpmsPushJson(['ok' => false, 'error' => 'authentication_required'], 401);
    }
    if (!in_array($identity['role'], ['staff', 'security'], true)) {
        cpmsPushJson(['ok' => false, 'error' => 'mobile_role_required'], 403);
    }
    return $identity;
}

function cpmsPushVerifyRequest(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        cpmsPushJson(['ok' => false, 'error' => 'post_required'], 405);
    }
    $token = (string) ($_SERVER['HTTP_X_CPMS_CSRF'] ?? '');
    $stored = (string) ($_SESSION['cpms_push_csrf'] ?? '');
    if ($stored === '' || !hash_equals($stored, $token)) {
        cpmsPushJson(['ok' => false, 'error' => 'invalid_csrf'], 403);
    }
}

function cpmsPushBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function cpmsPushDerToJose(string $der): string
{
    $offset = 2;
    if (ord($der[1]) > 0x80) {
        $offset = 2 + (ord($der[1]) & 0x7f);
    }
    if (ord($der[$offset]) !== 0x02) {
        return '';
    }
    $rLength = ord($der[$offset + 1]);
    $r = substr($der, $offset + 2, $rLength);
    $offset += 2 + $rLength;
    $sLength = ord($der[$offset + 1]);
    $s = substr($der, $offset + 2, $sLength);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return substr($r, -32) . substr($s, -32);
}

function cpmsPushGenerateKeys(): array
{
    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($resource === false) {
        throw new RuntimeException('OpenSSL EC key generation failed.');
    }
    $privatePem = '';
    if (!openssl_pkey_export($resource, $privatePem)) {
        throw new RuntimeException('Private key export failed.');
    }
    $details = openssl_pkey_get_details($resource);
    if (!isset($details['ec']['x'], $details['ec']['y'])) {
        throw new RuntimeException('EC public key details unavailable.');
    }
    $public = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    return [
        'public_key' => cpmsPushBase64Url($public),
        'private_key_pem' => $privatePem,
        'dispatch_token' => bin2hex(random_bytes(32)),
    ];
}

function cpmsPushVapidHeaders(
    string $endpoint,
    string $publicKey,
    string $privatePem,
    string $subject
): array {
    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return [];
    }
    $audience = $parts['scheme'] . '://' . $parts['host'];
    $header = cpmsPushBase64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = cpmsPushBase64Url(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $subject,
    ]));
    $unsigned = $header . '.' . $claims;
    $signature = '';
    $key = openssl_pkey_get_private($privatePem);
    if (!$key || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
        return [];
    }
    $jose = cpmsPushDerToJose($signature);
    if ($jose === '') {
        return [];
    }
    return [
        'TTL: 300',
        'Urgency: normal',
        'Content-Length: 0',
        'Authorization: vapid t=' . $unsigned . '.'
            . cpmsPushBase64Url($jose) . ', k=' . $publicKey,
    ];
}

function cpmsPushSendEmpty(
    string $endpoint,
    string $publicKey,
    string $privatePem,
    string $subject
): int {
    $headers = cpmsPushVapidHeaders(
        $endpoint, $publicKey, $privatePem, $subject
    );
    if (!$headers || !function_exists('curl_init')) {
        return 0;
    }
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $status;
}
