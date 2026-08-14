<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
cpmsApiMethod('POST');

$db = cpmsApiDatabase();
if (!cpmsApiTablesReady($db)) {
    cpmsApiError(
        'API_NOT_INSTALLED',
        'Jalankan migration CPMS Workforce v4.0.1 terlebih dahulu.',
        503
    );
}

$input = cpmsApiInput();
$username = strtolower(trim((string) ($input['username'] ?? '')));
$password = (string) ($input['password'] ?? '');
$device = isset($input['device']) && is_array($input['device'])
    ? $input['device'] : [];

if ($username === '' || $password === '' || strlen($username) > 190) {
    cpmsApiError(
        'INVALID_CREDENTIALS',
        'Username atau kata laluan tidak sah.',
        401
    );
}

$ip = cpmsApiClientIp();
$loginHash = hash('sha256', $username);
$limitStmt = $db->prepare(
    "SELECT COUNT(*) AS total
     FROM cpms_api_login_attempts
     WHERE login_key_hash=? AND ip_address=? AND succeeded=0
       AND attempted_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)"
);
if (!$limitStmt) {
    throw new RuntimeException('Unable to prepare login rate limit.');
}
$limitStmt->bind_param('ss', $loginHash, $ip);
$limitStmt->execute();
$attempts = (int) ($limitStmt->get_result()->fetch_assoc()['total'] ?? 0);
$limitStmt->close();
if ($attempts >= 5) {
    cpmsApiError(
        'TOO_MANY_ATTEMPTS',
        'Terlalu banyak percubaan login. Cuba semula selepas 15 minit.',
        429
    );
}

$authentication = cpmsUnifiedAuthenticate($db, $username, $password, null);
$valid = !empty($authentication['valid']);
$context = $valid && is_array($authentication['context'] ?? null)
    ? $authentication['context'] : [];
$role = strtolower((string) ($context['role'] ?? ''));
$propertyId = (int) ($context['property_id'] ?? 0);
$allowed = $valid
    && in_array($role, ['staff', 'security'], true)
    && $propertyId > 0;

$attemptValue = $allowed ? 1 : 0;
$attemptStmt = $db->prepare(
    'INSERT INTO cpms_api_login_attempts
     (login_key_hash,ip_address,succeeded,user_agent)
     VALUES (?,?,?,?)'
);
if ($attemptStmt) {
    $agent = cpmsApiUserAgent();
    $attemptStmt->bind_param('ssis', $loginHash, $ip, $attemptValue, $agent);
    $attemptStmt->execute();
    $attemptStmt->close();
}

if (!$allowed) {
    cpmsApiAudit($db, null, 'workforce.login', 'failed', null, null, [
        'login_hash' => $loginHash,
    ]);
    cpmsApiError(
        $valid ? 'ROLE_NOT_ALLOWED' : 'INVALID_CREDENTIALS',
        $valid
            ? 'Hanya akaun Staff atau Security dibenarkan menggunakan aplikasi ini.'
            : 'Username atau kata laluan tidak sah.',
        $valid ? 403 : 401
    );
}

$user = $authentication['user'];
$sourceTable = (string) ($user['source_table'] ?? '');
$sourceId = (int) ($user['source_id'] ?? 0);
if (($role === 'staff' && $sourceTable !== 'staff')
    || ($role === 'security' && $sourceTable !== 'security_guards')
    || $sourceId < 1) {
    cpmsApiError(
        'PROFILE_NOT_LINKED',
        'Akaun belum dipadankan dengan profil Workforce.',
        409
    );
}

$propertyStmt = $db->prepare(
    'SELECT id,property_code,property_name,timezone_name
     FROM cpms_properties WHERE id=? AND is_active=1 LIMIT 1'
);
if (!$propertyStmt) {
    throw new RuntimeException('Unable to prepare property lookup.');
}
$propertyStmt->bind_param('i', $propertyId);
$propertyStmt->execute();
$property = $propertyStmt->get_result()->fetch_assoc();
$propertyStmt->close();
if (!is_array($property)) {
    cpmsApiError('PROPERTY_UNAVAILABLE', 'Property tidak aktif atau tidak dijumpai.', 403);
}

$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$tokenHash = hash('sha256', $token);
$systemUserId = (int) $user['id'];
$platform = substr(trim((string) ($device['platform'] ?? 'android')), 0, 40);
$appVersion = substr(trim((string) ($device['app_version'] ?? '')), 0, 30);
$deviceName = substr($platform, 0, 120);
$agent = cpmsApiUserAgent();

$db->query(
    "UPDATE cpms_api_tokens
     SET revoked_at=COALESCE(revoked_at,NOW())
     WHERE revoked_at IS NULL AND expires_at<=NOW()"
);

$tokenStmt = $db->prepare(
    "INSERT INTO cpms_api_tokens
     (system_user_id,property_id,role_name,token_hash,device_name,
      app_version,ip_address,user_agent,expires_at,last_used_at)
     VALUES (?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 8 HOUR),NOW())"
);
if (!$tokenStmt) {
    throw new RuntimeException('Unable to prepare access token.');
}
$tokenStmt->bind_param(
    'iissssss',
    $systemUserId,
    $propertyId,
    $role,
    $tokenHash,
    $deviceName,
    $appVersion,
    $ip,
    $agent
);
$tokenStmt->execute();
$tokenId = (int) $db->insert_id;
$tokenStmt->close();

$updateUser = $db->prepare('UPDATE system_users SET last_login_at=NOW() WHERE id=?');
if ($updateUser) {
    $updateUser->bind_param('i', $systemUserId);
    $updateUser->execute();
    $updateUser->close();
}

$identity = [
    'token_id' => $tokenId,
    'system_user_id' => $systemUserId,
    'property_id' => $propertyId,
    'role' => $role,
];
cpmsApiAudit($db, $identity, 'workforce.login', 'success', 'api_token', $tokenId, [
    'app_version' => $appVersion,
]);

cpmsApiRespond([
    'access_token' => $token,
    'expires_in' => 28800,
    'user' => [
        'id' => $systemUserId,
        'name' => (string) $user['full_name'],
        'role' => $role,
    ],
    'property' => [
        'id' => (int) $property['id'],
        'code' => (string) $property['property_code'],
        'name' => (string) $property['property_name'],
    ],
]);

